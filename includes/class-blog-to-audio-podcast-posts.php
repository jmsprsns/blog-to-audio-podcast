<?php
/**
 * Finds posts by audio status and counts what they'd cost to read.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post lookups.
 */
final class Blog_To_Audio_Podcast_Posts {

	/**
	 * Post meta: cached character count for estimates (array: sig, chars).
	 */
	const META_CHARS = '_btap_chars';

	/**
	 * Published posts that bulk creation would cover: of a chosen type, not
	 * password protected, not left out, not already queued or failed, and
	 * with no audio (or, with $include_stale, audio that's out of date).
	 *
	 * @param bool $include_stale  Include posts whose audio is out of date.
	 * @param int  $limit          Most to return (0 = all).
	 * @param bool $include_queued Include posts already in the queue (for counts).
	 * @return int[] Post IDs, newest first.
	 */
	public static function outstanding( $include_stale = false, $limit = 0, $include_queued = false ) {
		global $wpdb;

		$types = blog_to_audio_podcast_plugin()->post_types();

		if ( ! $types ) {
			return array();
		}

		$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$audio  = $include_stale ? '( a.meta_id IS NULL OR st.meta_id IS NOT NULL )' : 'a.meta_id IS NULL';
		$queue  = $include_queued ? "( q.meta_id IS NULL OR q.meta_value IN ('queued', 'processing') )" : 'q.meta_id IS NULL';
		$limits = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above; fixed SQL fragments only.
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = %s
			WHERE p.post_type IN ($in) AND p.post_status = 'publish' AND p.post_password = ''
			AND sk.meta_id IS NULL AND $queue AND $audio
			ORDER BY p.post_date DESC, p.ID DESC",
			array_merge(
				array( Blog_To_Audio_Podcast_Audio::META_ID, Blog_To_Audio_Podcast_Audio::META_STALE, Blog_To_Audio_Podcast_Audio::META_SKIP, Blog_To_Audio_Podcast_Queue::META_STATUS ),
				$types
			)
		) . $limits;

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Numbers for the overview. "waiting" counts every published post without
	 * audio (queued or not); "outstanding" only those bulk creation would add.
	 *
	 * @return array{with_audio:int,seconds:float,stale:int,outstanding:int,waiting:int,credits:int}
	 */
	public static function stats() {
		global $wpdb;

		$stats = array(
			'with_audio'  => 0,
			'seconds'     => 0.0,
			'stale'       => 0,
			'outstanding' => count( self::outstanding( false ) ),
			'waiting'     => count( self::outstanding( false, 0, true ) ),
			'credits'     => 0,
		);

		$types = blog_to_audio_podcast_plugin()->post_types();

		if ( ! $types ) {
			return $stats;
		}

		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS audio, st.meta_id AS stale FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
				WHERE pm.meta_key = %s AND p.post_type IN ($in) AND p.post_status = 'publish'",
				array_merge( array( Blog_To_Audio_Podcast_Audio::META_STALE, Blog_To_Audio_Podcast_Audio::META ), $types )
			)
		);
		// phpcs:enable

		foreach ( $rows as $row ) {
			$audio = maybe_unserialize( $row->audio );

			if ( ! is_array( $audio ) ) {
				continue;
			}

			++$stats['with_audio'];
			$stats['seconds'] += isset( $audio['duration'] ) ? (float) $audio['duration'] : 0;
			$stats['credits'] += isset( $audio['credits'] ) ? (int) $audio['credits'] : 0;

			if ( $row->stale ) {
				++$stats['stale'];
			}
		}

		return $stats;
	}

	/**
	 * How many characters a post's audio would use, cached until the post or
	 * the reading settings change.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $settings Plugin settings.
	 * @return int
	 */
	public static function chars( WP_Post $post, array $settings ) {
		$sig    = self::signature( $post, $settings );
		$cached = get_post_meta( $post->ID, self::META_CHARS, true );

		if ( is_array( $cached ) && isset( $cached['sig'], $cached['chars'] ) && $cached['sig'] === $sig ) {
			return (int) $cached['chars'];
		}

		$text  = Blog_To_Audio_Podcast_Text::for_post( $post, $settings );
		$chars = (int) $text['chars'];

		update_post_meta(
			$post->ID,
			self::META_CHARS,
			array(
				'sig'   => $sig,
				'chars' => $chars,
			)
		);

		return $chars;
	}

	/**
	 * What a cached character count depends on.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $settings Plugin settings.
	 * @return string
	 */
	private static function signature( WP_Post $post, array $settings ) {
		return md5(
			Blog_To_Audio_Podcast_Text::VERSION . '|' . $post->post_modified_gmt . '|' . $settings['intro'] . '|' . $settings['outro'] . '|' . $settings['pronunciations']
		);
	}
}
