=== Semantic SEO Engine VI-EN ===
Contributors: thuexemayhanoi
Tags: seo, internal links, cannibalization, vietnamese, semantic search
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-beta.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Vietnamese–English semantic SEO auditing: inventory, internal-link suggestions, cannibalization review, Pillar–Hub–Silo clusters, content QA and related content — read-only by default, every edit human-approved and reversible.

== Description ==

Semantic SEO Engine VI-EN analyses the posts, pages and categories of a bilingual (Vietnamese/English) WordPress site and turns the results into reviewable evidence. It never changes content, SEO metadata, URLs or theme output on activation.

**Six modules**

* M1 — URL inventory / SEO matrix with search, filters, CSV/XLSX export and safe-preview CSV import of editorial fields.
* M2 — Internal-link suggestions with exact anchor offsets, evidence and confidence; orphan and coverage reports.
* M3 — Cannibalization review that never compares a page with its own translation; distinguishes competition from distinct facets (daily vs monthly, price vs licence, pillar vs supporting).
* M4 — Pillar–Hub–Silo cluster map built from your existing taxonomy (never creates terms).
* M5 — Content QA (24 deterministic checks: titles, descriptions, headings, broken/redirected internal links, images without alt, noindex conflicts…).
* M6 — Related content block / shortcode / PHP function (off until you enable it).

**Two analysis tiers**

* Lexical tier (always available, runs on any shared hosting): Vietnamese-aware tokenizer (NFC, accent folding, syllable bigrams, language-aware stop words), BM25F index, MinHash near-duplicate detection, link graph.
* Optional semantic tier: Qwen3-Embedding-0.6B (Apache-2.0) runs **only in the administrator's browser** (Web Worker, WebGPU or WASM) after explicit consent, or in an optional private worker you control. No paid AI API, no chatbot, nothing loads for visitors.

**Human Mode and Agent Mode share one rule set**

Every write is a proposal with an exact diff. A human with the review capability approves it in WP Admin (single-use, hash-bound, expiring approval); the change is applied idempotently, verified by readback and can be rolled back. AI agents (REST, WordPress Abilities API on 6.9+, optional official MCP Adapter) can read, analyse and propose — they can never approve.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `semantic-seo-engine-vi-en-1.0.0-beta.4.zip` → Install → Activate.
2. Open **Semantic SEO → Overview & Setup** and follow the five-step wizard (scope → pilot on the latest posts → review).
3. Optional: **Jobs & Inference Device** → check this device → tick the download consent → load the model and run the self-test.

Activation only creates the plugin's own tables (`{prefix}sseo_*`) and options; it does not modify posts, terms, SEO plugin data, rewrite rules or theme output. Full guide: docs/INSTALLATION.md in the source repository.

== Frequently Asked Questions ==

= Does it change my content or SEO settings automatically? =

No. Analysis is read-only. Changes (insert one internal link, assign an existing category) are only applied after a human approves the exact diff, and each change can be rolled back.

= Do my visitors download an AI model? =

No. The front end never loads the model or any AI script. The Related Content block uses precomputed results.

= Does it work on iPhone? =

The lexical tier works everywhere. The browser model may be unsupported or too slow on many phones; the plugin detects this and keeps the lexical tier working. Locking the phone or closing the tab interrupts browser inference (claimed work returns to the queue).

= Which SEO plugins are compatible? =

Yoast SEO, Rank Math, SEOPress, The SEO Framework and All in One SEO metadata are read (never written). Polylang and WPML translations are detected; a URL-prefix mapping is available for other setups.

== External services ==

This plugin connects to **Hugging Face (huggingface.co)** only when an administrator explicitly starts browser inference on the Jobs & Inference Device page and ticks the download consent. The administrator's browser then downloads the pinned model files of `onnx-community/Qwen3-Embedding-0.6B-ONNX` (revision c25a394dd583836952667c12f008335071b3f43d, about 580 MB for q4f16) and verifies their size and SHA-256. No post content, personal data or site URL is sent to Hugging Face; the text is processed inside the browser and the resulting vectors are sent only to your own WordPress site. Hugging Face terms: https://huggingface.co/terms-of-service — privacy: https://huggingface.co/privacy

If "Verify internal link HTTP status" is enabled (off by default), the server sends HEAD requests to **your own site's** internal URLs only.

No other external service is contacted. There is no telemetry.

== Third-party software ==

Bundled in `assets/vendor/transformers/` (browser runtime, loaded only on the Jobs page for administrators):

* Transformers.js 4.3.1 — Apache License 2.0
* ONNX Runtime Web 1.31.0-dev.20260914-8d85527a0 — MIT License
* @huggingface/jinja (MIT) and @huggingface/tokenizers (Apache-2.0), bundled inside transformers.min.js

Model weights are not bundled.

== Changelog ==

= 1.0.0-beta.3 =
* Data integrity: an approved link now changes post_content only, with a compare-and-set write. Before, the write re-saved the whole post and could change the slug/URL, unpublish a post dated in the future, add the default category, re-resolve tags and overwrite an edit saved at the same moment. Apply re-checks that the source and the target are still published; a rollback cannot run twice; an interrupted change is recovered.
* Links are never inserted inside an existing link (apostrophes in attributes), an embed or bare URL, a block rebuilt from attributes (e.g. FAQ blocks), a caption, or in the middle of a name.
* Large sites: the cluster step no longer loads every document at once (20,000 posts: 159 s / 759 MB before, 10.7 s / 156 MB now) and background ticks keep to their time budget.
* Background jobs: a job whose ticks keep crashing fails after its attempts instead of blocking its type; a batch whose database writes failed is retried, never reported as done; WP-CLI waits for WP-Cron instead of spinning; embedding items can no longer get stuck.
* Agents no longer see drafts or private posts that link to a page, nor the text of password-protected posts (no longer stored).
* Overlap report: Search Console and focus-keyword evidence come before cluster roles; "giá rẻ" no longer makes a supporting page; competing pages are not cross-linked; links on drafts do not count as inbound; exact duplicates count as overlap risk in the SEO Matrix. Browser worker keeps the end-of-text token when it truncates long texts (model profile key changes: re-embed).

= 1.0.0-beta.2 =
* Security: the kses filters suspended for an approved write are restored in a finally block; an exception in another plugin's hook is reverted and reported; no other post field may change during a write.
* Embeddings are stored only after the server itself has checked the device's self-test vectors (24 h pass per account and model profile); older workers get HTTP 428 and must be updated.
* Every proposal states its URL, post ID and risk; term proposals are refused when the permalink contains the taxonomy (a URL could change).
* Overlap report: different places in titles are distinct facets, different intents are information, vector-only pairs are the weakest tier; lists are ordered by these tiers. "Missing translation" only for content types the site pairs, never for posts the language plugin declares standalone.
* The Max documents setting is applied; the overview page says when it leaves objects out.
* Faster on large sites (bounded candidate search, cached document frequencies, covering indexes: database schema 3, migrated automatically), reproducible ZIP builds.

= 1.0.0-beta.1 =
* First public beta. Lexical tier, M1–M6, Human Mode approvals with rollback, REST API, Abilities (WP 6.9+), opt-in MCP exposure of read-only abilities, browser inference worker with pinned and verified model files, private worker (Python) for self-hosted embeddings.
* Real Qwen3-Embedding-0.6B (q4f16 default, fp16 option) verified in CI: model-card self-test, browser worker, private worker and hybrid evaluation. Beta until tried on a staging copy of a real site. See docs/TEST-REPORT.md.

== Upgrade Notice ==

= 1.0.0-beta.3 =
Fixes found by an independent audit, including P0 data-integrity issues of the approved-change write path: update before approving any change. The browser model profile changes (long-text truncation fix), so browser-computed embeddings are recomputed. Read-only by default; back up your database before approving any change.

= 1.0.0-beta.2 =
Update the private worker together with the plugin (it now sends its self-test to the server). The plugin's own tables get new indexes automatically (schema 3, data kept). Read-only by default; back up your database before approving any change.

= 1.0.0-beta.1 =
First beta. Read-only by default; back up your database before approving any change.
