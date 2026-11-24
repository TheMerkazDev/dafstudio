# dafstudio

Source for two WordPress plugins used on **the-merkaz.org**, a Jewish learning and community site.

| Plugin | Path | Version | Production status |
|---|---|---|---|
| **Daf Studio** | `plugins/daf-studio/` | 0.4.0 | **Active on production.** Treat as live code. |
| **Merkaz TextLab** | `plugins/merkaz-textlab/` | 0.3.1 | **Inactive.** 0.3.1 must not be reactivated (it leaks user emails). |

- **Daf Studio** is an editor for bilingual (Hebrew and English) source sheets laid out in a Vilna-style
  page. Sheets are private to their owner and have a revision history. A Pro user can share a sheet
  through a link that expires. Sources can be looked up on Sefaria. Paid Memberships Pro is optional.
  Shortcode `[daf_studio]`, REST namespace `daf-studio/v1`.
- **Merkaz TextLab** is a workbench for annotating, outlining and translating texts linked to
  Sefaria. It supports projects with named collaborators and can import from and export to DOCX, XLSX
  and RTF. BuddyPress groups are optional. Shortcode `[merkaz_textlab]`, REST namespace `merkaz-textlab/v1`.

Both plugins came from the operator's uploaded ZIPs. The first commit on this repository's history
is a byte-identical import, so later changes can be reviewed as diffs. Each plugin has a
`CHANGELOG.md` that records what changed and any known issues.

## Status

- Daf Studio: imported unchanged. Defects found in review are listed under "Known issues" in
  `plugins/daf-studio/CHANGELOG.md`. They are **not** fixed here because the plugin is live.
- TextLab 0.3.1: imported unchanged. Its `/users/search` route returns the email address and login
  of any user. Do not activate it until that is fixed; see `plugins/merkaz-textlab/CHANGELOG.md`.

## Test

Each plugin has an integration suite under `tests/`. The suites use the WordPress PHPUnit test library and a local MariaDB. The reference
environment is `/home/user/_wpenv`, with a dedicated test config whose `$table_prefix` is `wptests_ds_`:

```bash
cd plugins/daf-studio        # or plugins/merkaz-textlab
WP_TESTS_DIR=/home/user/_wpenv/vendor/wp-phpunit/wp-phpunit \
WP_PHPUNIT__TESTS_CONFIG=/home/user/_wpenv/wp-tests-config-dafstudio.php \
/home/user/_wpenv/vendor/bin/phpunit
```

Lint every PHP file:

```bash
find plugins -name '*.php' -print0 | xargs -0 -n1 php -l
```

The tests mock all outbound HTTP (Sefaria, OpenAI), so they never contact a live service.

## Build

```bash
bin/build-zip.sh daf-studio        # -> dist/daf-studio-<version>.zip and .sha256
bin/build-zip.sh merkaz-textlab
```

The ZIP leaves out `tests/` and `phpunit.xml.dist`. The `dist/` directory is not committed.
Releases are installed by a person after staging sign-off. Nothing here deploys automatically.

## Licence

Both plugins are GPL-2.0-or-later. See [`LICENSE`](LICENSE).

## Agents

Read [`AGENTS.md`](AGENTS.md) before changing anything. `CLAUDE.md` imports it.
