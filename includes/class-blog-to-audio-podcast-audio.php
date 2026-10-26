<?php
/**
 * Stores each post's audio as a Media Library attachment and keeps track of it.
 *
 * Files go in uploads/blog-to-audio-podcast/. As ordinary attachments they
 * are backed up with the rest of the media, and offload plugins (S3, CDNs)
 * pick them up the same way they pick up images. Each file name ends in a
 * random string, so audio made for a draft can't be found by guessing.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audio storage.
 */
final class Blog_To_Audio_Podcast_Audio {

	/**
	 * Post meta: details of the post's audio (array).
	 */
	const META = '_btap_audio';

	/**
	 * Post meta: the audio attachment's ID, for queries.
	 */
	const META_ID = '_btap_audio_id';

	/**
	 * Post meta: set when the post has changed since its audio was made.
	 */
	const META_STALE = '_btap_stale';

	/**
	 * Post meta: set to leave the post out (no audio, no player, not in the feed).
	 */
	const META_SKIP = '_btap_skip';

	/**
	 * Attachment meta: the post the audio belongs to.
	 */
	const META_FOR = '_btap_audio_for';

	/**
	 * Folder inside uploads.
	 */
	const DIR = 'blog-to-audio-podcast';

	/**
	 * Option bumped whenever audio is added or removed, so podcast apps
	 * aren't told the feed is unchanged.
	 */
	const CHANGED_OPTION = 'blog_to_audio_podcast_feed_changed';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'save_post', array( __CLASS__, 'check_stale' ), 20, 2 );
		add_action( 'save_post', array( __CLASS__, 'sync_visibility' ), 30 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 30, 3 );
	}

	/**
	 * Whether a post's audio may be public: it's published, has no password
	 * and isn't left out.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_public( WP_Post $post ) {
		return 'publish' === $post->post_status && '' === (string) $post->post_password && ! self::skipped( $post->ID );
	}

	/**
	 * Keep the audio attachment private while its post isn't public. Core's
	 * media screens, attachment pages and REST API then only show it to people
	 * who could see the post anyway, while the player keeps working for them.
	 *
	 * @param int|WP_Post $post Post.
	 * @return void
	 */
	public static function sync_visibility( $post ) {
		global $wpdb;

		$post = get_post( $post );

		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type || wp_is_post_revision( $post ) ) {
			return;
		}

		$attachment = get_post( (int) get_post_meta( $post->ID, self::META_ID, true ) );
		$status     = self::is_public( $post ) ? 'inherit' : 'private';

		if ( $attachment instanceof WP_Post && 'attachment' === $attachment->post_type && $status !== $attachment->post_status ) {
			// Direct, so changing it doesn't fire every save_post hook again.
			$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $attachment->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $attachment->ID );
		}
	}

	/**
	 * A post was published, unpublished, made private or trashed.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status ) {
			self::sync_visibility( $post );
			self::touch_feed();
		}
	}

	/**
	 * A post's audio, if it has some.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array|null id, url, duration, bytes, model, voice_id, voice_name, chars, credits, hash, created, stale.
	 */
	public static function get( $post ) {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$audio   = get_post_meta( $post_id, self::META, true );

		if ( ! is_array( $audio ) || empty( $audio['id'] ) ) {
			return null;
		}

		$url = wp_get_attachment_url( (int) $audio['id'] );

		if ( ! $url ) {
			return null;
		}

		return array_merge(
			array(
				'duration'   => 0,
				'bytes'      => 0,
				'model'      => '',
				'voice_id'   => '',
				'voice_name' => '',
				'chars'      => 0,
				'credits'    => 0,
				'hash'       => '',
				'created'    => 0,
			),
			$audio,
			array(
				'id'    => (int) $audio['id'],
				'url'   => $url,
				'stale' => (bool) get_post_meta( $post_id, self::META_STALE, true ),
			)
		);
	}

	/**
	 * Whether a post is set to be left out.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function skipped( $post_id ) {
		return (bool) get_post_meta( $post_id, self::META_SKIP, true );
	}

	/**
	 * The audio folder, created (with an index.php) if needed.
	 *
	 * @param string $sub Optional subfolder ("parts" for work in progress).
	 * @return array{path:string,url:string}|WP_Error
	 */
	public static function dir( $sub = '' ) {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'upload_dir', (string) $uploads['error'], array( 'kind' => 'pause' ) );
		}

		$path = trailingslashit( $uploads['basedir'] ) . self::DIR . ( '' !== $sub ? '/' . $sub : '' );
		$url  = trailingslashit( $uploads['baseurl'] ) . self::DIR . ( '' !== $sub ? '/' . $sub : '' );

		if ( ! wp_mkdir_p( $path ) ) {
			return new WP_Error(
				'upload_dir',
				/* translators: %s: folder path. */
				sprintf( __( 'Couldn\'t create the folder %s. Check that the uploads folder is writable.', 'blog-to-audio-podcast' ), $path ),
				array( 'kind' => 'pause' )
			);
		}

		// No folder listings.
		if ( ! file_exists( $path . '/index.php' ) ) {
			file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return array(
			'path' => $path,
			'url'  => $url,
		);
	}

	/**
	 * A new, empty work-in-progress file for a post's audio.
	 *
	 * @return string|WP_Error File name (inside the parts folder).
	 */
	public static function new_part() {
		$dir = self::dir( 'parts' );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$name = wp_generate_password( 24, false, false ) . '.part';

		if ( false === file_put_contents( $dir['path'] . '/' . $name, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'write_failed', __( 'Couldn\'t write to the uploads folder.', 'blog-to-audio-podcast' ), array( 'kind' => 'pause' ) );
		}

		return $name;
	}

	/**
	 * Full path of a work-in-progress file.
	 *
	 * @param string $name File name from {@see new_part()}.
	 * @return string Empty if the name isn't one of ours.
	 */
	public static function part_path( $name ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{24}\.part$/', (string) $name ) ) {
			return '';
		}

		$dir = self::dir( 'parts' );

		return is_wp_error( $dir ) ? '' : $dir['path'] . '/' . $name;
	}

	/**
	 * Add audio to a work-in-progress file.
	 *
	 * @param string $name  File name.
	 * @param string $audio MP3 frames.
	 * @return true|WP_Error
	 */
	public static function append_part( $name, $audio ) {
		$path = self::part_path( $name );

		if ( '' === $path || false === file_put_contents( $path, $audio, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'write_failed', __( 'Couldn\'t write the audio to the uploads folder. Check that it\'s writable and the disk isn\'t full.', 'blog-to-audio-podcast' ), array( 'kind' => 'pause' ) );
		}

		return true;
	}

	/**
	 * Delete a work-in-progress file.
	 *
	 * @param string $name File name.
	 * @return void
	 */
	public static function delete_part( $name ) {
		$path = self::part_path( $name );

		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Turn a finished work-in-progress file into the post's audio.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $job  Finished job (part, frames, seconds, bytes, model, voice...).
	 * @return int|WP_Error Attachment ID.
	 */
	public static function save( WP_Post $post, array $job ) {
		$part = self::part_path( isset( $job['part'] ) ? $job['part'] : '' );
		$dir  = self::dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( '' === $part || ! file_exists( $part ) || ! filesize( $part ) ) {
			return new WP_Error( 'missing_part', __( 'The audio in progress went missing. It will be made again.', 'blog-to-audio-podcast' ), array( 'kind' => 'post' ) );
		}

		$slug = $post->post_name ? sanitize_title( rawurldecode( $post->post_name ) ) : 'post';
		$slug = substr( '' !== $slug ? $slug : 'post', 0, 60 );
		$name = wp_unique_filename( $dir['path'], sanitize_file_name( $slug . '-' . $post->ID . '-' . strtolower( wp_generate_password( 8, false, false ) ) . '.mp3' ) );
		$path = $dir['path'] . '/' . $name;

		// Move the finished file into place (copy if the move isn't allowed).
		$moved = @rename( $part, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename

		if ( ! $moved && @copy( $part, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $part );
			$moved = true;
		}

		if ( ! $moved ) {
			return new WP_Error( 'write_failed', __( 'Couldn\'t save the audio file in the uploads folder.', 'blog-to-audio-podcast' ), array( 'kind' => 'pause' ) );
		}

		// Same permissions as other uploads.
		$stat = @stat( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $stat ) {
			@chmod( $path, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		$bytes   = (int) filesize( $path );
		$seconds = ! empty( $job['seconds'] ) ? (float) $job['seconds'] : Blog_To_Audio_Podcast_MP3::seconds_from_size( $bytes );
		$title   = wp_strip_all_tags( get_the_title( $post ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'audio/mpeg',
				/* translators: %s: post title. */
				'post_title'     => sprintf( __( 'Audio: %s', 'blog-to-audio-podcast' ), $title ),
				'post_content'   => '',
				'post_status'    => self::is_public( $post ) ? 'inherit' : 'private',
				// Owned by whoever made it (an Editor or Administrator), or by
				// nobody when a background job did. Never by the post's author,
				// who could otherwise delete it and have it made again.
				'post_author'    => current_user_can( Blog_To_Audio_Podcast_Editor::capability() ) ? get_current_user_id() : 0,
			),
			$path,
			$post->ID,
			true
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $path );

			return new WP_Error( 'attachment_failed', __( 'Couldn\'t add the audio to the Media Library.', 'blog-to-audio-podcast' ), array( 'kind' => 'pause' ) );
		}

		// The same audio details WordPress reads from uploaded MP3s, filled in
		// directly since the format is known.
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'filesize'         => $bytes,
				'mime_type'        => 'audio/mpeg',
				'length'           => (int) round( $seconds ),
				'length_formatted' => self::format_time( $seconds ),
				'dataformat'       => 'mp3',
				'codec'            => 'LAME',
				'sample_rate'      => 44100,
				'bitrate'          => 128000,
				'bitrate_mode'     => 'cbr',
				'channels'         => 1,
				'lossless'         => false,
				'title'            => $title,
				'artist'           => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			)
		);

		update_post_meta( $attachment_id, self::META_FOR, $post->ID );

		$previous = get_post_meta( $post->ID, self::META, true );

		update_post_meta(
			$post->ID,
			self::META,
			array(
				'id'         => (int) $attachment_id,
				'duration'   => round( $seconds, 2 ),
				'bytes'      => $bytes,
				'model'      => isset( $job['model'] ) ? (string) $job['model'] : '',
				'voice_id'   => isset( $job['voice_id'] ) ? (string) $job['voice_id'] : '',
				'voice_name' => isset( $job['voice_name'] ) ? (string) $job['voice_name'] : '',
				'chars'      => isset( $job['chars'] ) ? (int) $job['chars'] : 0,
				'credits'    => isset( $job['credits'] ) ? (int) $job['credits'] : 0,
				'hash'       => isset( $job['hash'] ) ? (string) $job['hash'] : '',
				'created'    => time(),
			)
		);
		update_post_meta( $post->ID, self::META_ID, (int) $attachment_id );
		delete_post_meta( $post->ID, self::META_STALE );

		// The old audio goes once the new audio is in place.
		if ( is_array( $previous ) && ! empty( $previous['id'] ) && (int) $previous['id'] !== (int) $attachment_id ) {
			wp_delete_attachment( (int) $previous['id'], true );
		}

		self::touch_feed();

		return (int) $attachment_id;
	}

	/**
	 * Delete a post's audio.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete( $post_id ) {
		$audio = get_post_meta( $post_id, self::META, true );

		self::forget( $post_id );

		if ( is_array( $audio ) && ! empty( $audio['id'] ) ) {
			wp_delete_attachment( (int) $audio['id'], true );
		}
	}

	/**
	 * Remove a post's audio records (not the file).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function forget( $post_id ) {
		delete_post_meta( $post_id, self::META );
		delete_post_meta( $post_id, self::META_ID );
		delete_post_meta( $post_id, self::META_STALE );
		self::touch_feed();
	}

	/**
	 * Someone deleted an audio file from the Media Library: forget it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function on_delete_attachment( $attachment_id ) {
		$post_id = (int) get_post_meta( $attachment_id, self::META_FOR, true );

		if ( $post_id && (int) get_post_meta( $post_id, self::META_ID, true ) === (int) $attachment_id ) {
			self::forget( $post_id );
		}
	}

	/**
	 * A post is being deleted for good: its audio goes with it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete_post( $post_id ) {
		if ( 'attachment' === get_post_type( $post_id ) ) {
			return;
		}

		$job = get_post_meta( $post_id, Blog_To_Audio_Podcast_Generator::META_JOB, true );

		if ( is_array( $job ) && ! empty( $job['part'] ) ) {
			self::delete_part( $job['part'] );
		}

		if ( get_post_meta( $post_id, self::META_ID, true ) ) {
			self::delete( $post_id );
		}
	}

	/**
	 * After a post is saved, mark its audio out of date if the words changed.
	 * Audio is never remade on its own: that would spend credits unasked.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function check_stale( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}

		$audio = get_post_meta( $post_id, self::META, true );

		if ( ! is_array( $audio ) || empty( $audio['hash'] ) ) {
			return;
		}

		if ( Blog_To_Audio_Podcast_Text::hash( $post ) !== $audio['hash'] ) {
			update_post_meta( $post_id, self::META_STALE, 1 );
		} else {
			delete_post_meta( $post_id, self::META_STALE );
		}
	}

	/**
	 * Note that the podcast feed has changed.
	 *
	 * @return void
	 */
	public static function touch_feed() {
		update_option( self::CHANGED_OPTION, time(), false );
	}

	/**
	 * Seconds as "8:05" or "1:02:09".
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function format_time( $seconds ) {
		$seconds = (int) round( $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		return $hours ? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs ) : sprintf( '%d:%02d', $minutes, $secs );
	}

	/**
	 * Seconds as a short phrase: "8 min", "1 hr 2 min", "45 sec".
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function human_duration( $seconds ) {
		$seconds = (int) round( $seconds );

		if ( $seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%d sec', 'blog-to-audio-podcast' ), $seconds );
		}

		$minutes = (int) round( $seconds / 60 );

		if ( $minutes < 60 ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%d min', 'blog-to-audio-podcast' ), $minutes );
		}

		/* translators: 1: hours, 2: minutes. */
		return sprintf( __( '%1$d hr %2$d min', 'blog-to-audio-podcast' ), (int) floor( $minutes / 60 ), $minutes % 60 );
	}
}
