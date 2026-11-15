<?php
/**
 * The Divi module catalog: what each module is for, where it sits, and what
 * it may hold.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the catalog divi-list-modules and the context bundle serve, from
 * the same module.json index the schema reads.
 *
 * Divi's own module.json files carry no description, so core modules get a
 * hand-kept one-line purpose; a module pack's line comes from its module.json
 * "description" when it has one, else its title.
 */
class Saddle_Divi_Catalog {

	/**
	 * Containers that hold any content module. module.json leaves their
	 * childrenName empty, as it does for modules that hold nothing.
	 * divi/dropdown renders its children as its panel (DropdownModule.php).
	 */
	const ANY_CHILD = array( 'divi/column', 'divi/column-inner', 'divi/group', 'divi/dropdown' );

	/**
	 * The full catalog, sorted by type, filtered by a search when given.
	 *
	 * Each entry: type, title, purpose, group, container, child, and
	 * children (types, or "*" for any content module) on a container.
	 *
	 * @param string $search Words that must all appear in the type, title,
	 *                       purpose or group. Empty for everything.
	 * @return array[]
	 */
	public static function modules( $search = '' ) {
		$parents  = array();
		$entries  = array();
		$purposes = self::purposes();
		// The cached summaries carry the few module.json facts used here, so
		// listing every module keeps no decoded module.json in memory.
		foreach ( Saddle_Divi_Schema::index() as $type => $file ) {
			$summary = Saddle_Divi_Schema::summary( $type );
			if ( ! is_array( $summary ) ) {
				continue;
			}
			$children = self::children( $type, $summary );
			foreach ( $children as $child ) {
				$parents[ $child ] = $type;
			}
			$title = isset( $summary['title'] ) && '' !== $summary['title'] ? $summary['title'] : $type;
			if ( isset( $purposes[ $type ] ) ) {
				$purpose = $purposes[ $type ];
			} elseif ( isset( $summary['description'] ) ) {
				$purpose = $summary['description'];
			} else {
				$purpose = $title;
			}
			$entry = array(
				'type'      => $type,
				'title'     => $title,
				'purpose'   => $purpose,
				'group'     => self::group( $type, (string) $file ),
				'container' => (bool) $children,
				'child'     => isset( $summary['category'] ) && 'child-module' === $summary['category'],
			);
			if ( $children ) {
				$entry['children'] = $children;
			}
			$entries[ $type ] = $entry;
		}

		// A child module sits in its parent's group (an accordion item with
		// the accordion), unless a pack owns it.
		foreach ( $entries as $type => $entry ) {
			if ( $entry['child'] && isset( $parents[ $type ], $entries[ $parents[ $type ] ] ) && 0 !== strpos( $entry['group'], 'pack:' ) ) {
				$entries[ $type ]['group'] = $entries[ $parents[ $type ] ]['group'];
			}
		}

		ksort( $entries );
		$entries = array_values( $entries );
		return '' === trim( (string) $search ) ? $entries : self::search( $entries, (string) $search );
	}

	/**
	 * The catalog trimmed for the context bundle, which rides every session:
	 * no purpose line, and container and child flags only when true.
	 *
	 * @return array[]
	 */
	public static function compact() {
		$out = array();
		foreach ( self::modules() as $entry ) {
			$row = array(
				'type'  => $entry['type'],
				'title' => $entry['title'],
				'group' => $entry['group'],
			);
			if ( isset( $entry['children'] ) ) {
				$row['children'] = $entry['children'];
			}
			if ( $entry['child'] ) {
				$row['child'] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * What a module may hold: its childrenName, else its childModuleName
	 * (rows declare their column that way), else "*" for the containers that
	 * take any content module. Empty for a module that holds nothing.
	 *
	 * @param string $type Module type.
	 * @param array  $json Its module.json, or its Saddle_Divi_Schema::summary().
	 * @return string[]
	 */
	public static function children( $type, array $json ) {
		if ( ! empty( $json['childrenName'] ) && is_array( $json['childrenName'] ) ) {
			return array_values( array_map( 'strval', $json['childrenName'] ) );
		}
		if ( ! empty( $json['childModuleName'] ) && is_string( $json['childModuleName'] ) ) {
			return array( $json['childModuleName'] );
		}
		return in_array( $type, self::ANY_CHILD, true ) ? array( '*' ) : array();
	}

	/**
	 * Keep the entries whose type, title, purpose or group holds every word.
	 *
	 * @param array[] $entries Catalog entries.
	 * @param string  $search  Search words.
	 * @return array[]
	 */
	private static function search( array $entries, $search ) {
		$words = array_filter( preg_split( '/\s+/', strtolower( trim( $search ) ) ) );
		$hits  = array();
		foreach ( $entries as $entry ) {
			$haystack = strtolower( $entry['type'] . ' ' . $entry['title'] . ' ' . $entry['purpose'] . ' ' . $entry['group'] );
			foreach ( $words as $word ) {
				if ( false === strpos( $haystack, $word ) ) {
					continue 2;
				}
			}
			$hits[] = $entry;
		}
		return $hits;
	}

	/**
	 * The catalog group: "pack: <plugin folder>" for a module pack (or
	 * "pack: <namespace>" for one found outside the plugins folder), "shop"
	 * for Divi's WooCommerce modules, else the hand-kept group.
	 *
	 * @param string $type Module type.
	 * @param string $file Its module.json path.
	 * @return string
	 */
	private static function group( $type, $file ) {
		$plugins = defined( 'WP_PLUGIN_DIR' ) ? wp_normalize_path( WP_PLUGIN_DIR ) . '/' : '';
		$file    = wp_normalize_path( $file );
		if ( '' !== $plugins && 0 === strpos( $file, $plugins ) ) {
			return 'pack: ' . strtok( substr( $file, strlen( $plugins ) ), '/' );
		}
		if ( 0 !== strpos( $type, 'divi/' ) ) {
			return 'pack: ' . strtok( $type, '/' );
		}
		if ( 'divi/shop' === $type || 0 === strpos( $type, 'divi/woocommerce-' ) ) {
			return 'shop';
		}
		foreach ( self::groups() as $group => $types ) {
			if ( in_array( $type, $types, true ) ) {
				return $group;
			}
		}
		return 'text';
	}

	/**
	 * Core modules by group. Child modules follow their parent.
	 *
	 * @return array<string,string[]>
	 */
	private static function groups() {
		return array(
			'layout'     => array( 'divi/section', 'divi/row', 'divi/row-inner', 'divi/column', 'divi/column-inner', 'divi/group', 'divi/group-carousel', 'divi/global-layout', 'divi/canvas-portal', 'divi/dropdown', 'divi/divider', 'divi/code', 'divi/fullwidth-code' ),
			'media'      => array( 'divi/image', 'divi/fullwidth-image', 'divi/gallery', 'divi/video', 'divi/video-slider', 'divi/audio', 'divi/slider', 'divi/fullwidth-slider', 'divi/map', 'divi/fullwidth-map', 'divi/lottie', 'divi/svg', 'divi/before-after-image', 'divi/instagram-feed', 'divi/icon' ),
			'forms'      => array( 'divi/contact-form', 'divi/contact-form-7', 'divi/signup', 'divi/login', 'divi/search' ),
			'posts'      => array( 'divi/blog', 'divi/portfolio', 'divi/filterable-portfolio', 'divi/fullwidth-portfolio', 'divi/post-slider', 'divi/fullwidth-post-slider', 'divi/post-title', 'divi/fullwidth-post-title', 'divi/post-content', 'divi/fullwidth-post-content', 'divi/post-nav', 'divi/post-filter', 'divi/comments' ),
			'navigation' => array( 'divi/menu', 'divi/fullwidth-menu', 'divi/breadcrumbs', 'divi/social-media-follow', 'divi/sidebar', 'divi/table-of-contents', 'divi/link' ),
		);
	}

	/**
	 * One line per core module, saying what it is for.
	 *
	 * @return array<string,string>
	 */
	private static function purposes() {
		return array(
			'divi/accordion'                   => __( 'Stack of collapsible panels; holds accordion items.', 'saddle' ),
			'divi/accordion-item'              => __( 'One panel of an accordion: a title and its content.', 'saddle' ),
			'divi/audio'                       => __( 'Audio player with cover image, title and artist.', 'saddle' ),
			'divi/before-after-image'          => __( 'Two images compared with a slider.', 'saddle' ),
			'divi/blog'                        => __( 'List or grid of posts from a query.', 'saddle' ),
			'divi/blurb'                       => __( 'Icon or image with a title and short text: the classic feature card.', 'saddle' ),
			'divi/breadcrumbs'                 => __( 'Breadcrumb trail to the current page.', 'saddle' ),
			'divi/button'                      => __( 'A call-to-action button.', 'saddle' ),
			'divi/canvas-portal'               => __( 'Shows another canvas of this page in place.', 'saddle' ),
			'divi/circle-counter'              => __( 'Animated circle that fills to a percentage.', 'saddle' ),
			'divi/code'                        => __( 'Raw HTML, CSS or script. Not for layout.', 'saddle' ),
			'divi/column'                      => __( 'Vertical slot inside a row; holds content modules.', 'saddle' ),
			'divi/column-inner'                => __( 'Column inside an inner row.', 'saddle' ),
			'divi/comments'                    => __( 'The post\'s comments and comment form.', 'saddle' ),
			'divi/contact-field'               => __( 'One field of a contact form.', 'saddle' ),
			'divi/contact-form'                => __( 'Contact form that emails its messages; holds its fields.', 'saddle' ),
			'divi/contact-form-7'              => __( 'Shows and styles a Contact Form 7 form.', 'saddle' ),
			'divi/countdown-timer'             => __( 'Countdown to a date and time.', 'saddle' ),
			'divi/counter'                     => __( 'One bar of a bar counter.', 'saddle' ),
			'divi/counters'                    => __( 'Animated progress bars; holds bar counters.', 'saddle' ),
			'divi/cta'                         => __( 'Call-to-action band with title, text and button.', 'saddle' ),
			'divi/divider'                     => __( 'Horizontal line or spacer.', 'saddle' ),
			'divi/dropdown'                    => __( 'Panel of modules that opens on hover or click.', 'saddle' ),
			'divi/filterable-portfolio'        => __( 'Project grid with category filter buttons.', 'saddle' ),
			'divi/fullwidth-code'              => __( 'Raw code across a fullwidth section.', 'saddle' ),
			'divi/fullwidth-header'            => __( 'Hero banner with title, text, buttons and image.', 'saddle' ),
			'divi/fullwidth-image'             => __( 'Image across a fullwidth section.', 'saddle' ),
			'divi/fullwidth-map'               => __( 'Google map across a fullwidth section; holds map pins.', 'saddle' ),
			'divi/fullwidth-menu'              => __( 'Navigation menu across a fullwidth section.', 'saddle' ),
			'divi/fullwidth-portfolio'         => __( 'Carousel or grid of projects across a fullwidth section.', 'saddle' ),
			'divi/fullwidth-post-content'      => __( 'The post\'s own content, in a Theme Builder template.', 'saddle' ),
			'divi/fullwidth-post-slider'       => __( 'Slider of posts across a fullwidth section.', 'saddle' ),
			'divi/fullwidth-post-title'        => __( 'The post\'s title and meta across a fullwidth section.', 'saddle' ),
			'divi/fullwidth-slider'            => __( 'Slider across a fullwidth section; holds slides.', 'saddle' ),
			'divi/gallery'                     => __( 'Image gallery as a grid or a slider.', 'saddle' ),
			'divi/global-layout'               => __( 'A Library layout kept in sync everywhere it is used. Not built by hand.', 'saddle' ),
			'divi/group'                       => __( 'Box that groups modules inside a column, with its own layout.', 'saddle' ),
			'divi/group-carousel'              => __( 'Carousel whose slides are groups.', 'saddle' ),
			'divi/heading'                     => __( 'A heading (h1 to h6).', 'saddle' ),
			'divi/icon'                        => __( 'One icon, optionally linked.', 'saddle' ),
			'divi/icon-list'                   => __( 'List with an icon per item; holds icon list items.', 'saddle' ),
			'divi/icon-list-item'              => __( 'One item of an icon list.', 'saddle' ),
			'divi/image'                       => __( 'A single image.', 'saddle' ),
			'divi/instagram-feed'              => __( 'Posts from a connected Instagram account.', 'saddle' ),
			'divi/link'                        => __( 'A text link with an optional icon.', 'saddle' ),
			'divi/login'                       => __( 'Login form.', 'saddle' ),
			'divi/lottie'                      => __( 'Lottie animation from a JSON file.', 'saddle' ),
			'divi/map'                         => __( 'Google map; holds map pins.', 'saddle' ),
			'divi/map-pin'                     => __( 'One pin on a map.', 'saddle' ),
			'divi/menu'                        => __( 'Navigation menu with logo, cart and search icons.', 'saddle' ),
			'divi/number-counter'              => __( 'Number that counts up when shown.', 'saddle' ),
			'divi/portfolio'                   => __( 'Grid or list of projects.', 'saddle' ),
			'divi/post-content'                => __( 'The post\'s own content, in a Theme Builder template.', 'saddle' ),
			'divi/post-filter'                 => __( 'Filter controls for a loop on the page; holds filter items.', 'saddle' ),
			'divi/post-filter-item'            => __( 'One filter control of a post filter.', 'saddle' ),
			'divi/post-nav'                    => __( 'Pagination links for the main query or a loop on the page.', 'saddle' ),
			'divi/post-slider'                 => __( 'Slider of posts.', 'saddle' ),
			'divi/post-title'                  => __( 'The post\'s title, meta and featured image.', 'saddle' ),
			'divi/pricing-table'               => __( 'One plan of a pricing table.', 'saddle' ),
			'divi/pricing-tables'              => __( 'Side-by-side pricing plans; holds pricing tables.', 'saddle' ),
			'divi/row'                         => __( 'Horizontal layout inside a section; holds columns.', 'saddle' ),
			'divi/row-inner'                   => __( 'Row inside a column of a specialty section.', 'saddle' ),
			'divi/search'                      => __( 'Search form.', 'saddle' ),
			'divi/section'                     => __( 'Top-level page band. Pages are a stack of sections.', 'saddle' ),
			'divi/sidebar'                     => __( 'The widgets of one widget area.', 'saddle' ),
			'divi/signup'                      => __( 'Email opt-in form for a mailing list provider.', 'saddle' ),
			'divi/signup-custom-field'         => __( 'One extra field of an email opt-in form.', 'saddle' ),
			'divi/slide'                       => __( 'One slide of a slider.', 'saddle' ),
			'divi/slider'                      => __( 'Slider of full slides with text and buttons; holds slides.', 'saddle' ),
			'divi/social-media-follow'         => __( 'Row of social network icons; holds social networks.', 'saddle' ),
			'divi/social-media-follow-network' => __( 'One social network icon.', 'saddle' ),
			'divi/svg'                         => __( 'An inline SVG graphic.', 'saddle' ),
			'divi/tab'                         => __( 'One tab of a tabs module.', 'saddle' ),
			'divi/table-of-contents'           => __( 'Table of contents built from the page\'s headings.', 'saddle' ),
			'divi/tabs'                        => __( 'Tabbed content; holds tabs.', 'saddle' ),
			'divi/team-member'                 => __( 'Person card with photo, name, role and social links.', 'saddle' ),
			'divi/testimonial'                 => __( 'Quote with author attribution.', 'saddle' ),
			'divi/text'                        => __( 'Rich text content.', 'saddle' ),
			'divi/timeline'                    => __( 'Timeline of events; holds timeline items.', 'saddle' ),
			'divi/timeline-item'               => __( 'One event of a timeline.', 'saddle' ),
			'divi/toggle'                      => __( 'One collapsible content panel.', 'saddle' ),
			'divi/tooltip'                     => __( 'Popover text shown on hover or click of its parent module.', 'saddle' ),
			'divi/video'                       => __( 'Embedded or uploaded video.', 'saddle' ),
			'divi/video-slider'                => __( 'Slider of videos; holds video slider items.', 'saddle' ),
			'divi/video-slider-item'           => __( 'One video of a video slider.', 'saddle' ),
		);
	}
}
