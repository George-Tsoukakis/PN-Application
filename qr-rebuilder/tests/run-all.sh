#!/bin/sh
# QR ReBuilder Pro — όλα τα PHP regression scripts. PDIR=/path/to/qr-rebuilder-pro
#
# 2.15.4: tests λογικής τρέχουν με ψεύτικο renderer (χωρίς GD). Τα integration
# tests πραγματικού DataMatrix (t_rebuild, t_datamatrix_gd) βγαίνουν SKIP όταν
# λείπει το GD — όχι FAIL. Προσομοίωση server χωρίς GD (Debian/Ubuntu):
#   NOGD=1 ./run-all.sh
cd "$(dirname "$0")" || exit 1
mkdir -p tmpmail

PHP=${PHP:-php}
# 2.15.4: PDIR προαιρετικό — αλλιώς ο φάκελος qr-rebuilder-pro δίπλα στα tests.
if [ -z "${PDIR:-}" ]; then
	for d in ../qr-rebuilder-pro ./qr-rebuilder-pro; do
		[ -f "$d/qr-rebuilder-pro.php" ] && PDIR=$(cd "$d" && pwd) && break
	done
fi
if [ -z "${PDIR:-}" ] || [ ! -f "$PDIR/qr-rebuilder-pro.php" ]; then
	echo "Δεν βρέθηκε το plugin. Βάλτε τον φάκελο qr-rebuilder-pro δίπλα στον φάκελο των tests, ή ορίστε PDIR=/path/to/qr-rebuilder-pro" >&2
	exit 2
fi
export PDIR
if [ "${NOGD:-0}" = "1" ]; then
	scan=$($PHP --ini | sed -n 's/^Scan for additional .ini files in: *//p')
	tmp=$(mktemp -d)
	cp "$scan"/*.ini "$tmp"/ 2>/dev/null
	rm -f "$tmp"/*-gd.ini
	PHP_INI_SCAN_DIR=$tmp
	export PHP_INI_SCAN_DIR
fi

echo "Plugin: $PDIR"
echo "PHP $($PHP -r 'echo PHP_VERSION;')  GD: $($PHP -r 'echo extension_loaded("gd") ? "yes" : "no";')"

fail=0
for t in t_rebuild t_race t_mailer t_greek_sigma t_expiry_dd00 t_hri_split t_sigma_perf t_guest t_tokens_limiter t_storage_longrun t_token_sweep_edges t_admin t_admin_webmail t_access_2155 t_vendor_check t_datamatrix_gd t_mixed_separators; do
	out=$($PHP "$t.php" 2>&1)
	rc=$?
	# 2.15.7: και PHP Warning/Notice/Deprecated μετρούν ως αποτυχία.
	f=$(printf '%s\n' "$out" | grep -c -E '^FAIL( |$)|Fatal error|^(PHP )?(Warning|Notice|Deprecated):')
	p=$(printf '%s\n' "$out" | grep -c '^PASS')
	s=$(printf '%s\n' "$out" | grep -c '^SKIP')
	if [ "$s" -gt 0 ] && [ "$p" -eq 0 ] && [ "$f" -eq 0 ] && [ "$rc" -eq 0 ]; then
		printf '%-20s SKIP  %s\n' "$t" "$(printf '%s\n' "$out" | grep '^SKIP' | head -1 | cut -d: -f2-)"
		continue
	fi
	printf '%-20s pass=%s fail=%s\n' "$t" "$p" "$f"
	# 2.15.5: αποτυχία και όταν το script δεν τυπώνει κανένα PASS (π.χ. warning
	# πριν από τα checks) ή τερματίζει με κωδικό ≠ 0 χωρίς γραμμή FAIL.
	if [ "$f" -ne 0 ] || [ "$p" -eq 0 ] || [ "$rc" -ne 0 ]; then
		fail=1
		[ "$p" -eq 0 ] && echo "  (κανένα PASS)"
		[ "$rc" -ne 0 ] && echo "  (exit code $rc)"
		printf '%s\n' "$out" | grep -E '^FAIL|Fatal|Warning|Deprecated' | head -20
	fi
done
$PHP golden.php 2>/dev/null > golden-out.json
if cmp -s golden-out.json golden-2.15.7.json; then echo "golden               identical to golden-2.15.7.json"; else echo "golden               DIFFERS"; fail=1; fi
rm -f golden-out.json
$PHP bench.php
[ -n "${tmp:-}" ] && rm -rf "$tmp"
exit $fail
