#!/usr/bin/env bash
# Sets up the throw-away WordPress the wp-integration tests expect
# (tests/README.md). Used by CI; also reproduces /home/claude/wpenv.
# MariaDB/MySQL must already run and accept root (or DB_ROOT_*) logins.
#
# Environment (defaults in brackets):
#   PD_WP_PATH      [/home/claude/wpenv]   where WordPress goes
#   PD_BASE         [http://127.0.0.1:8899]
#   PD_DB_HOST      [127.0.0.1]  PD_DB_NAME [wptest]  PD_DB_USER [wp]  PD_DB_PASS [wp]  PD_DB_PREFIX [wp_]
#   DB_ROOT_USER    [root]  DB_ROOT_PASS []   (to create the database and user)
#   WP_VERSION      [latest]  a release tag; fetched with wp-cli, or from github.com/WordPress/WordPress by git
#   WP_CLI_VERSION  [2.12.0]
#   PLUGIN_DIR      [<repo>/plandose]
#   START_SERVER    [1]  start `php -S` on PD_BASE's port
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS="$(cd "$HERE/.." && pwd)"
WP_PATH="${PD_WP_PATH:-/home/claude/wpenv}"
BASE="${PD_BASE:-http://127.0.0.1:8899}"
PORT="${BASE##*:}"
DB_HOST="${PD_DB_HOST:-127.0.0.1}"
DB_NAME="${PD_DB_NAME:-wptest}"
DB_USER="${PD_DB_USER:-wp}"
DB_PASS="${PD_DB_PASS:-wp}"
DB_PREFIX="${PD_DB_PREFIX:-wp_}"
ROOT_USER="${DB_ROOT_USER:-root}"
ROOT_PASS="${DB_ROOT_PASS:-}"
WP_VERSION="${WP_VERSION:-latest}"
WP_CLI_VERSION="${WP_CLI_VERSION:-2.12.0}"
PLUGIN_DIR="$(cd "${PLUGIN_DIR:-$TESTS/../plandose}" && pwd)"

mkdir -p "$WP_PATH"
cd "$WP_PATH"

# ---- wp-cli --------------------------------------------------------------------
if [ ! -f wp-cli.phar ]; then
	curl -fsSL -o wp-cli.phar "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
fi
cat > wp <<EOF
#!/bin/sh
exec php -d memory_limit=512M "$WP_PATH/wp-cli.phar" --path="$WP_PATH" --allow-root "\$@"
EOF
chmod +x wp

# ---- WordPress core --------------------------------------------------------------
if [ ! -f wp-load.php ]; then
	if ! ./wp core download --version="$WP_VERSION" 2>/dev/null; then
		echo "wp core download failed; trying git (github.com/WordPress/WordPress)"
		tag="$WP_VERSION"
		if [ "$tag" = "latest" ]; then
			tag="$(git ls-remote --tags https://github.com/WordPress/WordPress | sed -n 's|.*refs/tags/\([0-9][0-9.]*\)$|\1|p' | sort -V | tail -n1)"
		fi
		tmp="$(mktemp -d)"
		git clone -q --depth 1 --branch "$tag" https://github.com/WordPress/WordPress "$tmp/wp"
		rm -rf "$tmp/wp/.git"
		cp -a "$tmp/wp/." "$WP_PATH/"
		rm -rf "$tmp"
	fi
fi

# ---- database -----------------------------------------------------------------------
mysql_root() { mysql -h"$DB_HOST" -u"$ROOT_USER" ${ROOT_PASS:+-p"$ROOT_PASS"} "$@"; }
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
	CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
	CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
	GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
	GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
	FLUSH PRIVILEGES;"

# ---- wp-config + install ------------------------------------------------------------
if [ ! -f wp-config.php ]; then
	./wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
		--dbprefix="$DB_PREFIX" --skip-check --force --extra-php <<PHP
define( 'WP_HOME', '$BASE' );
define( 'WP_SITEURL', '$BASE' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'PLANDOSE_TESTS', true );
define( 'PLANDOSE_TEST_USERS', 'pharm1,pharmpro,pdt_*' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$WP_PATH/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
PHP
fi
if ! ./wp core is-installed 2>/dev/null; then
	./wp core install --url="$BASE" --title="PlanDose Test" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.test --skip-email
fi
./wp option update siteurl "$BASE" >/dev/null
./wp option update home "$BASE" >/dev/null

# ---- plugin, mu-plugin, users ---------------------------------------------------------
mkdir -p wp-content/plugins wp-content/mu-plugins
ln -sfn "$PLUGIN_DIR" wp-content/plugins/plandose
ln -sfn "$TESTS/wp-integration/pd-test-mu.php" wp-content/mu-plugins/pd-test-mu.php
./wp plugin activate plandose

for u in pharm1 pharmpro; do
	./wp user get "$u" >/dev/null 2>&1 || ./wp user create "$u" "$u@example.test" --user_pass=pharmpass --role=subscriber >/dev/null
	./wp user meta update "$u" account_type 'Φαρμακείο' >/dev/null
done
./wp eval '$u = get_user_by( "login", "pharmpro" ); if ( ! Plandose_Subscriptions::user_is_pro( $u->ID ) ) { Plandose_Subscriptions::grant_pro_days_if_unchanged( $u->ID, 365, Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row( $u->ID ) ) ); } echo Plandose_Subscriptions::user_is_pro( $u->ID ) ? "pharmpro is Pro\n" : "pharmpro NOT Pro\n";'

# ---- server -----------------------------------------------------------------------------
if [ "${START_SERVER:-1}" = "1" ] && ! curl -s -o /dev/null -m 5 "$BASE/wp-login.php"; then
	PHP_CLI_SERVER_WORKERS=8 nohup php -d display_errors=0 -S "127.0.0.1:$PORT" -t "$WP_PATH" >"$WP_PATH/php-server.log" 2>&1 &
	for _ in $(seq 1 40); do
		curl -s -o /dev/null -m 5 "$BASE/wp-login.php" && break
		sleep 0.25
	done
fi
echo "WordPress ready at $BASE ($WP_PATH)"
