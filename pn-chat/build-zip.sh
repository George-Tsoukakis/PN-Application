#!/usr/bin/env bash
# Builds pn-chat/build/pn-chat-<version>.zip from pn-chat/pn-chat/ (one folder
# "pn-chat/" inside the zip, ready for Plugins → Add New → Upload).
# Checks first: header Version = PNCHAT_VERSION = readme Stable tag = top
# CHANGELOG entry, and php -l on every PHP file.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$HERE/pn-chat"
OUT_DIR="${1:-$HERE/build}"
fail() { echo "ERROR: $*" >&2; exit 1; }

header="$(sed -n 's/^[[:space:]/*#@]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$PLUGIN_DIR/pn-chat.php" | head -n1)"
const="$(sed -n "s/.*define([[:space:]]*'PNCHAT_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" "$PLUGIN_DIR/pn-chat.php" | head -n1)"
readme="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$PLUGIN_DIR/readme.txt" | head -n1)"
changelog="$(sed -n 's/^##[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "$PLUGIN_DIR/CHANGELOG.md" | head -n1)"
echo "Version: header $header, PNCHAT_VERSION $const, readme $readme, CHANGELOG $changelog"
[ -n "$header" ] && [ "$header" = "$const" ] && [ "$header" = "$readme" ] && [ "$header" = "$changelog" ] || fail "version mismatch"

while IFS= read -r -d '' f; do
	php -l "$f" >/dev/null || { php -l "$f"; fail "php -l failed"; }
done < <(find "$PLUGIN_DIR" -name '*.php' -print0)

mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
ZIP="$OUT_DIR/pn-chat-$header.zip"
rm -f "$ZIP"
(cd "$HERE" && zip -q -X -r "$ZIP" pn-chat -x '*.DS_Store' '*/node_modules/*' '*.orig')
unzip -Z1 "$ZIP" | grep -qv '^pn-chat/' && fail "zip has entries outside pn-chat/"
echo "Built $ZIP ($(unzip -Z1 "$ZIP" | grep -vc '/$') files)"
