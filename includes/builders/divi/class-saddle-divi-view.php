<?php
/**
 * Token-lean projections of Divi page state.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Context discipline (saddle-pro#11): the agent's context window is for
 * REASONING, not for warehousing attrs it never reads. This class centralizes
 * the three projections that keep Divi tool responses lean:
 *
 *  - compact tree reads — the skeleton without attrs; full attrs on demand
 *    per subtree (a whole-page read was the single largest token sink);
 *  - version stamps — a cheap content hash so an unchanged page short-
 *    circuits to one line instead of a re-read;
 *  - changed-node extracts — writes hand back exactly what they touched, so
 *    the mandatory follow-up divi-get-page disappears.
 */
class Saddle_Divi_View {

	use Saddle_Divi_Attr_Resolution;

	/**
	 * A cheap version stamp for a post's content: changes whenever the
	 * content does, comparable across sessions, never stored.
	 *
	 * @param WP_Post $post The post.
	 * @return string
	 */
	public static function version( WP_Post $post ) {
		return substr( md5( $post->post_modified_gmt . '|' . strlen( (string) $post->post_content ) . '|' . md5( (string) $post->post_content ) ), 0, 12 );
	}

	/**
	 * The memoized resolver instance the static projections read attr
	 * envelopes through (same trait as the accessors — the view can never
	 * disagree with them about where content lives).
	 *
	 * @return self
	 */
	private static function resolver() {
		static $resolver = null;
		if ( null === $resolver ) {
			$resolver = new self();
		}
		return $resolver;
	}

	/**
	 * A node's text: innerHTML when it carries any (native blocks), else
	 * the content excerpt from the attr envelopes (real Divi 5 blocks have
	 * EMPTY innerHTML — content lives in attrs, and a blank text column
	 * made compact reads useless for re-reading what a page says).
	 *
	 * @param string $inner_text Text derived from innerHTML.
	 * @param array  $attrs      Node attrs.
	 * @return string
	 */
	private static function node_text( $inner_text, $attrs ) {
		if ( '' !== trim( (string) $inner_text ) ) {
			return (string) $inner_text;
		}
		return self::resolver()->content_excerpt( array( 'attrs' => is_array( $attrs ) ? $attrs : array() ) );
	}

	/**
	 * Whether a node carries REAL styling/behavior: a preset binding, or any
	 * attr envelope beyond plain content. The old !empty(attrs) check was
	 * always true on authored nodes (builderVersion is stamped on every one)
	 * — a flag that never varies carries no signal.
	 *
	 * @param mixed $attrs Node attrs.
	 * @return bool
	 */
	private static function is_styled( $attrs ) {
		if ( ! is_array( $attrs ) ) {
			return false;
		}
		if ( ! empty( $attrs['modulePreset'] ) ) {
			return true;
		}
		foreach ( $attrs as $name => $value ) {
			if ( 'builderVersion' === $name || 'modulePreset' === $name || ! is_array( $value ) ) {
				continue;
			}
			foreach ( array_keys( $value ) as $key ) {
				if ( 'innerContent' !== $key ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Project flattened nodes for a read.
	 *
	 * @param array[] $nodes   Flat nodes (Saddle_Divi_Tree::flatten()).
	 * @param string  $mode    'compact' (attrs stripped) or 'full'.
	 * @param string  $address Optional subtree focus — its nodes keep full
	 *                         attrs even in compact mode.
	 * @param int     $depth   Optional depth cap (1 = roots only, 0 = all).
	 * @return array[]
	 */
	public static function project( array $nodes, $mode, $address = '', $depth = 0 ) {
		$out = array();
		foreach ( $nodes as $node ) {
			$node_depth = substr_count( $node['address'], '.' ) + 1;
			$in_focus   = '' !== $address && self::in_subtree( $node['address'], $address );

			if ( '' !== $address && ! $in_focus ) {
				continue;
			}
			if ( $depth > 0 && $node_depth > $depth && ! $in_focus ) {
				continue;
			}

			if ( 'full' === $mode || $in_focus ) {
				$out[] = $node;
				continue;
			}

			$out[] = array(
				'address'  => $node['address'],
				'type'     => $node['type'],
				'children' => $node['children'],
				'text'     => self::node_text( $node['text'], isset( $node['attrs'] ) ? $node['attrs'] : array() ),
				'styled'   => self::is_styled( isset( $node['attrs'] ) ? $node['attrs'] : array() ),
			);
		}
		return $out;
	}

	/**
	 * The full nodes a write touched, extracted from the just-persisted
	 * tree — what makes the post-write re-read unnecessary.
	 *
	 * @param array[]  $tree      Persisted tree.
	 * @param string[] $addresses Addresses that changed.
	 * @return array[]
	 */
	public static function changed( array $tree, array $addresses ) {
		$changed = array();
		foreach ( $addresses as $address ) {
			$block = Saddle_Tree::get( $tree, (string) $address );
			if ( ! $block ) {
				continue;
			}
			$changed[] = array(
				'address'  => (string) $address,
				'type'     => (string) $block['blockName'],
				'attrs'    => is_array( $block['attrs'] ) ? $block['attrs'] : array(),
				'children' => count( $block['innerBlocks'] ),
				'text'     => self::node_text(
					mb_substr( trim( wp_strip_all_tags( (string) $block['innerHTML'] ) ), 0, 120 ),
					$block['attrs']
				),
			);
		}
		return $changed;
	}

	/**
	 * Whether $address is $focus or inside its subtree.
	 *
	 * @param string $address Node address.
	 * @param string $focus   Focus address.
	 * @return bool
	 */
	private static function in_subtree( $address, $focus ) {
		return $address === $focus || 0 === strpos( $address . '.', $focus . '.' );
	}
}
