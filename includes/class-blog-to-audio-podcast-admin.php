<?php
/**
 * Admin side: the Content Powered > Blog Post to Audio Podcast page and the Plugins-screen link.
 *
 * Five tabs: Overview (bulk creation with a cost estimate, progress, recent
 * audio), ElevenLabs (key, model, voice), Content (what gets read), Player
 * and Podcast. Built from WordPress's own admin pieces: the post editor's
 * box layout (#poststuff, .postbox), form tables, buttons and notices.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class Blog_To_Audio_Podcast_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'blog-to-audio-podcast';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'podcast';

	/**
	 * Capability needed for everything on the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action for the page's AJAX and forms.
	 */
	const NONCE = 'blog_to_audio_podcast_admin';

	/**
	 * AJAX action.
	 */
	const AJAX = 'blog_to_audio_podcast_admin';

	/**
	 * Option tracking the current bulk run's size, for its progress bar.
	 */
	const BATCH_OPTION = 'blog_to_audio_podcast_batch';

	/**
	 * Tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview' => 'layout-dashboard',
		'voice'    => 'audio-lines',
		'content'  => 'file-text',
		'player'   => 'circle-play',
		'podcast'  => 'rss',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Blog_To_Audio_Podcast_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Blog_To_Audio_Podcast_Plugin $plugin Plugin instance.
	 */
	public function __construct( Blog_To_Audio_Podcast_Plugin $plugin ) {
		$this->plugin = $plugin;

		Blog_To_Audio_Podcast_Menu::init();
		Blog_To_Audio_Podcast_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( BLOG_TO_AUDIO_PODCAST_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url( 'overview' ) ),
			esc_html__( 'Settings', 'blog-to-audio-podcast' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * URL of a tab on the page.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab = 'overview', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Blog_To_Audio_Podcast_Icons::get( self::ICON, 17, 'blog-to-audio-podcast-menu-icon' );
		$menu_title = $icon . '<span class="blog-to-audio-podcast-menu-label">' . esc_html__( 'Blog Post to Audio Podcast', 'blog-to-audio-podcast' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Blog_To_Audio_Podcast_Menu::SLUG,
			__( 'Blog Post to Audio Podcast', 'blog-to-audio-podcast' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
			add_filter( 'screen_options_show_screen', array( $this, 'hide_screen_options' ), 10, 2 );
		}
	}

	/**
	 * No Screen Options tab: it would sit on top of the page header.
	 *
	 * @param bool      $show   Whether to show the tab.
	 * @param WP_Screen $screen Current screen.
	 * @return bool
	 */
	public function hide_screen_options( $show, $screen ) {
		return $screen instanceof WP_Screen && $screen->id === $this->hook_suffix ? false : $show;
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' btap-page' : $classes;
	}

	/**
	 * The tab being viewed.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'overview';
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'overview' => __( 'Overview', 'blog-to-audio-podcast' ),
			'voice'    => __( 'ElevenLabs', 'blog-to-audio-podcast' ),
			'content'  => __( 'Content', 'blog-to-audio-podcast' ),
			'player'   => __( 'Player', 'blog-to-audio-podcast' ),
			'podcast'  => __( 'Podcast', 'blog-to-audio-podcast' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	// Page actions --------------------------------------------------------------

	/**
	 * Before the page is drawn: handle the queue buttons and refresh links.
	 *
	 * @return void
	 */
	public function load_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- Checked below before acting.
		$action = isset( $_REQUEST['btap_do'] ) ? sanitize_key( wp_unslash( $_REQUEST['btap_do'] ) ) : '';
		// phpcs:enable

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$tab    = $this->current_tab();
		$notice = '';

		switch ( $action ) {
			case 'resume':
				Blog_To_Audio_Podcast_Queue::resume();
				$notice = 'resumed';
				break;
			case 'pause':
				Blog_To_Audio_Podcast_Queue::pause( 'manual', __( 'You paused it.', 'blog-to-audio-podcast' ) );
				$notice = 'paused';
				break;
			case 'cancel':
				Blog_To_Audio_Podcast_Queue::cancel_all();
				delete_option( self::BATCH_OPTION );
				$notice = 'cancelled';
				break;
			case 'retry':
				$failed = Blog_To_Audio_Podcast_Queue::ids( 'error' );
				foreach ( $failed as $post_id ) {
					Blog_To_Audio_Podcast_Queue::remove( $post_id );
				}
				Blog_To_Audio_Podcast_Queue::add_many( $failed );
				self::grow_batch( count( $failed ) );
				Blog_To_Audio_Podcast_Queue::kick();
				$notice = 'retrying';
				break;
			case 'clear':
				foreach ( Blog_To_Audio_Podcast_Queue::ids( 'error' ) as $post_id ) {
					Blog_To_Audio_Podcast_Queue::remove( $post_id );
				}
				$notice = 'cleared';
				break;
			case 'refresh':
				delete_transient( 'blog_to_audio_podcast_voices' );
				delete_transient( 'blog_to_audio_podcast_models' );
				delete_transient( 'blog_to_audio_podcast_account' );
				$notice = 'refreshed';
				break;
		}

		wp_safe_redirect( self::url( $tab, array( 'btap_notice' => $notice ) ) );
		exit;
	}

	/**
	 * A small form that runs one of the page's actions.
	 *
	 * @param string $action  Action.
	 * @param string $label   Button label.
	 * @param string $class   Button classes.
	 * @param string $icon    Lucide icon.
	 * @param string $confirm Confirmation question, if any.
	 * @return string HTML.
	 */
	private function action_button( $action, $label, $class = 'button', $icon = '', $confirm = '' ) {
		return sprintf(
			'<form method="post" action="%1$s" class="btap-inline-form"%2$s>%3$s<input type="hidden" name="btap_do" value="%4$s"><button type="submit" class="%5$s">%6$s%7$s</button></form>',
			esc_url( self::url( $this->current_tab() ) ),
			'' !== $confirm ? ' data-btap-confirm="' . esc_attr( $confirm ) . '"' : '',
			wp_nonce_field( self::NONCE, '_wpnonce', true, false ),
			esc_attr( $action ),
			esc_attr( $class ),
			'' !== $icon ? Blog_To_Audio_Podcast_Icons::get( $icon, 16 ) : '',
			esc_html( $label )
		);
	}

	/**
	 * The notice after an action.
	 *
	 * @return void
	 */
	private function render_notice() {
		$notice   = isset( $_GET['btap_notice'] ) ? sanitize_key( wp_unslash( $_GET['btap_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$messages = array(
			'resumed'   => __( 'The queue is running again.', 'blog-to-audio-podcast' ),
			'paused'    => __( 'The queue is paused. Nothing more will be sent to ElevenLabs until you resume it.', 'blog-to-audio-podcast' ),
			'cancelled' => __( 'The queue is empty. Audio that was already made is kept.', 'blog-to-audio-podcast' ),
			'retrying'  => __( 'The failed posts are back in the queue.', 'blog-to-audio-podcast' ),
			'cleared'   => __( 'The list of failed posts is cleared.', 'blog-to-audio-podcast' ),
			'refreshed' => __( 'Voices, models and credits were reloaded from ElevenLabs.', 'blog-to-audio-podcast' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	/**
	 * Notice shown while the queue is paused (also used on the posts list).
	 *
	 * @return void
	 */
	public static function paused_notice() {
		$paused = Blog_To_Audio_Podcast_Queue::paused();

		if ( ! $paused || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$counts = Blog_To_Audio_Podcast_Queue::counts();

		if ( ! $counts['queued'] && ! $counts['processing'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Audio creation is paused.', 'blog-to-audio-podcast' ),
			esc_html( $paused['message'] ),
			esc_url( self::url( 'overview' ) ),
			esc_html__( 'Review and resume', 'blog-to-audio-podcast' )
		);
	}

	// Settings ------------------------------------------------------------------

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Blog_To_Audio_Podcast_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Blog_To_Audio_Podcast_Plugin::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Clean submitted settings. Each tab sends only its own fields, so the
	 * rest are kept as they are.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$current = $this->plugin->get_settings();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$tabs  = array( 'voice', 'content', 'player', 'podcast' );
		$clean = $current;

		if ( isset( $input['_tab'] ) && in_array( $input['_tab'], $tabs, true ) ) {
			$tabs = array( $input['_tab'] );
		} else {
			// Saved by code, or a form without a tab: check every field, with
			// the current values standing in for any that weren't sent.
			$input = wp_parse_args( $input, $current );
		}

		foreach ( $tabs as $tab ) {
			$method = 'sanitize_' . $tab;
			$clean  = $this->$method( $input, $clean );
		}

		Blog_To_Audio_Podcast_Audio::touch_feed();

		return array_intersect_key( $clean, Blog_To_Audio_Podcast_Plugin::defaults() );
	}

	/**
	 * A number from the form, kept within a range.
	 *
	 * @param array  $input   Form values.
	 * @param string $key     Key.
	 * @param float  $min     Lowest.
	 * @param float  $max     Highest.
	 * @param float  $default If missing.
	 * @return float
	 */
	private static function number( array $input, $key, $min, $max, $default ) {
		$value = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (float) $input[ $key ] : (float) $default;

		return round( max( $min, min( $max, $value ) ), 2 );
	}

	/**
	 * ElevenLabs tab: key, model, voice and voice settings.
	 *
	 * @param array $input Form values.
	 * @param array $clean Settings so far.
	 * @return array
	 */
	private function sanitize_voice( array $input, array $clean ) {
		$key_changed = false;

		if ( ! empty( $input['remove_key'] ) && ! Blog_To_Audio_Podcast_Plugin::key_in_config() ) {
			Blog_To_Audio_Podcast_Plugin::save_api_key( '' );
			$key_changed = true;
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) && ! Blog_To_Audio_Podcast_Plugin::key_in_config() ) {
			$key = trim( (string) $input['api_key'] );

			if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,200}$/', $key ) ) {
				add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_key_format', __( 'That doesn\'t look like an ElevenLabs API key. Copy it again from elevenlabs.io > Developers > API Keys.', 'blog-to-audio-podcast' ) );
			} else {
				$check = ( new Blog_To_Audio_Podcast_API( $key ) )->models();

				if ( is_wp_error( $check ) && 'pause' === Blog_To_Audio_Podcast_API::kind( $check ) && in_array( (int) $check->get_error_data()['status'], array( 401 ), true ) && 'missing_permissions' !== $check->get_error_code() ) {
					/* translators: %s: error from ElevenLabs. */
					add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_key_invalid', sprintf( __( 'ElevenLabs didn\'t accept that key: %s', 'blog-to-audio-podcast' ), $check->get_error_message() ) );
				} else {
					Blog_To_Audio_Podcast_Plugin::save_api_key( $key );
					$key_changed = true;

					if ( is_wp_error( $check ) && 'retry' === Blog_To_Audio_Podcast_API::kind( $check ) ) {
						/* translators: %s: error message. */
						add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_key_unchecked', sprintf( __( 'API key saved, but ElevenLabs couldn\'t be reached to check it: %s', 'blog-to-audio-podcast' ), $check->get_error_message() ), 'warning' );
					} else {
						add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_key_saved', __( 'API key saved and connected to ElevenLabs.', 'blog-to-audio-podcast' ), 'success' );
					}
				}
			}
		}

		if ( $key_changed ) {
			delete_transient( 'blog_to_audio_podcast_voices' );
			delete_transient( 'blog_to_audio_podcast_models' );
			delete_transient( 'blog_to_audio_podcast_account' );

			// A new key fixes key and credit problems: let the queue go again.
			$paused = Blog_To_Audio_Podcast_Queue::paused();
			if ( $paused && 'manual' !== $paused['code'] ) {
				delete_option( Blog_To_Audio_Podcast_Queue::PAUSED );
			}
		}

		$models = Blog_To_Audio_Podcast_Plugin::models();
		$model  = isset( $input['model_id'] ) ? sanitize_key( $input['model_id'] ) : '';

		if ( isset( $models[ $model ] ) ) {
			$clean['model_id'] = $model;
		}

		$custom = isset( $input['voice_custom'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['voice_custom'] ) : '';
		$voice  = '' !== $custom ? $custom : ( isset( $input['voice_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['voice_id'] ) : '' );

		if ( '' !== $voice && strlen( $voice ) <= 64 ) {
			$list = Blog_To_Audio_Podcast_Plugin::voices();

			if ( isset( $list['voices'][ $voice ] ) ) {
				$clean['voice_id']   = $voice;
				$clean['voice_name'] = $list['voices'][ $voice ]['name'];
			} elseif ( '' !== Blog_To_Audio_Podcast_Plugin::api_key() ) {
				$found = Blog_To_Audio_Podcast_Plugin::api()->voice( $voice );

				if ( is_wp_error( $found ) ) {
					/* translators: %s: error from ElevenLabs. */
					add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_voice', sprintf( __( 'That voice couldn\'t be used, so the previous one is kept: %s', 'blog-to-audio-podcast' ), $found->get_error_message() ) );
				} else {
					$clean['voice_id']   = $voice;
					$clean['voice_name'] = $found['name'];
				}
			}
		}

		$clean['stability']     = self::number( $input, 'stability', 0, 1, 0.5 );
		$clean['similarity']    = self::number( $input, 'similarity', 0, 1, 0.75 );
		$clean['style']         = self::number( $input, 'style', 0, 1, 0 );
		$clean['speed']         = self::number( $input, 'speed', 0.7, 1.2, 1 );
		$clean['speaker_boost'] = empty( $input['speaker_boost'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Content tab: post types, auto-create, intro, outro, pronunciations.
	 *
	 * @param array $input Form values.
	 * @param array $clean Settings so far.
	 * @return array
	 */
	private function sanitize_content( array $input, array $clean ) {
		$types = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();

		$clean['post_types']     = array_values( array_intersect( array_keys( self::readable_post_types() ), $types ) );
		$clean['auto_create']    = empty( $input['auto_create'] ) ? 0 : 1;
		$clean['intro']          = isset( $input['intro'] ) ? mb_substr( sanitize_textarea_field( $input['intro'] ), 0, 600 ) : '';
		$clean['outro']          = isset( $input['outro'] ) ? mb_substr( sanitize_textarea_field( $input['outro'] ), 0, 600 ) : '';
		$clean['pronunciations'] = isset( $input['pronunciations'] ) ? mb_substr( sanitize_textarea_field( $input['pronunciations'] ), 0, 5000 ) : '';

		if ( ! $clean['post_types'] ) {
			add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_types', __( 'No content types are chosen, so no audio will be made.', 'blog-to-audio-podcast' ), 'warning' );
		}

		return $clean;
	}

	/**
	 * Player tab.
	 *
	 * @param array $input Form values.
	 * @param array $clean Settings so far.
	 * @return array
	 */
	private function sanitize_player( array $input, array $clean ) {
		$where       = isset( $input['player'] ) ? sanitize_key( $input['player'] ) : '';
		$attribution = isset( $input['attribution'] ) ? sanitize_key( $input['attribution'] ) : '';

		$clean['player']            = in_array( $where, array( 'before', 'after', 'manual' ), true ) ? $where : 'before';
		$clean['player_label']      = isset( $input['player_label'] ) ? mb_substr( sanitize_text_field( $input['player_label'] ), 0, 120 ) : '';
		$clean['player_duration']   = empty( $input['player_duration'] ) ? 0 : 1;
		$clean['player_disclosure'] = empty( $input['player_disclosure'] ) ? 0 : 1;
		$clean['player_download']   = empty( $input['player_download'] ) ? 0 : 1;
		$clean['attribution']       = in_array( $attribution, array( 'auto', 'on', 'off' ), true ) ? $attribution : 'auto';
		$clean['schema']            = empty( $input['schema'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Podcast tab.
	 *
	 * @param array $input Form values.
	 * @param array $clean Settings so far.
	 * @return array
	 */
	private function sanitize_podcast( array $input, array $clean ) {
		$text = function ( $key, $max ) use ( $input ) {
			return isset( $input[ $key ] ) ? mb_substr( sanitize_text_field( $input[ $key ] ), 0, $max ) : '';
		};

		$slug  = isset( $input['feed_slug'] ) ? sanitize_title( $input['feed_slug'] ) : '';
		$email = isset( $input['podcast_owner_email'] ) ? sanitize_email( $input['podcast_owner_email'] ) : '';
		$image = isset( $input['podcast_image'] ) ? absint( $input['podcast_image'] ) : 0;
		$lang  = isset( $input['podcast_language'] ) ? sanitize_text_field( $input['podcast_language'] ) : '';
		$cat   = isset( $input['podcast_category'] ) ? sanitize_text_field( $input['podcast_category'] ) : '';

		if ( in_array( $slug, array( '', 'feed', 'rss', 'rss2', 'atom', 'rdf' ), true ) ) {
			$slug = 'podcast';
		}

		if ( isset( $input['podcast_owner_email'] ) && '' !== trim( (string) $input['podcast_owner_email'] ) && '' === $email ) {
			add_settings_error( Blog_To_Audio_Podcast_Plugin::OPTION, 'btap_email', __( 'The owner email isn\'t a valid email address, so it wasn\'t saved.', 'blog-to-audio-podcast' ) );
			$email = $clean['podcast_owner_email'];
		}

		$clean['feed']                = empty( $input['feed'] ) ? 0 : 1;
		$clean['feed_slug']           = $slug;
		$clean['podcast_title']       = $text( 'podcast_title', 255 );
		$clean['podcast_description'] = isset( $input['podcast_description'] ) ? mb_substr( sanitize_textarea_field( $input['podcast_description'] ), 0, 3900 ) : '';
		$clean['podcast_author']      = $text( 'podcast_author', 255 );
		$clean['podcast_owner_name']  = $text( 'podcast_owner_name', 255 );
		$clean['podcast_owner_email'] = $email;
		$clean['podcast_image']       = $image && wp_attachment_is_image( $image ) ? $image : 0;
		$clean['podcast_category']    = Blog_To_Audio_Podcast_Feed::parse_category( $cat ) ? $cat : '';
		$clean['podcast_explicit']    = empty( $input['podcast_explicit'] ) ? 0 : 1;
		$clean['podcast_language']    = preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang ) ? $lang : '';
		$clean['podcast_copyright']   = $text( 'podcast_copyright', 255 );
		$clean['podcast_limit']       = (int) self::number( $input, 'podcast_limit', 1, 1000, 100 );
		$clean['podcast_disclosure']  = empty( $input['podcast_disclosure'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Public content types with an editor.
	 *
	 * @return array<string,string> name => label.
	 */
	private static function readable_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && post_type_supports( $type->name, 'editor' ) ) {
				$types[ $type->name ] = $type->labels->name;
			}
		}

		return $types;
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function field( $key ) {
		return Blog_To_Audio_Podcast_Plugin::OPTION . '[' . $key . ']';
	}

	// Account ---------------------------------------------------------------------

	/**
	 * The ElevenLabs account's plan and credits, cached for ten minutes.
	 *
	 * @return array|WP_Error|null Null without a key.
	 */
	public static function account() {
		if ( '' === Blog_To_Audio_Podcast_Plugin::api_key() ) {
			return null;
		}

		$account = get_transient( 'blog_to_audio_podcast_account' );

		if ( is_array( $account ) ) {
			return $account;
		}

		$account = Blog_To_Audio_Podcast_Plugin::api()->subscription();

		if ( is_wp_error( $account ) ) {
			return $account;
		}

		set_transient( 'blog_to_audio_podcast_account', $account, 10 * MINUTE_IN_SECONDS );

		// Remembered for the free plan's attribution rule (autoloaded: the
		// player checks it on every post).
		update_option( 'blog_to_audio_podcast_tier', $account['tier'], true );

		return $account;
	}

	// AJAX --------------------------------------------------------------------------

	/**
	 * The page's AJAX: estimate, start, run, status, test, preview.
	 *
	 * @return void
	 */
	public function ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'blog-to-audio-podcast' ) ), 403 );
		}

		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		switch ( $action ) {
			case 'estimate':
				$this->ajax_estimate();
				break;
			case 'start':
				$this->ajax_start();
				break;
			case 'run':
				$result = Blog_To_Audio_Podcast_Generator::run( 20 );
				Blog_To_Audio_Podcast_Queue::kick( 'busy' === $result['result'] ? 60 : 0 );
				wp_send_json_success( $this->progress_state( 'busy' === $result['result'] ) );
				break;
			case 'status':
				wp_send_json_success( $this->progress_state( false ) );
				break;
			case 'test':
				$this->ajax_test();
				break;
			case 'preview':
				$this->ajax_preview();
				break;
		}

		wp_send_json_error( array( 'message' => __( 'Unknown request.', 'blog-to-audio-podcast' ) ), 400 );
	}

	/**
	 * Count the characters bulk creation would use, 25 posts per request.
	 *
	 * @return void
	 */
	private function ajax_estimate() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in ajax().
		$stale  = ! empty( $_POST['stale'] );
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
		// phpcs:enable

		$settings = $this->plugin->get_settings();
		$ids      = Blog_To_Audio_Podcast_Posts::outstanding( $stale );
		$total    = count( $ids );
		$batch    = array_slice( $ids, $offset, 25 );

		if ( $batch ) {
			_prime_post_caches( $batch, false, true );

			foreach ( $batch as $post_id ) {
				$post = get_post( $post_id );

				if ( $post ) {
					Blog_To_Audio_Podcast_Posts::chars( $post, $settings );
				}
			}
		}

		$done = min( $total, $offset + count( $batch ) );

		if ( $done < $total ) {
			wp_send_json_success(
				array(
					'finished' => false,
					'done'     => $done,
					'total'    => $total,
				)
			);
		}

		$chosen = $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
		$chars  = 0;

		update_meta_cache( 'post', $chosen );

		foreach ( $chosen as $post_id ) {
			$cached = get_post_meta( $post_id, Blog_To_Audio_Podcast_Posts::META_CHARS, true );
			$chars += is_array( $cached ) && isset( $cached['chars'] ) ? (int) $cached['chars'] : 0;
		}

		wp_send_json_success(
			array(
				'finished' => true,
				'done'     => $total,
				'total'    => $total,
				'posts'    => count( $chosen ),
				'html'     => $this->estimate_html( count( $chosen ), $chars ),
			)
		);
	}

	/**
	 * The cost estimate: credits per model, against what's left this month.
	 *
	 * @param int $posts Posts.
	 * @param int $chars Characters.
	 * @return string HTML.
	 */
	private function estimate_html( $posts, $chars ) {
		$settings = $this->plugin->get_settings();
		$selected = (string) $settings['model_id'];
		$models   = Blog_To_Audio_Podcast_Plugin::models();
		$account  = self::account();
		$left     = is_array( $account ) ? max( 0, $account['limit'] - $account['used'] ) : null;
		$rate     = Blog_To_Audio_Podcast_Plugin::credit_rate( $selected );
		$credits  = (int) ceil( $chars * $rate['rate'] );

		if ( ! $posts ) {
			return '<p class="btap-estimate-empty">' . Blog_To_Audio_Podcast_Icons::get( 'circle-check', 18 ) . '<span>' . esc_html__( 'Every post already has audio. Nothing to do.', 'blog-to-audio-podcast' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		}

		ob_start();
		?>
		<div class="btap-estimate-summary">
			<div>
				<strong><?php echo esc_html( number_format_i18n( $posts ) ); ?></strong>
				<span><?php echo esc_html( _n( 'post', 'posts', $posts, 'blog-to-audio-podcast' ) ); ?></span>
			</div>
			<div>
				<strong><?php echo esc_html( number_format_i18n( $chars ) ); ?></strong>
				<span><?php esc_html_e( 'characters', 'blog-to-audio-podcast' ); ?></span>
			</div>
			<div>
				<strong><?php echo esc_html( Blog_To_Audio_Podcast_Audio::human_duration( $chars / 15 ) ); ?></strong>
				<span><?php esc_html_e( 'of audio, roughly', 'blog-to-audio-podcast' ); ?></span>
			</div>
			<div class="btap-estimate-credits<?php echo null !== $left && $credits > $left ? ' is-over' : ''; ?>">
				<strong><?php echo esc_html( number_format_i18n( $credits ) ); ?></strong>
				<span>
					<?php
					/* translators: %s: model name. */
					echo esc_html( sprintf( __( 'credits with %s', 'blog-to-audio-podcast' ), isset( $models[ $selected ] ) ? $models[ $selected ]['name'] : $selected ) );
					?>
				</span>
			</div>
		</div>

		<?php if ( null !== $left ) : ?>
			<p class="btap-estimate-left<?php echo $credits > $left ? ' is-over' : ''; ?>">
				<?php echo Blog_To_Audio_Podcast_Icons::get( $credits > $left ? 'triangle-alert' : 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span>
					<?php
					if ( $credits > $left ) {
						echo esc_html(
							sprintf(
								/* translators: 1: credits left, 2: estimated credits. */
								__( 'Your account has %1$s credits left this month, fewer than the %2$s this needs. Audio will be made until the credits run out, then the queue pauses. Choose fewer posts or a cheaper model, or add credits.', 'blog-to-audio-podcast' ),
								number_format_i18n( $left ),
								number_format_i18n( $credits )
							)
						);
					} else {
						echo esc_html(
							sprintf(
								/* translators: 1: credits left, 2: credits left afterwards. */
								__( 'Your account has %1$s credits left this month, so about %2$s would remain.', 'blog-to-audio-podcast' ),
								number_format_i18n( $left ),
								number_format_i18n( $left - $credits )
							)
						);
					}
					?>
				</span>
			</p>
		<?php endif; ?>

		<details class="btap-estimate-models">
			<summary><?php esc_html_e( 'Compare models', 'blog-to-audio-podcast' ); ?></summary>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Model', 'blog-to-audio-podcast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Credits per character', 'blog-to-audio-podcast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Estimated credits', 'blog-to-audio-podcast' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $models as $id => $model ) : ?>
						<?php $model_rate = Blog_To_Audio_Podcast_Plugin::credit_rate( $id ); ?>
						<tr<?php echo $id === $selected ? ' class="is-selected"' : ''; ?>>
							<td><?php echo esc_html( $model['name'] ); ?><?php echo $id === $selected ? ' <span class="btap-pill">' . esc_html__( 'Selected', 'blog-to-audio-podcast' ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( number_format_i18n( $model_rate['rate'], 2 ) ); ?><?php echo $model_rate['measured'] ? '' : '*'; ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ceil( $chars * $model_rate['rate'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Rates come from what your recent audio really cost. * List price, until audio has been made with that model: your plan may charge less.', 'blog-to-audio-podcast' ); ?></p>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Queue the outstanding posts.
	 *
	 * @return void
	 */
	private function ajax_start() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in ajax().
		$stale = ! empty( $_POST['stale'] );
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
		// phpcs:enable

		if ( '' === Blog_To_Audio_Podcast_Plugin::api_key() ) {
			wp_send_json_error( array( 'message' => __( 'Add your ElevenLabs API key first.', 'blog-to-audio-podcast' ) ), 400 );
		}

		$ids = Blog_To_Audio_Podcast_Posts::outstanding( $stale, $limit );

		// Out-of-date posts already have audio: drop their stale flag only
		// once new audio replaces it, so nothing else needs doing here.
		$added = Blog_To_Audio_Podcast_Queue::add_many( $ids );

		self::grow_batch( $added );
		Blog_To_Audio_Podcast_Queue::kick();

		wp_send_json_success( $this->progress_state( false ) );
	}

	/**
	 * Count posts added to the current bulk run.
	 *
	 * @param int $added Posts added.
	 * @return void
	 */
	private static function grow_batch( $added ) {
		if ( $added < 1 ) {
			return;
		}

		$batch = get_option( self::BATCH_OPTION, array() );
		$batch = is_array( $batch ) && isset( $batch['total'] ) ? $batch : array(
			'total' => 0,
			'start' => time(),
		);

		$batch['total'] += $added;

		update_option( self::BATCH_OPTION, $batch, false );
	}

	/**
	 * Generate a short sample with the settings on the form (saved or not).
	 *
	 * @return void
	 */
	private function ajax_test() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in ajax().
		$text  = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$model = isset( $_POST['model_id'] ) ? sanitize_key( $_POST['model_id'] ) : '';
		$voice = isset( $_POST['voice_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['voice_id'] ) ) : '';
		$input = array(
			'stability'     => isset( $_POST['stability'] ) ? wp_unslash( $_POST['stability'] ) : 0.5,
			'similarity'    => isset( $_POST['similarity'] ) ? wp_unslash( $_POST['similarity'] ) : 0.75,
			'style'         => isset( $_POST['style'] ) ? wp_unslash( $_POST['style'] ) : 0,
			'speed'         => isset( $_POST['speed'] ) ? wp_unslash( $_POST['speed'] ) : 1,
			'speaker_boost' => ! empty( $_POST['speaker_boost'] ),
		);
		// phpcs:enable

		$text = mb_substr( trim( $text ), 0, 300 );

		if ( '' === $text || '' === $voice ) {
			wp_send_json_error( array( 'message' => __( 'Type a sentence and choose a voice first.', 'blog-to-audio-podcast' ) ), 400 );
		}

		if ( ! isset( Blog_To_Audio_Podcast_Plugin::models()[ $model ] ) ) {
			$model = (string) $this->plugin->get_setting( 'model_id' );
		}

		$settings = array(
			'stability'     => self::number( $input, 'stability', 0, 1, 0.5 ),
			'similarity'    => self::number( $input, 'similarity', 0, 1, 0.75 ),
			'style'         => self::number( $input, 'style', 0, 1, 0 ),
			'speed'         => self::number( $input, 'speed', 0.7, 1.2, 1 ),
			'speaker_boost' => $input['speaker_boost'],
		);

		$result = Blog_To_Audio_Podcast_Plugin::api()->tts( $text, $voice, $model, Blog_To_Audio_Podcast_Plugin::voice_settings( $settings, $model ), array(), 60 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$audio = Blog_To_Audio_Podcast_MP3::clean( $result['audio'] );

		if ( is_wp_error( $audio ) ) {
			wp_send_json_error( array( 'message' => $audio->get_error_message() ), 400 );
		}

		if ( $result['cost'] > 0 ) {
			Blog_To_Audio_Podcast_Plugin::record_rate( $model, Blog_To_Audio_Podcast_Text::length( $text ), $result['cost'] );
		}

		delete_transient( 'blog_to_audio_podcast_account' );

		wp_send_json_success(
			array(
				'audio'   => 'data:audio/mpeg;base64,' . base64_encode( $audio['audio'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Audio data URI.
				/* translators: %s: credits used. */
				'message' => sprintf( _n( 'Sample made. It used %s credit.', 'Sample made. It used %s credits.', $result['cost'], 'blog-to-audio-podcast' ), number_format_i18n( $result['cost'] ) ),
			)
		);
	}

	/**
	 * Show the text a post's audio would read.
	 *
	 * @return void
	 */
	private function ajax_preview() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in ajax().
		$post    = get_post( $post_id );

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a post.', 'blog-to-audio-podcast' ) ), 400 );
		}

		$settings = $this->plugin->get_settings();
		$text     = Blog_To_Audio_Podcast_Text::for_post( $post, $settings );
		$chunks   = Blog_To_Audio_Podcast_Text::chunks( $text['text'], Blog_To_Audio_Podcast_Plugin::chunk_size( (string) $settings['model_id'] ) );
		$rate     = Blog_To_Audio_Podcast_Plugin::credit_rate( (string) $settings['model_id'] );

		wp_send_json_success(
			array(
				'text'    => $text['text'],
				'summary' => sprintf(
					/* translators: 1: characters, 2: requests, 3: estimated credits, 4: estimated length. */
					_n( '%1$s characters in %2$s request: about %3$s credits and %4$s of audio.', '%1$s characters in %2$s requests: about %3$s credits and %4$s of audio.', count( $chunks ), 'blog-to-audio-podcast' ),
					number_format_i18n( $text['chars'] ),
					number_format_i18n( count( $chunks ) ),
					number_format_i18n( (int) ceil( $text['chars'] * $rate['rate'] ) ),
					Blog_To_Audio_Podcast_Audio::human_duration( $text['chars'] / 15 )
				),
			)
		);
	}

	/**
	 * Progress of the queue, for the Overview's progress box.
	 *
	 * @param bool $busy Whether another run held the lock just now.
	 * @return array
	 */
	private function progress_state( $busy ) {
		$counts    = Blog_To_Audio_Podcast_Queue::counts();
		$paused    = Blog_To_Audio_Podcast_Queue::paused();
		$remaining = $counts['queued'] + $counts['processing'];
		$batch     = get_option( self::BATCH_OPTION, array() );

		if ( ! $remaining ) {
			delete_option( self::BATCH_OPTION );
			$batch = array();
		} elseif ( ! is_array( $batch ) || empty( $batch['total'] ) || $batch['total'] < $remaining ) {
			$batch = array(
				'total' => $remaining,
				'start' => time(),
			);
			update_option( self::BATCH_OPTION, $batch, false );
		}

		return array(
			'working'   => $remaining > 0 && ! $paused,
			'paused'    => (bool) $paused,
			'busy'      => $busy,
			'remaining' => $remaining,
			'errors'    => $counts['error'],
			'html'      => $this->progress_html( $counts, $paused, $batch ),
		);
	}

	// Assets ---------------------------------------------------------------------

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="blog-to-audio-podcast-menu-icon-style">'
			. '#adminmenu a:has(>.blog-to-audio-podcast-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .blog-to-audio-podcast-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .blog-to-audio-podcast-menu-icon,#adminmenu a:hover .blog-to-audio-podcast-menu-icon,#adminmenu a:focus .blog-to-audio-podcast-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		if ( 'podcast' === $this->current_tab() ) {
			wp_enqueue_media();
		}

		wp_enqueue_style( 'blog-to-audio-podcast-admin', BLOG_TO_AUDIO_PODCAST_URL . 'assets/admin.css', array(), BLOG_TO_AUDIO_PODCAST_VERSION );
		wp_enqueue_script( 'blog-to-audio-podcast-admin', BLOG_TO_AUDIO_PODCAST_URL . 'assets/admin.js', array(), BLOG_TO_AUDIO_PODCAST_VERSION, true );

		wp_localize_script(
			'blog-to-audio-podcast-admin',
			'blog_to_audio_podcast_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'models'  => Blog_To_Audio_Podcast_Plugin::models(),
				'i18n'    => array(
					/* translators: 1: posts counted, 2: posts in total. */
					'counting'   => __( 'Counting characters: %1$s of %2$s posts', 'blog-to-audio-podcast' ),
					/* translators: %s: number of posts. */
					'startPosts' => __( 'Create audio for %s posts', 'blog-to-audio-podcast' ),
					/* translators: %s: credits per character. */
					'listPrice'  => __( 'List price: %s credits per character.', 'blog-to-audio-podcast' ),
					'error'      => __( 'Something went wrong. Reload the page to see the latest status.', 'blog-to-audio-podcast' ),
					'stopped'    => __( 'Lost contact with the server. The queue carries on in the background; reload the page to check on it.', 'blog-to-audio-podcast' ),
					'generating' => __( 'Making the sample…', 'blog-to-audio-podcast' ),
					'copied'     => __( 'Copied', 'blog-to-audio-podcast' ),
					'chooseArt'  => __( 'Choose cover art', 'blog-to-audio-podcast' ),
					'useArt'     => __( 'Use this image', 'blog-to-audio-podcast' ),
					'loading'    => __( 'Loading…', 'blog-to-audio-podcast' ),
					'artGood'    => __( 'Looks good: square, the right size and format. Save to use it.', 'blog-to-audio-podcast' ),
					'artMissing' => __( 'Add cover art: a square JPG or PNG, 1400 to 3000 pixels wide.', 'blog-to-audio-podcast' ),
					'artType'    => __( 'Cover art has to be a JPG or PNG.', 'blog-to-audio-podcast' ),
					/* translators: 1: width, 2: height. */
					'artSquare'  => __( 'Cover art has to be square. This image is %1$s × %2$s pixels.', 'blog-to-audio-podcast' ),
					/* translators: %s: width in pixels. */
					'artSize'    => __( 'Cover art has to be between 1400 and 3000 pixels wide. This image is %s pixels.', 'blog-to-audio-podcast' ),
				),
			)
		);
	}

	// Page ---------------------------------------------------------------------------

	/**
	 * Render the page: a header with the tabs, then WordPress's two-column
	 * box layout.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = $this->current_tab();
		?>
		<div class="btap-header">
			<div class="btap-header-title">
				<span class="btap-header-icon"><?php echo Blog_To_Audio_Podcast_Icons::get( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="btap-version"><?php echo esc_html( 'v' . BLOG_TO_AUDIO_PODCAST_VERSION ); ?></span>
			</div>
			<nav class="btap-tabs" aria-label="<?php esc_attr_e( 'Blog Post to Audio Podcast sections', 'blog-to-audio-podcast' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="btap-tab<?php echo $tab === $id ? ' is-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>>
						<?php echo Blog_To_Audio_Podcast_Icons::get( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap btap-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors( Blog_To_Audio_Podcast_Plugin::OPTION );
			settings_errors( 'general' );
			$this->render_notice();

			if ( Blog_To_Audio_Podcast_Plugin::key_unreadable() ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The saved ElevenLabs API key can\'t be read any more, probably because the security keys in wp-config.php changed. Enter it again on the ElevenLabs tab.', 'blog-to-audio-podcast' ) . '</p></div>';
			}
			?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						switch ( $tab ) {
							case 'voice':
								$this->render_voice_tab();
								break;
							case 'content':
								$this->render_content_tab();
								break;
							case 'player':
								$this->render_player_tab();
								break;
							case 'podcast':
								$this->render_podcast_tab();
								break;
							default:
								$this->render_overview_tab();
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="btap-sidebar">
							<?php $this->render_sidebar( $tab ); ?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>

			<p class="btap-disclaimer"><?php esc_html_e( 'Blog Post to Audio Podcast is made by Content Powered. It isn\'t affiliated with or endorsed by ElevenLabs. ElevenLabs is a trademark of its owner.', 'blog-to-audio-podcast' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Open a box.
	 *
	 * @param string $title Title.
	 * @param string $icon  Lucide icon.
	 * @param string $class Extra classes.
	 * @param string $attrs Extra attributes (already escaped).
	 * @return void
	 */
	private function box_start( $title, $icon, $class = '', $attrs = '' ) {
		?>
		<div class="postbox btap-box <?php echo esc_attr( $class ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by callers. ?>>
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo Blog_To_Audio_Podcast_Icons::get( $icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $title ); ?>
				</h2>
			</div>
			<div class="inside">
		<?php
	}

	/**
	 * Close a box.
	 *
	 * @return void
	 */
	private function box_end() {
		echo '</div></div>';
	}

	/**
	 * Open a settings form for a tab.
	 *
	 * @param string $tab Tab ID.
	 * @return void
	 */
	private function form_start( $tab ) {
		echo '<form action="options.php" method="post" class="btap-settings-form">';
		settings_fields( self::PAGE );
		echo '<input type="hidden" name="' . esc_attr( self::field( '_tab' ) ) . '" value="' . esc_attr( $tab ) . '">';
	}

	/**
	 * Close a settings form.
	 *
	 * @return void
	 */
	private function form_end() {
		echo '<div class="btap-submit">';
		submit_button( __( 'Save Changes', 'blog-to-audio-podcast' ), 'primary', 'submit', false );
		echo '</div></form>';
	}

	/**
	 * A checkbox row's content.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function checkbox( $key, $label, $description = '' ) {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>%4$s',
			esc_attr( self::field( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label ),
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * A text input.
	 *
	 * @param string $key         Setting key.
	 * @param string $id          Element ID.
	 * @param string $placeholder Placeholder.
	 * @param string $description Help text.
	 * @param string $type        Input type.
	 * @return void
	 */
	private function text_input( $key, $id, $placeholder = '', $description = '', $type = 'text' ) {
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" placeholder="%5$s">%6$s',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( self::field( $key ) ),
			esc_attr( (string) $this->plugin->get_setting( $key ) ),
			esc_attr( $placeholder ),
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	// Overview -----------------------------------------------------------------------

	/**
	 * Overview tab.
	 *
	 * @return void
	 */
	private function render_overview_tab() {
		$stats = Blog_To_Audio_Podcast_Posts::stats();
		$tiles = array(
			array( 'headphones', number_format_i18n( $stats['with_audio'] ), __( 'Posts with audio', 'blog-to-audio-podcast' ), 'good' ),
			array( 'hourglass', number_format_i18n( $stats['waiting'] ), __( 'Waiting for audio', 'blog-to-audio-podcast' ), '' ),
			array( 'clock', Blog_To_Audio_Podcast_Audio::human_duration( $stats['seconds'] ), __( 'Listening time', 'blog-to-audio-podcast' ), '' ),
			array( 'coins', number_format_i18n( $stats['credits'] ), __( 'Credits spent on audio', 'blog-to-audio-podcast' ), '' ),
		);
		?>
		<ul class="btap-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<li class="btap-stat<?php echo $tile[3] ? ' btap-stat-' . esc_attr( $tile[3] ) : ''; ?>">
					<span class="btap-stat-icon"><?php echo Blog_To_Audio_Podcast_Icons::get( $tile[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="btap-stat-text">
						<strong><?php echo esc_html( $tile[1] ); ?></strong>
						<span><?php echo esc_html( $tile[2] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php

		$this->render_bulk_box( $stats );
		$this->render_failed_box();
		$this->render_recent_box();
	}

	/**
	 * Overview: the bulk creation box.
	 *
	 * @param array $stats Stats.
	 * @return void
	 */
	private function render_bulk_box( array $stats ) {
		$state   = $this->progress_state( false );
		$has_key = '' !== Blog_To_Audio_Podcast_Plugin::api_key();

		$this->box_start( __( 'Create audio for your posts', 'blog-to-audio-podcast' ), 'audio-lines', 'btap-bulk', ' data-btap-bulk data-working="' . ( $state['working'] ? '1' : '0' ) . '"' );

		if ( ! $has_key ) {
			echo '<p class="btap-intro">' . esc_html__( 'Connect your ElevenLabs account to start creating audio.', 'blog-to-audio-podcast' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'voice' ) ) . '">' . esc_html__( 'Add your API key', 'blog-to-audio-podcast' ) . '</a></p>';
			$this->box_end();
			return;
		}
		?>
		<div class="btap-progress-area" data-btap-progress aria-live="polite"><?php echo $state['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts. ?></div>

		<div class="btap-bulk-form" data-btap-bulk-form<?php echo $state['remaining'] ? ' hidden' : ''; ?>>
			<p class="btap-intro"><?php esc_html_e( 'Make audio for every published post that doesn\'t have it yet. First count the cost: nothing is sent to ElevenLabs until you start.', 'blog-to-audio-podcast' ); ?></p>

			<fieldset class="btap-checks">
				<legend class="screen-reader-text"><?php esc_html_e( 'Which posts', 'blog-to-audio-podcast' ); ?></legend>
				<label>
					<input type="radio" name="btap_scope" value="missing" checked>
					<?php
					/* translators: %s: number of posts. */
					echo esc_html( sprintf( __( 'Posts without audio (%s)', 'blog-to-audio-podcast' ), number_format_i18n( $stats['outstanding'] ) ) );
					?>
				</label>
				<label>
					<input type="radio" name="btap_scope" value="stale"<?php disabled( ! $stats['stale'] ); ?>>
					<?php
					/* translators: %s: number of posts. */
					echo esc_html( sprintf( __( 'Posts without audio, plus out-of-date audio (%s more)', 'blog-to-audio-podcast' ), number_format_i18n( $stats['stale'] ) ) );
					?>
				</label>
			</fieldset>

			<p class="btap-limit">
				<label for="btap-limit"><?php esc_html_e( 'Newest first, up to', 'blog-to-audio-podcast' ); ?></label>
				<input type="number" id="btap-limit" min="1" step="1" class="small-text" placeholder="<?php esc_attr_e( 'All', 'blog-to-audio-podcast' ); ?>" data-btap-limit>
				<?php esc_html_e( 'posts', 'blog-to-audio-podcast' ); ?>
			</p>

			<p class="btap-buttons">
				<button type="button" class="button button-large" data-btap-estimate<?php disabled( ! $stats['outstanding'] && ! $stats['stale'] ); ?>>
					<?php echo Blog_To_Audio_Podcast_Icons::get( 'calculator', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Count the cost', 'blog-to-audio-podcast' ); ?>
				</button>
			</p>

			<div class="btap-estimate" data-btap-estimate-result hidden></div>

			<p class="btap-buttons" data-btap-start-row hidden>
				<button type="button" class="button button-primary button-large" data-btap-start>
					<?php echo Blog_To_Audio_Podcast_Icons::get( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span data-btap-start-label><?php esc_html_e( 'Start creating audio', 'blog-to-audio-podcast' ); ?></span>
				</button>
			</p>
		</div>
		<?php
		$this->box_end();
	}

	/**
	 * The progress box's contents, redrawn as the queue moves.
	 *
	 * @param array       $counts Queue counts.
	 * @param array|false $paused Why the queue is paused.
	 * @param array       $batch  Current bulk run.
	 * @return string HTML.
	 */
	private function progress_html( array $counts, $paused, $batch ) {
		$remaining = $counts['queued'] + $counts['processing'];

		if ( ! $remaining ) {
			return '';
		}

		$total   = is_array( $batch ) && ! empty( $batch['total'] ) ? (int) $batch['total'] : $remaining;
		$done    = max( 0, $total - $remaining );
		$percent = $total ? (int) floor( 100 * $done / $total ) : 0;
		$current = Blog_To_Audio_Podcast_Queue::ids( 'processing', 1 );
		$line    = '';

		if ( $current ) {
			$status = Blog_To_Audio_Podcast_Queue::status( $current[0] );
			$line   = sprintf(
				/* translators: 1: post title, 2: part, 3: parts. */
				__( 'Now: “%1$s”, part %2$s of %3$s', 'blog-to-audio-podcast' ),
				wp_strip_all_tags( get_the_title( $current[0] ) ),
				number_format_i18n( min( max( 1, $status['chunks'] ), $status['chunk'] + 1 ) ),
				number_format_i18n( max( 1, $status['chunks'] ) )
			);
		}

		ob_start();
		?>
		<div class="btap-run<?php echo $paused ? ' is-paused' : ''; ?>">
			<p class="btap-run-title">
				<?php echo Blog_To_Audio_Podcast_Icons::get( $paused ? 'circle-pause' : 'loader-circle', 18, $paused ? '' : 'btap-spin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<strong>
					<?php
					echo esc_html(
						$paused
							? __( 'Paused', 'blog-to-audio-podcast' )
							/* translators: 1: posts done, 2: posts in this run. */
							: sprintf( __( 'Creating audio: %1$s of %2$s posts done', 'blog-to-audio-podcast' ), number_format_i18n( $done ), number_format_i18n( $total ) )
					);
					?>
				</strong>
			</p>
			<div class="btap-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $percent; ?>" aria-label="<?php esc_attr_e( 'Progress', 'blog-to-audio-podcast' ); ?>"><span style="width: <?php echo (int) $percent; ?>%"></span></div>

			<?php if ( $paused ) : ?>
				<p class="btap-run-paused"><?php echo esc_html( $paused['message'] ); ?></p>
				<?php if ( in_array( $paused['code'], array( 'quota_exceeded', 'insufficient_credits', 'payment_required' ), true ) ) : ?>
					<p class="description"><?php esc_html_e( 'Your ElevenLabs credits ran out. Add credits or wait for them to renew, then resume. Nothing that was already made is lost.', 'blog-to-audio-podcast' ); ?></p>
				<?php elseif ( 'manual' !== $paused['code'] ) : ?>
					<p class="description"><?php esc_html_e( 'Every post would fail the same way, so the queue stopped instead of using up credits. Fix the problem on the ElevenLabs tab, then resume.', 'blog-to-audio-podcast' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( '' !== $line ) : ?>
					<p class="btap-run-now"><?php echo esc_html( $line ); ?></p>
				<?php endif; ?>
				<?php if ( $counts['waiting'] ) : ?>
					<p class="btap-run-now">
						<?php
						/* translators: %s: number of posts. */
						echo esc_html( sprintf( _n( '%s post is waiting to retry after a temporary error from ElevenLabs.', '%s posts are waiting to retry after a temporary error from ElevenLabs.', $counts['waiting'], 'blog-to-audio-podcast' ), number_format_i18n( $counts['waiting'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Keep this page open to go faster. If you leave, the audio keeps being made in the background, about one post a minute.', 'blog-to-audio-podcast' ); ?></p>
			<?php endif; ?>

			<div class="btap-run-buttons">
				<?php
				if ( $paused ) {
					echo $this->action_button( 'resume', __( 'Resume', 'blog-to-audio-podcast' ), 'button button-primary', 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
				} else {
					echo $this->action_button( 'pause', __( 'Pause', 'blog-to-audio-podcast' ), 'button', 'pause' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
				}

				echo $this->action_button( 'cancel', __( 'Cancel the rest', 'blog-to-audio-podcast' ), 'button-link button-link-delete', '', __( 'Take every waiting post out of the queue? Audio that was already made is kept.', 'blog-to-audio-podcast' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Overview: posts whose audio couldn't be made.
	 *
	 * @return void
	 */
	private function render_failed_box() {
		$failed = Blog_To_Audio_Podcast_Queue::ids( 'error', 50 );

		if ( ! $failed ) {
			return;
		}

		$this->box_start( __( 'Couldn\'t create audio', 'blog-to-audio-podcast' ), 'triangle-alert', 'btap-failed' );
		?>
		<ul class="btap-failed-list">
			<?php foreach ( $failed as $post_id ) : ?>
				<li>
					<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $post_id ) ) ); ?></a>
					<span><?php echo esc_html( (string) get_post_meta( $post_id, Blog_To_Audio_Podcast_Queue::META_ERROR, true ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="btap-run-buttons">
			<?php
			echo $this->action_button( 'retry', __( 'Try them again', 'blog-to-audio-podcast' ), 'button', 'rotate-ccw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
			echo $this->action_button( 'clear', __( 'Clear the list', 'blog-to-audio-podcast' ), 'button-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in action_button().
			?>
		</div>
		<?php
		$this->box_end();
	}

	/**
	 * Overview: the latest audio.
	 *
	 * @return void
	 */
	private function render_recent_box() {
		$types = $this->plugin->post_types();
		$posts = $types ? get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'any',
				'posts_per_page' => 8,
				'meta_key'       => Blog_To_Audio_Podcast_Audio::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		) : array();

		$this->box_start( __( 'Recent audio', 'blog-to-audio-podcast' ), 'list-music', 'btap-recent' );

		if ( ! $posts ) {
			echo '<p class="btap-empty">' . Blog_To_Audio_Podcast_Icons::get( 'headphones', 16 ) . '<span>' . esc_html__( 'No audio yet. Create it above, from the Audio box when you edit a post, or with the "Create audio" bulk action on the Posts screen.', 'blog-to-audio-podcast' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			$this->box_end();
			return;
		}

		usort(
			$posts,
			function ( $a, $b ) {
				$a_audio = get_post_meta( $a->ID, Blog_To_Audio_Podcast_Audio::META, true );
				$b_audio = get_post_meta( $b->ID, Blog_To_Audio_Podcast_Audio::META, true );

				return ( isset( $b_audio['created'] ) ? (int) $b_audio['created'] : 0 ) - ( isset( $a_audio['created'] ) ? (int) $a_audio['created'] : 0 );
			}
		);
		?>
		<table class="widefat striped btap-recent-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post', 'blog-to-audio-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Length', 'blog-to-audio-podcast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Listen', 'blog-to-audio-podcast' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $posts as $post ) : ?>
					<?php
					$audio = Blog_To_Audio_Podcast_Audio::get( $post );

					if ( ! $audio ) {
						continue;
					}
					?>
					<tr>
						<td>
							<a class="btap-recent-title" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $post ) ) ); ?></a>
							<span class="btap-recent-meta">
								<?php
								echo esc_html(
									implode(
										' · ',
										array_filter(
											array(
												$audio['voice_name'],
												/* translators: %s: time ago, e.g. "2 hours". */
												sprintf( __( '%s ago', 'blog-to-audio-podcast' ), human_time_diff( (int) $audio['created'] ) ),
												$audio['stale'] ? __( 'Out of date', 'blog-to-audio-podcast' ) : '',
											)
										)
									)
								);
								?>
							</span>
						</td>
						<td><?php echo esc_html( Blog_To_Audio_Podcast_Audio::format_time( $audio['duration'] ) ); ?></td>
						<td><audio controls preload="none" src="<?php echo esc_url( $audio['url'] ); ?>" aria-label="<?php /* translators: %s: post title. */ echo esc_attr( sprintf( __( 'Audio version of %s', 'blog-to-audio-podcast' ), wp_strip_all_tags( get_the_title( $post ) ) ) ); ?>"></audio></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->box_end();
	}

	// ElevenLabs tab -------------------------------------------------------------------

	/**
	 * ElevenLabs tab: key, model, voice and voice settings.
	 *
	 * @return void
	 */
	private function render_voice_tab() {
		$settings = $this->plugin->get_settings();
		$models   = Blog_To_Audio_Podcast_Plugin::models();
		$voices   = Blog_To_Audio_Podcast_Plugin::voices();
		$account  = self::account();
		$has_key  = '' !== Blog_To_Audio_Podcast_Plugin::api_key();
		$model    = isset( $models[ $settings['model_id'] ] ) ? $models[ $settings['model_id'] ] : Blog_To_Audio_Podcast_Plugin::model( (string) $settings['model_id'] );

		$this->form_start( 'voice' );

		$this->box_start( __( 'ElevenLabs account', 'blog-to-audio-podcast' ), 'key-round' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="btap-api-key"><?php esc_html_e( 'API key', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<?php if ( Blog_To_Audio_Podcast_Plugin::key_in_config() ) : ?>
						<p class="btap-key-status is-good">
							<?php echo Blog_To_Audio_Podcast_Icons::get( 'shield-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span>
								<?php
								printf(
									/* translators: %s: constant name. */
									esc_html__( 'Set in wp-config.php (%s).', 'blog-to-audio-podcast' ),
									'<code>' . esc_html( Blog_To_Audio_Podcast_Plugin::KEY_CONSTANT ) . '</code>'
								);
								?>
							</span>
						</p>
					<?php else : ?>
						<input type="password" id="btap-api-key" name="<?php echo esc_attr( self::field( 'api_key' ) ); ?>" value="" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $has_key ? str_repeat( '•', 16 ) . Blog_To_Audio_Podcast_Plugin::key_hint() : 'sk_...' ); ?>">
						<?php if ( $has_key ) : ?>
							<p class="description">
								<?php
								/* translators: %s: last four characters of the key. */
								echo esc_html( sprintf( __( 'A key ending in %s is saved, encrypted. Leave this empty to keep it, or paste a new one to replace it.', 'blog-to-audio-podcast' ), Blog_To_Audio_Podcast_Plugin::key_hint() ) );
								?>
							</p>
							<p><label><input type="checkbox" name="<?php echo esc_attr( self::field( 'remove_key' ) ); ?>" value="1"> <?php esc_html_e( 'Remove the saved key', 'blog-to-audio-podcast' ); ?></label></p>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to ElevenLabs' API keys page. */
									esc_html__( 'Create one at %s. It needs Text to Speech access. Read access to Voices, Models and User lets this page list your voices and show your credits.', 'blog-to-audio-podcast' ),
									'<a href="https://elevenlabs.io/app/developers/api-keys" target="_blank" rel="noopener noreferrer">elevenlabs.io &gt; Developers &gt; API Keys</a>'
								);
								?>
							</p>
						<?php endif; ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: constant name. */
								esc_html__( 'To keep it out of the database entirely, define %s in wp-config.php instead.', 'blog-to-audio-podcast' ),
								'<code>' . esc_html( Blog_To_Audio_Podcast_Plugin::KEY_CONSTANT ) . '</code>'
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $has_key ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'blog-to-audio-podcast' ); ?></th>
					<td>
						<?php if ( is_wp_error( $account ) ) : ?>
							<p class="btap-key-status is-bad"><?php echo Blog_To_Audio_Podcast_Icons::get( 'circle-x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $account->get_error_message() ); ?></span></p>
						<?php elseif ( is_array( $account ) ) : ?>
							<p class="btap-key-status is-good">
								<?php echo Blog_To_Audio_Podcast_Icons::get( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
								<span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: plan name, 2: credits used, 3: credits in the plan. */
											__( 'Connected. %1$s plan: %2$s of %3$s credits used this month.', 'blog-to-audio-podcast' ),
											ucfirst( $account['tier'] ),
											number_format_i18n( $account['used'] ),
											number_format_i18n( $account['limit'] )
										)
									);
									?>
								</span>
							</p>
							<?php if ( 'free' === $account['tier'] ) : ?>
								<p class="description btap-warn-text"><?php esc_html_e( 'ElevenLabs\' free plan doesn\'t allow commercial use and requires crediting ElevenLabs. With the Player tab\'s credit setting on Automatic, "elevenlabs.io" is added to the player and to each podcast episode\'s title. A paid plan removes both requirements.', 'blog-to-audio-podcast' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
		$this->box_end();

		$this->box_start( __( 'Voice', 'blog-to-audio-podcast' ), 'mic-vocal' );

		if ( '' !== $voices['error'] ) {
			/* translators: %s: error from ElevenLabs. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Your voices couldn\'t be loaded, so ElevenLabs\' built-in voices are shown: %s', 'blog-to-audio-podcast' ), $voices['error'] ) ) . '</p></div>';
		}

		$own     = array();
		$premade = array();

		foreach ( $voices['voices'] as $id => $voice ) {
			if ( 'premade' === $voice['category'] ) {
				$premade[ $id ] = $voice;
			} else {
				$own[ $id ] = $voice;
			}
		}

		$current_in_list = isset( $voices['voices'][ $settings['voice_id'] ] );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="btap-model"><?php esc_html_e( 'Model', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<select id="btap-model" name="<?php echo esc_attr( self::field( 'model_id' ) ); ?>" data-btap-model>
						<?php foreach ( $models as $id => $item ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['model_id'], $id ); ?>><?php echo esc_html( $item['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description" data-btap-model-info>
						<?php echo esc_html( $this->model_info( $model ) ); ?>
					</p>
					<p class="description"><?php esc_html_e( 'Eleven v4 and Multilingual v2 sound the most natural for long articles. Flash v2.5 costs half as much and is much faster, with a little less expression.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-voice"><?php esc_html_e( 'Voice', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<div class="btap-voice-row">
						<select id="btap-voice" name="<?php echo esc_attr( self::field( 'voice_id' ) ); ?>" data-btap-voice>
							<?php if ( ! $current_in_list ) : ?>
								<option value="<?php echo esc_attr( $settings['voice_id'] ); ?>" selected><?php echo esc_html( $settings['voice_name'] ? $settings['voice_name'] : $settings['voice_id'] ); ?></option>
							<?php endif; ?>
							<?php if ( $own ) : ?>
								<optgroup label="<?php esc_attr_e( 'Your voices', 'blog-to-audio-podcast' ); ?>">
									<?php $this->voice_options( $own, (string) $settings['voice_id'] ); ?>
								</optgroup>
							<?php endif; ?>
							<optgroup label="<?php esc_attr_e( 'ElevenLabs voices', 'blog-to-audio-podcast' ); ?>">
								<?php $this->voice_options( $premade, (string) $settings['voice_id'] ); ?>
							</optgroup>
						</select>
						<button type="button" class="button" data-btap-preview-voice aria-label="<?php esc_attr_e( 'Play this voice\'s sample', 'blog-to-audio-podcast' ); ?>">
							<?php echo Blog_To_Audio_Podcast_Icons::get( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php esc_html_e( 'Sample', 'blog-to-audio-podcast' ); ?>
						</button>
					</div>
					<p class="description" data-btap-voice-info></p>
					<p class="description">
						<?php
						printf(
							/* translators: 1: link to the Voice Library, 2: refresh link. */
							esc_html__( 'Add voices from the %1$s to your ElevenLabs account and they appear here. %2$s', 'blog-to-audio-podcast' ),
							'<a href="https://elevenlabs.io/app/voice-library" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Voice Library', 'blog-to-audio-podcast' ) . '</a>',
							'<a href="' . esc_url( wp_nonce_url( self::url( 'voice', array( 'btap_do' => 'refresh' ) ), self::NONCE ) ) . '">' . esc_html__( 'Reload the list', 'blog-to-audio-podcast' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-voice-custom"><?php esc_html_e( 'Or a voice ID', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<input type="text" id="btap-voice-custom" name="<?php echo esc_attr( self::field( 'voice_custom' ) ); ?>" value="" class="regular-text code" placeholder="JBFqnCBsd6RMkjVDRZzb" data-btap-voice-custom spellcheck="false">
					<p class="description"><?php esc_html_e( 'Paste a voice ID to use a voice that isn\'t in the list. Leave empty to use the list.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		$this->box_end();

		$this->box_start( __( 'Voice settings', 'blog-to-audio-podcast' ), 'sliders-horizontal' );
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->slider( 'stability', __( 'Stability', 'blog-to-audio-podcast' ), 0, 1, 0.05, __( 'Higher is steadier and more even; lower is more expressive but less predictable. Around 0.5 suits most articles.', 'blog-to-audio-podcast' ) );
			$this->slider( 'similarity', __( 'Similarity', 'blog-to-audio-podcast' ), 0, 1, 0.05, __( 'How closely to stick to the original voice.', 'blog-to-audio-podcast' ) );
			$this->slider( 'style', __( 'Style exaggeration', 'blog-to-audio-podcast' ), 0, 1, 0.05, __( 'Adds emphasis. Keep it at 0 for a calm, natural read.', 'blog-to-audio-podcast' ), empty( $model['style'] ) );
			$this->slider( 'speed', __( 'Speed', 'blog-to-audio-podcast' ), 0.7, 1.2, 0.05, __( '1 is the voice\'s natural pace.', 'blog-to-audio-podcast' ) );
			?>
			<tr data-btap-needs="boost"<?php echo empty( $model['boost'] ) ? ' hidden' : ''; ?>>
				<th scope="row"><?php esc_html_e( 'Speaker boost', 'blog-to-audio-podcast' ); ?></th>
				<td><?php $this->checkbox( 'speaker_boost', __( 'Boost the likeness to the original speaker', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
		</table>
		<?php
		$this->box_end();

		$this->form_end();
	}

	/**
	 * A line about a model: limit and cost.
	 *
	 * @param array $model Model details.
	 * @return string
	 */
	private function model_info( array $model ) {
		$parts = array();

		if ( '' !== $model['description'] ) {
			$parts[] = rtrim( $model['description'], '.' ) . '.';
		}

		/* translators: %s: credits per character. */
		$parts[] = sprintf( __( 'List price: %s credits per character.', 'blog-to-audio-podcast' ), number_format_i18n( $model['cost'], 1 ) );

		return implode( ' ', $parts );
	}

	/**
	 * Voice <option>s.
	 *
	 * @param array  $voices  voice_id => details.
	 * @param string $current Selected voice.
	 * @return void
	 */
	private function voice_options( array $voices, $current ) {
		foreach ( $voices as $id => $voice ) {
			printf(
				'<option value="%1$s" data-preview="%2$s" data-description="%3$s" %4$s>%5$s</option>',
				esc_attr( $id ),
				esc_url( $voice['preview'] ),
				esc_attr( $voice['description'] ),
				selected( $current, $id, false ),
				esc_html( preg_replace( '/\s+-\s+.*$/', '', $voice['name'] ) )
			);
		}
	}

	/**
	 * A slider row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param float  $min         Lowest.
	 * @param float  $max         Highest.
	 * @param float  $step        Step.
	 * @param string $description Help text.
	 * @param bool   $hidden      Hide the row (the model doesn't use it).
	 * @return void
	 */
	private function slider( $key, $label, $min, $max, $step, $description, $hidden = false ) {
		$value = (float) $this->plugin->get_setting( $key );
		$id    = 'btap-' . $key;
		?>
		<tr<?php echo 'style' === $key ? ' data-btap-needs="style"' : ''; ?><?php echo $hidden ? ' hidden' : ''; ?>>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="btap-slider">
					<input type="range" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::field( $key ) ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" value="<?php echo esc_attr( $value ); ?>" data-btap-setting="<?php echo esc_attr( $key ); ?>">
					<output for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( number_format( $value, 2 ) ); ?></output>
				</div>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	// Content tab ---------------------------------------------------------------------

	/**
	 * Content tab: what gets read.
	 *
	 * @return void
	 */
	private function render_content_tab() {
		$settings = $this->plugin->get_settings();

		$this->form_start( 'content' );
		$this->box_start( __( 'What gets audio', 'blog-to-audio-podcast' ), 'file-text' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Content types', 'blog-to-audio-podcast' ); ?></th>
				<td>
					<fieldset class="btap-checks">
						<legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'blog-to-audio-podcast' ); ?></legend>
						<?php foreach ( self::readable_post_types() as $name => $label ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $settings['post_types'], true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'New posts', 'blog-to-audio-podcast' ); ?></th>
				<td><?php $this->checkbox( 'auto_create', __( 'Create audio automatically when a post is published', 'blog-to-audio-podcast' ), __( 'Off by default, since every article uses ElevenLabs credits, including posts published by Authors. Each post gets audio once: edited posts are marked as out of date for you to recreate, never redone on their own.', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
		</table>
		<?php
		$this->box_end();
		$this->box_start( __( 'What gets read', 'blog-to-audio-podcast' ), 'speech' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="btap-intro"><?php esc_html_e( 'Spoken intro', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<textarea id="btap-intro" name="<?php echo esc_attr( self::field( 'intro' ) ); ?>" rows="3" class="large-text"><?php echo esc_textarea( (string) $settings['intro'] ); ?></textarea>
					<p class="description">
						<?php
						printf(
							/* translators: %s: list of placeholders. */
							esc_html__( 'Read before the article. Use %s. Apple Podcasts asks for AI voices to be disclosed in the audio itself, which the default intro does.', 'blog-to-audio-podcast' ),
							'<code>{title}</code> <code>{site}</code> <code>{author}</code> <code>{date}</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-outro"><?php esc_html_e( 'Spoken outro', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<textarea id="btap-outro" name="<?php echo esc_attr( self::field( 'outro' ) ); ?>" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Thanks for listening. Find more articles at {site}.', 'blog-to-audio-podcast' ); ?>"><?php echo esc_textarea( (string) $settings['outro'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Read after the article. Leave empty for none.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-pronunciations"><?php esc_html_e( 'Pronunciations', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<textarea id="btap-pronunciations" name="<?php echo esc_attr( self::field( 'pronunciations' ) ); ?>" rows="5" class="large-text code" placeholder="<?php echo esc_attr( "SaaS = sass\nWooCommerce = Woo Commerce\nNginx = engine x" ); ?>"><?php echo esc_textarea( (string) $settings['pronunciations'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line: the word as written, an equals sign, then how to say it. For brand names, acronyms and anything the voice gets wrong. Whole words only, any capitalization.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		$this->box_end();
		$this->form_end();

		$this->render_text_preview_box();
	}

	/**
	 * Content tab: see the text a post's audio would read.
	 *
	 * @return void
	 */
	private function render_text_preview_box() {
		$types = $this->plugin->post_types();
		$posts = $types ? get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		) : array();

		$this->box_start( __( 'Preview the text', 'blog-to-audio-podcast' ), 'scan-text' );
		?>
		<p class="btap-intro"><?php esc_html_e( 'See exactly what would be sent to ElevenLabs for a post, after the clean-up. Free: nothing is sent.', 'blog-to-audio-podcast' ); ?></p>
		<div class="btap-voice-row">
			<label for="btap-preview-post" class="screen-reader-text"><?php esc_html_e( 'Post', 'blog-to-audio-podcast' ); ?></label>
			<select id="btap-preview-post" data-btap-preview-post>
				<?php foreach ( $posts as $post ) : ?>
					<option value="<?php echo (int) $post->ID; ?>"><?php echo esc_html( wp_html_excerpt( wp_strip_all_tags( get_the_title( $post ) ), 80, '…' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" data-btap-preview-text<?php disabled( ! $posts ); ?>>
				<?php echo Blog_To_Audio_Podcast_Icons::get( 'eye', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php esc_html_e( 'Show the text', 'blog-to-audio-podcast' ); ?>
			</button>
		</div>
		<p class="description" data-btap-preview-summary aria-live="polite"></p>
		<textarea class="large-text btap-preview-text" rows="14" readonly data-btap-preview-output hidden aria-label="<?php esc_attr_e( 'Text that would be read', 'blog-to-audio-podcast' ); ?>"></textarea>
		<?php
		$this->box_end();
	}

	// Player tab -------------------------------------------------------------------------

	/**
	 * Player tab.
	 *
	 * @return void
	 */
	private function render_player_tab() {
		$settings = $this->plugin->get_settings();

		$this->form_start( 'player' );
		$this->box_start( __( 'Audio player', 'blog-to-audio-podcast' ), 'circle-play' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show the player', 'blog-to-audio-podcast' ); ?></th>
				<td>
					<fieldset class="btap-checks">
						<legend class="screen-reader-text"><?php esc_html_e( 'Show the player', 'blog-to-audio-podcast' ); ?></legend>
						<?php
						$places = array(
							'before' => __( 'Above the article', 'blog-to-audio-podcast' ),
							'after'  => __( 'Below the article', 'blog-to-audio-podcast' ),
							'manual' => __( 'Only where I add it', 'blog-to-audio-podcast' ),
						);
						foreach ( $places as $value => $label ) :
							?>
							<label>
								<input type="radio" name="<?php echo esc_attr( self::field( 'player' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['player'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php
						printf(
							/* translators: 1: shortcode, 2: shortcode with an ID. */
							esc_html__( 'Place it yourself with %1$s, or %2$s for another post\'s audio. It only appears on posts that have audio.', 'blog-to-audio-podcast' ),
							'<code>[' . esc_html( Blog_To_Audio_Podcast_Player::SHORTCODE ) . ']</code>',
							'<code>[' . esc_html( Blog_To_Audio_Podcast_Player::SHORTCODE ) . ' id="123"]</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-player-label"><?php esc_html_e( 'Label', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php $this->text_input( 'player_label', 'btap-player-label', __( 'Listen to this article', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Details', 'blog-to-audio-podcast' ); ?></th>
				<td>
					<fieldset class="btap-checks">
						<legend class="screen-reader-text"><?php esc_html_e( 'Details', 'blog-to-audio-podcast' ); ?></legend>
						<?php $this->checkbox( 'player_duration', __( 'Show the length', 'blog-to-audio-podcast' ) ); ?>
						<?php $this->checkbox( 'player_disclosure', __( 'Say it\'s read by an AI voice', 'blog-to-audio-podcast' ) ); ?>
						<?php $this->checkbox( 'player_download', __( 'Show a download link', 'blog-to-audio-podcast' ) ); ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-attribution"><?php esc_html_e( 'Credit ElevenLabs', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<select id="btap-attribution" name="<?php echo esc_attr( self::field( 'attribution' ) ); ?>">
						<option value="auto" <?php selected( $settings['attribution'], 'auto' ); ?>><?php esc_html_e( 'Automatic: only on ElevenLabs\' free plan', 'blog-to-audio-podcast' ); ?></option>
						<option value="on" <?php selected( $settings['attribution'], 'on' ); ?>><?php esc_html_e( 'Always', 'blog-to-audio-podcast' ); ?></option>
						<option value="off" <?php selected( $settings['attribution'], 'off' ); ?>><?php esc_html_e( 'Never', 'blog-to-audio-podcast' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The free plan requires "elevenlabs.io" in the title of anything you publish, so it\'s added to the player and to podcast episode titles. Paid plans don\'t need it.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Schema', 'blog-to-audio-podcast' ); ?></th>
				<td><?php $this->checkbox( 'schema', __( 'Add AudioObject structured data', 'blog-to-audio-podcast' ), __( 'Describes the audio (title, length, file, language) to search engines in JSON-LD, printed with the player so it only appears where the audio does.', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
		</table>
		<?php
		$this->box_end();
		$this->form_end();
	}

	// Podcast tab --------------------------------------------------------------------------

	/**
	 * Podcast tab.
	 *
	 * @return void
	 */
	private function render_podcast_tab() {
		$settings = $this->plugin->get_settings();
		$image    = Blog_To_Audio_Podcast_Feed::image();
		$problem  = Blog_To_Audio_Podcast_Feed::image_problem();
		$language = get_bloginfo( 'language' );

		$this->form_start( 'podcast' );
		$this->box_start( __( 'Podcast feed', 'blog-to-audio-podcast' ), 'rss' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Feed', 'blog-to-audio-podcast' ); ?></th>
				<td><?php $this->checkbox( 'feed', __( 'Publish a podcast feed of the posts with audio', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-feed-slug"><?php esc_html_e( 'Feed address', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<code class="btap-slug-prefix"><?php echo esc_html( trailingslashit( home_url( get_option( 'permalink_structure' ) ? '/feed' : '/?feed=' ) ) ); ?></code><input type="text" id="btap-feed-slug" name="<?php echo esc_attr( self::field( 'feed_slug' ) ); ?>" value="<?php echo esc_attr( (string) $settings['feed_slug'] ); ?>" class="btap-slug" spellcheck="false">
					<p class="description"><?php esc_html_e( 'Change it only if another podcast plugin already uses this address. Once you\'ve submitted the feed, keep it the same.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		$this->box_end();
		$this->box_start( __( 'Show details', 'blog-to-audio-podcast' ), 'podcast' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="btap-podcast-title"><?php esc_html_e( 'Title', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php $this->text_input( 'podcast_title', 'btap-podcast-title', wp_strip_all_tags( get_bloginfo( 'name' ) ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-podcast-description"><?php esc_html_e( 'Description', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<textarea id="btap-podcast-description" name="<?php echo esc_attr( self::field( 'podcast_description' ) ); ?>" rows="4" class="large-text" placeholder="<?php echo esc_attr( wp_strip_all_tags( get_bloginfo( 'description' ) ) ); ?>"><?php echo esc_textarea( (string) $settings['podcast_description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'What listeners see in Apple Podcasts and Spotify. Up to 4,000 characters.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cover art', 'blog-to-audio-podcast' ); ?></th>
				<td>
					<div class="btap-art" data-btap-art>
						<input type="hidden" name="<?php echo esc_attr( self::field( 'podcast_image' ) ); ?>" value="<?php echo (int) $settings['podcast_image']; ?>" data-btap-art-id>
						<span class="btap-art-preview" data-btap-art-preview>
							<?php if ( $image ) : ?>
								<img src="<?php echo esc_url( $image['url'] ); ?>" alt="">
							<?php else : ?>
								<?php echo Blog_To_Audio_Podcast_Icons::get( 'image', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php endif; ?>
						</span>
						<span class="btap-art-actions">
							<button type="button" class="button" data-btap-art-choose><?php esc_html_e( 'Choose image', 'blog-to-audio-podcast' ); ?></button>
							<button type="button" class="button-link button-link-delete" data-btap-art-remove<?php echo $image ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'blog-to-audio-podcast' ); ?></button>
						</span>
					</div>
					<p class="description<?php echo '' !== $problem && $image ? ' btap-warn-text' : ''; ?>" data-btap-art-note>
						<?php echo esc_html( '' !== $problem ? $problem : __( 'Looks good: square, the right size and format.', 'blog-to-audio-podcast' ) ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-podcast-category"><?php esc_html_e( 'Category', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<select id="btap-podcast-category" name="<?php echo esc_attr( self::field( 'podcast_category' ) ); ?>">
						<option value=""><?php esc_html_e( 'Choose a category', 'blog-to-audio-podcast' ); ?></option>
						<?php foreach ( Blog_To_Audio_Podcast_Feed::categories() as $main => $subs ) : ?>
							<optgroup label="<?php echo esc_attr( $main ); ?>">
								<option value="<?php echo esc_attr( $main ); ?>" <?php selected( $settings['podcast_category'], $main ); ?>><?php echo esc_html( $main ); ?></option>
								<?php foreach ( $subs as $sub ) : ?>
									<option value="<?php echo esc_attr( $main . '|' . $sub ); ?>" <?php selected( $settings['podcast_category'], $main . '|' . $sub ); ?>><?php echo esc_html( $main . ' › ' . $sub ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Explicit', 'blog-to-audio-podcast' ); ?></th>
				<td><?php $this->checkbox( 'podcast_explicit', __( 'The show contains explicit language or adult content', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-podcast-author"><?php esc_html_e( 'Author', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php $this->text_input( 'podcast_author', 'btap-podcast-author', wp_strip_all_tags( get_bloginfo( 'name' ) ), __( 'Shown as the show\'s creator.', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-owner-name"><?php esc_html_e( 'Owner name', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php $this->text_input( 'podcast_owner_name', 'btap-owner-name', wp_strip_all_tags( get_bloginfo( 'name' ) ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-owner-email"><?php esc_html_e( 'Owner email', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php $this->text_input( 'podcast_owner_email', 'btap-owner-email', 'podcast@example.com', __( 'Spotify sends a code to this address to confirm the show is yours. It\'s visible in the feed, so use an address you don\'t mind being public.', 'blog-to-audio-podcast' ), 'email' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-podcast-language"><?php esc_html_e( 'Language', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php $this->text_input( 'podcast_language', 'btap-podcast-language', $language, __( 'A language code like en-US. Leave empty to use the site\'s language.', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-podcast-copyright"><?php esc_html_e( 'Copyright', 'blog-to-audio-podcast' ); ?></label></th>
				<td><?php /* translators: 1: year, 2: site name. */ $this->text_input( 'podcast_copyright', 'btap-podcast-copyright', sprintf( __( '© %1$s %2$s', 'blog-to-audio-podcast' ), gmdate( 'Y' ), wp_strip_all_tags( get_bloginfo( 'name' ) ) ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="btap-podcast-limit"><?php esc_html_e( 'Episodes in the feed', 'blog-to-audio-podcast' ); ?></label></th>
				<td>
					<input type="number" id="btap-podcast-limit" name="<?php echo esc_attr( self::field( 'podcast_limit' ) ); ?>" value="<?php echo (int) $settings['podcast_limit']; ?>" min="1" max="1000" class="small-text">
					<p class="description"><?php esc_html_e( 'The newest posts with audio. Apps keep episodes they\'ve already seen.', 'blog-to-audio-podcast' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'AI disclosure', 'blog-to-audio-podcast' ); ?></th>
				<td><?php $this->checkbox( 'podcast_disclosure', __( 'Say in the show and episode descriptions that episodes are read by an AI voice', 'blog-to-audio-podcast' ), __( 'Apple Podcasts asks shows that use AI voices to disclose it in their descriptions.', 'blog-to-audio-podcast' ) ); ?></td>
			</tr>
		</table>
		<?php
		$this->box_end();
		$this->form_end();
	}

	// Sidebar ------------------------------------------------------------------------------

	/**
	 * Sidebar boxes for a tab.
	 *
	 * @param string $tab Tab ID.
	 * @return void
	 */
	private function render_sidebar( $tab ) {
		switch ( $tab ) {
			case 'voice':
				$this->render_try_box();
				$this->render_account_box();
				break;
			case 'content':
				$this->render_skipped_box();
				break;
			case 'player':
				$this->render_player_preview_box();
				break;
			case 'podcast':
				$this->render_checklist_box();
				$this->render_submit_box();
				break;
			default:
				$this->render_account_box();
				$this->render_feed_box();
				$this->render_how_box();
				$this->render_updates_box();
		}
	}

	/**
	 * Sidebar: the account's credits.
	 *
	 * @return void
	 */
	private function render_account_box() {
		$account = self::account();

		$this->box_start( __( 'ElevenLabs credits', 'blog-to-audio-podcast' ), 'coins' );

		if ( null === $account ) {
			echo '<p>' . esc_html__( 'Not connected yet.', 'blog-to-audio-podcast' ) . ' <a href="' . esc_url( self::url( 'voice' ) ) . '">' . esc_html__( 'Add your API key', 'blog-to-audio-podcast' ) . '</a></p>';
		} elseif ( is_wp_error( $account ) ) {
			echo '<p class="btap-key-status is-bad">' . Blog_To_Audio_Podcast_Icons::get( 'circle-x', 16 ) . '<span>' . esc_html( $account->get_error_message() ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			echo '<p class="description">' . esc_html__( 'Showing credits needs the key to have read access to User. Audio can still be made without it.', 'blog-to-audio-podcast' ) . '</p>';
		} else {
			$used    = (int) $account['used'];
			$limit   = max( 1, (int) $account['limit'] );
			$percent = min( 100, (int) round( 100 * $used / $limit ) );
			?>
			<p class="btap-credits">
				<strong><?php echo esc_html( number_format_i18n( max( 0, $limit - $used ) ) ); ?></strong>
				<?php
				/* translators: %s: credits in the plan. */
				echo esc_html( sprintf( __( 'of %s credits left', 'blog-to-audio-podcast' ), number_format_i18n( $limit ) ) );
				?>
			</p>
			<div class="btap-progress<?php echo $percent > 90 ? ' is-low' : ''; ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $percent; ?>" aria-label="<?php esc_attr_e( 'Credits used', 'blog-to-audio-podcast' ); ?>"><span style="width: <?php echo (int) $percent; ?>%"></span></div>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: plan name, 2: date the credits renew. */
						__( '%1$s plan. Renews %2$s.', 'blog-to-audio-podcast' ),
						ucfirst( $account['tier'] ),
						$account['reset'] ? date_i18n( get_option( 'date_format' ), $account['reset'] ) : '–'
					)
				);
				?>
			</p>
			<p><a href="<?php echo esc_url( wp_nonce_url( self::url( $this->current_tab(), array( 'btap_do' => 'refresh' ) ), self::NONCE ) ); ?>"><?php esc_html_e( 'Refresh', 'blog-to-audio-podcast' ); ?></a> · <a href="https://elevenlabs.io/app/subscription" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage plan', 'blog-to-audio-podcast' ); ?></a></p>
			<?php
		}

		$this->box_end();
	}

	/**
	 * Sidebar: the feed's address.
	 *
	 * @return void
	 */
	private function render_feed_box() {
		$this->box_start( __( 'Podcast feed', 'blog-to-audio-podcast' ), 'rss' );

		if ( ! Blog_To_Audio_Podcast_Feed::enabled() ) {
			echo '<p>' . esc_html__( 'The podcast feed is off.', 'blog-to-audio-podcast' ) . ' <a href="' . esc_url( self::url( 'podcast' ) ) . '">' . esc_html__( 'Turn it on', 'blog-to-audio-podcast' ) . '</a></p>';
			$this->box_end();
			return;
		}

		$count = Blog_To_Audio_Podcast_Feed::count();
		$this->feed_url_field();
		?>
		<p class="description">
			<?php
			/* translators: %s: number of episodes. */
			echo esc_html( sprintf( _n( '%s episode so far.', '%s episodes so far.', $count, 'blog-to-audio-podcast' ), number_format_i18n( $count ) ) );
			?>
		</p>
		<p><a href="<?php echo esc_url( self::url( 'podcast' ) ); ?>"><?php esc_html_e( 'Set up and submit to Apple Podcasts and Spotify', 'blog-to-audio-podcast' ); ?></a></p>
		<?php
		$this->box_end();
	}

	/**
	 * The feed URL with a copy button.
	 *
	 * @return void
	 */
	private function feed_url_field() {
		$url = Blog_To_Audio_Podcast_Feed::url();
		?>
		<div class="btap-copy">
			<label for="btap-feed-url" class="screen-reader-text"><?php esc_html_e( 'Feed address', 'blog-to-audio-podcast' ); ?></label>
			<input type="text" id="btap-feed-url" class="code" value="<?php echo esc_attr( $url ); ?>" readonly data-btap-copy-source>
			<button type="button" class="button" data-btap-copy aria-label="<?php esc_attr_e( 'Copy the feed address', 'blog-to-audio-podcast' ); ?>"><?php echo Blog_To_Audio_Podcast_Icons::get( 'copy', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
			<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Open the feed', 'blog-to-audio-podcast' ); ?>"><?php echo Blog_To_Audio_Podcast_Icons::get( 'external-link', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></a>
		</div>
		<?php
	}

	/**
	 * Sidebar: what the plugin does.
	 *
	 * @return void
	 */
	private function render_how_box() {
		$items = array(
			array( 'file-text', __( 'Posts are cleaned up for reading: no code, tables, captions or link lists', 'blog-to-audio-podcast' ) ),
			array( 'audio-lines', __( 'Long posts are read in parts that are stitched into one seamless MP3', 'blog-to-audio-podcast' ) ),
			array( 'hard-drive', __( 'Audio is saved in your Media Library and played from your own site', 'blog-to-audio-podcast' ) ),
			array( 'refresh-cw', __( 'Edited posts are flagged, never redone without you, so credits aren\'t spent by surprise', 'blog-to-audio-podcast' ) ),
			array( 'circle-pause', __( 'The queue pauses itself if credits run out or the key stops working', 'blog-to-audio-podcast' ) ),
		);

		$this->box_start( __( 'How it works', 'blog-to-audio-podcast' ), 'list-checks' );
		?>
		<ul class="btap-checklist">
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo Blog_To_Audio_Podcast_Icons::get( $item[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php
		$this->box_end();
	}

	/**
	 * Sidebar (ElevenLabs tab): try the voice before saving.
	 *
	 * @return void
	 */
	private function render_try_box() {
		$this->box_start( __( 'Try it', 'blog-to-audio-podcast' ), 'headphones', 'btap-try', ' data-btap-try' );

		if ( '' === Blog_To_Audio_Podcast_Plugin::api_key() ) {
			echo '<p>' . esc_html__( 'Save your API key first, then try voices and settings here.', 'blog-to-audio-podcast' ) . '</p>';
			$this->box_end();
			return;
		}
		?>
		<p class="btap-intro"><?php esc_html_e( 'Hear the voice and settings chosen on this page, saved or not. A sample uses a few dozen credits.', 'blog-to-audio-podcast' ); ?></p>
		<label for="btap-try-text" class="screen-reader-text"><?php esc_html_e( 'Text to read', 'blog-to-audio-podcast' ); ?></label>
		<textarea id="btap-try-text" rows="4" class="large-text" maxlength="300" data-btap-try-text><?php esc_html_e( 'Updating old posts is one of the quickest ways to grow your traffic. Here\'s how to choose which ones to start with.', 'blog-to-audio-podcast' ); ?></textarea>
		<p>
			<button type="button" class="button button-secondary" data-btap-try-button>
				<?php echo Blog_To_Audio_Podcast_Icons::get( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php esc_html_e( 'Make a sample', 'blog-to-audio-podcast' ); ?>
			</button>
		</p>
		<p class="description" data-btap-try-status aria-live="polite"></p>
		<audio controls class="btap-try-audio" data-btap-try-audio hidden></audio>
		<?php
		$this->box_end();
	}

	/**
	 * Sidebar (Content tab): what's left out.
	 *
	 * @return void
	 */
	private function render_skipped_box() {
		$items = array(
			__( 'Code, tables and preformatted text', 'blog-to-audio-podcast' ),
			__( 'Images, galleries, captions, videos and embeds', 'blog-to-audio-podcast' ),
			__( 'Buttons, forms, tables of contents and related-post lists', 'blog-to-audio-podcast' ),
			__( 'Affiliate disclosures and "Read more:" links', 'blog-to-audio-podcast' ),
			__( 'Shortcodes, footnote markers and struck-out text', 'blog-to-audio-podcast' ),
			__( 'Bare links, which are read as their domain name', 'blog-to-audio-podcast' ),
		);

		$this->box_start( __( 'Left out of the audio', 'blog-to-audio-podcast' ), 'eye-off' );
		?>
		<ul class="btap-checklist">
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<p><strong><?php esc_html_e( 'Leave out anything else', 'blog-to-audio-podcast' ); ?></strong></p>
		<ul class="btap-checklist">
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'square-dashed', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Wrap it in the "Exclude from audio" block', 'blog-to-audio-podcast' ); ?></span></li>
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'brackets', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo wp_kses( __( 'In the classic editor, wrap it in <code>[no_audio]</code> … <code>[/no_audio]</code>', 'blog-to-audio-podcast' ), array( 'code' => array() ) ); ?></span></li>
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'tag', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo wp_kses( __( 'Or give any block the CSS class <code>no-audio</code>', 'blog-to-audio-podcast' ), array( 'code' => array() ) ); ?></span></li>
		</ul>
		<?php
		$this->box_end();
	}

	/**
	 * Sidebar (Player tab): what the player looks like.
	 *
	 * @return void
	 */
	private function render_player_preview_box() {
		$types = $this->plugin->post_types();
		$posts = $types ? get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => Blog_To_Audio_Podcast_Audio::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'has_password'   => false,
			)
		) : array();

		$this->box_start( __( 'Preview', 'blog-to-audio-podcast' ), 'eye' );

		$player = $posts ? Blog_To_Audio_Podcast_Player::render( $posts[0] ) : '';

		if ( '' !== $player ) {
			echo '<div class="btap-player-preview">' . $player . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			echo '<p class="description">' . esc_html__( 'On your site the player takes its font and colors from your theme. Save to see changes.', 'blog-to-audio-podcast' ) . '</p>';
			echo '<p><a href="' . esc_url( get_permalink( $posts[0] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See it on a post', 'blog-to-audio-podcast' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'The preview appears once a published post has audio.', 'blog-to-audio-podcast' ) . '</p>';
		}
		?>
		<p><strong><?php esc_html_e( 'Accessible by design', 'blog-to-audio-podcast' ); ?></strong></p>
		<ul class="btap-checklist">
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'keyboard', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'The browser\'s own controls: keyboard and screen reader friendly', 'blog-to-audio-podcast' ); ?></span></li>
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'volume-x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Never plays on its own, and loads nothing until someone presses play', 'blog-to-audio-podcast' ); ?></span></li>
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'file-text', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'The article on the page is the transcript', 'blog-to-audio-podcast' ); ?></span></li>
			<li><?php echo Blog_To_Audio_Podcast_Icons::get( 'contrast', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Uses your theme\'s text colors, so contrast matches the rest of the page', 'blog-to-audio-podcast' ); ?></span></li>
		</ul>
		<?php
		$this->box_end();
	}

	/**
	 * Sidebar (Podcast tab): what Apple and Spotify need.
	 *
	 * @return void
	 */
	private function render_checklist_box() {
		$settings = $this->plugin->get_settings();
		$count    = Blog_To_Audio_Podcast_Feed::enabled() ? Blog_To_Audio_Podcast_Feed::count() : 0;
		$problem  = Blog_To_Audio_Podcast_Feed::image_problem();
		$checks   = array(
			array( Blog_To_Audio_Podcast_Feed::enabled(), __( 'The feed is on', 'blog-to-audio-podcast' ) ),
			/* translators: %s: number of episodes. */
			array( $count > 0, $count ? sprintf( _n( '%s episode', '%s episodes', $count, 'blog-to-audio-podcast' ), number_format_i18n( $count ) ) : __( 'At least one post with audio', 'blog-to-audio-podcast' ) ),
			array( '' === $problem, '' === $problem ? __( 'Cover art', 'blog-to-audio-podcast' ) : $problem ),
			array( (bool) Blog_To_Audio_Podcast_Feed::parse_category( (string) $settings['podcast_category'] ), __( 'A category', 'blog-to-audio-podcast' ) ),
			array( '' !== (string) $settings['podcast_owner_email'], __( 'An owner email (Spotify needs it)', 'blog-to-audio-podcast' ) ),
			array( '' !== Blog_To_Audio_Podcast_Feed::description(), __( 'A description', 'blog-to-audio-podcast' ) ),
		);

		$this->box_start( __( 'Ready to submit?', 'blog-to-audio-podcast' ), 'badge-check' );
		?>
		<ul class="btap-checklist btap-readiness">
			<?php foreach ( $checks as $check ) : ?>
				<li class="<?php echo $check[0] ? 'is-good' : 'is-bad'; ?>">
					<?php echo Blog_To_Audio_Podcast_Icons::get( $check[0] ? 'circle-check' : 'circle-x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php echo esc_html( $check[1] ); ?><span class="screen-reader-text"><?php echo esc_html( $check[0] ? __( '(done)', 'blog-to-audio-podcast' ) : __( '(missing)', 'blog-to-audio-podcast' ) ); ?></span></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		if ( Blog_To_Audio_Podcast_Feed::enabled() ) {
			$this->feed_url_field();
		}

		$this->box_end();
	}

	/**
	 * Sidebar (Podcast tab): how to submit the feed.
	 *
	 * @return void
	 */
	private function render_submit_box() {
		$steps = array(
			array(
				'simpleicons/applepodcasts',
				__( 'Apple Podcasts', 'blog-to-audio-podcast' ),
				'https://podcastsconnect.apple.com/',
				__( 'Sign in to Podcasts Connect, click + and New Show, choose "Add a show with an RSS feed" and paste the feed address. Review takes a day or two.', 'blog-to-audio-podcast' ),
			),
			array(
				'simpleicons/spotify',
				__( 'Spotify', 'blog-to-audio-podcast' ),
				'https://creators.spotify.com/',
				__( 'In Spotify for Creators, choose to add an existing podcast, paste the feed address and enter the code Spotify emails to the owner address.', 'blog-to-audio-podcast' ),
			),
			array(
				'simpleicons/podcastindex',
				__( 'Everywhere else', 'blog-to-audio-podcast' ),
				'https://podcastindex.org/add',
				__( 'Podcast Index feeds Overcast, Pocket Casts, Podcast Addict and many more apps. YouTube Music and Amazon Music take RSS feeds too.', 'blog-to-audio-podcast' ),
			),
		);

		$this->box_start( __( 'Submit your podcast', 'blog-to-audio-podcast' ), 'send' );
		?>
		<ol class="btap-submit-steps">
			<?php foreach ( $steps as $step ) : ?>
				<li>
					<span class="btap-brand"><?php echo Blog_To_Audio_Podcast_Icons::get( $step[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span>
						<a href="<?php echo esc_url( $step[2] ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( $step[1] ); ?></strong></a>
						<span class="description"><?php echo esc_html( $step[3] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="description"><?php esc_html_e( 'New audio shows up in apps on its own: they check the feed every few hours.', 'blog-to-audio-podcast' ); ?></p>
		<?php
		$this->box_end();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( BLOG_TO_AUDIO_PODCAST_REPO ) );
		$check_url  = Blog_To_Audio_Podcast_Updater::check_now_url();

		$this->box_start( __( 'Updates', 'blog-to-audio-podcast' ), 'refresh-cw', 'btap-updates-box' );
		?>
		<p>
			<?php
			/* translators: %s: installed plugin version. */
			printf( esc_html__( 'Installed version: %s', 'blog-to-audio-podcast' ), '<strong>' . esc_html( BLOG_TO_AUDIO_PODCAST_VERSION ) . '</strong>' );
			?>
		</p>

		<?php if ( ! Blog_To_Audio_Podcast_Updater::has_repo() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: header name, 2: main plugin file. */
					esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'blog-to-audio-podcast' ),
					'<code>Update URI</code>',
					'<code>' . esc_html( plugin_basename( BLOG_TO_AUDIO_PODCAST_FILE ) ) . '</code>'
				);
				?>
			</p>
		<?php elseif ( Blog_To_Audio_Podcast_Updater::is_dev_copy() ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: repo, 2: constant name. */
					esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'blog-to-audio-podcast' ),
					'<a href="' . esc_url( BLOG_TO_AUDIO_PODCAST_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
					'<code>BLOG_TO_AUDIO_PODCAST_DEV_MODE</code>'
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: repo. */
					esc_html__( 'Updates come from the latest release of %s.', 'blog-to-audio-podcast' ),
					'<a href="' . esc_url( BLOG_TO_AUDIO_PODCAST_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
				);
				?>
			</p>
			<?php if ( $check_url ) : ?>
				<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'blog-to-audio-podcast' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		$this->box_end();
	}
}
