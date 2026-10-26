<?php
/**
 * Audio in the post editor and the posts list: the Audio box, the Audio
 * column, the "Create audio" bulk action, creating audio when a post is
 * published, and the ways to keep part of a post out of the audio (the
 * "Exclude from audio" block and the [no_audio] shortcode).
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor integration.
 */
final class Blog_To_Audio_Podcast_Editor {

	/**
	 * AJAX action for the Audio box.
	 */
	const AJAX = 'blog_to_audio_podcast_post';

	/**
	 * Nonce action for the Audio box's buttons.
	 */
	const NONCE = 'blog_to_audio_podcast_post';

	/**
	 * Nonce action for the "leave this post out" checkbox.
	 */
	const SAVE_NONCE = 'blog_to_audio_podcast_save';

	/**
	 * Plugin instance.
	 *
	 * @var Blog_To_Audio_Podcast_Plugin
	 */
	private static $plugin;

	/**
	 * Hook in.
	 *
	 * @param Blog_To_Audio_Podcast_Plugin $plugin Plugin.
	 * @return void
	 */
	public static function init( Blog_To_Audio_Podcast_Plugin $plugin ) {
		self::$plugin = $plugin;

		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 20, 3 );

		if ( ! shortcode_exists( 'no_audio' ) ) {
			add_shortcode( 'no_audio', array( __CLASS__, 'no_audio_shortcode' ) );
		}

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 10, 2 );
			add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
			add_action( 'current_screen', array( __CLASS__, 'list_screen' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		}
	}

	/**
	 * Capability needed to spend credits on a post's audio (besides being able
	 * to edit the post). Editors and administrators by default.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability needed to create or delete a post's audio.
		 * Users also need to be able to edit the post.
		 *
		 * @param string $capability Default "edit_others_posts" (Editors and Administrators).
		 */
		return (string) apply_filters( 'blog_to_audio_podcast_capability', 'edit_others_posts' );
	}

	/**
	 * Whether the current user may create and delete a post's audio.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_generate( $post_id ) {
		return current_user_can( 'edit_post', $post_id ) && current_user_can( self::capability() );
	}

	// Publishing, block and shortcode ---------------------------------------------

	/**
	 * Queue audio when a post is published, if that's turned on.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! self::$plugin->get_setting( 'auto_create' ) || ! self::$plugin->supports( $post ) || '' !== $post->post_password ) {
			return;
		}

		if ( Blog_To_Audio_Podcast_Audio::skipped( $post->ID ) || get_post_meta( $post->ID, Blog_To_Audio_Podcast_Audio::META_ID, true ) ) {
			return;
		}

		Blog_To_Audio_Podcast_Queue::add( $post->ID );

		// Run once the request is over, so the post has saved its content and meta.
		add_action( 'shutdown', array( 'Blog_To_Audio_Podcast_Queue', 'kick' ) );
	}

	/**
	 * Register the "Exclude from audio" block.
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'blog-to-audio-podcast-exclude',
			BLOG_TO_AUDIO_PODCAST_URL . 'assets/exclude-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ),
			BLOG_TO_AUDIO_PODCAST_VERSION,
			true
		);

		wp_register_style(
			'blog-to-audio-podcast-exclude',
			BLOG_TO_AUDIO_PODCAST_URL . 'assets/exclude-block.css',
			array(),
			BLOG_TO_AUDIO_PODCAST_VERSION
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'blog-to-audio-podcast-exclude', 'blog-to-audio-podcast' );
		}

		register_block_type(
			Blog_To_Audio_Podcast_Text::EXCLUDE_BLOCK,
			array(
				'editor_script' => 'blog-to-audio-podcast-exclude',
				'editor_style'  => 'blog-to-audio-podcast-exclude',
			)
		);
	}

	/**
	 * [no_audio]...[/no_audio]: shown on the page, left out of the audio.
	 *
	 * @param array|string $atts    Attributes (none).
	 * @param string|null  $content Content.
	 * @return string
	 */
	public static function no_audio_shortcode( $atts, $content = null ) {
		return null === $content ? '' : do_shortcode( $content );
	}

	// The Audio box -----------------------------------------------------------------

	/**
	 * Add the Audio box to supported post types.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public static function add_meta_box( $post_type, $post ) {
		if ( ! in_array( $post_type, self::$plugin->post_types(), true ) ) {
			return;
		}

		add_meta_box(
			'blog-to-audio-podcast',
			__( 'Audio', 'blog-to-audio-podcast' ),
			array( __CLASS__, 'render_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Print the Audio box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$status = self::status( $post );

		wp_nonce_field( self::SAVE_NONCE, 'blog_to_audio_podcast_nonce' );
		?>
		<div class="btap-box" data-btap-box data-post="<?php echo (int) $post->ID; ?>" data-working="<?php echo $status['working'] ? '1' : '0'; ?>" data-can="<?php echo $status['can'] ? '1' : '0'; ?>" aria-live="polite">
			<?php echo $status['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts. ?>
		</div>
		<p class="btap-skip">
			<label>
				<input type="checkbox" name="blog_to_audio_podcast_skip" value="1" <?php checked( Blog_To_Audio_Podcast_Audio::skipped( $post->ID ) ); ?>>
				<?php esc_html_e( 'Leave this post out (no audio, no player, not in the podcast)', 'blog-to-audio-podcast' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * The status part of the Audio box, which is redrawn as audio is made.
	 *
	 * @param WP_Post $post Post.
	 * @return array{html:string,working:bool,can:bool}
	 */
	public static function status( WP_Post $post ) {
		$audio   = Blog_To_Audio_Podcast_Audio::get( $post );
		$queue   = Blog_To_Audio_Podcast_Queue::status( $post->ID );
		$paused  = Blog_To_Audio_Podcast_Queue::paused();
		$can     = self::can_generate( $post->ID );
		$working = in_array( $queue['status'], array( 'queued', 'processing' ), true );
		$manage  = current_user_can( 'manage_options' );
		$page    = Blog_To_Audio_Podcast_Admin::url( 'overview' );

		ob_start();

		if ( '' === Blog_To_Audio_Podcast_Plugin::api_key() ) {
			echo '<p class="btap-note">' . esc_html__( 'Add your ElevenLabs API key to start creating audio.', 'blog-to-audio-podcast' );
			if ( $manage ) {
				echo ' <a href="' . esc_url( Blog_To_Audio_Podcast_Admin::url( 'voice' ) ) . '">' . esc_html__( 'Add it now', 'blog-to-audio-podcast' ) . '</a>';
			}
			echo '</p>';
		}

		if ( $working ) {
			self::print_progress( $post, $queue, $paused, $manage, $page );
		} elseif ( $audio ) {
			self::print_audio( $audio, $can );
		} elseif ( 'error' === $queue['status'] ) {
			echo '<p class="btap-state btap-state-error">' . Blog_To_Audio_Podcast_Icons::get( 'triangle-alert', 16 ) . '<span>' . esc_html__( 'The audio couldn\'t be created:', 'blog-to-audio-podcast' ) . ' ' . esc_html( $queue['error'] ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		} else {
			self::print_empty( $post );
		}

		if ( ! $working && $can && '' !== Blog_To_Audio_Podcast_Plugin::api_key() ) {
			self::print_buttons( $post, (bool) $audio, $paused );
		}

		return array(
			'html'    => (string) ob_get_clean(),
			'working' => $working && ! $paused,
			'can'     => $can,
		);
	}

	/**
	 * Box: audio in the queue or being made.
	 *
	 * @param WP_Post     $post   Post.
	 * @param array       $queue  Queue status.
	 * @param array|false $paused Why the queue is paused.
	 * @param bool        $manage Whether the user can manage the plugin.
	 * @param string      $page   Overview page URL.
	 * @return void
	 */
	private static function print_progress( WP_Post $post, array $queue, $paused, $manage, $page ) {
		if ( $paused ) {
			echo '<p class="btap-state btap-state-error">' . Blog_To_Audio_Podcast_Icons::get( 'circle-pause', 16 ) . '<span>' . esc_html__( 'Audio creation is paused:', 'blog-to-audio-podcast' ) . ' ' . esc_html( $paused['message'] ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			if ( $manage ) {
				echo '<p><a href="' . esc_url( $page ) . '">' . esc_html__( 'Fix it and resume', 'blog-to-audio-podcast' ) . '</a></p>';
			}
			return;
		}

		if ( $queue['chunks'] && ( $queue['chunk'] > 0 || 'processing' === $queue['status'] ) ) {
			$percent = (int) floor( 100 * $queue['chunk'] / max( 1, $queue['chunks'] ) );
			/* translators: 1: part being made, 2: number of parts. */
			$label = sprintf( __( 'Creating audio: part %1$s of %2$s', 'blog-to-audio-podcast' ), number_format_i18n( min( $queue['chunks'], $queue['chunk'] + 1 ) ), number_format_i18n( $queue['chunks'] ) );
		} else {
			$ahead   = Blog_To_Audio_Podcast_Queue::position( $post->ID );
			$percent = 0;
			$label   = $ahead
				/* translators: %s: number of posts ahead in the queue. */
				? sprintf( _n( 'Waiting in the queue: %s post ahead', 'Waiting in the queue: %s posts ahead', $ahead, 'blog-to-audio-podcast' ), number_format_i18n( $ahead ) )
				: __( 'Starting…', 'blog-to-audio-podcast' );
		}

		echo '<p class="btap-state">' . Blog_To_Audio_Podcast_Icons::get( 'loader-circle', 16, 'btap-spin' ) . '<span>' . esc_html( $label ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		echo '<div class="btap-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $percent . '" aria-label="' . esc_attr__( 'Audio progress', 'blog-to-audio-podcast' ) . '"><span style="width:' . (int) $percent . '%"></span></div>';

		if ( $queue['retry_at'] > time() ) {
			/* translators: 1: error message, 2: time until the retry, e.g. "2 mins". */
			echo '<p class="btap-note">' . esc_html( sprintf( __( '%1$s Trying again in %2$s.', 'blog-to-audio-podcast' ), $queue['error'], human_time_diff( time(), $queue['retry_at'] ) ) ) . '</p>';
		}

		echo '<p class="btap-note">' . esc_html__( 'You can leave this page: the audio keeps being made in the background.', 'blog-to-audio-podcast' ) . '</p>';
	}

	/**
	 * Box: the post's audio.
	 *
	 * @param array $audio Audio details.
	 * @param bool  $can   Whether the user may recreate it.
	 * @return void
	 */
	private static function print_audio( array $audio, $can ) {
		$models = Blog_To_Audio_Podcast_Plugin::models();
		$model  = isset( $models[ $audio['model'] ] ) ? $models[ $audio['model'] ]['name'] : $audio['model'];
		$parts  = array_filter(
			array(
				Blog_To_Audio_Podcast_Audio::format_time( $audio['duration'] ),
				$audio['voice_name'],
				$model,
			)
		);
		?>
		<audio class="btap-audio" controls preload="none" src="<?php echo esc_url( $audio['url'] ); ?>"></audio>
		<p class="btap-meta"><?php echo esc_html( implode( ' · ', $parts ) ); ?></p>
		<p class="btap-meta">
			<?php
			/* translators: 1: date, 2: credits used. */
			echo esc_html( sprintf( __( 'Created %1$s · %2$s credits', 'blog-to-audio-podcast' ), date_i18n( get_option( 'date_format' ), (int) $audio['created'] ), number_format_i18n( (int) $audio['credits'] ) ) );
			?>
		</p>
		<?php if ( $audio['stale'] ) : ?>
			<p class="btap-state btap-state-warn">
				<?php echo Blog_To_Audio_Podcast_Icons::get( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php echo esc_html( $can ? __( 'The post has changed since this audio was made. Recreate it to match.', 'blog-to-audio-podcast' ) : __( 'The post has changed since this audio was made.', 'blog-to-audio-podcast' ) ); ?></span>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Box: no audio yet.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private static function print_empty( WP_Post $post ) {
		$settings = self::$plugin->get_settings();
		$chars    = 'auto-draft' === $post->post_status ? 0 : Blog_To_Audio_Podcast_Posts::chars( $post, $settings );

		echo '<p class="btap-state">' . Blog_To_Audio_Podcast_Icons::get( 'headphones', 16 ) . '<span>' . esc_html__( 'No audio yet.', 'blog-to-audio-podcast' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.

		if ( $chars ) {
			$rate = Blog_To_Audio_Podcast_Plugin::credit_rate( (string) $settings['model_id'] );

			echo '<p class="btap-meta">' . esc_html(
				sprintf(
					/* translators: 1: characters, 2: estimated credits, 3: estimated length. */
					__( '%1$s characters: about %2$s credits and %3$s of audio.', 'blog-to-audio-podcast' ),
					number_format_i18n( $chars ),
					number_format_i18n( (int) ceil( $chars * $rate['rate'] ) ),
					Blog_To_Audio_Podcast_Audio::human_duration( $chars / 15 )
				)
			) . '</p>';
		}
	}

	/**
	 * Box: Create / Recreate / Delete.
	 *
	 * @param WP_Post     $post      Post.
	 * @param bool        $has_audio Whether there's audio.
	 * @param array|false $paused    Why the queue is paused.
	 * @return void
	 */
	private static function print_buttons( WP_Post $post, $has_audio, $paused ) {
		$saved = 'auto-draft' !== $post->post_status;
		?>
		<p class="btap-actions">
			<button type="button" class="button<?php echo $has_audio ? '' : ' button-primary'; ?>" data-btap-action="create"<?php disabled( ! $saved || (bool) $paused ); ?>>
				<?php echo Blog_To_Audio_Podcast_Icons::get( $has_audio ? 'refresh-cw' : 'audio-lines', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php echo esc_html( $has_audio ? __( 'Recreate audio', 'blog-to-audio-podcast' ) : __( 'Create audio', 'blog-to-audio-podcast' ) ); ?>
			</button>
			<?php if ( $has_audio ) : ?>
				<button type="button" class="button-link button-link-delete" data-btap-action="delete"><?php esc_html_e( 'Delete audio', 'blog-to-audio-podcast' ); ?></button>
			<?php endif; ?>
		</p>
		<p class="btap-note">
			<?php
			echo esc_html(
				$saved
					? __( 'Uses the last saved version of the post.', 'blog-to-audio-podcast' )
					: __( 'Save the post first.', 'blog-to-audio-podcast' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Save the "leave this post out" checkbox.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['blog_to_audio_podcast_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['blog_to_audio_podcast_nonce'] ) ), self::SAVE_NONCE ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! self::$plugin->supports( $post ) ) {
			return;
		}

		if ( ! empty( $_POST['blog_to_audio_podcast_skip'] ) ) {
			update_post_meta( $post_id, Blog_To_Audio_Podcast_Audio::META_SKIP, 1 );

			// Waiting in the queue? Not any more.
			if ( 'queued' === get_post_meta( $post_id, Blog_To_Audio_Podcast_Queue::META_STATUS, true ) ) {
				Blog_To_Audio_Podcast_Queue::remove( $post_id );
			}
		} else {
			delete_post_meta( $post_id, Blog_To_Audio_Podcast_Audio::META_SKIP );
		}

		Blog_To_Audio_Podcast_Audio::touch_feed();
	}

	/**
	 * AJAX for the Audio box: create, delete, status, run.
	 *
	 * @return void
	 */
	public static function ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$action  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$post    = get_post( $post_id );

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) || ! self::$plugin->supports( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'blog-to-audio-podcast' ) ), 403 );
		}

		if ( in_array( $action, array( 'create', 'delete', 'run' ), true ) && ! self::can_generate( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'blog-to-audio-podcast' ) ), 403 );
		}

		$busy = false;

		switch ( $action ) {
			case 'create':
				if ( 'auto-draft' !== $post->post_status ) {
					Blog_To_Audio_Podcast_Queue::add( $post_id, true );
					Blog_To_Audio_Podcast_Queue::kick();
				}
				break;

			case 'delete':
				Blog_To_Audio_Podcast_Audio::delete( $post_id );
				break;

			case 'run':
				// Work on the queue while someone watches (the post is at the
				// front, so it's what gets made). Short, so no time limit bites.
				$result = Blog_To_Audio_Podcast_Generator::run( 20 );
				$busy   = 'busy' === $result['result'];
				Blog_To_Audio_Podcast_Queue::kick( $busy ? 60 : 0 );
				break;
		}

		// Fresh meta after the work above.
		wp_cache_delete( $post_id, 'post_meta' );

		$status         = self::status( get_post( $post_id ) );
		$status['busy'] = $busy;

		wp_send_json_success( $status );
	}

	// Posts list ----------------------------------------------------------------------

	/**
	 * On a supported posts list, add the Audio column and bulk action.
	 *
	 * @param WP_Screen $screen Screen.
	 * @return void
	 */
	public static function list_screen( $screen ) {
		if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, self::$plugin->post_types(), true ) ) {
			return;
		}

		add_filter( 'manage_' . $screen->id . '_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_' . $screen->post_type . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		if ( current_user_can( self::capability() ) ) {
			add_filter( 'bulk_actions-' . $screen->id, array( __CLASS__, 'add_bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen->id, array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		}

		add_action( 'admin_notices', array( __CLASS__, 'list_notices' ) );
	}

	/**
	 * Add the Audio column after the title.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['btap_audio'] = '<span class="btap-col-head" title="' . esc_attr__( 'Audio', 'blog-to-audio-podcast' ) . '">' . Blog_To_Audio_Podcast_Icons::get( 'headphones', 16 ) . '<span class="screen-reader-text">' . esc_html__( 'Audio', 'blog-to-audio-podcast' ) . '</span></span>';
			}
		}

		if ( ! isset( $new['btap_audio'] ) ) {
			$new['btap_audio'] = esc_html__( 'Audio', 'blog-to-audio-podcast' );
		}

		return $new;
	}

	/**
	 * Print a post's audio status in the column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'btap_audio' !== $column ) {
			return;
		}

		$status = (string) get_post_meta( $post_id, Blog_To_Audio_Podcast_Queue::META_STATUS, true );
		$audio  = in_array( $status, array( 'queued', 'processing' ), true ) ? null : Blog_To_Audio_Podcast_Audio::get( $post_id );

		if ( Blog_To_Audio_Podcast_Audio::skipped( $post_id ) ) {
			$cell = array( 'eye-off', __( 'Left out', 'blog-to-audio-podcast' ), 'muted', __( 'This post is left out of the audio and the podcast.', 'blog-to-audio-podcast' ) );
		} elseif ( 'queued' === $status ) {
			$cell = array( 'hourglass', __( 'Queued', 'blog-to-audio-podcast' ), 'muted', __( 'Waiting in the audio queue.', 'blog-to-audio-podcast' ) );
		} elseif ( 'processing' === $status ) {
			$cell = array( 'loader-circle', __( 'Creating', 'blog-to-audio-podcast' ), 'busy', __( 'Audio is being created.', 'blog-to-audio-podcast' ) );
		} elseif ( 'error' === $status ) {
			$cell = array( 'triangle-alert', __( 'Failed', 'blog-to-audio-podcast' ), 'bad', (string) get_post_meta( $post_id, Blog_To_Audio_Podcast_Queue::META_ERROR, true ) );
		} elseif ( $audio && $audio['stale'] ) {
			$cell = array( 'headphones', Blog_To_Audio_Podcast_Audio::format_time( $audio['duration'] ), 'warn', __( 'The post has changed since its audio was made.', 'blog-to-audio-podcast' ) );
		} elseif ( $audio ) {
			$cell = array( 'headphones', Blog_To_Audio_Podcast_Audio::format_time( $audio['duration'] ), 'good', __( 'Has audio.', 'blog-to-audio-podcast' ) );
		} else {
			echo '<span class="btap-col btap-col-none" aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'No audio', 'blog-to-audio-podcast' ) . '</span>';
			return;
		}

		printf(
			'<span class="btap-col btap-col-%1$s" title="%2$s">%3$s<span>%4$s</span></span><span class="screen-reader-text">%5$s</span>',
			esc_attr( $cell[2] ),
			esc_attr( $cell[3] ),
			Blog_To_Audio_Podcast_Icons::get( $cell[0], 15 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			esc_html( $cell[1] ),
			esc_html( $cell[3] )
		);
	}

	/**
	 * Add "Create audio" to the bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function add_bulk_action( $actions ) {
		$actions['btap_create_audio'] = __( 'Create audio', 'blog-to-audio-podcast' );

		return $actions;
	}

	/**
	 * Queue the chosen posts that need audio.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param int[]  $ids      Post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'btap_create_audio' !== $action ) {
			return $redirect;
		}

		$queue = array();

		foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || ! self::can_generate( $post_id ) || ! self::$plugin->supports( $post ) || Blog_To_Audio_Podcast_Audio::skipped( $post_id ) ) {
				continue;
			}

			$audio = Blog_To_Audio_Podcast_Audio::get( $post );

			// Posts with up-to-date audio are left as they are.
			if ( $audio && ! $audio['stale'] ) {
				continue;
			}

			$queue[] = $post_id;
		}

		$added = Blog_To_Audio_Podcast_Queue::add_many( $queue );
		Blog_To_Audio_Podcast_Queue::kick();

		return add_query_arg( 'btap_queued', $added, remove_query_arg( 'btap_queued', $redirect ) );
	}

	/**
	 * Notices on the posts list: after the bulk action, and while paused.
	 *
	 * @return void
	 */
	public static function list_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['btap_queued'] ) ) {
			$added = absint( $_GET['btap_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$text  = $added
				/* translators: %s: number of posts. */
				? sprintf( _n( '%s post added to the audio queue. Its audio is being created in the background.', '%s posts added to the audio queue. Their audio is being created in the background.', $added, 'blog-to-audio-podcast' ), number_format_i18n( $added ) )
				: __( 'No posts were added to the audio queue: the ones you chose already have up-to-date audio, are left out, or are queued already.', 'blog-to-audio-podcast' );

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s %3$s</p></div>',
				$added ? 'success' : 'info',
				esc_html( $text ),
				$added && current_user_can( 'manage_options' ) ? '<a href="' . esc_url( Blog_To_Audio_Podcast_Admin::url( 'overview' ) ) . '">' . esc_html__( 'Watch the progress', 'blog-to-audio-podcast' ) . '</a>' : ''
			);
		}

		Blog_To_Audio_Podcast_Admin::paused_notice();
	}

	// Assets ------------------------------------------------------------------------------

	/**
	 * Styles for the Audio box and column, and the box's script.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->post_type, self::$plugin->post_types(), true ) || ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'blog-to-audio-podcast-editor', BLOG_TO_AUDIO_PODCAST_URL . 'assets/editor.css', array(), BLOG_TO_AUDIO_PODCAST_VERSION );

		if ( 'edit.php' === $hook ) {
			return;
		}

		wp_enqueue_script( 'blog-to-audio-podcast-editor', BLOG_TO_AUDIO_PODCAST_URL . 'assets/editor.js', array(), BLOG_TO_AUDIO_PODCAST_VERSION, true );

		wp_localize_script(
			'blog-to-audio-podcast-editor',
			'blog_to_audio_podcast_editor',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'confirmDelete' => __( 'Delete this post\'s audio? It will also leave the podcast feed. This can\'t be undone.', 'blog-to-audio-podcast' ),
				'error'         => __( 'Something went wrong. Reload the page to see the latest status.', 'blog-to-audio-podcast' ),
			)
		);
	}
}
