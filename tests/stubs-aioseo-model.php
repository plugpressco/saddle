<?php
/**
 * Thin stub of AIOSEO's Post model, namespaced to match the real class.
 *
 * AIOSEO v4+ stores per-post SEO in its own {prefix}aioseo_posts table
 * behind \AIOSEO\Plugin\Common\Models\Post — getPost() returns an unsaved
 * model with defaults when no row exists; save() owns row creation and
 * caches (contract confirmed by live introspection against AIOSEO 5.0.0.1
 * on divi-dev, and matching Waggle's proven aioseo-writer). This stub is a
 * property bag with that find-or-build/save shape, enough for unit coverage
 * of the field mapping, the robots default-switch logic, and the keyphrases
 * focus handling. Live behavior is verified separately with real AIOSEO.
 *
 * @package Saddle
 */

namespace AIOSEO\Plugin\Common\Models {
	if ( ! class_exists( __NAMESPACE__ . '\\Post' ) ) {
		#[\AllowDynamicProperties]
		class Post {
			public $post_id;

			// The columns the integration touches, at their real defaults
			// (robots_default true = inherit site settings; twitter mirrors OG).
			public $title                    = '';
			public $description              = '';
			public $keyphrases               = null;
			public $canonical_url            = '';
			public $og_title                 = '';
			public $og_description           = '';
			public $og_image_type            = 'default';
			public $og_image_custom_url      = '';
			public $twitter_use_og           = true;
			public $twitter_title            = '';
			public $twitter_description      = '';
			public $twitter_image_type       = 'default';
			public $twitter_image_custom_url = '';
			public $pillar_content           = false;
			public $robots_default           = true;
			public $robots_noindex           = false;
			public $robots_nofollow          = false;
			public $robots_noarchive         = false;
			public $robots_nosnippet         = false;
			public $robots_noimageindex      = false;

			private static $store = array();

			public static function getPost( $post_id ) {
				if ( ! isset( self::$store[ $post_id ] ) ) {
					$model          = new self();
					$model->post_id = $post_id;
					self::$store[ $post_id ] = $model;
				}
				return self::$store[ $post_id ];
			}

			public function save() {
				self::$store[ $this->post_id ] = $this;
			}

			/** Test helper: wipe the in-memory table between tests. */
			public static function reset_store() {
				self::$store = array();
			}
		}
	}
}
