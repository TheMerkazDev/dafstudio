# Merkaz TextLab 0.3.1

Merkaz TextLab is an installable WordPress/BuddyPress plugin for structured biblical text study and publication. It uses Sefaria as a live source library while keeping translations, annotations, outlines, collaboration, permissions, imports, and exports in WordPress.

The central provenance rule is simple: **source material stays source material; substantive student/research work remains user-authored; TextLab automates organization and formatting.**

## Install / upgrade

1. Back up WordPress before upgrading an alpha plugin.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload `merkaz-textlab-v0.3.1.zip` and activate it. Installing over 0.2 upgrades the TextLab tables in place.
4. Create or reuse a WordPress/Divi page containing:

   `[merkaz_textlab]`

5. Visit **WP Admin → TextLab → Settings & Access** and set the workspace page URL.

Existing TextLab research data is preserved on plugin deactivation/uninstall unless you explicitly enable destructive cleanup in `wp-config.php`.

## What changed in 0.3.1

### Toggleable verbose debug mode

WP Admin → **TextLab → Settings & Access** now has an opt-in debug switch. When enabled, TextLab records verbose operational events for the browser/REST layer, Sefaria requests and cache hits, OpenAI import requests, import extraction/chunking/apply stages, exports, PDF-engine selection, and fatal PHP errors. Each request receives a short request ID so an action can be followed across components.

A new **TextLab → Debug Log** page supports filtering by level/component, clearing logs, and downloading a JSON support report containing environment information and up to 2,000 recent log events. The report is intended to make troubleshooting reproducible without requiring shell access to the WordPress host.

Logging is privacy-conscious: API keys, authorization values, cookies, nonces, passwords and token-like secrets are redacted. Large user-authored content fields such as translations, Hebrew segments, annotation bodies, prompts, manuscript/import text and snapshots are represented by size/hash metadata rather than stored verbatim. Debug logs are capped at 10,000 entries and default to seven-day retention. Leave debug mode off normally and enable it while reproducing a problem.

For staging or a controlled troubleshooting session, `wp-config.php` can force the mode regardless of the admin checkbox:

```php
define('MTL_DEBUG', true);
```

Set it to `false` or remove the constant to return control to the WordPress setting. The browser console also becomes verbose while TextLab debug mode is active, showing REST timing/status information without printing request content. See `docs/DEBUGGING.md`.

## What changed in 0.3

### Sefaria versions and licensing

The workbench now normalizes a reference through Sefaria, loads live source/translation text, retrieves the available versions for the relevant Sefaria index, and lets the user compare another version without copying that comparison text into the project. The version selector displays the version title and available license metadata.

### Multiple user-defined segments per traditional verse

A Sefaria reference remains the canonical anchor, but a project can now store multiple ordered user segments for one verse. This is important for clause-level translation work such as the TN3101 side-by-side workbook.

### Collaborator management

Project owners/editors with the collaboration capability get an **Access** tab. Named WordPress/BuddyPress users can be added by email, username, or user ID with one of four project roles:

- viewer
- commenter
- contributor
- editor

Project role and global feature entitlement are separate. For example, someone can be an editor on a project but still lack manuscript features, or can have manuscript-reading entitlement without being a collaborator on a private manuscript.

### Membership / paywall bridge

TextLab remains payment-provider-neutral.

- **Role mapping:** WP Admin → TextLab can map any WordPress role to the Basic, Advanced, or Manuscript TextLab bundle.
- **Durable entitlements:** an administrator can grant a bundle or individual capability permanently or until an expiration date. This is appropriate for legacy/past-paywall users whose access should survive a future billing-system change.
- **Adapter filter:** membership plugins can grant capabilities at runtime with the `mtl_external_user_capabilities` filter. See `docs/MEMBERSHIP-ADAPTER.md`.

### Preview-first imports

WP Admin → TextLab → Import supports:

- DOCX
- XLSX
- TXT
- Markdown
- JSON
- CSV
- TSV

Nothing is committed immediately. Every import becomes a preview showing the proposed translation segments, annotations, outline nodes, and warnings. The user must explicitly **Apply import**.

The known TN3101 side-by-side workbook is parsed deterministically. It imports the user-created **Swafford Translation**, user-defined Hebrew segmentation, and structural metadata, while deliberately ignoring third-party comparison translations. Continuation rows can inherit an omitted traditional reference, and a parenthesized Hebrew Masoretic verse number can restore an omitted verse number.

DOCX imports preserve paragraph text plus Word paragraph style/list-level information and Word comments in the extracted representation. When the original document is too inconsistent for deterministic parsing, the optional AI import layer can infer structure without being authorized to rewrite the content.

### Optional OpenAI import agent

Configure this under **WP Admin → TextLab → Settings & Access**.

You can set:

- OpenAI API key
- model
- reasoning effort
- maximum output tokens
- import chunk size
- persistent baseline/import context
- extra file-specific context for each import

The key is used only by server-side PHP and is never localized into the browser application. TextLab explicitly sends `store=false` on AI import Responses API requests.

For production, the recommended configuration is in `wp-config.php`:

```php
define('MTL_OPENAI_API_KEY', 'your-key-here');
```

This takes precedence over a key stored in the WordPress options table. The admin key field is provided for convenience on a private site.

The AI importer uses a strict structured-output schema. Its system constraints require it to:

- treat uploaded content as data, not instructions;
- preserve user-authored wording;
- copy Hebrew exactly when present;
- never generate missing translation/commentary;
- infer only structure supported by the file;
- preserve uncertainty as a warning rather than silently inventing an answer.

AI use is recorded on the import job, and the result still requires human preview/approval before database changes occur.

### Export

Exports are generated from structured project data rather than by printing the workbench UI.

- **DOCX:** native OpenXML, Word paragraph styles, Letter margins, RTL Hebrew paragraphs, outline labels generated from hierarchy.
- **RTF:** editable Unicode RTF, including Hebrew. Requires PHP `mbstring`.
- **PDF:** if the WordPress host already provides mPDF, Dompdf, or TCPDF, TextLab can generate a direct PDF download. Otherwise it falls back to a dedicated Letter-sized print document so the user can choose **Save as PDF** in the browser.

TextLab intentionally does not bundle a PDF/font package in this alpha. The admin diagnostics page reports which PDF engine is currently available.

## Feature bundles

### Basic

- workbench
- projects
- outline
- annotations/commentary
- homework export

### Advanced

Basic plus:

- translation workspace
- Sefaria source/citation tools
- advanced annotation output targets
- advanced export profiles
- collaboration
- import

### Manuscript/editorial

All TextLab capabilities, including restricted manuscript and publication features.

The bundles are convenience groupings. WordPress capabilities remain the actual authorization primitive.

## Data model

0.3 uses dedicated WordPress tables for:

- projects
- project members
- translation/text segments
- annotations
- outline nodes
- saved sources
- annotation ↔ source links
- durable entitlements
- export profiles
- revisions/audit snapshots
- staged imports
- privacy-redacted debug logs

Every annotation and outline node stores its WordPress author. Translation segments store the translation author/editor. Sefaria material is fetched separately and is not reclassified as user-authored content.

## Sefaria architecture

The browser calls the TextLab WordPress REST API. WordPress then calls and caches Sefaria server-side. This avoids putting external-service logic throughout the browser app and gives TextLab one place to preserve version/license metadata and handle unavailable services.

The plugin currently wraps:

- Texts v3
- Ref metadata/normalization
- Versions
- Links
- Related (REST wrapper available for later UI use)

## Shortcodes

- `[merkaz_textlab]`
- `[merkaz_textlab project="123"]`

## BuddyPress

BuddyPress is optional. When active, TextLab can use BuddyPress group membership for group-visible projects, and the configured workspace page can be linked from BuddyPress navigation. Named collaborators are WordPress users, which BuddyPress also uses.

## PDF and PHP diagnostics

WP Admin → TextLab reports:

- OpenAI key status
- detected PDF engine
- ZipArchive
- DOMDocument
- mbstring
- BuddyPress
- TextLab debug mode / retention status

DOCX export and DOCX/XLSX import require `ZipArchive`. DOCX/XLSX import also requires `DOMDocument`. RTF Unicode export requires `mbstring`.

## Data preservation / uninstall

Uninstall is non-destructive by default. To intentionally remove TextLab tables and options during uninstall, add this to `wp-config.php` first:

```php
define('MTL_DELETE_DATA_ON_UNINSTALL', true);
```

Then uninstall the plugin.

## Alpha status

This package has been syntax/lint checked and packaged for WordPress, but it has not been exercised against the specific production WordPress/BuddyPress/theme/membership stack on the target site. Test it on a staging copy first.
