# User guide — Semantic SEO Engine VI–EN 1.0.0-beta.2

For site administrators and editors. Vietnamese version: [USER-GUIDE-VI.md](USER-GUIDE-VI.md).
The plugin's admin screens default to Vietnamese; switch to English in *Settings & Diagnostics →
Plugin admin language*.

## What it does — and does not do

* **Does:** URL inventory, internal-link suggestions, same-language overlap (cannibalization) risk,
  Pillar–Hub–Silo clusters, content QA and related content — every conclusion with its evidence.
* **Does not:** change content, SEO metadata, URLs, canonicals, noindex, redirects or the theme on
  its own. Every change is a **proposal** with an exact diff that a **human approves** in WP Admin,
  and it can be **rolled back**.
* No chatbot, no paid AI API, no model for site visitors. Results are hints/hypotheses, not ranking
  promises; overlap is only "confirmed competition" with genuinely imported Search Console data.

## Getting started

1. Back up, preferably test on staging. Upload and activate the ZIP ([INSTALLATION.md](INSTALLATION.md)).
2. *Semantic SEO → Overview & Setup*: compatibility check → compute tiers → scope → read-only
   pilot (20 latest posts) → finish → *Full inventory + analysis*.

## Screens

* **URL Inventory / SEO Matrix** — every real URL with locale, intent, cluster/role, links in/out,
  robots, QA and overlap counts; server-side filters/sort/paging; CSV (UTF-8 BOM) and XLSX export;
  import of the plugin's editorial fields only, always through a preview (empty cell = no change).
* **Semantic Analysis** — one URL: measured *facts* kept separate from *inferences*; editorial
  overrides, lock, exclude.
* **Link Suggestions & Graph** — source → target with a natural anchor found in the source, its
  exact byte offset, reasons and evidence; orphan candidates with scan coverage (menus, widgets and
  templates are not observed).
* **Cannibalization Review** — same-locale groups: exact/near duplicate, potential same-query
  competition, different intent, distinct facet (daily vs monthly), pillar + supporting.
  Translations are never compared.
* **Pillar–Hub–Silo Clusters** — built from existing categories; recommended pillar/hub with
  evidence; category suggestions. No term is ever created.
* **Content QA** — 26 rules labelled *verified error* / *warning* / *suggestion*; no quality score.
* **Related Content Output** — off by default; shortcode `[sseo_related]`, block *Related content
  (Semantic SEO)*, PHP `sseo_get_related_posts()`; same-language, published, indexable posts only.
* **Jobs & Inference Device** — job control and the browser-model panel.
* **Agent Connections / Permissions**, **Settings & Diagnostics** (administrators only), **Audit /
  Undo / Rollback**.

## Safe change workflow

Create preview → review the before/after excerpt, inserted markup and the *unchanged* list →
tick the confirmation → **Approve** (single use, expires after 24 h by default) → **Apply now**
(stale-content check, write, readback verification, automatic revert on mismatch) → **Roll back**
any time from *Applied changes* (exact restore if untouched since, otherwise a surgical revert of
just the inserted link, or a clear "manual recovery needed").

## Browser model (optional)

*Check this device* → consent to the ≈ 579 MB download from huggingface.co → *Load model & run
self-test* (size + SHA-256 verified; uploads only after the self-test matches the model-card
reference) → *Queue embeddings* → *Process embedding queue*; *Pause* / *Stop & release* any time.
Keep the tab open. Prefer desktop Chrome/Edge with WebGPU; without WebGPU the same precision runs
on the CPU (WASM), slower but with the same results. The real model was verified this way in
Chromium (see [TEST-REPORT.md](TEST-REPORT.md) §6); real-GPU speed was not measured.

## Search Console (optional)

Matrix → *Search Console data*: upload a genuine page × query export, enter period and source;
dry run by default.

## FAQ

* **Front-end impact?** None unless you enable related content (precomputed, cached list).
* **Uninstall?** Data is kept by default; type `DELETE` under the uninstall policy to remove it.
  Posts are never deleted.
* **Few link suggestions?** Without vectors (lexical tier) links are suggested only with a natural,
  descriptive anchor: on the labelled fixture ≈ 79% of returned suggestions were correct but only
  24% of the expected links were found. With the real model's vectors 70% were found (69% of
  returned suggestions correct). Related content has much higher recall in both tiers.
