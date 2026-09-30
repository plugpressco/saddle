<?php
/**
 * Every Saddle admin area, told as plain data.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The AREA body behind `GET /modules`, `GET /modules/{key}` and
 * `saddle/list-modules`: the registry's pages with their tabs, status line,
 * setup progress, tool counts and settings summary, every callable already
 * resolved. One builder, so the admin screens and an agent read the same
 * thing.
 */
class Saddle_Modules_View {

	/**
	 * Every area, in menu order.
	 *
	 * @return array[]
	 */
	public static function all() {
		$areas = array();
		foreach ( array_keys( Saddle_Modules::areas() ) as $key ) {
			$areas[] = self::one( $key );
		}

		return $areas;
	}

	/**
	 * One area, or null when the key names none.
	 *
	 * @param string $key Area key.
	 * @return array|null
	 */
	public static function one( $key ) {
		$areas = Saddle_Modules::areas();
		if ( ! isset( $areas[ $key ] ) ) {
			return null;
		}

		$area   = $areas[ $key ];
		$module = ! empty( $area['module'] );

		$tabs = array();
		foreach ( $area['tabs'] as $tab => $label ) {
			$tabs[] = array(
				'key'       => $tab,
				'label'     => $label,
				'admin_url' => Saddle_Modules::url( $key, $tab ),
			);
		}

		$status = $module ? Saddle_Modules::status( $key ) : null;
		$tasks  = $module ? Saddle_Modules::setup( $key ) : null;
		$schema = $module ? Saddle_Settings_Registry::schema( $key ) : null;

		return array(
			'key'       => $key,
			'kind'      => $module ? 'module' : 'core',
			'title'     => $area['title'],
			'product'   => ! empty( $area['product'] ) ? $area['product'] : ( $module ? $area['title'] : 'Saddle' ),
			'version'   => ! empty( $area['version'] ) ? $area['version'] : ( $module ? '' : SADDLE_VERSION ),
			'summary'   => self::summary( $key, $area ),
			'admin_url' => Saddle_Modules::url( $key ),
			'tabs'      => $tabs,
			'state'     => $status ? $status['state'] : null,
			'line'      => $status ? $status['line'] : '',
			'setup'     => $tasks ? self::setup( $tasks ) : null,
			'tools'     => self::tools( $key, $module ),
			'settings'  => $schema ? array(
				'fields'         => count( $schema['fields'] ),
				'agent_writable' => Saddle_Settings_Guard::agent_writable( $key, $schema ),
			) : null,
		);
	}

	/**
	 * One sentence an agent can repeat to the owner.
	 *
	 * @param string $key  Area key.
	 * @param array  $area Registry area.
	 * @return string
	 */
	private static function summary( $key, array $area ) {
		if ( ! empty( $area['summary'] ) ) {
			return $area['summary'];
		}

		$core = array(
			'home'        => __( 'What your connected apps have been doing, and what is left to set up.', 'saddle' ),
			'connections' => __( 'Connect apps, and choose what they are allowed to do.', 'saddle' ),
			'context'     => __( 'What every connected app knows about this site: your instructions, skills and memory.', 'saddle' ),
			'settings'    => __( 'Advanced options for Saddle.', 'saddle' ),
		);

		return isset( $core[ $key ] )
			? $core[ $key ]
			/* translators: %s: module name. */
			: sprintf( __( '%s is installed.', 'saddle' ), $area['title'] );
	}

	/**
	 * Setup progress.
	 *
	 * @param array[] $tasks Tasks from Saddle_Modules::setup().
	 * @return array{done:int,total:int,tasks:array[]}
	 */
	private static function setup( array $tasks ) {
		return array(
			'done'  => count( wp_list_filter( $tasks, array( 'done' => true ) ) ),
			'total' => count( $tasks ),
			'tasks' => $tasks,
		);
	}

	/**
	 * How many tools an area owns, and how many of them this user can call
	 * now. A module owns `saddle/{key}-*`; Home owns the rest of Saddle's own.
	 *
	 * @param string $key    Area key.
	 * @param bool   $module Whether it is a module.
	 * @return array{total:int,usable:int}
	 */
	private static function tools( $key, $module ) {
		$counts = array(
			'total'  => 0,
			'usable' => 0,
		);
		if ( ! $module && 'home' !== $key ) {
			return $counts;
		}

		$wrapped = array_merge( array_keys( Saddle_Modules::modules() ), Saddle_Integrations::FIRST_PARTY );

		foreach ( array_keys( wp_get_abilities() ) as $name ) {
			if ( 0 !== strpos( $name, 'saddle/' ) ) {
				continue;
			}

			$short = substr( $name, 7 );
			if ( $module ) {
				$mine = 0 === strpos( $short, $key . '-' );
			} else {
				$mine = 0 !== strpos( $short, 'rank-math-' ) ? ! self::has_prefix( $short, $wrapped ) : true;
			}
			if ( ! $mine ) {
				continue;
			}

			++$counts['total'];
			if ( Saddle_Capabilities::is_callable_now( $name ) ) {
				++$counts['usable'];
			}
		}

		return $counts;
	}

	/**
	 * Whether a tool's short name starts with one of the wrapped keys.
	 *
	 * @param string   $short Short tool name.
	 * @param string[] $keys  Integration keys.
	 * @return bool
	 */
	private static function has_prefix( $short, array $keys ) {
		foreach ( $keys as $key ) {
			if ( 0 === strpos( $short, $key . '-' ) ) {
				return true;
			}
		}

		return false;
	}
}
