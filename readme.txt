=== Blog Post to Audio Podcast ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: text to speech, audio, podcast, elevenlabs, accessibility
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns your blog posts into natural-sounding audio with ElevenLabs, adds an accessible audio player, and publishes a podcast feed for Apple Podcasts and Spotify.

== Description ==

Give every article an audio version. Connect your ElevenLabs account, choose a voice, and the plugin reads your posts aloud, saves the audio on your own site, adds a player to each post and publishes your blog as a podcast.

= Features =

* Choose any ElevenLabs model and voice: ElevenLabs' built-in voices, voices you've added from the Voice Library, or your own cloned voice. Hear a sample with your settings before you save.
* Create audio from the post editor, in bulk for every post that doesn't have it yet, from the Posts screen, or automatically when a post is published.
* See the cost first. Bulk creation counts the characters and shows the credits it will use for each model, against what's left on your account, before anything is sent.
* Posts are cleaned up before they're read: code, tables, captions, embeds, buttons, tables of contents, related-post lists, affiliate disclosures and shortcodes are left out. Add your own pronunciations for brand names.
* Long posts are read in parts that are stitched together, so the voice stays consistent, and joined into one MP3.
* Audio is saved in your Media Library and played from your own site, so it works with backups and CDN or S3 offload plugins.
* A lightweight, accessible player: the browser's own audio controls, keyboard and screen reader friendly, nothing loaded until someone presses play, and only on posts that have audio.
* AudioObject structured data for every post with audio.
* A podcast feed with cover art, category, episode lengths and everything Apple Podcasts and Spotify need, plus a checklist and step-by-step submission guide.
* A spoken intro and outro, and AI voice disclosures for the player and the podcast.
* Edited posts are marked as out of date for you to recreate. Nothing is ever redone without you, so credits aren't spent by surprise.
* If your credits run out or the key stops working, the queue pauses and picks up where it left off when you resume.

You need an ElevenLabs account and API key. ElevenLabs' free plan doesn't allow commercial use and requires crediting ElevenLabs; the plugin can add that credit for you. Paid plans remove both requirements.

Find it under Content Powered > Blog Post to Audio Podcast in the admin menu.

Blog Post to Audio Podcast isn't affiliated with or endorsed by ElevenLabs. ElevenLabs is a trademark of its owner.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Privacy =

When audio is made, the post's text is sent to ElevenLabs. Nothing about your visitors is sent anywhere: the audio files are stored on and played from your site. The plugin adds suggested wording to Settings > Privacy.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Brand icons by [Simple Icons](https://simpleicons.org), CC0 1.0. Brand names and logos are trademarks of their respective owners.
* Background jobs by [Action Scheduler](https://actionscheduler.org), GPL-3.0.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `blog-to-audio-podcast` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Blog Post to Audio Podcast > ElevenLabs and add your API key (elevenlabs.io > Developers > API Keys).
4. Choose a voice, then create audio from the Overview tab or from the Audio box when you edit a post.
5. To publish a podcast, fill in the Podcast tab and submit the feed address to Apple Podcasts and Spotify.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= How much does it cost? =

The plugin is free. ElevenLabs charges credits per character read, and the plugin shows the estimate before it creates anything. A typical 1,500-word article is around 9,000 characters. Flash models use half as many credits per character as the standard ones.

= Will it slow down my site? =

No. The player is the browser's own audio element with a few lines of CSS, loaded only on posts that have audio, and the audio file isn't downloaded until someone presses play. Audio is made in the background, never while a visitor waits.

= What happens when I edit a post? =

Its audio is marked as out of date in the Audio box and on the Posts screen. Recreate it when you're ready, or include out-of-date posts in a bulk run.

= Can I leave part of a post out of the audio? =

Yes. Wrap it in the "Exclude from audio" block, wrap it in [no_audio] ... [/no_audio] in the classic editor, or give it the CSS class no-audio. To leave a whole post out, tick "Leave this post out" in its Audio box.

= How do I get my podcast on Apple Podcasts and Spotify? =

Fill in the Podcast tab (cover art, category and owner email), then paste the feed address into Apple Podcasts Connect and Spotify for Creators. The Podcast tab has a checklist and the steps for each. New audio appears in podcast apps on its own.

= Who can create audio? =

Administrators and Editors. Authors and Contributors can see the audio status but can't spend credits. Developers can change this with the blog_to_audio_podcast_capability filter.

= Is my API key safe? =

It's stored encrypted, never shown in full on any page, and only used for requests from your server to ElevenLabs. You can also define BLOG_TO_AUDIO_PODCAST_API_KEY in wp-config.php to keep it out of the database.

== Changelog ==

See https://github.com/jmsprsns/blog-to-audio-podcast/releases for the full release history.
