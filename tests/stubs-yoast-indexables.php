<?php
/**
 * Thin stub of Yoast SEO's Indexable repository/model, namespaced to match
 * the real classes.
 *
 * Yoast's term SEO title/description live on its Indexable model
 * (`Indexable_Repository::find_by_id_and_type( $term_id, 'term' )`), NOT
 * the legacy `WPSEO_Taxonomy_Meta` option — confirmed by reflection AND a
 * live round-trip against a real Yoast SEO 28.3 install on divi-dev (see
 * Saddle_Yoast_Seo's docblock). This stub mirrors just enough of that
 * API — find-or-build, `->title`/`->description`, `->save()` — for unit
 * coverage of the field mapping and the robots/length logic around it.
 *
 * @package Saddle
 */

namespace Yoast\WP\SEO\Models {
	if ( ! class_exists( __NAMESPACE__ . '\\Indexable' ) ) {
		class Indexable {
			public $object_id;
			public $object_type;
			public $title       = null;
			public $description = null;

			public function save() {
				\Saddle_Test_Yoast_Indexable_Store::save( $this );
			}
		}
	}
}

namespace Yoast\WP\SEO\Repositories {
	if ( ! class_exists( __NAMESPACE__ . '\\Indexable_Repository' ) ) {
		class Indexable_Repository {
			public function find_by_id_and_type( $id, $type ) {
				return \Saddle_Test_Yoast_Indexable_Store::find_or_build( $id, $type );
			}
		}
	}
}

namespace {
	if ( ! class_exists( 'Saddle_Test_Yoast_Indexable_Store' ) ) {
		/**
		 * In-memory store backing the Indexable stub — real Yoast persists
		 * to its own DB table; a plain static array is enough for one test
		 * run.
		 */
		class Saddle_Test_Yoast_Indexable_Store {
			private static $store = array();

			public static function find_or_build( $id, $type ) {
				$key = $type . ':' . $id;
				if ( ! isset( self::$store[ $key ] ) ) {
					$indexable              = new \Yoast\WP\SEO\Models\Indexable();
					$indexable->object_id   = $id;
					$indexable->object_type = $type;
					self::$store[ $key ]    = $indexable;
				}
				return self::$store[ $key ];
			}

			public static function save( $indexable ) {
				$key                 = $indexable->object_type . ':' . $indexable->object_id;
				self::$store[ $key ] = $indexable;
			}
		}
	}

	if ( ! function_exists( 'YoastSEO' ) ) {
		function YoastSEO() {
			static $instance = null;
			if ( null === $instance ) {
				$instance           = new stdClass();
				$instance->classes  = new class() {
					public function get( $class ) {
						if ( \Yoast\WP\SEO\Repositories\Indexable_Repository::class === $class ) {
							return new \Yoast\WP\SEO\Repositories\Indexable_Repository();
						}
						return null;
					}
				};
			}
			return $instance;
		}
	}
}
