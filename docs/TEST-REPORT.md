# Test report — Semantic SEO Engine VI–EN 1.0.0-beta.1

Date: 2026-10-23. Branch `claude/semantic-seo-engine-vi-en`. Every number below comes from a
command that was actually run; raw results are in [`docs/evidence/`](evidence/) and screenshots in
[`docs/screenshots/`](screenshots/). Nothing was run against any live/production site.

**Release decision: BETA.** 19 of 20 acceptance criteria PASS with evidence; **AC06 is BLOCKED**
(real Qwen3-Embedding weights unreachable from the build environment), so the release is **not**
labelled fully AI-verified.

## 1. Environments

* Local sandbox: 4 vCPU, 16 GB RAM, Linux, PHP 8.3.6, MariaDB 10.11.14, WP-CLI 2.12.0, Node 22.22,
  Python 3.13, Playwright 1.56.1 Chromium (headless). Disposable WordPress sites 7.1.3, 6.9.10 and
  6.5.13 served by `php -S` (4 workers) with `WP_ENVIRONMENT_TYPE=local`.
* Real third-party plugins installed from source: Polylang 3.9.1 (WP 6.9.10/7.1.3; it requires
  WP 6.8+), official WordPress MCP Adapter 0.7.1 (WP 6.9.10/7.1.3).
* GitHub Actions CI (ubuntu-latest, MariaDB 10.11 service, PHP 7.4–8.4).
* Network: wordpress.org, huggingface.co and most CDNs were **blocked** in the sandbox; GitHub,
  npm and PyPI were reachable.

## 2. Suites and results

| Suite | Command | Result |
| --- | --- | --- |
| PHP unit | `vendor/bin/phpunit -c phpunit-unit.xml.dist` | 14 tests, 68 assertions, OK |
| WordPress integration (WP 7.1.3) | `vendor/bin/phpunit -c phpunit.xml.dist` | 58 tests, 1,107 assertions, OK |
| WordPress integration (WP 6.9.10) | `eval "$(bin/install-wp-tests.sh 6.9.10)"; vendor/bin/phpunit -c phpunit.xml.dist` | 57 tests OK (run before the 58th test was added) |
| WordPress integration (WP 6.5.13) | same with `6.5.13` | 57 tests OK, 4 skipped (Abilities API needs WP 6.9+) |
| Coding standards | `vendor/bin/phpcs` (WordPress + PHPCompatibilityWP 7.4-) | 0 errors, 0 warnings |
| JS unit | `node --test tests/js/*.test.mjs` | 18 tests, OK |
| Browser worker vs NumPy (Chromium) | `node tests/e2e/worker-fixture.mjs` | 10/10 |
| ZIP install/activation/read-only/WP-CLI/uninstall | `tests/e2e/smoke-zip.sh <ver> <port>` | 27/27 (7.1.3), 27/27 (6.9.10), 26/26 (6.5.13) |
| HTTP Agent Mode & security | `tests/e2e/prepare-agent-site.sh` + `node tests/e2e/http-agent.mjs` | 26/26 (7.1.3), 26/26 (6.9.10), 22/22 (6.5.13) |
| Private worker e2e | `tests/e2e/private-worker.sh 7.1.3 …` | 89/89 vectors stored, max \|Δ\| vs NumPy 4.5e-8 |
| Browser UI e2e (Playwright) | `tests/e2e/prepare-ui-site.sh` + `node tests/e2e/ui-e2e.mjs` | see §4 |
| Relevance evaluation | `vendor/bin/phpunit --filter EvaluationTest` | see §5 |
| Performance | `tests/bench/run-bench.sh 10|100|1000` | see [PERFORMANCE.md](PERFORMANCE.md) |
| CI | GitHub Actions run [39880430340](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39880430340) | 17/17 jobs green (lint ×6, WPCS, unit ×4, integration ×4, JS, ZIP + smoke) |

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
Not real site data; the target site was not accessible. Raw: [`evidence/evaluation.json`](evidence/evaluation.json).

| Output | P@3 | R@3 | P@5 | R@5 | Precision of returned items | Sources with output |
| --- | --- | --- | --- | --- | --- | --- |
| Internal-link suggestions (new links only) | 0.229 | 0.244 | 0.138 | 0.244 | 0.786 | 10/16 |
| Related content | 0.604 | 0.725 | 0.425 | 0.794 | 0.773 | 16/16 |

Overlap (cannibalization) report: 23 same-locale pairs reported (0.885 per document — the fixture
is deliberately dense); **0 false positives on 7 controls**; **3/3 expected overlaps** found with the
expected category; **0 cross-locale (translation) pairs** compared.

Interpretation: link suggestions are deliberately precision-first (a suggestion requires a natural
anchor that describes the target), so recall is low; related content has good recall. These are
fixture numbers, not predictions of SEO outcomes.

## 6. Acceptance matrix (README §20)

| ID | Result | Evidence |
| --- | --- | --- |
| AC01 ZIP installs/activates without fatal error on declared versions | **PASS** | `evidence/smoke-7.1.3.json`, `smoke-6.9.10.json`, `smoke-6.5.13.json`; CI "Build release ZIP + install smoke test" (WP 6.5.13 + 7.1.3); PHP 7.4 via CI integration job (PHP 7.4 + WP 6.5.13) |
| AC02 Activation read-only | **PASS** | smoke checks: posts/meta/terms/users/roles/options/rewrite rules hashes identical; front-end HTML byte-identical (control run proves determinism); `ActivationMigrationTest` |
| AC03 Inventory discovers real Post/Page/Category records and locales | **PASS (fixtures)** | real WordPress objects + real Polylang languages/translations: `PipelineTest`, smoke (`wp sseo status`), http-agent inventory total 38; not run on the authorized target site |
| AC04 Matrix paginates, searches, filters, exports, imports by safe preview | **PASS** | `OperationsTest` (matrix query, CSV/XLSX export, import preview/apply/conflicts), http-agent cursor pagination, `wp sseo export`, UI matrix page |
| AC05 Lexical mode useful without a model | **PASS** | §5 evaluation (all lexical), golden cases in `PipelineTest` |
| AC06 Real Qwen embedding demonstrated or clearly blocked | **BLOCKED** | huggingface.co unreachable from the sandbox (proxy refusal); pinned revision/sizes/SHA-256 recorded from Hub metadata; full pipeline verified with a labelled tiny fixture model in Chromium (`evidence/worker-fixture.json`) and Python (`evidence/private-worker-7.1.3.json`). To unblock: run *Load model & run self-test* once on a desktop Chrome/Edge |
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
| AC18 Existing SEO/theme/language/canonical behaviour unchanged by default | **PASS** | front-end byte-identical incl. Polylang `/en/` pages, home, category, search, feed; SEO plugins only read (not installed as real plugins in tests) |
| AC19 Performance, test results, artifacts, screenshots are real | **PASS** | this report, `docs/evidence/*.json`, `docs/screenshots/*`, CI run link |
| AC20 README, docs, CI, ZIP, PR, install guide match implementation | **PASS** | README status block + docs set, CI workflow, `bin/build-zip.sh` (artifact in CI), PR from this branch |

## 7. Not complete / not verified

* Real Qwen3-Embedding-0.6B inference (AC06) and its speed on WebGPU/WASM.
* Evaluation on the real authorized site (blog.rentbikehanoi.com) — not accessible; only fixtures.
* WPML, Yoast/Rank Math/SEOPress/TSF/AIOSEO as installed plugins; real iPhone/Safari; a real MCP
  client application (only the adapter's JSON-RPC endpoint was exercised).
* PHP 7.4 ran in CI only (unit + integration), not in the local e2e suites.
