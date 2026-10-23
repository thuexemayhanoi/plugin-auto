#!/usr/bin/env bash
# End-to-end test of the Tier 2 private worker (tools/private-worker/sseo_worker.py) against a
# disposable WordPress site, using the TINY RANDOM FIXTURE model (NOT Qwen3-Embedding) — or, when
# REAL_MODEL_MANIFEST is set (manifest from tests/real-model/fetch_verify.py), the REAL pinned
# Qwen3-Embedding-0.6B files with REAL_DTYPE (default q4f16), already verified in the worker cache.
#
#   tests/e2e/private-worker.sh <wp-version> <site-port> <fixture-port> <python>
#
# <python> must have numpy, onnxruntime and tokenizers installed.
set -euo pipefail

V="$1"
PORT="$2"
FPORT="$3"
PY="$4"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${ZIP:-$ROOT/dist/semantic-seo-engine-vi-en-$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/semantic-seo-engine-vi-en.php" | head -1 | tr -d '\r').zip}"
DIR="${SITES_DIR:-/tmp/sseo-sites}/worker-$V"
WP="wp --allow-root --path=$DIR"
OUT="$ROOT/tests/e2e/artifacts"
mkdir -p "$OUT"

"$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
$WP eval-file "$HERE/load-corpus.php" >/dev/null
$WP plugin install "$ZIP" --activate >/dev/null
REAL="${REAL_MODEL_MANIFEST:-}"
DTYPE="${REAL_DTYPE:-q4f16}"
if [ -n "$REAL" ]; then
	MODEL="REAL Qwen3-Embedding-0.6B ONNX ($DTYPE, pinned + SHA-256 verified)"
	CACHE="$("$PY" -I -c 'import json,sys,os; r=json.load(open(sys.argv[1]))["root"]; print(os.path.dirname(os.path.dirname(r)))' "$REAL")"
	$WP eval "\\SemanticSEO\\Settings::update( array( 'model_dtype' => '$DTYPE' ) );"
else
	MODEL="TINY RANDOM TEST FIXTURE (not Qwen3-Embedding)"
	CACHE="$DIR/.worker-cache"
	mkdir -p "$DIR/wp-content/mu-plugins"
	cp "$HERE/fixture-profile-mu.php" "$DIR/wp-content/mu-plugins/"
	$WP config set SSEO_FIXTURE_PROFILE "$ROOT/tests/fixtures/tiny-qwen3/expected.json" >/dev/null
	$WP config set SSEO_FIXTURE_HOST "http://127.0.0.1:$FPORT/hf" >/dev/null
	( exec node "$HERE/static-server.mjs" "$FPORT" ) >"$DIR/.fixture-server.log" 2>&1 </dev/null &
	FIXTURE_PID=$!
	trap 'kill $FIXTURE_PID 2>/dev/null || true' EXIT
	sleep 1
fi

$WP sseo start inventory --then-analyze --run --user=admin >/dev/null
$WP eval '\SemanticSEO\Capabilities::ensure_agent_roles();'
$WP user create agent_worker agent_worker@example.test --role=sseo_worker --user_pass="$(head -c 18 /dev/urandom | base64)" >/dev/null
APP="$($WP user application-password create agent_worker private-worker --porcelain)"

KEY="$($WP eval 'echo \SemanticSEO\Semantic\ModelProfile::active()["model_key"];')"
$WP sseo start embed --user=admin >/dev/null
for _ in 1 2 3 4; do $WP eval '\SemanticSEO\Jobs\JobRunner::tick( 15 );' >/dev/null; done
QUEUED="$($WP db query "SELECT COUNT(*) FROM wp_sseo_queue WHERE model_key = '$KEY'" --skip-column-names)"

START=$(date +%s.%N)
RC=0
SSEO_APP_PASSWORD="$APP" "$PY" -I "$ROOT/tools/private-worker/sseo_worker.py" \
	--site "http://127.0.0.1:$PORT" --user agent_worker --allow-http --once --batch 8 \
	--cache-dir "$CACHE" > "$DIR/.worker.log" 2>&1 || RC=$?
END=$(date +%s.%N)

STORED="$($WP db query "SELECT COUNT(*) FROM wp_sseo_embeddings WHERE model_key = '$KEY'" --skip-column-names)"
PENDING="$($WP db query "SELECT COUNT(*) FROM wp_sseo_queue WHERE model_key = '$KEY' AND status <> 'done'" --skip-column-names)"
AUDIT="$($WP db query "SELECT COUNT(*) FROM wp_sseo_audit WHERE action = 'embedding.stored' AND details LIKE '%worker%'" --skip-column-names)"

# Independent check of one stored vector (fixture: NumPy reference; real model: a second process).
ROW="$($WP eval '
global $wpdb;
$r = $wpdb->get_row( "SELECT e.vector_data AS vector, c.text FROM {$wpdb->prefix}sseo_embeddings e JOIN {$wpdb->prefix}sseo_chunks c ON c.document_id = e.document_id AND c.chunk_index = e.chunk_id ORDER BY e.id ASC LIMIT 1", ARRAY_A );
if ( ! $r ) { fwrite( STDERR, "no stored vector: " . $wpdb->last_error . "\n" ); exit( 1 ); }
echo wp_json_encode( array( "text" => $r["text"], "vector" => base64_encode( $r["vector"] ) ) );')"
if [ -n "$REAL" ]; then
	# Independent check: recompute the stored vector in a separate process (tests/real-model/embed_items.py).
	CHECK="$(printf '%s' "$ROW" | "$PY" -I -c '
import sys, json, base64, subprocess
import numpy as np
row = json.load(sys.stdin)
p = subprocess.Popen([sys.executable, "-I", sys.argv[1], "--manifest", sys.argv[2], "--dtype", sys.argv[3]], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
assert json.loads(p.stdout.readline())["ready"]
p.stdin.write(json.dumps({"items": [{"text": row["text"], "role": "document"}]}) + "\n"); p.stdin.flush()
v = np.frombuffer(base64.b64decode(json.loads(p.stdout.readline())["vectors"][0]), dtype="<f4")
p.stdin.close(); p.wait()
stored = np.frombuffer(base64.b64decode(row["vector"]), dtype="<f4")
print("%.3g" % float(np.abs(stored - v).max()))
' "$ROOT/tests/real-model/embed_items.py" "$REAL" "$DTYPE")"
	tail -n 3 "$DIR/.worker.log" || true
else
CHECK="$(printf '%s' "$ROW" | "$PY" -I -c '
import sys, json, base64, importlib.util
import numpy as np
spec = importlib.util.spec_from_file_location("build", sys.argv[1])
b = importlib.util.module_from_spec(spec); spec.loader.exec_module(b)
from tokenizers import Tokenizer
import onnx
from onnx import numpy_helper
row = json.load(sys.stdin)
tok = Tokenizer.from_file(sys.argv[2] + "/tokenizer.json")
tok.enable_truncation(max_length=512)
m = onnx.load(sys.argv[2] + "/onnx/model.onnx")
w = {i.name: numpy_helper.to_array(i) for i in m.graph.initializer}
full, _ = b.reference(tok, w["emb"], w["pos"], w["w"], [row["text"]])
v = full[0][:b.DIM]; v = v / np.linalg.norm(v)
stored = np.frombuffer(base64.b64decode(row["vector"]), dtype="<f4")
print("%.3g" % float(np.abs(stored - v).max()))
' "$ROOT/tests/fixtures/tiny-qwen3/build.py" "$ROOT/tests/fixtures/tiny-qwen3/repo")"
fi

SUFFIX=""; [ -n "$REAL" ] && SUFFIX="-real-$DTYPE"
python3 - "$OUT/private-worker-$V$SUFFIX.json" <<PY
import json, sys
diff = float("$CHECK") if "$CHECK" else 1.0
res = {
  "wordpress": "$V", "model": "$MODEL",
  "worker_exit_code": $RC, "queued": int("$QUEUED" or 0), "stored": int("$STORED" or 0),
  "not_done": int("$PENDING" or 0), "audit_entries": int("$AUDIT" or 0),
  "seconds": round($END - $START, 2), "max_abs_diff_vs_independent_recompute": diff,
}
res["ok"] = res["worker_exit_code"] == 0 and res["queued"] > 0 and res["stored"] == res["queued"] and res["not_done"] == 0 and res["audit_entries"] > 0 and diff < 1e-4
json.dump(res, open(sys.argv[1], "w"), indent=1)
print(json.dumps(res))
sys.exit(0 if res["ok"] else 1)
PY
