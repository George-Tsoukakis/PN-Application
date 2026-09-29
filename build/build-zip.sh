#!/usr/bin/env bash
# Builds build/plandose-<version>.zip from plandose/ (repo layout: plandose/,
# tests/, build/). The zip holds one folder, plandose/.
#
# Checks first, and stops on any failure:
#   - the version in the plandose.php header = PLANDOSE_VERSION = readme.txt
#     "Stable tag" = the top "## <version>" entry of CHANGELOG.md;
#   - php -l on every PHP file;
#   - no test/development file would be packaged.
# .DS_Store, node_modules/ and *.orig are left out silently.
#
# Usage: build/build-zip.sh [--skip-version-check] [--plugin-dir DIR] [--out-dir DIR]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$HERE/../plandose"
OUT_DIR="$HERE"
SKIP_VERSION=0

while [ $# -gt 0 ]; do
	case "$1" in
		--skip-version-check) SKIP_VERSION=1 ;;
		--plugin-dir) PLUGIN_DIR="$2"; shift ;;
		--out-dir) OUT_DIR="$2"; shift ;;
		-h|--help) sed -n '2,13p' "$0"; exit 0 ;;
		*) echo "Unknown option: $1" >&2; exit 1 ;;
	esac
	shift
done

PLUGIN_DIR="$(cd "$PLUGIN_DIR" && pwd)"
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
fail() { echo "ERROR: $*" >&2; exit 1; }

[ -f "$PLUGIN_DIR/plandose.php" ] || fail "no plandose.php in $PLUGIN_DIR"

# ---- versions ---------------------------------------------------------------
header_version="$(sed -n 's/^[[:space:]/*#@]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$PLUGIN_DIR/plandose.php" | head -n1)"
const_version="$(sed -n "s/.*define([[:space:]]*'PLANDOSE_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" "$PLUGIN_DIR/plandose.php" | head -n1)"
readme_version="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$PLUGIN_DIR/readme.txt" 2>/dev/null | head -n1)"
changelog_version="$(sed -n 's/^##[[:space:]]*\([0-9][0-9.]*[^[:space:]]*\).*/\1/p' "$PLUGIN_DIR/CHANGELOG.md" 2>/dev/null | head -n1)"

[ -n "$header_version" ] || fail "no Version: in the plandose.php header"
[[ "$header_version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?([-.][0-9A-Za-z.]+)?$ ]] || fail "odd version: $header_version"

echo "Version: header $header_version, PLANDOSE_VERSION ${const_version:-?}, readme ${readme_version:-?}, CHANGELOG ${changelog_version:-?}"
if [ "$SKIP_VERSION" -eq 0 ]; then
	bad=0
	[ "$const_version" = "$header_version" ] || { echo "  PLANDOSE_VERSION ($const_version) != header ($header_version)" >&2; bad=1; }
	[ "$readme_version" = "$header_version" ] || { echo "  readme.txt Stable tag ($readme_version) != header ($header_version)" >&2; bad=1; }
	[ "$changelog_version" = "$header_version" ] || { echo "  CHANGELOG.md top entry ($changelog_version) != header ($header_version)" >&2; bad=1; }
	[ "$bad" -eq 0 ] || fail "version mismatch (use --skip-version-check only for local test builds)"
else
	echo "  (version consistency check skipped)"
fi

# ---- files that go into the zip ----------------------------------------------
# Paths relative to PLUGIN_DIR, without the silently excluded ones.
mapfile -t FILES < <(cd "$PLUGIN_DIR" && find . \
	\( -name node_modules -prune \) -o \
	\( -type f ! -name '.DS_Store' ! -name '*.orig' -print \) | sed 's|^\./||' | LC_ALL=C sort)
[ "${#FILES[@]}" -gt 0 ] || fail "nothing to package"

# ---- no test / development files -----------------------------------------------
DEV_PATTERN='(^|/)(tests?|wp-integration|rx-corpus|rx-samples|i18n-requests|visual|\.github|\.git|\.vscode|\.idea|bin)(/|$)|\.(py|pyc|mjs)$|\.test\.(js|php)$|\.spec\.(js|ts)$|(^|/)(package(-lock)?\.json|composer\.(json|lock)|phpunit[^/]*\.xml(\.dist)?|\.?phpcs[^/]*\.xml(\.dist)?|\.editorconfig|\.gitignore|\.gitattributes|\.env[^/]*|pd-test-mu\.php|harness\.js|debug\.log|Makefile|Gruntfile\.js|webpack\.config\.js)$|\.(rej|bak|swp|swo|tmp|log|map|zip|tar|gz|pot\.check)$|~$'
dev=()
for f in "${FILES[@]}"; do
	if [[ "$f" =~ $DEV_PATTERN ]]; then
		dev+=("$f")
	fi
done
if [ "${#dev[@]}" -gt 0 ]; then
	printf '  %s\n' "${dev[@]}" >&2
	fail "test/development files would be packaged (above)"
fi

# ---- php -l --------------------------------------------------------------------
lint_fail=0
for f in "${FILES[@]}"; do
	case "$f" in
		*.php)
			if ! out="$(php -l "$PLUGIN_DIR/$f" 2>&1)"; then
				echo "$out" >&2
				lint_fail=1
			fi
			;;
	esac
done
[ "$lint_fail" -eq 0 ] || fail "php -l failed"
echo "php -l: OK"

# ---- zip -----------------------------------------------------------------------
ZIP="$OUT_DIR/plandose-$header_version.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/plandose"
(cd "$PLUGIN_DIR" && printf '%s\n' "${FILES[@]}" | while IFS= read -r f; do
	mkdir -p "$STAGE/plandose/$(dirname "$f")"
	cp -p "$f" "$STAGE/plandose/$f"
done)
rm -f "$ZIP"
(cd "$STAGE" && zip -q -X -r "$ZIP" plandose)

count="$(unzip -Z1 "$ZIP" | grep -vc '/$')"
[ "$count" -eq "${#FILES[@]}" ] || fail "zip holds $count files, expected ${#FILES[@]}"
unzip -Z1 "$ZIP" | grep -qv '^plandose/' && fail "zip has entries outside plandose/"
echo "Built $ZIP ($count files, $(du -h "$ZIP" | cut -f1))"
