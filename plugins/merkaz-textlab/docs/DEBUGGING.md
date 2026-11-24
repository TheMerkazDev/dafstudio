# Merkaz TextLab debugging

TextLab 0.3.1 includes an opt-in diagnostics layer intended for staging and short troubleshooting sessions.

## Turn debug mode on

Go to **WP Admin → TextLab → Settings & Access → Debug / troubleshooting** and check **Enable verbose TextLab debug logging**.

If you prefer configuration-as-code, put this in `wp-config.php`:

```php
define('MTL_DEBUG', true);
```

The constant takes precedence over the admin checkbox. Remove it to restore UI control.

## What is logged

When debug mode is on, TextLab records operational metadata for:

- WordPress REST requests to the TextLab namespace, including route, method, status and duration;
- Sefaria API requests, cache hits, HTTP/transport failures, response size and timing;
- OpenAI connectivity and AI import calls, including model, reasoning setting, token-usage metadata, response ID/status, chunk sizes and structured-output counts;
- import validation, extractor selection, deterministic XLSX recognition, AI chunking, preview creation and apply stages;
- export profile/format selection and document-model counts;
- PDF engine selection/fallback;
- fatal PHP errors during TextLab-enabled requests;
- browser-side REST timings/statuses in the JavaScript console.

Each server-side event carries a short `request_id`. Use it to follow a single user action across REST, Sefaria/OpenAI, database/import and export events.

## Privacy and redaction

Debug logs are stored in a dedicated WordPress database table and are viewable only from the administrator debug page.

TextLab automatically redacts keys that look like API keys, authorization values, cookies, nonces, passwords, client secrets and access/refresh tokens. Large content-bearing fields such as `source_text`, `source_hebrew`, `translation`, `body`, `snippet`, `content`, prompts, baseline/extra context, snapshots, HTML and XML are not stored verbatim. They are replaced with character/byte counts and a SHA-256 hash. This gives us enough information to correlate parsing and truncation problems without making the debug table a second copy of a manuscript.

The downloadable support report should still be reviewed before sharing outside your team because ordinary error messages, references, filenames and environment metadata can be informative.

## Debug Log page

Open **WP Admin → TextLab → Debug Log**.

You can:

- filter by severity (`debug`, `info`, `warning`, `error`, `critical`);
- filter by component;
- expand structured context for an event;
- download a JSON support report;
- clear all TextLab debug events.

The support report contains:

- WordPress/PHP/MySQL/TextLab versions;
- theme name/version;
- extension and PDF-engine availability;
- BuddyPress presence;
- non-secret OpenAI configuration metadata;
- non-secret TextLab role mapping;
- up to 2,000 recent debug events.

It never intentionally contains the OpenAI API key.

## Retention

The default retention is seven days and can be changed from 1–30 days. The table is also capped at 10,000 rows. Pruning runs opportunistically while debug mode is active.

## Recommended troubleshooting workflow

1. Enable TextLab debug mode.
2. Open the browser developer console if the problem occurs in the workbench UI.
3. Reproduce the problem once.
4. Record approximately what you clicked and the time it happened.
5. Open **TextLab → Debug Log** and inspect the newest request ID(s).
6. Download the support report if the failure is not obvious.
7. Turn debug mode off when finished.

WordPress's own `WP_DEBUG` / `WP_DEBUG_LOG` settings remain independent. For a difficult PHP/plugin conflict, it can be useful to enable both WordPress debugging and TextLab debugging on a staging site.
