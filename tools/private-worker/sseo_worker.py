#!/usr/bin/env python3
"""
Semantic SEO Engine VI-EN — optional private embedding worker (Tier 2).

Runs on a machine YOU control (laptop, server, CI runner). It reads the pinned model profile from
your WordPress site, downloads exactly the pinned files of the pinned revision from the profile's
host, verifies size + SHA-256, reproduces the model-card reference similarities (self-test), and
then processes the embedding queue through the plugin's REST API with a dedicated low-privilege
account (role "SEO Embedding Worker") and an Application Password.

It is not a hosted AI service and is never required: the plugin's lexical tier works without it.

Security properties
  * credentials come from the SSEO_APP_PASSWORD environment variable (never a CLI argument);
  * HTTPS is required unless --allow-http is given for a local test site;
  * leases are single-use and expire server-side (replay protection); results are bounded (16/claim);
  * the server re-validates model key, dimension, norm and content hash before storing anything.

Requirements: Python 3.9+, numpy, onnxruntime, tokenizers (see requirements.txt).

Usage
  export SSEO_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
  python sseo_worker.py --site https://example.com --user seo-worker [--once] [--batch 8]
"""

import argparse
import base64
import hashlib
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

import numpy as np

VERSION = "1.0.0-beta.1"
MAX_BATCH = 16


class ApiError(Exception):
    def __init__(self, status, code, message, retry_after=0):
        super().__init__("%s %s: %s" % (status, code, message))
        self.status = status
        self.code = code
        self.retry_after = retry_after


class Client:
    """Minimal REST client for the semantic-seo/v1 namespace."""

    def __init__(self, site, user, password, allow_http=False, timeout=60):
        parsed = urllib.parse.urlparse(site)
        if parsed.scheme != "https" and not allow_http:
            raise SystemExit("Refusing to send credentials over %s:// (use HTTPS, or --allow-http for a local test site)." % parsed.scheme)
        self.base = site.rstrip("/") + "/wp-json/semantic-seo/v1"
        self.auth = "Basic " + base64.b64encode(("%s:%s" % (user, password)).encode()).decode()
        self.timeout = timeout

    def request(self, method, path, body=None):
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(self.base + path, data=data, method=method)
        req.add_header("Authorization", self.auth)
        req.add_header("Accept", "application/json")
        req.add_header("User-Agent", "sseo-private-worker/" + VERSION)
        if data is not None:
            req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as res:
                payload = json.loads(res.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            try:
                payload = json.loads(e.read().decode("utf-8"))
            except Exception:  # noqa: BLE001 - non-JSON error page
                payload = {}
            err = payload.get("error") or {}
            raise ApiError(e.code, err.get("code", "http_error"), err.get("message", str(e)), int(e.headers.get("Retry-After") or 0))
        if not payload.get("ok"):
            err = payload.get("error") or {}
            raise ApiError(200, err.get("code", "error"), err.get("message", "unexpected response"))
        return payload["data"]


def sha256_file(path, chunk=8 * 1024 * 1024):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while True:
            b = f.read(chunk)
            if not b:
                break
            h.update(b)
    return h.hexdigest()


def verified(path, size, digest):
    if not os.path.isfile(path) or os.path.getsize(path) != size:
        return False
    return not digest or sha256_file(path) == digest


def fetch_pinned(profile, cache_dir, log):
    """Downloads every pinned file (or reuses a verified cached copy). Returns the local directory."""
    host = profile["host"].rstrip("/")
    if not host.startswith("https://") and not host.startswith("http://127.0.0.1") and not host.startswith("http://localhost"):
        raise SystemExit("Model host must be HTTPS: %s" % host)
    root = os.path.join(cache_dir, profile["model_id"].replace("/", "__"), profile["revision"])
    for rel, (size, digest) in sorted(profile["files"].items()):
        if ".." in rel.split("/") or rel.startswith("/"):
            raise SystemExit("Unsafe path in profile: %s" % rel)
        dest = os.path.join(root, *rel.split("/"))
        if verified(dest, int(size), digest):
            log("cached and verified: %s" % rel)
            continue
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        url = "%s/%s/resolve/%s/%s" % (host, profile["model_id"], profile["revision"], urllib.parse.quote(rel))
        tmp = dest + ".part"
        h = hashlib.sha256()
        got = 0
        log("downloading %s (%d bytes)" % (rel, int(size)))
        req = urllib.request.Request(url, headers={"User-Agent": "sseo-private-worker/" + VERSION})
        with urllib.request.urlopen(req, timeout=120) as res, open(tmp, "wb") as out:
            while True:
                b = res.read(4 * 1024 * 1024)
                if not b:
                    break
                got += len(b)
                if got > int(size):
                    raise SystemExit("Download of %s exceeds the pinned size; aborting." % rel)
                h.update(b)
                out.write(b)
        if got != int(size) or (digest and h.hexdigest() != digest):
            os.unlink(tmp)
            raise SystemExit("Integrity check failed for %s (size %d, sha256 %s)." % (rel, got, h.hexdigest()))
        os.replace(tmp, dest)
    return root


class Embedder:
    """Tokenizer + ONNX Runtime session reproducing the browser worker's computation."""

    def __init__(self, model_dir, profile, threads=0):
        import onnxruntime as ort
        from tokenizers import Tokenizer

        self.profile = profile
        with open(os.path.join(model_dir, "tokenizer_config.json"), encoding="utf-8") as f:
            tok_cfg = json.load(f)
        with open(os.path.join(model_dir, "config.json"), encoding="utf-8") as f:
            self.config = json.load(f)
        self.tok = Tokenizer.from_file(os.path.join(model_dir, "tokenizer.json"))
        pad_token = tok_cfg.get("pad_token") or tok_cfg.get("eos_token")
        if isinstance(pad_token, dict):
            pad_token = pad_token.get("content")
        pad_id = self.tok.token_to_id(pad_token) if pad_token else 0
        self.tok.enable_padding(direction=tok_cfg.get("padding_side", "right"), pad_id=pad_id or 0, pad_token=pad_token or "")
        self.tok.enable_truncation(max_length=int(profile.get("max_tokens", 512)))
        onnx_files = [p for p in profile["files"] if p.endswith(".onnx")]
        if len(onnx_files) != 1:
            raise SystemExit("Expected exactly one .onnx graph in the profile, got %s" % onnx_files)
        opts = ort.SessionOptions()
        if threads:
            opts.intra_op_num_threads = threads
        self.session = ort.InferenceSession(os.path.join(model_dir, *onnx_files[0].split("/")), opts, providers=["CPUExecutionProvider"])
        self.inputs = {i.name: i for i in self.session.get_inputs()}

    def _feeds(self, ids, mask):
        feeds = {"input_ids": ids, "attention_mask": mask}
        if "position_ids" in self.inputs:
            pos = np.cumsum(mask, axis=1) - 1
            pos[mask == 0] = 1
            feeds["position_ids"] = pos.astype(np.int64)
        kv_heads = int(self.config.get("num_key_value_heads", self.config.get("num_attention_heads", 1)))
        head_dim = int(self.config.get("head_dim", int(self.config.get("hidden_size", 0)) // max(1, int(self.config.get("num_attention_heads", 1)))))
        for name, meta in self.inputs.items():
            if name in feeds:
                continue
            if name.startswith("past_key_values"):
                dtype = np.float16 if "float16" in meta.type else np.float32
                feeds[name] = np.zeros((ids.shape[0], kv_heads, 0, head_dim), dtype=dtype)
            elif name == "num_logits_to_keep":
                feeds[name] = np.array(0, dtype=np.int64)
            else:
                raise SystemExit("Unsupported model input: %s" % name)
        return feeds

    def embed(self, texts, batch=4):
        """Full-dimension, L2-normalized last-token embeddings."""
        out = []
        for start in range(0, len(texts), batch):
            enc = self.tok.encode_batch(texts[start:start + batch])
            ids = np.array([e.ids for e in enc], dtype=np.int64)
            mask = np.array([e.attention_mask for e in enc], dtype=np.int64)
            hidden = self.session.run(None, self._feeds(ids, mask))[0].astype(np.float32)
            seq = hidden.shape[1]
            left = bool(np.all(mask[:, seq - 1] == 1))
            for b in range(hidden.shape[0]):
                idx = seq - 1 if left else int(mask[b].sum()) - 1
                v = hidden[b, idx, :]
                out.append(v / max(float(np.linalg.norm(v)), 1e-12))
        return out

    def format_query(self, text):
        return "Instruct: %s\nQuery:%s" % (self.profile["query_task"], text)


def mrl(v, dim):
    cut = v[:dim] if dim and dim < len(v) else v
    return (cut / max(float(np.linalg.norm(cut)), 1e-12)).astype("<f4")


def expected_scores(reference, dtype):
    """Matrix measured for this precision with the real pinned files, else the model-card matrix."""
    by_dtype = reference.get("scores_by_dtype") or {}
    return by_dtype.get(dtype) or reference["scores"]


def selftest(embedder, reference, dtype):
    q = embedder.embed([embedder.format_query(x) for x in reference["queries"]])
    d = embedder.embed(list(reference["documents"]))
    measured = [[float(np.dot(a, b)) for b in d] for a in q]
    expected = expected_scores(reference, dtype)
    tol = float(reference["tolerance"].get(dtype, 0.03))
    diff = max(abs(measured[i][j] - expected[i][j]) for i in range(len(measured)) for j in range(len(measured[i])))
    rank_ok = all(int(np.argmax(measured[i])) == int(np.argmax(expected[i])) for i in range(len(measured)))
    return diff <= tol and rank_ok, diff, tol, measured, (q, d)


def server_selftest(client, profile, vectors):
    """Sends the reference vectors; the server recomputes the matrix (a local pass is only a claim)."""
    q, d = vectors
    return client.request("POST", "/embeddings/selftest", {
        "model_key": profile["model_key"],
        "source": "worker",
        "queries": [[float(x) for x in v] for v in q],
        "documents": [[float(x) for x in v] for v in d],
    })


def run(args):
    password = os.environ.get("SSEO_APP_PASSWORD", "")
    if not password:
        raise SystemExit("Set SSEO_APP_PASSWORD (an Application Password of the worker account).")
    log = (lambda m: None) if args.quiet else (lambda m: print(time.strftime("%H:%M:%S"), m, flush=True))
    client = Client(args.site, args.user, password, allow_http=args.allow_http)
    info = client.request("GET", "/embeddings/profile")
    profile = info["profile"]
    log("profile %s @ %s, %s, dim %s, key %s" % (profile["model_id"], profile["revision"][:12], profile["dtype"], profile["dimension"], profile["model_key"][:12]))
    model_dir = fetch_pinned(profile, os.path.expanduser(args.cache_dir), log)
    embedder = Embedder(model_dir, profile, threads=args.threads)
    ok, diff, tol, measured, vectors = selftest(embedder, info["reference"], profile["dtype"])
    log("self-test %s: max diff %.6f (tolerance %s) %s" % ("PASSED" if ok else "FAILED", diff, tol, measured))
    if not ok:
        raise SystemExit(2)
    check = server_selftest(client, profile, vectors)
    log("server check %s: max diff %s (tolerance %s)" % ("PASSED" if check.get("verified") else "FAILED", check.get("max_abs_diff"), check.get("tolerance")))
    if not check.get("verified"):
        raise SystemExit(2)
    if args.selftest_only:
        return 0
    stored = 0
    batch = max(1, min(MAX_BATCH, args.batch))
    while True:
        try:
            claim = client.request("POST", "/embeddings/claim", {"limit": batch})
        except ApiError as e:
            if e.status == 429:
                time.sleep(max(5, e.retry_after))
                continue
            raise
        if claim["profile"]["model_key"] != profile["model_key"]:
            client.request("POST", "/embeddings/release", {"lease_token": claim["lease_token"], "error": "profile changed"})
            raise SystemExit("Model profile changed on the site; restart the worker.")
        items = claim["items"]
        if not items:
            log("queue empty — %d vectors stored" % stored)
            if args.once:
                return 0
            time.sleep(args.poll)
            continue
        token = claim["lease_token"]
        try:
            t0 = time.time()
            texts = [embedder.format_query(i["text"]) if i.get("role") == "query" else i["text"] for i in items]
            vectors = embedder.embed(texts)
            results = [{"queue_id": i["queue_id"], "vector": base64.b64encode(mrl(v, int(profile["dimension"])).tobytes()).decode()} for i, v in zip(items, vectors)]
            res = client.request("POST", "/embeddings/complete", {"lease_token": token, "model_key": profile["model_key"], "source": "worker", "results": results})
        except Exception as e:  # noqa: BLE001 - always return the lease
            try:
                client.request("POST", "/embeddings/release", {"lease_token": token, "error": str(e)[:240]})
            finally:
                raise
        stored += res["stored"]
        log("%d stored, %d rejected in %.2fs" % (res["stored"], len(res["rejected"]), time.time() - t0))
        for r in res["rejected"]:
            log("  rejected #%s: %s" % (r["queue_id"], r["reason"]))
        if args.max_items and stored >= args.max_items:
            return 0


def main(argv=None):
    p = argparse.ArgumentParser(description="Semantic SEO Engine VI-EN private embedding worker")
    p.add_argument("--site", required=True, help="WordPress site URL, e.g. https://example.com")
    p.add_argument("--user", required=True, help="Worker account login (role: SEO Embedding Worker)")
    p.add_argument("--cache-dir", default="~/.cache/sseo-worker")
    p.add_argument("--batch", type=int, default=8)
    p.add_argument("--threads", type=int, default=0)
    p.add_argument("--once", action="store_true", help="Stop when the queue is empty")
    p.add_argument("--poll", type=int, default=60, help="Seconds between polls when idle")
    p.add_argument("--max-items", type=int, default=0)
    p.add_argument("--selftest-only", action="store_true")
    p.add_argument("--allow-http", action="store_true", help="Allow http:// (local test sites only)")
    p.add_argument("--quiet", action="store_true")
    return run(p.parse_args(argv))


if __name__ == "__main__":
    sys.exit(main())
