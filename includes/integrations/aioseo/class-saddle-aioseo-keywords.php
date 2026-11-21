<?php
/**
 * AIOSEO — a post's focus keyphrase and additional keyphrases.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the keyphrases on AIOSEO's Post model. Its own class
 * because AIOSEO keeps them in two shapes and both must agree:
 *
 * - `keyphrases` (every version): `{ focus: { keyphrase, score, analysis },
 *   additional: [ { keyphrase, score } ] }`.
 * - Since AIOSEO 5.0.0, the columns `focus_keyword` (a plain string, at most
 *   255 characters) and `additional_keywords` (a list of `{ word, score }`).
 *   These win: AIOSEO copies `keyphrases` into a column only while that
 *   column is empty, so a write to `keyphrases` alone changes nothing on a
 *   post that already has a keyphrase.
 *
 * So a read prefers the columns and falls back to `keyphrases`, and a write
 * sets both, as AIOSEO's own editor does. Shapes read from AIOSEO 5.0.5.1
 * (its Post model and its editor).
 */
class Saddle_Aioseo_Keywords {

	/**
	 * The keyphrases on a model, as the tools return them.
	 *
	 * @param object $model AIOSEO Post model.
	 * @return array{focus_keyword:string,additional_keywords:string[]}
	 */
	public static function read( $model ) {
		$legacy     = self::to_array( $model->keyphrases ?? null );
		$focus      = isset( $legacy['focus']['keyphrase'] ) && is_string( $legacy['focus']['keyphrase'] )
			? $legacy['focus']['keyphrase']
			: '';
		$additional = self::words( $legacy['additional'] ?? null, 'keyphrase' );

		if ( Saddle_Aioseo::has_keyword_columns() ) {
			$column = $model->focus_keyword ?? '';
			if ( is_string( $column ) && '' !== $column ) {
				$focus = $column;
			}
			$column_words = self::words( self::to_array( $model->additional_keywords ?? null ), 'word' );
			if ( $column_words ) {
				$additional = $column_words;
			}
		}

		return array(
			'focus_keyword'       => $focus,
			'additional_keywords' => $additional,
		);
	}

	/**
	 * Put the keyphrases in $input on the model (not saved here). Only the
	 * keys present change: a new focus keyphrase leaves the additional ones
	 * alone, and the other way round.
	 *
	 * @param object $model AIOSEO Post model.
	 * @param array  $input Ability input.
	 * @return string[] The friendly fields that were set.
	 */
	public static function apply( $model, array $input ) {
		$sets_focus      = array_key_exists( 'focus_keyword', $input );
		$sets_additional = array_key_exists( 'additional_keywords', $input );
		if ( ! $sets_focus && ! $sets_additional ) {
			return array();
		}

		$columns = Saddle_Aioseo::has_keyword_columns();
		$legacy  = self::to_array( $model->keyphrases ?? null );
		$touched = array();

		if ( ! isset( $legacy['focus'] ) || ! is_array( $legacy['focus'] ) ) {
			$legacy['focus'] = array();
		}
		if ( ! isset( $legacy['focus']['keyphrase'] ) ) {
			// An empty focus must still save as an object, not a list.
			$legacy['focus']['keyphrase'] = '';
		}

		if ( $sets_focus ) {
			$focus                        = self::clean( $input['focus_keyword'] );
			$legacy['focus']['keyphrase'] = $focus;
			if ( $columns ) {
				$model->focus_keyword = '' === $focus ? null : mb_substr( $focus, 0, 255 );
			}
			$touched[] = 'focus_keyword';
		}

		if ( $sets_additional ) {
			$old_legacy = self::by_word( $legacy['additional'] ?? null, 'keyphrase' );
			$old_column = self::by_word( self::to_array( $model->additional_keywords ?? null ), 'word' );
			$new_legacy = array();
			$new_column = array();

			foreach ( self::clean_list( $input['additional_keywords'] ) as $key => $word ) {
				// A keyphrase already on the post keeps its score and analysis.
				$score        = (int) ( $old_column[ $key ]['score'] ?? $old_legacy[ $key ]['score'] ?? 0 );
				$new_legacy[] = array_merge(
					array(
						'keyphrase' => $word,
						'score'     => $score,
					),
					$old_legacy[ $key ] ?? array(),
					array( 'keyphrase' => $word )
				);
				$new_column[] = array_merge(
					array(
						'word'  => $word,
						'score' => $score,
					),
					$old_column[ $key ] ?? array(),
					array( 'word' => $word )
				);
			}

			$legacy['additional'] = $new_legacy;
			if ( $columns ) {
				$model->additional_keywords = $new_column ? json_decode( (string) wp_json_encode( $new_column ) ) : null;
			}
			$touched[] = 'additional_keywords';
		}

		// The model round-trips keyphrases as a JSON-decoded object.
		$model->keyphrases = json_decode( (string) wp_json_encode( $legacy ) );

		return $touched;
	}

	/**
	 * One keyphrase, as plain text.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	private static function clean( $value ) {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/**
	 * A list of keyphrases: plain text, no empty ones, no repeats (case
	 * ignored), keyed by the lowercase word.
	 *
	 * @param mixed $values Raw input.
	 * @return array<string,string>
	 */
	private static function clean_list( $values ) {
		$out = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$word = self::clean( $value );
			$key  = mb_strtolower( $word );
			if ( '' !== $word && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $word;
			}
		}
		return $out;
	}

	/**
	 * The words in a list of keyphrase entries.
	 *
	 * @param mixed  $entries A list of entries.
	 * @param string $field   The entry key holding the word.
	 * @return string[]
	 */
	private static function words( $entries, $field ) {
		return array_values( wp_list_pluck( self::by_word( $entries, $field ), $field ) );
	}

	/**
	 * A list of keyphrase entries, keyed by the lowercase word.
	 *
	 * @param mixed  $entries A list of entries.
	 * @param string $field   The entry key holding the word.
	 * @return array<string,array>
	 */
	private static function by_word( $entries, $field ) {
		$out = array();
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			$word = is_array( $entry ) && isset( $entry[ $field ] ) && is_scalar( $entry[ $field ] ) ? trim( (string) $entry[ $field ] ) : '';
			if ( '' !== $word ) {
				$entry[ $field ]               = $word;
				$out[ mb_strtolower( $word ) ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Coerce a JSON column (string, object, array or null) to arrays all the
	 * way down. The model decodes a list column to a PHP array of objects,
	 * so an array goes through the same round trip as an object.
	 *
	 * @param mixed $value Raw column value.
	 * @return array
	 */
	private static function to_array( $value ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		} elseif ( is_object( $value ) || is_array( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : array();
	}
}
