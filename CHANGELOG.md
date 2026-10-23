# Changelog

All notable changes to Semantic SEO Engine VI–EN. Versions follow semantic versioning.

## [1.0.0-beta.1] — 2026-10-23

First beta. Release notes: [docs/RELEASE-NOTES-1.0.0-beta.1.md](docs/RELEASE-NOTES-1.0.0-beta.1.md).

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
