# Changelog

All notable changes to Semantic SEO Engine VI–EN. Versions follow semantic versioning.

## [1.0.0-beta.3] — 2026-10-23

Independent architecture and security audit. Report: [docs/QA-AUDIT-REPORT.md](docs/QA-AUDIT-REPORT.md).
Every fix below has a regression test that fails on beta.2.

### Fixed — data integrity (P0)
- Approved link writes go through a compare-and-set `UPDATE` of `post_content` (and the modified
  dates) instead of `wp_update_post()`, then fire core's post hooks. No more slug/URL changes,
  unpublished future-dated posts, default category added, tags re-resolved by name or invalid
  entities rewritten; an edit saved before or during the write is never overwritten; an undo only
  reverts this plugin's own write (`WriteIntegrityTest`, `ChangeExecutorSafetyTest`).
- Apply re-validates source and target publication and URL under the lock; a category change
  that would move the URL (CPT permalink built from a term) is undone; rollback re-checks the
  status under the lock (no double rollback); `applying` changes left by a dead process are settled
  from the stored value.
- `HtmlScanner` quote handling (a link could be nested in a link); anchors never in embed/bare
  URLs, blocks rebuilt from JSON attributes, enclosing shortcodes or across a capitalised name;
  anchor search on original bytes (`AnchorSafetyTest`).

### Fixed — jobs and embeddings (P1)
- A batch whose database writes failed is retried, never reported as completed
  (`DatabaseFailureTest`). Crashed ticks count as attempts; fatal errors are recorded; WP-CLI waits
  for a lease held by WP-Cron; embedding items re-queued with new content stay claimable; items out
  of attempts never keep the embed job open; claims hand out the hash of the text they send
  (`JobRecoveryTest`).
- Browser worker: the end-of-text token is kept when long texts are truncated (pooling reads the
  last token); preprocessing version `p2` (new model profile key).

### Fixed — large sites (P1)
- The cluster step of the analysis read every document and full profile at once and ran two
  link-count queries per member: on 20,000 posts 159 s and 759 MB, fatal under a 256 MB WP-Cron
  request. Now paged, slim profiles and grouped counts: 10.7 s, 156 MB, identical results
  (`ClusterScaleTest`).
- Ticks size their batches from the measured time per item, so a tick stays within its budget
  (20 s budget: 68 s before, 20.5–22.1 s after on 20,000 posts; `TickBudgetTest`).

### Fixed — security (P2)
- Unpublished link sources are hidden from agents in URL analysis; password-protected posts' text
  is never stored, analysed or handed to agents/workers (`PrivacyScopeTest`).

### Fixed — SEO logic (P1/P2)
- Search Console / focus-keyword evidence is evaluated before cluster roles; generic modifiers
  ("giá rẻ") do not make a supporting page; no exact-match links between competing pages except up
  to their pillar/hub; inbound counts only from published pages (`SeoLogicTest`); exact duplicates
  count as overlap risk in the SEO Matrix (`MatrixRiskColumnsTest`).

## [1.0.0-beta.2] — 2026-10-23

Final audit of the beta. Release notes: [docs/RELEASE-NOTES-1.0.0-beta.2.md](docs/RELEASE-NOTES-1.0.0-beta.2.md).

### Security
- ChangeExecutor: kses filters suspended for a verified write are restored exactly in a `finally`
  block; exceptions from WordPress or other plugins' hooks become errors and are reverted; other
  post fields are verified unchanged (`ChangeExecutorSafetyTest`, fails on beta.1).
- Embeddings: server-side check of the device's self-test vectors before any upload (`428`
  without it; `DeviceVerificationTest`).
- Proposals carry URL, post ID and risk; term proposals refused when the permalink contains the
  taxonomy, re-checked before applying.

### Changed
- Overlap classification: places in titles (`Language\Places`), different intent as information,
  vector-only pairs as confidence `low` (`CannibalizationRulesTest`). Overlap lists are ordered by
  review tier, then score (`OverlapOrderTest`). Copy of the blog with the real model: manual
  review 563 → 424, 104 vector-only pairs and 36 more different-place pairs in their own tiers,
  nothing hidden.
- "Missing translation" limited to content types with pairs and to posts not declared standalone
  (`QaTranslationScopeTest`, `NhVienLanguageTest`).
- Performance: single-statement finding upsert, multi-row related insert, cached anchor title
  counts, batched embedding enqueue; the BM25 candidate search reads at most 20,000 postings per
  query (filter `sseo_search_postings_budget`), dropping the least informative common terms first,
  and document frequencies are counted once per request instead of once per analysed document
  (same 25 documents of a 6,000-post synthetic site: 0.65 s → 0.17 s each;
  `LexicalSearchBudgetTest`; labelled-fixture results unchanged).
- Database schema 3 (migrated automatically, data kept): `findings.target_id` index and per-side
  lookups for the SEO Matrix risk columns (`GET /inventory?limit=20` at 1,000 synthetic posts:
  459 → 46 ms); a covering findings index with deferred-join paging and an orphan index for the
  status summary, link and overlap lists (6,000 synthetic posts, same data: 0.55 → 0.07 s,
  0.42 → 0.02 s, 0.39 → 0.09 s; `MatrixRiskColumnsTest`, migration test 1 → 3).
- *Max documents* is now enforced (it was shown in the settings but not applied): new objects
  beyond the limit are counted and reported (overview notice, `GET /status` → `inventory_limit`),
  stored documents keep being refreshed (`InventoryLimitTest`).
- Reproducible ZIP (sorted entries, fixed permissions and timestamp); CI checks it, the shipped
  licenses, and coexistence with WooCommerce, Yoast SEO and Polylang.

## [1.0.0-beta.1] — 2026-10-23

First beta (built from the branch, never released separately).

### Added
- Read-only inventory of posts, pages and category archives with locale detection (Polylang,
  WPML filters, URL prefix rules, site default), SEO metadata from Yoast/Rank Math/SEOPress/
  The SEO Framework/AIOSEO (read-only), content hashes and incremental re-indexing.
- Vietnamese/English lexical engine: NFC normalisation, accent folding, syllable unigrams and
  bigrams, language-aware stop words, BM25F index (binary-collated terms), MinHash
  near-duplicates, link graph with offsets and resolution status.
- Modules M1–M6: SEO Matrix (CSV/XLSX export, safe-preview import, Search Console import),
  internal-link suggestions with exact anchor offsets, cannibalization review (never across
  translations), Pillar–Hub–Silo clusters, 26 content-QA rules, related content (shortcode,
  block, PHP API, off by default).
- Human Mode: exact hash-bound diff previews, human-only single-use approvals, idempotent apply
  with object mutex, stale check, readback verification and auto-revert, exact/surgical rollback,
  locks/exclusions, redacted audit log; 12 admin pages (desktop + mobile, WCAG checks), complete
  Vietnamese translation (default) and English.
- Agent Mode: REST API `semantic-seo/v1`, 15 WordPress Abilities (WP 6.9+, opt-in REST exposure),
  opt-in MCP exposure of read-only abilities through the official MCP Adapter, agent roles,
  Application-Password sessions treated as agents, rate limits, payload caps.
- Semantic tier: pinned Qwen3-Embedding-0.6B ONNX profile; browser Web Worker (bundled
  Transformers.js 4.3.1 + ONNX Runtime Web, same-origin) with pinned/SHA-256-verified downloads,
  self-test gate, last-token pooling, MRL; embedding queue with single-use leases; optional
  Python private worker.
- WP-CLI `wp sseo …`, installable ZIP build, CI (PHP 7.4–8.4, WordPress 6.5–7.1), e2e suites,
  benchmark harness and labelled evaluation.
- Precision chosen from real-model measurements: q4f16 default, fp16 option (q8 removed); each
  precision's self-test is compared with its own measured matrix.
- Overlap-risk gate calibration: raw cosine for ranking; for the overlap gate only the similarity
  above the model floor (0.40) counts, raised to the site's own median similarity per language when
  that is higher (`sseo_similarity_baseline`, re-measured at every site-wide analysis). On a copy of
  the blog the overlap report went from 2,323 groups (raw cosine) to 898 (median there 0.38/0.36, so
  the model floor applied).
- "Missing translation" only for content types the site translates as linked pairs (the blog's
  English posts are standalone articles: 1,006 suggestions on its copy were noise).
- Read-only adapter for the "Nguyen Ha VI-EN Core" language plugin; cross-language "possible
  translation, not linked yet" suggestions (never linked automatically); E-E-A-T source heuristic.

### Fixed during development (found by the test suites)
- BM25 candidate retrieval ignored the shared terms (related-content recall 0.27 → 0.79 on the
  labelled fixture).
- Pillar recommendation counted only direct cluster members; supporting pages that merely add a
  generic modifier to the pillar's head query were hidden as "pillar + supporting".
- An idempotent job retry was rejected by the agent quota.
- Closing/reloading the browser tab during an upload kept items leased until expiry.
- Translation loading for a plugin UI language different from the site locale.

### Known limitations
- Real-GPU (WebGPU) speed not measured (CI has no GPU); WPML and SEO plugins other than Yoast not
  tested as installed plugins; no real iPhone or real MCP/ChatGPT client tested; not yet installed on
  a staging copy of the blog. See docs/TEST-REPORT.md.
