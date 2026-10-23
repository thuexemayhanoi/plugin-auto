# Release notes — 1.0.0-beta.2 (2026-10-23)

**Status: BETA — all 20 acceptance criteria PASS with evidence.** The lexical engine, the six
modules, Human Mode, Agent Mode (REST, Abilities, MCP), packaging and both inference tiers are
implemented and tested on real WordPress installs. The real Qwen3-Embedding-0.6B model runs in
GitHub Actions (browser worker, private worker, hybrid evaluation); see TEST-REPORT §6. It stays a
beta until the owner has tried it on a staging copy of the blog.

## Artifact

* `dist/semantic-seo-engine-vi-en-1.0.0-beta.2.zip` (one root folder `semantic-seo-engine-vi-en/`,
  ≈ 10.9 MB, of which ≈ 41 MB uncompressed is the bundled ONNX Runtime Web WASM), plus `.sha256`.
  The ZIP is rebuilt by CI (`Build release ZIP + install smoke test` job artifact) and locally by
  `bin/build-zip.sh`. It is not committed to git.
* Requirements: WordPress 6.5+, PHP 7.4+ (`mbstring`).

## Changes since 1.0.0-beta.1 (final audit)

* **Security:** the kses filters suspended for an approved write are restored exactly in a
  `finally` block; an exception thrown by WordPress or another plugin during a write is reverted
  and reported; any change to another post field (title, slug, status…) is detected.
* **Model evidence:** vectors are stored only after the server itself recomputed the device's
  self-test matrix (`POST /embeddings/selftest`, 24 h pass per account and model profile); a
  device's own "passed" is no longer trusted. Update the private worker with the plugin.
* **SEO protection:** every proposal states URL, post ID and risk; term proposals are refused when
  the permalink contains the taxonomy.
* **Overlap report:** different places in titles → distinct facets; different intents →
  information; vector-only pairs → weakest tier; lists ordered by these tiers. On the copy of the
  blog with the real model: 95 competition pairs listed first, "manual review" 563 → 424, nothing
  hidden. "Missing translation" only for content types the site pairs and never for posts the
  language plugin declares standalone (copy of the blog: 1,006 → 5 suggestions).
* ***Max documents* is applied** (it was shown but not enforced); the overview page and
  `GET /status` say when the limit leaves objects out.
* **Performance on large sites:** bounded BM25 candidate search, document frequencies counted once
  per request, covering indexes with deferred-join paging (schema 3), fewer queries. Measured on
  synthetic sites up to 6,000 posts (docs/PERFORMANCE.md). Reproducible ZIP builds.

## Highlights

* Read-only by default; activation leaves content, SEO data, URLs and front-end HTML byte-identical
  (verified on WordPress 6.5.13, 6.9.10, 7.1.3).
* Vietnamese-first admin (complete translation, 757 strings), English available.
* Real Qwen3-Embedding-0.6B (pinned revision, SHA-256-verified): q4f16 default (567 MB), fp16
  option; precision chosen from measurements (q8 removed: least accurate, not reproducible across
  runtimes). Hybrid scoring found 70% of expected links vs 24% lexical-only on the labelled fixture.
* Works with the blog's own language plugin (Nguyen Ha VI-EN Core, read-only adapter) and suggests
  possible unlinked translations from cross-language similarity (never links automatically).
* Every write is a human-approved, hash-bound diff with readback verification and rollback.
* Agents can read/analyse/propose; never approve. MCP exposure is opt-in and read-only.
* Browser inference downloads only pinned, SHA-256-verified files after consent; never on the
  front end.

## Upgrade / rollback

From 1.0.0-beta.1 (if installed from a branch build): the plugin's own tables move to schema 3
automatically on the first request after the update (indexes only, data kept; the migration runs
under a lock and is logged in *Settings & Diagnostics*). Update the private worker together with the
plugin (server-side self-test). To roll back the plugin: deactivate and delete it (data is kept unless
you chose the delete policy). Changes applied through the plugin can be rolled back individually
before removing it.

## What the site owner should do next

1. Install on a **staging** copy of blog.rentbikehanoi.com (VI + /en/) from the ZIP, run the
   wizard and the read-only pilot, review findings.
2. Optionally run the browser model once on a desktop Chrome/Edge with a GPU and note the speed
   shown on the Jobs page (real-GPU speed is the one inference number not measured in CI).
3. Only after review, decide whether to install on production (separate approval).
