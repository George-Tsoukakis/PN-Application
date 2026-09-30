#!/usr/bin/env bash
# Smoke test of the BUILT plugin zip (build/build-zip.sh) in a fresh
# WordPress, as a site owner would install it. TEST/DEV tool, not shipped.
# Used by CI (job zip-smoke); runs locally the same way:
#
#   bash build/build-zip.sh && DB_ROOT_PASS=root bash tests/tools/zip-smoke.sh
#
# Checks, and exits 1 on the first failure:
#   1. the zip holds plandose/plandose.php whose Version: header matches the
#      zip's file name;
#   2. `wp plugin install <zip> --activate` succeeds in an empty WordPress
#      (fresh database, no test mu-plugin, no test users, no PLANDOSE_TESTS);
#   3. the plugin is active and `wp plugin get plandose --field=version`
#      is that version;
#   4. the front page and wp-login.php answer 200 with the plugin active;
#   5. `wp plandose check --docroot=<wp>` (stdin not a terminal, so the
#      logged-in cache test is not asked about but reported as WARN) ends
#      with exit status 0 and a «Σύνολο: … 0 FAIL» line. WARN is accepted:
#      a fresh site legitimately has some (no pharmacy account, loopback-only
#      HTTP, no persistent object cache);
#   6. deactivate + `wp plugin uninstall` (uninstall.php) succeed;
#   7. debug.log has no PHP Fatal/Parse error at all, and no PHP
#      Warning/Notice/Deprecated raised in the plugin's own files.
#
# Usage: tests/tools/zip-smoke.sh [path/to/plandose-<version>.zip]
#   (default: the newest build/plandose-*.zip)
# Environment (defaults in brackets):
#   SMOKE_WP_PATH  [<scratch>/pd-zip-smoke-wp]  wiped and reinstalled on every run
#   SMOKE_BASE     [http://127.0.0.1:8997]      php -S is started on its port
#   PD_DB_HOST     [127.0.0.1]  SMOKE_DB_NAME [pdzipsmoke]  SMOKE_DB_USER [pdzipsmoke]  SMOKE_DB_PASS [pdzipsmoke]
#                  (the database is DROPPED and re-created)
#   DB_ROOT_USER   [root]  DB_ROOT_PASS []
#   WP_VERSION     [latest]  WP_CLI_VERSION [2.12.0]
#   WP_CLI_PHAR    []  an existing wp-cli.phar to copy instead of downloading
#   SMOKE_KEEP     [0]  1 = leave WordPress, database and server in place
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
ZIP="${1:-}"
if [ -z "$ZIP" ]; then
	ZIP="$(ls -t "$REPO"/build/plandose-*.zip 2>/dev/null | head -n1 || true)"
fi
[ -n "$ZIP" ] && [ -f "$ZIP" ] || { echo "zip-smoke: no zip (run build/build-zip.sh, or pass the zip)" >&2; exit 1; }
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"

WP_PATH="${SMOKE_WP_PATH:-${TMPDIR:-/tmp}/pd-zip-smoke-wp}"
BASE="${SMOKE_BASE:-http://127.0.0.1:8997}"
PORT="${BASE##*:}"
DB_HOST="${PD_DB_HOST:-127.0.0.1}"
DB_NAME="${SMOKE_DB_NAME:-pdzipsmoke}"
DB_USER="${SMOKE_DB_USER:-pdzipsmoke}"
DB_PASS="${SMOKE_DB_PASS:-pdzipsmoke}"
ROOT_USER="${DB_ROOT_USER:-root}"
ROOT_PASS="${DB_ROOT_PASS:-}"
WP_VERSION="${WP_VERSION:-latest}"
WP_CLI_VERSION="${WP_CLI_VERSION:-2.12.0}"

step() { printf '\n== %s\n' "$*"; }
fail() { echo "ZIP SMOKE FAIL: $*" >&2; exit 1; }
ok() { echo "ok     $*"; }

SERVER_PID=""
cleanup() {
	if [ "${SMOKE_KEEP:-0}" != "1" ]; then
		[ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
	fi
}
trap cleanup EXIT

DB_CLI_HOST="$DB_HOST"
DB_CLI_PORT=""
case "$DB_HOST" in
	*:*) DB_CLI_HOST="${DB_HOST%%:*}"; DB_CLI_PORT="${DB_HOST##*:}" ;;
esac
mysql_root() { mysql -h"$DB_CLI_HOST" ${DB_CLI_PORT:+-P"$DB_CLI_PORT"} -u"$ROOT_USER" ${ROOT_PASS:+-p"$ROOT_PASS"} "$@"; }

# ---- 1. the zip -----------------------------------------------------------------
step "zip: $ZIP"
name_version="$(basename "$ZIP" .zip)"
name_version="${name_version#plandose-}"
header_version="$(unzip -p "$ZIP" plandose/plandose.php | sed -n 's/^[[:space:]/*#@]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n1)"
[ -n "$header_version" ] || fail "no plandose/plandose.php with a Version: header in the zip"
[ "$header_version" = "$name_version" ] || fail "zip name says $name_version, plandose.php says $header_version"
ok "plandose/plandose.php Version: $header_version"
if unzip -Z1 "$ZIP" | grep -v '^plandose/' | grep -q .; then
	fail "the zip holds files outside plandose/: $(unzip -Z1 "$ZIP" | grep -v '^plandose/' | head -n3 | tr '\n' ' ')"
fi
ok "one top-level folder, plandose/"

# ---- 2. a fresh WordPress -----------------------------------------------------------
step "fresh WordPress $WP_VERSION at $WP_PATH ($BASE, database $DB_NAME)"
if [ -f "$WP_PATH/php-server.pid" ]; then
	kill "$(cat "$WP_PATH/php-server.pid")" 2>/dev/null || true
fi
rm -rf "$WP_PATH"
mkdir -p "$WP_PATH"
cd "$WP_PATH"
if [ -n "${WP_CLI_PHAR:-}" ]; then
	cp "$WP_CLI_PHAR" wp-cli.phar
else
	curl -fsSL -o wp-cli.phar "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
fi
wp() { php -d memory_limit=512M "$WP_PATH/wp-cli.phar" --path="$WP_PATH" --allow-root "$@"; }

if ! wp core download --version="$WP_VERSION" --quiet 2>/dev/null; then
	# Same fallback as setup-wp.sh (api.wordpress.org unreachable).
	echo "wp core download failed; trying git (github.com/WordPress/WordPress)"
	tag="$WP_VERSION"
	if [ "$tag" = "latest" ]; then
		tag="$(git ls-remote --tags https://github.com/WordPress/WordPress | sed -n 's|.*refs/tags/\([0-9][0-9.]*\)$|\1|p' | sort -V | tail -n1)"
	fi
	tmp="$(mktemp -d)"
	git -c advice.detachedHead=false clone -q --depth 1 --branch "$tag" https://github.com/WordPress/WordPress "$tmp/wp"
	rm -rf "$tmp/wp/.git"
	cp -a "$tmp/wp/." "$WP_PATH/"
	rm -rf "$tmp"
fi
mysql_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;
	CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4;
	CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
	CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
	GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
	GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
	FLUSH PRIVILEGES;"
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
	--skip-check --force --quiet --extra-php <<PHP
define( 'WP_HOME', '$BASE' );
define( 'WP_SITEURL', '$BASE' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$WP_PATH/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
PHP
wp core install --url="$BASE" --title="PlanDose zip smoke" --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.test --skip-email --quiet
ok "WordPress $(wp core version) installed"
: > "$WP_PATH/debug.log"

PHP_CLI_SERVER_WORKERS=4 nohup php -d display_errors=0 -S "127.0.0.1:$PORT" -t "$WP_PATH" >"$WP_PATH/php-server.log" 2>&1 &
SERVER_PID=$!
echo "$SERVER_PID" > "$WP_PATH/php-server.pid"
for _ in $(seq 1 40); do
	curl -s -o /dev/null -m 5 "$BASE/wp-login.php" && break
	sleep 0.25
done

# ---- 3. install + activate the zip ---------------------------------------------------
step "wp plugin install <zip> --activate"
wp plugin install "$ZIP" --activate || fail "wp plugin install --activate failed"
wp plugin is-active plandose || fail "plandose is not active after install --activate"
ok "plandose is active"
active_version="$(wp plugin get plandose --field=version)"
[ "$active_version" = "$header_version" ] || fail "installed version $active_version != $header_version"
ok "installed version $active_version"
[ ! -L "$WP_PATH/wp-content/plugins/plandose" ] || fail "wp-content/plugins/plandose is a symlink, not the zip's copy"

# ---- 4. the site answers ------------------------------------------------------------------
step "HTTP with the plugin active"
for path in / /wp-login.php; do
	code="$(curl -s -o /dev/null -w '%{http_code}' -m 30 "$BASE$path")"
	[ "$code" = "200" ] || fail "GET $path answered $code"
	ok "GET $path → 200"
done

# ---- 5. wp plandose check -------------------------------------------------------------------
step "wp plandose check --docroot=$WP_PATH (no terminal)"
set +e
wp plandose check --docroot="$WP_PATH" </dev/null >"$WP_PATH/plandose-check.txt" 2>&1
check_status=$?
set -e
cat "$WP_PATH/plandose-check.txt"
summary="$(grep -E 'Σύνολο: [0-9]+ PASS, [0-9]+ WARN, [0-9]+ FAIL' "$WP_PATH/plandose-check.txt" | tail -n1 || true)"
[ -n "$summary" ] || fail "wp plandose check printed no «Σύνολο: …» line (exit $check_status)"
[ "$check_status" -eq 0 ] || fail "wp plandose check exited $check_status ($summary)"
echo "$summary" | grep -q ' 0 FAIL' || fail "wp plandose check reported FAIL ($summary)"
ok "wp plandose check: $summary (WARN accepted)"

# ---- 6. deactivate + uninstall ----------------------------------------------------------------
step "deactivate + uninstall"
wp plugin deactivate plandose || fail "wp plugin deactivate failed"
wp plugin uninstall plandose || fail "wp plugin uninstall failed (uninstall.php)"
[ ! -e "$WP_PATH/wp-content/plugins/plandose" ] || fail "plugin folder still there after uninstall"
ok "deactivated and uninstalled"

# ---- 7. debug.log -----------------------------------------------------------------------
step "debug.log"
log="$WP_PATH/debug.log"
fatal="$(grep -E 'PHP (Fatal|Parse|Recoverable fatal) error' "$log" || true)"
own="$(grep -E 'PHP (Warning|Notice|Deprecated|Strict Standards)' "$log" | grep -E '/plugins/plandose/' || true)"
if [ -n "$fatal$own" ]; then
	printf '%s\n%s\n' "$fatal" "$own" | sed '/^$/d' >&2
	fail "debug.log has PHP errors (above)"
fi
ok "no PHP fatal error, no PHP warning/notice/deprecation from plandose ($(wc -l <"$log") log lines in all)"

echo
echo "ZIP SMOKE OK: plandose $header_version on WordPress $(wp core version)"
if [ "${SMOKE_KEEP:-0}" != "1" ]; then
	mysql_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" || true
fi
