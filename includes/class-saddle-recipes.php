<?php
/**
 * Section recipes — curated, ready-to-insert blueprints for the common page
 * sections (hero, features, pricing, testimonials, CTA, FAQ).
 *
 * A recipe is a node tree in Saddle's authoring format ({type, content, attrs,
 * children}) with placeholder copy, so an agent inserts a well-structured
 * section and then swaps in real content + design tokens (from
 * get-design-system) instead of composing a layout from scratch. The free
 * plugin ships the Gutenberg (core-block) bodies; a page-builder addon returns
 * its own bodies for the same recipe names via the `saddle_section_recipe`
 * filter, so the recipe vocabulary is the same across builders.
 *
 * Recipes stay design-token-agnostic on purpose: they carry structure + copy +
 * heading levels, not site-specific color/size slugs (which would render as
 * "unset" on a site that doesn't define them). The response tells the agent to
 * apply tokens from get-design-system after inserting.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Curated section-recipe catalog + Gutenberg bodies.
 */
class Saddle_Recipes {

	/**
	 * The recipe catalog: name => { title, description }.
	 *
	 * @return array<string, array{title:string, description:string}>
	 */
	public static function catalog() {
		return array(
			'hero'         => array(
				'title'       => __( 'Hero', 'saddle' ),
				'description' => __( 'A headline, a supporting line, and a primary + secondary call to action.', 'saddle' ),
			),
			'features'     => array(
				'title'       => __( 'Features', 'saddle' ),
				'description' => __( 'A section heading over a three-column grid of feature blurbs.', 'saddle' ),
			),
			'pricing'      => array(
				'title'       => __( 'Pricing', 'saddle' ),
				'description' => __( 'Three pricing tiers, each with a name, price, feature list, and button.', 'saddle' ),
			),
			'testimonials' => array(
				'title'       => __( 'Testimonials', 'saddle' ),
				'description' => __( 'A heading over three customer quotes with attributions.', 'saddle' ),
			),
			'cta'          => array(
				'title'       => __( 'Call to action', 'saddle' ),
				'description' => __( 'A closing banner: a short headline, a line of copy, and a button.', 'saddle' ),
			),
			'faq'          => array(
				'title'       => __( 'FAQ', 'saddle' ),
				'description' => __( 'A heading over a list of question/answer pairs.', 'saddle' ),
			),
		);
	}

	/**
	 * The Gutenberg (core-block) node tree for a recipe, or null if unknown.
	 *
	 * @param string $name Recipe name.
	 * @return array[]|null
	 */
	public static function gutenberg( $name ) {
		switch ( $name ) {
			case 'hero':
				return array(
					self::group(
						array(
							self::heading( __( 'A clear, benefit-led headline', 'saddle' ), 1 ),
							self::paragraph( __( 'One supporting sentence that says who it is for and why it matters.', 'saddle' ) ),
							self::buttons( array( __( 'Get started', 'saddle' ), __( 'Learn more', 'saddle' ) ) ),
						)
					),
				);

			case 'features':
				return array(
					self::heading( __( 'What you get', 'saddle' ), 2 ),
					self::columns(
						array(
							self::feature_col( __( 'First benefit', 'saddle' ) ),
							self::feature_col( __( 'Second benefit', 'saddle' ) ),
							self::feature_col( __( 'Third benefit', 'saddle' ) ),
						)
					),
				);

			case 'pricing':
				return array(
					self::heading( __( 'Simple pricing', 'saddle' ), 2 ),
					self::columns(
						array(
							self::price_col( __( 'Starter', 'saddle' ), __( '$0', 'saddle' ) ),
							self::price_col( __( 'Pro', 'saddle' ), __( '$29', 'saddle' ) ),
							self::price_col( __( 'Team', 'saddle' ), __( '$99', 'saddle' ) ),
						)
					),
				);

			case 'testimonials':
				return array(
					self::heading( __( 'What people say', 'saddle' ), 2 ),
					self::columns(
						array(
							self::quote_col(),
							self::quote_col(),
							self::quote_col(),
						)
					),
				);

			case 'cta':
				return array(
					self::group(
						array(
							self::heading( __( 'Ready to start?', 'saddle' ), 2 ),
							self::paragraph( __( 'A short line that removes the last bit of hesitation.', 'saddle' ) ),
							self::buttons( array( __( 'Get started', 'saddle' ) ) ),
						)
					),
				);

			case 'faq':
				return array(
					self::heading( __( 'Frequently asked questions', 'saddle' ), 2 ),
					self::heading( __( 'A common question?', 'saddle' ), 3 ),
					self::paragraph( __( 'A clear, direct answer in one or two sentences.', 'saddle' ) ),
					self::heading( __( 'Another common question?', 'saddle' ), 3 ),
					self::paragraph( __( 'A clear, direct answer in one or two sentences.', 'saddle' ) ),
					self::heading( __( 'A third common question?', 'saddle' ), 3 ),
					self::paragraph( __( 'A clear, direct answer in one or two sentences.', 'saddle' ) ),
				);
		}

		return null;
	}

	/*
	 * ---- small node builders (keep the recipes above readable) ----
	 */

	/**
	 * A core/heading node.
	 *
	 * @param string $text  Heading text.
	 * @param int    $level Heading level (2-4).
	 * @return array
	 */
	private static function heading( $text, $level ) {
		return array(
			'type'    => 'core/heading',
			'content' => $text,
			'attrs'   => array( 'level' => $level ),
		);
	}

	/**
	 * A core/paragraph node.
	 *
	 * @param string $text Paragraph text.
	 * @return array
	 */
	private static function paragraph( $text ) {
		return array(
			'type'    => 'core/paragraph',
			'content' => $text,
		);
	}

	/**
	 * A core/buttons row with one placeholder-linked button per label.
	 *
	 * @param string[] $labels Button labels.
	 * @return array
	 */
	private static function buttons( array $labels ) {
		$buttons = array();
		foreach ( $labels as $label ) {
			$buttons[] = array(
				'type'    => 'core/button',
				'content' => $label,
				'attrs'   => array( 'url' => '#' ),
			);
		}
		return array(
			'type'     => 'core/buttons',
			'children' => $buttons,
		);
	}

	/**
	 * A core/group wrapper.
	 *
	 * @param array $children Child nodes.
	 * @return array
	 */
	private static function group( array $children ) {
		return array(
			'type'     => 'core/group',
			'children' => $children,
		);
	}

	/**
	 * A core/columns row.
	 *
	 * @param array $columns Column nodes.
	 * @return array
	 */
	private static function columns( array $columns ) {
		return array(
			'type'     => 'core/columns',
			'children' => $columns,
		);
	}

	/**
	 * A feature column: heading + placeholder blurb.
	 *
	 * @param string $title Feature title.
	 * @return array
	 */
	private static function feature_col( $title ) {
		return array(
			'type'     => 'core/column',
			'children' => array(
				self::heading( $title, 3 ),
				self::paragraph( __( 'One or two sentences describing this benefit in plain language.', 'saddle' ) ),
			),
		);
	}

	/**
	 * A pricing column: plan name, price, included-items list, CTA.
	 *
	 * @param string $plan  Plan name.
	 * @param string $price Price line.
	 * @return array
	 */
	private static function price_col( $plan, $price ) {
		return array(
			'type'     => 'core/column',
			'children' => array(
				self::heading( $plan, 3 ),
				self::paragraph( $price ),
				array(
					'type'     => 'core/list',
					'children' => array(
						array(
							'type'    => 'core/list-item',
							'content' => __( 'What this plan includes', 'saddle' ),
						),
						array(
							'type'    => 'core/list-item',
							'content' => __( 'A second included item', 'saddle' ),
						),
						array(
							'type'    => 'core/list-item',
							'content' => __( 'A third included item', 'saddle' ),
						),
					),
				),
				self::buttons( array( __( 'Choose plan', 'saddle' ) ) ),
			),
		);
	}

	/**
	 * A testimonial column: quote + attribution placeholders.
	 *
	 * @return array
	 */
	private static function quote_col() {
		return array(
			'type'     => 'core/column',
			'children' => array(
				self::paragraph( __( '"A specific, believable sentence about the result they got."', 'saddle' ) ),
				self::paragraph( __( '— Name, Role', 'saddle' ) ),
			),
		);
	}
}
