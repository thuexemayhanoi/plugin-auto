#!/usr/bin/env bash
# Coexistence with widely used plugins on a disposable site: WooCommerce, Yoast SEO and Polylang
# (installed from wordpress.org, so this runs in CI where that host is reachable).
#
#   tests/e2e/compat-plugins.sh <wp-version> <port>
#
# Checks: activation next to them without PHP errors; public pages (home, post, page, product,
# shop) byte-identical with this plugin on and off, compared at the same moment after warming the
# site (right after activation and after a full inventory + analysis; a page that differs between
# two identical requests even without this plugin is checked for the absence of plugin assets
# instead); products are not inventoried by default; deactivation leaves the others active and no
# output of this plugin. Writes tests/e2e/artifacts/compat-plugins.json.
set -uo pipefail

V="$1"
PORT="$2"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${ZIP:-$(ls -1 "$ROOT"/dist/semantic-seo-engine-vi-en-*.zip | head -1)}"
DIR="${SITES_DIR:-/tmp/sseo-sites}/compat-$V"
WP="wp --allow-root --path=$DIR"
BASE="http://127.0.0.1:$PORT"
OUT="$ROOT/tests/e2e/artifacts"
mkdir -p "$OUT"
RESULTS=()
FAILS=0

check() {
	local name="$1" ok="$2" detail="${3:-}"
	if [ "$ok" = "0" ]; then echo "PASS $name ${detail:+— $detail}"; else echo "FAIL $name ${detail:+— $detail}"; FAILS=$((FAILS + 1)); fi
	RESULTS+=( "{\"check\":$(printf '%s' "$name" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))'),\"passed\":$([ "$ok" = "0" ] && echo true || echo false),\"detail\":$(printf '%s' "$detail" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))')}" )
}

"$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
$WP plugin install woocommerce wordpress-seo polylang --activate >/dev/null 2>&1
check "WooCommerce, Yoast SEO and Polylang installed and active" "$($WP plugin is-active woocommerce && $WP plugin is-active wordpress-seo && $WP plugin is-active polylang; echo $?)" "$($WP plugin list --status=active --field=name | tr '\n' ' ')"

# Sample content: posts, a page and products.
for i in 1 2 3; do
	$WP post create --post_type=post --post_status=publish --post_title="Thuê xe máy Hà Nội $i" --post_content="<h2>Giá thuê</h2><p>Thuê xe máy tại Hà Nội, giao xe tận nơi. Bài $i.</p>" >/dev/null
	$WP post create --post_type=product --post_status=publish --post_title="Honda Vision $i" --post_content="<p>Xe tay ga $i.</p>" >/dev/null
done
PAGE_ID="$($WP post create --post_type=page --post_status=publish --post_title='Liên hệ' --post_content='<p>Liên hệ</p>' --porcelain)"
$WP rewrite structure '/%postname%/' >/dev/null && $WP rewrite flush >/dev/null
POST_URL="$($WP post list --post_type=post --posts_per_page=1 --field=url)"
PRODUCT_URL="$($WP post list --post_type=product --posts_per_page=1 --field=url)"
PAGE_URL="$($WP post get "$PAGE_ID" --field=url 2>/dev/null || echo "$BASE/lien-he/")"
SHOP_URL="$BASE/shop/"
URLS=( "$BASE/" "$POST_URL" "$PAGE_URL" "$PRODUCT_URL" "$SHOP_URL" )
: > "$DIR/wp-content/debug.log"

fetch() { curl -s -o - "$1"; }
# Warm the site first: WooCommerce and Yoast SEO build data (indexables, transients) on the first
# visits, so a page can change between the first and later requests without any other plugin.
for _ in 1 2 3; do for u in "${URLS[@]}"; do fetch "$u" >/dev/null; done; done
$WP cron event run --due-now >/dev/null 2>&1 || true

# Same-moment comparison: this plugin ON vs OFF, with an OFF/OFF control for determinism.
compare_pages() {
	local stage="$1"
	declare -A ON
	for u in "${URLS[@]}"; do ON[$u]="$(fetch "$u")"; done
	$WP plugin deactivate semantic-seo-engine-vi-en >/dev/null 2>&1
	for u in "${URLS[@]}"; do
		local off1 off2
		off1="$(fetch "$u")"
		off2="$(fetch "$u")"
		if [ "$off1" = "$off2" ]; then
			[ "${ON[$u]}" = "$off1" ]
			local rc=$?
			local where=""
			[ $rc -ne 0 ] && where="$(diff <(printf '%s\n' "$off1") <(printf '%s\n' "${ON[$u]}") | head -4 | tr '\n' ' ' | cut -c1-300)"
			check "$stage: $u identical with this plugin on and off" "$rc" "$where"
		else
			! printf '%s' "${ON[$u]}" | grep -qE 'semantic-seo-engine-vi-en/(assets|admin)|transformers|onnx'
			check "$stage: $u (differs between two identical requests even without this plugin) has no plugin asset or model runtime" "$?"
		fi
	done
	$WP plugin activate semantic-seo-engine-vi-en >/dev/null 2>&1
}

$WP plugin install "$ZIP" --activate >/dev/null 2>&1
check "this plugin activates next to them" "$($WP plugin is-active semantic-seo-engine-vi-en; echo $?)"
compare_pages "after activation"

$WP sseo start inventory --then-analyze --run --user=admin >/dev/null 2>&1
JOBS="$($WP eval 'echo \SemanticSEO\Database\Db::value( "SELECT GROUP_CONCAT(CONCAT(type, \":\", status)) FROM {jobs}" );')"
echo "$JOBS" | grep -q "analyze:completed"
check "inventory + analysis complete" "$?" "$JOBS"
PRODUCTS="$($WP eval 'echo \SemanticSEO\Database\Db::value( "SELECT COUNT(*) FROM {documents} WHERE subtype = %s", array( "product" ) );')"
[ "$PRODUCTS" = "0" ]
check "products are not inventoried by default (scope: posts and pages)" "$?" "product documents: $PRODUCTS"
POSTS="$($WP eval 'echo \SemanticSEO\Database\Db::value( "SELECT COUNT(*) FROM {documents} WHERE subtype IN (%s, %s)", array( "post", "page" ) );')"
[ "${POSTS:-0}" -gt 0 ]
check "posts and pages inventoried" "$?" "documents: $POSTS"
compare_pages "after full analysis"

ERRORS="$(grep -E 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' "$DIR/wp-content/debug.log" | grep -c 'semantic-seo-engine-vi-en' || true)"
[ "$ERRORS" = "0" ]
check "no PHP error, warning, notice or deprecation from this plugin in debug.log" "$?" "lines: $ERRORS"

$WP plugin deactivate semantic-seo-engine-vi-en >/dev/null 2>&1
check "deactivation leaves WooCommerce, Yoast SEO and Polylang active" "$($WP plugin is-active woocommerce && $WP plugin is-active wordpress-seo && $WP plugin is-active polylang; echo $?)"
for u in "${URLS[@]}"; do
	! fetch "$u" | grep -qE 'semantic-seo-engine-vi-en/|sseo-'
	check "after deactivation: $u has no output of this plugin" "$?"
done

VERSIONS="$($WP plugin list --fields=name,version --format=json)"
printf '{"wordpress":"%s","plugins":%s,"checks":[%s],"failed":%d}\n' "$V" "$VERSIONS" "$(IFS=,; echo "${RESULTS[*]}")" "$FAILS" > "$OUT/compat-plugins.json"
echo "$(( ${#RESULTS[@]} - FAILS ))/${#RESULTS[@]} compatibility checks passed"
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
[ "$FAILS" = "0" ]
