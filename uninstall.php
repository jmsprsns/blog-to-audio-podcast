<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * The audio files themselves stay in the Media Library: they cost credits to
 * make, and are easy to delete there if they're no longer wanted.
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function blog_to_audio_podcast_uninstall_site() {
	global $wpdb;

	$options = array(
		'blog_to_audio_podcast_settings',
		'blog_to_audio_podcast_api_key',
		'blog_to_audio_podcast_rates',
		'blog_to_audio_podcast_paused',
		'blog_to_audio_podcast_lock',
		'blog_to_audio_podcast_batch',
		'blog_to_audio_podcast_tier',
		'blog_to_audio_podcast_guid',
		'blog_to_audio_podcast_rewrite',
		'blog_to_audio_podcast_feed_changed',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	foreach ( array( 'blog_to_audio_podcast_voices', 'blog_to_audio_podcast_models', 'blog_to_audio_podcast_account', 'blog_to_audio_podcast_feed' ) as $transient ) {
		delete_transient( $transient );
	}

	// Post meta: audio records, queue state, jobs and cached counts.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_btap\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	wp_clear_scheduled_hook( 'blog_to_audio_podcast_run' );
	wp_clear_scheduled_hook( 'blog_to_audio_podcast_watchdog' );

	// Action Scheduler jobs (its tables belong to every plugin that uses it).
	$table = $wpdb->prefix . 'actionscheduler_actions';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hook = %s AND status = 'pending'", 'blog_to_audio_podcast_run' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Work-in-progress files.
	$uploads = wp_upload_dir( null, false );
	$parts   = trailingslashit( $uploads['basedir'] ) . 'blog-to-audio-podcast/parts';

	if ( is_dir( $parts ) ) {
		foreach ( (array) glob( $parts . '/*' ) as $file ) {
			if ( $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		@rmdir( $parts ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $blog_id ) {
		switch_to_blog( $blog_id );
		blog_to_audio_podcast_uninstall_site();
		restore_current_blog();
	}
} else {
	blog_to_audio_podcast_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-blog-to-audio-podcast' );
