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
	 * Resolve a var(--gcid-…) reference through Divi's global palette when
	 * the runtime is available; anything else passes through raw.
	 *
	 * @param string $color Color value.
	 * @return string
	 */
	private function resolve_global_color( $color ) {
		if ( ! preg_match( '/^var\(\s*--(gcid-[a-z0-9-]+)\s*\)$/i', trim( $color ), $m ) ) {
			return $color;
		}

		if ( null === $this->global_colors ) {
			$this->global_colors = array();
			if ( class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
				foreach ( (array) \ET\Builder\Packages\GlobalData\GlobalData::get_global_colors() as $id => $entry ) {
					if ( isset( $entry['color'] ) && is_string( $entry['color'] ) ) {
						$this->global_colors[ (string) $id ] = $entry['color'];
					}
				}
			}
		}

		return isset( $this->global_colors[ $m[1] ] ) ? $this->global_colors[ $m[1] ] : $color;
	}
}
