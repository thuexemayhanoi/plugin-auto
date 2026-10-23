<?php
/**
 * Pinned embedding model profile (Qwen3-Embedding-0.6B ONNX for Transformers.js).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Facts below were verified from the Hugging Face Hub API on 2026-10-22 (see docs/COMPATIBILITY.md).
 * Model weights are never bundled; the browser downloads them only after explicit consent and
 * verifies their SHA-256 before use.
 */
final class ModelProfile {

	const MODEL_ID      = 'onnx-community/Qwen3-Embedding-0.6B-ONNX';
	const REVISION      = 'c25a394dd583836952667c12f008335071b3f43d';
	const BASE_MODEL    = 'Qwen/Qwen3-Embedding-0.6B';
	const BASE_REVISION = '97b0c614be4d77ee51c0cef4e5f07c00f9eb65b3';
	const LICENSE       = 'apache-2.0';
	const HOST          = 'https://huggingface.co';
	const MAX_DIMENSION = 1024;
	const POOLING       = 'last_token';
	// p2: the browser worker keeps the end-of-text token when it truncates (as the private worker
	// always did); vectors made before are not compared with newer ones (part of the model key).
	const PREPROCESS = 'p2';
	const MAX_TOKENS = 512;

	/**
	 * Query instruction used for retrieval-style queries (documents get no instruction), as documented
	 * on the model card: "Instruct: {task}\nQuery:{query}".
	 */
	const QUERY_TASK = 'Given a web search query, retrieve relevant passages that answer the query';

	/**
	 * Downloadable files per dtype (path => [bytes, sha256]). Non-LFS JSON files are verified by size.
	 */
	const FILES = array(
		'common' => array(
			'config.json'           => array( 1576, '' ),
			'tokenizer_config.json' => array( 9731, '' ),
			'tokenizer.json'        => array( 11423705, 'def76fb086971c7867b829c23a26261e38d9d74e02139253b38aeb9df8b4b50a' ),
		),
		'q4f16'  => array(
			'onnx/model_q4f16.onnx' => array( 567458583, '7f9ebb9a77c428f2b4c2d8ed8a2ee64f292a0b1ce7a3f027ccaaef150ef7bdd1' ),
		),
		'fp16'   => array(
			'onnx/model_fp16.onnx'      => array( 583522, 'f376cf065d912675dd559270e19361367ebc28c938b9811dd61d5834c78d0834' ),
			'onnx/model_fp16.onnx_data' => array( 1199927296, '06fbfded30166e3a33fe5252d7002ed1c370a4ec1bf45aba778cdf6cf7d31439' ),
		),
	);

	/**
	 * Runtime self-test: a query/document similarity matrix every device must reproduce before it may
	 * upload vectors. "scores" are the fp32 values published on the model card. Quantized weights
	 * shift that matrix systematically, so each offered dtype is compared with the matrix MEASURED
	 * for it with the real pinned files (GitHub Actions workflow "Real Qwen3-Embedding-0.6B", ONNX
	 * Runtime CPU; the browser worker on WASM and WebGPU reproduced q4f16/q4 within 0.008), using a
	 * tight tolerance. Measured deviations from the model card: fp32 0.0000, fp16 0.0005, q4f16
	 * 0.0937. Deliberately wrong pipelines deviate by 0.11–0.81 (no query instruction 0.193, mean
	 * pooling 0.436, first-token pooling 0.813, missing end-of-text token 0.108) and are rejected.
	 *
	 * The q8 variant (dynamic int8) is not offered: it was the least accurate variant (mean cosine 0.836 to fp32,
	 * top-5 neighbour agreement 0.869) and not reproducible across runtimes (browser vs ONNX Runtime
	 * CPU cosine 0.885–0.946), so vectors from different devices would not be comparable.
	 */
	const REFERENCE = array(
		'queries'         => array( 'What is the capital of China?', 'Explain gravity' ),
		'documents'       => array(
			'The capital of China is Beijing.',
			'Gravity is a force that attracts two bodies towards each other. It gives weight to physical objects and is responsible for the movement of planets around the sun.',
		),
		'scores'          => array( array( 0.7645590305328369, 0.14142560958862305 ), array( 0.13549776375293732, 0.599955141544342 ) ),
		'scores_by_dtype' => array(
			'fp16'  => array( array( 0.76408, 0.14131 ), array( 0.1356, 0.60018 ) ),
			'q4f16' => array( array( 0.73918, 0.23513 ), array( 0.19281, 0.67363 ) ),
		),
		'tolerance'       => array(
			'fp32'  => 0.01,
			'fp16'  => 0.02,
			'q4f16' => 0.03,
		),
	);

	/**
	 * Score calibration of raw cosine similarity, per precision. Qwen3 vectors of unrelated pages in
	 * the same language are not orthogonal: on the labelled fixture with the real q4f16 model, the
	 * document summary vectors gave 0.42 on average for unrelated same-language pairs and 0.62 for
	 * related pairs (fp16/fp32: 0.43 / 0.64). Without calibration that floor is added to every pair
	 * and lexical thresholds are crossed by unrelated pages. The calibrated value
	 * (cosine − floor) / (ceiling − floor), clipped to 0..1, gates overlap (cannibalization) risk;
	 * ranking keeps the raw cosine. Measured with the real q4f16 vectors (weight 0.35): calibrated
	 * everywhere cut overlap pairs 42 → 31 but also link recall@3 0.702 → 0.390 and related P@5
	 * 0.475 → 0.425, so it is used only where it helps. Both values are in the evidence.
	 */
	const CALIBRATION = array(
		'q4f16' => array(
			'floor'   => 0.40,
			'ceiling' => 0.90,
		),
		'fp16'  => array(
			'floor'   => 0.40,
			'ceiling' => 0.90,
		),
	);

	/**
	 * Calibration of the active profile (filterable; null disables it).
	 *
	 * @param string $dtype Precision.
	 * @return array{floor: float, ceiling: float}|null
	 */
	public static function calibration( $dtype ) {
		$cal = isset( self::CALIBRATION[ $dtype ] ) ? self::CALIBRATION[ $dtype ] : null;
		/**
		 * Filters the semantic score calibration (null = use raw cosine).
		 *
		 * @param array|null $cal   {floor, ceiling}.
		 * @param string     $dtype Precision.
		 */
		$cal = apply_filters( 'sseo_semantic_calibration', $cal, $dtype );
		if ( ! is_array( $cal ) || ! isset( $cal['floor'], $cal['ceiling'] ) || (float) $cal['ceiling'] <= (float) $cal['floor'] ) {
			return null;
		}
		return array(
			'floor'   => (float) $cal['floor'],
			'ceiling' => (float) $cal['ceiling'],
		);
	}

	/**
	 * Active profile from settings.
	 *
	 * @return array
	 */
	public static function active() {
		$dtype = (string) Settings::get( 'model_dtype', 'q4f16' );
		if ( ! isset( self::FILES[ $dtype ] ) ) {
			$dtype = 'q4f16';
		}
		$dim     = (int) Settings::get( 'embedding_dimension', 1024 );
		$files   = array_merge( self::FILES['common'], self::FILES[ $dtype ] );
		$profile = array(
			'model_id'           => self::MODEL_ID,
			'revision'           => self::REVISION,
			'base_model'         => self::BASE_MODEL,
			'base_revision'      => self::BASE_REVISION,
			'license'            => self::LICENSE,
			'host'               => self::HOST,
			'dtype'              => $dtype,
			'dimension'          => $dim,
			'pooling'            => self::POOLING,
			'normalize'          => true,
			'preprocess_version' => self::PREPROCESS,
			'max_tokens'         => self::MAX_TOKENS,
			'query_task'         => self::QUERY_TASK,
			'files'              => $files,
			'reference'          => self::REFERENCE,
		);
		/**
		 * Developer hook (e.g. serving the same pinned files from a self-hosted mirror, or the
		 * labelled test fixture in tests/e2e). Requires server code; the browser still verifies every
		 * file against the size/SHA-256 pins in the returned profile, and model_key is recomputed so
		 * vectors from different profiles are never mixed.
		 *
		 * @param array $profile Profile.
		 */
		$filtered = apply_filters( 'sseo_model_profile', $profile );
		if ( is_array( $filtered ) && self::is_valid( $filtered ) ) {
			$profile = $filtered;
		}
		$profile['download_bytes'] = 0;
		foreach ( $profile['files'] as $f ) {
			$profile['download_bytes'] += (int) $f[0];
		}
		$profile['model_key'] = self::key( $profile );
		return $profile;
	}

	/**
	 * Minimal structural validation of a (filtered) profile.
	 *
	 * @param array $p Profile.
	 * @return bool
	 */
	private static function is_valid( array $p ) {
		foreach ( array( 'model_id', 'revision', 'dtype', 'dimension', 'pooling', 'preprocess_version', 'host', 'files', 'query_task', 'max_tokens', 'reference' ) as $k ) {
			if ( ! isset( $p[ $k ] ) ) {
				return false;
			}
		}
		if ( ! is_array( $p['files'] ) || array() === $p['files'] || 0 !== strpos( (string) $p['host'], 'http' ) ) {
			return false;
		}
		foreach ( $p['files'] as $path => $pin ) {
			if ( ! is_string( $path ) || ! is_array( $pin ) || ! isset( $pin[0], $pin[1] ) || (int) $pin[0] <= 0 ) {
				return false;
			}
		}
		return (int) $p['dimension'] > 0 && (int) $p['dimension'] <= self::MAX_DIMENSION;
	}

	/**
	 * Stable key identifying vectors that are comparable with each other.
	 *
	 * @param array $p Profile.
	 * @return string
	 */
	public static function key( array $p ) {
		return sha1( $p['model_id'] . '|' . $p['revision'] . '|' . $p['dtype'] . '|' . $p['dimension'] . '|' . $p['preprocess_version'] . '|' . $p['pooling'] );
	}
}
