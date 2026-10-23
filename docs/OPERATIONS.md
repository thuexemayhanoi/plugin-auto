# Operations and troubleshooting

## Daily operation

* Content edits mark documents dirty; after the first inventory an **incremental** job is queued
  automatically (Settings → *Re-index changed posts automatically*). Only changed documents are
  re-indexed and re-analysed.
* Jobs run in WP-Cron ticks bounded by *batch size* and *time budget*. Progress, phase and errors
  are on **Jobs & Inference Device** and in `GET /jobs/{id}`.
* A daily maintenance event prunes the audit log (default retention 180 days).

## WP-CLI

```bash
wp sseo status --user=admin                       # JSON status (same as GET /status)
wp sseo start inventory --then-analyze --run --user=admin
wp sseo start analyze --post_ids=12,34 --run --user=admin
wp sseo run --user=admin                          # run every runnable job to completion
wp sseo jobs [<id>] --user=admin
wp sseo cancel <id> --user=admin
wp sseo report links|cannibalization|qa|clusters [--locale=vi] [--limit=50] --user=admin
wp sseo export --file=matrix.csv|matrix.xlsx --user=admin
wp sseo migrate --user=admin
wp sseo audit --user=admin
```

Embedding jobs are skipped by `wp sseo run` (they wait for a browser or private worker).

## Troubleshooting

| Symptom | Likely cause | What to do |
| --- | --- | --- |
| Job stays *queued* | WP-Cron not running (low traffic, `DISABLE_WP_CRON`, loopback blocked) | keep the Jobs page open (it advances jobs), click *Advance now*, add a real cron hitting `wp-cron.php`, or run `wp sseo run` |
| Job *failed* | a tick failed 5 times in a row (exponential backoff 30 s → 8 min between attempts) | Jobs page shows `last_error` with file:line; object errors are listed in the job summary; start a new job after fixing (failed jobs are kept for the record) |
| "A job of this type is already queued or running" (409) | one active job per type by design | wait, pause/cancel the active one, or reuse it |
| Agent gets 429 | rate limit (Settings → Operations) | honour `Retry-After`; agents may also run only one job at a time |
| Agent gets 403 on apply | Application-Password sessions never approve; apply needs the operator role **and** the *agents may execute approved changes* setting **and** a human approval | approve in WP Admin; enable the setting only if wanted |
| Apply returns 409 `sseo_stale` / "content changed" | the post was edited after the preview/approval | create a new preview and approve again |
| Rollback says *manual recovery needed* | the post changed after apply and the inserted link occurs 0 or several times | edit manually; the before/after snapshot is in *Audit / Undo / Rollback → Applied changes* |
| Translations not detected | no Polylang/WPML, or URL prefix rules missing | install/configure the language plugin, or set *URL prefix rules* (e.g. `en=/en/`) and re-run inventory |
| Orphan list contains pages that are in menus | menus, widgets and templates are not scanned (stated with every orphan list) | treat orphans as candidates; check navigation manually |
| "Unsupported on this device" | no WebAssembly SIMD / module workers / WebGPU | use desktop Chrome/Edge, or the private worker; lexical analysis is unaffected |
| Model download fails with *Integrity check failed* | a file differs from the pinned SHA-256 (corrupted download, proxy rewriting, or a changed upstream file) | retry; if it persists, do not bypass — report it; vectors are never uploaded without a passing self-test |
| Self-test fails | the runtime produced different numbers than the ones measured for this precision (broken GPU driver, unsupported browser build) | do not bypass it; try another browser, WASM instead of WebGPU, or the private worker; vectors are never uploaded without a passing self-test |
| Application Passwords missing on user profiles | site not on HTTPS (WordPress rule) | enable HTTPS; local test sites can set `WP_ENVIRONMENT_TYPE` to `local` |
| MCP client cannot see tools | MCP Adapter not active, exposure off, or no read-only ability selected | Agent Connections page shows adapter status; select abilities and enable MCP exposure |
| Admin screens in Vietnamese but you want English | default plugin UI language is Vietnamese | Settings → *Plugin admin language* → English or *Follow WordPress profile language* |

## Diagnostics

*Settings & Diagnostics → Diagnostics JSON* (copy button) contains versions, active
language/SEO frameworks, table health, schema version, migration log, cron status, job counts and
settings (no secrets). Attach it to bug reports.

## Recovery

* Derived data (index, findings, clusters, related, embeddings) can be rebuilt any time:
  Jobs → *Inventory + analysis*, or `POST /index/rebuild {"scope":"lexical","confirm":true}`.
  This never writes posts.
* Every applied change can be rolled back from *Audit / Undo / Rollback* while its snapshot exists.
* Deactivation stops cron events and keeps data; reactivation resumes.
