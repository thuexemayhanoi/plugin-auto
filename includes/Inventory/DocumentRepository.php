<?php
/**
 * Persistence for inventory documents and their chunks.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Inventory;

use SemanticSEO\Database\Db;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Document rows (one per WordPress object).
 */
final class DocumentRepository {

	const UPDATABLE = array( 'subtype', 'url', 'url_hash', 'locale', 'locale_source', 'detected_lang', 'status', 'title', 'canonical', 'robots', 'indexable', 'seo_source', 'seo_title', 'meta_description', 'focus_keyword', 'translation_group', 'terms_json', 'content_hash', 'index_hash', 'word_count', 'doc_len', 'intent', 'intent_confidence', 'editorial_intent', 'cluster_id', 'cluster_role', 'cluster_locked', 'review_state', 'excluded', 'inbound_count', 'outbound_count', 'signals', 'published_at', 'modified_at', 'scanned_at', 'indexed_at', 'analyzed_at', 'dirty', 'algorithm_version' );

	/**
	 * Inserts or updates by (object_type, object_id).
	 *
	 * @param array $data Row data (object_type and object_id required).
	 * @return int Document ID.
	 */
	public static function upsert( array $data ) {
		$existing = self::by_object( $data['object_type'], (int) $data['object_id'] );
		$clean    = array_intersect_key( $data, array_flip( self::UPDATABLE ) );
		if ( null !== $existing ) {
			if ( array() !== $clean ) {
				Db::update( 'documents', $clean, array( 'id' => (int) $existing['id'] ) );
			}
			return (int) $existing['id'];
		}
		$clean['object_type'] = (string) $data['object_type'];
		$clean['object_id']   = (int) $data['object_id'];
		if ( ! isset( $clean['title'] ) ) {
			$clean['title'] = '';
		}
		if ( ! isset( $clean['url'] ) ) {
			$clean['url'] = '';
		}
		return Db::insert( 'documents', $clean );
	}

	/**
	 * Updates columns of a document.
	 *
	 * @param int   $id   Document ID.
	 * @param array $data Data.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		$clean = array_intersect_key( $data, array_flip( self::UPDATABLE ) );
		if ( array() !== $clean ) {
			Db::update( 'documents', $clean, array( 'id' => (int) $id ) );
		}
	}

	/**
	 * Fetches by document ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return Db::row( 'SELECT * FROM {documents} WHERE id = %d', array( (int) $id ) );
	}

	/**
	 * Fetches by WordPress object.
	 *
	 * @param string $type post|term.
	 * @param int    $id   Object ID.
	 * @return array|null
	 */
	public static function by_object( $type, $id ) {
		return Db::row( 'SELECT * FROM {documents} WHERE object_type = %s AND object_id = %d', array( (string) $type, (int) $id ) );
	}

	/**
	 * Fetches by URL hash (exact URL match).
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public static function by_url( $url ) {
		return Db::row( 'SELECT * FROM {documents} WHERE url_hash = %s ORDER BY (status = %s) DESC, id ASC LIMIT 1', array( self::url_hash( $url ), 'publish' ) );
	}

	/**
	 * Hash of the canonical comparison form of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_hash( $url ) {
		return sha1( \SemanticSEO\Graph\LinkGraph::canonical_form( (string) $url ) );
	}

	/**
	 * Several documents by ID.
	 *
	 * @param int[] $ids IDs.
	 * @return array<int,array> Keyed by ID.
	 */
	public static function many( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			foreach ( Db::rows( 'SELECT * FROM {documents} WHERE id IN (' . Db::in( $chunk ) . ')', $chunk ) as $row ) {
				$out[ (int) $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * IDs of analyzable documents (published, not deleted), optionally per locale.
	 *
	 * @param string $locale Locale or ''.
	 * @param int    $after  Cursor (ID greater than).
	 * @param int    $limit  Limit.
	 * @return int[]
	 */
	public static function analyzable_ids( $locale = '', $after = 0, $limit = 100000 ) {
		if ( '' !== $locale ) {
			return array_map( 'intval', Db::col( "SELECT id FROM {documents} WHERE status = 'publish' AND id > %d AND locale = %s ORDER BY id ASC LIMIT %d", array( (int) $after, (string) $locale, (int) $limit ) ) );
		}
		return array_map( 'intval', Db::col( "SELECT id FROM {documents} WHERE status = 'publish' AND id > %d ORDER BY id ASC LIMIT %d", array( (int) $after, (int) $limit ) ) );
	}

	/**
	 * Counts by status/locale for status reports.
	 *
	 * @return array
	 */
	public static function counts() {
		$out = array(
			'total'     => (int) Db::value( 'SELECT COUNT(*) FROM {documents}' ),
			'published' => (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish'" ),
			'by_locale' => array(),
			'by_type'   => array(),
			'dirty'     => (int) Db::value( 'SELECT COUNT(*) FROM {documents} WHERE dirty = 1' ),
			'excluded'  => (int) Db::value( 'SELECT COUNT(*) FROM {documents} WHERE excluded = 1' ),
		);
		foreach ( Db::rows( 'SELECT locale, COUNT(*) AS n FROM {documents} WHERE status = %s GROUP BY locale', array( 'publish' ) ) as $r ) {
			$out['by_locale'][ $r['locale'] ] = (int) $r['n'];
		}
		foreach ( Db::rows( 'SELECT subtype, COUNT(*) AS n FROM {documents} GROUP BY subtype' ) as $r ) {
			$out['by_type'][ $r['subtype'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Marks a document as needing re-indexing (cheap; used by save hooks).
	 *
	 * @param string $type post|term.
	 * @param int    $id   Object ID.
	 * @return void
	 */
	public static function mark_dirty( $type, $id ) {
		Db::query( 'UPDATE {documents} SET dirty = 1 WHERE object_type = %s AND object_id = %d', array( (string) $type, (int) $id ) );
	}

	/**
	 * Marks every document dirty (permalink or language map change).
	 *
	 * @return void
	 */
	public static function mark_all_dirty() {
		Db::query( 'UPDATE {documents} SET dirty = 1' );
	}

	/**
	 * Decoded signals of a document row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function signals( array $row ) {
		return Util::json_array( isset( $row['signals'] ) ? $row['signals'] : '' );
	}

	/**
	 * Replaces the chunks of a document.
	 *
	 * @param int   $doc_id Document ID.
	 * @param array $chunks Chunks from ContentExtractor.
	 * @return void
	 */
	public static function replace_chunks( $doc_id, array $chunks ) {
		Db::delete( 'chunks', array( 'document_id' => (int) $doc_id ) );
		foreach ( array_values( $chunks ) as $i => $c ) {
			Db::insert(
				'chunks',
				array(
					'document_id'    => (int) $doc_id,
					'chunk_index'    => $i,
					'kind'           => (string) $c['kind'],
					'heading'        => (string) $c['heading'],
					'start_offset'   => (int) $c['start'],
					'end_offset'     => (int) $c['end'],
					'text'           => (string) $c['text'],
					'content_hash'   => Util::sha256( $c['text'] ),
					'token_estimate' => (int) ceil( mb_strlen( (string) $c['text'], 'UTF-8' ) / 3 ),
				)
			);
		}
	}

	/**
	 * Chunks of a document.
	 *
	 * @param int $doc_id Document ID.
	 * @return array
	 */
	public static function chunks( $doc_id ) {
		return Db::rows( 'SELECT * FROM {chunks} WHERE document_id = %d ORDER BY chunk_index ASC', array( (int) $doc_id ) );
	}

	/**
	 * Removes a document and its derived data (object deleted in WordPress).
	 *
	 * @param int $doc_id Document ID.
	 * @return void
	 */
	public static function purge( $doc_id ) {
		$doc_id = (int) $doc_id;
		foreach ( array( 'chunks', 'embeddings', 'queue' ) as $t ) {
			Db::delete( $t, array( 'document_id' => $doc_id ) );
		}
		Db::delete( 'postings', array( 'document_id' => $doc_id ) );
		LexicalIndex::flush_cache();
		Db::delete( 'links', array( 'source_id' => $doc_id ) );
		Db::query( 'UPDATE {links} SET target_id = 0, resolution = %s WHERE target_id = %d', array( 'unresolved', $doc_id ) );
		Db::delete( 'related', array( 'document_id' => $doc_id ) );
		Db::delete( 'related', array( 'related_id' => $doc_id ) );
		Db::query( "UPDATE {findings} SET status = 'resolved' WHERE (document_id = %d OR target_id = %d) AND status = 'open'", array( $doc_id, $doc_id ) );
		Db::update(
			'documents',
			array(
				'status' => 'deleted',
				'dirty'  => 0,
			),
			array( 'id' => $doc_id )
		);
	}
}
