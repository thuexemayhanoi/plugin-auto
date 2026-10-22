=== Semantic SEO Engine VI-EN ===
Contributors: thuexemayhanoi
Tags: seo, internal links, cannibalization, vietnamese, semantic search
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0-beta.1
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

1. Plugins → Add New → Upload Plugin → choose `semantic-seo-engine-vi-en-1.0.0-beta.1.zip` → Install → Activate.
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

= 1.0.0-beta.1 =
* First public beta. Lexical tier, M1–M6, Human Mode approvals with rollback, REST API, Abilities (WP 6.9+), opt-in MCP exposure of read-only abilities, browser inference worker with pinned and verified model files, private worker (Python) for self-hosted embeddings.
* Beta because real Qwen3-Embedding inference could not be executed in the build environment (model host unreachable); the pipeline is verified with a labelled tiny fixture model. See docs/TEST-REPORT.md.

== Upgrade Notice ==

= 1.0.0-beta.1 =
First beta. Read-only by default; back up your database before approving any change.
