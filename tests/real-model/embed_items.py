#!/usr/bin/env python3
"""Line-oriented embedding helper for tests/php/Integration/HybridEvaluationTest.php (CI only).

Loads the real, verified model once with the private worker's Embedder, runs the model-card
self-test, then answers one JSON line per request:

  first line out: {"ready": true, "dtype": ..., "selftest": {...}}
  in:  {"items": [{"text": "...", "role": "query"|"document"}, ...]}
  out: {"vectors": ["<base64 little-endian float32, MRL-truncated to --dimension, L2-normalized>", ...]}
"""
import argparse
import base64
import importlib.util
import json
import os
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--manifest", required=True)
    p.add_argument("--dtype", default="q4f16")
    p.add_argument("--dimension", type=int, default=1024)
    args = p.parse_args()

    spec = importlib.util.spec_from_file_location("sseo_worker", os.path.join(ROOT, "tools", "private-worker", "sseo_worker.py"))
    w = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(w)

    manifest = json.load(open(args.manifest))
    prof = json.loads(subprocess.check_output(["php", os.path.join(ROOT, "tests", "real-model", "export.php"), "profile"]))
    files = dict(manifest["files"]["common"])
    files.update(manifest["files"][args.dtype])
    profile = dict(prof, files=files, dtype=args.dtype, dimension=args.dimension)
    emb = w.Embedder(manifest["root"], profile)
    ok, diff, tol, measured, _ = w.selftest(emb, prof["reference"], args.dtype)
    print(json.dumps({"ready": bool(ok), "dtype": args.dtype, "selftest": {"passed": bool(ok), "max_abs_diff": float(diff), "tolerance": tol, "measured": measured}}), flush=True)
    if not ok:
        return 2
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        items = json.loads(line)["items"]
        texts = [emb.format_query(i["text"]) if i.get("role") == "query" else i["text"] for i in items]
        vecs = emb.embed(texts)
        out = [base64.b64encode(w.mrl(v, args.dimension).tobytes()).decode() for v in vecs]
        print(json.dumps({"vectors": out}), flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
