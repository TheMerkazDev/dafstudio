# AGENTS.md: Daf Studio and Merkaz TextLab

Guidance for any agent or human who changes this repository. `CLAUDE.md` imports this file, so
edit this one.

## This repository is PUBLIC

`TheMerkazDev/dafstudio` is a public GitHub repository. Never commit:

- secrets of any kind, such as API keys (OpenAI, `DAF_CONVERTER_TOKEN`), passwords, salts, cookies
  or `wp-config.php` values;
- personal data, such as real users' names, emails, logins, user IDs, IP addresses or member lists;
- site exports, database dumps, debug-log downloads, support reports, screenshots of admin
  screens, or real users' sheets and projects;
- copyrighted liturgy or texts that are not openly licensed. Tests use short public-domain
  fragments such as Genesis 1:1;
- any other uploads from the operator. Only the two plugin directories came from uploads.

Test fixtures use invented people at `@example.org` or `@secret.example`. If something sensitive is
committed by mistake, stop and tell the operator. Rewriting history is their decision.

## What is here

| Path | What it is |
|---|---|
| `plugins/daf-studio/` | Daf Studio 0.4.0. **Live on production.** |
| `plugins/merkaz-textlab/` | Merkaz TextLab. Inactive on production. |
| `bin/build-zip.sh` | Builds `dist/<slug>-<version>.zip` and its `.sha256` file. |
| `plugins/*/tests/` | PHPUnit integration suites. They are not shipped in the ZIP. |
| `plugins/*/CHANGELOG.md` | Records every change and every known issue. |

The wider platform rules are in `merkaz-platform` (`AGENTS.md`, `docs/platform/INTERFACES.md`).
Where those rules and this file conflict, the stricter rule wins.

### Daf Studio

This plugin provides a full-screen source-sheet editor (`assets/*.js`), served through
`/?daf_studio_app=1` and embedded with `[daf_studio]`. Documents use a strict JSON schema
(`includes/schema.php`, `schemaVersion` 1) that holds plain text and format ranges, never HTML.
Each sheet belongs to one owner and keeps 20 revisions. Pro plan: the `daf_studio_pro` capability,
`manage_options`, a Paid Memberships Pro level, or the `daf_studio_plan` filter. Share links use a
64-hex token: only its SHA-256 is stored, it expires after 30 days, and the owner must still be Pro.
The Sefaria lookup goes only to the fixed host `www.sefaria.org`, with a rate limit. The optional
PDF converter is configured only through the `DAF_CONVERTER_URL` and `DAF_CONVERTER_TOKEN`
constants. The plugin has no BuddyPress code.

**Daf Studio is live, so do not change its behaviour** unless the operator explicitly asks and a
staging test is planned. Record defects under "Known issues" in its CHANGELOG.

### Merkaz TextLab

This plugin is a workbench with projects, segments (one Sefaria ref can have several user-defined
segments), annotations, outlines, sources, revisions, named collaborators (`viewer`, `commenter`,
`contributor`, `editor`, plus the owner), DOCX/XLSX import with optional OpenAI structuring,
DOCX/RTF/print export and a debug log. It uses 12 `wp_mtl_*` tables and 15 `mtl_*` capabilities.
BuddyPress is optional and every call to it is guarded.

## Identifiers that must never change

Renaming any of these breaks stored content, saved URLs or existing data. Change one only with a
reviewed migration.

| | Daf Studio | Merkaz TextLab |
|---|---|---|
| Slug / main file | `daf-studio/daf-studio.php` | `merkaz-textlab/merkaz-textlab.php` |
| Shortcode | `daf_studio` | `merkaz_textlab` |
| Query vars | `daf_studio_app`, `daf_fresh` | |
| REST namespace | `daf-studio/v1` | `merkaz-textlab/v1` |
| Tables | `{prefix}daf_sheets`, `{prefix}daf_revisions` | `{prefix}mtl_` + `projects`, `project_members`, `segments`, `annotations`, `outline_nodes`, `sources`, `annotation_sources`, `entitlements`, `export_profiles`, `revisions`, `imports`, `debug_logs` |
| Options | `daf_studio_schema`, `daf_studio_pmpro_levels`, `daf_studio_upgrade_url`, `daf_studio_defaults`, `daf_studio_ui_theme`, `daf_studio_default_sheet_json` | `mtl_db_version`, `mtl_cap_version`, `mtl_workspace_page_url`, `mtl_role_bundle_map`, `mtl_debug_enabled`, `mtl_debug_retention_days`, `mtl_openai_*` |
| Capabilities | `daf_studio_pro` | `mtl_use_workbench`, `mtl_create_projects`, `mtl_outline`, `mtl_annotate`, `mtl_export_homework`, `mtl_translate`, `mtl_sources`, `mtl_advanced_annotations`, `mtl_manuscript_read`, `mtl_manuscript_edit`, `mtl_publish`, `mtl_advanced_export`, `mtl_collaborate`, `mtl_import_data`, `mtl_manage_projects` |
| Filters | `daf_studio_plan` | `mtl_external_user_capabilities` |
| Constants | `DAF_STUDIO_DELETE_DATA`, `DAF_CONVERTER_URL`, `DAF_CONVERTER_TOKEN` | `MTL_DELETE_DATA_ON_UNINSTALL`, `MTL_DEBUG`, `MTL_OPENAI_API_KEY` |
| admin-post actions | | `mtl_import_preview`, `mtl_import_apply`, `mtl_import_discard`, `mtl_export`, `mtl_debug_clear`, `mtl_debug_download` |
| Transients | `daf_rate_*`, `daf_sefaria_*` | `mtl_sef_*` |
| Document format | `schemaVersion: 1` and every key in `schema.php` | |

## REST permission rules

1. Every route has a real `permission_callback`. `__return_true` is allowed only on Daf Studio's
   `GET /shared`, which instead requires a valid unexpired token in the `X-Daf-Share` header.
2. Anonymous users get 401 or 403 from every other route. The one designed exception is TextLab
   projects whose visibility is set to `public`, which can be read anonymously.
3. Check ownership on the server for every object. Daf Studio scopes each query with
   `owner_id = current user` and returns 404, not 403, for other people's sheets. TextLab goes
   through `MTL_DB::access_role()` and the `role_can_*` helpers, and every row it touches must
   belong to the project in the route.
4. A nonce is never authorization. Cookie-authenticated writes need the `wp_rest` nonce **and**
   a capability or role check.
5. **No REST response may contain an email address or a login** (`user_email`, `user_login`),
   whether the user's own or another's. Identify people by user ID and `display_name`.
6. Server-side calls go only to fixed hosts (`www.sefaria.org`, `api.openai.com`, and the
   converter that the constants configure). Never build an outbound URL from user input, apart
   from an encoded path segment or query value.
7. Adding a route means adding tests for anonymous access, another user's object, and the
   owner's success case.

## Data and privacy rules

- Migrations are additive, versioned and idempotent. Uninstall keeps data unless the
  documented constant is set. Never drop tables or remove capabilities that existing rows depend
  on without a reviewed migration.
- The TextLab debug log must stay redacted. Do not log content, keys, cookies or nonces.
- Daf Studio's privacy exporter and eraser (`daf-studio`) must keep covering sheets and revisions.
- The project's group visibility must fail closed. A user who cannot be shown to be a group
  member is not one.
- BuddyPress is not installed on the target site and must not be required. TextLab asks the
  `merkaz_community_is_group_member` filter (Merkaz Community, see `INTERFACES.md`) first and
  falls back to `groups_is_user_member()` only when that filter has no callbacks. Do not add a
  BuddyPress shim.

## Hebrew fidelity

- Keep niqqud, cantillation, maqaf, sof pasuq, geresh and gershayim (`U+05F3`/`U+05F4`) intact.
  Do not apply Unicode normalisation to stored text, and do not cut a combining mark from its
  base letter.
- Daf Studio deliberately strips bidi embedding and override controls (`U+202A` to `U+202E`).
  Keep that, and do not strip anything else.
- Use `dir="auto"` or `dir="rtl"` on user and source text. Do not reorder text on the server.
- A test that touches text must round-trip a pointed Hebrew string byte for byte.

## Releases and deployment

- **No auto-deploy.** Do not add `Update URI`, `GitHub Plugin URI` or any other updater header,
  and do not add a workflow that pushes to a site. A person installs a release ZIP after staging
  sign-off.
- Never contact the live site, its database or its credentials. Agents work locally only.
- With every behaviour change, bump the version in the plugin header and the version constant
  together (`DAF_STUDIO_VERSION`, `MTL_VERSION`) and add a CHANGELOG entry. TextLab's
  `mtl_db_version` and `mtl_cap_version` follow `MTL_VERSION`, so a bump re-runs the idempotent
  installers.
- Work on a feature branch. Never push to `main`.

## Test commands

```bash
# Once per machine: a separate table prefix so other suites in the same DB are untouched.
sed "s/^\$table_prefix = .*/\$table_prefix = 'wptests_ds_';/" \
  /home/user/_wpenv/wp-tests-config.php > /home/user/_wpenv/wp-tests-config-dafstudio.php

for p in daf-studio merkaz-textlab; do
  (cd plugins/$p && WP_TESTS_DIR=/home/user/_wpenv/vendor/wp-phpunit/wp-phpunit \
    WP_PHPUNIT__TESTS_CONFIG=/home/user/_wpenv/wp-tests-config-dafstudio.php \
    /home/user/_wpenv/vendor/bin/phpunit)
done
find plugins -name '*.php' -print0 | xargs -0 -n1 php -l
```

The tests must not reach the network. Answer every HTTP request through `pre_http_request`, and
treat an unexpected request as a test failure. Report the counts that actually ran, and write
"NOT RUN" for anything you could not run.

## How to work

- Read the plugin's README, `CHANGELOG.md` and the relevant tests first.
- Keep each change small, with one concern per commit. Put a CHANGELOG entry in the same commit.
- State what you verified and at what level: source, local tests, staging or production.
