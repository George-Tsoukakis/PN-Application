#!/bin/sh
# PlanDose PHP tests against the test WordPress (TEST-ONLY).
#   tests/php/run.sh                 all tests
#   WP_LOAD=/path/wp-load.php tests/php/run.sh
# Runs php/test-*.php and then the php/admin checks exactly as CI does
# (php admin/storage-admin-1270.php "$WP_LOAD"; node --test
# --test-concurrency=1 php/admin/diagnostics-http-1270.test.js from tests/).
# PD_SKIP_ADMIN=1 skips the admin checks (e.g. where CI runs them itself).
# PD_WP_PATH defaults to the folder of WP_LOAD (the HTTP check needs wp-cli).
# Exit status: 0 all passed, 1 a test failed, 2 no WordPress found.
cd "$(dirname "$0")" || exit 2
: "${WP_LOAD:=/home/claude/wpenv/wp-load.php}"
export WP_LOAD
if [ ! -r "$WP_LOAD" ]; then
	echo "SKIP: no WordPress at $WP_LOAD (set WP_LOAD)"
	exit 2
fi
: "${PD_WP_PATH:=$(dirname "$WP_LOAD")}"
export PD_WP_PATH
status=0
for t in test-*.php; do
	echo "== $t"
	php "$t" || status=1
done
if [ "${PD_SKIP_ADMIN:-0}" != "1" ]; then
	echo "== admin/storage-admin-1270.php"
	php admin/storage-admin-1270.php "$WP_LOAD" || status=1
	echo "== admin/diagnostics-http-1270.test.js"
	if command -v node >/dev/null 2>&1; then
		(cd .. && node --test --test-concurrency=1 php/admin/diagnostics-http-1270.test.js) || status=1
	else
		echo "FAIL: node not found (needed for php/admin/diagnostics-http-1270.test.js)"
		status=1
	fi
fi
echo
[ "$status" -eq 0 ] && echo "PHP tests: all passed" || echo "PHP tests: FAILURES"
exit $status
