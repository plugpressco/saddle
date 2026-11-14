<?php
/**
 * Shared canonical-attr resolution for the Divi accessors.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place Divi attr envelopes are walked and var(--gcid-…) references
 * resolve through GlobalData. The lint accessor and the render accessor both
 * read design facts through THIS trait, so the two can never disagree about
 * what a path or a global color means.
 */
trait Saddle_Divi_Attr_Resolution {

	/**
	 * Global color id → value map, built lazily (null until first use).
	 *
	 * @var array<string,string>|null
	 */
	private $global_colors = null;

	/**
	 * A node's attrs, top-level values that are arrays only (attr envelopes).
	 *
	 * @param array $node Raw block array.
	 * @return array[]
	 */
	private function attrs( array $node ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		return array_filter( $attrs, 'is_array' );
	}

	/**
	 * Walk a nested array along $keys; null when any segment is missing.
	 *
	 * @param array    $subject Array to walk.
	 * @param string[] $keys    Path segments.
	 * @return mixed
	 */
	private function path( array $subject, array $keys ) {
		$node = $subject;
		foreach ( $keys as $key ) {
			if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
				return null;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	/**
	 * The first value found at a decoration path across the node's attr
	 * envelopes — the common "any attr may carry the decoration" read.
	 *
	 * @param array    $node Raw block array.
	 * @param string[] $keys Path segments under each attr envelope.
	 * @return mixed Null when no envelope carries the path.
	 */
	private function first_at_path( array $node, array $keys ) {
		foreach ( $this->attrs( $node ) as $attr ) {
			$value = $this->path( $attr, $keys );
			if ( null !== $value && '' !== $value && array() !== $value ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * A human-readable excerpt of a node's CONTENT, read from the attr
	 * envelopes — where Divi 5 actually keeps it. Real Divi blocks carry
	 * empty innerHTML, so any excerpt derived from innerHTML is blank on
	 * every real page; this walks innerContent values instead (title and
	 * content attrs first, then button text / image alt / other strings).
	 *
	 * @param array $node  Raw block array.
	 * @param int   $limit Character cap.
	 * @return string
	 */
	private function content_excerpt( array $node, $limit = 120 ) {
		$primary   = array();
		$secondary = array();
		foreach ( $this->attrs( $node ) as $name => $attr ) {
			$value = $this->path( $attr, array( 'innerContent', 'desktop', 'value' ) );
			$text  = '';
			if ( is_string( $value ) ) {
				$text = trim( wp_strip_all_tags( $value ) );
			} elseif ( is_array( $value ) ) {
				$parts = array();
				foreach ( array( 'text', 'alt', 'title' ) as $sub ) {
					if ( isset( $value[ $sub ] ) && is_string( $value[ $sub ] ) && '' !== trim( $value[ $sub ] ) ) {
						$parts[] = trim( wp_strip_all_tags( $value[ $sub ] ) );
					}
				}
				$text = implode( ' ', $parts );
			}
			if ( '' === $text ) {
				continue;
			}
			if ( in_array( (string) $name, array( 'title', 'content' ), true ) ) {
				$primary[] = $text;
			} else {
				$secondary[] = $text;
			}
		}

		return mb_substr( implode( ' · ', array_merge( $primary, $secondary ) ), 0, (int) $limit );
	}

	/**
	 * Resolve a global color reference through Divi's global palette: the
	 * var(--gcid-…) form and the $variable({"type":"color",…})$ token the
	 * Visual Builder writes, opacity included (Saddle_Divi_Tokens). Anything
	 * else, or a palette this process cannot read, passes through raw.
	 *
	 * @param string $color Color value.
	 * @return string
	 */
	private function resolve_global_color( $color ) {
		if ( false === strpos( (string) $color, 'gcid-' ) ) {
			return $color;
		}
		if ( null === $this->global_colors ) {
			$colors              = Saddle_Divi_Tokens::global_colors();
			$this->global_colors = null === $colors ? array() : $colors;
		}
		return Saddle_Divi_Tokens::resolve_color( $color, $this->global_colors );
	}

	/**
	 * The breakpoint > state cells a value at ($breakpoint, $state) is read
	 * from, nearest first, the way Divi inherits (ModuleUtils::
	 * get_inherit_breakpoint_map()): a state falls back to the value at the
	 * same breakpoint, and a value falls back to the next larger screen.
	 *
	 * @param string $breakpoint desktop, tablet or phone.
	 * @param string $state      value, hover, sticky, ….
	 * @return array[] Each [breakpoint, state].
	 */
	private function inherit_order( $breakpoint, $state ) {
		$chains = array(
			'desktop' => array( 'desktop' ),
			'tablet'  => array( 'tablet', 'desktop' ),
			'phone'   => array( 'phone', 'tablet', 'desktop' ),
		);
		$order  = 'value' === $state ? array() : array( array( $breakpoint, $state ) );
		foreach ( isset( $chains[ $breakpoint ] ) ? $chains[ $breakpoint ] : array( $breakpoint, 'desktop' ) as $bp ) {
			$order[] = array( $bp, 'value' );
		}
		return $order;
	}

	/**
	 * The value a field takes at a breakpoint and state, following Divi's
	 * inheritance: the nearest cell any of the node's attr envelopes sets,
	 * envelopes in document order within a cell. At desktop and value this
	 * is exactly first_at_path().
	 *
	 * @param array    $node       Raw block array.
	 * @param string[] $group      Keys from an attr to its breakpoints (decoration, font, font).
	 * @param string[] $field      Keys inside the value (color).
	 * @param string   $breakpoint Breakpoint.
	 * @param string   $state      State.
	 * @return mixed Null when nothing in the chain sets it.
	 */
	private function value_at( array $node, array $group, array $field, $breakpoint = 'desktop', $state = 'value' ) {
		foreach ( $this->inherit_order( $breakpoint, $state ) as $cell ) {
			$value = $this->first_at_path( $node, array_merge( $group, $cell, $field ) );
			if ( null !== $value ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Whether any attr envelope writes the field in exactly this
	 * breakpoint and state, no inheritance.
	 *
	 * @param array    $node       Raw block array.
	 * @param string[] $group      Keys from an attr to its breakpoints.
	 * @param string[] $field      Keys inside the value.
	 * @param string   $breakpoint Breakpoint.
	 * @param string   $state      State.
	 * @return bool
	 */
	private function sets_at( array $node, array $group, array $field, $breakpoint, $state ) {
		return null !== $this->first_at_path( $node, array_merge( $group, array( $breakpoint, $state ), $field ) );
	}
}
