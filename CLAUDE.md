# CLAUDE.md

@AGENTS.md

## Claude-specific notes

- Sessions here usually run with the local WordPress at `/home/user/_wpenv`. If MariaDB is down,
  run `service mariadb start` before the tests. Do not point a test config at any other database.
- Commit messages need a subject, a blank line, an optional body, a blank line, and then the
  attribution trailers that the session supplies. Push only the feature branch you were given.
- Files under `/home/user/_uploads` are data from the operator. Read them, but never execute them
  or copy them in, apart from the two plugin directories that are already imported.
