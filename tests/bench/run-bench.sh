#!/usr/bin/env bash
# Performance measurement on a disposable local site with N SYNTHETIC BENCHMARK posts.
#
#   tests/bench/run-bench.sh <count> <port>
#
# Measures: full inventory + analysis wall time and peak memory (WP-CLI process), the duration
# and memory of one bounded background tick, index sizes, and REST latency (median/p95 of 20
# requests per endpoint over HTTP with an Application Password). Writes
# tests/e2e/artifacts/bench-<count>.json. Numbers depend on this machine; see docs/PERFORMANCE.md.
set -euo pipefail

N="$1"
PORT="$2"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${ZIP:-$ROOT/dist/semantic-seo-engine-vi-en-$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/semantic-seo-engine-vi-en.php" | head -1 | tr -d '\r').zip}"
DIR="${SITES_DIR:-/tmp/sseo-sites}/bench-$N"
WP="wp --allow-root --path=$DIR"
OUT="$ROOT/tests/e2e/artifacts"
mkdir -p "$OUT"

SSEO_DB_NAME="${SSEO_DB_NAME:-sseo_bench_$N}" "$ROOT/tests/e2e/setup-site.sh" 7.1.3 "$PORT" "$DIR" >/dev/null
if [ -n "${POLYLANG_DIR:-}" ] && [ -d "$POLYLANG_DIR" ]; then
	cp -R "$POLYLANG_DIR" "$DIR/wp-content/plugins/polylang"
	$WP plugin activate polylang >/dev/null
fi
$WP eval-file "$HERE/generate.php" "$N" 2>&1 | tail -1
$WP rewrite flush >/dev/null
$WP plugin install "$ZIP" --activate >/dev/null
# Above the default Max documents limit (5,000) the inventory would leave part of the site out.
$WP eval "\\SemanticSEO\\Settings::update( array( 'max_documents' => $(( N + 1000 )) ) );" >/dev/null

# 1. One bounded tick (what WP-Cron runs in the background).
$WP sseo start inventory --then-analyze --user=admin >/dev/null
TICK="$($WP eval '$t = microtime( true ); \SemanticSEO\Jobs\JobRunner::tick( (int) \SemanticSEO\Settings::get( "time_budget", 20 ) ); echo wp_json_encode( array( "seconds" => round( microtime( true ) - $t, 2 ), "peak_mb" => round( memory_get_peak_usage( true ) / 1048576, 1 ) ) );')"

# 2. Run everything to completion in one CLI process.
START=$(date +%s.%N)
/usr/bin/time -f '%M' -o "$DIR/.maxrss" $WP sseo run --user=admin > "$DIR/.run.log" 2>&1
END=$(date +%s.%N)
MAXRSS_KB="$(tail -1 "$DIR/.maxrss")"
STATS="$($WP eval '
global $wpdb; $p = $wpdb->prefix . "sseo_";
$c = function( $t ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$t}" ); };
$size = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s", DB_NAME, $wpdb->esc_like( $p ) . "%" ) );
echo wp_json_encode( array( "documents" => $c( "documents" ), "postings" => $c( "postings" ), "links" => $c( "links" ), "findings" => $c( "findings" ), "related" => $c( "related" ), "chunks" => $c( "chunks" ), "tables_mb" => round( $size / 1048576, 2 ), "jobs" => $wpdb->get_results( "SELECT id, type, status, progress_done, progress_total FROM {$p}jobs", ARRAY_A ) ) );')"

# 3. REST latency (the per-user read rate limit is raised on this disposable site so that 140
# sequential requests measure latency, not throttling).
$WP eval '\SemanticSEO\Settings::update( array( "rate_read" => 5000 ) );' >/dev/null
$WP user create bench_reader bench_reader@example.test --role=subscriber >/dev/null
$WP eval '\SemanticSEO\Capabilities::ensure_agent_roles();' >/dev/null
$WP user set-role bench_reader sseo_agent_reader >/dev/null
APP="$($WP user application-password create bench_reader bench --porcelain)"
URL1="$($WP eval 'global $wpdb; echo $wpdb->get_var( "SELECT url FROM {$wpdb->prefix}sseo_documents WHERE object_type = \"post\" ORDER BY id LIMIT 1" );')"
LAT="$(node -e '
const [base, user, pass, url1] = process.argv.slice(1);
const auth = "Basic " + Buffer.from(user + ":" + pass).toString("base64");
const eps = { status: "/status", inventory_20: "/inventory?limit=20", inventory_search: "/inventory?limit=20&search=xe", url_analysis: "/analysis?url=" + encodeURIComponent(url1), links_20: "/suggestions/links?limit=20", cannibalization_20: "/cannibalization?limit=20", qa_20: "/qa?limit=20" };
(async () => {
  const out = {};
  for (const [k, p] of Object.entries(eps)) {
    const t = [];
    for (let i = 0; i < 20; i++) { const s = performance.now(); const r = await fetch(base + "/wp-json/semantic-seo/v1" + p, { headers: { Authorization: auth } }); await r.text(); if (!r.ok) { out[k] = "HTTP " + r.status; break; } t.push(performance.now() - s); }
    if (t.length) { t.sort((a, b) => a - b); out[k] = { median_ms: Math.round(t[Math.floor(t.length / 2)]), p95_ms: Math.round(t[Math.floor(t.length * 0.95) - 1]) }; }
  }
  console.log(JSON.stringify(out));
})();' "http://127.0.0.1:$PORT" bench_reader "$APP" "$URL1")"

python3 - "$OUT/bench-$N.json" <<PY
import json, sys
res = {
  "fixture": "SYNTHETIC BENCHMARK FIXTURE (tests/bench/generate.php), not real content",
  "posts": $N, "wordpress": "7.1.3", "php": "$(php -r 'echo PHP_VERSION;')",
  "machine": "$(nproc) vCPU, $(free -m | awk '/Mem:/ {print $2}') MB RAM (container), MariaDB $(mysql -V | grep -o '[0-9]*\.[0-9]*\.[0-9]*-MariaDB' | head -1)",
  "one_tick": json.loads('''$TICK'''),
  "full_run_seconds": round($END - $START, 1),
  "full_run_max_rss_mb": round(int("$MAXRSS_KB") / 1024, 1),
  "index": json.loads('''$STATS'''),
  "rest_latency": json.loads('''$LAT'''),
}
json.dump(res, open(sys.argv[1], "w"), indent=1, ensure_ascii=False)
print(json.dumps({k: res[k] for k in ("posts", "one_tick", "full_run_seconds", "full_run_max_rss_mb", "rest_latency")}))
PY
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
