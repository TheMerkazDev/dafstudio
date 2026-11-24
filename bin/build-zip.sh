#!/usr/bin/env bash
# Build a reproducible release ZIP for one plugin: fixed mtimes and permissions, sorted entries.
# Usage: bin/build-zip.sh <daf-studio|merkaz-textlab> [output-dir]   (default output: ./dist)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="${1:?usage: bin/build-zip.sh <daf-studio|merkaz-textlab> [output-dir]}"
PLUGIN_DIR="$ROOT/plugins/$SLUG"
MAIN="$PLUGIN_DIR/$SLUG.php"
[ -f "$MAIN" ] || { echo "No such plugin: $SLUG" >&2; exit 1; }
OUT_DIR="${2:-$ROOT/dist}"
EPOCH="${SOURCE_DATE_EPOCH:-1767225600}" # 2026-01-01T00:00:00Z

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$MAIN" | head -n1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Could not read Version from plugin header" >&2; exit 1; }
# The version constant must match the header (DAF_STUDIO_VERSION / MTL_VERSION).
grep -Eq "define\([[:space:]]*'(DAF_STUDIO_VERSION|MTL_VERSION)'[[:space:]]*,[[:space:]]*'$VERSION'" "$MAIN" \
	|| { echo "Version constant does not match header Version $VERSION" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"

( cd "$PLUGIN_DIR" && tar -cf - --exclude=./tests --exclude=./phpunit.xml.dist --exclude=./.phpunit.result.cache . ) \
	| ( cd "$STAGE/$SLUG" && tar -xf - )

while IFS= read -r -d '' file; do
	php -l "$file" > /dev/null || { echo "Syntax error in $file" >&2; exit 1; }
done < <(find "$STAGE/$SLUG" -name '*.php' -print0)

find "$STAGE/$SLUG" -type d -exec chmod 755 {} +
find "$STAGE/$SLUG" -type f -exec chmod 644 {} +
find "$STAGE/$SLUG" -exec touch -h -d "@$EPOCH" {} +

ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"
( cd "$STAGE" && find "$SLUG" -print | LC_ALL=C sort | TZ=UTC zip -X -q -9 "$ZIP" -@ )
( cd "$OUT_DIR" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
echo "$ZIP"
cat "$ZIP.sha256"
