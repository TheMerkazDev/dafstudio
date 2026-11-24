# Changelog: Daf Studio

All notable changes to this plugin. Versions match the `Version:` header and `DAF_STUDIO_VERSION`.
Release notes from earlier versions are in `readme.txt` under `== Changelog ==`.

## Unreleased (repository only, no plugin code change)

- Added a PHPUnit integration suite (`tests/`, `phpunit.xml.dist`). It is not shipped in the ZIP.
  It covers activation, anonymous rejection on every route, cross-user access (IDOR) on every
  sheet route, share-token hashing, the 30-day expiry, revocation and the Pro downgrade, the
  free-plan quota, a byte-for-byte pointed-Hebrew round-trip, and the Sefaria host, URL rejection,
  cache and rate limit, with HTTP mocked. The suite needed no change to plugin code.

## 0.4.0 (imported)

- Imported unchanged into `TheMerkazDev/dafstudio` from the operator's upload
  (`b8483d23-daf-studio.zip`, sha256 `3b994fc7a210920b1d5cff74f40cc6103003811a48cc71bdd8e5c111c5c45748`).
  This is the version active on production.

### Known issues

Found in a source review during the import. None has been fixed, because this version is live.
Each needs an operator decision and a staging test before a fix ships.

1. **Uninstall leaves three options behind.** When `DAF_STUDIO_DELETE_DATA` is `true`,
   `uninstall.php` drops the tables and deletes `daf_studio_schema`, `daf_studio_pmpro_levels` and
   `daf_studio_upgrade_url`. It does not delete `daf_studio_defaults`, `daf_studio_ui_theme` or
   `daf_studio_default_sheet_json`. Severity: low, because those hold site configuration and no
   personal data.
2. **Deleting a user always deletes that user's sheets.** The `delete_user` hook ignores the
   `$reassign` argument. The sheets and revisions are removed even when the admin chooses
   "Attribute all content to another user", and the removal does not run in a transaction. This is
   arguably correct for private data, but the admin screen does not warn about it. Severity: low.
3. **Activation creates tables only for the current site on multisite.** `daf_install()` runs once,
   for the blog that activates the plugin. A network activation does not create tables on the
   other sites, and REST calls there fail. Severity: low, since production is a single site.
4. **The rate limit is a sliding window.** `daf_rate_limit()` calls `set_transient(..., MINUTE_IN_SECONDS)`
   on every request, which resets the timer. A client that keeps retrying stays blocked longer than
   one minute. Severity: cosmetic.
5. **The share route expects a decodable document.** `daf_share_sheet()` loops over
   `$doc['blocks']` without first checking that `json_decode()` succeeded. A corrupted row would
   raise a PHP warning instead of returning a clean error. Rows written through the REST API are
   always valid JSON. Severity: low.
