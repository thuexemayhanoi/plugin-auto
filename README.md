# Semantic SEO Engine VI–EN — WordPress Plugin
## Master Engineering Specification / README for Claude & AI Coding Agents

> **Status: IMPLEMENTED AS 1.0.0-beta.1 on branch `claude/semantic-seo-engine-vi-en` — BETA, NOT FULLY AI-VERIFIED.**
> The plugin, tests, CI and installable ZIP exist and were verified on disposable local WordPress 6.5.13 / 6.9.10 / 7.1.3 sites and in CI. Real Qwen3-Embedding inference has **not** been run yet (model host unreachable from the build environment — AC06 BLOCKED). Nothing has been installed on any live site. Evidence: [docs/TEST-REPORT.md](docs/TEST-REPORT.md).
>
> | Documentation | |
> | --- | --- |
> | Install / build | [docs/INSTALLATION.md](docs/INSTALLATION.md) · [readme.txt](readme.txt) · [CHANGELOG.md](CHANGELOG.md) · [release notes](docs/RELEASE-NOTES-1.0.0-beta.1.md) |
> | Human guides | [Tiếng Việt](docs/USER-GUIDE-VI.md) · [English](docs/USER-GUIDE-EN.md) |
> | Agents | [docs/AI-AGENT-INTEGRATION.md](docs/AI-AGENT-INTEGRATION.md) · [private worker](tools/private-worker/README.md) |
> | Engineering | [architecture](docs/ARCHITECTURE.md) · [data schema & migrations](docs/DATA-SCHEMA.md) · [security / threat model](docs/SECURITY.md) · [operations & troubleshooting](docs/OPERATIONS.md) |
> | Evidence | [test report (AC01–AC20)](docs/TEST-REPORT.md) · [performance](docs/PERFORMANCE.md) · [compatibility](docs/COMPATIBILITY.md) · [raw results](docs/evidence/) · [screenshots](docs/screenshots/) |
>
> The specification below is unchanged and remains the binding brief.
>
> **Repository (sole write scope):** https://github.com/thuexemayhanoi/plugin-auto  
> **Primary target (read-only until separately authorized):** https://blog.rentbikehanoi.com/ and https://blog.rentbikehanoi.com/en/  
> **Product name:** Semantic SEO Engine VI–EN  
> **WordPress plugin slug (proposed):** semantic-seo-engine-vi-en  
> **Target users:** WordPress administrators and authorized external AI agents.  
> **No chatbot. No generative AI article writing. No paid inference API required.**

---

## 0. CLAUDE: READ THIS FIRST — EXECUTION CONTRACT

Your mission is to **design, build, test, package, and document an actual production-quality WordPress plugin**, not just offer advice or scaffold an incomplete demo. Deliver a coherent, integrated first release instead of repeatedly asking for cosmetic upgrades. Break the work into verifiable implementation stages internally, but preserve the complete architecture from the outset.

**Authority and repository boundary**

1. Read every file, branch, workflow, and repository instruction in **this repository** before editing. This README is the product brief; do not ignore a more restrictive security policy.
2. Commit code **only to thuexemayhanoi/plugin-auto** on a development branch. Never alter thuexemayhanoi/theme, theme-thuha, wp-plugin, wp-aiwriter, wp-writer, another repository, or a live WordPress installation as a side effect.
3. Other themes, plugins, sitemaps, and WordPress data may be consulted **read-only** where access exists. Do not assume access.
4. Creating commits, PRs, builds, and ZIP artifacts in this repo is authorized by this brief. **Production installation, activation, DB migration on the live site, publishing, deleting, redirecting, changing live SEO, or editing other repos are not authorized.** Obtain separate approval for these actions.
5. Treat existing URLs, traffic, SEO settings, permalinks, internal links, metadata, multilingual mappings, and content as protected assets.
6. Use real tests, real code, real output, and real evidence. Never fabricate screenshots, benchmark figures, tests, WordPress access, URL counts, model execution, commit hashes, or deployment success.
7. If the environment lacks internet, GPU, an actual WordPress instance, credentials, or the intended model artifacts, implement what can be validated; explicitly label what remains **UNVERIFIED/BLOCKED** with reproduction instructions. No fake success or simulated model result represented as genuine AI inference.
8. Prevent scope creep. No chatbot, frontend LLM, auto-generated articles, paid SaaS dependency, unapproved mass edits, or opaque AI deciding production SEO.
9. Human administrator **and** authorized AI agent must use the **same business rules and service layer**, not two inconsistent implementations.
10. The deliverable must be installable, maintainable, multilingual, secure, testable and usable from mobile admin screens.

**Definition of completion:** all mandatory modules, tested fallbacks, UI, API/Abilities integration, permission controls, recovery features, tests, packaging and documentation meet the acceptance matrix in section 20. An item is never completed merely because its screen or class exists.

## 1. PRODUCT VISION

Build a **hybrid semantic SEO operations system** for a bilingual WordPress site with roughly 1,000 or more live posts, pages and category URLs. Actual counts must come from WordPress/sitemaps, not estimates.

The system identifies and explains semantic relationships between real URLs, makes useful SEO suggestions, detects possible overlap between same-language pages, and organizes a verifiable, reviewable SEO Matrix. Its output is transparent, reversible and available both in WP Admin and as machine-readable agent tools.

**Two interfaces, one engine**

- **Human Mode:** WordPress Admin dashboard, Setup Wizard, semantic analysis, link suggestions, clusters, QA, export, audit log, approvals and rollback.
- **Agent Mode:** authenticated WordPress REST endpoints, structured WordPress Abilities, and optional exposure through the official WordPress MCP Adapter (only if installed/configured and explicitly enabled). Agents can inspect, analyze, propose, monitor and perform only preapproved changes.

**Three compute tiers**

- **Tier 0 — Deterministic/lexical mode:** works on ordinary shared hosting. No model, GPU or background inference is required. Useful for URL inventories, heading/link/SEO checks, lexical search and safe candidate generation.
- **Tier 1 — Browser-local model:** an administrator explicitly starts Qwen inference in a supported browser using a Web Worker and Transformers.js / ONNX Runtime Web (WebGPU when verified, WASM fallback only if feasible). No site visitor loads the model.
- **Tier 2 — Optional private worker:** if later configured, an external user-controlled worker computes embeddings asynchronously using the same model/profile, authenticated against WordPress. This is not a commercial model API and is optional; do not pretend it is provided by PHP hosting.

No tier can misrepresent keyword rankings, GSC metrics, factual accuracy or semantic confidence.

## 2. AUDIT FIRST, BUILD SECOND

Before coding:

1. Inspect repository HEAD, workflows, dependencies, README and git history; report whether it contains only this specification or actual plugin code.
2. Document the local toolchain, PHP, Node, WordPress test version, operating system, and CI support.
3. Review publicly accessible target-site URLs and robots/sitemap only if lawful and possible, but do not confuse public crawl with authenticated WordPress inventory.
4. Identify current taxonomy, language plugin, SEO plugin, sitemap provider, caching plugin, related-post components, and permalink policies **only from actual evidence**. Unknowns remain unknown.
5. Inspect compatibility with WordPress Abilities API and official MCP Adapter; version-gate rather than silently failing.
6. Verify the exact Qwen model ONNX revision, license, tokenizer configuration, precision, pooling and query/document preprocessing before adopting it.
7. Decide target minimum PHP/WordPress versions based on tested CI combinations; WordPress 6.9+ is needed for the core Abilities API, while the base plugin may degrade gracefully on older environments if explicitly implemented and tested.
8. Write the architecture, threat model, performance budget and proof-of-concept result before large code changes; treat this README as accepted scope.

Do not silently use thuexemaynguyentu.com production as a testing ground. This plugin is initially intended for the Nguyen Ha VI–EN blog.

## 3. REQUIRED MODULES

| ID | Module | Deliverable | Non-negotiable behavior |
| --- | --- | --- | --- |
| M1 | Semantic SEO | URL topic/entity/intent signals, Pillar–Hub–Silo clusters, indexed inventory | Explain the evidence and separate fact from inference |
| M2 | Internal Link Intelligence | source/target/anchor recommendations, orphan candidate discovery, graph analysis | No automatic mass insertion; prevent invalid links |
| M3 | Cannibalization Risk | prioritized same-language overlap groups, reason and evidence | **Risk hypotheses**, not definitive cannibalization without performance data |
| M4 | Content Classification | category/topic classification and reviewable changes | Never create categories or change permalink by default |
| M5 | Content QA Gate | structural, language, metadata, link, and duplication checks | Rules and semantic hints clearly distinguished |
| M6 | Related Content | precomputed semantic/lexical related-post suggestions via optional shortcode, block, PHP API | No frontend inference and no theme override by default |
| OPS | Operations & Matrix | batch jobs, export/import, diagnostics, audit, approval, rollback | Reviewable, resumable, secure |
| AI | AI Agent Interface | WordPress REST, Abilities, optional MCP adapter exposure | Strong authentication and minimum permission |

**M1 — Semantic SEO**: Preserve post ID, URL, type, language, status, categories, content hash, canonical/indexability signals and last analysis. Extract title, headings, concise content chunks, entities/topics where evidence exists, existing outbound/inbound internal links and translation relationships. Search intent classification is a **hybrid heuristic/classifier**, not something inferred reliably from cosine similarity alone. Support mixed-language edge cases and UTF-8 Vietnamese accents.

**M2 — Internal Links**: Construct a site link graph. Suggest source, target, reason, probable anchor location and anchor snippet. Check target existence, final HTTP status when verifiable, internal domain scope, canonical, indexability, locale, self/duplicate link rules, anchor reuse and editorial relevance. Record exact content offset or durable fragment fingerprint for previews. Detect orphans based on links **observed by the scanner** and label crawl coverage.

**M3 — Cannibalization**: Compare same-locale candidate pages by topic, lexical similarity, semantic similarity, title/heading overlap, target query if explicitly set, intent, and optional imported **real** Search Console query/page data. Distinguish:
- exact/near duplicates;
- same topic with different intent;
- valid Pillar + supporting pages;
- VI/EN translations;
- potential true same-query competition.
Never force canonical, consolidation, 301, noindex or content deletion.

**M4 — Topic Clusters**: Propose a cluster tree using existing taxonomy first. Label Pillar/Hub/Silo/Supporting content as **editorial recommendations**; permit manual overrides, locks, review states, exclusions and versioned approvals. Do not create hundreds of near-identical taxonomy terms.

**M5 — QA**: Checks include missing/inconsistent SEO metadata (where visible), titles, heading hierarchy, schema duplication risk (only if evidenced), broken internal links, missing translations, hreflang/canonical conflicts, robots status, language mismatch, near-duplicate content, excessive repetitive phrasing, thin or unhelpful passages relative to intent, orphan risk and missing related links. Some are **verified errors**, others **warnings**, and others **AI suggestions**; never conflate them. No made-up E-E-A-T score or claim to verify truth without sources.

**M6 — Recommendations**: Precompute related content by locale and relevance; expose via shortcode, block, and PHP filter/API. Cache with explicit invalidation; keep frontend output lean, accessible, theme-compatible and disabled until enabled in settings. Do not inject into body/footer automatically at activation.

## 4. QWEN MODEL POLICY — AI THAT DOES ONE JOB WELL

**Preferred:** Qwen3-Embedding-0.6B, an open-weights embedding model (not a chat or article-writing model). Official model card: https://huggingface.co/Qwen/Qwen3-Embedding-0.6B

Optional browser-oriented ONNX candidate: https://huggingface.co/onnx-community/Qwen3-Embedding-0.6B-ONNX

Documentation: https://huggingface.co/docs/transformers.js/ and https://huggingface.co/docs/transformers.js/guides/webgpu

**Required verification before release:**
- Confirm the actual downloadable ONNX artifact, weights, tokenizer, compatibility, model revision, license and hash; do not assume every quantized variant works.
- Implement query/document instruction/prefix processing consistent with the verified model card. Validate pooling and L2 normalization against reference outputs; do not substitute a generic mean pooling method without testing.
- Validate output dimensions, truncated/MRL dimensions if used, cosine similarity normalization and long-document chunking. Model supports up to 1024-dimensional embeddings, but effective dimension, memory and retrieval quality require measured tradeoffs.
- Test relevant Vietnamese accent handling, English queries, translation pairs and mixed-language text. Provide an evaluation dataset and expected metrics.
- Store (model ID, revision, quantization, dimension, preprocessing version, locale, content hash) with every embedding; changes require controlled reindexing.
- Display model size, approximate initial download, compatible devices, potential bandwidth/privacy implications and active inference tier **before** downloading.
- Do not bundle enormous model weights into plugin ZIP. Model download must be explicit and pinned; check origin integrity and caching behavior. Model assets downloaded to the browser do **not** imply that post content is sent to that host.
- Provide lexical fallback; if WebGPU/WASM cannot reliably execute on an iPhone or a Cloud Browser, show **unsupported on this device**, never invent a successful run.
- No inference in public requests, no hidden remote AI calls, no commercial AI API keys as a prerequisite.

**Strong limitation:** Embeddings are similarity vectors. They cannot independently establish Google keyword cannibalization, search volumes, ranking positions, user intent with certainty, truthfulness, policy compliance, or article quality. All such outputs require additional logic, evidence and uncertainty labels.

## 5. HYBRID RETRIEVAL AND SCORING

Use a deterministic lexical retrieval layer (BM25 or equivalent tested index) plus Qwen embeddings. Build candidate sets through lexical/structural filtering **before** vector reranking when beneficial. Handle Vietnamese tokenization using Unicode normalization, accent-aware tokens and a validated n-gram fallback; do not assume splitting on spaces alone suffices.

Recommend per-task scoring rather than one universal magic weight:

- Link relevance: compatible locale + crawl validity + lexical topic + semantic topic + anchor suitability + graph usefulness.
- Cannibalization: locale + intent overlap + lexical/title evidence + semantic evidence + real query overlap if available; use manual-review category for ambiguous pairs.
- Classification: human-maintained taxonomy anchors + editorial intent + lexical and semantic cluster consistency.
- Related content: locale + topical relevance + diversity + exclusion/canonical rules.

Return separate evidence components and an algorithm version. Confidence is calibrated on labeled VI/EN cases, not a fabricated percentage. Provide top-k, thresholds, evaluation/override controls and reports of false positives and negatives. Golden benchmark examples include Vietnam tourism versus motorbike rental, 50cc licensing versus 50cc pricing, monthly rental versus daily rental, and EN translation of a VI article.

## 6. TARGET-SITE DATA & SEO MATRIX

**Discovery:** inventory WP Post, Page, Category/taxonomy with appropriate permissions and legitimate public sitemap signals. Deduplicate by stable WordPress IDs, canonical URLs, language mappings and post types. Record publication/update timestamps, publish status, content fingerprint, last crawl and data provenance.

A row includes at least: object ID/type, URL, locale, status, title, taxonomy, optional source-grounded primary keyword, editorial intent, proposed/locked Pillar–Hub–Silo, canonical, meta robots, sitemap presence when verified, translation pair, inbound/outbound internal-link counts (with scan coverage), proposed links, overlap-risk group, QA issues, content hash, model/scoring version, human review state and last analyzed. Optional GSC metrics appear **only** after a genuine authorized import/connection, with period and source.

**Matrix features:** server-side pagination, search, multiple filters, risk sorting, exports, import preview with column mapping, invalid-row isolation and resumable jobs. Export CSV UTF-8 BOM if needed for Excel; optional true XLSX via tested compatible dependency. Never claim a CSV renamed to XLSX is a spreadsheet workbook. Import must not silently overwrite existing values or treat empty cells as deletion.

**No fake data:** inventory counts, links, statuses and scores must be based on actual accessible objects; demo fixtures live only in test environments and are clearly labeled.

## 7. WORKFLOW: READ → ANALYZE → PROPOSE → APPROVE → APPLY → VERIFY

**Default state after activation: READ-ONLY.**

1. Inventory relevant URLs and sources with timestamps and scan coverage.
2. Run deterministic checks. Queue local/browser or private-worker embeddings only when requested.
3. Generate a ranked **proposal** with human-readable reason, uncertainty, affected URL IDs and precise editable diff.
4. Administrator previews changes; run a dry-run and verify that target content hash has not changed since analysis.
5. High-risk content edits require explicit approval in WP Admin, bound to scope, object IDs, user, expiry, exact diff hash and action category.
6. Write only supported, reversible operations in a transaction-like safeguarded sequence; log before/after snapshots, idempotency key and actor.
7. Verify the resulting WordPress value and public URL when available. On failure, stop and revert safely, reporting any manual recovery need.

**Forbidden without separate explicit action-specific approval:** auto-publish, mass inline edits, changing H1/H2/H3, permalink/slugs, redirect chains, canonical, robots/noindex, sitemap settings, delete/merge, untrusted redirects, theme files, SEO plugin config, categories at scale or production schema migration. Do not permit an agent to self-approve through another endpoint.

Give administrators lock/exclude options for priority URLs, established SEO metadata and entire topic groups. Locks are enforced by shared service code for Admin and API/Agent alike.

## 8. WORDPRESS ADMIN (HUMAN MODE)

Build clean, responsive, accessible, keyboard-friendly UI in Vietnamese first, with English translations. An administrator with no programming knowledge must be able to operate basic features without understanding vectors or API schemas.

**Pages:**
1. Overview & quick-start wizard.
2. URL Inventory / SEO Matrix.
3. Semantic Analysis.
4. Link Suggestions & Graph.
5. Cannibalization Review.
6. Pillar–Hub–Silo Clusters.
7. Content QA.
8. Related Content Output.
9. Jobs & Inference Device.
10. Agent Connections / Permissions.
11. Settings & Diagnostics.
12. Audit / Undo / Rollback.

Each page needs functional controls, loading/empty/error states, permissions, result filtering, accessible responsive tables and actionable explanations. No placeholder buttons, imaginary stats or impossible automatic retries. Mobile admin must show read/report/approve workflows without requiring horizontal overflow for primary actions.

**First-run UX:**
- Detect WP/PHP/runtime/plugin compatibility.
- Explain three compute tiers and which is available **now**.
- Default to read-only and preview-only.
- Choose inventory scope, languages, exclude rules, batch size and max resources.
- Test model loading only with informed administrator action.
- Start with a small read-only pilot on real or staging data and show the count/coverage.
- Show "Not configured", "Ready (lexical)", "Model loaded", "Processing", "Needs review", "Failed", "Paused" accurately.
- Keep advanced weights/inference/dimensions hidden behind an expert panel with tested defaults.

Do not automatically start large scans on activation.

## 9. AGENT MODE — MACHINE-READABLE FIRST CLASS CITIZEN

The plugin must expose its capabilities through a **single permissioned business-service layer** for WordPress UI, REST and Abilities. AI agents should not have to read the plugin PHP code to discover how to use it.

**WordPress Abilities API:** core support is available in WordPress **6.9+**. Register abilities on the appropriate initialization hook with accurate input/output JSON Schema, human descriptions, execution callbacks and permission checks. When the Abilities API is absent, the plugin should still provide its safely supported features (if compatibility is a product decision) and show that agent-native integration is unavailable — no fatal error.

Official docs: https://developer.wordpress.org/apis/abilities-api/  
Official WordPress MCP Adapter: https://github.com/WordPress/mcp-adapter

**Exposure rule:** Abilities are private by default; opt in **only** for selected, admin-enabled read-only abilities through the adapter. The official adapter needs separate installation/connection and an authenticated client. Installing this plugin does not automatically connect Claude or ChatGPT. AI authentication never grants more rights than the underlying WordPress user.

**Proposed ability namespace** (adjust exact registration shape to the tested WordPress API):

| Ability name | Access | Input | Result |
| --- | --- | --- | --- |
| semantic-seo/get-site-status | Read | none | version, mode, index counts, coverage, diagnostics |
| semantic-seo/list-urls | Read | locale,type,limit,cursor | paginated inventory with provenance |
| semantic-seo/get-url-analysis | Read | post_id or URL | structured evidence and warnings |
| semantic-seo/get-link-suggestions | Read | URL/locale,min_relevance | ranked candidates with reasons |
| semantic-seo/get-cannibalization-report | Read | locale,cluster,limit | evidence groups and uncertainty |
| semantic-seo/get-topic-clusters | Read | locale,cluster | tree and recommended mappings |
| semantic-seo/get-qa-issues | Read | severity,URL,cursor | checks with verified/suggested labels |
| semantic-seo/get-job-status | Read | job_id | queued/running/failed/completed and progress |
| semantic-seo/get-audit-log | Privileged read | filters,limit | redacted action history |
| semantic-seo/start-analysis | Controlled | bounded targets,mode | idempotent job with owner |
| semantic-seo/cancel-analysis | Controlled | job_id | cancellation request/status |
| semantic-seo/rebuild-index | Controlled | scope,model_version | confirmed background job |
| semantic-seo/create-change-preview | Controlled | exact proposal IDs | diff hash, guardrails |
| semantic-seo/apply-approved-change | Sensitive write | approved change ID,nonce/idempotency | readback and audit, rejects stale approval |
| semantic-seo/rollback-change | Sensitive write | change ID + separate authorization | verified restoration |

**REST endpoints** (design examples; implement with permission_callback): route prefix /wp-json/semantic-seo/v1. Provide GET status, inventory, analysis, suggestions, clusters, QA, jobs and audit; POST bounded analysis, cancel, preview, approved apply and rollback. Validate JSON Schema, sanitize URLs/object IDs, enforce page sizes, meaningful errors, HTTP status, predictable response envelope and server-side auth. Avoid giant full-site responses; use cursors and pagination.

**Security levels:**
- PUBLIC: none for admin data or AI controls.
- READ: explicitly authorized WP users; use custom capabilities where useful.
- ANALYZE: authenticated jobs with concurrency/quotas.
- REVIEW/APPROVE: trusted humans in WP Admin.
- APPLY/ROLLBACK: strong permissions **and** matching prior human approval; cannot be escalated by AI token alone.

For cookie-based REST, follow WP nonce conventions; for off-site agents use HTTPS and appropriately scoped WordPress Application Passwords or a more restrictive supported auth mechanism. Document password creation, account isolation, revocation and logs; never store secrets in repo or show raw tokens in dashboards. Rate-limit and log sensitive actions.

**No unsafe "AI can do anything" endpoint.** Do not expose arbitrary PHP/SQL/shell/file operations, dynamic admin-option mutation, secret retrieval or agent self-approval.

## 10. COMPUTE & JOB ORCHESTRATION

**Lexical worker** runs on shared hosting in bounded PHP jobs; never blocks public page requests. Incremental index on content changes, not perpetual full scans.

**Browser worker** loads tokenizer/model in an isolated Worker on explicit Admin action, processes small payload batches, validates model state and uploads embeddings/results only through authenticated WP REST. It must report that closing the tab or iPhone going to sleep can interrupt work. Implement pause/resume/cancel, idempotent queue claim/release, progress and retry.

**Optional private headless worker** may use local Python/Node/ONNX runtime or a self-controlled compute service. It must be documented and secured with least privilege, with replay protection, rate limit, model pinning, batch bounds and verifiable status. Never require this for basic plugin activation.

Shared constraints: queue leases/locks, max batch, timeout, backoff, resumable cursor, bounded memory, crash recovery, cancellation, idempotency, no overlapping duplicate jobs, explicit worker heartbeat. If WP-Cron is used, it should orchestrate short tasks, not run a 0.6B model in a typical PHP request. Consider Action Scheduler only if it meaningfully improves reliability without conflicts. Provide WP-CLI utilities for authenticated operators.

**Incremental invalidation:** reanalyze on content changes, permalink/canonical/language map changes, index config/model revision changes, and source deletion. Store document/content hashes; re-run only affected workloads. Do not trigger loops on plugin-generated metadata changes.

## 11. PROPOSED PLUGIN COMPONENTS

Implementation may adjust file naming, but preserve boundaries and testability. Suggested deliverable tree:

    semantic-seo-engine-vi-en/
      semantic-seo-engine-vi-en.php
      uninstall.php
      readme.txt
      composer.json
      package.json
      includes/
        Bootstrap.php
        Capabilities.php
        Settings.php
        Database/
        Inventory/
        Extractor/
        Language/
        Lexical/
        Semantic/
        Ranking/
        Graph/
        Cannibalization/
        Clustering/
        Quality/
        Jobs/
        Recommendations/
        Approval/
        Rollback/
        Audit/
        REST/
        Abilities/
        Diagnostics/
      admin/
        assets/
        views/
        controllers/
      assets/
        js/ai-worker/
      languages/
      templates/
      tests/
        php/
        js/
        fixtures/
        integration/
        e2e/
      docs/
        INSTALLATION.md
        USER-GUIDE-VI.md
        ADMIN-GUIDE.md
        AI-AGENT-INTEGRATION.md
        ARCHITECTURE.md
        SECURITY.md
        PERFORMANCE.md
        COMPATIBILITY.md
        TEST-REPORT.md
      .github/workflows/
      README.md

Follow WordPress plugin standards, namespaces and autoloading; bundle only production assets in the release ZIP, not node_modules, dev caches or full model weights. Separate UI components, services, repositories, transport controllers and model adapters so refactoring does not break all modules.

## 12. STORAGE AND MIGRATION CONTRACT

Use WordPress-native post/meta/options only where appropriate; for scalable indexing and jobs, design versioned custom tables with proper indexes. Candidate logical records:

- documents: object ID, locale, canonical/URL, status, content hash, timestamps, metadata provenance;
- chunks: document ID, content hash, boundaries/headings, chunk kind, locale;
- embeddings: chunk ID, model revision, normalization/dimension, quantization/preprocess version, compact vector representation;
- lexical index: tested term/document stats and tokenizer version;
- link edges: source/target, anchor, location, crawl status, scan coverage;
- analyses/recommendations: type, algorithm version, evidence, confidence category, status;
- jobs: queued/leased/running/failed/complete, owner, heartbeat, progress, retry;
- approvals: authorizing human, scope, diff hash, expiry, applied/invalidated state;
- change log/snapshots: before/after, actor, rollback capability, timestamp;
- locks/exclusions and per-site settings.

Exact schemas should be justified via benchmarks. WordPress database migrations must be versioned, repeatable, non-destructive and tested from clean install, version upgrade and interrupted upgrade. Deactivation keeps data. Uninstall defaults to **preserve data** or requires a separately confirmed cleanup policy. Backup/restore procedures must be documented. Reindex can reconstruct derived data without re-writing posts.

Never store API secrets in source control, unbounded logs, or expose private draft content in public REST responses.

## 13. WORDPRESS / SEO COMPATIBILITY

Detect, do not assume:

- Existing SEO plugin(s), metadata fields, schema output, canonical and robots.
- VI–EN framework (Polylang/WPML/custom or other).
- Related posts component, theme hooks, shortcodes and block styles.
- Caching/CDN layers and cron tools.
- Access limitations of WordPress roles/capabilities.
- Import/plugin compatibility.

Follow existing authoritative metadata rather than duplicating it; use adapters for supported SEO and language plugins. Gracefully degrade if no translation mapping can be verified. Keep original WP post ID, canonical and post language stable. Do not inject a second JSON-LD graph or new canonical solely because this plugin is installed.

Avoid keyword stuffing, doorway pages, duplicate AI content, thin content, hidden links and mass anchor replacements. Avoid changing established H1/H2/H3 and slug unless a separate approved diff explicitly requires it.

## 14. SECURITY AND PRIVACY THREAT MODEL

Implement and test:
- all privilege checks and WP REST permission callbacks;
- CSRF/nonce in Admin, capability enforcement in all transports;
- input validation, escaping, prepared SQL, strict URL allowlist and SSRF defenses;
- stored/reflected XSS prevention with adversarial HTML content;
- rate limiting per user/endpoint, pagination caps, circuit breakers and timeouts;
- model file integrity and safe download/update behavior;
- data exfiltration prevention, redacted logs and PII minimization;
- no arbitrary file upload or command execution through agent APIs;
- replay resistance for approval/apply/rollback;
- concurrency safety under simultaneous admin + agent actions;
- idempotency and rollback when a write fails;
- untrusted model suggestions **never** treated as privileged instructions.

Write operations must operate on known WordPress objects with changes fully specified and permissioned. Prevent an AI user from changing its own role, creating approval credentials, or bypassing review through a direct REST call.

Public frontend response must be cached and sanitized, without secret content or model internals.

## 15. PERFORMANCE & COST BUDGET

The success target is a normal WordPress installation on modest hosting:

- No AI model loaded on customer-facing pages.
- No Qwen model process running inside regular PHP HTTP requests.
- Frontend only renders lightweight precomputed recommendations when explicitly enabled.
- A thousand URLs should not require fetching all posts into memory at once.
- Work queues have bounded batch and retries, and can resume after disconnect.
- Server-side Matrix filtering/pagination; prohibit giant unpaginated REST responses.
- Vector comparison works on a filtered candidate set where feasible rather than naive all-pairs on every page load.
- Use cache + invalidation rather than unnecessary repeated calculations.
- Provide separate **measured** reports for CPU/RAM, query latency, admin response, indexing throughput, model download, inference speed, and initial vs incremental pass.

Do not invent numeric benchmarks or "100/100 performance" scores. Define thresholds from baseline testing, explain device-specific limitations, and show evidence for claims. Browser-local inference is allowed to be slow or unavailable on some iPhones; lexical tier must continue to function.

## 16. RELEASE AND USER EXPERIENCE

**Installation target:** A genuine installable ZIP containing one plugin root folder. It must activate without fatal errors in every declared supported WordPress/PHP environment. Show an informative admin notice (not fatal) when optional AI/MCP capabilities are not available.

**Basic path for humans:**
1. Upload and activate ZIP.
2. Open Semantic SEO → Setup Wizard.
3. See verified environment compatibility and read-only defaults.
4. Run a safe, small inventory pilot.
5. Review QA/link/cluster suggestions.
6. Optionally enable and test browser Qwen model.
7. Export Matrix and approve specific changes if desired.
8. View rollback options for changes made by this plugin.

**Basic path for agents:**
1. Administrator authorizes a dedicated low-permission WordPress identity.
2. Configures REST and optionally enables supported Abilities/MCP transport.
3. Tests connection against site-status ability.
4. Discovers only allowed read-only tools and schema.
5. Lists inventory with pagination, requests bounded analysis, fetches reports.
6. Builds proposals, not hidden writes. A human reviews in WP Admin.
7. Receives readback and audit only for actual approved changes.

**Documentation examples:** "Find 20 high-risk orphan candidates with evidence", "Review internal links for 50cc pages without changing H1/H2", "List bilingual translation mapping gaps", "Export VI–EN Matrix", "Analyze without activating a model". Clearly show which tasks need lexical-only, browser device, or private worker.

## 17. IMPLEMENTATION ORDER — ONE COHERENT RELEASE

This is **one complete product scope**; stages are engineering quality gates, not excuses to stop at an MVP.

**Gate A — Foundation:** plugin bootstraps, capabilities, settings, supported WP/PHP versions, DB migration tests, read-only inventory and diagnostics.

**Gate B — Deterministic engine:** extraction, language adapters, lexical index/BM25, graph, QA rules, Matrix export/import preview, safe proposals; benchmark on fixtures.

**Gate C — Semantic engine:** verified Qwen inference adapter, pinned ONNX/tokenizer, model/locale test vectors, model caching, browser worker, fallback and async job persistence.

**Gate D — Six modules:** full end-to-end integration of M1–M6, documented scoring and tests, verified admin UI on desktop and mobile.

**Gate E — AI tools:** REST API, native Abilities (version-gated), optional verified MCP Adapter path, capabilities, JSON Schemas, API docs and negative security tests.

**Gate F — Mutations and operations:** exact diff, human approval, idempotent apply, snapshot, rollback, logging, concurrency, automated failure recovery.

**Gate G — Release:** security review, WP coding standards, unit/integration/e2e tests, real local/staging activation, WordPress compatibility matrix, ZIP install smoke test, CI, documentation, PR and reproducible artifacts.

Completion requires passing **all applicable mandatory gates**. A blocked environment is reported honestly as a blocker; never ship an empty stub under a finished label.

## 18. REQUIRED TESTS

**PHP / WP:** PHPUnit, PHP lint, WordPress Coding Standards, install/activation/deactivation, migrations, scheduled jobs, REST permissions, Abilities schemas, application-password auth integration in a safe test instance, rollback, reindex, WP-CLI behavior and uninstall preservation.

**JS/browser:** tokenizer/model loader, WebGPU fallback behavior, Web Worker cancellation, reconnect/resume, batch serialization, invalid payloads, UI accessibility/keyboard, mobile viewport, no frontend model requests.

**Semantic & SEO fixtures:** Vietnamese Unicode and accents, EN/VI translations, same intent vs distinct intent, pillar/supporting relation, poor and good link suggestions, nonexistent URL, redirect/noindex, stale content hash, duplicate anchors, mixed-language headings, missing category map, drafts/private posts.

**Security:** guest call, editor vs admin, no capability, invalid nonce, SSRF attempt, XSS payload, SQL injection, request replay, double-apply, forged approval, expired approval, changed source document, agent self-approval, large API payload and secret leakage.

**Load & reliability:** 10/100/1,000 URL inventories where fixtures or actual authorized data permit, large content chunks, interrupted browser inference, duplicate jobs, rate limit, DB timeouts, update/remove post mid-job, multiple concurrent operators.

**Measure relevance:** create and publish a curated VI/EN evaluation set with expected relevant pairs and false-positive controls. Record precision@k, recall@k and overlap warning rates from the actual evaluation. Never represent embedding similarities as guaranteed SEO outcomes.

## 19. CI, GIT AND DELIVERY

- Work only on a feature branch **within plugin-auto**.
- Use understandable commits and include tests alongside functionality.
- Add GitHub Actions checks for relevant supported PHP/WP and Node versions, lint, unit tests, integration/e2e where available, dependency and packaging checks, and unsafe-secrets scan. Do not mark skipped CI as passed.
- Generate a tagged/versioned ZIP artifact (only after tests pass), ensure exact plugin root folder and WordPress plugin headers, and verify install/activate/deactivate from that ZIP on a disposable WordPress instance.
- Open a PR with scope, diff summary, dependencies, test evidence, known limits, screenshots when genuine, review status and rollback instructions.
- Do not force-push production history, change unrelated repos, or deploy live changes absent separate approval.
- Do not claim "installed", "works live", "MCP connected", "Qwen runs on iPhone", or "SEO fixed" without corresponding readback/logs/tests.

Deliver files: source, production ZIP, release notes, changelog, complete WordPress install guide, human guide VI/EN, agent integration guide, security/threat model, data schema/migrations, architecture diagram, operational troubleshooting, and TEST-REPORT with actual commands and results. If documentation is split across files later, this README stays the main entry point linking them.

## 20. ACCEPTANCE MATRIX — BINARY GATES

| ID | Pass only with evidence that... |
| --- | --- |
| AC01 | ZIP installs and activates without fatal error on declared supported versions |
| AC02 | Activation is read-only and does not alter existing WP content/SEO/URLs |
| AC03 | Inventory accurately discovers real Post/Page/Category records and locales |
| AC04 | Matrix paginates, searches, filters, exports and imports by safe preview |
| AC05 | Lexical mode produces useful checked results **without any model** |
| AC06 | Real Qwen embedding inference is demonstrated with model/revision/test vectors, or clearly marked blocked and **release is not marked fully AI-verified** |
| AC07 | Unsupported WebGPU/mobile browser is handled without fatal failure |
| AC08 | M1–M6 outputs have evidence, provenance and safe review workflow |
| AC09 | VI–EN translations are not falsely flagged as same-locale cannibalization |
| AC10 | All write actions require proper permission, exact diff and separate human approval |
| AC11 | Rollback reliably restores data for every operation claimed reversible |
| AC12 | REST responses are schema-validated, paginated, permission checked and tested |
| AC13 | Abilities are registered and verified on supported WP core, version-gated elsewhere |
| AC14 | MCP exposure is opt-in, correctly authenticated and tested against installed adapter; absent connection is explicitly reported |
| AC15 | Agent cannot approve its own changes or use privileged endpoints with read-only credentials |
| AC16 | No frontend model load or hidden paid AI API |
| AC17 | Batch jobs resume, cancel, avoid duplicates and expose honest progress |
| AC18 | Existing SEO/theme/language/canonical behavior remains unchanged by default |
| AC19 | Performance numbers, test results, build artifacts and screenshots are **real** |
| AC20 | README, full docs, CI, release ZIP, PR and installation guide match implementation |

**Release decision:** PASS / FAIL / BLOCKED for each AC, with a source command, test identifier, link or artifact. "Not run" is not "pass". A functional partial release may be labeled beta/experimental, but it may not be called complete.

## 21. FINAL REPORT FORMAT FOR CLAUDE

After execution, provide only factual, concise status:

- Repository: thuexemayhanoi/plugin-auto
- Branch / commit / PR URL
- Plugin version and validated PHP/WordPress compatibility
- ZIP artifact location and install-test result
- M1–M6 state: PASS/FAIL/BLOCKED, not subjective praise
- Qwen: actual model revision, runtime, hardware, benchmark or exact blocker
- REST/Abilities/MCP: verified capability and remaining connection requirements
- Test suite: commands, passed/failed/skipped counts and CI links
- Evaluated VI/EN URL count and source type (fixtures, staging, real authorized site)
- Security/rollback result and known risks
- What is **not** complete
- Exact next installation/approval action required from site owner

Do not require the owner to inspect hundreds of files or approve routine coding decisions. Escalate only true scope/permission conflicts, irreversible production changes, security incidents and blockers that require credentials or human decisions.

## 22. OFFICIAL TECHNICAL REFERENCES

Use upstream documentation and verify versions before implementation:

- Qwen embedding model card: https://huggingface.co/Qwen/Qwen3-Embedding-0.6B
- Browser ONNX candidate: https://huggingface.co/onnx-community/Qwen3-Embedding-0.6B-ONNX
- Transformers.js: https://huggingface.co/docs/transformers.js
- Transformers.js WebGPU: https://huggingface.co/docs/transformers.js/guides/webgpu
- WordPress Plugin Handbook: https://developer.wordpress.org/plugins/
- WordPress REST API: https://developer.wordpress.org/rest-api/
- WordPress Abilities API (WP 6.9+): https://developer.wordpress.org/apis/abilities-api/
- WordPress MCP Adapter (optional): https://github.com/WordPress/mcp-adapter
- WordPress security practices: https://developer.wordpress.org/apis/security/

---

## 23. CÂU LỆNH NGẮN CHO CLAUDE / ONE-COMMAND HANDOFF

**Read this README in thuexemayhanoi/plugin-auto as the binding technical specification. Audit the empty/new repository first, implement the complete Semantic SEO Engine VI–EN WordPress plugin with Qwen3-Embedding-0.6B and a trustworthy lexical fallback, full human dashboard, six SEO modules, secure Agent Mode through REST/Abilities and optional official MCP Adapter, real tests, reversible reviewed edits, installable ZIP, documentation, CI and a PR, all in this repository. Keep existing live WordPress/SEO/data and all other repositories untouched. Proceed autonomously through safe development stages; report evidence, genuine blockers, and remaining production approvals. Do not claim completion without acceptance-test evidence.**

---

**Current state of this README:** product specification only. No deployed website or plugin is modified by changing this file.