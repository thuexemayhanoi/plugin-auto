# Security and threat model

Scope: the plugin code in this repository (PHP, admin JS, browser worker) and the optional
private worker in `tools/private-worker`. Report vulnerabilities privately to the repository owner
(GitHub security advisories on `thuexemayhanoi/plugin-auto`), not in public issues.

## 1. Assets and trust boundaries

| Asset | Threat | Primary control |
| --- | --- | --- |
| Post content, SEO metadata, URLs, taxonomy | unauthorised or silent modification | read-only analysis; writes only via human-approved, hash-bound proposals with readback and rollback |
| Approval decision | agent self-approval, forged/replayed approvals | approvals only from interactive WP Admin (cookie + nonce + `sseo_review` + confirmation); single-use atomic consume; expiry; bound to proposal, object and diff hash |
| Credentials (Application Passwords, lease tokens) | leakage via responses, logs, repository | never stored by the plugin; lease tokens stored as SHA-256 only; audit redaction; secret scan in CI and in the ZIP build |
| Server resources | DoS, runaway jobs | rate limits per user and route class; page/batch/payload caps; bounded jobs with leases, time budget, one active job per type, one per agent |
| Visitors | model downloads, tracking, XSS | front end renders only a cached, escaped list when explicitly enabled; no scripts, no external requests (verified) |
| Model integrity | tampered or swapped weights | pinned model ID + revision, size + SHA-256 per file, verified before every use (also from cache), self-test vs model-card reference before any upload, model key bound to every vector |

## 2. Actors and capabilities

`sseo_read`, `sseo_analyze`, `sseo_embed`, `sseo_review`, `sseo_apply`, `sseo_manage`.
Administrators (`manage_options`) hold all of them **in interactive sessions**. The
`user_has_cap` filter removes `sseo_review` and `sseo_manage` (and `sseo_apply` unless the
administrator enabled "agents may execute already-approved changes") for:

* users with an agent role (`sseo_agent_reader`, `sseo_agent_analyst`, `sseo_agent_operator`,
  `sseo_worker`), and
* **any request authenticated with an Application Password**, including an administrator's.

Agent roles also never receive core escalation capabilities (`edit_users`, `promote_users`,
`manage_options`, `activate_plugins`, …). Administrators and the current user cannot be turned
into agent accounts from the UI.

## 3. Controls by attack class (tested)

| Attack | Control | Evidence |
| --- | --- | --- |
| Guest / wrong password | every route has a `permission_callback`; core Application Password auth | `http-agent` "guest is refused", "wrong Application Password" |
| Missing/forged nonce (CSRF) | `check_admin_referer()` on every admin-post action; REST cookie auth requires `X-WP-Nonce` | `AdminUiTest::test_actions_require_valid_nonce`, `http-agent` cookie test |
| Privilege escalation via agent | capability filter above; no approve route/ability; MCP exposure limited to read-only abilities (filtered server-side) | `http-agent` approve-route 404, operator/admin-app-password 403, MCP write refused; `ChangeLifecycleTest` |
| Forged / expired / replayed approval | approval lookup by ID **and** proposal **and** scope; `UPDATE … WHERE status='active'` consume; expiry | `ChangeLifecycleTest` (forged, expired, replay, double apply) |
| Stale content (TOCTOU) | base hash re-checked under an object mutex right before writing; readback verification; auto-revert | `ChangeLifecycleTest` stale cases, UI rollback byte-identical |
| Double apply / retries | idempotency key (hash stored, unique index); replays return the original change | `ChangeLifecycleTest`, `OperationsTest` |
| SQL injection | all queries through `Db` helpers using `$wpdb->prepare()`; whitelisted sort columns; schema-validated REST args | `http-agent` SQLi strings, `RestApiTest` |
| XSS | all admin output escaped or passed through `wp_kses()` (`Ui::out`); REST is JSON with `nosniff`; inserted link markup built with `esc_url`/`esc_html`; kses bypass only for the single verified write and only for the exact computed result | WPCS `EscapeOutput` clean; `http-agent` XSS |
| SSRF | agent-supplied URLs are only resolved against the local inventory; the only outbound HTTP (optional link verification) uses `wp_safe_remote_head` to the site's own host without redirects | `http-agent` SSRF (metadata IP, localhost port, `file://`, external host) |
| Oversized payloads / DoS | 512 KB body cap (413), ≤ 100 rows per page, ≤ 200 targets per job, ≤ 16 vectors per claim, per-user rate limits (429 + `Retry-After`) | `http-agent` 413/429, `RestApiTest` |
| Embedding replay / poisoning | single-use leases (hash only), owner-bound, 180 s expiry; model key, dimension, finite values and unit norm validated; stale content rejected | `EmbeddingQueueTest`, `http-agent` embedding lease |
| Device claims a model it does not run (mock, wrong model or pipeline) | a device's own "self-test passed" is not trusted: it must send its reference vectors to `/embeddings/selftest`; the server recomputes the similarity matrix, compares it with the matrix measured for the precision and keeps a 24 h pass per user and model profile; `complete` answers 428 without a pass; a failed re-check revokes it | `DeviceVerificationTest`, `http-agent` 428, real model in `HybridEvaluationTest` and the private-worker e2e |
| A hook or plugin throws during an approved write | the kses filters suspended for the verified write are restored in a `finally` block (exact priority); the exception becomes an error → automatic revert; other post fields (title, excerpt, status, slug, parent, author, password, menu order, type) must stay unchanged or the change is reported as needing manual recovery | `ChangeExecutorSafetyTest` (fails on the previous code) |
| Secret leakage | audit redaction of secret-named keys, Application-Password-shaped strings and bearer tokens; IPs stored as HMAC; responses scanned for every test credential | `http-agent` (each response checked for all secrets), `bin/scan-secrets.sh` |
| Supply chain (browser runtime) | runtime bundled same-origin (no CDN), pinned versions, `manifest.json` with SHA-256, `npm` install scripts disabled (`.npmrc`) | `worker-fixture` "no request leaves the allow-list" |
| Model download abuse | fetch allow-list: only pinned files of the pinned revision from the profile host; unknown paths 404 without network; downloads larger than the pin are aborted | `tests/js/embedder-core.test.mjs`, `worker-fixture` tampered-file test |

## 4. Data handling and privacy

* Post text processed by the browser model stays in the administrator's browser; only vectors are
  sent to the site. Only model files are downloaded from huggingface.co, after explicit consent.
* No telemetry. The private worker talks only to your site and the model host.
* Audit log retention is configurable (default 180 days, pruned daily). IPs are stored as a salted
  HMAC prefix, never raw.
* Drafts/private posts are excluded unless an administrator includes them; they are never exposed
  to agents through list endpoints or on the front end.

## 5. Known limitations

* The integrity pipeline was exercised with the real pinned Qwen3 files in CI (every byte
  verified against the pinned SHA-256) and locally with a fixture model and a deliberately
  tampered file (rejected).
* Rate limiting uses transients (per site, per user/IP-hash, fixed window); a persistent object
  cache makes it cluster-wide, otherwise it is per database.
* `unfiltered_html` semantics: approved link insertions are written with the post-field kses
  filters temporarily removed **only** for the exact verified content computed from the approved
  diff, and restored in a `finally` block whatever happens; the inserted markup is
  `<a href="esc_url(target)">existing anchor text</a>`.
* The server-side self-test proves that a device's pipeline reproduces the pinned model; it cannot
  prove that a dishonest account with the embedding role computed every later vector the same way
  (it could submit precomputed reference vectors). Uploads are therefore limited to a dedicated
  least-privilege worker account or an administrator's browser session.
* An administrator can still give an agent the `sseo_apply` capability (opt-in); approvals remain
  human-only regardless.
