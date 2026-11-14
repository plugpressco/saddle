<?php
/**
 * The node builders the Divi section recipes are made of.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small builders for Divi 5 authoring nodes ({type, fields, settings,
 * attrs, columns, children}) in the form the playbook teaches: style
 * groups written at their path without ".desktop.value", a phone value
 * with "@phone", and column widths with "columns" on the row.
 *
 * Sizes follow the shared design numbers (Saddle_Context::design_numbers()):
 * section padding 96px (64px on phones), a 56px hero and 36px section
 * headings (40px and 28px on phones), 18px body text in a 640px centred
 * column. Colours use the primary-colour token every Divi 5 site has; the
 * only raw colours are the white or near-black text that reads on it
 * (on_accent()), which have no default token.
 */
class Saddle_Divi_Recipe_Parts {

	/**
	 * The primary-accent token every Divi 5 site defines.
	 */
	const ACCENT = 'var(--gcid-primary-color)';

	/**
	 * The text colour that reads on the accent: white, or near-black when
	 * this site's accent is too light for white text (Divi's default blue
	 * gives white 2.75:1). White when the accent cannot be read.
	 *
	 * @return string
	 */
	public static function on_accent() {
		if ( ! class_exists( 'Saddle_Divi_Tokens' ) || ! class_exists( 'Saddle_Lint_Color' ) ) {
			return '#ffffff';
		}
		$accent = (string) Saddle_Divi_Tokens::resolve_color( self::ACCENT );
		$white  = Saddle_Lint_Color::contrast( '#ffffff', $accent );
		if ( null === $white || $white >= 4.5 ) {
			return '#ffffff';
		}
		$dark = Saddle_Lint_Color::contrast( '#111111', $accent );
		return null !== $dark && $dark > $white ? '#111111' : '#ffffff';
	}

	/**
	 * A section holding rows, with the shared vertical rhythm.
	 *
	 * @param array[] $rows  Row nodes.
	 * @param array   $attrs Extra attrs.
	 * @return array
	 */
	public static function section( array $rows, array $attrs = array() ) {
		return array(
			'type'     => 'divi/section',
			'attrs'    => array_merge(
				array(
					'module.decoration.spacing'       => array(
						'padding' => array(
							'top'    => '96px',
							'bottom' => '96px',
						),
					),
					'module.decoration.spacing@phone' => array(
						'padding' => array(
							'top'    => '64px',
							'bottom' => '64px',
						),
					),
				),
				$attrs
			),
			'children' => $rows,
		);
	}

	/**
	 * A row of columns. Two or more columns always get widths: without
	 * them Divi gives each column its full-width default and they stack.
	 *
	 * @param array[]    $columns Column nodes.
	 * @param array      $attrs   Row attrs.
	 * @param string|int $spec    "columns" value; null for equal columns.
	 * @return array
	 */
	public static function row( array $columns, array $attrs = array(), $spec = null ) {
		$row = array( 'type' => 'divi/row' );
		if ( count( $columns ) > 1 ) {
			$row['columns'] = null === $spec ? count( $columns ) : $spec;
		}
		if ( $attrs ) {
			$row['attrs'] = $attrs;
		}
		$row['children'] = $columns;
		return $row;
	}

	/**
	 * A column holding modules.
	 *
	 * @param array[] $modules Module nodes.
	 * @return array
	 */
	public static function column( array $modules ) {
		return array(
			'type'     => 'divi/column',
			'children' => $modules,
		);
	}

	/**
	 * A one-column row with a section heading and, when given, a line under
	 * it.
	 *
	 * @param string $heading Heading text.
	 * @param string $text    Intro text, '' for none.
	 * @param string $align   Text alignment.
	 * @return array
	 */
	public static function header_row( $heading, $text = '', $align = 'center' ) {
		$modules = array( self::heading( $heading, 'h2', $align ) );
		if ( '' !== $text ) {
			$modules[] = self::text( $text, $align );
		}
		return self::row( array( self::column( $modules ) ) );
	}

	/**
	 * A heading module: h1 for a hero, h2 for a section (Divi's own default
	 * is h1, which made every recipe heading a page title).
	 *
	 * @param string $title Heading text.
	 * @param string $level h1 or h2.
	 * @param string $align Text alignment.
	 * @return array
	 */
	public static function heading( $title, $level = 'h2', $align = 'center' ) {
		$hero = 'h1' === $level;
		return array(
			'type'   => 'divi/heading',
			'fields' => array( 'title' => $title ),
			'attrs'  => array(
				'title.decoration.font.font'       => array(
					'headingLevel' => $level,
					'size'         => $hero ? '56px' : '36px',
					'weight'       => '700',
					'lineHeight'   => $hero ? '1.1em' : '1.2em',
					'textAlign'    => $align,
				),
				'title.decoration.font.font@phone' => array( 'size' => $hero ? '40px' : '28px' ),
			),
		);
	}

	/**
	 * A text module. A centred one is also width-capped AND centred, never
	 * capped and pinned left.
	 *
	 * @param string $html  Body text.
	 * @param string $align Text alignment.
	 * @return array
	 */
	public static function text( $html, $align = 'center' ) {
		$attrs = array(
			'content.decoration.bodyFont.body.font' => array(
				'size'       => '18px',
				'lineHeight' => '1.6em',
				'textAlign'  => $align,
			),
		);
		if ( 'center' === $align ) {
			$attrs['module.decoration.sizing'] = array(
				'maxWidth'  => '640px',
				'alignment' => 'center',
			);
		}
		return array(
			'type'   => 'divi/text',
			'fields' => array( 'content' => $html ),
			'attrs'  => $attrs,
		);
	}

	/**
	 * The actions: a group laid out as a centred, wrapping row, so two
	 * buttons sit side by side and stack only when the screen is too
	 * narrow. The first is the main action (filled); the second stays
	 * quiet on purpose.
	 *
	 * @param array[] $buttons Each {text, url}.
	 * @return array
	 */
	public static function buttons( array $buttons ) {
		$children = array();
		foreach ( array_values( $buttons ) as $i => $button ) {
			$text       = isset( $button['text'] ) ? (string) $button['text'] : '';
			$url        = isset( $button['url'] ) ? (string) $button['url'] : '#';
			$children[] = 0 === $i ? self::filled_button( $text, $url ) : self::button( $text, $url );
		}
		return array(
			'type'     => 'divi/group',
			'attrs'    => array(
				'module.decoration.layout' => array(
					'display'        => 'flex',
					'flexDirection'  => 'row',
					'flexWrap'       => 'wrap',
					'justifyContent' => 'center',
					'alignItems'     => 'center',
					'columnGap'      => '16px',
					'rowGap'         => '16px',
				),
			),
			'children' => $children,
		);
	}

	/**
	 * A button module.
	 *
	 * @param string $text Label.
	 * @param string $url  Link.
	 * @return array
	 */
	public static function button( $text, $url = '#' ) {
		return array(
			'type'   => 'divi/button',
			'fields' => array(
				'button' => array(
					'text'    => $text,
					'linkUrl' => '' === $url ? '#' : $url,
				),
			),
		);
	}

	/**
	 * The main action: filled (the "enable" switch, or Divi draws an
	 * outline) on the accent token.
	 *
	 * @param string $text Label.
	 * @param string $url  Link.
	 * @return array
	 */
	public static function filled_button( $text, $url = '#' ) {
		$node          = self::button( $text, $url );
		$node['attrs'] = array(
			'button.decoration.button'     => array( 'enable' => 'on' ),
			'button.decoration.background' => array( 'color' => self::ACCENT ),
			'button.decoration.font.font'  => array( 'color' => self::on_accent() ),
		);
		return $node;
	}

	/**
	 * Set a module option the way the playbook teaches: through "settings"
	 * when this site's module.json lists it, else at its full attrs path
	 * (a site where SaddleWP cannot read the module's settings).
	 *
	 * @param array  $node  Node.
	 * @param string $id    Setting id, e.g. "module.advanced.featured".
	 * @param string $value Stored value.
	 * @return array
	 */
	public static function setting( array $node, $id, $value ) {
		if ( class_exists( 'Saddle_Divi_Settings' ) && Saddle_Divi_Settings::find( $node['type'], $id ) ) {
			$node['settings'][ $id ] = $value;
		} else {
			$node['attrs'][ $id . '.desktop.value' ] = $value;
		}
		return $node;
	}

	/**
	 * Split a count into rows of at most four, as even as possible
	 * (5 is 3 + 2, 7 is 4 + 3, 9 is 3 + 3 + 3).
	 *
	 * @param int $count Items.
	 * @return int[] Items per row.
	 */
	public static function row_sizes( $count ) {
		$count = max( 1, (int) $count );
		$rows  = (int) ceil( $count / 4 );
		$base  = intdiv( $count, $rows );
		$extra = $count % $rows;
		$sizes = array();
		for ( $i = 0; $i < $rows; $i++ ) {
			$sizes[] = $base + ( $i < $extra ? 1 : 0 );
		}
		return $sizes;
	}
}
