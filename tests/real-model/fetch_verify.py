#!/usr/bin/env python3
"""Fetches and verifies the pinned Qwen3-Embedding-0.6B ONNX files (CI only, not shipped).

1. Reads the plugin's pins (includes/Semantic/ModelProfile.php) through tests/real-model/export.php.
2. Cross-checks every pin against the Hugging Face Hub metadata of the pinned revision: LFS files by
   size + SHA-256, small JSON files by size + git blob SHA-1.
3. Downloads the files of the requested dtypes into the private worker's cache layout
   (<cache>/<model_id with __>/<revision>/...) or reuses verified cached copies, and verifies every
   byte (size + SHA-256, or git blob SHA-1 for non-LFS files).

Variants the plugin does not offer (fp32 baseline, and with --dtypes all every other ONNX variant in
the repository) are fetched with pins taken from the Hub metadata and used only as measurements.
Every variant's size and SHA-256 is printed, so new pins can be copied from the log.

Writes a manifest JSON: {"root": dir, "hub_checked": true, "files": {dtype: {path: [size, sha256]}}, ...}
"""
import argparse
import hashlib
import json
import os
import subprocess
import sys
import time
import urllib.parse
import urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
# Transformers.js dtype name of each ONNX variant file stem.
VARIANTS = {
    "model": "fp32",
    "model_fp16": "fp16",
    "model_quantized": "q8",
    "model_int8": "int8",
    "model_uint8": "uint8",
    "model_q4": "q4",
    "model_q4f16": "q4f16",
    "model_bnb4": "bnb4",
}


def variant_files(tree):
    """dtype -> {path: [size, sha256]} for every ONNX graph (with its external data) in the tree."""
    out = {}
    for path in sorted(tree):
        if not path.startswith("onnx/") or not path.endswith(".onnx"):
            continue
        stem = path[len("onnx/"):-len(".onnx")]
        dtype = VARIANTS.get(stem)
        if not dtype:
            continue
        files = {path: [tree[path]["size"], tree[path]["sha256"]]}
        for extra in sorted(tree):
            if extra.startswith(path + "_data") or extra.startswith(path + ".data"):
                files[extra] = [tree[extra]["size"], tree[extra]["sha256"]]
        out[dtype] = files
    return out


def php_profile():
    return json.loads(subprocess.check_output(["php", os.path.join(ROOT, "tests", "real-model", "export.php"), "profile"]))


def get_json(url):
    req = urllib.request.Request(url, headers={"User-Agent": "sseo-ci-model-check"})
    with urllib.request.urlopen(req, timeout=60) as res:
        return json.load(res)


def hub_tree(model_id, revision):
    """path -> {"size", "sha256" (LFS) or "", "blob_sha1" (non-LFS) or ""}"""
    url = "https://huggingface.co/api/models/%s/tree/%s?recursive=true" % (model_id, revision)
    out = {}
    for e in get_json(url):
        if e.get("type") != "file":
            continue
        lfs = e.get("lfs") or {}
        out[e["path"]] = {
            "size": int(lfs.get("size", e.get("size", 0))),
            "sha256": lfs.get("oid", ""),
            "blob_sha1": "" if lfs else e.get("oid", ""),
        }
    return out


def digests(path):
    sha256 = hashlib.sha256()
    size = os.path.getsize(path)
    blob = hashlib.sha1(b"blob %d\0" % size)
    with open(path, "rb") as f:
        while True:
            b = f.read(16 * 1024 * 1024)
            if not b:
                break
            sha256.update(b)
            blob.update(b)
    return size, sha256.hexdigest(), blob.hexdigest()


def ok(path, meta):
    if not os.path.isfile(path):
        return False
    size, sha256, blob = digests(path)
    if size != meta["size"]:
        return False
    if meta["sha256"]:
        return sha256 == meta["sha256"]
    return blob == meta["blob_sha1"]


def download(url, dest, meta, log):
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    tmp = dest + ".part"
    for attempt in range(1, 5):
        try:
            t0 = time.time()
            req = urllib.request.Request(url, headers={"User-Agent": "sseo-ci-model-check"})
            got = 0
            with urllib.request.urlopen(req, timeout=120) as res, open(tmp, "wb") as out:
                while True:
                    b = res.read(8 * 1024 * 1024)
                    if not b:
                        break
                    got += len(b)
                    if got > meta["size"]:
                        raise IOError("larger than the pinned size")
                    out.write(b)
            os.replace(tmp, dest)
            log("  downloaded %s: %d bytes in %.1fs" % (os.path.basename(dest), got, time.time() - t0))
            return
        except Exception as e:  # noqa: BLE001 - retried, then fatal
            log("  attempt %d failed: %s" % (attempt, e))
            if os.path.exists(tmp):
                os.unlink(tmp)
            time.sleep(2 ** attempt)
    raise SystemExit("download failed: %s" % url)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--cache-dir", default=os.path.expanduser("~/.cache/sseo-worker"))
    p.add_argument("--dtypes", default="q8,q4f16,fp32")
    p.add_argument("--manifest", required=True)
    args = p.parse_args()
    log = lambda m: print(m, flush=True)  # noqa: E731

    prof = php_profile()
    model_id, rev = prof["model_id"], prof["revision"]
    tree = hub_tree(model_id, rev)
    log("Hub tree of %s @ %s: %d files" % (model_id, rev, len(tree)))
    variants = variant_files(tree)
    for dtype, files in variants.items():
        for path, (size, sha) in files.items():
            log("hub %-6s %-32s %12d  sha256 %s" % (dtype, path, size, sha))

    # 1. Every plugin pin must match the Hub metadata of the pinned revision.
    pin_report = []
    for group, files in prof["files"].items():
        for path, (size, sha) in files.items():
            hub = tree.get(path)
            match = hub is not None and hub["size"] == int(size) and (not sha or hub["sha256"] == sha)
            pin_report.append({"group": group, "path": path, "size": int(size), "sha256": sha, "hub": hub, "match": match})
            log("pin %-6s %-28s %s" % (group, path, "MATCHES Hub" if match else "MISMATCH %s" % hub))
    if not all(r["match"] for r in pin_report):
        raise SystemExit("Plugin pins do not match the Hub metadata of the pinned revision.")

    # 2. Files to fetch: plugin-pinned dtypes use the plugin pins (already matched against the Hub);
    # other variants ("all") use the Hub metadata and serve only as measurements.
    wanted = {"common": prof["files"]["common"]}
    names = list(variants) if args.dtypes == "all" else [x for x in args.dtypes.split(",") if x]
    for d in names:
        if d in prof["files"]:
            wanted[d] = prof["files"][d]
        elif d in variants:
            wanted[d] = variants[d]
        else:
            log("%s not present at this revision; skipped" % d)

    root = os.path.join(args.cache_dir, model_id.replace("/", "__"), rev)
    for group, files in wanted.items():
        for path in sorted(files):
            meta = tree[path]
            dest = os.path.join(root, *path.split("/"))
            if ok(dest, meta):
                log("verified (cached) %s" % path)
                continue
            url = "https://huggingface.co/%s/resolve/%s/%s" % (model_id, rev, urllib.parse.quote(path))
            log("fetching %s (%d bytes)" % (path, meta["size"]))
            download(url, dest, meta, log)
            if not ok(dest, meta):
                os.unlink(dest)
                raise SystemExit("Integrity check failed for %s" % path)
            log("verified %s" % path)

    manifest = {
        "model_id": model_id,
        "revision": rev,
        "root": root,
        "hub_checked": True,
        "pins": pin_report,
        "files": wanted,
        "plugin_dtypes": sorted(k for k in prof["files"] if k != "common"),
        "when": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    with open(args.manifest, "w") as f:
        json.dump(manifest, f, indent=1)
    log("manifest written: %s" % args.manifest)


if __name__ == "__main__":
    sys.exit(main())
