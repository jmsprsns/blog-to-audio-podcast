<?php
/**
 * Creates a post's audio: text to chunks, chunks to speech, speech to one MP3.
 *
 * Only one post is worked on at a time across the whole site (a lock in the
 * options table), whether the work was started by a background job or by
 * someone watching on the settings page. That keeps to ElevenLabs'
 * concurrency limits and means two runs can never write the same file.
 *
 * The chunks of a post are sent one after another, each with the request IDs
 * of the ones before it, so the voice carries on in the same tone (request
 * stitching). Progress is saved after every chunk, so a job that runs out of
 * time, or a queue paused for lack of credits, picks up where it left off.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audio creation.
 */
final class Blog_To_Audio_Podcast_Generator {

	/**
	 * Post meta: the job in progress.
	 */
	const META_JOB = '_btap_job';

	/**
	 * Option used as the site-wide lock.
	 */
	const LOCK = 'blog_to_audio_podcast_lock';

	/**
	 * Seconds the lock lasts without being renewed. Long enough for one
	 * request to ElevenLabs; short enough that a crashed run soon frees it.
	 */
	const LOCK_TTL = 180;

	/**
	 * Tries per post for temporary errors before giving up on it.
	 */
	const MAX_ATTEMPTS = 6;

	/**
	 * Seconds a request ID stays usable for stitching (ElevenLabs allows two
	 * hours; this leaves a margin).
	 */
	const STITCH_WINDOW = 6600;

	/**
	 * Token of the lock this request holds.
	 *
	 * @var string
	 */
	private static $lock_token = '';

	/**
	 * Work on the next post in the queue for up to $budget seconds.
	 *
	 * @param int $budget Seconds to keep starting new chunks.
	 * @return array{result:string,post_id:int} result: done, continued, retry, paused, error, removed, cancelled, idle or busy.
	 */
	public static function run( $budget ) {
		if ( Blog_To_Audio_Podcast_Queue::paused() ) {
			return array(
				'result'  => 'paused',
				'post_id' => 0,
			);
		}

		if ( ! self::lock() ) {
			return array(
				'result'  => 'busy',
				'post_id' => 0,
			);
		}

		try {
			$post_id = Blog_To_Audio_Podcast_Queue::next();

			if ( ! $post_id ) {
				return array(
					'result'  => 'idle',
					'post_id' => 0,
				);
			}

			return array(
				'result'  => self::work( $post_id, max( 5, (int) $budget ) ),
				'post_id' => $post_id,
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Create (or carry on creating) one post's audio.
	 *
	 * @param int $post_id Post ID.
	 * @param int $budget  Seconds to keep starting new chunks.
	 * @return string Result.
	 */
	private static function work( $post_id, $budget ) {
		$post = get_post( $post_id );

		// Deleted, trashed, left out, or no longer a type that gets audio.
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true )
			|| ! blog_to_audio_podcast_plugin()->supports( $post ) || Blog_To_Audio_Podcast_Audio::skipped( $post_id ) ) {
			Blog_To_Audio_Podcast_Queue::remove( $post_id );
			return 'removed';
		}

		if ( '' === Blog_To_Audio_Podcast_Plugin::api_key() ) {
			Blog_To_Audio_Podcast_Queue::pause( 'missing_api_key', __( 'Add your ElevenLabs API key to start creating audio.', 'blog-to-audio-podcast' ) );
			return 'paused';
		}

		$job = get_post_meta( $post_id, self::META_JOB, true );

		if ( ! self::valid_job( $job ) ) {
			$job = self::new_job( $post, blog_to_audio_podcast_plugin()->get_settings() );

			if ( is_wp_error( $job ) ) {
				return self::fail( $post, null, $job );
			}

			update_post_meta( $post_id, self::META_JOB, $job );
		}

		Blog_To_Audio_Podcast_Queue::processing( $post_id );

		$api   = Blog_To_Audio_Podcast_Plugin::api();
		$start = microtime( true );
		$times = array();
		$count = count( $job['chunks'] );

		while ( $job['index'] < $count ) {
			if ( $times ) {
				// Stop before a chunk that probably wouldn't finish in time;
				// the next job carries on from here.
				$average = array_sum( $times ) / count( $times );

				if ( microtime( true ) - $start + $average * 1.25 > $budget ) {
					Blog_To_Audio_Podcast_Queue::requeue( $post_id );
					return 'continued';
				}

				// Cancelled while the last chunk was being made.
				if ( ! self::still_queued( $post_id ) ) {
					Blog_To_Audio_Podcast_Audio::delete_part( $job['part'] );
					delete_post_meta( $post_id, self::META_JOB );
					return 'cancelled';
				}
			}

			// The file in progress is gone (cleaned up after a long pause): start over.
			if ( '' === Blog_To_Audio_Podcast_Audio::part_path( $job['part'] ) || ! file_exists( Blog_To_Audio_Podcast_Audio::part_path( $job['part'] ) ) ) {
				delete_post_meta( $post_id, self::META_JOB );
				Blog_To_Audio_Podcast_Queue::requeue( $post_id );
				return 'continued';
			}

			self::refresh_lock();

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 150 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on every host.
			}

			$chunk   = (string) $job['chunks'][ $job['index'] ];
			$context = array();

			if ( empty( $job['no_stitch'] ) && $job['request_ids'] && Blog_To_Audio_Podcast_Plugin::stitches( $job['model'] ) && time() - (int) $job['last_request'] < self::STITCH_WINDOW ) {
				$context['previous_request_ids'] = array_slice( $job['request_ids'], -3 );
			}

			$began  = microtime( true );
			$result = $api->tts( $chunk, $job['voice_id'], $job['model'], $job['voice'], $context, 120 );

			if ( is_wp_error( $result ) ) {
				// If the model won't take stitching, carry on without it.
				if ( ! empty( $context['previous_request_ids'] ) && 'post' === Blog_To_Audio_Podcast_API::kind( $result ) ) {
					$job['no_stitch'] = true;
					update_post_meta( $post_id, self::META_JOB, $job );
					continue;
				}

				return self::fail( $post, $job, $result );
			}

			$audio = Blog_To_Audio_Podcast_MP3::clean( $result['audio'] );

			if ( is_wp_error( $audio ) ) {
				return self::fail( $post, $job, $audio );
			}

			$written = Blog_To_Audio_Podcast_Audio::append_part( $job['part'], $audio['audio'] );

			if ( is_wp_error( $written ) ) {
				return self::fail( $post, $job, $written );
			}

			++$job['index'];

			$job['request_ids'][] = $result['request_id'];
			$job['request_ids']   = array_values( array_filter( array_slice( $job['request_ids'], -3 ) ) );
			$job['last_request']  = time();
			$job['frames']       += $audio['frames'];
			$job['seconds']      += $audio['seconds'];
			$job['bytes']        += strlen( $audio['audio'] );
			$job['credits']      += $result['cost'];
			$job['attempts']      = 0;

			update_post_meta( $post_id, self::META_JOB, $job );
			delete_post_meta( $post_id, Blog_To_Audio_Podcast_Queue::META_ERROR );

			if ( $result['cost'] > 0 ) {
				Blog_To_Audio_Podcast_Plugin::record_rate( $job['model'], Blog_To_Audio_Podcast_Text::length( $chunk ), $result['cost'] );
			}

			$times[] = microtime( true ) - $began;
		}

		$attachment_id = Blog_To_Audio_Podcast_Audio::save( $post, $job );

		if ( is_wp_error( $attachment_id ) ) {
			if ( 'missing_part' === $attachment_id->get_error_code() ) {
				delete_post_meta( $post_id, self::META_JOB );
				Blog_To_Audio_Podcast_Queue::requeue( $post_id );
				return 'continued';
			}

			return self::fail( $post, $job, $attachment_id );
		}

		Blog_To_Audio_Podcast_Queue::remove( $post_id );
		delete_transient( 'blog_to_audio_podcast_account' );

		/**
		 * Fires after a post's audio is created.
		 *
		 * @param int   $post_id       Post ID.
		 * @param int   $attachment_id The audio attachment.
		 * @param array $job           The finished job (model, voice, chars, credits, seconds).
		 */
		do_action( 'blog_to_audio_podcast_audio_created', $post_id, $attachment_id, $job );

		return 'done';
	}

	/**
	 * Plan a post's audio.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $settings Plugin settings.
	 * @return array|WP_Error
	 */
	private static function new_job( WP_Post $post, array $settings ) {
		$text = Blog_To_Audio_Podcast_Text::for_post( $post, $settings );

		if ( '' === $text['body'] ) {
			return new WP_Error( 'empty_text', __( 'There\'s no text in this post to read.', 'blog-to-audio-podcast' ), array( 'kind' => 'post' ) );
		}

		/**
		 * Filters the most characters one post's audio may use, as a guard
		 * against spending a month's credits on one enormous page.
		 *
		 * @param int     $max  Characters. Default 150,000 (about 2.5 hours of audio).
		 * @param WP_Post $post Post.
		 */
		$max = (int) apply_filters( 'blog_to_audio_podcast_max_chars', 150000, $post );

		if ( $max > 0 && $text['chars'] > $max ) {
			return new WP_Error(
				'too_long',
				/* translators: 1: characters in the post, 2: the limit. */
				sprintf( __( 'This post has %1$s characters to read, more than the %2$s allowed for one post.', 'blog-to-audio-podcast' ), number_format_i18n( $text['chars'] ), number_format_i18n( $max ) ),
				array( 'kind' => 'post' )
			);
		}

		$part = Blog_To_Audio_Podcast_Audio::new_part();

		if ( is_wp_error( $part ) ) {
			return $part;
		}

		$model = (string) $settings['model_id'];

		return array(
			'version'      => 1,
			'model'        => $model,
			'voice_id'     => (string) $settings['voice_id'],
			'voice_name'   => (string) $settings['voice_name'],
			'voice'        => Blog_To_Audio_Podcast_Plugin::voice_settings( $settings, $model ),
			'hash'         => $text['hash'],
			'chars'        => $text['chars'],
			'chunks'       => Blog_To_Audio_Podcast_Text::chunks( $text['text'], Blog_To_Audio_Podcast_Plugin::chunk_size( $model ) ),
			'index'        => 0,
			'request_ids'  => array(),
			'last_request' => 0,
			'part'         => $part,
			'frames'       => 0,
			'seconds'      => 0.0,
			'bytes'        => 0,
			'credits'      => 0,
			'attempts'     => 0,
			'started'      => time(),
		);
	}

	/**
	 * Whether a saved job can be carried on.
	 *
	 * @param mixed $job Saved job.
	 * @return bool
	 */
	private static function valid_job( $job ) {
		return is_array( $job ) && isset( $job['version'], $job['chunks'], $job['index'], $job['part'], $job['model'], $job['voice_id'], $job['voice'] )
			&& is_array( $job['chunks'] ) && $job['chunks'] && is_array( $job['voice'] ) && (int) $job['index'] <= count( $job['chunks'] );
	}

	/**
	 * Handle an error according to its kind.
	 *
	 * @param WP_Post    $post  Post.
	 * @param array|null $job   Job in progress, if any.
	 * @param WP_Error   $error Error.
	 * @return string Result.
	 */
	private static function fail( WP_Post $post, $job, WP_Error $error ) {
		$kind    = Blog_To_Audio_Podcast_API::kind( $error );
		$message = $error->get_error_message();

		if ( 'retry' === $kind ) {
			$attempts = is_array( $job ) ? (int) $job['attempts'] + 1 : 1;

			if ( $attempts <= self::MAX_ATTEMPTS ) {
				if ( is_array( $job ) ) {
					$job['attempts'] = $attempts;
					update_post_meta( $post->ID, self::META_JOB, $job );
				}

				// 30 s, 1, 2, 4, 8 then 15 minutes, or longer if asked.
				$data = $error->get_error_data();
				$wait = max( isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0, min( 900, 30 * (int) pow( 2, $attempts - 1 ) ) );

				Blog_To_Audio_Podcast_Queue::retry_later( $post->ID, $wait );
				update_post_meta( $post->ID, Blog_To_Audio_Podcast_Queue::META_ERROR, sanitize_text_field( $message ) );

				return 'retry';
			}

			$kind = 'post';
		}

		if ( 'pause' === $kind ) {
			if ( is_array( $job ) ) {
				update_post_meta( $post->ID, self::META_JOB, $job );
			}

			Blog_To_Audio_Podcast_Queue::requeue( $post->ID );
			Blog_To_Audio_Podcast_Queue::pause( $error->get_error_code(), $message );

			return 'paused';
		}

		if ( is_array( $job ) && ! empty( $job['part'] ) ) {
			Blog_To_Audio_Podcast_Audio::delete_part( $job['part'] );
		}

		delete_post_meta( $post->ID, self::META_JOB );
		Blog_To_Audio_Podcast_Queue::error( $post->ID, $message );

		return 'error';
	}

	/**
	 * Whether a post is still in the queue (it wasn't cancelled meanwhile).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function still_queued( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );

		return in_array( get_post_meta( $post_id, Blog_To_Audio_Podcast_Queue::META_STATUS, true ), array( 'queued', 'processing' ), true );
	}

	// Lock ------------------------------------------------------------------------

	/**
	 * Take the site-wide lock. Raw SQL, because add_option() isn't atomic and
	 * a persistent object cache could hide another request's lock.
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;

		$token = wp_generate_password( 20, false, false );
		$value = ( time() + self::LOCK_TTL ) . '|' . $token;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$taken = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value )
		);

		if ( ! $taken ) {
			// Someone holds it. Take it over only if it has expired.
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
			$expires = (int) strtok( $current, '|' );

			if ( '' === $current || $expires >= time() ) {
				return false;
			}

			$taken = $wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK, $current )
			);
		}
		// phpcs:enable

		if ( ! $taken ) {
			return false;
		}

		self::$lock_token = $token;
		wp_cache_delete( self::LOCK, 'options' );

		return true;
	}

	/**
	 * Push the lock's expiry back before a long request.
	 *
	 * @return void
	 */
	private static function refresh_lock() {
		global $wpdb;

		if ( '' === self::$lock_token ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
				( time() + self::LOCK_TTL ) . '|' . self::$lock_token,
				self::LOCK,
				'%|' . $wpdb->esc_like( self::$lock_token )
			)
		);
	}

	/**
	 * Release the lock, if it's still ours.
	 *
	 * @return void
	 */
	private static function unlock() {
		global $wpdb;

		if ( '' === self::$lock_token ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK, '%|' . $wpdb->esc_like( self::$lock_token ) )
		);

		self::$lock_token = '';
		wp_cache_delete( self::LOCK, 'options' );
	}

	/**
	 * Whether a run holds the lock right now.
	 *
	 * @return bool
	 */
	public static function is_running() {
		global $wpdb;

		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return '' !== $current && (int) strtok( $current, '|' ) >= time();
	}
}
