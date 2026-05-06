=== Audio Summary Player ===
Contributors: whistler
Tags: audio, player, podcast, analytics, gutenberg
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom audio player for article summaries with anonymous (GDPR-friendly) listening analytics.

== Description ==

Replaces the default WordPress `<audio>` element on articles with a branded player featuring:

* Animated rotating CTA / article title
* Sticky mini-bar that follows the reader after scroll
* Variable speed control (1x / 1.25x / 1.5x / 2x)
* Anonymous listening analytics (no IP, no User-Agent, no cookies)
* Conversion funnel reporting in the admin

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/audio-summary-player/`.
2. Activate via the Plugins screen.
3. In the block editor, insert the "Odtwarzacz streszczenia" block and pick an audio file.

== Changelog ==

= 0.2.0 =
* Stage 2: tracking pipeline. REST endpoint `asp/v1/event`, events table, validator, bot detector, rate limiting per session and IP, frontend tracker with offline buffer and `sendBeacon` for `abandon`.

= 0.1.0 =
* Stage 1: scaffolding, Gutenberg block, frontend player UI (no analytics yet).
