<?php
/**
 * The front-end audio player and its AudioObject schema.
 *
 * The player is the browser's own <audio> element: no player library, no
 * JavaScript, nothing downloaded until someone presses play
 * (preload="none"), and a few hundred bytes of CSS printed only on pages that
 * show it. Native controls work with the keyboard and screen readers out of
 * the box, and the article itself is the transcript.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Player and schema.
 */
final class Blog_To_Audio_Podcast_Player {

	/**
	 * Shortcode that prints the player.
	 */
	const SHORTCODE = 'blog_audio';

	/**
	 * Plugin instance.
	 *
	 * @var Blog_To_Audio_Podcast_Plugin
	 */
	private static $plugin;

	/**
	 * Whether the CSS has been printed on this page.
	 *
	 * @var bool
	 */
	private static $styled = false;

	/**
	 * Posts whose player is already on this page (so schema is printed once).
	 *
	 * @var int[]
	 */
	private static $shown = array();

	/**
	 * Hook in.
	 *
	 * @param Blog_To_Audio_Podcast_Plugin $plugin Plugin.
	 * @return void
	 */
	public static function init( Blog_To_Audio_Podcast_Plugin $plugin ) {
		self::$plugin = $plugin;

		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		// After wpautop and shortcodes, so nothing rewrites the player's markup.
		add_filter( 'the_content', array( __CLASS__, 'add_to_content' ), 20 );
	}

	/**
	 * Put the player above or below the article.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function add_to_content( $content ) {
		$where = (string) self::$plugin->get_setting( 'player' );

		// Block themes before WordPress 6.4 render the post outside "the loop".
		$in_loop = in_the_loop() || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );

		if ( 'manual' === $where || is_feed() || ! is_singular() || ! $in_loop || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post || (int) get_queried_object_id() !== (int) $post->ID || has_shortcode( $content, self::SHORTCODE ) ) {
			return $content;
		}

		$player = self::render( $post );

		if ( '' === $player ) {
			return $content;
		}

		return 'after' === $where ? $content . $player : $player . $content;
	}

	/**
	 * [blog_audio] or [blog_audio id="123"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, self::SHORTCODE );
		$post = $atts['id'] ? get_post( absint( $atts['id'] ) ) : get_post();

		// Only for posts this visitor may read.
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) ) {
			return '';
		}

		return self::render( $post );
	}

	/**
	 * The player for a post, or nothing if it has no audio.
	 *
	 * @param WP_Post $post Post.
	 * @return string HTML.
	 */
	public static function render( WP_Post $post ) {
		if ( ! self::$plugin->supports( $post ) || post_password_required( $post ) || Blog_To_Audio_Podcast_Audio::skipped( $post->ID ) || in_array( $post->ID, self::$shown, true ) ) {
			return '';
		}

		$audio = Blog_To_Audio_Podcast_Audio::get( $post );

		if ( ! $audio ) {
			return '';
		}

		self::$shown[] = $post->ID;

		$settings = self::$plugin->get_settings();
		$title    = wp_strip_all_tags( get_the_title( $post ) );
		$id       = 'btap-player-' . $post->ID;
		$meta     = array();

		if ( ! empty( $settings['player_duration'] ) && $audio['duration'] > 0 ) {
			$meta[] = '<time datetime="' . esc_attr( self::iso_duration( $audio['duration'] ) ) . '">' . esc_html( Blog_To_Audio_Podcast_Audio::human_duration( $audio['duration'] ) ) . '</time>';
		}

		if ( ! empty( $settings['player_disclosure'] ) ) {
			$meta[] = esc_html__( 'Read by an AI voice', 'blog-to-audio-podcast' );
		}

		if ( self::attribution_on() ) {
			$meta[] = esc_html__( 'Voice: elevenlabs.io', 'blog-to-audio-podcast' );
		}

		$label = '' !== trim( (string) $settings['player_label'] ) ? (string) $settings['player_label'] : __( 'Listen to this article', 'blog-to-audio-podcast' );

		ob_start();
		self::print_style();
		?>
		<figure class="btap-player" id="<?php echo esc_attr( $id ); ?>">
			<figcaption class="btap-player-head">
				<svg class="btap-player-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>
				<span class="btap-player-label" id="<?php echo esc_attr( $id ); ?>-label"><?php echo esc_html( $label ); ?></span>
				<?php if ( $meta ) : ?>
					<span class="btap-player-meta"><?php echo implode( '<span aria-hidden="true"> · </span>', $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></span>
				<?php endif; ?>
			</figcaption>
			<audio class="btap-player-audio" controls preload="none" src="<?php echo esc_url( $audio['url'] ); ?>" aria-label="<?php /* translators: %s: post title. */ echo esc_attr( sprintf( __( 'Audio version of %s', 'blog-to-audio-podcast' ), $title ) ); ?>">
				<a href="<?php echo esc_url( $audio['url'] ); ?>"><?php esc_html_e( 'Download the audio (MP3)', 'blog-to-audio-podcast' ); ?></a>
			</audio>
			<?php if ( ! empty( $settings['player_download'] ) ) : ?>
				<a class="btap-player-download" href="<?php echo esc_url( $audio['url'] ); ?>" download>
					<?php
					/* translators: %s: file size, e.g. "7 MB". */
					echo esc_html( sprintf( __( 'Download MP3 (%s)', 'blog-to-audio-podcast' ), size_format( $audio['bytes'] ) ) );
					?>
				</a>
			<?php endif; ?>
			<?php
			if ( ! empty( $settings['schema'] ) ) {
				self::print_schema( $post, $audio );
			}
			?>
		</figure>
		<?php
		// One line, so themes and filters can't wedge <p> or <br> tags into it.
		return (string) preg_replace( '/>\s+</', '><', trim( (string) ob_get_clean() ) );
	}

	/**
	 * Whether to credit ElevenLabs. The free plan requires it; paid plans don't.
	 *
	 * @return bool
	 */
	public static function attribution_on() {
		$setting = (string) self::$plugin->get_setting( 'attribution' );

		if ( 'auto' === $setting ) {
			return 'free' === (string) get_option( 'blog_to_audio_podcast_tier', '' );
		}

		return 'on' === $setting;
	}

	/**
	 * The player's CSS, once per page. It takes its colours from the theme
	 * (currentColor), so it fits light and dark designs, and contrast is the
	 * theme's own text contrast.
	 *
	 * @return void
	 */
	private static function print_style() {
		if ( self::$styled ) {
			return;
		}

		self::$styled = true;

		// No width or side margins: block themes size it to the article column.
		$css = '.btap-player{box-sizing:border-box;margin-top:0;margin-bottom:1.5em;padding:.85em 1em 1em;border:1px solid;border-color:rgba(127,127,127,.35);border-radius:10px}'
			. '.btap-player-head{display:flex;flex-wrap:wrap;align-items:center;gap:.25em .6em;margin:0 0 .6em;padding:0;font-size:.95em;line-height:1.4}'
			. '.btap-player-icon{flex:none}'
			. '.btap-player-label{font-weight:600}'
			. '.btap-player-meta{font-size:.9em}'
			. '.btap-player-audio{display:block;width:100%;min-height:40px}'
			. '.btap-player-download{display:inline-block;margin-top:.6em;font-size:.9em}';

		/**
		 * Filters the player's CSS. Return an empty string to style it in your theme.
		 *
		 * @param string $css CSS.
		 */
		$css = (string) apply_filters( 'blog_to_audio_podcast_player_css', $css );

		if ( '' !== $css ) {
			echo '<style id="btap-player-css">' . wp_strip_all_tags( $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain CSS, tags stripped.
		}
	}

	/**
	 * AudioObject JSON-LD for the post's audio, printed with the player so
	 * the schema describes audio that's really on the page.
	 *
	 * @param WP_Post $post  Post.
	 * @param array   $audio Audio details.
	 * @return void
	 */
	private static function print_schema( WP_Post $post, array $audio ) {
		$permalink = get_permalink( $post );
		$author    = get_userdata( (int) $post->post_author );
		$excerpt   = wp_strip_all_tags( get_the_excerpt( $post ) );
		$schema    = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'AudioObject',
			'@id'                 => $permalink . '#audio',
			'name'                => wp_strip_all_tags( get_the_title( $post ) ),
			'contentUrl'          => $audio['url'],
			'encodingFormat'      => 'audio/mpeg',
			'duration'            => self::iso_duration( $audio['duration'] ),
			'contentSize'         => size_format( $audio['bytes'] ),
			'bitrate'             => '128 kbps',
			'uploadDate'          => gmdate( 'c', $audio['created'] ? (int) $audio['created'] : time() ),
			'inLanguage'          => get_bloginfo( 'language' ),
			'isAccessibleForFree' => true,
			'url'                 => $permalink,
			'mainEntityOfPage'    => $permalink,
			'publisher'           => array(
				'@type' => 'Organization',
				'name'  => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'url'   => home_url( '/' ),
			),
		);

		if ( '' !== $excerpt ) {
			$schema['description'] = $excerpt;
		}

		if ( $author ) {
			$schema['author'] = array(
				'@type' => 'Person',
				'name'  => $author->display_name,
			);
		}

		if ( has_post_thumbnail( $post ) ) {
			$schema['thumbnailUrl'] = get_the_post_thumbnail_url( $post, 'full' );
		}

		/**
		 * Filters the AudioObject schema printed with the player.
		 *
		 * @param array   $schema Schema.
		 * @param WP_Post $post   Post.
		 * @param array   $audio  Audio details.
		 */
		$schema = apply_filters( 'blog_to_audio_podcast_schema', $schema, $post, $audio );

		if ( $schema ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and & escaped.
		}
	}

	/**
	 * Seconds as an ISO 8601 duration: PT8M5S.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function iso_duration( $seconds ) {
		$seconds = (int) round( $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		return 'PT' . ( $hours ? $hours . 'H' : '' ) . ( $minutes ? $minutes . 'M' : '' ) . $secs . 'S';
	}
}
