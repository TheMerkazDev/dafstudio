=== Daf Studio ===
Contributors: site-owner
Tags: source-sheets, hebrew, jewish-studies, education, typography
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adaptive bilingual source-sheet editor. Version 0.4.0 is a staging beta, not a certified production release.

== Description ==
Four-arrow handles, live source reordering, click/keyboard move controls, simplified Text/Sheet/Style panels,
copy/paste and local file imports, adaptive stepped margins, Hebrew/English direction,
font/color controls, browser PDF/HTML/JSON export, private account storage and membership-aware sharing.

== Installation ==
Back up and test on staging. Upload this folder/ZIP, activate, and add [daf_studio] to a page.
Use a wide Divi Text module or a WordPress Shortcode block. Settings > Daf Studio contains configuration.
No build step is required. Existing WordPress accounts are used.

== External services ==
Sefaria: optional explicit source lookup sends the requested reference/language to www.sefaria.org.
Google Fonts: optional user-approved font loading contacts fonts.googleapis.com/fonts.gstatic.com.
Private PDF converter: optional server configured in wp-config.php receives uploaded PDF bytes for extraction.
No source text is sent to an AI service. No font binaries are included.

== Important limitations ==
No payment checkout, real-time collaboration, OCR, Word-layout fidelity, or strict same-line note anchoring. Inline bold/italic/underline are supported.
Browser editor/schema/converter tests were run; real WordPress, Docker, Divi, BuddyPress and payment integration
require testing on the destination host. Full deployment and acceptance guides are in the source bundle.

== Data and uninstall ==
Sheets and retained history are in custom database tables. Uninstall preserves them unless the administrator
explicitly sets DAF_STUDIO_DELETE_DATA=true. Back up before removal. Privacy export/erase hooks are provided.

== Changelog ==
= 0.4.0 =
WordPress admin controls for default sheet appearance, editor UI colors, fonts, page defaults and spacing.
Administrator-editable validated JSON for the default populated sheet, with bundled-example restore and fresh-preview links.
Bundled default replaced with a public Sefaria-derived conversation based on Allen Katz’s “Breyshit: A Derech Eretz Commentary” (sheet 469471).
Fresh workspaces use site defaults while existing saved sheets remain unchanged; File > Start from site default reloads the configured starter.
Schema remains version 1; no database migration.

= 0.3.0 =
Independent desktop collapse controls for Sources and Settings panels.
US Half Letter/Letter/Legal/Tabloid and ISO A5/A4/B5/A3 page presets.
Selection toolbar for bold, italic and underline stored as validated formatting ranges, not HTML.
Visual font pickers with the requested Latin and Hebrew families; optional web-font loading remains opt-in.
Default demo replaced with a public Living Daf product-design conversation (superseded in 0.4.0).
Schema remains version 1; no database migration.

= 0.2.0 =
Dedicated pointer-based source handles, live list/page updates, drag rollback and one-step Undo.
Clear region drop targets, keyboard/click alternatives, touch-sized controls and responsive drawers.
Progressively disclosed advanced settings, neutral/teal UI, focus mode and style presets.
Schema and account/membership settings unchanged. Back up and replace the plugin on staging.
