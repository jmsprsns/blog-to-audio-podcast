<?php
/**
 * The podcast feed (/feed/podcast/) for Apple Podcasts, Spotify and other apps.
 *
 * A standard RSS 2.0 feed with Apple's iTunes tags (cover art, category,
 * explicit flag, owner, duration) and the open Podcasting 2.0 tags (a
 * permanent show GUID, and the article as each episode's transcript). Only
 * published, public posts with audio are listed.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Podcast feed.
 */
final class Blog_To_Audio_Podcast_Feed {

	/**
	 * Option recording which feed address the rewrite rules were built for.
	 */
	const REWRITE_OPTION = 'blog_to_audio_podcast_rewrite';

	/**
	 * Option holding the show's permanent podcast:guid.
	 */
	const GUID_OPTION = 'blog_to_audio_podcast_guid';

	/**
	 * Podcasting 2.0 namespace for show GUIDs (UUID v5).
	 */
	const GUID_NAMESPACE = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_feed' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_action( 'wp_head', array( __CLASS__, 'discovery_link' ) );
		add_filter( 'feed_content_type', array( __CLASS__, 'content_type' ), 10, 2 );
		add_filter( 'get_lastpostmodified', array( __CLASS__, 'last_modified' ), 10, 2 );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	private static function settings() {
		return blog_to_audio_podcast_plugin()->get_settings();
	}

	/**
	 * Whether the feed is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = self::settings();

		return ! empty( $settings['feed'] );
	}

	/**
	 * The feed's name in its address: /feed/{slug}/.
	 *
	 * @return string
	 */
	public static function slug() {
		$settings = self::settings();
		$slug     = sanitize_title( (string) $settings['feed_slug'] );

		return '' !== $slug ? $slug : 'podcast';
	}

	/**
	 * Register the feed.
	 *
	 * @return void
	 */
	public static function register_feed() {
		if ( self::enabled() ) {
			add_feed( self::slug(), array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Remove the feed, so flushing the rewrite rules drops its address.
	 *
	 * @return void
	 */
	public static function unregister_feed() {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof WP_Rewrite && is_array( $wp_rewrite->feeds ) ) {
			$wp_rewrite->feeds = array_values( array_diff( $wp_rewrite->feeds, array( self::slug() ) ) );
		}
	}

	/**
	 * What the rewrite rules depend on.
	 *
	 * @return string
	 */
	public static function rewrite_signature() {
		return self::enabled() ? self::slug() : 'off';
	}

	/**
	 * Rebuild the rewrite rules once after the feed is turned on, off or renamed.
	 *
	 * @return void
	 */
	public static function maybe_flush() {
		$signature = self::rewrite_signature();

		if ( get_option( self::REWRITE_OPTION ) !== $signature ) {
			flush_rewrite_rules( false );
			// Autoloaded: it's checked on every request.
			update_option( self::REWRITE_OPTION, $signature, true );
		}
	}

	/**
	 * The feed's address.
	 *
	 * @return string
	 */
	public static function url() {
		return get_feed_link( self::slug() );
	}

	/**
	 * Whether this request is for the podcast feed.
	 *
	 * @return bool
	 */
	private static function is_request() {
		global $wp;

		return self::enabled() && $wp instanceof WP && isset( $wp->query_vars['feed'] ) && self::slug() === $wp->query_vars['feed'];
	}

	/**
	 * Serve the feed as RSS.
	 *
	 * @param string $content_type Content type.
	 * @param string $type         Feed type.
	 * @return string
	 */
	public static function content_type( $content_type, $type ) {
		return self::enabled() && self::slug() === $type ? 'application/rss+xml' : $content_type;
	}

	/**
	 * WordPress answers "not modified" to feed readers based on when a post
	 * was last edited. Audio can be added without editing a post, so the
	 * podcast feed also counts the last time audio changed.
	 *
	 * @param string|false $modified Last modified time.
	 * @param string       $timezone gmt, blog or server.
	 * @return string|false
	 */
	public static function last_modified( $modified, $timezone ) {
		// Core asks for 'GMT' when it sends feed headers.
		if ( 'gmt' !== strtolower( (string) $timezone ) || ! self::is_request() ) {
			return $modified;
		}

		$changed = (int) get_option( Blog_To_Audio_Podcast_Audio::CHANGED_OPTION, 0 );

		if ( ! $changed ) {
			return $modified;
		}

		$changed = gmdate( 'Y-m-d H:i:s', $changed );

		return ( ! $modified || $changed > $modified ) ? $changed : $modified;
	}

	/**
	 * Let browsers and apps find the feed from any page.
	 *
	 * @return void
	 */
	public static function discovery_link() {
		if ( ! self::enabled() ) {
			return;
		}

		printf(
			'<link rel="alternate" type="application/rss+xml" title="%1$s" href="%2$s">' . "\n",
			esc_attr( self::title() ),
			esc_url( self::url() )
		);
	}

	// Show details ----------------------------------------------------------------

	/**
	 * Show title.
	 *
	 * @return string
	 */
	public static function title() {
		$settings = self::settings();
		$title    = trim( (string) $settings['podcast_title'] );

		return '' !== $title ? $title : wp_strip_all_tags( get_bloginfo( 'name' ) );
	}

	/**
	 * Show description.
	 *
	 * @return string
	 */
	public static function description() {
		$settings    = self::settings();
		$description = trim( (string) $settings['podcast_description'] );

		if ( '' === $description ) {
			$tagline = wp_strip_all_tags( get_bloginfo( 'description' ) );
			/* translators: %s: site name. */
			$description = '' !== $tagline ? $tagline : sprintf( __( 'Audio versions of articles from %s.', 'blog-to-audio-podcast' ), wp_strip_all_tags( get_bloginfo( 'name' ) ) );
		}

		if ( ! empty( $settings['podcast_disclosure'] ) ) {
			$description .= ' ' . __( 'Episodes are read by an AI voice.', 'blog-to-audio-podcast' );
		}

		return $description;
	}

	/**
	 * Show author.
	 *
	 * @return string
	 */
	public static function author() {
		$settings = self::settings();
		$author   = trim( (string) $settings['podcast_author'] );

		return '' !== $author ? $author : wp_strip_all_tags( get_bloginfo( 'name' ) );
	}

	/**
	 * Show language, as a lowercase code like "en-us".
	 *
	 * @return string
	 */
	public static function language() {
		$settings = self::settings();
		$language = trim( (string) $settings['podcast_language'] );

		return strtolower( '' !== $language ? $language : get_bloginfo( 'language' ) );
	}

	/**
	 * Cover art: URL, width and height.
	 *
	 * @return array{url:string,width:int,height:int,mime:string}|null
	 */
	public static function image() {
		$settings = self::settings();
		$id       = (int) $settings['podcast_image'];
		$src      = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;

		if ( ! $src ) {
			return null;
		}

		return array(
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'mime'   => (string) get_post_mime_type( $id ),
		);
	}

	/**
	 * Why the cover art won't be accepted, if it won't.
	 *
	 * @return string Empty when it's fine.
	 */
	public static function image_problem() {
		$image = self::image();

		if ( ! $image ) {
			return __( 'Add cover art: a square JPG or PNG, 1400 to 3000 pixels wide.', 'blog-to-audio-podcast' );
		}

		if ( ! in_array( $image['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return __( 'Cover art has to be a JPG or PNG.', 'blog-to-audio-podcast' );
		}

		if ( $image['width'] !== $image['height'] ) {
			/* translators: 1: width, 2: height. */
			return sprintf( __( 'Cover art has to be square. This image is %1$d × %2$d pixels.', 'blog-to-audio-podcast' ), $image['width'], $image['height'] );
		}

		if ( $image['width'] < 1400 || $image['width'] > 3000 ) {
			/* translators: %d: width in pixels. */
			return sprintf( __( 'Cover art has to be between 1400 and 3000 pixels wide. This image is %d pixels.', 'blog-to-audio-podcast' ), $image['width'] );
		}

		return '';
	}

	/**
	 * The show's permanent GUID. Made once from the feed's first address and
	 * kept, so it stays the same even if the address changes later.
	 *
	 * @return string
	 */
	public static function guid() {
		$guid = (string) get_option( self::GUID_OPTION, '' );

		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $guid ) ) {
			return $guid;
		}

		$name = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', self::url() ), '/' );
		$hash = sha1( hex2bin( str_replace( '-', '', self::GUID_NAMESPACE ) ) . $name );
		$guid = sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
			substr( $hash, 20, 12 )
		);

		update_option( self::GUID_OPTION, $guid, false );

		return $guid;
	}

	/**
	 * Apple Podcasts categories and subcategories.
	 *
	 * @return array<string,string[]>
	 */
	public static function categories() {
		return array(
			'Arts'                    => array( 'Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts' ),
			'Business'                => array( 'Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit' ),
			'Comedy'                  => array( 'Comedy Interviews', 'Improv', 'Stand-Up' ),
			'Education'               => array( 'Courses', 'How To', 'Language Learning', 'Self-Improvement' ),
			'Fiction'                 => array( 'Comedy Fiction', 'Drama', 'Science Fiction' ),
			'Government'              => array(),
			'Health & Fitness'        => array( 'Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality' ),
			'History'                 => array(),
			'Kids & Family'           => array( 'Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids' ),
			'Leisure'                 => array( 'Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies', 'Home & Garden', 'Video Games' ),
			'Music'                   => array( 'Music Commentary', 'Music History', 'Music Interviews' ),
			'News'                    => array( 'Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics', 'Sports News', 'Tech News' ),
			'Religion & Spirituality' => array( 'Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality' ),
			'Science'                 => array( 'Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics', 'Natural Sciences', 'Nature', 'Physics', 'Social Sciences' ),
			'Society & Culture'       => array( 'Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships' ),
			'Sports'                  => array( 'Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'Football', 'Golf', 'Hockey', 'Rugby', 'Running', 'Soccer', 'Swimming', 'Tennis', 'Volleyball', 'Wilderness', 'Wrestling' ),
			'Technology'              => array(),
			'True Crime'              => array(),
			'TV & Film'               => array( 'After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews' ),
		);
	}

	/**
	 * Split a saved category ("Business|Marketing") into its parts, if valid.
	 *
	 * @param string $value Saved value.
	 * @return array{0:string,1:string}|null Category and subcategory ('' for none).
	 */
	public static function parse_category( $value ) {
		$parts = explode( '|', (string) $value, 2 );
		$main  = $parts[0];
		$sub   = isset( $parts[1] ) ? $parts[1] : '';
		$list  = self::categories();

		if ( ! isset( $list[ $main ] ) || ( '' !== $sub && ! in_array( $sub, $list[ $main ], true ) ) ) {
			return null;
		}

		return array( $main, $sub );
	}

	// Episodes ----------------------------------------------------------------------

	/**
	 * Posts in the feed, newest first.
	 *
	 * @param int $limit Most to return.
	 * @return WP_Post[]
	 */
	public static function episodes( $limit ) {
		$types = blog_to_audio_podcast_plugin()->post_types();

		if ( ! $types ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => max( 1, min( 1000, (int) $limit ) ),
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => Blog_To_Audio_Podcast_Audio::META_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Blog_To_Audio_Podcast_Audio::META_SKIP,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * How many episodes the feed lists.
	 *
	 * @return int
	 */
	public static function count() {
		$settings = self::settings();

		return count( self::episodes( (int) $settings['podcast_limit'] ) );
	}

	/**
	 * An episode's description, as HTML: the excerpt and a link to the article.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function episode_description( WP_Post $post ) {
		$settings = self::settings();
		$excerpt  = trim( html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$excerpt  = (string) preg_replace( '/\s*\[(…|\.\.\.)\]$/u', '…', $excerpt );
		$html     = '' !== $excerpt ? '<p>' . esc_html( $excerpt ) . '</p>' : '';

		$html .= '<p>' . sprintf(
			/* translators: %s: link to the article. */
			esc_html__( 'Read the full article: %s', 'blog-to-audio-podcast' ),
			'<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) . '</a>'
		) . '</p>';

		if ( ! empty( $settings['podcast_disclosure'] ) ) {
			$html .= '<p>' . esc_html__( 'This episode is read by an AI voice.', 'blog-to-audio-podcast' ) . '</p>';
		}

		return $html;
	}

	/**
	 * Serve the feed. Podcast apps poll feeds often, so the XML is cached
	 * until audio, a post or the settings change.
	 *
	 * @return void
	 */
	public static function render() {
		$key = md5(
			wp_json_encode(
				array(
					BLOG_TO_AUDIO_PODCAST_VERSION,
					get_option( Blog_To_Audio_Podcast_Audio::CHANGED_OPTION, 0 ),
					get_lastpostmodified( 'gmt' ),
					get_option( 'blog_to_audio_podcast_tier', '' ),
					self::url(),
				)
			)
		);

		$cached = get_transient( 'blog_to_audio_podcast_feed' );

		if ( is_array( $cached ) && isset( $cached['key'], $cached['xml'] ) && $cached['key'] === $key ) {
			$xml = (string) $cached['xml'];
		} else {
			ob_start();
			self::print_feed();
			$xml = (string) ob_get_clean();

			set_transient(
				'blog_to_audio_podcast_feed',
				array(
					'key' => $key,
					'xml' => $xml,
				),
				12 * HOUR_IN_SECONDS
			);
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/rss+xml; charset=UTF-8', true );
		}

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in print_feed().
	}

	/**
	 * Print the feed's XML.
	 *
	 * @return void
	 */
	private static function print_feed() {
		$settings = self::settings();
		$episodes = self::episodes( (int) $settings['podcast_limit'] );

		// Load every episode's audio attachment in one query.
		$attachments = array();

		foreach ( $episodes as $episode ) {
			$attachments[] = (int) get_post_meta( $episode->ID, Blog_To_Audio_Podcast_Audio::META_ID, true );
		}

		if ( array_filter( $attachments ) ) {
			_prime_post_caches( array_filter( $attachments ), false, true );
		}
		$image    = self::image();
		$category = self::parse_category( (string) $settings['podcast_category'] );
		$explicit = ! empty( $settings['podcast_explicit'] ) ? 'true' : 'false';
		$credit   = Blog_To_Audio_Podcast_Player::attribution_on();
		$email    = sanitize_email( (string) $settings['podcast_owner_email'] );
		$owner    = trim( (string) $settings['podcast_owner_name'] );
		$built    = (int) get_option( Blog_To_Audio_Podcast_Audio::CHANGED_OPTION, 0 );

		foreach ( $episodes as $episode ) {
			$built = max( $built, (int) get_post_time( 'U', true, $episode ) );
		}

		$copyright = trim( (string) $settings['podcast_copyright'] );
		/* translators: 1: year, 2: site name. */
		$copyright = '' !== $copyright ? $copyright : sprintf( __( '© %1$s %2$s', 'blog-to-audio-podcast' ), gmdate( 'Y' ), wp_strip_all_tags( get_bloginfo( 'name' ) ) );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
	<title><?php echo esc_xml( self::title() ); ?></title>
	<link><?php echo esc_xml( home_url( '/' ) ); ?></link>
	<atom:link href="<?php echo esc_xml( self::url() ); ?>" rel="self" type="application/rss+xml" />
	<description><?php echo esc_xml( self::description() ); ?></description>
	<language><?php echo esc_xml( self::language() ); ?></language>
	<copyright><?php echo esc_xml( $copyright ); ?></copyright>
	<lastBuildDate><?php echo esc_xml( gmdate( 'D, d M Y H:i:s', $built ? $built : time() ) . ' +0000' ); ?></lastBuildDate>
	<generator>Blog Post to Audio Podcast</generator>
	<itunes:author><?php echo esc_xml( self::author() ); ?></itunes:author>
	<?php if ( $email ) : ?>
	<itunes:owner>
		<itunes:name><?php echo esc_xml( '' !== $owner ? $owner : self::author() ); ?></itunes:name>
		<itunes:email><?php echo esc_xml( $email ); ?></itunes:email>
	</itunes:owner>
	<?php endif; ?>
	<?php if ( $image ) : ?>
	<itunes:image href="<?php echo esc_xml( $image['url'] ); ?>" />
	<image>
		<url><?php echo esc_xml( $image['url'] ); ?></url>
		<title><?php echo esc_xml( self::title() ); ?></title>
		<link><?php echo esc_xml( home_url( '/' ) ); ?></link>
	</image>
	<?php endif; ?>
	<?php if ( $category ) : ?>
	<itunes:category text="<?php echo esc_xml( $category[0] ); ?>"><?php if ( '' !== $category[1] ) : ?><itunes:category text="<?php echo esc_xml( $category[1] ); ?>" /><?php endif; ?></itunes:category>
	<?php endif; ?>
	<itunes:explicit><?php echo esc_xml( $explicit ); ?></itunes:explicit>
	<itunes:type>episodic</itunes:type>
	<podcast:guid><?php echo esc_xml( self::guid() ); ?></podcast:guid>
	<podcast:medium>podcast</podcast:medium>
		<?php
		foreach ( $episodes as $episode ) {
			$audio = Blog_To_Audio_Podcast_Audio::get( $episode );

			if ( ! $audio ) {
				continue;
			}

			$title = wp_strip_all_tags( get_the_title( $episode ) );

			if ( $credit ) {
				$title .= ' (elevenlabs.io)';
			}

			$description = self::episode_description( $episode );
			?>
	<item>
		<title><?php echo esc_xml( $title ); ?></title>
		<link><?php echo esc_xml( get_permalink( $episode ) ); ?></link>
		<guid isPermaLink="false"><?php echo esc_xml( get_the_guid( $episode ) . '#audio' ); ?></guid>
		<pubDate><?php echo esc_xml( get_post_time( 'D, d M Y H:i:s', true, $episode ) . ' +0000' ); ?></pubDate>
		<description><?php echo esc_xml( $description ); ?></description>
		<content:encoded><?php echo esc_xml( $description ); ?></content:encoded>
		<enclosure url="<?php echo esc_xml( $audio['url'] ); ?>" length="<?php echo (int) $audio['bytes']; ?>" type="audio/mpeg" />
		<itunes:duration><?php echo (int) round( $audio['duration'] ); ?></itunes:duration>
		<itunes:explicit><?php echo esc_xml( $explicit ); ?></itunes:explicit>
		<itunes:episodeType>full</itunes:episodeType>
		<podcast:transcript url="<?php echo esc_xml( get_permalink( $episode ) ); ?>" type="text/html" />
	</item>
			<?php
		}
		?>
</channel>
</rss>
		<?php
	}
}
