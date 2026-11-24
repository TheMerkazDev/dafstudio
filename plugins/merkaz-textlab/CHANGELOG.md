# Changelog: Merkaz TextLab

All notable changes to this plugin. Versions match the `Version:` header and `MTL_VERSION`.
Release notes from earlier versions are in `README.md` ("What changed in ...").

## 0.3.2

### Security

- **`GET /users/search` no longer leaks email addresses or logins.** The route now:
  - requires a logged-in user, the `mtl_collaborate` capability, **and** the `owner` or `editor`
    role on the project named by the new required `project_id` parameter. These are the same
    people who may add collaborators to that project;
  - returns only `{ id, display_name }`;
  - returns only people who already share at least one TextLab project with the searcher (as owner
    or named member), matched by a substring of their display name of at least 2 characters, up
    to 12 results.

  Why: a suggestion list is a convenience, so it should never reveal who has an account. To
  invite someone new, a manager still types that person's exact email, username or user ID into
  "Add collaborator" (`POST /projects/{id}/members`), which works as before.
- `GET|POST /projects/{id}/members` and the member `PUT`/`DELETE` routes return
  `id, project_id, user_id, role, invited_by, created_at, updated_at, display_name`. They no
  longer return `user_email` or `user_login`.
- `GET /me` no longer returns the user's own `email`. The app never used it.
- `assets/app.js`: the collaborator suggestions send `project_id` and use the user ID as the option
  value, and member rows show the display name and user ID in place of the email.

### Tests

- Added a PHPUnit integration suite (`tests/`, `phpunit.xml.dist`). It is not shipped in the ZIP.
  It covers activation and the capability grants, anonymous rejection on every route, cross-user
  access (IDOR) on every project route for a user who holds all 15 capabilities but no project
  role, annotation ownership, the public-visibility exception, the user-search and member-list
  privacy fixes, and the Sefaria host restriction with HTTP mocked. Run against 0.3.1 code, 8 of
  its tests fail. Run against 0.3.2, all pass.

### Capability review (documented, no change)

On activation, and again whenever `mtl_cap_version` differs from `MTL_VERSION`,
`MTL_Capabilities::activate()` adds the 5 **basic** capabilities to subscriber, contributor,
author, editor and administrator. It adds the 10 advanced ones to administrator only. Other users
get advanced capabilities only through the `mtl_role_bundle_map` option, the `entitlements` table
or the `mtl_external_user_capabilities` filter. The review checked whether any basic capability
lets a user write beyond their own objects:

| Capability | What it allows on its own | Write scope |
|---|---|---|
| `mtl_use_workbench` | `/me`, `/projects` listing, the Sefaria proxy | read only |
| `mtl_create_projects` | `POST /projects` | creates a project the user owns (always `private`) |
| `mtl_outline` | outline rows in `PUT /workspace` | needs the `owner`, `editor` or `contributor` role on that project, and touches only rows the user wrote |
| `mtl_annotate` | annotation rows in `PUT /workspace` | needs the `commenter` role or higher, and touches only rows the user wrote |
| `mtl_export_homework` | `admin-post.php?action=mtl_export` | read only, needs a role on the project |

None of them grants writes outside the user's own objects or projects where they hold a role, so
**no capability was removed or narrowed**, and no migration is needed. Residual points the
operator should decide on:

- Every logged-in subscriber can **read** projects whose visibility is `members`, and anyone,
  even a logged-out visitor, can read `public` projects through `GET /projects/{id}` and
  `/workspace`. This is how visibility is designed to work. On a site with open registration,
  "members" in practice means "anyone".
- Any subscriber can use the Sefaria proxy (`/sefaria/*`), which has no rate limit. Daf Studio's
  proxy allows 20 requests per minute.
- Bumping `MTL_VERSION` re-runs `activate()`. That **re-adds** the basic capabilities to any of the
  five roles from which an administrator removed them by hand. To keep a role out of TextLab, use
  a role manager *after* each upgrade, or remove that role from `activate()` in a reviewed change.
- `POST /projects/{id}/members` answers 404 when no user matches the email, username or ID that a
  manager typed. A project manager can therefore still confirm whether an exact email has an
  account, one guess at a time. This oracle is needed for the "add by email" feature, and it is
  limited to project managers who hold `mtl_collaborate`.

## 0.3.1 (imported)

- Imported unchanged into `TheMerkazDev/dafstudio` from the operator's upload
  (`a91fe2f1-merkaz-textlab.zip`, sha256 `cd4e60cad48c57a9d90c3513f18d603eb2b58dc7ba395b758f57ebc78dbbd90c`).
  This version is inactive on production. **Do not reactivate it.**

### Known issues (fixed in 0.3.2)

1. **Medium: `GET /users/search` discloses email addresses and logins.** Any user who holds
   `mtl_collaborate` can search every account on the site by email, login or display name. The
   response includes each match's `email` and `login` (`includes/class-mtl-rest.php`, `user_search`).
2. **Low: `GET /projects/{id}/members` returns members' `user_email` and `user_login`** to
   project managers.
3. **Low: `GET /me` returns the user's own email address.**
