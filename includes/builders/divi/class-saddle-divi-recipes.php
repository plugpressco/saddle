<?php
/**
 * Divi bodies for free Saddle's section recipes.
 *
 * Returns the same six section blueprints (hero, features, pricing,
 * testimonials, CTA, FAQ) as Divi 5 authoring node trees ({type, fields,
 * attrs, children}), so `get-section-recipe` speaks the same recipe
 * vocabulary on a Divi site as on a block theme.
 *
 * Default-token, structure-first: recipes use PURPOSE-BUILT core modules
 * (divi/cta, divi/accordion, divi/pricing-tables, divi/testimonial — the
 * same "purpose-built beats hand-stacked" rule the skill teaches) and the
 * minimal tasteful attrs that model the design bar: section rhythm, a
 * width-capped-AND-CENTERED text column, a filled primary button, ONE
 * featured pricing plan. Colors reference Divi's stable default Global
 * Data tokens (var(--gcid-primary-color) ships with every Divi 5 site);
 * the agent swaps in the site's own tokens after insert.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Divi 5 section-recipe bodies.
 */
class Saddle_Divi_Recipes {

	/**
	 * The primary-accent token every Divi 5 site defines.
	 */
	const ACCENT = 'var(--gcid-primary-color)';

	/**
	 * The Divi node tree for a recipe name, or null if unknown.
	 *
	 * @param string $name Recipe name.
	 * @return array[]|null
	 */
	public static function tree( $name ) {
		switch ( $name ) {
			case 'hero':
				return array(
					self::section(
						array(
							self::heading(
								__( 'A clear, benefit-led headline', 'saddle' ),
								array(
									'title.decoration.font.font.desktop.value' => array(
										'size'      => '48px',
										'weight'    => '700',
										'textAlign' => 'center',
									),
								)
							),
							// The centering model: a width-capped text column
							// is ALSO centered — never pinned left.
							self::text(
								__( 'One supporting sentence that says who it is for and why it matters.', 'saddle' ),
								array(
									'module.decoration.sizing.desktop.value'  => array(
										'maxWidth'  => '640px',
										'alignment' => 'center',
									),
									'content.decoration.bodyFont.body.font.desktop.value' => array( 'textAlign' => 'center' ),
								)
							),
							self::filled_button( __( 'Get Started', 'saddle' ) ),
							// The secondary action stays unstyled on purpose —
							// primary filled, secondary quiet.
							self::button( __( 'See How It Works', 'saddle' ) ),
						)
					),
				);

			case 'features':
				return array(
					self::section_cols(
						array(
							self::blurb_col( __( 'First benefit', 'saddle' ) ),
							self::blurb_col( __( 'Second benefit', 'saddle' ) ),
							self::blurb_col( __( 'Third benefit', 'saddle' ) ),
						)
					),
				);

			case 'pricing':
				return array(
					self::section(
						array(
							array(
								'type'     => 'divi/pricing-tables',
								'children' => array(
									self::plan( __( 'Starter', 'saddle' ), '0', false ),
									// ONE featured plan — never three identical cards.
									self::plan( __( 'Pro', 'saddle' ), '29', true ),
									self::plan( __( 'Team', 'saddle' ), '99', false ),
								),
							),
						)
					),
				);

			case 'testimonials':
				return array(
					self::section_cols(
						array(
							self::testimonial_col(),
							self::testimonial_col(),
							self::testimonial_col(),
						)
					),
				);

			case 'cta':
				return array(
					self::section(
						array(
							array(
								'type'   => 'divi/cta',
								'fields' => array(
									'title'   => __( 'Ready to start?', 'saddle' ),
									'content' => __( 'A short line that removes the last bit of hesitation.', 'saddle' ),
									'button'  => array(
										'text'    => __( 'Get Started', 'saddle' ),
										'linkUrl' => '#',
									),
								),
								'attrs'  => array(
									'module.decoration.background.desktop.value.color' => self::ACCENT,
									'button.decoration.button.desktop.value.enable' => 'on',
								),
							),
						)
					),
				);

			case 'faq':
				return array(
					self::section(
						array(
							self::heading( __( 'Frequently asked questions', 'saddle' ) ),
							array(
								'type'     => 'divi/accordion',
								'children' => array(
									self::faq_item( __( 'A common question?', 'saddle' ) ),
									self::faq_item( __( 'Another common question?', 'saddle' ) ),
									self::faq_item( __( 'A third common question?', 'saddle' ) ),
								),
							),
						)
					),
				);
		}

		return null;
	}

	/* ---- module + container builders ---- */

	/**
	 * A heading module.
	 *
	 * @param string $title Heading text.
	 * @param array  $attrs Optional raw attrs merged onto the node.
	 * @return array
	 */
	private static function heading( $title, array $attrs = array() ) {
		$node = array(
			'type'   => 'divi/heading',
			'fields' => array( 'title' => $title ),
		);
		if ( $attrs ) {
			$node['attrs'] = $attrs;
		}
		return $node;
	}

	/**
	 * A text module.
	 *
	 * @param string $html  Body markup.
	 * @param array  $attrs Optional raw attrs merged onto the node.
	 * @return array
	 */
	private static function text( $html, array $attrs = array() ) {
		$node = array(
			'type'   => 'divi/text',
			'fields' => array( 'content' => $html ),
		);
		if ( $attrs ) {
			$node['attrs'] = $attrs;
		}
		return $node;
	}

	/**
	 * A button module with a placeholder link.
	 *
	 * @param string $label Button text.
	 * @return array
	 */
	private static function button( $label ) {
		return array(
			'type'   => 'divi/button',
			'fields' => array(
				'button' => array(
					'text'    => $label,
					'linkUrl' => '#',
				),
			),
		);
	}

	/**
	 * The primary action: FILLED (enable on — ghost otherwise) on the accent token.
	 *
	 * @param string $label Button text.
	 * @return array
	 */
	private static function filled_button( $label ) {
		$node          = self::button( $label );
		$node['attrs'] = array(
			'button.decoration.button.desktop.value.enable' => 'on',
			'button.decoration.background.desktop.value.color' => self::ACCENT,
			'button.decoration.font.font.desktop.value.color' => '#ffffff',
		);
		return $node;
	}

	/**
	 * A section wrapping a single row + column holding the given modules.
	 *
	 * @param array $modules Module nodes for the single column.
	 * @return array
	 */
	private static function section( array $modules ) {
		return array(
			'type'     => 'divi/section',
			'attrs'    => self::section_rhythm(),
			'children' => array(
				array(
					'type'     => 'divi/row',
					'children' => array(
						array(
							'type'     => 'divi/column',
							'children' => $modules,
						),
					),
				),
			),
		);
	}

	/**
	 * A section whose single row holds the given columns.
	 *
	 * @param array $columns Column nodes for the row.
	 * @return array
	 */
	private static function section_cols( array $columns ) {
		return array(
			'type'     => 'divi/section',
			'attrs'    => self::section_rhythm(),
			'children' => array(
				array(
					'type'     => 'divi/row',
					'children' => $columns,
				),
			),
		);
	}

	/** The shared vertical rhythm every recipe section carries. */
	private static function section_rhythm() {
		return array(
			'module.decoration.spacing.desktop.value.padding' => array(
				'top'    => '96px',
				'bottom' => '96px',
			),
		);
	}

	/**
	 * A feature column: divi/blurb — the purpose-built card, never a hand-stack.
	 *
	 * @param string $title Blurb title.
	 * @return array
	 */
	private static function blurb_col( $title ) {
		return array(
			'type'     => 'divi/column',
			'children' => array(
				array(
					'type'   => 'divi/blurb',
					'fields' => array(
						'title'   => $title,
						'content' => __( 'One or two sentences describing this benefit in plain language.', 'saddle' ),
					),
				),
			),
		);
	}

	/**
	 * One pricing plan; the featured one gets Divi's own featured treatment.
	 *
	 * @param string $plan     Plan name.
	 * @param string $price    Price figure (currency and period come separately).
	 * @param bool   $featured Whether this is the highlighted plan.
	 * @return array
	 */
	private static function plan( $plan, $price, $featured ) {
		$node = array(
			'type'   => 'divi/pricing-table',
			'fields' => array(
				'title'             => $plan,
				'currencyFrequency' => array(
					'currency' => '$',
					'per'      => __( 'mo', 'saddle' ),
				),
				'price'             => $price,
				'content'           => '<ul><li>' . __( 'What this plan includes', 'saddle' ) . '</li><li>' . __( 'A second included item', 'saddle' ) . '</li><li>' . __( 'A third included item', 'saddle' ) . '</li></ul>',
				'button'            => array(
					'text'    => __( 'Choose Plan', 'saddle' ),
					'linkUrl' => '#',
				),
			),
		);
		if ( $featured ) {
			$node['attrs'] = array( 'module.advanced.featured.desktop.value' => 'on' );
		}
		return $node;
	}

	/** A testimonial column: the purpose-built divi/testimonial module. */
	private static function testimonial_col() {
		return array(
			'type'     => 'divi/column',
			'children' => array(
				array(
					'type'   => 'divi/testimonial',
					'fields' => array(
						'content'  => __( 'A specific, believable sentence about the result they got.', 'saddle' ),
						'author'   => __( 'Name', 'saddle' ),
						'jobTitle' => __( 'Role', 'saddle' ),
						'company'  => array( 'text' => __( 'Company', 'saddle' ) ),
					),
				),
			),
		);
	}

	/**
	 * One FAQ entry: a real accordion item, editable as such in the VB.
	 *
	 * @param string $question The question shown as the accordion title.
	 * @return array
	 */
	private static function faq_item( $question ) {
		return array(
			'type'   => 'divi/accordion-item',
			'fields' => array(
				'title'   => $question,
				'content' => __( 'A clear, direct answer in one or two sentences.', 'saddle' ),
			),
		);
	}
}
