# AI agent integration guide (Agent Mode)

Agents use the **same service layer and rules as humans, with less power**: they can read,
analyse and propose; they can **never approve**. Installing the plugin connects no AI service and
exposes nothing to agents by default.

## 1. Set up a dedicated identity (administrator, once)

1. *Users → Add New*: create one non-administrator user per agent (e.g. `seo-agent-reader`).
2. *Semantic SEO → Agent Connections / Permissions*: **Create / update agent roles**, then assign:

| Role | Capabilities | Typical use |
| --- | --- | --- |
| SEO Agent (read-only) — `sseo_agent_reader` | `sseo_read` | reports, inventory, URL analysis |
| SEO Agent (read + analyze) — `sseo_agent_analyst` | + `sseo_analyze` | start bounded jobs, create change **previews** |
| SEO Agent (may execute human-approved changes) — `sseo_agent_operator` | + `sseo_apply` only if *Allow agent accounts … to execute changes that a human already approved* is ticked | apply a change a human approved in WP Admin |
| SEO Embedding Worker — `sseo_worker` | `sseo_embed` | private embedding worker |

3. On that user's profile → **Application Passwords**: create one, copy it once, give it only to
   that agent. Use HTTPS (WordPress only offers Application Passwords over HTTPS or on `local`
   environments).

Enforced for every transport (verified over real HTTP in `tests/e2e/http-agent.mjs`):

* agent roles and **any** Application-Password request — even an administrator's — never hold
  `sseo_review` or `sseo_manage`, and hold `sseo_apply` only when the administrator enabled it;
* there is **no route or ability that approves**; approvals exist only as a WP Admin form for a
  logged-in human with a nonce and an explicit confirmation tick;
* agents may run one job at a time; requests are rate-limited (`429` + `Retry-After`); bodies are
  capped at 512 KB (`413`); URLs outside the site are refused (no server-side fetching of
  agent-supplied URLs); agents see only their own audit entries.

## 2. REST API — `/wp-json/semantic-seo/v1`

Authentication: HTTP Basic with the Application Password. Every response is an envelope:

```json
{ "ok": true, "data": { … }, "meta": { "api_version": "1", "plugin_version": "1.0.0-beta.4", "generated_at": "…" } }
{ "ok": false, "error": { "code": "sseo_quota", "message": "…", "data": {} }, "meta": { … } }
```

(Permission failures before the route runs use WordPress core's `{code, message, data.status}`.)
Lists use `limit` (≤ 100) and an opaque `cursor` → `next_cursor`.

| Method & route | Capability | Purpose |
| --- | --- | --- |
| `GET /status` | read | version, read-only mode, tiers, index counts, link-scan coverage, findings summary, `inventory_limit` (Max documents vs stored and in-scope objects), agent interface (abilities_api, mcp_adapter) |
| `GET /health` | read | dashboard data for a person or an agent: health (ok/warning/error), database state (`ready`, tables present/expected, missing tables/columns, last SQL error with errno and SQLSTATE), counts (pages analyzed by language, issues by priority and kind, orphans, overlap, content quality, verified errors), active and failed jobs, last scan and last trial (with read-only evidence), comparison with the previous scan, measured estimate, Qwen state, allowed / never-done actions, system check rows |
| `GET /priorities` | read | the work list, most important first: `priority` 1–4, `category`, `problem`, `url`, `target_url`, `evidence`, `confidence`, `benefit`, `action`, `detail_url`; filters `locale`, `category` (technical, links, overlap, content, translation, structure), `priority`, `search`; `limit` ≤ 100, `offset` |
| `GET /scans` | read | history of completed scans (scope pilot/partial/site, documents, measured processing seconds, peak memory, read-only evidence) and the difference between the last two full scans |
| `GET /inventory` | read | SEO Matrix rows (`locale`, `type`, `status`, `intent`, `search`, `has_qa`, `has_risk`, `sort`, `order`) |
| `GET /analysis?url=…` (or `post_id`, `document_id`) | read | facts vs inferences, QA, link suggestions, overlap risks, related, provenance |
| `GET /suggestions/links` | read | ranked suggestions with anchor + byte offset + evidence; orphan candidates with scan coverage |
| `GET /cannibalization` | read | same-locale overlap hypotheses (never across translations), ordered by review tier (competition → judgement needed → vector-only → information) then score; filter `category`, `locale`, `group_key` |
| `GET /clusters`, `GET /classification` | read | Pillar–Hub–Silo tree, category suggestions |
| `GET /qa` | read | QA issues labelled `verified_error` / `warning` / `suggestion` |
| `GET /related?post_id=…` | read | precomputed related content |
| `GET /audit` | read | redacted log (agents: own entries only) |
| `GET /changes`, `GET /changes/proposals` | read | applied changes, proposals |
| `GET /jobs`, `GET /jobs/{id}` | read | job list / honest progress |
| `POST /jobs` | analyze | `{type: inventory|analyze|embed|verify_links, post_ids?: ≤200, idempotency_key?}` |
| `POST /jobs/{id}/(cancel|pause|resume|tick)` | analyze | job control (agents: own jobs only) |
| `POST /index/rebuild` | analyze | `{scope: lexical|embeddings, confirm: true}` — derived data only |
| `POST /changes/preview` | analyze | `{finding_ids: [1–10]}` → exact, hash-bound diff previews; writes nothing |
| `POST /changes/apply` | apply | `{proposal_id, approval_id, idempotency_key}` — only a **human-approved** proposal |
| `POST /changes/rollback` | apply | `{change_id}` — agents need a separate human-issued rollback approval |
| `GET /embeddings/profile`, `POST /embeddings/selftest|claim|complete|release` | embed | worker protocol: server-checked self-test first (`complete` answers 428 without it), then single-use leases |

### Examples

```bash
SITE=https://example.com; AUTH='seo-agent-reader:xxxx xxxx xxxx xxxx xxxx xxxx'
curl -su "$AUTH" "$SITE/wp-json/semantic-seo/v1/status"
curl -su "$AUTH" "$SITE/wp-json/semantic-seo/v1/inventory?locale=vi&limit=20&sort=risk&order=desc"
curl -su "$AUTH" "$SITE/wp-json/semantic-seo/v1/analysis?url=$SITE/thue-xe-may-ha-noi/"
```

## 3. Task recipes (which tier each needs)

| Request | How | Tier |
| --- | --- | --- |
| "Find 20 high-risk orphan candidates with evidence" | `GET /suggestions/links?limit=20` → `orphans` (each with `inbound_count`, `word_count` and the scan coverage that bounds the claim) | lexical only |
| "Review internal links for 50cc pages without changing H1/H2" | `GET /inventory?search=50cc` → `GET /suggestions/links?post_id=…`; previews never touch headings (anchors in headings/links/shortcodes/code are refused, `diff.unchanged` lists `headings`) → a human approves in WP Admin | lexical; semantic optional |
| "List bilingual translation mapping gaps" | `GET /cannibalization?category=translation_mapping_conflict` and `GET /inventory` (`translation_group` per row; rows without a group have no mapped translation) | lexical |
| "Export VI–EN Matrix" | humans: Matrix page → CSV/XLSX; agents: page through `GET /inventory` | lexical |
| "Analyze without activating a model" | `POST /jobs {type:"analyze"}` → poll `GET /jobs/{id}` | lexical (default) |
| Semantic similarity evidence | an administrator runs the browser model, or a private worker processes the queue | browser device / private worker |

## 4. WordPress Abilities API (WordPress 6.9+)

15 abilities are registered in category `semantic-seo` on WordPress 6.9+ (version-gated: on 6.5–6.8
nothing is registered and REST keeps working). Read-only: `get-site-status`, `list-urls`,
`get-url-analysis`, `get-link-suggestions`, `get-cannibalization-report`, `get-topic-clusters`,
`get-qa-issues`, `get-job-status`. Others: `get-audit-log`, `start-analysis`, `cancel-analysis`,
`rebuild-index`, `create-change-preview`, `apply-approved-change`, `rollback-change`. Each has
JSON Schemas for input and output and annotations (`readonly`, `destructive`, `idempotent`).
Names follow the README specification; where a brief says "stop analysis", the ability is
`cancel-analysis` (same behaviour: request cancellation of a job and report its status).

Exposure is **opt-in per ability** (Agent Connections page → *Expose via Abilities REST*). Example
(WordPress core routes):

```bash
curl -su "$AUTH" "$SITE/wp-json/wp-abilities/v1/abilities/semantic-seo/get-site-status/run"
curl -su "$AUTH" "$SITE/wp-json/wp-abilities/v1/abilities/semantic-seo/list-urls/run?input%5Blimit%5D=3"
```

## 5. MCP (official WordPress MCP Adapter, optional)

1. Install and activate the official [MCP Adapter](https://github.com/WordPress/mcp-adapter)
   (tested: 0.7.1, requires WordPress 6.9+).
2. Agent Connections page → tick the **read-only** abilities to expose and *Enable MCP exposure*.
   Write abilities can never be exposed through MCP (the setting is filtered server-side).
3. Connect a client to the adapter's default server: HTTP endpoint
   `/wp-json/mcp/mcp-adapter-default-server` with the agent's Application Password, or STDIO via
   `wp mcp-adapter serve --user=<agent account>`. Client configuration examples (Claude Desktop,
   `@automattic/mcp-wordpress-remote` proxy) are in the adapter's
   `docs/guides/cli-usage.md`. Use the dedicated agent account, not an administrator.

Verified with MCP Adapter 0.7.1 on WordPress 7.1.3 and 6.9.10: unauthenticated clients are
refused; with exposure off, `mcp-adapter-discover-abilities` lists no `semantic-seo/*` ability;
after opting in, exactly the selected read-only abilities are discoverable and executable through
`mcp-adapter-execute-ability`; an attempt to execute `semantic-seo/apply-approved-change` is
refused. **No real third-party MCP client (e.g. Claude Desktop, ChatGPT) was connected in this
build** — the tests speak JSON-RPC to the adapter directly, so an integration with ChatGPT is not
claimed as working. The step-by-step runbook for using the plugin with ChatGPT Work through the
admin interface (without giving it any write access) is in [ADMIN-GUIDE.md](ADMIN-GUIDE.md).

## 6. Change workflow for agents

```
analyst:  POST /changes/preview {finding_ids:[42]}      → proposal #7, diff_hash, base_hash (nothing written)
human:    WP Admin → Audit / Undo / Rollback → Proposals → review diff → tick confirmation → Approve
operator: POST /changes/apply {proposal_id:7, approval_id:3, idempotency_key:"agent-7-1"}
          → change #5, readback verified (or 409 if the content changed since preview: create a new preview)
```

Retrying `apply` with the same idempotency key returns the same change (no double write).
Rollback by an agent requires a separate human *Authorize an agent rollback* action.
