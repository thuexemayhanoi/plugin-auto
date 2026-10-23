<?php
/**
 * Stores embeddings together with full model provenance.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

use SemanticSEO\Database\Db;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Vectors are only comparable when their model_key matches; a profile change makes old vectors
 * ineligible without deleting them (controlled reindexing). The chunk_id column stores the stable
 * chunk_index of the document, and the content hash must still match the current chunk text.
 */
final class EmbeddingRepository {

	/**
	 * Saves (replaces) one vector.
	 *
	 * @param array   $item    {document_id, chunk_id, content_hash, locale}.
	 * @param float[] $vector  Normalized vector.
	 * @param array   $profile Active profile.
	 * @param string  $source  browser|worker.
	 * @return bool
	 */
	public static function save( array $item, array $vector, array $profile, $source ) {
		Db::query(
			'DELETE FROM {embeddings} WHERE document_id = %d AND chunk_id = %d AND model_key = %s',
			array( (int) $item['document_id'], (int) $item['chunk_id'], $profile['model_key'] )
		);
		return Db::insert(
			'embeddings',
			array(
				'document_id'        => (int) $item['document_id'],
				'chunk_id'           => (int) $item['chunk_id'],
				'model_key'          => $profile['model_key'],
				'model_id'           => $profile['model_id'],
				'revision'           => $profile['revision'],
				'dtype'              => $profile['dtype'],
				'dimension'          => (int) $profile['dimension'],
				'preprocess_version' => $profile['preprocess_version'],
				'locale'             => isset( $item['locale'] ) ? (string) $item['locale'] : '',
				'content_hash'       => (string) $item['content_hash'],
				'vector_data'        => VectorMath::pack( $vector ),
				'norm'               => round( VectorMath::norm( $vector ), 6 ),
				'source'             => substr( (string) $source, 0, 16 ),
				'created_at'         => Util::now(),
			)
		) > 0;
	}

	/**
	 * Summary-chunk vectors for documents (only current content hash and model key).
	 *
	 * @param int[]  $doc_ids   Document IDs.
	 * @param string $model_key Model key.
	 * @return array<int,float[]>
	 */
	public static function summary_vectors( array $doc_ids, $model_key ) {
		$doc_ids = array_values( array_unique( array_filter( array_map( 'intval', $doc_ids ) ) ) );
		if ( array() === $doc_ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $doc_ids, 200 ) as $chunk ) {
			$rows = Db::rows(
				'SELECT e.document_id, e.vector_data FROM {embeddings} e JOIN {chunks} c ON c.document_id = e.document_id AND c.chunk_index = e.chunk_id AND c.kind = %s AND c.content_hash = e.content_hash WHERE e.model_key = %s AND e.document_id IN (' . Db::in( $chunk ) . ')',
				array_merge( array( 'summary', $model_key ), $chunk )
			);
			foreach ( $rows as $r ) {
				$vec = VectorMath::unpack( $r['vector_data'] );
				if ( array() !== $vec ) {
					$out[ (int) $r['document_id'] ] = $vec;
				}
			}
		}
		return $out;
	}

	/**
	 * Section-chunk vectors of one document (for anchor placement hints).
	 *
	 * @param int    $doc_id    Document ID.
	 * @param string $model_key Model key.
	 * @return array<int,float[]> chunk_index => vector
	 */
	public static function chunk_vectors( $doc_id, $model_key ) {
		$out = array();
		foreach ( Db::rows( "SELECT e.chunk_id, e.vector_data FROM {embeddings} e JOIN {chunks} c ON c.document_id = e.document_id AND c.chunk_index = e.chunk_id AND c.content_hash = e.content_hash WHERE e.document_id = %d AND e.model_key = %s AND c.kind = 'section'", array( (int) $doc_id, $model_key ) ) as $r ) {
			$out[ (int) $r['chunk_id'] ] = VectorMath::unpack( $r['vector_data'] );
		}
		return $out;
	}

	/**
	 * Coverage statistics for the active model.
	 *
	 * @param string $model_key Model key.
	 * @return array
	 */
	public static function stats( $model_key ) {
		return array(
			'vectors_current_model' => (int) Db::value( 'SELECT COUNT(*) FROM {embeddings} WHERE model_key = %s', array( $model_key ) ),
			'documents_with_vector' => (int) Db::value( 'SELECT COUNT(DISTINCT document_id) FROM {embeddings} WHERE model_key = %s', array( $model_key ) ),
			'vectors_other_models'  => (int) Db::value( 'SELECT COUNT(*) FROM {embeddings} WHERE model_key <> %s', array( $model_key ) ),
		);
	}
}
