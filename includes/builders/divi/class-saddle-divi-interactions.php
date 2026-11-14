<?php
/**
 * Divi 5 interactions: the stored entry and the two marks it needs.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and removes a Divi 5 interaction ("click this, show that") in a
 * page tree, in the exact shape the Visual Builder stores. Read from Divi
 * 5.10.1 source, not rendered here:
 *
 *  - The entry lives on the trigger module, in
 *    module.decoration.interactions.desktop.value.interactions[] (desktop
 *    only; InteractionsScriptData::generate_data()). The Visual Builder
 *    writes { id, enableInteraction, trigger, effect, triggerClass,
 *    target { targetClass, label, moduleId, targetType }, timeDelay } and,
 *    for attribute effects, attributeName and attributeValue.
 *  - The trigger module also gets module.decoration.interactionTrigger, the
 *    id after "et-interaction-trigger-". Module::render() prints it as
 *    data-interaction-trigger, which the front-end script finds the module
 *    by (it falls back to the first entry's triggerClass, letters and
 *    digits only).
 *  - The target module gets module.decoration.interactionTarget, the id
 *    after "et-interaction-target-". Module::render() prints it as
 *    data-interaction-target, and the script adds the target class to it.
 *  - Effect names are split by the script on their prefix: toggle, add or
 *    remove, then Visibility or Attribute; scrollToElement stands alone.
 *    An attribute effect runs only with both a name and a value, and the
 *    value joins the attribute's space-separated list.
 *  - Ids are 10 characters of 0-9a-z, like the Visual Builder's.
 */
class Saddle_Divi_Interactions {

	/**
	 * Triggers this writes (Divi also has breakpointEnter and breakpointExit,
	 * which need a breakpoint name; not offered here).
	 */
	const TRIGGERS = array( 'click', 'mouseEnter', 'mouseExit', 'viewportEnter', 'viewportExit', 'load' );

	/**
	 * Effects whose stored shape is confirmed.
	 */
	const EFFECTS = array( 'toggleVisibility', 'addVisibility', 'removeVisibility', 'toggleAttribute', 'addAttribute', 'removeAttribute', 'scrollToElement' );

	/**
	 * Attribute names an interaction may not change: event handlers and
	 * addresses would let page content run code or send people elsewhere.
	 */
	const DENIED_ATTRIBUTES = array( 'href', 'src', 'srcdoc', 'action', 'formaction', 'xlink:href', 'style' );

	const TRIGGER_PREFIX = 'et-interaction-trigger-';
	const TARGET_PREFIX  = 'et-interaction-target-';

	/**
	 * Where the entries live under a module's attrs.
	 */
	const LIST_PATH = array( 'module', 'decoration', 'interactions', 'desktop', 'value', 'interactions' );

	/**
	 * Whether a module type declares the interactions group in its
	 * module.json; null when its module.json cannot be read.
	 *
	 * @param string $type Module type.
	 * @return bool|null
	 */
	public static function supports( $type ) {
		$json = Saddle_Divi_Schema::module_json( (string) $type );
		if ( ! is_array( $json ) ) {
			return null;
		}
		$decoration = isset( $json['attributes']['module']['settings']['decoration'] ) ? $json['attributes']['module']['settings']['decoration'] : null;
		return is_array( $decoration ) && array_key_exists( 'interactions', $decoration );
	}

	/**
	 * Check an add request and build its entry, without the ids.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error { trigger, effect, timeDelay, attributeName?, attributeValue?, adminLabel? }.
	 */
	public static function spec( array $input ) {
		$trigger = isset( $input['trigger'] ) ? (string) $input['trigger'] : 'click';
		$effect  = isset( $input['effect'] ) ? (string) $input['effect'] : 'toggleVisibility';
		if ( ! in_array( $trigger, self::TRIGGERS, true ) ) {
			/* translators: %s: comma-separated trigger names. */
			return new WP_Error( 'saddle_bad_trigger', sprintf( __( 'Unknown trigger. Use one of: %s.', 'saddle' ), implode( ', ', self::TRIGGERS ) ) );
		}
		if ( ! in_array( $effect, self::EFFECTS, true ) ) {
			/* translators: %s: comma-separated effect names. */
			return new WP_Error( 'saddle_bad_effect', sprintf( __( 'Unknown effect. Use one of: %s.', 'saddle' ), implode( ', ', self::EFFECTS ) ) );
		}

		$delay = self::delay( isset( $input['delay'] ) ? $input['delay'] : 0 );
		if ( null === $delay ) {
			return new WP_Error( 'saddle_bad_delay', __( 'The delay is a number of milliseconds from 0 to 60000, such as 300 or "300ms".', 'saddle' ) );
		}

		$spec = array(
			'trigger'   => $trigger,
			'effect'    => $effect,
			'timeDelay' => $delay,
		);

		if ( false !== strpos( $effect, 'Attribute' ) ) {
			$name  = isset( $input['attribute_name'] ) ? strtolower( trim( (string) $input['attribute_name'] ) ) : '';
			$value = isset( $input['attribute_value'] ) ? sanitize_text_field( (string) $input['attribute_value'] ) : '';
			if ( ! preg_match( '/^[a-z_:][a-z0-9_:-]{0,199}$/', $name ) || '' === $value ) {
				return new WP_Error( 'saddle_bad_attribute', __( 'An attribute effect needs "attribute_name" (letters, digits, "-", "_" or ":", such as "class" or "data-state") and a non-empty "attribute_value" (such as "is-open").', 'saddle' ) );
			}
			if ( 0 === strpos( $name, 'on' ) || in_array( $name, self::DENIED_ATTRIBUTES, true ) ) {
				/* translators: %s: attribute name. */
				return new WP_Error( 'saddle_denied_attribute', sprintf( __( 'An interaction may not change the "%s" attribute. Use "class" or a "data-" or "aria-" attribute.', 'saddle' ), $name ) );
			}
			$spec['attributeName']  = $name;
			$spec['attributeValue'] = $value;
		}

		$label = isset( $input['label'] ) ? sanitize_text_field( (string) $input['label'] ) : '';
		if ( '' !== $label ) {
			$spec['adminLabel'] = $label;
		}
		return $spec;
	}

	/**
	 * Write an interaction into a tree: the entry on the trigger, appended
	 * to any it already has, and the two marks.
	 *
	 * @param array[] $tree            Page tree.
	 * @param string  $trigger_address Trigger module address.
	 * @param string  $target_address  Target module address (may equal the trigger).
	 * @param array   $spec            From spec().
	 * @return array|WP_Error { tree, item, patches: address => attrs written }.
	 */
	public static function add( array $tree, $trigger_address, $target_address, array $spec ) {
		$trigger = Saddle_Divi_Tree::get( $tree, $trigger_address );
		$target  = Saddle_Divi_Tree::get( $tree, $target_address );
		if ( ! $trigger || ! $target ) {
			/* translators: %s: node address. */
			return new WP_Error( 'saddle_bad_address', sprintf( __( 'No module at address %s.', 'saddle' ), $trigger ? $target_address : $trigger_address ) );
		}

		$type     = (string) $trigger['blockName'];
		$supports = self::supports( $type );
		if ( true !== $supports ) {
			return new WP_Error(
				'saddle_no_interactions',
				null === $supports
					/* translators: %s: module type. */
					? sprintf( __( 'SaddleWP cannot read the settings of %s, so it cannot confirm the module takes interactions. Use a Divi module as the trigger.', 'saddle' ), $type )
					/* translators: %s: module type. */
					: sprintf( __( '%s has no interactions setting, so Divi would never start one from it. Use a module that has one (divi-get-module-schema lists its groups) as the trigger, such as a button, text or section.', 'saddle' ), $type )
			);
		}

		$attrs      = is_array( $trigger['attrs'] ) ? $trigger['attrs'] : array();
		$items      = self::items( $attrs );
		$trigger_id = self::trigger_id( $attrs, $items );
		$target_id  = self::target_id( $trigger_address === $target_address ? $attrs : ( is_array( $target['attrs'] ) ? $target['attrs'] : array() ) );

		$item = array(
			'id'                => self::new_id(),
			'enableInteraction' => 'on',
			'trigger'           => $spec['trigger'],
			'effect'            => $spec['effect'],
			'triggerClass'      => self::TRIGGER_PREFIX . $trigger_id,
			'target'            => array(
				'targetClass' => self::TARGET_PREFIX . $target_id,
				'label'       => self::label( $target ),
				'moduleId'    => '',
				'targetType'  => 'module',
			),
			'timeDelay'         => $spec['timeDelay'],
		);
		foreach ( array( 'attributeName', 'attributeValue', 'adminLabel' ) as $key ) {
			if ( isset( $spec[ $key ] ) ) {
				$item[ $key ] = $spec[ $key ];
			}
		}
		$items[] = $item;

		$trigger_patch = array(
			'module' => array(
				'decoration' => array(
					'interactions'       => array( 'desktop' => array( 'value' => array( 'interactions' => $items ) ) ),
					'interactionTrigger' => $trigger_id,
				),
			),
		);
		$target_patch  = array( 'module' => array( 'decoration' => array( 'interactionTarget' => $target_id ) ) );

		$patches = array();
		if ( $trigger_address === $target_address ) {
			$patches[ $trigger_address ] = array_replace_recursive( $trigger_patch, $target_patch );
		} else {
			$patches[ $trigger_address ] = $trigger_patch;
			$patches[ $target_address ]  = $target_patch;
		}

		$next = self::apply( $tree, $patches );
		return is_wp_error( $next ) ? $next : array(
			'tree'    => $next,
			'item'    => $item,
			'patches' => $patches,
		);
	}

	/**
	 * Remove one interaction by id. The trigger loses its mark when no entry
	 * is left on it; the target loses its mark when no entry on the page
	 * points at it any more (as the Visual Builder does).
	 *
	 * @param array[] $tree Page tree.
	 * @param string  $id   Interaction id.
	 * @return array|WP_Error { tree, item, address, changed: string[] }.
	 */
	public static function remove( array $tree, $id ) {
		foreach ( Saddle_Divi_Tree::flatten( $tree ) as $entry ) {
			$address = (string) $entry['address'];
			$items   = self::items( $entry['attrs'] );
			foreach ( $items as $index => $item ) {
				if ( ! isset( $item['id'] ) || (string) $item['id'] !== (string) $id ) {
					continue;
				}
				unset( $items[ $index ] );
				$node  = Saddle_Divi_Tree::get( $tree, $address );
				$attrs = $entry['attrs'];
				if ( $items ) {
					$attrs['module']['decoration']['interactions']['desktop']['value']['interactions'] = array_values( $items );
				} else {
					unset( $attrs['module']['decoration']['interactions'], $attrs['module']['decoration']['interactionTrigger'] );
				}
				$node['attrs'] = $attrs;
				$next          = Saddle_Divi_Tree::replace( $tree, $address, $node );
				if ( is_wp_error( $next ) ) {
					return $next;
				}
				$changed = array( $address );
				$class   = isset( $item['target']['targetClass'] ) ? (string) $item['target']['targetClass'] : '';
				return array(
					'tree'    => self::release_target( $next, $class, $changed ),
					'item'    => $item,
					'address' => $address,
					'changed' => $changed,
				);
			}
		}
		/* translators: %s: interaction id. */
		return new WP_Error( 'saddle_no_interaction', sprintf( __( 'No interaction with id %s on this page. Each id is in the divi-add-interaction answer and in the trigger module\'s attrs, at module.decoration.interactions.desktop.value.interactions.', 'saddle' ), $id ) );
	}

	/**
	 * Clear a target mark that no entry on the page uses any more.
	 *
	 * @param array[]  $tree    Page tree.
	 * @param string   $target_class The removed entry's target class.
	 * @param string[] $changed Addresses changed so far (appended to).
	 * @return array[]
	 */
	private static function release_target( array $tree, $target_class, array &$changed ) {
		if ( 0 !== strpos( $target_class, self::TARGET_PREFIX ) ) {
			return $tree;
		}
		$entries = Saddle_Divi_Tree::flatten( $tree );
		foreach ( $entries as $entry ) {
			foreach ( self::items( $entry['attrs'] ) as $item ) {
				if ( isset( $item['target']['targetClass'] ) && $target_class === $item['target']['targetClass'] ) {
					return $tree;
				}
			}
		}
		$target_id = substr( $target_class, strlen( self::TARGET_PREFIX ) );
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['attrs']['module']['decoration']['interactionTarget'] ) || $target_id !== $entry['attrs']['module']['decoration']['interactionTarget'] ) {
				continue;
			}
			$node = Saddle_Divi_Tree::get( $tree, (string) $entry['address'] );
			unset( $node['attrs']['module']['decoration']['interactionTarget'] );
			$next = Saddle_Divi_Tree::replace( $tree, (string) $entry['address'], $node );
			if ( ! is_wp_error( $next ) ) {
				$tree      = $next;
				$changed[] = (string) $entry['address'];
			}
		}
		return $tree;
	}

	/**
	 * Merge each address's patch into its node.
	 *
	 * @param array[] $tree    Page tree.
	 * @param array   $patches Address => attrs to merge.
	 * @return array[]|WP_Error
	 */
	private static function apply( array $tree, array $patches ) {
		foreach ( $patches as $address => $patch ) {
			$node          = Saddle_Divi_Tree::get( $tree, (string) $address );
			$node['attrs'] = array_replace_recursive( is_array( $node['attrs'] ) ? $node['attrs'] : array(), $patch );
			// The entry list is written whole: array_replace_recursive would
			// keep stored items past the new list's end.
			if ( isset( $patch['module']['decoration']['interactions'] ) ) {
				$node['attrs']['module']['decoration']['interactions'] = $patch['module']['decoration']['interactions'];
			}
			$tree = Saddle_Divi_Tree::replace( $tree, (string) $address, $node );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
		}
		return $tree;
	}

	/**
	 * The interaction entries a module's attrs hold.
	 *
	 * @param array $attrs Module attrs.
	 * @return array[]
	 */
	public static function items( array $attrs ) {
		$cursor = $attrs;
		foreach ( self::LIST_PATH as $key ) {
			if ( ! is_array( $cursor ) || ! isset( $cursor[ $key ] ) ) {
				return array();
			}
			$cursor = $cursor[ $key ];
		}
		return is_array( $cursor ) ? array_values( array_filter( $cursor, 'is_array' ) ) : array();
	}

	/**
	 * The trigger id a module already uses, or a new one. One id per
	 * trigger module, so every entry on it shares one data attribute.
	 *
	 * @param array   $attrs Trigger attrs.
	 * @param array[] $items Its entries.
	 * @return string
	 */
	private static function trigger_id( array $attrs, array $items ) {
		$stored = isset( $attrs['module']['decoration']['interactionTrigger'] ) ? $attrs['module']['decoration']['interactionTrigger'] : '';
		if ( is_string( $stored ) && preg_match( '/^[A-Za-z0-9]+$/', $stored ) ) {
			return $stored;
		}
		foreach ( $items as $item ) {
			$class = isset( $item['triggerClass'] ) ? (string) $item['triggerClass'] : '';
			if ( preg_match( '/^' . self::TRIGGER_PREFIX . '([A-Za-z0-9]+)$/', $class, $m ) ) {
				return $m[1];
			}
		}
		return self::new_id();
	}

	/**
	 * The target id a module already carries, or a new one.
	 *
	 * @param array $attrs Target attrs.
	 * @return string
	 */
	private static function target_id( array $attrs ) {
		$stored = isset( $attrs['module']['decoration']['interactionTarget'] ) ? $attrs['module']['decoration']['interactionTarget'] : '';
		return is_string( $stored ) && preg_match( '/^[A-Za-z0-9-]+$/', $stored ) ? $stored : self::new_id();
	}

	/**
	 * The target's name in the Visual Builder's interaction list.
	 *
	 * @param array $node Target block.
	 * @return string
	 */
	private static function label( array $node ) {
		$label = isset( $node['attrs']['module']['meta']['adminLabel']['desktop']['value'] ) ? $node['attrs']['module']['meta']['adminLabel']['desktop']['value'] : '';
		return is_string( $label ) && '' !== trim( $label ) ? sanitize_text_field( $label ) : (string) $node['blockName'];
	}

	/**
	 * A delay as Divi stores it ("300ms"), or null when out of range.
	 *
	 * @param mixed $delay Milliseconds, or "300ms" / "0.3s".
	 * @return string|null
	 */
	private static function delay( $delay ) {
		if ( is_int( $delay ) || ( is_string( $delay ) && ctype_digit( $delay ) ) ) {
			$ms = (int) $delay;
		} elseif ( is_string( $delay ) && preg_match( '/^(\d+(?:\.\d+)?)(ms|s)$/', trim( $delay ), $m ) ) {
			$ms = (int) round( 's' === $m[2] ? (float) $m[1] * 1000 : (float) $m[1] );
		} else {
			return null;
		}
		return $ms >= 0 && $ms <= 60000 ? $ms . 'ms' : null;
	}

	/**
	 * A new id: 10 characters of 0-9a-z.
	 *
	 * @return string
	 */
	public static function new_id() {
		$chars = '0123456789abcdefghijklmnopqrstuvwxyz';
		$id    = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$id .= $chars[ wp_rand( 0, 35 ) ];
		}
		return $id;
	}
}
