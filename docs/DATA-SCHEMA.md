# Data schema and migrations

All plugin data lives in 16 custom tables with the prefix `{$wpdb->prefix}sseo_` plus three
options. WordPress core tables are only **read** (posts, postmeta, terms, term relationships,
options for SEO plugins), except for the single write path of an approved change (post content
via `wp_update_post`, or a category assignment via `wp_set_post_terms`) and its rollback.

## Options

| Option | Autoload | Content |
| --- | --- | --- |
| `sseo_settings` | no | all settings (schema-validated; defaults are read-only) |
| `sseo_db_version` | yes | schema version (`2`; version 2 adds the `findings.target_id` index) |
| `sseo_migration_log` | no | last migration runs (time, from → to, steps, result) |
| `sseo_state` | no | inventory/analysis timestamps and summary counters |
| `sseo_similarity_baseline` | no | per language: median (and p10/p90/p99) cosine of sampled same-language page pairs, with the model key it was measured with; re-measured at every site-wide analysis |

Transients: `sseo_notice_<user>` (admin notices), `sseo_import_view_<user>` / import tokens
(CSV preview, short-lived), rate-limit buckets (`sseo_rl_*`). Mutex rows: `sseo_mutex_<name>`
options (created and deleted around critical sections).

## Tables

| Table | Columns (type) | Keys |
| --- | --- | --- |
| `{prefix}sseo_documents` | `id` bigint(20), `object_type` varchar(20), `object_id` bigint(20), `subtype` varchar(64), `url` text, `url_hash` char(40), `locale` varchar(20), `locale_source` varchar(32), `detected_lang` varchar(10), `status` varchar(20), `title` text, `canonical` text, `robots` varchar(100), `indexable` tinyint(1), `seo_source` varchar(32), `seo_title` text, `meta_description` text, `focus_keyword` varchar(255), `translation_group` varchar(64), `terms_json` longtext, `content_hash` char(64), `index_hash` char(64), `word_count` int(10), `doc_len` int(10), `intent` varchar(32), `intent_confidence` varchar(16), `editorial_intent` varchar(32), `cluster_id` bigint(20), `cluster_role` varchar(16), `cluster_locked` tinyint(1), `review_state` varchar(20), `excluded` tinyint(1), `inbound_count` int(10), `outbound_count` int(10), `signals` longtext, `published_at` datetime, `modified_at` datetime, `scanned_at` datetime, `indexed_at` datetime, `analyzed_at` datetime, `dirty` tinyint(1), `algorithm_version` varchar(20) | PRIMARY KEY (id)<br>UNIQUE KEY object (object_type,object_id)<br>KEY locale_status (locale,status)<br>KEY url_hash (url_hash)<br>KEY translation_group (translation_group)<br>KEY cluster_id (cluster_id)<br>KEY dirty (dirty) |
| `{prefix}sseo_chunks` | `id` bigint(20), `document_id` bigint(20), `chunk_index` int(10), `kind` varchar(16), `heading` text, `start_offset` int(10), `end_offset` int(10), `text` longtext, `content_hash` char(64), `token_estimate` int(10) | PRIMARY KEY (id)<br>KEY document (document_id,chunk_index) |
| `{prefix}sseo_postings` | `term` varbinary(96), `document_id` bigint(20), `tf` float | PRIMARY KEY (term,document_id)<br>KEY document_id (document_id) |
| `{prefix}sseo_links` | `id` bigint(20), `source_id` bigint(20), `target_id` bigint(20), `target_url` text, `target_hash` char(40), `anchor` text, `start_offset` int(10), `length` int(10), `fingerprint` char(40), `rel` varchar(64), `is_internal` tinyint(1), `resolution` varchar(20), `http_status` smallint(5), `scanned_at` datetime | PRIMARY KEY (id)<br>KEY source_id (source_id)<br>KEY target_id (target_id)<br>KEY target_hash (target_hash) |
| `{prefix}sseo_embeddings` | `id` bigint(20), `document_id` bigint(20), `chunk_id` bigint(20), `model_key` char(40), `model_id` varchar(191), `revision` varchar(64), `dtype` varchar(16), `dimension` smallint(5), `preprocess_version` varchar(16), `locale` varchar(20), `content_hash` char(64), `vector` longblob, `norm` float, `source` varchar(16), `created_at` datetime | PRIMARY KEY (id)<br>UNIQUE KEY item (document_id,chunk_id,model_key)<br>KEY model_key (model_key) |
| `{prefix}sseo_queue` | `id` bigint(20), `job_id` bigint(20), `document_id` bigint(20), `chunk_id` bigint(20), `model_key` char(40), `content_hash` char(64), `status` varchar(16), `lease_hash` char(64), `lease_owner` bigint(20), `lease_expires` datetime, `attempts` smallint(5), `last_error` varchar(255), `updated_at` datetime | PRIMARY KEY (id)<br>UNIQUE KEY item (document_id,chunk_id,model_key)<br>KEY job_status (job_id,status)<br>KEY status_lease (status,lease_expires) |
| `{prefix}sseo_findings` | `id` bigint(20), `type` varchar(32), `check_id` varchar(64), `document_id` bigint(20), `target_id` bigint(20), `group_key` varchar(64), `fingerprint` char(40), `locale` varchar(20), `label` varchar(24), `severity` varchar(12), `confidence` varchar(16), `score` float, `evidence` longtext, `status` varchar(16), `algorithm_version` varchar(20), `run_id` bigint(20), `created_at` datetime, `updated_at` datetime | PRIMARY KEY (id)<br>UNIQUE KEY fingerprint (fingerprint)<br>KEY type_status (type,status)<br>KEY document_id (document_id)<br>KEY target_id (target_id)<br>KEY group_key (group_key) |
| `{prefix}sseo_related` | `document_id` bigint(20), `related_id` bigint(20), `rank_pos` smallint(5), `score` float, `evidence` text, `updated_at` datetime | PRIMARY KEY (document_id,related_id)<br>KEY document_rank (document_id,rank_pos) |
| `{prefix}sseo_clusters` | `id` bigint(20), `locale` varchar(20), `parent_id` bigint(20), `label` varchar(255), `source` varchar(16), `taxonomy` varchar(64), `term_id` bigint(20), `role` varchar(16), `pillar_document_id` bigint(20), `recommended_pillar_id` bigint(20), `member_count` int(10), `review_state` varchar(20), `locked` tinyint(1), `version` int(10), `evidence` longtext, `updated_at` datetime | PRIMARY KEY (id)<br>KEY locale_parent (locale,parent_id)<br>KEY term (taxonomy,term_id) |
| `{prefix}sseo_jobs` | `id` bigint(20), `type` varchar(32), `status` varchar(16), `owner_id` bigint(20), `via` varchar(24), `idempotency_key` char(64), `params` longtext, `state` longtext, `progress_done` int(10), `progress_total` int(10), `attempts` smallint(5), `max_attempts` smallint(5), `lease_owner` varchar(64), `lease_expires` datetime, `heartbeat_at` datetime, `next_run_at` datetime, `last_error` text, `created_at` datetime, `started_at` datetime, `finished_at` datetime, `updated_at` datetime | PRIMARY KEY (id)<br>UNIQUE KEY idempotency_key (idempotency_key)<br>KEY status_type (status,type) |
| `{prefix}sseo_proposals` | `id` bigint(20), `type` varchar(32), `finding_id` bigint(20), `object_type` varchar(20), `object_id` bigint(20), `payload` longtext, `diff` longtext, `diff_hash` char(64), `base_hash` char(64), `expected_hash` char(64), `status` varchar(16), `created_by` bigint(20), `created_via` varchar(24), `rationale` text, `created_at` datetime, `updated_at` datetime | PRIMARY KEY (id)<br>KEY status (status)<br>KEY object (object_type,object_id) |
| `{prefix}sseo_approvals` | `id` bigint(20), `proposal_id` bigint(20), `change_id` bigint(20), `action` varchar(16), `approver_id` bigint(20), `approver_via` varchar(24), `scope` longtext, `diff_hash` char(64), `action_category` varchar(32), `status` varchar(16), `expires_at` datetime, `created_at` datetime, `consumed_at` datetime, `consumed_by` bigint(20), `note` text | PRIMARY KEY (id)<br>KEY proposal_id (proposal_id)<br>KEY change_id (change_id)<br>KEY status (status) |
| `{prefix}sseo_changes` | `id` bigint(20), `proposal_id` bigint(20), `approval_id` bigint(20), `object_type` varchar(20), `object_id` bigint(20), `field` varchar(64), `before_value` longtext, `after_value` longtext, `before_hash` char(64), `after_hash` char(64), `actor_id` bigint(20), `actor_via` varchar(24), `idempotency_key` char(64), `status` varchar(20), `verify` longtext, `error` text, `applied_at` datetime, `rolled_back_at` datetime, `rollback_by` bigint(20) | PRIMARY KEY (id)<br>UNIQUE KEY idempotency_key (idempotency_key)<br>KEY proposal_id (proposal_id)<br>KEY object (object_type,object_id) |
| `{prefix}sseo_audit` | `id` bigint(20), `created_at` datetime, `user_id` bigint(20), `via` varchar(24), `action` varchar(64), `object_type` varchar(20), `object_id` bigint(20), `result` varchar(16), `ip_hash` char(16), `details` longtext | PRIMARY KEY (id)<br>KEY created_at (created_at)<br>KEY action (action)<br>KEY user_id (user_id) |
| `{prefix}sseo_locks` | `id` bigint(20), `scope_type` varchar(20), `scope_value` varchar(191), `lock_type` varchar(16), `reason` text, `created_by` bigint(20), `created_at` datetime | PRIMARY KEY (id)<br>UNIQUE KEY scope (scope_type,scope_value,lock_type) |
| `{prefix}sseo_gsc` | `id` bigint(20), `import_id` varchar(40), `page_url` text, `page_hash` char(40), `document_id` bigint(20), `query` varchar(255), `clicks` int(10), `impressions` int(10), `ctr` float, `position` float, `period_start` date, `period_end` date, `source` varchar(64), `imported_at` datetime, `imported_by` bigint(20) | PRIMARY KEY (id)<br>KEY page_hash (page_hash)<br>KEY document_id (document_id)<br>KEY import_id (import_id) |

Notes:

* `postings.term` is `varbinary` so MySQL collations never merge Vietnamese accents (`thuê` ≠ `thue`;
  the accent-folded form is indexed separately with a `~` prefix and a lower weight).
* `embeddings.vector` stores packed little-endian float32 values; every row carries `model_key`
  (sha1 of model ID, revision, dtype, dimension, preprocessing version and pooling). Vectors of a
  different profile are never mixed; changing precision/dimension makes old vectors ineligible.
* `queue.lease_hash` stores only SHA-256 of the single-use lease token.
* `changes` keep the full *before* and *after* snapshots needed for verified rollback, plus the
  idempotency key hash (unique).
* `audit.details` is redacted JSON (secret-named keys, Application-Password-shaped strings and
  bearer tokens removed); `ip_hash` is a salted HMAC prefix.

## Migrations

`Database\Migrator::migrate()` runs on activation and, when `sseo_db_version` is behind
`Schema::VERSION`, on the first request after an update. It takes the `sseo_mutex_migration`
lock (stale after 300 s), runs `dbDelta()` for every table, then explicit idempotent steps
(step 1: backfill `documents.url_hash`; version 2 only adds an index, created by `dbDelta()`), advances the version **only on success**, and appends to
`sseo_migration_log`. A failed migration leaves the previous version in place and is reported in
*Settings & Diagnostics*. Verified by `ActivationMigrationTest` (activation read-only for WordPress
data, repeatable migration preserving data, upgrade from an older schema restoring dropped columns
without data loss, upgrade 1 → 2 adding the `findings.target_id` index, interrupted upgrade resumed after a stale lock, exclusive mutex).

## Uninstall

`uninstall.php` keeps all data by default. With the *Delete all plugin data* policy (typed
`DELETE` confirmation) it drops the 16 tables, deletes the plugin's options, transients, mutex rows
and user meta, and removes the agent roles. WordPress posts are never deleted.
