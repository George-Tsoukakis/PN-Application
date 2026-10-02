#!/usr/bin/env bash
# Throw-away WordPress with PN Chat active, for the integration tests.
# MariaDB/MySQL must already run and accept root (or DB_ROOT_*) logins.
#
# Environment (defaults in brackets):
#   PNCHAT_WP_PATH [/tmp/pnchat-wp]   PNCHAT_BASE [http://127.0.0.1:8898]
#   DB_HOST [127.0.0.1]  DB_NAME [pnchat_test]  DB_ROOT_USER [root]  DB_ROOT_PASS []
#   WP_VERSION [latest]  WP_CLI_VERSION [2.12.0]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../pn-chat" && pwd)"
WP_PATH="${PNCHAT_WP_PATH:-/tmp/pnchat-wp}"
BASE="${PNCHAT_BASE:-http://127.0.0.1:8898}"
PORT="${BASE##*:}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-pnchat_test}"
ROOT_USER="${DB_ROOT_USER:-root}"
ROOT_PASS="${DB_ROOT_PASS:-}"
WP_VERSION="${WP_VERSION:-latest}"
WP_CLI_VERSION="${WP_CLI_VERSION:-2.12.0}"

mkdir -p "$WP_PATH"
cd "$WP_PATH"
if [ ! -f wp-cli.phar ]; then
	curl -fsSL -o wp-cli.phar "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
fi
cat > wp <<SH
#!/bin/sh
exec php -d memory_limit=512M "$WP_PATH/wp-cli.phar" --path="$WP_PATH" --allow-root "\$@"
SH
chmod +x wp

if [ ! -f wp-load.php ] && ! ./wp core download --version="$WP_VERSION" 2>/dev/null; then
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

mysql -h"$DB_HOST" -u"$ROOT_USER" ${ROOT_PASS:+-p"$ROOT_PASS"} -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
	CREATE USER IF NOT EXISTS 'pnchat'@'%' IDENTIFIED BY 'pnchat';
	CREATE USER IF NOT EXISTS 'pnchat'@'localhost' IDENTIFIED BY 'pnchat';
	GRANT ALL ON \`$DB_NAME\`.* TO 'pnchat'@'%';
	GRANT ALL ON \`$DB_NAME\`.* TO 'pnchat'@'localhost';
	FLUSH PRIVILEGES;"

if [ ! -f wp-config.php ]; then
	./wp config create --dbname="$DB_NAME" --dbuser=pnchat --dbpass=pnchat --dbhost="$DB_HOST" --skip-check --force --extra-php <<PHP
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$WP_PATH/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
PHP
fi
./wp core is-installed 2>/dev/null || ./wp core install --url="$BASE" --title="PN Chat Test" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
./wp option update siteurl "$BASE" >/dev/null
./wp option update home "$BASE" >/dev/null
./wp user get pharm1 >/dev/null 2>&1 || ./wp user create pharm1 pharm1@example.test --user_pass=pharmpass --role=subscriber >/dev/null

# Mail goes to a file instead of a mail server.
mkdir -p wp-content/mu-plugins wp-content/plugins
cat > wp-content/mu-plugins/pnchat-test-mail.php <<'PHP'
<?php
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	file_put_contents( WP_CONTENT_DIR . '/mail.log', wp_json_encode( $atts, JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
PHP

ln -sfn "$PLUGIN_DIR" wp-content/plugins/pn-chat
./wp plugin activate pn-chat

if ! curl -s -o /dev/null -m 5 "$BASE/wp-login.php"; then
	PHP_CLI_SERVER_WORKERS=4 nohup php -d display_errors=0 -S "127.0.0.1:$PORT" -t "$WP_PATH" >"$WP_PATH/php-server.log" 2>&1 &
	for _ in $(seq 1 40); do
		curl -s -o /dev/null -m 5 "$BASE/wp-login.php" && break
		sleep 0.25
	done
fi
echo "WordPress ready at $BASE ($WP_PATH)"
