<?php
/**
 * The builder-agnostic render engine.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns persisted page state into the lean, bounded views an agent's eyes
 * can afford (https://github.com/plugpressco/saddle/issues/24): one node in
 * detail, or a whole page as a shallow section outline. Everything
 * builder-specific resolves through Saddle_Render_Accessor; this class only
 * walks, caps, and sanitizes — an agent must never pay an unbounded token
 * bill for looking at its own work.
 */
class Saddle_Render {

	/**
	 * Hard cap on returned node HTML, in bytes.
	 */
	const HTML_CAP = 2048;

	/**
	 * Hard cap on an outline entry's text summary, in characters.
	 */
	const TEXT_CAP = 80;

	/**
	 * A detailed view of one node.
	 *
	 * @param WP_Post                $post     The post.
	 * @param array                  $tree     Parsed tree.
	 * @param string                 $address  Dot address.
	 * @param Saddle_Render_Accessor $accessor Builder accessor.
	 * @param string[]               $include  Artifacts to include: 'styles', 'html'.
	 * @return array|WP_Error
	 */
	public static function node( WP_Post $post, array $tree, $address, Saddle_Render_Accessor $accessor, array $include ) {
		$block = Saddle_Tree::get( $tree, (string) $address );
		if ( ! $block ) {
			return new WP_Error(
				'saddle_render_no_node',
				sprintf(
					/* translators: %s: node address. */
					__( 'No node at address "%s". Re-read the page first — addresses shift when the tree changes.', 'saddle' ),
					(string) $address
				),
				array( 'status' => 404 )
			);
		}

		$view = array(
			'address' => (string) $address,
			'type'    => (string) $block['blockName'],
		);

		if ( in_array( 'styles', $include, true ) ) {
			$view['styles'] = $accessor->effective_styles( $block );
		}

		if ( in_array( 'html', $include, true ) ) {
			$html = $accessor->render_node_html( $post, $tree, (string) $address );
			if ( is_wp_error( $html ) ) {
				$view['html']      = null;
				$view['html_note'] = $html->get_error_message();
			} else {
				$view['html'] = self::cap_html( (string) $html );
			}
			$view['fidelity'] = $accessor->render_fidelity();
		}

		return $view;
	}

	/**
	 * A whole page as a shallow outline: the top-level sections only, each a
	 * one-line summary — the agent drills into a node by address instead of
	 * paying for every node's HTML.
	 *
	 * @param array                  $tree     Parsed tree.
	 * @param Saddle_Render_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public static function outline( array $tree, Saddle_Render_Accessor $accessor ) {
		$nodes = Saddle_Lint::nodes( $tree );

		$sections = self::sections( $nodes );
		$outline  = array();
		foreach ( $sections as $section ) {
			$entry = array(
				'address'  => $section['address'],
				'type'     => $section['type'],
				'children' => count( self::children_of( $nodes, $section['address'] ) ),
			);

			$text = self::text_summary( $nodes, $section );
			if ( '' !== $text ) {
				$entry['text'] = $text;
			}

			$styles = $accessor->effective_styles( $section['block'] );
			if ( $styles ) {
				$entry['styles'] = $styles;
			}

			$outline[] = $entry;
		}
		return $outline;
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------
	 */

	/**
	 * Cap and sanitize rendered HTML for the agent: script/style bodies and
	 * HTML comments carry no visual information, whitespace runs are noise,
	 * and everything past the cap is truncation the agent is told about.
	 *
	 * @param string $html Raw rendered HTML.
	 * @return string
	 */
	private static function cap_html( $html ) {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		$html = trim( preg_replace( '/\s+/', ' ', $html ) );

		if ( strlen( $html ) > self::HTML_CAP ) {
			$html = substr( $html, 0, self::HTML_CAP ) . '… [truncated]';
		}
		return $html;
	}

	/**
	 * The page's top-level sections: root nodes, or — when the page is a
	 * single root wrapper (Divi's placeholder, a lone all-page group) — that
	 * wrapper's children. Same convention as the lint rules.
	 *
	 * @param array[] $nodes Flat node list (Saddle_Lint::nodes()).
	 * @return array[]
	 */
	private static function sections( array $nodes ) {
		$roots = self::children_of( $nodes, null );
		if ( 1 === count( $roots ) ) {
			$inner = self::children_of( $nodes, $roots[0]['address'] );
			if ( $inner ) {
				return $inner;
			}
		}
		return $roots;
	}

	/**
	 * Direct children of an address, in order.
	 *
	 * @param array[]     $nodes          Flat node list.
	 * @param string|null $parent_address Parent address (null = roots).
	 * @return array[]
	 */
	private static function children_of( array $nodes, $parent_address ) {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( $node['parent'] === $parent_address ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * A one-line text summary of a section: the first non-empty text found
	 * in its subtree, capped.
	 *
	 * @param array[] $nodes   Flat node list.
	 * @param array   $section Section node entry.
	 * @return string
	 */
	private static function text_summary( array $nodes, array $section ) {
		$candidates = array_merge( array( $section ), self::descendants_of( $nodes, $section['address'] ) );
		foreach ( $candidates as $node ) {
			$text = trim( wp_strip_all_tags( (string) $node['block']['innerHTML'] ) );
			if ( '' !== $text ) {
				return mb_substr( preg_replace( '/\s+/', ' ', $text ), 0, self::TEXT_CAP );
			}
		}
		return '';
	}

	/**
	 * All descendants of an address, document order.
	 *
	 * @param array[] $nodes    Flat node list.
	 * @param string  $ancestor Ancestor address.
	 * @return array[]
	 */
	private static function descendants_of( array $nodes, $ancestor ) {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( 0 === strpos( $node['address'] . '.', $ancestor . '.' ) && $node['address'] !== $ancestor ) {
				$out[] = $node;
			}
		}
		return $out;
	}
}
