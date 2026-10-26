# Blog Post to Audio Podcast

Turns your blog posts into natural-sounding audio with ElevenLabs, adds an accessible audio player, and publishes a podcast feed for Apple Podcasts and Spotify.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/). Not affiliated with or endorsed by ElevenLabs.

## What it does

- **Audio for every post.** Connect an ElevenLabs API key, pick a model and a voice (your own cloned or Voice Library voices, or ElevenLabs' built-in ones), and create audio one post at a time from the editor, in bulk from the plugin's page or the Posts screen, or automatically when a post is published.
- **Clean reading.** Posts are cleaned up before they're read: no code, tables, captions, embeds, buttons, tables of contents, related-post lists, affiliate disclosures, shortcodes or footnote markers. Headings and list items get a pause, bare links are read as their domain, and a pronunciation list fixes brand names.
- **Seamless long posts.** Long posts are sent in chunks at paragraph breaks, each one stitched to the ones before it (ElevenLabs request stitching) so the voice keeps the same tone, then joined into one MP3 in plain PHP.
- **Cost control.** Bulk creation shows the character count and credit cost (per model, against what's left on the account) before anything is sent. The queue pauses itself when credits run out or the key stops working, and edited posts are only flagged as out of date, never redone without asking.
- **Stored on the site.** Audio is saved as Media Library attachments in `uploads/blog-to-audio-podcast/`, so backups and S3/CDN offload plugins handle it like any other upload.
- **Player and schema.** A native `<audio>` player (no library, no JavaScript, `preload="none"`) above or below the article, or wherever `[blog_audio]` goes, with AudioObject JSON-LD.
- **Podcast feed.** `/feed/podcast/` with Apple's iTunes tags, cover art, category, owner email, durations and the Podcasting 2.0 GUID and transcript tags, ready to submit to Apple Podcasts, Spotify and Podcast Index.

Everything lives under **Content Powered > Blog Post to Audio Podcast**: Overview, ElevenLabs, Content, Player and Podcast tabs.

## How audio is made

1. `Blog_To_Audio_Podcast_Text::for_post()` rebuilds the post's HTML from its blocks' saved markup (skipping blocks that aren't article text), strips shortcodes, walks the HTML with DOMDocument (a regex fallback without it) and tidies the text. The intro and outro are added and the pronunciation list applied.
2. The text is split into chunks at paragraph, then sentence, then word boundaries. Chunks are sized to finish well inside a 30-60 second PHP time limit: 1,500 characters for the standard models (about a second per 90 characters) and 5,000 for Flash (about a second per 500), never more than the model's own limit.
3. A job (post meta `_btap_job`) sends the chunks one after another with `previous_request_ids` (not on `eleven_v3`, which doesn't support stitching; IDs older than about 1 hour 50 minutes are dropped, since ElevenLabs only accepts two hours). Progress is saved after every chunk.
4. Every response is a whole MP3: an ID3 tag, a LAME "Info" frame describing that file alone, then audio frames. `Blog_To_Audio_Podcast_MP3::clean()` keeps only the audio frames (and refuses anything that isn't MP3), so the chunks join into one file whose length players read correctly. Counting frames gives the exact duration. The format is fixed at `mp3_44100_128`, which every plan can use and Apple and Spotify accept.
5. The finished file becomes an attachment of the post and replaces any older audio.

### The queue

The queue is post meta (`_btap_status`, `_btap_queued`, `_btap_retry_at`), so it's one source of truth however a job starts. Each background job makes one post's audio and then schedules the next. Jobs run on Action Scheduler (the bundled 3.9.3 on WordPress 6.5+, or a newer copy from WooCommerce and others) or on WP-Cron on older WordPress. A long post that runs short of time continues in the next job. While someone watches the Overview page or a post's Audio box, the page also works on the queue, which is much faster than one post a minute in the background.

One run at a time across the site holds a lock (a raw `INSERT IGNORE` into the options table, with a 3-minute expiry renewed before every request), which keeps to ElevenLabs' concurrency limits and stops two runs writing the same file.

Errors from ElevenLabs are sorted into three kinds (`Blog_To_Audio_Podcast_API::kind()`):

| Kind | Examples | What happens |
|---|---|---|
| retry | 429 `rate_limit_exceeded`, `concurrent_limit_exceeded` / `too_many_concurrent_requests`, `system_busy`, 5xx, timeouts | The post waits 30 s, 1, 2, 4, 8 then 15 minutes (or the `Retry-After`), then fails after 6 tries |
| pause | 401, 402, 403, `quota_exceeded`, `insufficient_credits`, `invalid_api_key`, `voice_not_found`, missing permissions | The whole queue pauses with the message; progress is kept; Resume carries on |
| post | `text_too_long` and other problems with one post's text | That post is marked failed and the queue moves on |

## Settings and data

| Where | What |
|---|---|
| `blog_to_audio_podcast_settings` | All settings (one array; each tab saves only its own fields) |
| `blog_to_audio_podcast_api_key` | The API key, encrypted with libsodium using a key derived from the site's `AUTH_KEY`/`AUTH_SALT`. Never autoloaded, never printed (the page shows the last four characters) |
| `blog_to_audio_podcast_rates` | Characters sent and credits charged per model, from the `character-cost` response header, for estimates |
| `blog_to_audio_podcast_paused`, `_lock`, `_batch`, `_tier`, `_guid`, `_rewrite`, `_feed_changed` | Queue state, the lock, the bulk run's size, the account's plan, the podcast's permanent GUID, rewrite bookkeeping, and when audio last changed (for the feed's `Last-Modified`) |
| Post meta `_btap_*` | Audio details, queue state, the job in progress, cached character counts, the out-of-date flag and the "leave this post out" flag |

To keep the key out of the database, define it in `wp-config.php`:

```php
define( 'BLOG_TO_AUDIO_PODCAST_API_KEY', 'sk_...' );
```

The key needs Text to Speech access. Read access to Voices, Models and User lets the settings page list your voices and show your credits; without it, the built-in voice and model lists are used.

Deleting the plugin removes its settings, the key, the queue and all `_btap_` post meta. The audio files stay in the Media Library, since they cost credits to make.

### Permissions

- The settings page, bulk creation and the queue buttons need `manage_options`.
- Creating or deleting one post's audio needs `edit_post` for that post plus `edit_others_posts` (Editors and Administrators), filterable with `blog_to_audio_podcast_capability`. Authors see the audio status but can't spend credits. The one exception is "Create audio automatically when a post is published", which, when the site owner turns it on, covers posts published by any role, once per post.
- Audio attachments belong to the Editor or Administrator who made them, or to no one when a background job did, so an Author can't delete a post's audio to have it made again.
- While a post isn't public (draft, scheduled, private, password protected or left out), its audio attachment is `private`, so core's Media Library, attachment pages and REST API only show it to people who could see the post. It switches back to `inherit` when the post is published.
- The feed lists published, public, non-password-protected posts only. The player isn't shown on password-protected posts until the password is entered, and `[blog_audio id="..."]` only shows audio for posts the visitor can read. File names end in a random string, so a file's address can't be guessed. (Like any upload, a file stays reachable at its address; apps that already downloaded an episode keep it after a post is unpublished.)
- On multisite, a key defined in `wp-config.php` is shared by every site in the network, and each site's administrators can spend its credits. Save a key on each site instead if that isn't what you want.

## Developer reference

### Filters

| Filter | Use |
|---|---|
| `blog_to_audio_podcast_post_types` | Post types that get audio |
| `blog_to_audio_podcast_text` | The full text sent to ElevenLabs for a post (`$text, $post`) |
| `blog_to_audio_podcast_body_text` | The cleaned article text, before the intro and outro |
| `blog_to_audio_podcast_skip_block` | Leave a block out (`$skip, $block_name, $attrs`) |
| `blog_to_audio_podcast_skip_patterns` | Regexes for paragraphs never read (disclosures, "Related:" lines) |
| `blog_to_audio_podcast_abbreviations` | Regex => replacement map for abbreviations |
| `blog_to_audio_podcast_chunk_size` | Characters per request (`$size, $model_id`); capped at the model's limit |
| `blog_to_audio_podcast_stitching` | Whether a model gets `previous_request_ids` |
| `blog_to_audio_podcast_max_chars` | Most characters one post may use (default 150,000) |
| `blog_to_audio_podcast_capability` | Capability for creating and deleting a post's audio |
| `blog_to_audio_podcast_use_action_scheduler` | `false` to run jobs on WP-Cron even when Action Scheduler is loaded |
| `blog_to_audio_podcast_player_css` | The player's CSS (return `''` to style it in the theme) |
| `blog_to_audio_podcast_schema` | The AudioObject JSON-LD array |

### Actions

- `blog_to_audio_podcast_audio_created` (`$post_id, $attachment_id, $job`) after a post's audio is saved.

### Leaving content out

- The **Exclude from audio** block (`blog-to-audio-podcast/exclude`) wraps blocks that stay on the page but aren't read. It saves no markup of its own.
- `[no_audio]...[/no_audio]` in the classic editor.
- The CSS class `no-audio` on any block or element.
- The **Leave this post out** checkbox in the Audio box removes a post from the audio, the player and the feed.

## Automatic updates

Updates come from https://github.com/jmsprsns/blog-to-audio-podcast through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `blog-to-audio-podcast.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `blog-to-audio-podcast.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Push a tag that matches that version (or publish a GitHub release with it):

   ```bash
   git tag -a v1.0.1 -m "Version 1.0.1" && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The description, requirements and "Tested up to" in the "View details" popup come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag. PUC uses the latest GitHub release, then the highest version tag, then the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'BLOG_TO_AUDIO_PODCAST_DEV_MODE', false ); // false = check for updates, true = never check
```

## Structure

```
blog-to-audio-podcast.php                         Plugin header, constants, boot, activation
uninstall.php                                     Removes settings, the key, queue state and post meta
readme.txt                                        WordPress readme; feeds the "View details" popup
includes/
  class-blog-to-audio-podcast-plugin.php          Main singleton, settings, encrypted key, models, voices
  class-blog-to-audio-podcast-api.php             ElevenLabs client and error classification
  class-blog-to-audio-podcast-text.php            Post to clean text, and chunking
  class-blog-to-audio-podcast-mp3.php             Strips tags and info frames, joins chunks, counts frames
  class-blog-to-audio-podcast-generator.php       One post's audio: jobs, stitching, retries, the lock
  class-blog-to-audio-podcast-queue.php           The queue, Action Scheduler / WP-Cron jobs, pause, watchdog
  class-blog-to-audio-podcast-audio.php           Attachments, files, out-of-date detection, clean-up
  class-blog-to-audio-podcast-posts.php           Outstanding posts, stats, cached character counts
  class-blog-to-audio-podcast-player.php          Front-end player, [blog_audio] and AudioObject schema
  class-blog-to-audio-podcast-feed.php            The podcast feed
  class-blog-to-audio-podcast-editor.php          Audio box, posts column, bulk action, publish hook, block
  class-blog-to-audio-podcast-admin.php           Content Powered > Blog Post to Audio Podcast page and AJAX
  class-blog-to-audio-podcast-menu.php            Shared top-level "Content Powered" menu
  class-blog-to-audio-podcast-feedback.php        Shared Submit Feedback and Report a Bug pages
  class-blog-to-audio-podcast-updater.php         GitHub update checks (PUC wiring)
  class-blog-to-audio-podcast-icons.php           Inline Lucide and Simple Icons helper
assets/
  admin.css, admin.js                             The plugin's page only
  editor.css, editor.js                           Audio box and posts column
  exclude-block.js, exclude-block.css             The "Exclude from audio" block (editor only)
  icon.svg                                        Icon on the update screens
  content-powered.svg                             Sumo logo for the Content Powered menu
icons/                                            The Lucide and brand icons the plugin uses (plus LICENSE)
lib/action-scheduler/                             Action Scheduler 3.9.3
plugin-update-checker/                            Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every Content Powered plugin puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-blog-to-audio-podcast-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-blog-to-audio-podcast-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Icons

`icons/` holds the [Lucide](https://lucide.dev/icons) icons the plugin uses, and `icons/simpleicons/` the brand icons. Copy more from the skeleton's full set when you need them. Print one inline with:

```php
echo Blog_To_Audio_Podcast_Icons::get( 'podcast', 20 );                  // name, size in px
echo Blog_To_Audio_Podcast_Icons::get( 'simpleicons/spotify', 18 );      // a brand icon
```

Icons use `currentColor`, so they take on the text colour around them.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Simple Icons](https://simpleicons.org) brand icons** (`icons/simpleicons/`): CC0 1.0 (public domain). Brand names and logos are trademarks of their respective owners. Using one doesn't imply endorsement.
- **[Action Scheduler](https://actionscheduler.org)** (`lib/action-scheduler/`): GPL-3.0, by Automattic. See `lib/action-scheduler/license.txt`.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

ElevenLabs is a trademark of its owner. This plugin isn't affiliated with or endorsed by ElevenLabs.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
