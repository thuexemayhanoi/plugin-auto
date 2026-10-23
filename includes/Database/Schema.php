<?php
/**
 * Versioned custom-table schema.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and dbDelta definitions.
 */
final class Schema {

	/**
	 * Bump when the schema changes; add a matching step in Migrator::steps().
	 */
	const VERSION = 1;

	const TABLES = array(
		'documents',
		'chunks',
		'postings',
		'links',
		'embeddings',
		'queue',
		'findings',
		'related',
		'clusters',
		'jobs',
		'proposals',
		'approvals',
		'changes',
		'audit',
		'locks',
		'gsc',
	);

	/**
	 * Fully qualified table name.
	 *
	 * @param string $name Logical name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sseo_' . $name;
	}

	/**
	 * The dbDelta() statements for every table.
	 *
	 * @return string[]
	 */
	public static function definitions() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$t = array();
		foreach ( self::TABLES as $name ) {
			$t[ $name ] = self::table( $name );
		}

		return array(
			"CREATE TABLE {$t['documents']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
subtype varchar(64) NOT NULL DEFAULT '',
url text NOT NULL,
url_hash char(40) NOT NULL DEFAULT '',
locale varchar(20) NOT NULL DEFAULT '',
locale_source varchar(32) NOT NULL DEFAULT '',
detected_lang varchar(10) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT '',
title text NOT NULL,
canonical text NULL,
robots varchar(100) NOT NULL DEFAULT '',
indexable tinyint(1) NOT NULL DEFAULT 1,
seo_source varchar(32) NOT NULL DEFAULT '',
seo_title text NULL,
meta_description text NULL,
focus_keyword varchar(255) NOT NULL DEFAULT '',
translation_group varchar(64) NOT NULL DEFAULT '',
terms_json longtext NULL,
content_hash char(64) NOT NULL DEFAULT '',
index_hash char(64) NOT NULL DEFAULT '',
word_count int(10) unsigned NOT NULL DEFAULT 0,
doc_len int(10) unsigned NOT NULL DEFAULT 0,
intent varchar(32) NOT NULL DEFAULT '',
intent_confidence varchar(16) NOT NULL DEFAULT '',
editorial_intent varchar(32) NOT NULL DEFAULT '',
cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
cluster_role varchar(16) NOT NULL DEFAULT '',
cluster_locked tinyint(1) NOT NULL DEFAULT 0,
review_state varchar(20) NOT NULL DEFAULT 'unreviewed',
excluded tinyint(1) NOT NULL DEFAULT 0,
inbound_count int(10) unsigned NOT NULL DEFAULT 0,
outbound_count int(10) unsigned NOT NULL DEFAULT 0,
signals longtext NULL,
published_at datetime NULL,
modified_at datetime NULL,
scanned_at datetime NULL,
indexed_at datetime NULL,
analyzed_at datetime NULL,
dirty tinyint(1) NOT NULL DEFAULT 1,
algorithm_version varchar(20) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY object (object_type,object_id),
KEY locale_status (locale,status),
KEY url_hash (url_hash),
KEY translation_group (translation_group),
KEY cluster_id (cluster_id),
KEY dirty (dirty)
) $c;",
			"CREATE TABLE {$t['chunks']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
document_id bigint(20) unsigned NOT NULL,
chunk_index int(10) unsigned NOT NULL DEFAULT 0,
kind varchar(16) NOT NULL DEFAULT 'section',
heading text NULL,
start_offset int(10) unsigned NOT NULL DEFAULT 0,
end_offset int(10) unsigned NOT NULL DEFAULT 0,
text longtext NOT NULL,
content_hash char(64) NOT NULL DEFAULT '',
token_estimate int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY document (document_id,chunk_index)
) $c;",
			"CREATE TABLE {$t['postings']} (
term varbinary(96) NOT NULL,
document_id bigint(20) unsigned NOT NULL,
tf float NOT NULL DEFAULT 0,
PRIMARY KEY  (term,document_id),
KEY document_id (document_id)
) $c;",
			"CREATE TABLE {$t['links']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source_id bigint(20) unsigned NOT NULL,
target_id bigint(20) unsigned NOT NULL DEFAULT 0,
target_url text NOT NULL,
target_hash char(40) NOT NULL DEFAULT '',
anchor text NULL,
start_offset int(10) unsigned NOT NULL DEFAULT 0,
length int(10) unsigned NOT NULL DEFAULT 0,
fingerprint char(40) NOT NULL DEFAULT '',
rel varchar(64) NOT NULL DEFAULT '',
is_internal tinyint(1) NOT NULL DEFAULT 0,
resolution varchar(20) NOT NULL DEFAULT 'unresolved',
http_status smallint(5) unsigned NOT NULL DEFAULT 0,
scanned_at datetime NULL,
PRIMARY KEY  (id),
KEY source_id (source_id),
KEY target_id (target_id),
KEY target_hash (target_hash)
) $c;",
			"CREATE TABLE {$t['embeddings']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
document_id bigint(20) unsigned NOT NULL,
chunk_id bigint(20) unsigned NOT NULL DEFAULT 0,
model_key char(40) NOT NULL,
model_id varchar(191) NOT NULL,
revision varchar(64) NOT NULL,
dtype varchar(16) NOT NULL,
dimension smallint(5) unsigned NOT NULL,
preprocess_version varchar(16) NOT NULL,
locale varchar(20) NOT NULL DEFAULT '',
content_hash char(64) NOT NULL,
vector longblob NOT NULL,
norm float NOT NULL DEFAULT 0,
source varchar(16) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY item (document_id,chunk_id,model_key),
KEY model_key (model_key)
) $c;",
			"CREATE TABLE {$t['queue']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id bigint(20) unsigned NOT NULL DEFAULT 0,
document_id bigint(20) unsigned NOT NULL,
chunk_id bigint(20) unsigned NOT NULL DEFAULT 0,
model_key char(40) NOT NULL,
content_hash char(64) NOT NULL,
status varchar(16) NOT NULL DEFAULT 'pending',
lease_hash char(64) NOT NULL DEFAULT '',
lease_owner bigint(20) unsigned NOT NULL DEFAULT 0,
lease_expires datetime NULL,
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
last_error varchar(255) NOT NULL DEFAULT '',
updated_at datetime NULL,
PRIMARY KEY  (id),
UNIQUE KEY item (document_id,chunk_id,model_key),
KEY job_status (job_id,status),
KEY status_lease (status,lease_expires)
) $c;",
			"CREATE TABLE {$t['findings']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
type varchar(32) NOT NULL,
check_id varchar(64) NOT NULL DEFAULT '',
document_id bigint(20) unsigned NOT NULL DEFAULT 0,
target_id bigint(20) unsigned NOT NULL DEFAULT 0,
group_key varchar(64) NOT NULL DEFAULT '',
fingerprint char(40) NOT NULL,
locale varchar(20) NOT NULL DEFAULT '',
label varchar(24) NOT NULL DEFAULT '',
severity varchar(12) NOT NULL DEFAULT '',
confidence varchar(16) NOT NULL DEFAULT '',
score float NOT NULL DEFAULT 0,
evidence longtext NULL,
status varchar(16) NOT NULL DEFAULT 'open',
algorithm_version varchar(20) NOT NULL DEFAULT '',
run_id bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY fingerprint (fingerprint),
KEY type_status (type,status),
KEY document_id (document_id),
KEY group_key (group_key)
) $c;",
			"CREATE TABLE {$t['related']} (
document_id bigint(20) unsigned NOT NULL,
related_id bigint(20) unsigned NOT NULL,
rank_pos smallint(5) unsigned NOT NULL DEFAULT 0,
score float NOT NULL DEFAULT 0,
evidence text NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (document_id,related_id),
KEY document_rank (document_id,rank_pos)
) $c;",
			"CREATE TABLE {$t['clusters']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
locale varchar(20) NOT NULL DEFAULT '',
parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
label varchar(255) NOT NULL DEFAULT '',
source varchar(16) NOT NULL DEFAULT 'taxonomy',
taxonomy varchar(64) NOT NULL DEFAULT '',
term_id bigint(20) unsigned NOT NULL DEFAULT 0,
role varchar(16) NOT NULL DEFAULT 'hub',
pillar_document_id bigint(20) unsigned NOT NULL DEFAULT 0,
recommended_pillar_id bigint(20) unsigned NOT NULL DEFAULT 0,
member_count int(10) unsigned NOT NULL DEFAULT 0,
review_state varchar(20) NOT NULL DEFAULT 'unreviewed',
locked tinyint(1) NOT NULL DEFAULT 0,
version int(10) unsigned NOT NULL DEFAULT 1,
evidence longtext NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY locale_parent (locale,parent_id),
KEY term (taxonomy,term_id)
) $c;",
			"CREATE TABLE {$t['jobs']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
type varchar(32) NOT NULL,
status varchar(16) NOT NULL DEFAULT 'queued',
owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
via varchar(24) NOT NULL DEFAULT '',
idempotency_key char(64) NULL,
params longtext NULL,
state longtext NULL,
progress_done int(10) unsigned NOT NULL DEFAULT 0,
progress_total int(10) unsigned NOT NULL DEFAULT 0,
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
lease_owner varchar(64) NOT NULL DEFAULT '',
lease_expires datetime NULL,
heartbeat_at datetime NULL,
next_run_at datetime NULL,
last_error text NULL,
created_at datetime NOT NULL,
started_at datetime NULL,
finished_at datetime NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY idempotency_key (idempotency_key),
KEY status_type (status,type)
) $c;",
			"CREATE TABLE {$t['proposals']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
type varchar(32) NOT NULL,
finding_id bigint(20) unsigned NOT NULL DEFAULT 0,
object_type varchar(20) NOT NULL DEFAULT 'post',
object_id bigint(20) unsigned NOT NULL,
payload longtext NULL,
diff longtext NULL,
diff_hash char(64) NOT NULL,
base_hash char(64) NOT NULL,
expected_hash char(64) NOT NULL DEFAULT '',
status varchar(16) NOT NULL DEFAULT 'previewed',
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_via varchar(24) NOT NULL DEFAULT '',
rationale text NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY status (status),
KEY object (object_type,object_id)
) $c;",
			"CREATE TABLE {$t['approvals']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
proposal_id bigint(20) unsigned NOT NULL DEFAULT 0,
change_id bigint(20) unsigned NOT NULL DEFAULT 0,
action varchar(16) NOT NULL DEFAULT 'apply',
approver_id bigint(20) unsigned NOT NULL,
approver_via varchar(24) NOT NULL DEFAULT '',
scope longtext NULL,
diff_hash char(64) NOT NULL,
action_category varchar(32) NOT NULL DEFAULT '',
status varchar(16) NOT NULL DEFAULT 'active',
expires_at datetime NOT NULL,
created_at datetime NOT NULL,
consumed_at datetime NULL,
consumed_by bigint(20) unsigned NOT NULL DEFAULT 0,
note text NULL,
PRIMARY KEY  (id),
KEY proposal_id (proposal_id),
KEY change_id (change_id),
KEY status (status)
) $c;",
			"CREATE TABLE {$t['changes']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
proposal_id bigint(20) unsigned NOT NULL DEFAULT 0,
approval_id bigint(20) unsigned NOT NULL DEFAULT 0,
object_type varchar(20) NOT NULL DEFAULT 'post',
object_id bigint(20) unsigned NOT NULL,
field varchar(64) NOT NULL,
before_value longtext NULL,
after_value longtext NULL,
before_hash char(64) NOT NULL DEFAULT '',
after_hash char(64) NOT NULL DEFAULT '',
actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
actor_via varchar(24) NOT NULL DEFAULT '',
idempotency_key char(64) NOT NULL,
status varchar(20) NOT NULL DEFAULT 'applying',
verify longtext NULL,
error text NULL,
applied_at datetime NULL,
rolled_back_at datetime NULL,
rollback_by bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY idempotency_key (idempotency_key),
KEY proposal_id (proposal_id),
KEY object (object_type,object_id)
) $c;",
			"CREATE TABLE {$t['audit']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
via varchar(24) NOT NULL DEFAULT '',
action varchar(64) NOT NULL,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
result varchar(16) NOT NULL DEFAULT 'ok',
ip_hash char(16) NOT NULL DEFAULT '',
details longtext NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY action (action),
KEY user_id (user_id)
) $c;",
			"CREATE TABLE {$t['locks']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
scope_type varchar(20) NOT NULL,
scope_value varchar(191) NOT NULL,
lock_type varchar(16) NOT NULL DEFAULT 'lock',
reason text NULL,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY scope (scope_type,scope_value,lock_type)
) $c;",
			"CREATE TABLE {$t['gsc']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
import_id varchar(40) NOT NULL,
page_url text NOT NULL,
page_hash char(40) NOT NULL,
document_id bigint(20) unsigned NOT NULL DEFAULT 0,
query varchar(255) NOT NULL DEFAULT '',
clicks int(10) unsigned NOT NULL DEFAULT 0,
impressions int(10) unsigned NOT NULL DEFAULT 0,
ctr float NOT NULL DEFAULT 0,
position float NOT NULL DEFAULT 0,
period_start date NULL,
period_end date NULL,
source varchar(64) NOT NULL DEFAULT '',
imported_at datetime NOT NULL,
imported_by bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY page_hash (page_hash),
KEY document_id (document_id),
KEY import_id (import_id)
) $c;",
		);
	}
}
