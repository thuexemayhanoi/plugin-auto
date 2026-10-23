#!/usr/bin/env bash
# Runs every end-to-end suite against the current release ZIP on disposable local sites and
# collects the JSON results in tests/e2e/artifacts/. Requires: WP-CLI, MariaDB (wp/wp), PHP, Node,
# Playwright Chromium, WordPress copies in /opt/wp/wp-<version> (or WP_SOURCE per run), and
# optionally POLYLANG_DIR, MCP_ADAPTER_DIR and PYTHON (with numpy, onnxruntime, tokenizers).
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
LOG="$ROOT/tests/e2e/artifacts/run-all.log"
mkdir -p "$ROOT/tests/e2e/artifacts"
: > "$LOG"
status=0

step() {
	echo "### $*" | tee -a "$LOG"
	"$@" >>"$LOG" 2>&1
	local rc=$?
	echo "### exit $rc: $*" | tee -a "$LOG"
	[ $rc -eq 0 ] || status=1
}

step node --test "$ROOT"/tests/js/*.test.mjs
step node "$HERE/worker-fixture.mjs"
for spec in "7.1.3 8071" "6.9.10 8069" "6.5.13 8065"; do
	set -- $spec
	if [ "$1" = "6.5.13" ]; then
		step env -u POLYLANG_DIR "$HERE/smoke-zip.sh" "$1" "$2"
	else
		step "$HERE/smoke-zip.sh" "$1" "$2"
	fi
done
for spec in "7.1.3 8171" "6.9.10 8169" "6.5.13 8165"; do
	set -- $spec
	if [ "$1" = "6.5.13" ]; then
		SITE="$(env -u POLYLANG_DIR -u MCP_ADAPTER_DIR "$HERE/prepare-agent-site.sh" "$1" "$2" 2>>"$LOG" | tail -1)"
	else
		SITE="$("$HERE/prepare-agent-site.sh" "$1" "$2" 2>>"$LOG" | tail -1)"
	fi
	step node "$HERE/http-agent.mjs" "$SITE"
done
if [ -n "${PYTHON:-}" ]; then
	step "$HERE/private-worker.sh" 7.1.3 8271 8272 "$PYTHON"
fi
SITE="$("$HERE/prepare-ui-site.sh" 7.1.3 8371 8372 2>>"$LOG" | tail -1)"
step node "$HERE/ui-e2e.mjs" "$SITE"

grep -E '^(PASS|FAIL)|passed on|checks passed|^### exit' "$LOG"
exit $status
