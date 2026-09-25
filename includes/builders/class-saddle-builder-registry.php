<?php
/**
 * The builder driver registry.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knows which builder drivers exist and which one owns a page. Drivers
 * register through the `saddle_builder_drivers` filter — the single
 * extension point Elementor/Bricks (and third parties) use later; the
 * ability layer and the lint/echo wiring resolve drivers here instead of
 * hardcoding a builder anywhere.
 */
class Saddle_Builder_Registry {

	/**
	 * Resolved drivers by slug, built once per request.
	 *
	 * @var array<string,Saddle_Builder_Driver>|null
	 */
	private static $drivers = null;

	/**
	 * All registered drivers, keyed by slug.
	 *
	 * @return array<string,Saddle_Builder_Driver>
	 */
	public static function all() {
		if ( null === self::$drivers ) {
			/**
			 * Filter the registered builder drivers.
			 *
			 * @param Saddle_Builder_Driver[] $drivers Driver instances.
			 */
			$candidates = (array) apply_filters( 'saddle_builder_drivers', array( new Saddle_Divi_Driver() ) );

			self::$drivers = array();
			foreach ( $candidates as $driver ) {
				if ( $driver instanceof Saddle_Builder_Driver ) {
					self::$drivers[ $driver->slug() ] = $driver;
				}
			}
		}
		return self::$drivers;
	}

	/**
	 * The driver registered under a slug.
	 *
	 * @param string $slug Driver slug, e.g. 'divi'.
	 * @return Saddle_Builder_Driver|null
	 */
	public static function get( $slug ) {
		$drivers = self::all();
		return isset( $drivers[ $slug ] ) ? $drivers[ $slug ] : null;
	}

	/**
	 * The driver whose builder label matches free Saddle's detected builder
	 * (`Saddle_Abilities::builder_signature()`), e.g. 'Divi 5'.
	 *
	 * @param string $builder_name Detected builder label.
	 * @return Saddle_Builder_Driver|null
	 */
	public static function for_builder( $builder_name ) {
		foreach ( self::all() as $driver ) {
			if ( $driver->builder_name() === (string) $builder_name ) {
				return $driver;
			}
		}
		return null;
	}

	/**
	 * The driver that owns a post's content, if any claims it.
	 *
	 * @param WP_Post $post The post.
	 * @return Saddle_Builder_Driver|null
	 */
	public static function for_post( WP_Post $post ) {
		foreach ( self::all() as $driver ) {
			if ( $driver->detect( $post ) ) {
				return $driver;
			}
		}
		return null;
	}

	/**
	 * Drop the resolved cache (tests re-filter drivers between cases).
	 */
	public static function reset() {
		self::$drivers = null;
	}
}
