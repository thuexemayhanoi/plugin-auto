# Release notes — 1.0.0-beta.1 (2026-10-23)

**Status: BETA — not "fully AI-verified".** The lexical engine, the six modules, Human Mode, Agent
Mode (REST, Abilities, MCP), packaging and the browser/private-worker *pipelines* are implemented
and tested on real WordPress installs. Real Qwen3-Embedding-0.6B inference has **not** been run:
the model host was unreachable from the build environment (AC06 BLOCKED).

## Artifact

* `dist/semantic-seo-engine-vi-en-1.0.0-beta.1.zip` (one root folder `semantic-seo-engine-vi-en/`,
  ≈ 10.9 MB, of which ≈ 41 MB uncompressed is the bundled ONNX Runtime Web WASM), plus `.sha256`.
  The ZIP is rebuilt by CI (`Build release ZIP + install smoke test` job artifact) and locally by
  `bin/build-zip.sh`. It is not committed to git.
* Requirements: WordPress 6.5+, PHP 7.4+ (`mbstring`).

## Highlights

* Read-only by default; activation leaves content, SEO data, URLs and front-end HTML byte-identical
  (verified on WordPress 6.5.13, 6.9.10, 7.1.3).
* Vietnamese-first admin (complete translation, 711 strings), English available.
* Every write is a human-approved, hash-bound diff with readback verification and rollback.
* Agents can read/analyse/propose; never approve. MCP exposure is opt-in and read-only.
* Browser inference downloads only pinned, SHA-256-verified files after consent; never on the
  front end.

## Upgrade / rollback

First release — nothing to migrate. To roll back the plugin: deactivate and delete it (data is
kept unless you chose the delete policy). Changes applied through the plugin can be rolled back
individually before removing it.

## What the site owner should do next

1. Install on a **staging** copy of blog.rentbikehanoi.com (VI + /en/) from the ZIP, run the
   wizard and the read-only pilot, review findings.
2. Optionally run the browser model once on a desktop Chrome/Edge with WebGPU and report whether
   the self-test passes — this is the remaining step to verify AC06.
3. Only after review, decide whether to install on production (separate approval).
