<?php
/**
 * The closed-loop verify engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One scored answer to "did what I built actually land, and is it any good?"
 * (https://github.com/plugpressco/saddle/issues/26).
 *
 * Three passes over FRESHLY RE-READ persisted state — the re-read is the
 * whole point: it proves what is in the database, not what a write call
 * claimed:
 *
 *  1. structural — does the persisted tree hold together at all;
 *  2. echo — persisted attrs the builder will silently IGNORE (the intended
 *     design didn't take effect, the worst kind of failure because nothing
 *     errored);
 *  3. lint — the design/accessibility judgment rules.
 *
 * Findings merge into one deduped, document-ordered, capped list, every one
 * keyed by the same dot address the page-read tools emit — so an agent
 * loops: read → verify → fix the flagged address → re-verify. The score is
 * deterministic arithmetic, never vibes.
 *
 * Builder pages plug in through `saddle_verify_builder_findings` (structural
 * + echo, Saddle Pro's Divi driver) and the existing `saddle_lint_accessor`
 * filter (judgment) — same one-tool-surface pattern as lint and render.
 */
class Saddle_Verify {

	/**
	 * Hard cap on returned findings; the rest is an overflow count.
	 */
	const FINDINGS_CAP = 40;

	/**
	 * Score penalties per finding, by source (lint splits by severity).
	 */
	const PENALTY_STRUCTURAL = 25;
	const PENALTY_ECHO       = 10;
	const PENALTY_LINT_ERROR = 8;
	const PENALTY_LINT_WARN  = 3;

	/**
	 * Verify a post's persisted state.
	 *
	 * @param WP_Post                   $post     The post (freshly read by the caller).
	 * @param string|null               $builder  Detected builder, null = native.
	 * @param Saddle_Lint_Accessor|null $accessor Lint accessor, null = no judgment pass.
	 * @return array { score, grade, counts, findings, overflow, skipped }
	 */
	public static function run( WP_Post $post, $builder, $accessor ) {
		$tree     = Saddle_Tree::parse( $post->post_content );
		$findings = array();
		$skipped  = array();

		// Pass 1 + 2 — structural + applied-vs-ignored, on persisted attrs.
		if ( null === $builder ) {
			$findings = array_merge( $findings, self::native_structural( $post, $tree ) );
			$findings = array_merge( $findings, self::native_echo( $tree ) );
		} else {
			/**
			 * Filter builder structural + echo findings for verify-page.
			 *
			 * Builder integrations (Saddle Pro's Divi driver) validate the
			 * persisted tree and echo-check persisted attrs, returning
			 * findings in the engine's shape: { address, source
			 * (structural|echo), severity, message, fix_hint }. Null means
			 * the builder has no verifier installed.
			 *
			 * @param array[]|null $findings Builder findings, null = unhandled.
			 * @param array[]      $tree     Parsed persisted tree.
			 * @param string       $builder  Detected builder.
			 * @param WP_Post      $post     The post.
			 */
			$builder_findings = apply_filters( 'saddle_verify_builder_findings', null, $tree, $builder, $post );
			if ( is_array( $builder_findings ) ) {
				$findings = array_merge( $findings, $builder_findings );
			} else {
				$skipped[] = 'structural';
				$skipped[] = 'echo';
			}
		}

		// Pass 3 — judgment, through the same rules lint-page runs.
		if ( $accessor instanceof Saddle_Lint_Accessor ) {
			foreach ( Saddle_Lint::run( $tree, $accessor ) as $violation ) {
				$findings[] = array(
					'address'  => $violation['address'],
					'source'   => 'lint',
					'rule'     => $violation['rule'],
					'severity' => $violation['severity'],
					'message'  => $violation['message'],
					'fix_hint' => $violation['fix_hint'],
				);
			}
		} else {
			$skipped[] = 'lint';
		}

		$findings = self::merge( $findings );
		$score    = self::score( $findings );

		$overflow = 0;
		if ( count( $findings ) > self::FINDINGS_CAP ) {
			$overflow = count( $findings ) - self::FINDINGS_CAP;
			$findings = array_slice( $findings, 0, self::FINDINGS_CAP );
		}

		return array(
			'score'    => $score,
			'grade'    => self::grade( $score ),
			'counts'   => self::counts( $findings, $overflow ),
			'findings' => $findings,
			'overflow' => $overflow,
			'skipped'  => array_values( array_unique( $skipped ) ),
		);
	}

	/*
	---------------------------------------------------------------------
	 * Native passes
	 * -------------------------------------------------------------------
	 */

	/**
	 * Structural check for native content: content that exists but parses to
	 * no real blocks is a page the editor can't edit.
	 *
	 * @param WP_Post $post The post.
	 * @param array[] $tree Parsed tree.
	 * @return array[]
	 */
	private static function native_structural( WP_Post $post, array $tree ) {
		if ( '' === trim( (string) $post->post_content ) ) {
			return array();
		}
		foreach ( Saddle_Lint::nodes( $tree ) as $node ) {
			if ( '' !== trim( (string) $node['type'] ) ) {
				return array();
			}
		}
		return array(
			array(
				'address'  => '',
				'source'   => 'structural',
				'severity' => 'error',
				'message'  => __( 'The content is not block markup — the editor cannot address or edit it.', 'saddle' ),
				'fix_hint' => __( 'Rebuild the content as blocks (set-blocks) instead of raw HTML.', 'saddle' ),
			),
		);
	}

	/**
	 * Applied-vs-ignored on every persisted native node: attr paths core
	 * will silently drop are designs that never took effect.
	 *
	 * @param array[] $tree Parsed tree.
	 * @return array[]
	 */
	private static function native_echo( array $tree ) {
		$findings = array();
		foreach ( Saddle_Lint::nodes( $tree ) as $node ) {
			$attrs = isset( $node['block']['attrs'] ) && is_array( $node['block']['attrs'] ) ? $node['block']['attrs'] : array();
			if ( ! $attrs || '' === $node['type'] ) {
				continue;
			}
			foreach ( Saddle_Blocks_Echo::check_attrs( $node['type'], $attrs, $node['address'] ) as $warning ) {
				$findings[] = array(
					'address'  => $node['address'],
					'source'   => 'echo',
					'severity' => 'error',
					'message'  => (string) $warning,
					'fix_hint' => __( 'This persisted attribute does nothing — rewrite it on a path the block actually supports, or remove it.', 'saddle' ),
				);
			}
		}
		return $findings;
	}

	/*
	---------------------------------------------------------------------
	 * Merge + score
	 * -------------------------------------------------------------------
	 */

	/**
	 * Dedupe and order findings: severity class first (structural, echo,
	 * lint errors, lint warns), document order within each.
	 *
	 * @param array[] $findings Raw findings.
	 * @return array[]
	 */
	private static function merge( array $findings ) {
		$unique = array();
		foreach ( $findings as $finding ) {
			$key = implode(
				'|',
				array(
					isset( $finding['address'] ) ? (string) $finding['address'] : '',
					isset( $finding['source'] ) ? (string) $finding['source'] : '',
					isset( $finding['rule'] ) ? (string) $finding['rule'] : md5( isset( $finding['message'] ) ? (string) $finding['message'] : '' ),
				)
			);
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $finding;
			}
		}
		$findings = array_values( $unique );

		usort(
			$findings,
			static function ( $a, $b ) {
				$rank_a = self::rank( $a );
				$rank_b = self::rank( $b );
				if ( $rank_a !== $rank_b ) {
					return $rank_a < $rank_b ? -1 : 1;
				}
				return Saddle_Lint::compare_addresses( $a, $b );
			}
		);
		return $findings;
	}

	/**
	 * Ordering rank of a finding: what an agent should fix first.
	 *
	 * @param array $finding The finding.
	 * @return int
	 */
	private static function rank( array $finding ) {
		if ( 'structural' === $finding['source'] ) {
			return 0;
		}
		if ( 'echo' === $finding['source'] ) {
			return 1;
		}
		return 'error' === $finding['severity'] ? 2 : 3;
	}

	/**
	 * Deterministic 0–100 score.
	 *
	 * @param array[] $findings Deduped findings (pre-cap).
	 * @return int
	 */
	private static function score( array $findings ) {
		$score = 100;
		foreach ( $findings as $finding ) {
			switch ( self::rank( $finding ) ) {
				case 0:
					$score -= self::PENALTY_STRUCTURAL;
					break;
				case 1:
					$score -= self::PENALTY_ECHO;
					break;
				case 2:
					$score -= self::PENALTY_LINT_ERROR;
					break;
				default:
					$score -= self::PENALTY_LINT_WARN;
			}
		}
		return max( 0, $score );
	}

	/**
	 * Letter grade for a score.
	 *
	 * @param int $score The score.
	 * @return string
	 */
	private static function grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 75 ) {
			return 'B';
		}
		if ( $score >= 60 ) {
			return 'C';
		}
		if ( $score >= 40 ) {
			return 'D';
		}
		return 'F';
	}

	/**
	 * Finding counts by class, for the summary line.
	 *
	 * @param array[] $findings Capped findings.
	 * @param int     $overflow Findings beyond the cap.
	 * @return array
	 */
	private static function counts( array $findings, $overflow ) {
		$counts = array(
			'structural' => 0,
			'ignored'    => 0,
			'errors'     => 0,
			'warnings'   => 0,
		);
		foreach ( $findings as $finding ) {
			switch ( self::rank( $finding ) ) {
				case 0:
					++$counts['structural'];
					break;
				case 1:
					++$counts['ignored'];
					break;
				case 2:
					++$counts['errors'];
					break;
				default:
					++$counts['warnings'];
			}
		}
		$counts['overflow'] = (int) $overflow;
		return $counts;
	}
}
