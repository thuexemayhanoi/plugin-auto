# Test report — Semantic SEO Engine VI–EN 1.0.0-beta.1

Date: 2026-10-23. Branch `claude/semantic-seo-engine-vi-en`. Every number below comes from a
command that was actually run; raw results are in [`docs/evidence/`](evidence/) and screenshots in
[`docs/screenshots/`](screenshots/). Nothing was installed on, or written to, any live site; the
only contact with blog.rentbikehanoi.com was the read-only census/pilot in §7 (public GET requests).

**Release decision: BETA (1.0.0-beta.1), all 20 acceptance criteria PASS with evidence.** The real
Qwen3-Embedding-0.6B model now runs end-to-end (browser worker, private worker, plugin queue,
hybrid analysis) in GitHub Actions with the pinned, SHA-256-verified files (§6). It stays a beta
because the owner has not yet installed it on a staging copy of the blog, real GPU (WebGPU) speed and
real iPhones were not measured, and WPML/other SEO plugins were not installed (§9).

## 1. Environments

* Local sandbox: 4 vCPU, 16 GB RAM, Linux, PHP 8.3.6, MariaDB 10.11.14, WP-CLI 2.12.0, Node 22.22,
  Python 3.13, Playwright 1.56.1 Chromium (headless). Disposable WordPress sites 7.1.3, 6.9.10 and
  6.5.13 served by `php -S` (4 workers) with `WP_ENVIRONMENT_TYPE=local`.
* Real third-party plugins installed from source: Polylang 3.9.1 (WP 6.9.10/7.1.3; it requires
  WP 6.8+), official WordPress MCP Adapter 0.7.1 (WP 6.9.10/7.1.3).
* GitHub Actions CI (ubuntu-latest, MariaDB 10.11 service, PHP 7.4–8.4).
* Network: wordpress.org, huggingface.co, blog.rentbikehanoi.com and most CDNs were **blocked** in
  the sandbox; GitHub, npm and PyPI were reachable. Everything that needs those hosts runs in
  GitHub Actions (ubuntu-latest, 4 vCPU, 16 GB, no GPU): workflows "Real Qwen3-Embedding-0.6B" and
  "Read-only site census + pilot".

## 2. Suites and results

| Suite | Command | Result |
| --- | --- | --- |
| PHP unit | `vendor/bin/phpunit -c phpunit-unit.xml.dist` | 14 tests, 68 assertions, OK |
| WordPress integration | `vendor/bin/phpunit -c phpunit.xml.dist` | 65 tests OK locally (WP 7.1.3 test library; skipped: the real-model test, which runs in its own workflow, and the VI-EN plugin test when `NH_VIEN_PLUGIN_DIR` is unset); CI on PHP 7.4 + WP 6.5.13, 8.1 + 6.9.10, 8.3/8.4 + 7.1.3 |
| Real VI-EN language plugin | `NH_VIEN_PLUGIN_DIR=… phpunit --filter NhVienLanguageTest` | real `NH_VIEN_Links` class (pinned commit of the owner's repo): locales, pairs, standalone English posts, page pairs, English categories, read-only |
| Coding standards | `vendor/bin/phpcs` (WordPress + PHPCompatibilityWP 7.4-) | 0 errors, 0 warnings |
| JS unit | `node --test tests/js/*.test.mjs` | 19 tests, OK |
| Browser worker vs NumPy (Chromium) | `node tests/e2e/worker-fixture.mjs` | 10/10 |
| ZIP install/activation/read-only/WP-CLI/uninstall | `tests/e2e/smoke-zip.sh <ver> <port>` | 27/27 (7.1.3), 27/27 (6.9.10), 26/26 (6.5.13) |
| HTTP Agent Mode & security | `tests/e2e/prepare-agent-site.sh` + `node tests/e2e/http-agent.mjs` | 26/26 (7.1.3), 26/26 (6.9.10), 22/22 (6.5.13) |
| Private worker e2e | `tests/e2e/private-worker.sh 7.1.3 …` | 89/89 vectors stored, max \|Δ\| vs NumPy 4.5e-8 |
| Browser UI e2e (Playwright) | `tests/e2e/prepare-ui-site.sh` + `node tests/e2e/ui-e2e.mjs` | see §4 |
| Relevance evaluation | `vendor/bin/phpunit --filter EvaluationTest` (lexical) and `--group real-model` (hybrid, real vectors) | see §5 and §6 |
| Real model | workflow "Real Qwen3-Embedding-0.6B" | see §6; latest green run [39896404879](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39896404879) |
| Real site (read-only) | workflow "Read-only site census + pilot" | see §7 |
| Performance | `tests/bench/run-bench.sh 10|100|1000` | see [PERFORMANCE.md](PERFORMANCE.md) |
| CI | GitHub Actions run [39896590578](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39896590578) (commit `5a9cf4d`) | 17/17 jobs green (lint ×6, WPCS, unit ×4, integration ×4 incl. the site's VI-EN plugin, JS, ZIP + smoke) |

All e2e suites together: `tests/e2e/run-all.sh` (log: `tests/e2e/artifacts/run-all.log`).

## 3. Defects found by these tests and fixed

1. Idempotent retry of `start-analysis` returned 429 (agent quota) instead of the original job.
2. BM25 candidate retrieval chose only source-unique terms → 2–4 candidates per page.
3. Clusters were built after pair analysis (roles missing on the first run); pillar choice ignored
   links from child clusters; "pillar + supporting" hid real competition.
4. Translations for a plugin UI language different from the site locale did not load (WP 6.5+
   translation controller is per-locale) — now domain-scoped `gettext` filters.
5. Contrast of "n/a" meters below WCAG AA.
6. Reloading the tab during an upload left items leased for 180 s — now released on `pagehide`.
7. `transformers.web.min.js` imported `onnxruntime-web/webgpu` as a bare specifier (would have
   failed in every browser) — switched to the self-contained bundle.
8. Transformers.js probes metadata at revision `main` — the pinned fetch answered 404 and the
   tokenizer failed to load — probes are now answered from the pin without network access.
9. Real-model findings: the q8 precision was the least accurate and not reproducible across
   runtimes → removed; the self-test tolerance around the fp32 card was too loose to catch a missing
   end-of-text token → each precision is now checked against its own measured matrix; raw cosine
   inflated overlap reports → calibrated overlap gate.
10. The blog's own language plugin (Nguyen Ha VI-EN Core) was not recognised → read-only adapter;
    "missing translation" was a warning on selectively translated sites → now a suggestion.

## 4. Browser UI (real Chromium, WordPress 7.1.3, Polylang 3.9.1)

* 12 admin pages render without PHP notices or JS errors; screenshots: desktop full pages and an
  emulated iPhone 13 viewport (Chromium emulation, **not a real iPhone**).
* axe-core 4.10.3 (WCAG 2.1 A/AA) inside the plugin UI: 0 critical/serious, 0 moderate/minor.
* Keyboard: all inference controls reachable with Tab and with a visible focus indicator.
* Admin UI in Vietnamese by default.
* Human Mode through the real UI: create preview → approve (confirmation required) → apply (one
  link added, readback verified) → rollback → post content byte-identical to the original.
* Browser inference loop with the **tiny fixture model** (not Qwen): detect → consent → load →
  self-test → claim/embed/complete; pause (nothing processed while paused, no lease left),
  resume, reload during an upload (lease released immediately on `pagehide`), reload during a claim
  (released immediately in the recorded run; if the claim response is lost, the 180 s lease expiry
  returns the items — expiry is covered by `EmbeddingQueueTest::test_expired_lease_and_changed_content`),
  stop (lease released), finish (90/90 queued vectors stored).
* Unsupported device (no WebAssembly/WebGPU): clear message, loading disabled, lexical pages work.
* Front end with related content enabled: all requests same-origin; no model, worker, admin script
  or `.onnx` request; related list rendered.

## 5. Relevance evaluation (labelled fixture, lexical tier)

Source: `tests/fixtures/corpus.php` — **labelled test fixture**, 26 analysed documents (VI + EN,
motorbike rental and travel), 16 labelled sources, 7 must-not-compete controls, 3 expected overlaps.
Not real site data (the read-only pilot of the real blog is in §7). Raw: [`evidence/evaluation.json`](evidence/evaluation.json).

| Output | P@3 | R@3 | P@5 | R@5 | Precision of returned items | Sources with output |
| --- | --- | --- | --- | --- | --- | --- |
| Internal-link suggestions (new links only) | 0.229 | 0.244 | 0.138 | 0.244 | 0.786 | 10/16 |
| Related content | 0.604 | 0.725 | 0.425 | 0.794 | 0.773 | 16/16 |

Overlap (cannibalization) report: 23 same-locale pairs reported (0.885 per document — the fixture
is deliberately dense); **0 false positives on 7 controls**; **3/3 expected overlaps** found with the
expected category; **0 cross-locale (translation) pairs** compared.

Interpretation: without vectors, link suggestions are deliberately precision-first (a suggestion
requires a natural anchor that describes the target), so recall is low; related content has good
recall. With the real model's vectors link recall rises from 0.244 to 0.702 (§6). These are fixture
numbers, not predictions of SEO outcomes.

## 6. Real Qwen3-Embedding-0.6B (GitHub Actions)

Model `onnx-community/Qwen3-Embedding-0.6B-ONNX` @ `c25a394dd583…` (base `Qwen/Qwen3-Embedding-0.6B`,
Apache-2.0). Transcribed results: [`evidence/real-model-ci.json`](evidence/real-model-ci.json).

* **Pins and integrity:** every pin in `ModelProfile` matched the Hub metadata of the pinned revision;
  every downloaded byte was verified (SHA-256; git blob SHA-1 for the small JSON files).
* **Correct pipeline:** fp32 reproduces the model card exactly (max |Δ| 0.0000). The self-test rejects
  every deliberately wrong pipeline (no query instruction, mean pooling, first-token pooling, missing
  end-of-text token: |Δ| 0.11–0.81) for fp32, fp16 and q4f16.
* **Precision survey** (cosine to fp32 on the fixture corpus, mean / top-5 neighbour agreement):
  fp16 1.000 / 1.000; q4f16 0.926 / 0.938; q4 0.926 / 0.931; bnb4 0.944 / 0.908; q8 0.836 / 0.869;
  int8 0.831 / 0.877. Default **q4f16** (567 MB), option **fp16** (1.2 GB); q8 removed.
* **Browser worker** (Chromium headless, WASM): downloads the pinned q4f16 files from
  huggingface.co itself (only `huggingface.co` and its CDN contacted), self-test |Δ| 0.0054,
  vectors equal to Python ONNX Runtime (cosine ≥ 0.99927), reload from the verified cache without
  any download; fp16 cosine 1.0000; q8 only 0.885 (why it was removed).
* **Private worker over REST** (Application Password, WordPress 7.1.3): 89/89 chunks embedded and
  stored, independent recompute |Δ| 0.0.
* **Hybrid evaluation** (labelled fixture, real q4f16 vectors through the plugin queue, weight 0.35):

| | Lexical only | Hybrid (default) |
| --- | --- | --- |
| Link suggestions P@3 / R@3 / precision of returned | 0.229 / 0.244 / 0.786 | **0.521 / 0.702** / 0.694 |
| Related content P@3 / P@5 / R@5 | 0.604 / 0.425 / 0.794 | 0.563 / **0.475 / 0.888** |
| Overlap pairs reported; false positives on 7 controls; expected found | 23; 0; 3/3 | 30; 0; 3/3 |
| Cross-locale pairs compared | 0 | 0 |

  Overlap gate variants at the same weight: raw cosine 42 pairs (52 at weight 0.5); model floor only
  31; site baseline (default, Vietnamese median of the fixture 0.42) 30 — always 0 false positives and
  3/3 expected. Calibration everywhere (also for ranking) cut link recall@3 to 0.390, hence raw cosine
  for ranking. Weight grid with the default gate (link R@3 / related R@5 / overlap pairs): 0 →
  0.244 / 0.794 / 23; 0.2 → 0.468 / 0.903 / 27; 0.35 → 0.702 / 0.888 / 30; 0.5 → 0.723 / 0.867 / 33;
  0.7 → 0.702 / 0.867 / 34. Default 0.35. Run
  [39896404879](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39896404879).
* **Cross-language:** translations are each other's nearest page (fp32 9/9); the possible-translation
  rule at threshold 0.70 suggested 7 pairs, 7 correct (7/9 found).

## 7. Read-only census and pilot of blog.rentbikehanoi.com

Workflow "Read-only site census + pilot" (GitHub Actions; the sandbox cannot reach the site). Public
GET requests only, one at a time with a 1-second pause and an identifying User-Agent: robots.txt,
the XML sitemaps, the public REST collections and (from run 2) the HTML head of the English pages for
their hreflang tags. Nothing is written to the site. The content is analysed in a **disposable copy**
(WordPress 7.1.3 + Yoast SEO + the site's own "Nguyen Ha VI-EN Core" at a pinned commit + this
plugin) that is discarded with the runner; only aggregate numbers are published (no titles, URLs or
per-page findings). Aggregates of run 1: [`evidence/site-pilot-run1.json`](evidence/site-pilot-run1.json).

**Census (actual URL count, 2026-10-23 01:41 UTC, run [39885301378](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39885301378)):**

| Source | Count |
| --- | --- |
| URLs in the XML sitemaps (Yoast `sitemap_index.xml`, declared in robots.txt) | **1,083** — Vietnamese 543, English 540 |
| … by type | posts 1,002 (VI 502, EN 500), pages 37 (VI 18, EN 19), categories 43 (VI 22, EN 21), author 1 |
| REST API totals (published, public) | posts **1,001**, pages **37**, categories **43**, tags 4 |

The sitemaps list one post URL more than the REST API returns posts; per-URL lists are not
published, so this was not investigated further.

**Pilot run 1** (commit `65ed645`, i.e. *before* the overlap-gate calibration; q4f16):

| Step | Result |
| --- | --- |
| Copy | 1,001 posts, 37 pages, 43 categories imported; links in 823 contents rewritten to the copy. The copy also held WordPress' sample post/page and **no VI-EN pairs** (not public in REST), fixed in run 2 |
| Inventory + lexical analysis | 1,083 documents (all published, indexable); inventory 49 s, analysis 642 s on 4 vCPU; average 2,139 words (max 16,789); 17,944 chunks |
| Link graph | 6,756 resolved internal links, 14 unresolved, 4,400 external |
| Lexical findings | 832 link suggestions for 493 source pages; 161 overlap pairs in 99 groups (potential competition 89, same topic/different intent 28, distinct facet 23, manual review 19, pillar + supporting 2); **0 cross-language pairs**; 580 posts with no inbound link observed in content (menus/widgets not observed); 883 pages without a meta description in Yoast's public output |
| Embedding (real model, private worker over REST + Application Password) | 3,201 queue items (summary + first 2 body chunks per page) embedded and stored in 5,130 s (≈ 1.6 s per item, 4 vCPU, no GPU) |
| Hybrid re-analysis with **raw cosine** (old scoring) | 651 s; link suggestions 2,910 for 1,016 sources; related rows 1,552 → 5,195; **overlap groups 99 → 2,323** (manual review 19 → 1,560, same topic/different intent 28 → 529); 0 cross-language pairs |
| Front end of the copy | no plugin asset and no model runtime on the home page |

Finding: with raw cosine almost every pair of pages looked similar enough to report. (Run 1 used the
scoring from before the overlap-gate calibration.)

**Pilot run 2** (run [39896404986](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39896404986),
commit `4d3d257`; aggregates: [`evidence/site-pilot-run2.json`](evidence/site-pilot-run2.json)). WordPress'
sample content removed; VI-EN pairs read from the site's own hreflang tags (519 English pages read,
0 errors):

| Item | Result |
| --- | --- |
| Copy | 1,081 documents = 1,001 posts + 37 pages + 43 categories, all published and indexable; locales from the VI-EN plugin (1,076 by its post/term registry, 5 by path) |
| Translation pairs announced by the site | **16 page pairs, 0 post pairs**: the 500 English posts are standalone articles in the VI-EN plugin |
| Lexical analysis | 663 s; 830 link suggestions (492 sources); 99 overlap groups; 0 cross-language pairs; 881 missing meta descriptions; 579 posts without inbound content links; 54 long informational posts without any outside source |
| Embedding | 3,197 items in 4,944 s (q4f16, private worker, 4 vCPU) |
| Site similarity baseline (median of 20,000 sampled same-language pairs) | VI 0.378 (p90 0.524, p99 0.678), EN 0.361 (p99 0.649) — **below the model floor 0.40**, so the site baseline changes nothing on this blog (the model-floor and default runs are identical) |
| Hybrid, calibrated overlap gate | 655 s; overlap groups **898** (vs 2,323 with raw cosine in run 1, 99 lexical): potential competition 96 (54 medium confidence), distinct facet 42, pillar + supporting 53, same topic/different intent 212, manual review 563; 0 cross-language pairs. Reported pairs have cosine p10 0.68 / median 0.75, i.e. they are among the site's ~1% most similar pairs. 69% of posts appear in at least one hypothesis (lexical: 17%) |
| Hybrid links and related | 2,908 link suggestions (1,014 sources); related rows 1,553 → 5,190 |
| Cross-language rule vs the 16 announced page pairs | at 0.70: 14 suggested — 4 announced pairs, 1 contradicting an announced pair, 9 not announced (possible unlinked translations, for human review); recall of announced pairs 0.25 (pages here are separately written, not translations). At 0.60: 51 suggested, 6 announced, 1 contradicting |
| Front end of the copy | no plugin asset and no model runtime |

Consequences: (1) the calibrated gate is what removed most of the overlap noise; the site baseline is
kept as a safeguard for sites whose pages are more alike than the fixture (fixture VI median 0.42 →
30 instead of 31 pairs), and its rationale in the code was corrected. (2) "Missing translation" was
suggested for 1,006 documents because the blog does not pair posts; it is now limited to content types
the site translates as pairs (`QaTranslationScopeTest`), which on this blog leaves only the unpaired
pages. That change was made after run 2 and is covered by tests, not by a new pilot run. (3) The
overlap report needs triage on this blog: start with potential competition and near-duplicates;
"manual review" pairs are semantic-only hypotheses (ADMIN-GUIDE §3).

## 8. Acceptance matrix (README §20)

| ID | Result | Evidence |
| --- | --- | --- |
| AC01 ZIP installs/activates without fatal error on declared versions | **PASS** | `evidence/smoke-7.1.3.json`, `smoke-6.9.10.json`, `smoke-6.5.13.json`; CI "Build release ZIP + install smoke test" (WP 6.5.13 + 7.1.3); PHP 7.4 via CI integration job (PHP 7.4 + WP 6.5.13) |
| AC02 Activation read-only | **PASS** | smoke checks: posts/meta/terms/users/roles/options/rewrite rules hashes identical; front-end HTML byte-identical (control run proves determinism); `ActivationMigrationTest` |
| AC03 Inventory discovers real Post/Page/Category records and locales | **PASS** | real WordPress objects + real Polylang and the site's real VI-EN plugin: `PipelineTest`, `NhVienLanguageTest`, smoke, http-agent; **copy of the real blog: 1,081 documents = 1,001 posts + 37 pages + 43 categories, locales from the site's VI-EN plugin, 16 page pairs from its hreflang (§7)**; census of the live site: 1,083 sitemap URLs |
| AC04 Matrix paginates, searches, filters, exports, imports by safe preview | **PASS** | `OperationsTest` (matrix query, CSV/XLSX export, import preview/apply/conflicts), http-agent cursor pagination, `wp sseo export`, UI matrix page |
| AC05 Lexical mode useful without a model | **PASS** | §5 evaluation (all lexical), golden cases in `PipelineTest` |
| AC06 Real Qwen embedding demonstrated or clearly blocked | **PASS** | real pinned model in GitHub Actions (§6): model-card self-test (fp32 exact), browser worker downloading from huggingface.co with vectors equal to Python, private worker over REST, hybrid evaluation through the plugin queue; runs 39884170101, 39888434060, 39891925814, 39896404879; `evidence/real-model-ci.json` |
| AC07 Unsupported WebGPU/mobile handled without fatal failure | **PASS** | UI test "unsupported device"; iOS warning (emulated UA); JS `chooseDevice` tests. No real iPhone tested |
| AC08 M1–M6 outputs with evidence, provenance, review workflow | **PASS** | findings carry evidence components, algorithm version, label, confidence; `url_analysis` provenance; review/dismiss/accept, locks; UI screenshots |
| AC09 VI–EN translations not flagged as cannibalization | **PASS** | 0 cross-locale pairs (evaluation, `PipelineTest`), control pairs `vi-pillar`/`en-pillar`, `vi-ngay`/`en-daily` not reported |
| AC10 Writes need permission, exact diff, separate human approval | **PASS** | `ChangeLifecycleTest`, `AdminUiTest`, http-agent (no approve route, operator/admin-app-password refused), UI approve flow |
| AC11 Rollback reliably restores | **PASS** | UI rollback byte-identical; `ChangeLifecycleTest` exact, surgical and manual-recovery paths; category assignment rollback |
| AC12 REST schema-validated, paginated, permission-checked, tested | **PASS** | `RestApiTest`, http-agent (400 schema, 401/403, cursor, 413, 429) |
| AC13 Abilities registered/verified on supported core, gated elsewhere | **PASS** | `AbilitiesTest`; http-agent on 7.1.3/6.9.10 (15 registered, opt-in REST exposure, read runs, writes refused); 6.5.13 gated |
| AC14 MCP opt-in, authenticated, tested against installed adapter; absence reported | **PASS** | MCP Adapter 0.7.1: unauthenticated refused, nothing exposed by default, only selected read-only abilities discoverable/executable, write refused; status `mcp_adapter:false` on 6.5.13. No third-party MCP client app was connected |
| AC15 Agent cannot self-approve or use privileged endpoints with read-only credentials | **PASS** | http-agent reader 403s, no approve route, admin Application Password treated as agent, editor refused |
| AC16 No frontend model load or hidden paid AI API | **PASS** | UI front-end request audit; worker allow-list test (no CDN); code has no AI API client |
| AC17 Jobs resume, cancel, avoid duplicates, honest progress | **PASS** | `OperationsTest` (cancel/pause/resume with honest progress, resume after a crashed worker via expired lease, backoff then fail, post deleted/updated mid-job, idempotent retry under quota), `RestApiTest::test_job_start_is_idempotent_and_deduplicated`, http-agent duplicate 429, UI inference pause/resume/stop |
| AC18 Existing SEO/theme/language/canonical behaviour unchanged by default | **PASS** | front-end byte-identical incl. Polylang `/en/` pages, home, category, search, feed; SEO plugins only read; Yoast SEO and the site's VI-EN plugin installed for real in the pilot copy (§7), front page of the copy without plugin assets |
| AC19 Performance, test results, artifacts, screenshots are real | **PASS** | this report, `docs/evidence/*.json`, `docs/screenshots/*`, CI run link |
| AC20 README, docs, CI, ZIP, PR, install guide match implementation | **PASS** | README status block + docs set, CI workflow, `bin/build-zip.sh` (artifact in CI), PR from this branch |

## 9. Not complete / not verified

* Real-GPU (WebGPU) speed: CI has no GPU; only a software adapter (SwiftShader) was exercised.
* Installation on a staging copy of blog.rentbikehanoi.com by the owner (production is out of scope).
* Relevance labels exist only for the fixture; on the real-site copy (§7) only counts and the agreement
  with the site's own hreflang pairs are measured, not the precision of link/overlap suggestions.
* WPML, Rank Math/SEOPress/TSF/AIOSEO as installed plugins (Yoast SEO was installed only in the
  pilot copy); real iPhone/Safari; a real MCP client application or ChatGPT (only the adapter's
  JSON-RPC endpoint was exercised).
* PHP 7.4 ran in CI only (unit + integration), not in the local e2e suites.
