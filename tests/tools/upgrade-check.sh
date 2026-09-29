#!/usr/bin/env bash
# Upgrade check: a throw-away WordPress with the plugin at an older commit
# (default: the "baseline" commit), a pharmacy with a few prints, then the
# plugin files switched to the working tree and a page loaded. Verifies the
# schema upgrade (InnoDB, requests.replay_count), that the data are kept,
# that printing and the bounded replay work afterwards, and that debug.log
# has no PHP notice/warning/error from PlanDose. Cleans up (database, files,
# server) at the end unless KEEP=1. TEST-ONLY.
#
# Usage: tests/tools/upgrade-check.sh [<old-commit>]
# Env:   UP_PORT [8897]  UP_DB [wptest_up]  UP_DIR [mktemp]  DB_ROOT_* as setup-wp.sh
#        WP_SRC  [/home/claude/wpenv]  a WordPress install to copy core files from
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS="$(cd "$HERE/.." && pwd)"
REPO="$(cd "$TESTS/.." && pwd)"
OLD="${1:-$(git -C "$REPO" log --format=%h --grep='^baseline' | tail -n1)}"
PORT="${UP_PORT:-8897}"
DB="${UP_DB:-wptest_up}"
DIR="${UP_DIR:-$(mktemp -d)}"
WP_SRC="${WP_SRC:-/home/claude/wpenv}"
BASE="http://127.0.0.1:$PORT"
U="$DIR/wp"
OLD_PLUGIN="$DIR/old"
fails=0
check() { if eval "$2"; then echo "ok     $1"; else echo "NOT OK $1"; fails=$((fails + 1)); fi; }
mysql_root() { mysql -u"${DB_ROOT_USER:-root}" ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} "$@"; }
Q() { mysql_root -N "$DB" -e "$1"; }
server_stop() { ps -eo pid,args | awk -v p="127.0.0.1:$PORT" '$2=="php" && index($0, p) {print $1}' | xargs -r kill; sleep 0.5; }
server_start() {
	# A fresh server after every switch: long-lived workers keep resolving the
	# old symlink target from PHP's realpath cache.
	server_stop
	(cd "$U" && PHP_CLI_SERVER_WORKERS=4 nohup php -d display_errors=0 -S "127.0.0.1:$PORT" -t "$U" >"$U/php-server.log" 2>&1 &)
	for _ in $(seq 1 40); do curl -s -o /dev/null -m 5 "$BASE/wp-login.php" && return 0; sleep 0.25; done
	return 1
}
cleanup() {
	server_stop
	if [ "${KEEP:-0}" != "1" ]; then
		mysql_root -e "DROP DATABASE IF EXISTS \`$DB\`" || true
		rm -rf "$DIR"
	fi
}
trap cleanup EXIT

echo "Upgrade check: $OLD → working tree ($DIR, db $DB, $BASE)"
mkdir -p "$U" "$OLD_PLUGIN"
git -C "$REPO" archive "$OLD" plandose | tar -x -C "$OLD_PLUGIN"
old_version="$(sed -n 's/^[[:space:]/*#@]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$OLD_PLUGIN/plandose/plandose.php" | head -n1)"
new_version="$(sed -n 's/^[[:space:]/*#@]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$REPO/plandose/plandose.php" | head -n1)"
rsync -a --exclude wp-config.php --exclude '*.log' --exclude wp-content/plugins/plandose --exclude wp-content/mu-plugins \
	--exclude READY --exclude start.sh "$WP_SRC/" "$U/"
mysql_root -e "DROP DATABASE IF EXISTS \`$DB\`"
PD_WP_PATH="$U" PD_BASE="$BASE" PD_DB_HOST="${PD_DB_HOST:-localhost}" PD_DB_NAME="$DB" PLUGIN_DIR="$OLD_PLUGIN/plandose" \
	START_SERVER=0 "$HERE/setup-wp.sh" >/dev/null
server_start
check "old plugin $old_version active" '[ "$("$U/wp" option get plandose_version)" = "$old_version" ]'

export PD_BASE="$BASE" PD_WP_PATH="$U"
node -e '
const { WpClient, id } = require(process.argv[1] + "/lib/wp-client.js");
(async () => {
	const a = new WpClient("free"); await a.login();
	const t = id();
	for (const [tok, rid] of [[t, id()], [t, id()], [id(), id()]]) {
		const r = await a.registerPrint(tok, rid);
		if (!r.json || !r.json.success) { throw new Error(r.text.slice(0, 200)); }
	}
	const b = new WpClient("pro"); await b.login();
	const r = await b.registerPrint(id(), id());
	if (!r.json || !r.json.success) { throw new Error(r.text.slice(0, 200)); }
})().catch((e) => { console.error(e.message); process.exit(1); });' "$TESTS"
before_subs="$(Q "SELECT user_id,status,print_count,IFNULL(sub_end_date,'') FROM wp_plandose_subscriptions ORDER BY user_id")"
before_charges="$(Q "SELECT user_id,token_hash,charged_ts,reprints FROM wp_plandose_print_charges ORDER BY id")"
before_requests="$(Q "SELECT user_id,request_hash,token_hash,kind,created_ts FROM wp_plandose_print_requests ORDER BY id")"
check "prints recorded on $old_version (3 charges/reprint rows + subscriptions)" '[ "$(echo "$before_requests" | wc -l)" -eq 4 ]'

# ---- switch to the working tree -------------------------------------------------
: > "$U/debug.log"
server_stop
ln -sfn "$REPO/plandose" "$U/wp-content/plugins/plandose"
server_start
check "front page loads after the switch" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/")" = "200" ]'
check "plandose_version upgraded to $new_version" '[ "$("$U/wp" option get plandose_version)" = "$new_version" ]'
check "all PlanDose tables InnoDB" '[ -z "$(Q "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '"'"'wp\_plandose%'"'"' AND engine <> '"'"'InnoDB'"'"'")" ]'
check "requests.replay_count added" 'Q "SHOW COLUMNS FROM wp_plandose_print_requests LIKE '"'"'replay_count'"'"'" | grep -q replay_count'
check "subscriptions kept" '[ "$(Q "SELECT user_id,status,print_count,IFNULL(sub_end_date,'"'"''"'"') FROM wp_plandose_subscriptions ORDER BY user_id")" = "$before_subs" ]'
check "charge rows kept" '[ "$(Q "SELECT user_id,token_hash,charged_ts,reprints FROM wp_plandose_print_charges ORDER BY id")" = "$before_charges" ]'
check "request rows kept" '[ "$(Q "SELECT user_id,request_hash,token_hash,kind,created_ts FROM wp_plandose_print_requests ORDER BY id")" = "$before_requests" ]'

check "printing and bounded replay work after the upgrade" 'node -e '"'"'
const { WpClient, id } = require(process.argv[1] + "/lib/wp-client.js");
(async () => {
	const a = new WpClient("free"); await a.login();
	const t = id(), r = id();
	const codes = [];
	for (let i = 0; i < 4; i++) { codes.push((await a.registerPrint(t, r)).status); }
	if (codes.join() !== "200,200,200,409") { throw new Error("statuses " + codes.join()); }
})().catch((e) => { console.error(e.message); process.exit(1); });'"'"' "$TESTS"'

# ---- a legacy MyISAM table is converted on the next upgrade run ---------------------
Q "ALTER TABLE wp_plandose_print_charges ENGINE=MyISAM"
"$U/wp" option update plandose_version "$old_version" >/dev/null
curl -s -o /dev/null "$BASE/"
check "MyISAM print_charges converted back to InnoDB" '[ "$(Q "SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='"'"'wp_plandose_print_charges'"'"'")" = "InnoDB" ]'

check "debug.log: no PHP notice/warning/error from PlanDose" '! grep -E "PHP (Notice|Warning|Deprecated|Fatal|Parse)" "$U/debug.log" | grep -qi plandose'
grep -E "PHP (Notice|Warning|Deprecated|Fatal|Parse)" "$U/debug.log" | grep -vi plandose | sed 's/^/       (not PlanDose) /' | cut -c1-200 || true

echo
[ "$fails" -eq 0 ] && echo "UPGRADE $old_version → $new_version: ALL OK" || echo "UPGRADE: FAILED $fails"
exit $([ "$fails" -eq 0 ] && echo 0 || echo 1)
