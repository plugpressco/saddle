<?php
/**
 * Text facts the newer Divi lint rules read.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the words and type a Divi 5 node carries: the font family names it
 * sets, its text alignment, the short labels it shows (heading, button,
 * title) and the HTML inside a code module. Like Saddle_Divi_Lint_Facts it
 * answers null when it cannot tell, so rules skip.
 *
 * Shapes, from Divi 5.10.1 source:
 *   text align   <attr>.decoration.font.font.desktop.value.textAlign (Font.php),
 *                <attr>.decoration.bodyFont.body.font.desktop.value.textAlign,
 *                module.advanced.text.text.desktop.value.orientation (Text.php)
 *   labels       title.innerContent.desktop.value (a string),
 *                button.innerContent.desktop.value.text
 *   code         content.innerContent.desktop.value (divi/code, divi/fullwidth-code)
 */
class Saddle_Divi_Text_Facts {

	use Saddle_Divi_Attr_Resolution;

	/**
	 * Families that are icon fonts, not type.
	 */
	const ICON_FONTS = array( 'etmodules', 'fontawesome', 'font awesome', 'font awesome 5 free', 'font awesome 5 brands', 'font awesome 6 free', 'font awesome 6 brands', 'dashicons' );

	/**
	 * Generic or keyword values that name no family of their own.
	 */
	const GENERIC = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-sans-serif', 'ui-serif', 'ui-monospace', 'inherit', 'initial', 'unset', 'default', 'none' );

	/**
	 * Symbols that look like emoji but are ordinary typography (check, cross,
	 * star) unless the emoji selector follows them.
	 */
	const TYPOGRAPHIC = array( 0x2605, 0x2606, 0x2713, 0x2714, 0x2717, 0x2718 );

	/**
	 * Tags that build a layout rather than a small embed.
	 */
	const STRUCTURAL_TAGS = array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav' );

	/**
	 * The comparison key of a font family, or null when it names the site's
	 * own font (a token), an icon font or a generic family.
	 *
	 * @param string $family Family value as stored.
	 * @return string|null
	 */
	public static function font_key( $family ) {
		$family = trim( (string) $family );
		if ( '' === $family || 0 === strpos( $family, '$variable(' ) || 0 === stripos( $family, 'var(' ) || 0 === strpos( $family, '--' ) ) {
			return null;
		}
		$first = strtolower( trim( (string) strtok( $family, ',' ), " \t\n\r\0\x0B\"'" ) );
		if ( '' === $first || in_array( $first, self::GENERIC, true ) || in_array( $first, self::ICON_FONTS, true ) ) {
			return null;
		}
		return $first;
	}

	/**
	 * Every font family the node's own attrs set, with its path. Tokens
	 * (the site's font variables) and icon and generic families are left out.
	 *
	 * @param array $node Raw block array.
	 * @return array[] Each { key, family, path }.
	 */
	public function font_families( array $node ) {
		$found = array();
		$walk  = function ( $value, array $path ) use ( &$walk, &$found ) {
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( $value as $key => $child ) {
				$here = array_merge( $path, array( (string) $key ) );
				if ( 'family' === $key && is_string( $child ) && in_array( 'decoration', $path, true ) ) {
					$key_name = self::font_key( $child );
					if ( null !== $key_name ) {
						$found[] = array(
							'key'    => $key_name,
							'family' => trim( $child, " \t\n\r\0\x0B\"'" ),
							'path'   => implode( '.', $here ),
						);
					}
					continue;
				}
				$walk( $child, $here );
			}
		};
		foreach ( $this->attrs( $node ) as $name => $attr ) {
			$walk( $attr, array( (string) $name ) );
		}
		return $found;
	}

	/**
	 * The node's own text alignment, normalized, or null when it sets none.
	 *
	 * @param array $node Raw block array.
	 * @return string|null left, center, right or justify.
	 */
	public function text_align( array $node ) {
		$paths = array(
			array( 'decoration', 'font', 'font', 'desktop', 'value', 'textAlign' ),
			array( 'decoration', 'bodyFont', 'body', 'font', 'desktop', 'value', 'textAlign' ),
			array( 'advanced', 'text', 'text', 'desktop', 'value', 'orientation' ),
		);
		$align = null;
		foreach ( $paths as $path ) {
			$align = $this->first_at_path( $node, $path );
			if ( is_string( $align ) ) {
				break;
			}
		}
		if ( ! is_string( $align ) ) {
			return null;
		}
		$align = strtolower( str_replace( array( 'force_', 'force' ), '', trim( $align ) ) );
		return in_array( $align, array( 'left', 'center', 'right', 'justify' ), true ) ? $align : null;
	}

	/**
	 * The short texts the node shows as a heading, a button or a label.
	 *
	 * @param array $node Raw block array.
	 * @return array[] Each { kind: heading|button|label, text }.
	 */
	public function labels( array $node ) {
		$attrs  = $this->attrs( $node );
		$type   = (string) $node['blockName'];
		$labels = array();

		$title = isset( $attrs['title'] ) ? $this->path( $attrs['title'], array( 'innerContent', 'desktop', 'value' ) ) : null;
		if ( is_string( $title ) && '' !== trim( wp_strip_all_tags( $title ) ) ) {
			$labels[] = array(
				'kind' => 'divi/heading' === $type ? 'heading' : 'label',
				'text' => trim( wp_strip_all_tags( $title ) ),
			);
		}

		$button = isset( $attrs['button'] ) ? $this->path( $attrs['button'], array( 'innerContent', 'desktop', 'value' ) ) : null;
		$button = is_array( $button ) && isset( $button['text'] ) ? $button['text'] : $button;
		if ( is_string( $button ) && '' !== trim( wp_strip_all_tags( $button ) ) ) {
			$labels[] = array(
				'kind' => 'button',
				'text' => trim( wp_strip_all_tags( $button ) ),
			);
		}

		$content = isset( $attrs['content'] ) ? $this->path( $attrs['content'], array( 'innerContent', 'desktop', 'value' ) ) : null;
		if ( 'divi/text' === $type && is_string( $content ) && preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $m ) ) {
			foreach ( $m[1] as $heading ) {
				$text = trim( wp_strip_all_tags( $heading ) );
				if ( '' !== $text ) {
					$labels[] = array(
						'kind' => 'heading',
						'text' => $text,
					);
				}
			}
		}
		return $labels;
	}

	/**
	 * The emoji a text starts or ends with, used as an icon, or null.
	 *
	 * @param string $text Plain text.
	 * @return string|null
	 */
	public static function edge_emoji( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! preg_match( '/^(\X)/u', $text, $first ) || ! preg_match( '/(\X)$/u', $text, $last ) ) {
			return null;
		}
		foreach ( array( $first[1], $last[1] ) as $cluster ) {
			if ( self::is_emoji( $cluster ) ) {
				return $cluster;
			}
		}
		return null;
	}

	/**
	 * Whether one grapheme cluster is an emoji.
	 *
	 * @param string $cluster One grapheme cluster.
	 * @return bool
	 */
	private static function is_emoji( $cluster ) {
		if ( ! preg_match_all( '/./u', $cluster, $chars ) ) {
			return false;
		}
		$codes    = array_map( array( __CLASS__, 'code_point' ), $chars[0] );
		$selector = in_array( 0xFE0F, $codes, true );
		$code     = $codes[0];
		if ( in_array( $code, self::TYPOGRAPHIC, true ) ) {
			return $selector;
		}
		return ( $code >= 0x1F300 && $code <= 0x1FAFF )
			|| ( $code >= 0x1F1E6 && $code <= 0x1F1FF )
			|| ( $code >= 0x1F000 && $code <= 0x1F2FF )
			|| ( $code >= 0x2600 && $code <= 0x27BF )
			|| in_array( $code, array( 0x2B50, 0x2B55, 0x2B1B, 0x2B1C, 0x231A, 0x231B ), true )
			|| ( $code >= 0x23E9 && $code <= 0x23FA )
			|| ( $selector && $code > 0x7F );
	}

	/**
	 * The code point of one UTF-8 character, without mbstring.
	 *
	 * @param string $char One UTF-8 character.
	 * @return int
	 */
	private static function code_point( $char ) {
		$bytes = array_values( (array) unpack( 'C*', (string) $char ) );
		switch ( count( $bytes ) ) {
			case 1:
				return (int) $bytes[0];
			case 2:
				return ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
			case 3:
				return ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
			case 4:
				return ( ( $bytes[0] & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
		}
		return 0;
	}

	/**
	 * The HTML a code module holds.
	 *
	 * @param array $node Raw block array.
	 * @return string|null Null when the node is not a code module.
	 */
	public function code_content( array $node ) {
		if ( ! in_array( (string) $node['blockName'], array( 'divi/code', 'divi/fullwidth-code' ), true ) ) {
			return null;
		}
		$attrs   = $this->attrs( $node );
		$content = isset( $attrs['content'] ) ? $this->path( $attrs['content'], array( 'innerContent', 'desktop', 'value' ) ) : null;
		return is_string( $content ) ? $content : '';
	}

	/**
	 * What layout markup a piece of code HTML holds, or null when it is a
	 * small snippet or an embed (an iframe or a provider's script with at
	 * most a few wrapper tags and no style or table).
	 *
	 * @param string $html Code module content.
	 * @return array|null { style: bool, table: bool, structural: int }.
	 */
	public static function code_layout( $html ) {
		$html = (string) $html;
		if ( strlen( trim( $html ) ) < 200 ) {
			return null;
		}
		$facts = array(
			'style'      => (bool) preg_match( '/<style\b/i', $html ),
			'table'      => (bool) preg_match( '/<table\b/i', $html ),
			'structural' => preg_match_all( '/<(' . implode( '|', self::STRUCTURAL_TAGS ) . ')\b/i', $html ),
		);
		$embed = preg_match( '/<iframe\b/i', $html ) || preg_match( '/<script\b[^>]*\bsrc\s*=/i', $html );
		if ( $embed && ! $facts['style'] && ! $facts['table'] && $facts['structural'] <= 3 ) {
			return null;
		}
		if ( ! $facts['style'] && ! $facts['table'] && $facts['structural'] < 3 ) {
			return null;
		}
		return $facts;
	}
}
