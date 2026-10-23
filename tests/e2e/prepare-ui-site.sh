#!/usr/bin/env bash
# Disposable WordPress site for the browser UI tests: plugin from the release ZIP, real Polylang
# (optional), the labelled fixture corpus, a completed analysis, a filled embedding queue and the
# TEST-ONLY fixture model profile (tiny random model served locally — NOT Qwen3-Embedding).
#
#   tests/e2e/prepare-ui-site.sh <wp-version> <site-port> <fixture-port>
set -euo pipefail

V="$1"
PORT="$2"
FPORT="$3"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${ZIP:-$ROOT/dist/semantic-seo-engine-vi-en-$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/semantic-seo-engine-vi-en.php" | head -1 | tr -d '\r').zip}"
DIR="${SITES_DIR:-/tmp/sseo-sites}/ui-$V"
WP="wp --allow-root --path=$DIR"

"$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
if [ -n "${POLYLANG_DIR:-}" ] && [ -d "$POLYLANG_DIR" ]; then
	cp -R "$POLYLANG_DIR" "$DIR/wp-content/plugins/polylang"
	$WP plugin activate polylang >/dev/null
fi
$WP eval-file "$HERE/load-corpus.php" > "$DIR/.corpus-map.json"
$WP rewrite flush >/dev/null
$WP plugin install "$ZIP" --activate >/dev/null
mkdir -p "$DIR/wp-content/mu-plugins"
cp "$HERE/fixture-profile-mu.php" "$DIR/wp-content/mu-plugins/"
$WP config set SSEO_FIXTURE_PROFILE "$ROOT/tests/fixtures/tiny-qwen3/expected.json" >/dev/null
$WP config set SSEO_FIXTURE_HOST "http://127.0.0.1:$FPORT/hf" >/dev/null
pkill -f "static-server.mjs $FPORT" 2>/dev/null || true
( exec node "$HERE/static-server.mjs" "$FPORT" ) >"$DIR/.fixture-server.log" 2>&1 </dev/null &
echo $! > "$DIR/.fixture-server.pid"
$WP sseo start inventory --then-analyze --run --user=admin >/dev/null
$WP sseo start embed --user=admin >/dev/null
for _ in 1 2 3 4; do $WP eval '\SemanticSEO\Jobs\JobRunner::tick( 15 );' >/dev/null; done
: > "$DIR/wp-content/debug.log"
echo "$DIR"
