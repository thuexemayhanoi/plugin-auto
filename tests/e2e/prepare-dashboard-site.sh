#!/usr/bin/env bash
# Disposable WordPress site for the dashboard browser test: VI–EN fixture corpus, this release
# installed from its ZIP and activated, nothing analysed yet, WP-Cron enabled (as on the blog).
# Optional plugins already copied in OTHER_PLUGINS_DIR (e.g. Yoast SEO, LiteSpeed Cache, Nguyen
# Ha VI-EN Core) are activated first, so the corpus is loaded through them.
#
#   SSEO_DB_HOST=127.0.0.1:3318 tests/e2e/prepare-dashboard-site.sh <wp-version> <port>
set -euo pipefail

V="$1"
PORT="$2"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${ZIP:-$ROOT/dist/semantic-seo-engine-vi-en-$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/semantic-seo-engine-vi-en.php" | head -1 | tr -d '\r').zip}"
DIR="${SITES_DIR:-/tmp/sseo-sites}/dashboard-$V"
WP="wp --allow-root --path=$DIR"

SSEO_DB_NAME="${SSEO_DB_NAME:-sseo_dashboard}" "$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
if [ -n "${OTHER_PLUGINS_DIR:-}" ] && [ -d "$OTHER_PLUGINS_DIR" ]; then
	for p in "$OTHER_PLUGINS_DIR"/*/; do
		cp -R "$p" "$DIR/wp-content/plugins/"
		$WP plugin activate "$(basename "$p")" >/dev/null
	done
fi
$WP eval-file "$HERE/load-corpus.php" > "$DIR/.corpus-map.json"
$WP rewrite flush >/dev/null
# Let WordPress finish its own post-publication work (pings, enclosures) before the test measures
# that nothing changes.
$WP cron event run --due-now >/dev/null 2>&1 || true
$WP plugin install "$ZIP" --activate >/dev/null
: > "$DIR/wp-content/debug.log"
echo "$DIR"
