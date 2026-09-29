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
 * + echo, the built-in Divi 5 verifier) and the existing `saddle_lint_accessor`
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
			 * A builder integration validates the persisted tree and
			 * echo-checks persisted attrs, returning
			 * findings in the engine's shape: { address, source
			 * (structural|echo), severity, message, fix_hint }. Null leaves
			 * it to the built-in Divi 5 verifier, or none.
			 *
			 * @param array[]|null $findings Builder findings, null = unhandled.
			 * @param array[]      $tree     Parsed persisted tree.
			 * @param string       $builder  Detected builder.
			 * @param WP_Post      $post     The post.
			 */
			$builder_findings = apply_filters( 'saddle_verify_builder_findings', null, $tree, $builder, $post );

			// Divi 5 is verified here unless an integration already answered.
			if ( null === $builder_findings ) {
				$driver = Saddle_Builder_Registry::for_builder( (string) $builder );
				if ( $driver && 'divi' === $driver->slug() ) {
					$builder_findings = Saddle_Divi_Verify::findings( $tree );
				}
			}
			if ( is_array( $builder_findings ) ) {
				$findings = array_merge( $findings, $builder_findings );
			} else {
				$skipped[] = 'structural';
				$skipped[] = 'echo';
			}
		}

		// Pass 3 — judgment, through the same rules lint-page runs.
		if ( $accessor instanceof Saddle_Lint_Accessor ) {
			foreach ( array_merge( Saddle_Lint::post_findings( $post ), Saddle_Lint::run( $tree, $accessor ) ) as $violation ) {
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
		$grade    = self::grade( $score, $findings );

		$overflow = 0;
		if ( count( $findings ) > self::FINDINGS_CAP ) {
			$overflow = count( $findings ) - self::FINDINGS_CAP;
			$findings = array_slice( $findings, 0, self::FINDINGS_CAP );
		}

		return array(
			'score'    => $score,
			'grade'    => $grade,
			'counts'   => self::counts( $findings, $overflow ),
			'findings' => $findings,
			'overflow' => $overflow,
			'skipped'  => array_values( array_unique( $skipped ) ),
		);
	}

	/**
	 * Whether the PUBLIC page serves what was saved (#221).
	 *
	 * The community's standing complaint about AI site tools: "it read its own
	 * write back and reported success while the public page served old
	 * content". The saved state says nothing about a page cache or CDN in
	 * front of it. This fetches the permalink as a logged-out visitor and looks
	 * for a few distinctive passages of the saved page's rendered text. Kept
	 * out of the score on purpose: a stale cache is not a flaw in the page, and
	 * docking the grade for it would send an agent back into the editor.
	 *
	 * @param WP_Post $post The post.
	 * @return array { checked, status?, url?, looked_for?, missing?, reason? }
	 */
	public static function public_check( WP_Post $post ) {
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return array(
				'checked' => false,
				'reason'  => __( 'Only a published page without a password has a public version to check. Preview it with get-preview-url instead.', 'saddle' ),
			);
		}

		$markers = self::public_markers( $post );
		if ( ! $markers ) {
			return array(
				'checked' => false,
				'reason'  => __( 'The page has no text distinctive enough to look for on the public page.', 'saddle' ),
			);
		}

		$url     = (string) get_permalink( $post );
		$fetched = Saddle_HTTP::fetch_own_page( $url );
		if ( is_wp_error( $fetched ) || $fetched['status'] < 200 || $fetched['status'] >= 300 ) {
			return array(
				'checked' => true,
				'status'  => 'unreachable',
				'url'     => $url,
				'reason'  => is_wp_error( $fetched )
					? $fetched->get_error_message()
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The public page answered with HTTP %d.', 'saddle' ), $fetched['status'] ),
			);
		}

		$served  = self::plain_text( $fetched['body'] );
		$missing = array();
		foreach ( $markers as $marker ) {
			if ( false === strpos( $served, $marker ) ) {
				$missing[] = $marker;
			}
		}

		return array(
			'checked'    => true,
			'status'     => $missing ? 'stale' : 'served',
			'url'        => $url,
			'looked_for' => count( $markers ),
			'missing'    => $missing,
		);
	}

	/**
	 * Up to three distinctive passages of the saved page's rendered text.
	 *
	 * Rendered through the_content, so the same filters the front end applies
	 * (texturized quotes, shortcodes, blocks) apply here too, and rendered as
	 * a logged-out visitor, so a block that greets the signed-in user cannot
	 * produce a passage the public page was never going to show. The longest
	 * passages win: they are the least likely to also appear on the old copy.
	 *
	 * @param WP_Post $post The post.
	 * @return string[]
	 */
	private static function public_markers( WP_Post $post ) {
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$user_id  = get_current_user_id();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- render the_content in the post's own context, restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		wp_set_current_user( 0 );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own the_content filter, applied so the saved page renders exactly as the front end renders it.
		$html = apply_filters( 'the_content', $post->post_content );

		wp_set_current_user( $user_id );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value saved above.
		$GLOBALS['post'] = $previous;
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}

		$passages = array();
		foreach ( explode( "\n", self::plain_text( (string) $html, "\n" ) ) as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) >= 16 ) {
				$passages[ $line ] = strlen( $line );
			}
		}
		arsort( $passages );

		$markers = array();
		foreach ( array_keys( $passages ) as $line ) {
			// A long paragraph only needs its opening to identify it, and a
			// shorter marker is less likely to be broken by a theme's markup.
			$markers[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 80 ) : substr( $line, 0, 80 );
			if ( 3 === count( $markers ) ) {
				break;
			}
		}

		return array_values( array_unique( $markers ) );
	}

	/**
	 * HTML reduced to comparable text: tags become separators, entities are
	 * decoded, and runs of whitespace (including non-breaking spaces) collapse.
	 *
	 * @param string $html      HTML.
	 * @param string $separator What a tag becomes; "\n" keeps passages apart.
	 * @return string
	 */
	private static function plain_text( $html, $separator = ' ' ) {
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
		$text = html_entity_decode( (string) preg_replace( '/<[^>]+>/', $separator, $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );

		if ( "\n" === $separator ) {
			// Whitespace other than newlines. Not `\v`: in PCRE that is any
			// vertical whitespace, newlines included, which collapsed a whole
			// page into one passage (#238).
			return (string) preg_replace( array( '/[^\S\n]+/u', '/\n\s*/u' ), array( ' ', "\n" ), $text );
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
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
		$nodes = Saddle_Lint::nodes( $tree );
		foreach ( $nodes as $node ) {
			if ( '' !== trim( (string) $node['type'] ) ) {
				return self::unwrapped_children( $nodes );
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
	 * Container blocks whose wrapper element closes before their inner
	 * blocks. The children still parse as inner blocks, so every read looks
	 * fine, but they render after the wrapper: an empty <ul></ul> followed
	 * by loose <li> items (#250). Detected by the wrapper's own tag depth in
	 * the markup before the first child placeholder; an inner element that
	 * opens and closes there (a figure, a background span) doesn't count.
	 *
	 * @param array[] $nodes Flat node list from Saddle_Lint::nodes().
	 * @return array[]
	 */
	private static function unwrapped_children( array $nodes ) {
		$findings = array();
		foreach ( $nodes as $node ) {
			$block = $node['block'];
			if ( empty( $block['innerBlocks'] ) || empty( $block['innerContent'] ) ) {
				continue;
			}
			$before = $block['innerContent'][0];
			if ( ! is_string( $before ) || ! preg_match( '/^\s*<([a-z][a-z0-9-]*)[\s>\/]/i', $before, $match ) ) {
				continue;
			}
			$tag   = preg_quote( $match[1], '/' );
			$depth = preg_match_all( '/<' . $tag . '[\s>\/]/i', $before ) - preg_match_all( '/<\/' . $tag . '\s*>/i', $before );
			if ( $depth > 0 ) {
				continue;
			}
			$findings[] = array(
				'address'  => $node['address'],
				'source'   => 'structural',
				'severity' => 'error',
				'message'  => sprintf(
					/* translators: 1: block type, 2: HTML tag name. */
					__( 'The %1$s block closes its <%2$s> before its inner blocks, so they render outside it (for a list: an empty list followed by loose items).', 'saddle' ),
					$node['type'],
					$match[1]
				),
				'fix_hint' => __( 'Rebuild this block: remove-block, then add-block with its content, or set-blocks for the whole page.', 'saddle' ),
			);
		}
		return $findings;
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
	 * Letter grade for a score, demoted by categorical failures.
	 *
	 * The arithmetic alone let a page with one echo finding score 90/A —
	 * but "your styling never took effect" is categorically not an A, no
	 * matter how small the penalty. Any structural finding caps the grade
	 * at C; any echo finding caps it at B. The numeric score is untouched
	 * (it stays deterministic arithmetic); only the letter is honest about
	 * category.
	 *
	 * @param int     $score    The score.
	 * @param array[] $findings Deduped findings (pre-cap).
	 * @return string
	 */
	private static function grade( $score, array $findings = array() ) {
		if ( $score >= 90 ) {
			$letter = 'A';
		} elseif ( $score >= 75 ) {
			$letter = 'B';
		} elseif ( $score >= 60 ) {
			$letter = 'C';
		} elseif ( $score >= 40 ) {
			$letter = 'D';
		} else {
			$letter = 'F';
		}

		foreach ( $findings as $finding ) {
			if ( 'structural' === $finding['source'] ) {
				return self::worst( $letter, 'C' );
			}
		}
		foreach ( $findings as $finding ) {
			if ( 'echo' === $finding['source'] ) {
				return self::worst( $letter, 'B' );
			}
		}
		return $letter;
	}

	/**
	 * The worse of two letter grades.
	 *
	 * @param string $a One grade.
	 * @param string $b Another grade.
	 * @return string
	 */
	private static function worst( $a, $b ) {
		$order = array( 'A', 'B', 'C', 'D', 'F' );
		return $order[ max( (int) array_search( $a, $order, true ), (int) array_search( $b, $order, true ) ) ];
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
