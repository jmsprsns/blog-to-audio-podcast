<?php
/**
 * Turns a post into clean text for reading aloud, and splits it into chunks.
 *
 * What's left out: block markup and shortcodes, tables, code, image and
 * video captions, embeds, buttons, tables of contents, related-post lists,
 * affiliate disclosures, footnote markers, struck-out text, and anything in
 * an "Exclude from audio" block, a [no_audio] shortcode or an element with
 * the no-audio class. Headings and list items end with a full stop so the
 * voice pauses after them, and bare links are read as their domain name.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text extraction.
 */
final class Blog_To_Audio_Podcast_Text {

	/**
	 * Bump when the cleanup changes, so cached character counts are redone.
	 */
	const VERSION = 1;

	/**
	 * Name of the "Exclude from audio" block.
	 */
	const EXCLUDE_BLOCK = 'blog-to-audio-podcast/exclude';

	/**
	 * Marker meaning "end this line with a full stop if it has no punctuation".
	 */
	const STOP = "\x01";

	/**
	 * Blocks whose content is never read.
	 */
	const SKIP_BLOCKS = array(
		'core/archives',
		'core/audio',
		'core/avatar',
		'core/button',
		'core/buttons',
		'core/calendar',
		'core/categories',
		'core/code',
		'core/embed',
		'core/file',
		'core/footnotes',
		'core/gallery',
		'core/html',
		'core/image',
		'core/latest-comments',
		'core/latest-posts',
		'core/loginout',
		'core/math',
		'core/more',
		'core/navigation',
		'core/nextpage',
		'core/page-list',
		'core/preformatted',
		'core/pullquote',
		'core/rss',
		'core/search',
		'core/separator',
		'core/shortcode',
		'core/site-logo',
		'core/social-link',
		'core/social-links',
		'core/spacer',
		'core/table',
		'core/table-of-contents',
		'core/tag-cloud',
		'core/video',
		'core/verse',
		self::EXCLUDE_BLOCK,
	);

	/**
	 * Block name prefixes that are never read: query loops, post and comment
	 * template parts, old-style embeds and store widgets.
	 */
	const SKIP_PREFIXES = array( 'core/query', 'core/post-', 'core/comment', 'core-embed/', 'woocommerce/', 'jetpack/' );

	/**
	 * Third-party blocks whose names show they aren't article text.
	 */
	const SKIP_PATTERN = '/(toc|table-?of-?contents|related|breadcrumb|share|social|newsletter|subscribe|form|button|cta|call-to-action|advert|adsense|embed|video|gallery|slider|carousel|map|code|syntax|chart|faq-schema|review-box|author-box)/';

	/**
	 * Class or id fragments marking HTML that isn't article text.
	 */
	const SKIP_CLASS_PATTERN = '/(^|[\s_-])(no-audio|btap-skip|toc|ez-toc[\w-]*|lwptoc[\w-]*|table-of-contents|rank-math-toc|wp-block-yoast-seo-table-of-contents|related[\w-]*|jp-relatedposts|yarpp[\w-]*|sharedaddy|share[\w-]*|social[\w-]*|wp-block-buttons|screen-reader-text|sr-only|visually-hidden|affiliate-disclosure|disclosure|wp-caption-text)($|[\s_-])/i';

	/**
	 * Cleaned text for a post: the intro, the body and the outro.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $settings Plugin settings.
	 * @return array{text:string,body:string,hash:string,chars:int}
	 */
	public static function for_post( WP_Post $post, array $settings ) {
		$body  = self::body( $post );
		$title = self::clean_inline( get_the_title( $post ) );

		$parts = array(
			self::fill_tokens( (string) $settings['intro'], $post, $title ),
			$body,
			self::fill_tokens( (string) $settings['outro'], $post, $title ),
		);

		$text = implode( "\n\n", array_filter( array_map( 'trim', $parts ), 'strlen' ) );
		$text = self::apply_pronunciations( $text, (string) $settings['pronunciations'] );

		/**
		 * Filters the full text sent to ElevenLabs for a post, after the intro
		 * and outro are added.
		 *
		 * @param string  $text Text to read. Paragraphs are separated by blank lines.
		 * @param WP_Post $post Post.
		 */
		$text = trim( (string) apply_filters( 'blog_to_audio_podcast_text', $text, $post ) );

		return array(
			'text'  => $text,
			'body'  => $body,
			'hash'  => self::hash( $post, $body ),
			'chars' => self::length( $text ),
		);
	}

	/**
	 * Fingerprint of what the audio says: the title and the article text.
	 * Settings like the intro aren't part of it, so changing them doesn't
	 * mark every post's audio as out of date.
	 *
	 * @param WP_Post     $post Post.
	 * @param string|null $body Cleaned body, if already worked out.
	 * @return string
	 */
	public static function hash( WP_Post $post, $body = null ) {
		$body = null === $body ? self::body( $post ) : $body;

		return md5( get_the_title( $post ) . "\n" . $body );
	}

	/**
	 * The article text, cleaned up for reading.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function body( WP_Post $post ) {
		$content = (string) $post->post_content;

		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			$html = self::blocks_to_html( parse_blocks( $content ), 0 );
		} else {
			$html = wpautop( $content );
		}

		$html = self::strip_shortcodes( $html );
		$text = self::html_to_text( $html );

		/**
		 * Filters a post's cleaned article text (without the intro and outro).
		 *
		 * @param string  $text Text. Paragraphs are separated by blank lines.
		 * @param WP_Post $post Post.
		 */
		return trim( (string) apply_filters( 'blog_to_audio_podcast_body_text', $text, $post ) );
	}

	/**
	 * Rebuild a post's HTML from its blocks, leaving out blocks that aren't
	 * article text. Uses each block's saved HTML rather than rendering it,
	 * so dynamic blocks (latest posts, forms, widgets) add nothing.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $depth  Nesting depth, to stop runaway reusable blocks.
	 * @return string
	 */
	private static function blocks_to_html( array $blocks, $depth ) {
		$html = '';

		if ( $depth > 10 ) {
			return $html;
		}

		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( '' === $name ) {
				$html .= wpautop( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '' );
				continue;
			}

			if ( self::skip_block( $name, isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array() ) ) {
				continue;
			}

			// Synced patterns (reusable blocks) live in their own post.
			if ( 'core/block' === $name ) {
				$ref = isset( $block['attrs']['ref'] ) ? get_post( (int) $block['attrs']['ref'] ) : null;

				if ( $ref instanceof WP_Post && 'wp_block' === $ref->post_type && 'publish' === $ref->post_status ) {
					$html .= self::blocks_to_html( parse_blocks( $ref->post_content ), $depth + 1 );
				}
				continue;
			}

			if ( 'core/freeform' === $name ) {
				$html .= wpautop( (string) $block['innerHTML'] );
				continue;
			}

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$index = 0;

			foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $piece ) {
				if ( is_string( $piece ) ) {
					$html .= $piece;
				} elseif ( isset( $inner[ $index ] ) ) {
					$html .= self::blocks_to_html( array( $inner[ $index ] ), $depth + 1 );
					++$index;
				}
			}

			$html .= "\n\n";
		}

		return $html;
	}

	/**
	 * Whether a block is left out of the audio.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Block attributes.
	 * @return bool
	 */
	private static function skip_block( $name, array $attrs ) {
		$skip = in_array( $name, self::SKIP_BLOCKS, true );

		foreach ( self::SKIP_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$skip = true;
			}
		}

		if ( ! $skip && 0 !== strpos( $name, 'core/' ) ) {
			$skip = (bool) preg_match( self::SKIP_PATTERN, substr( $name, strpos( $name, '/' ) + 1 ) );
		}

		if ( ! $skip && ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			$skip = (bool) preg_match( '/(^|\s)(no-audio|btap-skip)(\s|$)/', $attrs['className'] );
		}

		/**
		 * Filters whether a block is left out of the audio.
		 *
		 * @param bool   $skip  Whether to skip it.
		 * @param string $name  Block name, e.g. "core/table".
		 * @param array  $attrs Block attributes.
		 */
		return (bool) apply_filters( 'blog_to_audio_podcast_skip_block', $skip, $name, $attrs );
	}

	/**
	 * Remove shortcodes. [no_audio] goes with everything inside it; other
	 * registered shortcodes (and their content, like captions) are removed.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function strip_shortcodes( $html ) {
		$html = preg_replace( '#\[no_audio\b[^\]]*\].*?\[/no_audio\]#is', '', $html );
		$html = preg_replace( '#\[/?no_audio\b[^\]]*\]#i', '', (string) $html );
		$html = strip_shortcodes( (string) $html );

		// Leftovers from plugins that are no longer active: [name], [/name],
		// [name attr="..."]. Bracketed words like [citation needed] stay.
		return (string) preg_replace( '#\[/?[a-z][a-z0-9_-]*(?:\s+[a-z0-9_-]+=[^\]]*)?\s*/?\]#i', '', $html );
	}

	/**
	 * HTML to speakable plain text.
	 *
	 * @param string $html HTML.
	 * @return string Paragraphs separated by blank lines.
	 */
	public static function html_to_text( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return '';
		}

		$text = class_exists( 'DOMDocument' ) ? self::dom_to_text( $html ) : null;

		if ( null === $text ) {
			$text = self::regex_to_text( $html );
		}

		return self::tidy( $text );
	}

	/**
	 * Walk the HTML with DOMDocument.
	 *
	 * @param string $html HTML.
	 * @return string|null Null if the HTML couldn't be parsed.
	 */
	private static function dom_to_text( $html ) {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML( '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );

		return $body ? self::node_text( $body ) : '';
	}

	/**
	 * Text of a node and its children.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function node_text( DOMNode $node ) {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$out .= $child->nodeValue;
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( self::skip_element( $child, $tag ) ) {
				// Keep the gap a skipped block leaves, so words don't run together.
				$out .= ' ';
				continue;
			}

			if ( 'br' === $tag ) {
				$out .= "\n";
				continue;
			}

			$inner = self::node_text( $child );

			if ( 'a' === $tag ) {
				$out .= self::link_text( $inner );
			} elseif ( preg_match( '/^(h[1-6]|li|dt|summary|caption|th)$/', $tag ) ) {
				$out .= "\n\n" . $inner . self::STOP . "\n\n";
			} elseif ( preg_match( '/^(p|div|section|article|blockquote|dd|dl|ul|ol|header|footer|main|aside|details|figure|address|hr|center)$/', $tag ) ) {
				$out .= "\n\n" . $inner . "\n\n";
			} else {
				$out .= $inner;
			}
		}

		return $out;
	}

	/**
	 * Whether an element is left out.
	 *
	 * @param DOMElement $el  Element.
	 * @param string     $tag Lowercase tag name.
	 * @return bool
	 */
	private static function skip_element( DOMElement $el, $tag ) {
		static $skip_tags = array(
			'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'video', 'audio', 'svg', 'canvas',
			'math', 'form', 'button', 'select', 'textarea', 'input', 'label', 'table', 'pre', 'code', 'kbd', 'samp',
			'figure', 'figcaption', 'img', 'picture', 'nav', 'del', 's', 'strike', 'sup', 'head', 'title', 'meta', 'link',
		);

		if ( in_array( $tag, $skip_tags, true ) ) {
			// Inline code in a sentence is read; code blocks aren't.
			if ( 'code' === $tag && 'pre' !== strtolower( $el->parentNode->nodeName ) ) {
				return false;
			}

			// Superscripts are skipped only when they're footnote markers.
			if ( 'sup' === $tag ) {
				return self::is_footnote_marker( $el );
			}

			return true;
		}

		if ( $el->hasAttribute( 'hidden' ) || 'true' === $el->getAttribute( 'aria-hidden' ) ) {
			return true;
		}

		$marks = trim( $el->getAttribute( 'class' ) . ' ' . $el->getAttribute( 'id' ) );

		return '' !== $marks && (bool) preg_match( self::SKIP_CLASS_PATTERN, $marks );
	}

	/**
	 * Whether a <sup> is a footnote marker: a number, or a link into the page.
	 *
	 * @param DOMElement $el The sup element.
	 * @return bool
	 */
	private static function is_footnote_marker( DOMElement $el ) {
		if ( preg_match( '/^\s*\[?\d{1,3}\]?\s*$/', $el->textContent ) ) {
			return true;
		}

		foreach ( $el->getElementsByTagName( 'a' ) as $link ) {
			if ( 0 === strpos( $link->getAttribute( 'href' ), '#' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A link's text, or its domain when the text is the URL itself.
	 *
	 * @param string $text Link text.
	 * @return string
	 */
	private static function link_text( $text ) {
		$trimmed = trim( $text );

		if ( preg_match( '#^(https?://|www\.)\S+$#i', $trimmed ) ) {
			return ' ' . self::domain( $trimmed ) . ' ';
		}

		return $text;
	}

	/**
	 * Fallback for servers without the DOM extension.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function regex_to_text( $html ) {
		$html = preg_replace( '#<(script|style|noscript|template|iframe|object|video|audio|svg|canvas|math|form|button|select|textarea|table|pre|figure|figcaption|nav|del|s|strike)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<sup\b[^>]*>\s*(<a\b[^>]*href="\#[^"]*"[^>]*>.*?</a>|\[?\d{1,3}\]?)\s*</sup>#is', '', (string) $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</(h[1-6]|li|dt|summary|th)>#i', self::STOP . "\n\n", (string) $html );
		$html = preg_replace( '#</?(p|div|section|article|blockquote|dd|dl|ul|ol|h[1-6]|li|dt|header|footer|main|aside|details|summary|address|hr)\b[^>]*>#i', "\n\n", (string) $html );
		$html = preg_replace_callback(
			'#<a\b[^>]*>(.*?)</a>#is',
			function ( $m ) {
				return self::link_text( wp_strip_all_tags( $m[1] ) );
			},
			(string) $html
		);

		return html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Final tidy-up: punctuation after headings, links, abbreviations,
	 * emoji, disclosures and whitespace.
	 *
	 * @param string $text Raw text with STOP markers.
	 * @return string
	 */
	private static function tidy( $text ) {
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );

		// Bare URLs are read as their domain name.
		$text = preg_replace_callback(
			'#\b(?:https?://|www\.)[^\s<>"\')\]]+#i',
			function ( $m ) {
				return self::domain( $m[0] );
			},
			$text
		);

		// Emoji and pictographs, which voices skip or stumble over.
		$text = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', '', (string) $text );

		$paragraphs = array();

		foreach ( preg_split( "/\n\s*\n/", (string) $text ) as $paragraph ) {
			$stop      = false !== strpos( $paragraph, self::STOP );
			$paragraph = trim( preg_replace( '/[ \t\n]+/', ' ', str_replace( self::STOP, ' ', $paragraph ) ) );
			$paragraph = (string) preg_replace( '/\s+([.,;:!?…])/u', '$1', $paragraph );

			if ( '' === $paragraph || self::is_boilerplate( $paragraph ) ) {
				continue;
			}

			// Headings and list items need a full stop so the voice pauses.
			if ( $stop && ! preg_match( '/[.!?:;…"”\')]$/u', $paragraph ) ) {
				$paragraph .= '.';
			}

			$paragraphs[] = self::expand_abbreviations( $paragraph );
		}

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Whether a paragraph is boilerplate nobody wants read out: an affiliate
	 * disclosure, a "Related:" or "Read more:" link, a table of contents title.
	 *
	 * @param string $paragraph One paragraph.
	 * @return bool
	 */
	private static function is_boilerplate( $paragraph ) {
		$patterns = array(
			'/^\W*(affiliate\s+)?disclos(ure|ures)\b/i',
			'/^\W*(this|the|our)\s+(post|article|page|content|site|website|blog)\s+(may\s+)?(contains?|includes?|has)\s+(affiliate|sponsored|partner)/i',
			'/^\W*as\s+an\s+amazon\s+associate/i',
			'/^\W*we\s+(may\s+)?(earn|receive|get)\s+(a\s+)?(small\s+)?(commission|compensation|fee)/i',
			'/^\W*(related|read\s+more|see\s+also|also\s+read|recommended(\s+reading)?|further\s+reading|you\s+might\s+also\s+like|related\s+posts?|related\s+articles?)\s*[:\-–—]/i',
			'/^\W*(table\s+of\s+contents|contents|in\s+this\s+(article|post))\W*$/i',
		);

		/**
		 * Filters the patterns for paragraphs that are never read: affiliate
		 * disclosures, "Related:" links and the like. Each is tested against
		 * one whole paragraph; short paragraphs only.
		 *
		 * @param string[] $patterns Regular expressions.
		 */
		$patterns = (array) apply_filters( 'blog_to_audio_podcast_skip_patterns', $patterns );

		if ( self::length( $paragraph ) > 400 ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && @preg_match( $pattern, $paragraph ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filtered pattern may be invalid.
				return true;
			}
		}

		return false;
	}

	/**
	 * Spell out abbreviations voices often read letter by letter.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function expand_abbreviations( $text ) {
		$map = array(
			'/\be\.g\.,?(?=\s)/i'     => __( 'for example,', 'blog-to-audio-podcast' ),
			'/\bi\.e\.,?(?=\s)/i'     => __( 'that is,', 'blog-to-audio-podcast' ),
			'/\bvs\.(?=\s)/i'         => __( 'versus', 'blog-to-audio-podcast' ),
			'/\s&\s/'                 => ' ' . __( 'and', 'blog-to-audio-podcast' ) . ' ',
			'/(?<=\s)w\/(?=\s)/i'     => __( 'with', 'blog-to-audio-podcast' ),
			'/\s[-–—]{1,2}>\s/u'      => ', ',
			'/\s*(?:\.\s*){3,}/'      => '… ',
		);

		/**
		 * Filters the abbreviations that are spelled out before reading.
		 *
		 * @param array<string,string> $map Regular expression => replacement.
		 */
		$map = (array) apply_filters( 'blog_to_audio_podcast_abbreviations', $map );

		foreach ( $map as $pattern => $replacement ) {
			$result = @preg_replace( $pattern, (string) $replacement, $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filtered pattern may be invalid.
			$text   = null === $result ? $text : $result;
		}

		return trim( $text );
	}

	/**
	 * A URL's domain, without "www.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function domain( $url ) {
		$url  = preg_match( '#^https?://#i', $url ) ? $url : 'http://' . $url;
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return preg_replace( '/^www\./i', '', $host );
	}

	/**
	 * Clean a short piece of text: a title, an author name.
	 *
	 * @param string $text Text that may hold HTML or entities.
	 * @return string
	 */
	private static function clean_inline( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Fill {title}, {site}, {author} and {date} in the intro or outro.
	 *
	 * @param string  $template Template.
	 * @param WP_Post $post     Post.
	 * @param string  $title    Clean title.
	 * @return string
	 */
	public static function fill_tokens( $template, WP_Post $post, $title ) {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$author = get_userdata( (int) $post->post_author );

		return strtr(
			$template,
			array(
				'{title}'  => $title,
				'{site}'   => self::clean_inline( get_bloginfo( 'name' ) ),
				'{author}' => $author ? self::clean_inline( $author->display_name ) : '',
				'{date}'   => get_the_date( '', $post ),
			)
		);
	}

	/**
	 * Apply the pronunciation list: one "word = how to say it" per line.
	 *
	 * @param string $text  Text.
	 * @param string $rules Rules from the settings.
	 * @return string
	 */
	public static function apply_pronunciations( $text, $rules ) {
		foreach ( self::parse_pronunciations( $rules ) as $word => $say ) {
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/iu';
			$result  = preg_replace( $pattern, $say, $text );
			$text    = null === $result ? $text : $result;
		}

		return $text;
	}

	/**
	 * Parse the pronunciation list.
	 *
	 * @param string $rules One "word = how to say it" per line.
	 * @return array<string,string>
	 */
	public static function parse_pronunciations( $rules ) {
		$parsed = array();

		foreach ( preg_split( '/\R/', (string) $rules ) as $line ) {
			$pair = explode( '=', $line, 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			$word = trim( $pair[0] );
			$say  = trim( $pair[1] );

			if ( '' !== $word && '' !== $say ) {
				$parsed[ $word ] = $say;
			}
		}

		// Longest first, so "WordPress.com" is handled before "WordPress".
		uksort(
			$parsed,
			function ( $a, $b ) {
				return self::length( $b ) - self::length( $a );
			}
		);

		return $parsed;
	}

	/**
	 * Split text into chunks of at most $max characters, at paragraph
	 * breaks where possible, then sentences, then words.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters per chunk.
	 * @return string[]
	 */
	public static function chunks( $text, $max ) {
		$max    = max( 100, (int) $max );
		$chunks = array();
		$buffer = '';

		foreach ( preg_split( "/\n\s*\n/", trim( (string) $text ) ) as $paragraph ) {
			foreach ( self::split_long( trim( $paragraph ), $max ) as $piece ) {
				if ( '' === $piece ) {
					continue;
				}

				if ( '' === $buffer ) {
					$buffer = $piece;
				} elseif ( self::length( $buffer ) + 2 + self::length( $piece ) <= $max ) {
					$buffer .= "\n\n" . $piece;
				} else {
					$chunks[] = $buffer;
					$buffer   = $piece;
				}
			}
		}

		if ( '' !== $buffer ) {
			$chunks[] = $buffer;
		}

		return $chunks;
	}

	/**
	 * Split one long paragraph into sentence-sized pieces.
	 *
	 * @param string $paragraph Paragraph.
	 * @param int    $max       Characters per piece.
	 * @return string[]
	 */
	private static function split_long( $paragraph, $max ) {
		if ( self::length( $paragraph ) <= $max ) {
			return array( $paragraph );
		}

		$pieces = array();
		$buffer = '';

		foreach ( preg_split( '/(?<=[.!?…])\s+/u', $paragraph ) as $sentence ) {
			$parts = self::length( $sentence ) > $max ? self::split_words( $sentence, $max ) : array( $sentence );

			foreach ( $parts as $part ) {
				if ( '' === $buffer ) {
					$buffer = $part;
				} elseif ( self::length( $buffer ) + 1 + self::length( $part ) <= $max ) {
					$buffer .= ' ' . $part;
				} else {
					$pieces[] = $buffer;
					$buffer   = $part;
				}
			}
		}

		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}

		return $pieces;
	}

	/**
	 * Last resort: split at spaces (or anywhere, for a single huge word).
	 *
	 * @param string $sentence Sentence.
	 * @param int    $max      Characters per piece.
	 * @return string[]
	 */
	private static function split_words( $sentence, $max ) {
		$pieces = array();
		$buffer = '';

		foreach ( preg_split( '/\s+/u', $sentence ) as $word ) {
			while ( self::length( $word ) > $max ) {
				$pieces[] = mb_substr( $word, 0, $max );
				$word     = mb_substr( $word, $max );
			}

			if ( '' === $buffer ) {
				$buffer = $word;
			} elseif ( self::length( $buffer ) + 1 + self::length( $word ) <= $max ) {
				$buffer .= ' ' . $word;
			} else {
				$pieces[] = $buffer;
				$buffer   = $word;
			}
		}

		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}

		return $pieces;
	}

	/**
	 * Length in characters (ElevenLabs counts characters, not bytes).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( $text ) {
		return (int) mb_strlen( (string) $text, 'UTF-8' );
	}
}
