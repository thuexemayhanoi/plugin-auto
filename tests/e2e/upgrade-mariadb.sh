#!/usr/bin/env bash
# Acceptance test of the 1.0.0-beta.4 database fix on disposable sites, through WP-CLI and the REST
# API with an Application Password (never a production site):
#
#  A. MariaDB 11.8 (the production server): the previous release (OLD_ZIP) creates 15 of 16 tables
#     and re-runs its failed migration on every request; upgrading to this release completes the
#     schema, stops the loop, and a read-only 20-page trial and a full scan (paused and resumed)
#     produce real results through the REST API without changing any post, meta or term.
#  B. MariaDB/MySQL without the VECTOR keyword (default 10.11): the previous release created the
#     embeddings table with a `vector` column; the upgrade renames it and keeps every stored vector.
#
#   OLD_ZIP=<previous release zip> MDB118=127.0.0.1:3318 tests/e2e/upgrade-mariadb.sh <wp-version> <port>
#
# Writes tests/e2e/artifacts/upgrade-mariadb.json; exits non-zero on the first failed check count.
set -uo pipefail

V="${1:-7.1.3}"
PORT="${2:-8150}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
NEW_ZIP="${NEW_ZIP:-$ROOT/dist/semantic-seo-engine-vi-en-$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/semantic-seo-engine-vi-en.php" | head -1 | tr -d '\r').zip}"
OLD_ZIP="${OLD_ZIP:?set OLD_ZIP to the previous release zip}"
MDB118="${MDB118:-127.0.0.1:3318}"
OTHER_DB="${OTHER_DB:-127.0.0.1}"
SITES="${SITES_DIR:-/tmp/sseo-sites}"
OUT="$ROOT/tests/e2e/artifacts"
mkdir -p "$OUT"
RESULTS=()
FAILED=0
check() {
	local name="$1" rc="$2" detail="${3:-}"
	if [ "$rc" -eq 0 ]; then echo "PASS $name ${detail:+— $detail}"; else echo "FAIL $name ${detail:+— $detail}"; FAILED=$((FAILED + 1)); fi
	RESULTS+=( "{\"check\":$(printf '%s' "$name" | php -r 'echo json_encode(stream_get_contents(STDIN));'),\"ok\":$([ "$rc" -eq 0 ] && echo true || echo false),\"detail\":$(printf '%s' "$detail" | php -r 'echo json_encode(stream_get_contents(STDIN));')}" )
}
jget() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){ if($k==="") continue; $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v):(string)$v);' "$1"; }

# ---------------------------------------------------------------- A. MariaDB 11.8, production path
DIR="$SITES/upgrade-118"
WP="wp --allow-root --path=$DIR"
URL="http://127.0.0.1:$PORT"
SSEO_DB_HOST="$MDB118" SSEO_DB_NAME=sseo_upgrade_118 "$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
SERVER="$($WP db query 'SELECT VERSION()' --skip-column-names 2>/dev/null)"
echo "$SERVER" | grep -q '^11\.8'
check "site on MariaDB 11.8" $? "$SERVER"
$WP eval-file "$HERE/load-corpus.php" > "$DIR/.corpus-map.json" 2>/dev/null
$WP rewrite flush >/dev/null 2>&1
POSTS="$($WP post list --post_type=post,page --post_status=publish --format=count)"
[ "$POSTS" -ge 20 ]
check "VI–EN sample content (at least 20 published posts and pages)" $? "$POSTS published"
APP="$($WP user application-password create admin acceptance --porcelain 2>/dev/null)"
AUTH="admin:$APP"
api() { curl -s -u "$AUTH" -H 'Content-Type: application/json' "$URL/wp-json/semantic-seo/v1$1" "${@:2}"; }
logcount() { $WP option get sseo_migration_log --format=json 2>/dev/null | php -r '$l=json_decode(stream_get_contents(STDIN),true); echo is_array($l)?count($l):0;'; }
visit() { for p in / /?p=1 /feed/; do curl -s -o /dev/null "$URL$p"; done; }

$WP plugin install "$OLD_ZIP" --activate >/dev/null 2>&1
OLDV="$($WP plugin get semantic-seo-engine-vi-en --field=version 2>/dev/null)"
check "previous release installed and active" $? "$OLDV"
T="$($WP db query "SHOW TABLES LIKE '%sseo\_%'" --skip-column-names 2>/dev/null | grep -c sseo_)"
[ "$T" -eq 15 ]
check "previous release on MariaDB 11.8: 15 of 16 tables (production state reproduced)" $? "$T tables"
LAST="$($WP option get sseo_migration_log --format=json | php -r '$l=json_decode(stream_get_contents(STDIN),true); echo end($l)["error"];')"
[ "$LAST" = "missing tables: embeddings" ]
check "previous release logs 'missing tables: embeddings'" $? "$LAST"
ST="$(api /status)"
[ "$(printf '%s' "$ST" | jget data.diagnostics.db_schema.installed)" = "0" ] && [ "$(printf '%s' "$ST" | jget data.diagnostics.db_schema.missing)" = '["embeddings"]' ]
check "previous release /status: installed 0, missing [\"embeddings\"]" $? "$(printf '%s' "$ST" | jget data.diagnostics.db_schema)"
N0="$(logcount)"; visit; N1="$(logcount)"
[ "$N1" -ge $(( N0 + 3 )) ]
check "previous release re-runs the failed migration on every request (loop reproduced)" $? "log entries $N0 → $N1 after 3 requests"

$WP plugin install "$NEW_ZIP" --force >/dev/null 2>&1
# Errors logged so far come from the previous release; from here on, only this release runs.
: > "$DIR/wp-content/debug.log"
NEWV="$($WP plugin get semantic-seo-engine-vi-en --field=version 2>/dev/null)"
check "upgrade to this release over the broken install (plugin stays active)" "$($WP plugin is-active semantic-seo-engine-vi-en; echo $?)" "$NEWV"
curl -s -o /dev/null "$URL/"
T="$($WP db query "SHOW TABLES LIKE '%sseo\_%'" --skip-column-names 2>/dev/null | grep -c sseo_)"
[ "$T" -eq 16 ]
check "after the upgrade: 16 of 16 tables" $? "$T tables"
COL="$($WP db query "SHOW COLUMNS FROM wp_sseo_embeddings LIKE 'vector_data'" --skip-column-names 2>/dev/null | cut -f1)"
[ "$COL" = "vector_data" ]
check "embeddings table created with the vector_data column" $? "$COL"
ST="$(api /status)"
SCHEMA="$(printf '%s' "$ST" | jget data.diagnostics.db_schema)"
[ "$(printf '%s' "$ST" | jget data.diagnostics.db_schema.ready)" = "true" ] && [ "$(printf '%s' "$ST" | jget data.diagnostics.db_schema.installed)" = "$(printf '%s' "$ST" | jget data.diagnostics.db_schema.expected)" ] && [ "$(printf '%s' "$ST" | jget data.diagnostics.db_schema.missing)" = "[]" ]
check "/status after the upgrade: schema installed = expected, nothing missing" $? "$SCHEMA"
N0="$(logcount)"; visit; visit; N1="$(logcount)"
[ "$N1" -eq "$N0" ]
check "no migration loop: 6 more requests add no migration run" $? "log entries $N0 → $N1"
LOGERR="$(grep -i 'semantic-seo-engine-vi-en\|SemanticSEO' "$DIR/wp-content/debug.log" 2>/dev/null | grep -v 'missing tables' | head -3)"
[ -z "$LOGERR" ]
check "no PHP error from this release in debug.log" $? "$LOGERR"

# Read-only 20-page trial through the REST API.
SNAP0="$($WP eval-file "$HERE/snapshot.php")"
IDS="$($WP post list --post_type=post,page --post_status=publish --orderby=modified --order=DESC --posts_per_page=20 --field=ID | paste -sd, -)"
JOB="$(api /jobs -X POST -d "{\"type\":\"inventory\",\"post_ids\":[$IDS],\"then_analyze\":true,\"pilot\":true}")"
[ "$(printf '%s' "$JOB" | jget ok)" = "true" ]
check "REST: 20-page trial started" $? "job $(printf '%s' "$JOB" | jget data.id)"
T0="$(date +%s.%N)"
$WP eval 'for ( $i = 0; $i < 20; $i++ ) { $n = \SemanticSEO\Jobs\JobRepository::next_runnable(); if ( $n <= 0 ) { break; } \SemanticSEO\Jobs\JobRunner::run_to_completion( $n ); }' --user=admin >/dev/null 2>&1
T1="$(date +%s.%N)"
H="$(api /health)"
PILOT="$(printf '%s' "$H" | jget data.last_pilot)"
[ "$(printf '%s' "$H" | jget data.last_pilot.readonly.unchanged)" = "true" ] && [ "$(printf '%s' "$H" | jget data.last_pilot.documents)" -ge 18 ]
check "trial finished, read-only verified by the plugin" $? "$(printf '%s' "$PILOT" | cut -c1-260)"
SNAP1="$($WP eval-file "$HERE/snapshot.php")"
php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);foreach(["posts","postmeta","terms","term_taxonomy","term_relationships","termmeta","users","roles","rewrite_rules"] as $k){if($a[$k]!==$b[$k]){fwrite(STDERR,$k);exit(1);}}' "$SNAP0" "$SNAP1"
check "trial wrote no post, meta, term, user, role or rewrite rule (independent snapshot)" $?
PR="$(api '/priorities?limit=5')"
[ "$(printf '%s' "$PR" | jget data.total)" -gt 0 ]
check "REST /priorities returns real items after the trial" $? "total $(printf '%s' "$PR" | jget data.total); first: $(printf '%s' "$PR" | jget data.items.0.problem) — $(printf '%s' "$PR" | jget data.items.0.url)"
EST="$(printf '%s' "$H" | jget data.estimate)"
[ -n "$(printf '%s' "$H" | jget data.estimate.estimated_seconds)" ]
check "whole-site estimate measured from the trial" $? "$EST"

# Full scan: paused, resumed, completed.
SNAP0="$($WP eval-file "$HERE/snapshot.php")"
JOB="$(api /jobs -X POST -d '{"type":"inventory","then_analyze":true}')"
JID="$(printf '%s' "$JOB" | jget data.id)"
[ -n "$JID" ]
check "REST: full scan started" $? "job $JID"
P="$(api "/jobs/$JID/pause" -X POST)"
[ "$(printf '%s' "$P" | jget data.status)" = "paused" ]
check "REST: full scan paused" $? "$(printf '%s' "$P" | jget data.status)"
R="$(api "/jobs/$JID/resume" -X POST)"
[ "$(printf '%s' "$R" | jget data.status)" = "queued" ]
check "REST: full scan resumed" $? "$(printf '%s' "$R" | jget data.status)"
F0="$(date +%s.%N)"
$WP eval 'for ( $i = 0; $i < 20; $i++ ) { $n = \SemanticSEO\Jobs\JobRepository::next_runnable(); if ( $n <= 0 ) { break; } \SemanticSEO\Jobs\JobRunner::run_to_completion( $n ); }' --user=admin >/dev/null 2>&1
F1="$(date +%s.%N)"
H="$(api /health)"
[ "$(printf '%s' "$H" | jget data.last_scan.scope)" = "site" ] && [ "$(printf '%s' "$H" | jget data.last_scan.readonly.unchanged)" = "true" ]
check "full scan finished, read-only verified" $? "$(printf '%s' "$H" | jget data.last_scan | cut -c1-300)"
SNAP1="$($WP eval-file "$HERE/snapshot.php")"
php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);foreach(["posts","postmeta","terms","term_taxonomy","term_relationships","termmeta","users","roles","rewrite_rules"] as $k){if($a[$k]!==$b[$k]){fwrite(STDERR,$k);exit(1);}}' "$SNAP0" "$SNAP1"
check "full scan wrote no post, meta, term, user, role or rewrite rule (independent snapshot)" $?
C="$(printf '%s' "$H" | jget data.counts)"
[ "$(printf '%s' "$H" | jget data.counts.analyzed)" = "$(printf '%s' "$H" | jget data.counts.inventoried)" ]
check "every inventoried page analyzed" $? "$C"
for ep in /inventory /qa /suggestions/links /clusters /cannibalization /scans; do
	RES="$(api "$ep")"
	[ "$(printf '%s' "$RES" | jget ok)" = "true" ]
	check "REST $ep returns data" $? "$(printf '%s' "$RES" | cut -c1-120)"
done
HDR="$(curl -s -D - -o /dev/null -u "$AUTH" "$URL/wp-json/semantic-seo/v1/health" | tr -d '\r')"
printf '%s' "$HDR" | grep -qi '^cache-control:.*no-store' && printf '%s' "$HDR" | grep -qi '^x-litespeed-cache-control: no-cache'
check "REST answers are not cacheable (Cache-Control no-store, X-LiteSpeed-Cache-Control no-cache)" $? "$(printf '%s' "$HDR" | grep -i 'cache-control' | tr '\n' ' ')"
TIMING="$(php -r 'printf("trial %.1f s, full scan %.1f s (WP-CLI ticks, %d posts)", $argv[2]-$argv[1], $argv[4]-$argv[3], $argv[5]);' "$T0" "$T1" "$F0" "$F1" "$POSTS")"
check "timings measured" 0 "$TIMING"
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true

# ------------------------------------------------- B. Upgrade of a beta.3 schema with stored vectors
DIR="$SITES/upgrade-other"
WP="wp --allow-root --path=$DIR"
SSEO_DB_HOST="$OTHER_DB" SSEO_DB_NAME=sseo_upgrade_other "$HERE/setup-site.sh" "$V" "$((PORT + 1))" "$DIR" >/dev/null
SERVER="$($WP db query 'SELECT VERSION()' --skip-column-names 2>/dev/null)"
$WP plugin install "$OLD_ZIP" --activate >/dev/null 2>&1
OLDCOL="$($WP db query "SHOW COLUMNS FROM wp_sseo_embeddings LIKE 'vector'" --skip-column-names 2>/dev/null | cut -f1)"
[ "$OLDCOL" = "vector" ] && [ "$($WP option get sseo_db_version)" = "3" ]
check "previous release on $SERVER: complete schema 3 with the old vector column" $? "column: $OLDCOL"
$WP db query "INSERT INTO wp_sseo_embeddings (document_id, chunk_id, model_key, model_id, revision, dtype, dimension, preprocess_version, locale, content_hash, \`vector\`, norm, source, created_at) VALUES (1, 0, REPEAT('k',40), 'Qwen/Qwen3-Embedding-0.6B', 'r', 'q4f16', 3, 'p2', 'vi', REPEAT('h',64), UNHEX('0000803F0000000000000000'), 1, 'browser', NOW())" >/dev/null 2>&1
BEFORE="$($WP db query "SELECT HEX(\`vector\`) FROM wp_sseo_embeddings" --skip-column-names 2>/dev/null)"
$WP plugin install "$NEW_ZIP" --force >/dev/null 2>&1
curl -s -o /dev/null "http://127.0.0.1:$((PORT + 1))/"
AFTER="$($WP db query "SELECT HEX(vector_data) FROM wp_sseo_embeddings" --skip-column-names 2>/dev/null)"
COLS="$($WP db query "SHOW COLUMNS FROM wp_sseo_embeddings LIKE 'vector%'" --skip-column-names 2>/dev/null | cut -f1 | grep -v '^$' | paste -sd, -)"
[ -n "$BEFORE" ] && [ "$BEFORE" = "$AFTER" ] && [ "$COLS" = "vector_data" ] && [ "$($WP option get sseo_db_version)" = "4" ]
check "upgrade on $SERVER renames vector → vector_data and keeps the stored vector byte for byte" $? "columns: $COLS; before $BEFORE; after $AFTER; schema $($WP option get sseo_db_version)"
pkill -f "php -S 127.0.0.1:$((PORT + 1))" 2>/dev/null || true

{
	printf '{"wordpress":"%s","php":"%s","old_zip":"%s","new_zip":"%s","when":"%s","checks":[' "$V" "$(php -r 'echo PHP_VERSION;')" "$(basename "$OLD_ZIP")" "$(basename "$NEW_ZIP")" "$(date -u +%FT%TZ)"
	(IFS=,; printf '%s' "${RESULTS[*]}")
	printf ']}\n'
} > "$OUT/upgrade-mariadb.json"
echo "$(( ${#RESULTS[@]} - FAILED ))/${#RESULTS[@]} checks passed"
exit $(( FAILED > 0 ))
