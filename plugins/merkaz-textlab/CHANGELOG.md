# Changelog: Merkaz TextLab

All notable changes to this plugin. Versions match the `Version:` header and `MTL_VERSION`.
Release notes from earlier versions are in `README.md` ("What changed in ...").

## 0.3.1 (imported)

- Imported unchanged into `TheMerkazDev/dafstudio` from the operator's upload
  (`a91fe2f1-merkaz-textlab.zip`, sha256 `cd4e60cad48c57a9d90c3513f18d603eb2b58dc7ba395b758f57ebc78dbbd90c`).
  This version is inactive on production. **Do not reactivate it.**

### Known issues

1. **Medium: `GET /users/search` discloses email addresses and logins.** Any user who holds
   `mtl_collaborate` can search every account on the site by email, login or display name. The
   response includes each match's `email` and `login` (`includes/class-mtl-rest.php`, `user_search`).
2. **Low: `GET /projects/{id}/members` returns members' `user_email` and `user_login`** to
   project managers.
3. **Low: `GET /me` returns the user's own email address.**
