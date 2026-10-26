<?php
/**
 * Plugin Name:       Blog Post to Audio Podcast
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Turns your blog posts into natural-sounding audio with ElevenLabs, adds an accessible audio player, and publishes a podcast feed for Apple Podcasts and Spotify.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blog-to-audio-podcast
 * Update URI:        https://github.com/jmsprsns/blog-to-audio-podcast
 *
 * @package Blog_To_Audio_Podcast
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$blog_to_audio_podcast_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'BLOG_TO_AUDIO_PODCAST_VERSION', $blog_to_audio_podcast_headers['version'] );
define( 'BLOG_TO_AUDIO_PODCAST_REPO', $blog_to_audio_podcast_headers['repo'] );
define( 'BLOG_TO_AUDIO_PODCAST_FILE', __FILE__ );
define( 'BLOG_TO_AUDIO_PODCAST_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOG_TO_AUDIO_PODCAST_URL', plugin_dir_url( __FILE__ ) );

unset( $blog_to_audio_podcast_headers );

// Action Scheduler runs the audio jobs, one post per job. The bundled copy
// needs WordPress 6.5; it only loads if it's the newest copy on the site
// (WooCommerce and others ship their own). On older WordPress the jobs run
// on WP-Cron instead. It has to be included now, before plugins_loaded.
if ( version_compare( (string) get_bloginfo( 'version' ), '6.5', '>=' ) ) {
	require_once BLOG_TO_AUDIO_PODCAST_DIR . 'lib/action-scheduler/action-scheduler.php';
}

require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-icons.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-updater.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-menu.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-feedback.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-api.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-text.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-mp3.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-audio.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-posts.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-queue.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-generator.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-player.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-feed.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-editor.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-admin.php';
require_once BLOG_TO_AUDIO_PODCAST_DIR . 'includes/class-blog-to-audio-podcast-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Blog_To_Audio_Podcast_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Blog_To_Audio_Podcast_Plugin
 */
function blog_to_audio_podcast_plugin() {
	return Blog_To_Audio_Podcast_Plugin::instance();
}
add_action( 'plugins_loaded', 'blog_to_audio_podcast_plugin' );

/**
 * Set up on activation: the podcast feed's address and the hourly check
 * that keeps the audio queue moving.
 *
 * @return void
 */
function blog_to_audio_podcast_activate() {
	Blog_To_Audio_Podcast_Feed::register_feed();
	flush_rewrite_rules( false );
	update_option( Blog_To_Audio_Podcast_Feed::REWRITE_OPTION, Blog_To_Audio_Podcast_Feed::rewrite_signature(), true );
	Blog_To_Audio_Podcast_Queue::schedule_watchdog();
}
register_activation_hook( BLOG_TO_AUDIO_PODCAST_FILE, 'blog_to_audio_podcast_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings, audio and the queue stay put (uninstall.php removes the plugin's
 * data). Scheduled runs are cleared; reactivating picks the queue back up.
 *
 * @return void
 */
function blog_to_audio_podcast_deactivate() {
	Blog_To_Audio_Podcast_Queue::unschedule_all();
	Blog_To_Audio_Podcast_Feed::unregister_feed();
	delete_option( Blog_To_Audio_Podcast_Feed::REWRITE_OPTION );
	flush_rewrite_rules( false );
}
register_deactivation_hook( BLOG_TO_AUDIO_PODCAST_FILE, 'blog_to_audio_podcast_deactivate' );
