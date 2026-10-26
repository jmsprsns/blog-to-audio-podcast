<?php
/**
 * The audio queue: which posts are waiting, and the background jobs that work through them.
 *
 * The queue itself is post meta (status, position, retry time), so it's one
 * source of truth however a job is started. Each background job creates the
 * audio for one post, then schedules the next job. Jobs run on Action
 * Scheduler when it's available (bundled for WordPress 6.5+, or from
 * WooCommerce and others), else on WP-Cron. Nothing ever works through the
 * whole queue in one long request, so host time limits don't matter.
 *
 * A post's chunks run back to back so they can be stitched together. If a job
 * runs short of time partway through a long post, the next job carries on
 * where it stopped.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue and scheduling.
 */
final class Blog_To_Audio_Podcast_Queue {

	/**
	 * Hook each job runs on.
	 */
	const HOOK = 'blog_to_audio_podcast_run';

	/**
	 * Hourly check that restarts the queue if a job died.
	 */
	const WATCHDOG = 'blog_to_audio_podcast_watchdog';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'blog-to-audio-podcast';

	/**
	 * Post meta: queued, processing or error.
	 */
	const META_STATUS = '_btap_status';

	/**
	 * Post meta: position in the queue (lower goes first).
	 */
	const META_ORDER = '_btap_queued';

	/**
	 * Post meta: don't try again before this time.
	 */
	const META_RETRY = '_btap_retry_at';

	/**
	 * Post meta: what went wrong.
	 */
	const META_ERROR = '_btap_error';

	/**
	 * Option set while the queue is paused: code, message, time.
	 */
	const PAUSED = 'blog_to_audio_podcast_paused';

	/**
	 * Seconds a background job keeps starting new chunks.
	 */
	const JOB_BUDGET = 45;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ) );
		add_action( self::WATCHDOG, array( __CLASS__, 'watchdog' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_watchdog' ) );
	}

	/**
	 * Whether jobs run on Action Scheduler.
	 *
	 * @return bool
	 */
	public static function uses_action_scheduler() {
		$available = function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' )
			&& class_exists( 'ActionScheduler' ) && ActionScheduler::is_initialized();

		/**
		 * Filters whether audio jobs run on Action Scheduler (true) or WP-Cron (false).
		 *
		 * @param bool $available Whether Action Scheduler is loaded.
		 */
		return $available && (bool) apply_filters( 'blog_to_audio_podcast_use_action_scheduler', true );
	}

	// Adding and removing -----------------------------------------------------

	/**
	 * Put a post in the queue.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $front   Jump the queue (someone is waiting for this one).
	 * @return void
	 */
	public static function add( $post_id, $front = false ) {
		$status = get_post_meta( $post_id, self::META_STATUS, true );

		if ( 'queued' !== $status && 'processing' !== $status ) {
			update_post_meta( $post_id, self::META_STATUS, 'queued' );
			update_post_meta( $post_id, self::META_ORDER, time() );
		}

		if ( $front ) {
			// Ahead of everything queued in the last ten years.
			update_post_meta( $post_id, self::META_ORDER, time() - 10 * YEAR_IN_SECONDS );
		}

		delete_post_meta( $post_id, self::META_RETRY );
		delete_post_meta( $post_id, self::META_ERROR );
	}

	/**
	 * Put several posts in the queue, oldest position first so they're made
	 * in the order given.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int How many were added.
	 */
	public static function add_many( array $post_ids ) {
		$added = 0;
		$order = time();

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$status  = get_post_meta( $post_id, self::META_STATUS, true );

			if ( ! $post_id || 'queued' === $status || 'processing' === $status ) {
				continue;
			}

			update_post_meta( $post_id, self::META_STATUS, 'queued' );
			update_post_meta( $post_id, self::META_ORDER, $order + $added );
			delete_post_meta( $post_id, self::META_RETRY );
			delete_post_meta( $post_id, self::META_ERROR );
			++$added;
		}

		return $added;
	}

	/**
	 * Back in the queue at the same position (to carry on, or after a pause).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function requeue( $post_id ) {
		update_post_meta( $post_id, self::META_STATUS, 'queued' );

		if ( ! get_post_meta( $post_id, self::META_ORDER, true ) ) {
			update_post_meta( $post_id, self::META_ORDER, time() );
		}
	}

	/**
	 * Try a post again after a wait.
	 *
	 * @param int $post_id Post ID.
	 * @param int $delay   Seconds.
	 * @return void
	 */
	public static function retry_later( $post_id, $delay ) {
		self::requeue( $post_id );
		update_post_meta( $post_id, self::META_RETRY, time() + max( 1, (int) $delay ) );
	}

	/**
	 * Mark a post as being worked on.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function processing( $post_id ) {
		update_post_meta( $post_id, self::META_STATUS, 'processing' );
	}

	/**
	 * Take a post out of the queue with an error.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function error( $post_id, $message ) {
		update_post_meta( $post_id, self::META_STATUS, 'error' );
		update_post_meta( $post_id, self::META_ERROR, sanitize_text_field( $message ) );
		delete_post_meta( $post_id, self::META_RETRY );
		delete_post_meta( $post_id, self::META_ORDER );
	}

	/**
	 * Take a post out of the queue (finished, cancelled or deleted), along
	 * with any work in progress.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function remove( $post_id ) {
		$job = get_post_meta( $post_id, Blog_To_Audio_Podcast_Generator::META_JOB, true );

		if ( is_array( $job ) && ! empty( $job['part'] ) ) {
			Blog_To_Audio_Podcast_Audio::delete_part( $job['part'] );
		}

		foreach ( array( self::META_STATUS, self::META_ORDER, self::META_RETRY, self::META_ERROR, Blog_To_Audio_Podcast_Generator::META_JOB ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * A post's place in the queue.
	 *
	 * @param int $post_id Post ID.
	 * @return array{status:string,error:string,retry_at:int,chunk:int,chunks:int}
	 */
	public static function status( $post_id ) {
		$job = get_post_meta( $post_id, Blog_To_Audio_Podcast_Generator::META_JOB, true );

		return array(
			'status'   => (string) get_post_meta( $post_id, self::META_STATUS, true ),
			'error'    => (string) get_post_meta( $post_id, self::META_ERROR, true ),
			'retry_at' => (int) get_post_meta( $post_id, self::META_RETRY, true ),
			'chunk'    => is_array( $job ) && isset( $job['index'] ) ? (int) $job['index'] : 0,
			'chunks'   => is_array( $job ) && isset( $job['chunks'] ) ? count( (array) $job['chunks'] ) : 0,
		);
	}

	/**
	 * Cancel everything waiting. A chunk that's being made right now finishes,
	 * then its job stops.
	 *
	 * @return int How many posts were taken out.
	 */
	public static function cancel_all() {
		$ids = array_merge( self::ids( 'queued' ), self::ids( 'processing' ) );

		foreach ( $ids as $post_id ) {
			self::remove( $post_id );
		}

		self::unschedule_all();
		self::schedule_watchdog();

		return count( $ids );
	}

	/**
	 * Post IDs with a status, in queue order.
	 *
	 * @param string $status queued, processing or error.
	 * @param int    $limit  Most to return (0 = all).
	 * @return int[]
	 */
	public static function ids( $status, $limit = 0 ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT s.post_id FROM {$wpdb->postmeta} s
			LEFT JOIN {$wpdb->postmeta} o ON o.post_id = s.post_id AND o.meta_key = %s
			WHERE s.meta_key = %s AND s.meta_value = %s
			ORDER BY CAST(o.meta_value AS SIGNED) ASC, s.post_id ASC",
			self::META_ORDER,
			self::META_STATUS,
			$status
		);

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}

	/**
	 * How many posts are ahead of this one in the queue.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function position( $post_id ) {
		$ids   = array_merge( self::ids( 'processing' ), self::ids( 'queued' ) );
		$index = array_search( (int) $post_id, $ids, true );

		return false === $index ? 0 : (int) $index;
	}

	/**
	 * How many posts are in each state.
	 *
	 * @return array{queued:int,processing:int,error:int,waiting:int}
	 */
	public static function counts() {
		global $wpdb;

		$counts = array(
			'queued'     => 0,
			'processing' => 0,
			'error'      => 0,
			'waiting'    => 0,
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META_STATUS )
		);

		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}

		// Queued but waiting to retry after a temporary error.
		$counts['waiting'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d", self::META_RETRY, time() )
		);

		return $counts;
	}

	/**
	 * The next post to work on: one left half-done first (its job died), then
	 * the queue in order, skipping posts waiting to retry.
	 *
	 * @return int Post ID, or 0 when there's nothing to do right now.
	 */
	public static function next() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT s.post_id FROM {$wpdb->postmeta} s
				INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
				LEFT JOIN {$wpdb->postmeta} o ON o.post_id = s.post_id AND o.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = s.post_id AND r.meta_key = %s
				WHERE s.meta_key = %s AND s.meta_value IN ('queued', 'processing')
				AND ( r.meta_id IS NULL OR CAST(r.meta_value AS UNSIGNED) <= %d )
				ORDER BY ( s.meta_value = 'processing' ) DESC, CAST(o.meta_value AS SIGNED) ASC, s.post_id ASC
				LIMIT 1",
				self::META_ORDER,
				self::META_RETRY,
				self::META_STATUS,
				time()
			)
		);
	}

	/**
	 * When the soonest retry is due, if every queued post is waiting.
	 *
	 * @return int Unix time, or 0.
	 */
	private static function next_retry() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MIN(CAST(r.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} r
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = r.post_id AND s.meta_key = %s AND s.meta_value = 'queued'
				WHERE r.meta_key = %s",
				self::META_STATUS,
				self::META_RETRY
			)
		);
	}

	// Pausing -------------------------------------------------------------------

	/**
	 * Stop the queue: the account or the settings need attention.
	 *
	 * @param string $code    Error code.
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function pause( $code, $message ) {
		update_option(
			self::PAUSED,
			array(
				'code'    => sanitize_key( $code ),
				'message' => sanitize_text_field( $message ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Why the queue is paused.
	 *
	 * @return array|false code, message, time; false when it isn't.
	 */
	public static function paused() {
		$paused = get_option( self::PAUSED, false );

		return is_array( $paused ) ? $paused : false;
	}

	/**
	 * Start the queue again.
	 *
	 * @return void
	 */
	public static function resume() {
		delete_option( self::PAUSED );
		self::kick();
	}

	// Scheduling ----------------------------------------------------------------

	/**
	 * Make sure a job is on its way if there's work to do.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function kick( $delay = 0 ) {
		if ( self::paused() ) {
			return;
		}

		$counts = self::counts();

		if ( ! $counts['queued'] && ! $counts['processing'] ) {
			return;
		}

		// Everything left is waiting to retry: wake up when the first is due.
		if ( ! self::next() ) {
			$due   = self::next_retry();
			$delay = max( $delay, $due ? $due - time() : 60 );
		}

		$when = time() + max( 0, (int) $delay );

		if ( self::uses_action_scheduler() ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);

			if ( ! $pending ) {
				as_schedule_single_action( $when, self::HOOK, array(), self::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $when, self::HOOK );
		}
	}

	/**
	 * A background job: make one post's audio, then line up the next job.
	 *
	 * @return void
	 */
	public static function run_job() {
		$result = Blog_To_Audio_Podcast_Generator::run( self::JOB_BUDGET );

		// Another job holds the lock: it will line up the next one itself,
		// but check back in case it dies.
		self::kick( 'busy' === $result['result'] ? 60 : 0 );
	}

	/**
	 * Hourly: restart the queue if a job died without lining up the next.
	 *
	 * @return void
	 */
	public static function watchdog() {
		self::kick();
		self::clean_parts();
	}

	/**
	 * Delete work-in-progress files no job is using (left by a cancelled or
	 * crashed job), once they're a day old.
	 *
	 * @return void
	 */
	private static function clean_parts() {
		global $wpdb;

		$dir = Blog_To_Audio_Podcast_Audio::dir( 'parts' );

		if ( is_wp_error( $dir ) ) {
			return;
		}

		// Parts a job still needs (a paused queue can sit for days).
		$in_use = array();
		$jobs   = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Blog_To_Audio_Podcast_Generator::META_JOB ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $jobs as $job ) {
			$job = maybe_unserialize( $job );

			if ( is_array( $job ) && ! empty( $job['part'] ) ) {
				$in_use[ (string) $job['part'] ] = true;
			}
		}

		foreach ( (array) glob( $dir['path'] . '/*.part' ) as $file ) {
			if ( $file && ! isset( $in_use[ basename( $file ) ] ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Schedule the hourly watchdog.
	 *
	 * @return void
	 */
	public static function schedule_watchdog() {
		if ( ! wp_next_scheduled( self::WATCHDOG ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCHDOG );
		}
	}

	/**
	 * Remove every scheduled job and the watchdog.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::WATCHDOG );

		if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( 'ActionScheduler' ) && ActionScheduler::is_initialized() ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}
}
