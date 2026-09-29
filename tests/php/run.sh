#!/bin/sh
# PlanDose PHP tests against the test WordPress (TEST-ONLY).
#   tests/php/run.sh                 all tests
#   WP_LOAD=/path/wp-load.php tests/php/run.sh
# Exit status: 0 all passed, 1 a test failed, 2 no WordPress found.
cd "$(dirname "$0")" || exit 2
: "${WP_LOAD:=/home/claude/wpenv/wp-load.php}"
export WP_LOAD
if [ ! -r "$WP_LOAD" ]; then
	echo "SKIP: no WordPress at $WP_LOAD (set WP_LOAD)"
	exit 2
fi
status=0
for t in test-*.php; do
	echo "== $t"
	php "$t" || status=1
done
echo
[ "$status" -eq 0 ] && echo "PHP tests: all passed" || echo "PHP tests: FAILURES"
exit $status
