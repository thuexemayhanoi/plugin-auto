#!/usr/bin/env python3
"""Real Qwen3-Embedding-0.6B checks on the pinned, verified ONNX files (CI only, not shipped).

Uses the private worker's own Embedder (tools/private-worker/sseo_worker.py: tokenizer, ONNX Runtime
CPU, last-token pooling, L2) for every fetched dtype and records, without thresholds tuned to pass:

* self-test: query/document similarity matrix vs the fp32 values published on the model card;
* agreement of each dtype with fp32 on the bilingual labelled fixture corpus (cosine per document);
* cross-language similarity: VI/EN translation pairs vs non-pairs, and top-1 translation retrieval;
* semantic-only same-locale retrieval on the fixture labels (P@3, R@5), document level;
* load and embedding times on the CI runner.

Writes <out>/real-model.json and <out>/real-model-vectors.json (inputs + vectors per dtype for the
browser comparison). Exit code 1 when fp32 or a dtype the plugin offers fails its self-test.
"""
import argparse
import importlib.util
import json
import os
import platform
import subprocess
import sys
import time

import numpy as np

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


def worker_module():
    spec = importlib.util.spec_from_file_location("sseo_worker", os.path.join(ROOT, "tools", "private-worker", "sseo_worker.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def export(what):
    return json.loads(subprocess.check_output(["php", os.path.join(ROOT, "tests", "real-model", "export.php"), what]))


def stats(xs):
    xs = [float(x) for x in xs]
    if not xs:
        return None
    return {"n": len(xs), "min": round(min(xs), 4), "mean": round(float(np.mean(xs)), 4), "max": round(max(xs), 4)}


def negative_controls(w, emb, prof, dtype):
    """Deliberately wrong pipelines with this dtype: its self-test (expected matrix + tolerance)
    must reject every one of them."""
    ref = prof["reference"]
    tol = float(ref["tolerance"].get(dtype, 0.03))
    expected = w.expected_scores(ref, dtype)
    out = {}

    def matrix(q, d):
        return [[float(np.dot(a, b)) for b in d] for a in q]

    def judge(m):
        diff = max(abs(m[i][j] - expected[i][j]) for i in range(2) for j in range(2))
        rank = all(int(np.argmax(m[i])) == int(np.argmax(expected[i])) for i in range(2))
        return {"max_abs_diff": round(diff, 4), "ranking_ok": rank, "tolerance": tol, "rejected": not (diff <= tol and rank)}

    queries = [emb.format_query(x) for x in ref["queries"]]
    out["no_query_instruction"] = judge(matrix(emb.embed(list(ref["queries"])), emb.embed(list(ref["documents"]))))

    def pooled(texts, mode):
        enc = emb.tok.encode_batch(texts)
        ids = np.array([e.ids for e in enc], dtype=np.int64)
        mask = np.array([e.attention_mask for e in enc], dtype=np.int64)
        hidden = emb.session.run(None, emb._feeds(ids, mask))[0].astype(np.float32)
        vecs = []
        for b in range(hidden.shape[0]):
            valid = np.where(mask[b] == 1)[0]
            if mode == "mean":
                v = hidden[b, valid, :].mean(axis=0)
            elif mode == "first":
                v = hidden[b, valid[0], :]
            else:  # second-to-last real token: as if the appended end-of-text token were missing
                v = hidden[b, valid[-2], :]
            vecs.append(v / max(float(np.linalg.norm(v)), 1e-12))
        return vecs

    for mode in ("mean", "first", "without_eos"):
        out[mode + "_pooling" if mode != "without_eos" else "last_token_without_eos"] = judge(matrix(pooled(queries, mode), pooled(list(ref["documents"]), mode)))
    return out


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--manifest", required=True)
    p.add_argument("--out", default=os.path.join(ROOT, "tests", "e2e", "artifacts"))
    p.add_argument("--threads", type=int, default=0)
    args = p.parse_args()
    os.makedirs(args.out, exist_ok=True)

    w = worker_module()
    import onnxruntime as ort
    import tokenizers

    manifest = json.load(open(args.manifest))
    prof = export("profile")
    corpus = export("corpus")
    docs = [d for d in corpus["docs"]]
    texts = [d["text"] for d in docs]
    # Longer than max_tokens (Qwen splits every digit into its own token): the browser must keep the
    # end-of-text token when it truncates, as the private worker's tokenizer does. Synthetic test text.
    long_text = "Mẫu kiểm thử bảng số liệu. " + " ".join(
        "Dòng %d: mẫu xe %d, %d.%03d.000 đồng mỗi ngày, %d.%03d.000 đồng mỗi tuần, đặt cọc %d.%03d.000 đồng." % (i, i * 7, i % 9 + 1, i * 13 % 1000, i % 7 + 2, i * 29 % 1000, i % 5 + 2, i * 31 % 1000)
        for i in range(1, 61)
    )
    offered = [d for d in prof["files"] if d != "common"]

    report = {
        "what": "REAL Qwen3-Embedding-0.6B ONNX (%s @ %s), pinned files verified against the Hub metadata" % (manifest["model_id"], manifest["revision"]),
        "runtime": {
            "python": platform.python_version(),
            "onnxruntime": ort.__version__,
            "tokenizers": tokenizers.__version__,
            "numpy": np.__version__,
            "machine": platform.machine(),
            "cpu_count": os.cpu_count(),
        },
        "reference": prof["reference"],
        "dtypes": {},
    }
    vectors = {}
    browser_vectors = {"items": [], "vectors": {}}
    failed_offered = []

    order = ["fp32", "fp16", "q8", "int8", "uint8", "q4", "q4f16", "bnb4"]
    plugin_dtypes = manifest.get("plugin_dtypes", offered)
    for dtype in [d for d in order if d in manifest["files"]]:
        files = dict(manifest["files"]["common"])
        files.update(manifest["files"][dtype])
        profile = dict(prof, files=files, dtype=dtype)
        if dtype not in prof["reference"]["tolerance"]:
            # Not offered by the plugin: measured only, compared with the loosest plugin tolerance.
            prof["reference"]["tolerance"][dtype] = max(prof["reference"]["tolerance"].values())
        entry = {"files": sorted(manifest["files"][dtype]), "offered_by_plugin": dtype in plugin_dtypes, "bytes": sum(int(v[0]) for v in manifest["files"][dtype].values())}
        try:
            t0 = time.time()
            emb = w.Embedder(manifest["root"], profile, threads=args.threads)
            entry["load_seconds"] = round(time.time() - t0, 2)
            ok, diff, tol, measured, _ = w.selftest(emb, prof["reference"], dtype)
            card = prof["reference"]["scores"]
            entry["selftest"] = {
                "passed": bool(ok), "max_abs_diff": round(float(diff), 5), "tolerance": tol,
                "expected": "measured matrix for this dtype" if dtype in prof["reference"].get("scores_by_dtype", {}) else "model card (fp32)",
                "measured": [[round(x, 5) for x in r] for r in measured],
                "max_abs_diff_vs_model_card": round(max(abs(measured[i][j] - card[i][j]) for i in range(2) for j in range(2)), 5),
            }
            t0 = time.time()
            vecs = np.array(emb.embed(texts))
            entry["corpus_docs"] = len(texts)
            entry["embed_seconds_per_doc"] = round((time.time() - t0) / len(texts), 3)
            vectors[dtype] = vecs
            if dtype in ("q4f16", "fp16", "q8", "q4", "bnb4"):
                q = [emb.format_query(x) for x in prof["reference"]["queries"]]
                inputs = [{"text": x, "role": "query"} for x in prof["reference"]["queries"]] + [{"text": x, "role": "document"} for x in prof["reference"]["documents"]] + [{"text": d["text"], "role": "document"} for d in docs[:4]] + [{"text": long_text, "role": "document"}]
                ref_vecs = emb.embed(q) + emb.embed(list(prof["reference"]["documents"])) + [v for v in vecs[:4]] + emb.embed([long_text])
                report["long_text_tokens"] = len(emb.tok.encode(long_text).ids)
                browser_vectors["items"] = inputs
                browser_vectors["vectors"][dtype] = [[float(x) for x in v] for v in ref_vecs]
        except Exception as e:  # noqa: BLE001 - recorded, not hidden
            entry["error"] = "%s: %s" % (type(e).__name__, str(e)[:500])
        report["dtypes"][dtype] = entry
        print(dtype, json.dumps({k: v for k, v in entry.items() if k != "files"}), flush=True)
        if dtype in plugin_dtypes and not entry.get("selftest", {}).get("passed"):
            failed_offered.append(dtype)
        if dtype in ["fp32"] + plugin_dtypes and not entry.get("selftest", {}).get("passed"):
            report.setdefault("pipeline_failures", []).append(dtype)
        if dtype in ["fp32"] + plugin_dtypes and "error" not in entry:
            report.setdefault("negative_controls", {})[dtype] = negative_controls(w, emb, prof, dtype)

    # Agreement with fp32: per-document cosine and same-locale top-5 neighbour overlap.
    if "fp32" in vectors:
        F = vectors["fp32"]
        live = [i for i, d in enumerate(docs) if d["key"] not in ("vi-trashed", "vi-draft")]

        def top5(V, i):
            cands = [j for j in live if j != i and docs[j]["lang"] == docs[i]["lang"]]
            return set(sorted(cands, key=lambda j: -float(V[i] @ V[j]))[:5])

        for dtype, vecs in vectors.items():
            if dtype != "fp32":
                cos = np.sum(vecs * F, axis=1)
                report["dtypes"][dtype]["cosine_vs_fp32"] = stats(cos)
                report["dtypes"][dtype]["top5_neighbour_overlap_vs_fp32"] = round(float(np.mean([len(top5(vecs, i) & top5(F, i)) / 5.0 for i in live])), 3)

    # Cross-language and same-locale retrieval with the best available dtype.
    base = "fp32" if "fp32" in vectors else ("q4f16" if "q4f16" in vectors else None)
    if base:
        V = vectors[base]
        idx = {d["key"]: i for i, d in enumerate(docs)}
        publishable = [i for i, d in enumerate(docs) if d["key"] not in ("vi-trashed", "vi-draft")]
        groups = {}
        for i, d in enumerate(docs):
            if d["group"]:
                groups.setdefault(d["group"], []).append(i)
        pairs = []
        for g, members in groups.items():
            vi = [i for i in members if docs[i]["lang"] == "vi"]
            en = [i for i in members if docs[i]["lang"] == "en"]
            if len(vi) == 1 and len(en) == 1:
                pairs.append((vi[0], en[0]))
        pair_set = set(pairs)
        vi_all = [i for i in publishable if docs[i]["lang"] == "vi"]
        en_all = [i for i in publishable if docs[i]["lang"] == "en"]
        pos = [float(V[a] @ V[b]) for a, b in pairs]
        neg = [float(V[a] @ V[b]) for a in vi_all for b in en_all if (a, b) not in pair_set]
        top1 = sum(1 for a, b in pairs if max(en_all, key=lambda j: float(V[a] @ V[j])) == b)
        report["cross_language"] = {
            "dtype": base,
            "translation_pairs": stats(pos),
            "non_pairs": stats(neg),
            "top1_translation_retrieval": "%d/%d" % (top1, len(pairs)),
            "pairs": {docs[a]["key"] + " ~ " + docs[b]["key"]: round(float(V[a] @ V[b]), 4) for a, b in pairs},
        }
        p3 = []
        r5 = []
        rel_sims = []
        other_sims = []
        for src, rel in corpus["labels"]["relevant"].items():
            s = idx[src]
            cands = [i for i in publishable if i != s and docs[i]["lang"] == docs[s]["lang"]]
            ranked = sorted(cands, key=lambda j: -float(V[s] @ V[j]))
            keys = [docs[j]["key"] for j in ranked]
            p3.append(len(set(keys[:3]) & set(rel)) / 3.0)
            r5.append(len(set(keys[:5]) & set(rel)) / float(len(rel)))
            for j in cands:
                (rel_sims if docs[j]["key"] in rel else other_sims).append(float(V[s] @ V[j]))
        controls = [float(V[idx[a]] @ V[idx[b]]) for a, b in corpus["labels"]["not_competition"]]
        overlaps = [float(V[idx[a]] @ V[idx[b]]) for a, b, _ in corpus["labels"]["expected_overlap"]]
        report["semantic_only_same_locale"] = {
            "dtype": base,
            "note": "document-level cosine (title + text, first 512 tokens), fixture labels; the plugin itself uses lexical candidates + hybrid reranking",
            "precision@3": round(float(np.mean(p3)), 3),
            "recall@5": round(float(np.mean(r5)), 3),
            "similarity_relevant_pairs": stats(rel_sims),
            "similarity_other_same_locale_pairs": stats(other_sims),
            "similarity_must_not_compete_controls": stats(controls),
            "similarity_expected_overlaps": stats(overlaps),
        }

    report["failed_offered_dtypes"] = failed_offered
    report["all_negative_controls_rejected"] = all(c["rejected"] for d in report.get("negative_controls", {}).values() for c in d.values())
    json.dump(browser_vectors, open(os.path.join(args.out, "real-model-vectors.json"), "w"))
    json.dump(report, open(os.path.join(args.out, "real-model.json"), "w"), indent=1, ensure_ascii=False)
    print("dtype  bytes        diff_vs_card   diff_vs_expect ok     cos_vs_fp32(min/mean)  top5_overlap  s/doc")
    for dtype, e in report["dtypes"].items():
        st = e.get("selftest", {})
        cv = e.get("cosine_vs_fp32") or {}
        print("%-6s %-12s %-14s %-14s %-6s %-22s %-13s %s" % (dtype, e.get("bytes"), st.get("max_abs_diff_vs_model_card", e.get("error", "")[:40]), st.get("max_abs_diff"), st.get("passed"), "%s/%s" % (cv.get("min"), cv.get("mean")) if cv else "-", e.get("top5_neighbour_overlap_vs_fp32", "-"), e.get("embed_seconds_per_doc")))
    print(json.dumps({k: report[k] for k in ("negative_controls", "all_negative_controls_rejected", "cross_language", "semantic_only_same_locale", "failed_offered_dtypes", "pipeline_failures") if k in report}, ensure_ascii=False))
    # fp32 proves the pipeline reproduces the model card; every offered dtype must pass its own
    # self-test, and every deliberately wrong pipeline must be rejected. Other variants are measured.
    return 1 if report.get("pipeline_failures") or not report["all_negative_controls_rejected"] else 0


if __name__ == "__main__":
    sys.exit(main())
