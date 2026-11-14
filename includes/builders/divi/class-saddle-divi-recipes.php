<?php
/**
 * Divi bodies for free Saddle's section recipes.
 *
 * Returns the same six section blueprints (hero, features, pricing,
 * testimonials, CTA, FAQ) as Divi 5 authoring node trees ({type, fields,
 * settings, attrs, columns, children}), so `get-section-recipe` speaks the
 * same recipe vocabulary on a Divi site as on a block theme.
 *
 * Structure first: purpose-built core modules (divi/cta, divi/accordion,
 * divi/pricing-tables, divi/testimonial, divi/blurb), every multi-column
 * row with its widths, a phone value where the desktop one would not fit,
 * h1 for the hero and h2 for every other section heading, the accent token
 * for colour, ONE featured pricing plan. Each recipe takes the user's
 * content (Saddle_Recipe_Content) in place of its placeholders.
 *
 * Another plugin adds Divi recipes through `saddle_divi_section_recipes`
 * (see extras()).
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
	 * @param string $name    Recipe name.
	 * @param array  $content Cleaned content (Saddle_Recipe_Content::clean()).
	 * @return array[]|null
	 */
	public static function tree( $name, array $content = array() ) {
		switch ( $name ) {
			case 'hero':
				return self::hero( $content );
			case 'features':
				return self::features( $content );
			case 'pricing':
				return self::pricing( $content );
			case 'testimonials':
				return self::testimonials( $content );
			case 'cta':
				return self::cta( $content );
			case 'faq':
				return self::faq( $content );
		}

		$extras = self::extras();
		if ( ! isset( $extras[ $name ] ) ) {
			return null;
		}
		$nodes = $extras[ $name ]['nodes'];
		if ( is_callable( $nodes ) ) {
			$nodes = call_user_func( $nodes, $content, $name );
		}
		return is_array( $nodes ) && $nodes ? array_values( $nodes ) : null;
	}

	/**
	 * The recipes other plugins add, as name => {title, description}, for
	 * the shared recipe list.
	 *
	 * @return array
	 */
	public static function extra_catalog() {
		$out = array();
		foreach ( self::extras() as $name => $entry ) {
			$out[ $name ] = array(
				'title'       => $entry['title'],
				'description' => $entry['description'],
			);
		}
		return $out;
	}

	/**
	 * Divi recipes added by other plugins.
	 *
	 * @return array name => {title, description, nodes}.
	 */
	private static function extras() {
		/**
		 * Filter: add Divi 5 section recipes to get-section-recipe and
		 * list-section-recipes on a Divi site.
		 *
		 * Each entry is keyed by its recipe name (lowercase letters, digits,
		 * "-" and "_"; the six built-in names cannot be replaced):
		 *
		 *   'logo-strip' => array(
		 *       'title'       => 'Logo strip',
		 *       'description' => 'One line of client logos.',
		 *       'nodes'       => callable|array,
		 *   )
		 *
		 * "nodes" is the section's authoring nodes, the format divi-set-page
		 * takes ({type, fields, settings, attrs, columns, children}), or a
		 * callable that returns them. The callable receives the cleaned
		 * content array (heading, text, buttons, items, plans, quotes,
		 * questions; only what the agent passed) and the recipe name, and
		 * puts that content in place of its placeholders.
		 *
		 * @param array $recipes Recipes added so far.
		 */
		$raw     = apply_filters( 'saddle_divi_section_recipes', array() );
		$builtin = array( 'hero', 'features', 'pricing', 'testimonials', 'cta', 'faq' );
		$out     = array();
		foreach ( (array) $raw as $name => $entry ) {
			$name = (string) $name;
			if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $name ) || in_array( $name, $builtin, true ) || ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry['title'] ) || ! isset( $entry['nodes'] ) || ( ! is_array( $entry['nodes'] ) && ! is_callable( $entry['nodes'] ) ) ) {
				continue;
			}
			$out[ $name ] = array(
				'title'       => (string) $entry['title'],
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
				'nodes'       => $entry['nodes'],
			);
		}
		return $out;
	}

	/* ---- the six recipes ---- */

	/**
	 * Hero: h1, a capped line, the main action filled and a quiet second
	 * one side by side.
	 *
	 * @param array $c Content.
	 * @return array[]
	 */
	private static function hero( array $c ) {
		$buttons = isset( $c['buttons'] ) ? $c['buttons'] : array(
			array( 'text' => __( 'Get Started', 'saddle' ) ),
			array( 'text' => __( 'See How It Works', 'saddle' ) ),
		);
		return array(
			Saddle_Divi_Recipe_Parts::section(
				array(
					Saddle_Divi_Recipe_Parts::row(
						array(
							Saddle_Divi_Recipe_Parts::column(
								array(
									Saddle_Divi_Recipe_Parts::heading( self::pick( $c, 'heading', __( 'A clear, benefit-led headline', 'saddle' ) ), 'h1' ),
									Saddle_Divi_Recipe_Parts::text( self::pick( $c, 'text', __( 'One supporting sentence that says who it is for and why it matters.', 'saddle' ) ) ),
									Saddle_Divi_Recipe_Parts::buttons( $buttons ),
								)
							),
						)
					),
				)
			),
		);
	}

	/**
	 * Features: a heading row, then the items as blurb cards in rows of at
	 * most four columns.
	 *
	 * @param array $c Content.
	 * @return array[]
	 */
	private static function features( array $c ) {
		$items = isset( $c['items'] ) ? $c['items'] : array(
			array( 'title' => __( 'First benefit', 'saddle' ) ),
			array( 'title' => __( 'Second benefit', 'saddle' ) ),
			array( 'title' => __( 'Third benefit', 'saddle' ) ),
		);
		$cards = array();
		foreach ( $items as $item ) {
			$cards[] = self::blurb( $item );
		}
		return array(
			Saddle_Divi_Recipe_Parts::section(
				array_merge(
					array( Saddle_Divi_Recipe_Parts::header_row( self::pick( $c, 'heading', __( 'What you get', 'saddle' ) ), self::pick( $c, 'text', '' ) ) ),
					self::card_rows( $cards )
				)
			),
		);
	}

	/**
	 * Pricing: a heading row, then Divi's pricing tables with one featured
	 * plan. The tables sit side by side and stack on phones (Divi 5.10.1
	 * prints a phone column direction with no gap; the recipe adds the gap).
	 *
	 * @param array $c Content.
	 * @return array[]
	 */
	private static function pricing( array $c ) {
		$plans = isset( $c['plans'] ) ? $c['plans'] : array(
			array(
				'name'  => __( 'Starter', 'saddle' ),
				'price' => '0',
			),
			array(
				'name'  => __( 'Pro', 'saddle' ),
				'price' => '29',
			),
			array(
				'name'  => __( 'Team', 'saddle' ),
				'price' => '99',
			),
		);

		// ONE featured plan, never three identical cards: the first one the
		// user marked, else the middle one.
		$featured = count( $plans ) > 1 ? intdiv( count( $plans ), 2 ) : -1;
		foreach ( $plans as $i => $plan ) {
			if ( ! empty( $plan['featured'] ) ) {
				$featured = $i;
				break;
			}
		}

		// A currency or period written on one plan holds for the others.
		$shared = array(
			'currency' => '',
			'period'   => '',
		);
		foreach ( $plans as $plan ) {
			foreach ( array_keys( $shared ) as $key ) {
				if ( '' === $shared[ $key ] && ! empty( $plan[ $key ] ) ) {
					$shared[ $key ] = (string) $plan[ $key ];
				}
			}
			if ( '' === $shared['currency'] && isset( $plan['price'] ) && preg_match( '/^(\D+?)\s*\d/u', (string) $plan['price'], $m ) ) {
				$shared['currency'] = trim( $m[1] );
			}
		}

		$tables = array();
		foreach ( array_values( $plans ) as $i => $plan ) {
			$tables[] = self::plan( $plan, $i === $featured, $shared );
		}

		return array(
			Saddle_Divi_Recipe_Parts::section(
				array(
					Saddle_Divi_Recipe_Parts::header_row( self::pick( $c, 'heading', __( 'Simple pricing', 'saddle' ) ), self::pick( $c, 'text', '' ) ),
					Saddle_Divi_Recipe_Parts::row(
						array(
							Saddle_Divi_Recipe_Parts::column(
								array(
									array(
										'type'     => 'divi/pricing-tables',
										'attrs'    => array(
											'module.decoration.layout@phone' => array(
												'flexDirection' => 'column',
												'rowGap' => '32px',
											),
										),
										'children' => $tables,
									),
								)
							),
						)
					),
				)
			),
		);
	}

	/**
	 * Testimonials: a heading row, then one quote per column.
	 *
	 * @param array $c Content.
	 * @return array[]
	 */
	private static function testimonials( array $c ) {
		$quotes = isset( $c['quotes'] ) ? $c['quotes'] : array( array(), array(), array() );
		$cards  = array();
		foreach ( $quotes as $quote ) {
			$fields = array(
				'content'  => self::pick( $quote, 'text', __( 'A specific, believable sentence about the result they got.', 'saddle' ) ),
				'author'   => self::pick( $quote, 'name', __( 'Name', 'saddle' ) ),
				'jobTitle' => self::pick( $quote, 'role', isset( $quote['name'] ) ? '' : __( 'Role', 'saddle' ) ),
				'company'  => array( 'text' => self::pick( $quote, 'company', isset( $quote['name'] ) ? '' : __( 'Company', 'saddle' ) ) ),
			);
			if ( '' === $fields['jobTitle'] ) {
				unset( $fields['jobTitle'] );
			}
			if ( '' === $fields['company']['text'] ) {
				unset( $fields['company'] );
			}
			$cards[] = array(
				'type'   => 'divi/testimonial',
				'fields' => $fields,
			);
		}
		return array(
			Saddle_Divi_Recipe_Parts::section(
				array_merge(
					array( Saddle_Divi_Recipe_Parts::header_row( self::pick( $c, 'heading', __( 'What people say', 'saddle' ) ), self::pick( $c, 'text', '' ) ) ),
					self::card_rows( $cards )
				)
			),
		);
	}

	/**
	 * Call to action: Divi's CTA module on the accent band, with text and a
	 * button in the colour that reads on it.
	 *
	 * @param array $c Content.
	 * @return array[]
	 */
	private static function cta( array $c ) {
		$button = isset( $c['buttons'][0] ) ? $c['buttons'][0] : array();
		$url    = isset( $button['url'] ) ? $button['url'] : '#';
		// The band is the accent; its text and the button take the colour
		// that reads on it, and the button's label takes the accent back.
		$ink   = Saddle_Divi_Recipe_Parts::on_accent();
		$label = '#ffffff' === $ink ? self::ACCENT : '#ffffff';
		return array(
			Saddle_Divi_Recipe_Parts::section(
				array(
					Saddle_Divi_Recipe_Parts::row(
						array(
							Saddle_Divi_Recipe_Parts::column(
								array(
									array(
										'type'   => 'divi/cta',
										'fields' => array(
											'title'   => self::pick( $c, 'heading', __( 'Ready to start?', 'saddle' ) ),
											'content' => self::pick( $c, 'text', __( 'A short line that removes the last bit of hesitation.', 'saddle' ) ),
											'button'  => array(
												'text'    => self::pick( $button, 'text', __( 'Get Started', 'saddle' ) ),
												'linkUrl' => '' === $url ? '#' : $url,
											),
										),
										'attrs'  => array(
											'module.decoration.background' => array( 'color' => self::ACCENT ),
											'module.decoration.spacing'    => array(
												'padding' => array(
													'top'  => '64px',
													'right' => '48px',
													'bottom' => '64px',
													'left' => '48px',
												),
											),
											'module.decoration.spacing@phone' => array(
												'padding' => array(
													'top'  => '48px',
													'right' => '24px',
													'bottom' => '48px',
													'left' => '24px',
												),
											),
											'title.decoration.font.font'   => array(
												'headingLevel' => 'h2',
												'color' => $ink,
											),
											'content.decoration.bodyFont.body.font' => array( 'color' => $ink ),
											'button.decoration.button'     => array( 'enable' => 'on' ),
											'button.decoration.background' => array( 'color' => $ink ),
											'button.decoration.font.font'  => array( 'color' => $label ),
										),
									),
								)
							),
						)
					),
				)
			),
		);
	}

	/**
	 * FAQ: a heading and Divi's accordion in a narrower, centred row.
	 *
	 * @param array $c Content.
	 * @return array[]
	 */
	private static function faq( array $c ) {
		$questions = isset( $c['questions'] ) ? $c['questions'] : array(
			array( 'question' => __( 'A common question?', 'saddle' ) ),
			array( 'question' => __( 'Another common question?', 'saddle' ) ),
			array( 'question' => __( 'A third common question?', 'saddle' ) ),
		);
		$items     = array();
		foreach ( $questions as $q ) {
			$items[] = array(
				'type'   => 'divi/accordion-item',
				'fields' => array(
					'title'   => self::pick( $q, 'question', __( 'A common question?', 'saddle' ) ),
					'content' => self::pick( $q, 'answer', __( 'A clear, direct answer in one or two sentences.', 'saddle' ) ),
				),
			);
		}

		$modules = array( Saddle_Divi_Recipe_Parts::heading( self::pick( $c, 'heading', __( 'Frequently asked questions', 'saddle' ) ), 'h2', 'left' ) );
		if ( isset( $c['text'] ) ) {
			$modules[] = Saddle_Divi_Recipe_Parts::text( $c['text'], 'left' );
		}
		$modules[] = array(
			'type'     => 'divi/accordion',
			'children' => $items,
		);

		return array(
			Saddle_Divi_Recipe_Parts::section(
				array(
					Saddle_Divi_Recipe_Parts::row(
						array( Saddle_Divi_Recipe_Parts::column( $modules ) ),
						array(
							'module.decoration.sizing' => array(
								'maxWidth'  => '800px',
								'alignment' => 'center',
							),
						)
					),
				)
			),
		);
	}

	/* ---- pieces ---- */

	/**
	 * A feature card: divi/blurb, the purpose-built card, never a
	 * hand-stack. Its title is an h3 under the section's h2 (Divi's default
	 * is h4).
	 *
	 * @param array $item {title, text, icon}.
	 * @return array
	 */
	private static function blurb( array $item ) {
		$fields = array(
			'title'   => self::pick( $item, 'title', __( 'A benefit', 'saddle' ) ),
			'content' => self::pick( $item, 'text', __( 'One or two sentences describing this benefit in plain language.', 'saddle' ) ),
		);
		if ( ! empty( $item['icon'] ) ) {
			$icon                = is_array( $item['icon'] ) ? $item['icon'] : array( 'unicode' => (string) $item['icon'] );
			$fields['imageIcon'] = array(
				'useIcon' => 'on',
				'icon'    => array_merge(
					array(
						'type'   => 'divi',
						'weight' => '400',
					),
					$icon
				),
			);
		}
		return array(
			'type'   => 'divi/blurb',
			'fields' => $fields,
			'attrs'  => array(
				'title.decoration.font.font' => array( 'headingLevel' => 'h3' ),
			),
		);
	}

	/**
	 * One pricing plan; the featured one gets Divi's own featured switch.
	 *
	 * @param array $plan     {name, price, currency, period, features, button}.
	 * @param bool  $featured Whether this is the highlighted plan.
	 * @param array $shared   The currency and period other plans gave.
	 * @return array
	 */
	private static function plan( array $plan, $featured, array $shared ) {
		$price    = self::pick( $plan, 'price', '0' );
		$currency = self::pick( $plan, 'currency', '' );
		// "$29" written as the price: the symbol is the currency.
		if ( '' === $currency && preg_match( '/^(\D+?)\s*(\d[\d.,]*)$/u', $price, $m ) ) {
			$currency = trim( $m[1] );
			$price    = $m[2];
		}
		$features = ! empty( $plan['features'] ) ? $plan['features'] : array(
			__( 'What this plan includes', 'saddle' ),
			__( 'A second included item', 'saddle' ),
			__( 'A third included item', 'saddle' ),
		);
		$list     = '';
		foreach ( $features as $feature ) {
			$list .= '<li>' . esc_html( $feature ) . '</li>';
		}
		$button = isset( $plan['button'] ) ? $plan['button'] : array();
		$url    = isset( $button['url'] ) ? $button['url'] : '#';

		$node = array(
			'type'   => 'divi/pricing-table',
			'fields' => array(
				'title'             => self::pick( $plan, 'name', __( 'Plan', 'saddle' ) ),
				'currencyFrequency' => array(
					'currency' => '' !== $currency ? $currency : ( '' !== $shared['currency'] ? $shared['currency'] : '$' ),
					'per'      => self::pick( $plan, 'period', '' !== $shared['period'] ? $shared['period'] : __( 'mo', 'saddle' ) ),
				),
				'price'             => $price,
				'content'           => '<ul>' . $list . '</ul>',
				'button'            => array(
					'text'    => self::pick( $button, 'text', __( 'Choose Plan', 'saddle' ) ),
					'linkUrl' => '' === $url ? '#' : $url,
				),
			),
		);
		return $featured ? Saddle_Divi_Recipe_Parts::setting( $node, 'module.advanced.featured', 'on' ) : $node;
	}

	/**
	 * Cards laid out in rows of at most four equal columns.
	 *
	 * @param array[] $cards Module nodes.
	 * @return array[] Row nodes.
	 */
	private static function card_rows( array $cards ) {
		$rows   = array();
		$offset = 0;
		foreach ( Saddle_Divi_Recipe_Parts::row_sizes( count( $cards ) ) as $size ) {
			$columns = array();
			foreach ( array_slice( $cards, $offset, $size ) as $card ) {
				$columns[] = Saddle_Divi_Recipe_Parts::column( array( $card ) );
			}
			$rows[]  = Saddle_Divi_Recipe_Parts::row( $columns );
			$offset += $size;
		}
		return $rows;
	}

	/**
	 * A content value, or the placeholder when it is missing or empty.
	 *
	 * @param array  $from    Content array.
	 * @param string $key     Key.
	 * @param string $fallback Placeholder.
	 * @return string
	 */
	private static function pick( array $from, $key, $fallback ) {
		return isset( $from[ $key ] ) && is_scalar( $from[ $key ] ) && '' !== (string) $from[ $key ] ? (string) $from[ $key ] : $fallback;
	}
}
