#!/usr/bin/env bash
# ZIP install / activation / read-only / WP-CLI / deactivation / uninstall-preserve smoke test on a
# disposable local WordPress site.
#
#   tests/e2e/smoke-zip.sh <wp-version> <port> [zip]
#
# Optional: POLYLANG_DIR=<built Polylang plugin dir> to run with the real Polylang plugin.
# Writes tests/e2e/artifacts/smoke-<version>.json and exits non-zero on the first failed check.
set -uo pipefail

V="$1"
PORT="$2"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${3:-$(ls -1 "$ROOT"/dist/semantic-seo-engine-vi-en-*.zip | head -1)}"
SITES="${SITES_DIR:-/tmp/sseo-sites}"
DIR="$SITES/wp-$V"
URL="http://127.0.0.1:$PORT"
WP="wp --allow-root --path=$DIR"
OUT="$ROOT/tests/e2e/artifacts"
mkdir -p "$OUT" "$SITES"
RESULTS=()
FAILED=0

check() { # name, condition-result (0 = pass), detail
	local name="$1" rc="$2" detail="${3:-}"
	if [ "$rc" -eq 0 ]; then
		echo "PASS $name ${detail:+— $detail}"
		RESULTS+=("{\"check\":$(printf '%s' "$name" | php -r 'echo json_encode(stream_get_contents(STDIN));'),\"ok\":true,\"detail\":$(printf '%s' "$detail" | php -r 'echo json_encode(stream_get_contents(STDIN));')}")
	else
		echo "FAIL $name ${detail:+— $detail}"
		RESULTS+=("{\"check\":$(printf '%s' "$name" | php -r 'echo json_encode(stream_get_contents(STDIN));'),\"ok\":false,\"detail\":$(printf '%s' "$detail" | php -r 'echo json_encode(stream_get_contents(STDIN));')}")
		FAILED=$((FAILED + 1))
	fi
}

valid_snapshot() { # fails unless the argument is a complete snapshot
	printf '%s' "$1" | php -r '$s=json_decode(stream_get_contents(STDIN),true); exit(is_array($s) && isset($s["posts"],$s["options"],$s["rewrite_rules"]) ? 0 : 1);'
}

frontend() { # prints sha256 per public URL
	for path in / /thue-xe-may-ha-noi/ "${EN_PREFIX:-}/motorbike-rental-hanoi/" /category/thue-xe-may/ /lien-he/ '/?s=xe+may' /feed/; do
		printf '%s %s\n' "$path" "$(curl -fsS "$URL$path" | sha256sum | cut -c1-16)"
	done
}

"$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
check "disposable WordPress $V site created" $? "$($WP core version)"

if [ -n "${POLYLANG_DIR:-}" ] && [ -d "$POLYLANG_DIR" ]; then
	cp -R "$POLYLANG_DIR" "$DIR/wp-content/plugins/polylang"
	$WP plugin activate polylang >/dev/null 2>&1
	check "real Polylang activated" $? "$($WP plugin get polylang --field=version 2>/dev/null)"
	EN_PREFIX=/en
fi
MAP="$($WP eval-file "$HERE/load-corpus.php" 2>/dev/null)"
echo "$MAP" > "$DIR/.corpus-map.json"
$WP rewrite flush >/dev/null 2>&1
check "fixture corpus loaded" $? "$(echo "$MAP" | php -r '$m=json_decode(stream_get_contents(STDIN),true); echo is_array($m)?count($m)." posts":"none";')"

: > "$DIR/wp-content/debug.log"
SNAP0="$($WP eval-file "$HERE/snapshot.php")"
valid_snapshot "$SNAP0"
check "baseline snapshot taken" $?
FRONT0="$(frontend)"
! echo "$FRONT0" | grep -q ' e3b0c44298fc1c14'
check "baseline front-end pages reachable" $? "$(echo "$FRONT0" | tr '\n' ' ')"
[ "$FRONT0" = "$(frontend)" ]
check "control: front-end output is deterministic without changes (comparison is meaningful)" $?

$WP plugin install "$ZIP" >/dev/null 2>&1
check "ZIP installs (one root folder)" $? "$(basename "$ZIP")"

ACT="$($WP plugin activate semantic-seo-engine-vi-en 2>&1)"
check "activation without fatal error" $? "$ACT"
LOG="$(grep -i 'semantic-seo-engine-vi-en\|SemanticSEO' "$DIR/wp-content/debug.log" 2>/dev/null | head -5)"
[ -z "$LOG" ]
check "no PHP notices/warnings from the plugin in debug.log after activation" $? "$LOG"

SNAP1="$($WP eval-file "$HERE/snapshot.php")"
valid_snapshot "$SNAP1" || SNAP1=invalid
[ "$SNAP0" = "$SNAP1" ]
check "activation is read-only (posts, meta, terms, users, roles, options, rewrite rules unchanged)" $? "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);$d=[];foreach($a as $k=>$v){if($b[$k]!==$v)$d[]=$k;}echo $d?"changed: ".implode(",",$d):"identical";' "$SNAP0" "$SNAP1")"
FRONT1="$(frontend)"
[ "$FRONT0" = "$FRONT1" ]
check "front-end HTML byte-identical after activation (home, posts, category, page, search, feed)" $? "$(echo "$FRONT1" | wc -l) URLs"

TABLES="$($WP db query "SHOW TABLES LIKE '%sseo\_%'" --skip-column-names 2>/dev/null | grep -c 'sseo_')"
[ "$TABLES" -eq 16 ]
check "16 plugin tables created" $? "$TABLES"
[ "$($WP option get sseo_db_version 2>/dev/null)" = "1" ]
check "schema version recorded" $? "sseo_db_version=$($WP option get sseo_db_version 2>/dev/null)"

STATUS="$($WP sseo status --user=admin 2>&1)"
check "wp sseo status" $? "$(echo "$STATUS" | tr '\n' ' ' | cut -c1-200)"
RUN="$($WP sseo start inventory --then-analyze --run --user=admin 2>&1)"
echo "$RUN" | grep -qi "completed"
check "wp sseo start inventory --then-analyze --run completes" $? "$(echo "$RUN" | tail -3 | tr '\n' ' ' | cut -c1-240)"
REP="$($WP sseo report cannibalization --user=admin 2>&1)"
echo "$REP" | php -r 'exit(is_array(json_decode(stream_get_contents(STDIN),true))?0:1);'
check "wp sseo report cannibalization returns JSON" $? "$(echo "$REP" | cut -c1-160)"
$WP sseo export --user=admin --file="$DIR/matrix.csv" >/dev/null 2>&1 && [ -s "$DIR/matrix.csv" ]
check "wp sseo export writes a CSV" $? "$(wc -l < "$DIR/matrix.csv" 2>/dev/null) lines"

SNAP2="$($WP eval-file "$HERE/snapshot.php")"
valid_snapshot "$SNAP2" || SNAP2=invalid
php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);foreach(["posts","postmeta","terms","term_taxonomy","term_relationships","termmeta","users","roles","rewrite_rules"] as $k){if($a[$k]!==$b[$k]){fwrite(STDERR,$k);exit(1);}}' "$SNAP0" "$SNAP2"
check "full analysis wrote no content, meta, terms, users, roles or rewrite rules" $?
FRONT2="$(frontend)"
[ "$FRONT0" = "$FRONT2" ]
check "front-end HTML unchanged after analysis (related output off by default)" $?
LOG="$(grep -i 'semantic-seo-engine-vi-en\|SemanticSEO' "$DIR/wp-content/debug.log" 2>/dev/null | head -5)"
[ -z "$LOG" ]
check "no PHP notices/warnings from the plugin after a full analysis" $? "$LOG"

$WP plugin deactivate semantic-seo-engine-vi-en >/dev/null 2>&1
check "deactivation without error" $?
CRON="$($WP cron event list --fields=hook --format=csv 2>&1)"
echo "$CRON" | grep -q '^hook' && ! echo "$CRON" | grep -q '^sseo_'
check "deactivation clears the plugin's cron events" $?
TABLES="$($WP db query "SHOW TABLES LIKE '%sseo\_%'" --skip-column-names 2>/dev/null | grep -c 'sseo_')"
[ "$TABLES" -eq 16 ]
check "deactivation keeps data" $? "$TABLES tables"
$WP plugin activate semantic-seo-engine-vi-en >/dev/null 2>&1
check "re-activation" $?
$WP plugin uninstall --deactivate semantic-seo-engine-vi-en >/dev/null 2>&1
check "uninstall (default policy: preserve)" $?
TABLES="$($WP db query "SHOW TABLES LIKE '%sseo\_%'" --skip-column-names 2>/dev/null | grep -c 'sseo_')"
[ "$TABLES" -eq 16 ]
check "uninstall preserves analysis data by default" $? "$TABLES tables kept"
SNAP3="$($WP eval-file "$HERE/snapshot.php")"
valid_snapshot "$SNAP3" || SNAP3=invalid
php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);foreach(["posts","postmeta","terms","term_taxonomy","term_relationships","users","roles"] as $k){if($a[$k]!==$b[$k]){fwrite(STDERR,$k);exit(1);}}' "$SNAP0" "$SNAP3"
check "site content identical after the whole lifecycle" $?

pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
{
	printf '{"wordpress":"%s","php":"%s","zip":"%s","when":"%s","checks":[' "$V" "$(php -r 'echo PHP_VERSION;')" "$(basename "$ZIP")" "$(date -u +%FT%TZ)"
	(IFS=,; printf '%s' "${RESULTS[*]}")
	printf ']}\n'
} > "$OUT/smoke-$V.json"
echo "$(( ${#RESULTS[@]} - FAILED ))/${#RESULTS[@]} checks passed on WordPress $V"
exit $(( FAILED > 0 ))
