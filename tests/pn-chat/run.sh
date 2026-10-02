#!/bin/sh
# PN Chat tests (TEST-ONLY), against a throw-away WordPress with PN Chat
# active (they change its PN Chat data):
#   WP_LOAD=/path/wp-load.php tests/pn-chat/run.sh
#   PN_BASE=http://127.0.0.1:8898  also runs the Chromium checks
#                                  (the site served there, playwright-core in tests/node_modules)
# Exit status: 0 all passed, 1 a test failed, 2 no WordPress found.
cd "$(dirname "$0")" || exit 2
status=0
echo "== test-text-alone.php"
php test-text-alone.php || status=1
: "${WP_LOAD:=/home/claude/wpenv/wp-load.php}"
export WP_LOAD
if [ ! -r "$WP_LOAD" ]; then
	echo "SKIP: no WordPress at $WP_LOAD (set WP_LOAD)"
	exit 2
fi
for t in test-*-1[0-9][0-9]0.php; do
	echo "== $t"
	php "$t" || status=1
done
if [ -n "${PN_BASE:-}" ]; then
	echo "== browser-1800.mjs"
	(cd .. && node pn-chat/browser-1800.mjs) || status=1
fi
exit $status
