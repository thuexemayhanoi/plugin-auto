# Installation guide — Semantic SEO Engine VI–EN 1.0.0-beta.1

> Tiếng Việt: xem [USER-GUIDE-VI.md](USER-GUIDE-VI.md) mục 1 cho phần cài đặt tóm tắt.

## 1. Requirements

| | Minimum | Tested |
| --- | --- | --- |
| WordPress | 6.5 | 6.5.13, 6.9.10, 7.1.3 (see [COMPATIBILITY.md](COMPATIBILITY.md)) |
| PHP | 7.4 (`mbstring` required, `intl` recommended, `zip` for XLSX export) | 8.3.6 locally; 7.4–8.4 in CI |
| Database | MySQL 5.7+/MariaDB 10.4+ (InnoDB, utf8mb4) | MariaDB 10.11 |
| Optional | WordPress 6.9+ for the Abilities API; the official MCP Adapter plugin for MCP clients; Polylang or WPML for bilingual sites | Polylang 3.9.1, MCP Adapter 0.7.1 |

Shared hosting is enough for everything except embeddings: the semantic model never runs on the server. It runs in an administrator's browser (Tier 1) or in an optional private worker you control (Tier 2).

## 2. Before you install on a live site

1. Take a full backup (files + database). The plugin is read-only by default, but backups are the right habit before any plugin installation.
2. Prefer a staging copy first. Nothing in this beta has been run on your production site.
3. Note your current SEO setup (SEO plugin, language plugin, permalink structure). The plugin reads these and never changes them.

## 3. Install from the ZIP

1. Download `semantic-seo-engine-vi-en-1.0.0-beta.1.zip` (and its `.sha256`). Optional check: `sha256sum -c semantic-seo-engine-vi-en-1.0.0-beta.1.zip.sha256`.
2. WP Admin → **Plugins → Add New → Upload Plugin** → choose the ZIP → **Install Now** → **Activate**.
   *WP-CLI:* `wp plugin install semantic-seo-engine-vi-en-1.0.0-beta.1.zip --activate`
3. What activation does (verified by `tests/e2e/smoke-zip.sh` on WordPress 6.5.13, 6.9.10 and 7.1.3):
   * creates 16 tables `{prefix}sseo_*` and the options `sseo_settings`, `sseo_db_version`, `sseo_migration_log`;
   * does **not** change posts, post meta, terms, users, roles, other options, rewrite rules or front-end HTML (byte-identical before/after);
   * starts no scan and contacts no external service.

## 4. First run (5-step wizard)

Open **Semantic SEO → Tổng quan & Thiết lập (Overview & Setup)**:

1. **Compatibility check** — WordPress/PHP versions, tables, `intl`, Abilities API, language framework (Polylang/WPML/URL prefix/site default), SEO metadata source.
2. **Compute tiers** — Tier 0 lexical works immediately; Tier 1/2 are optional.
3. **Scope** — post types, exclusions, batch size, maximum documents.
4. **Read-only pilot** — analyse the N most recently updated posts (default 20) and check the counts.
5. **Finish** — then run *Full inventory + analysis* from the Overview or Jobs page.

Jobs run in bounded background batches through WP-Cron (default 25 objects / 20 s per tick). If `DISABLE_WP_CRON` is set, keep the Jobs page open (it advances jobs) or run `wp sseo run --user=<admin>`.

## 5. Optional: browser model (Tier 1)

**Jobs & Inference Device** page → *Check this device* → tick the download consent (≈ 579 MB for the default q4f16 precision, from huggingface.co, pinned revision `c25a394dd583836952667c12f008335071b3f43d`) → *Load model & run self-test* → *Process embedding queue* (after *Queue embeddings*).

* The browser verifies size and SHA-256 of every model file before use, also when reusing its cache.
* Uploads are refused unless the self-test reproduces the similarity matrix measured for the chosen
  precision (q4f16 default; fp16 optional) within its tolerance.
* Desktop Chrome/Edge with WebGPU is the fastest. Without WebGPU the same precision runs on WASM
  (slower, same results). Many phones (including iPhones) may be unsupported or too slow; the
  plugin detects this and the lexical tier keeps working.
* Keep the tab open; closing it returns claimed items to the queue.

> **Beta status:** the real Qwen3-Embedding-0.6B weights could not be downloaded in the build environment, so real-model inference is **not yet verified** (see [TEST-REPORT.md](TEST-REPORT.md), AC06). The pipeline itself is verified end-to-end with a labelled tiny fixture model.

## 6. Optional: private worker (Tier 2)

See [`tools/private-worker/README.md`](../tools/private-worker/README.md). It runs on your own machine with Python + ONNX Runtime and authenticates with an Application Password of a dedicated *SEO Embedding Worker* account.

## 7. Optional: AI agents (REST, Abilities, MCP)

See [AI-AGENT-INTEGRATION.md](AI-AGENT-INTEGRATION.md). Nothing is exposed to agents until you create a dedicated account, give it an agent role and an Application Password, and (for Abilities/MCP) opt in per ability.

## 8. Update, deactivate, uninstall

* **Update:** upload the new ZIP (WordPress replaces the plugin folder). Database migrations run once, under a lock, and are logged in *Settings & Diagnostics → Migration log*.
* **Deactivate:** removes the plugin's cron events; all data is kept.
* **Delete:** by default all plugin data is **kept** (so a reinstall finds it). To remove everything, choose *Delete all plugin data* in Settings and type `DELETE` before deleting the plugin. WordPress posts are never deleted; the change log and rollback snapshots are deleted with the plugin data.

## 9. Building from source

```bash
npm ci --ignore-scripts          # pinned Transformers.js 4.3.1 + ONNX Runtime Web 1.31.0-dev
node bin/build-vendor.mjs        # copies the browser runtime into assets/vendor/transformers (+ SHA-256 manifest)
composer install                 # dev tools only (PHPUnit, WPCS); the plugin has no runtime Composer dependencies
bin/build-zip.sh                 # → dist/semantic-seo-engine-vi-en-<version>.zip (+ .sha256)
```

`bin/build-zip.sh` refuses to build if the version headers disagree, if development files or model weights would be included, if a secret-like string is found or if any PHP file fails `php -l`.
