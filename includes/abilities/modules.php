<?php
/**
 * The owner's admin, for agents: what is installed, what each setting means,
 * and the few settings an agent may change.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register saddle/list-modules, saddle/get-module-settings and
 * saddle/update-module-settings. Hooked to `wp_abilities_api_init`.
 */
function saddle_register_module_abilities() {
	wp_register_ability(
		'saddle/list-modules',
		array(
			'label'               => __( 'List Saddle pages and modules', 'saddle' ),
			'description'         => __( 'Lists every page of the owner\'s Saddle admin (Home, AI apps, Services, Context, Settings) and every installed Saddle module (for example Analytics), in menu order. Each entry has a one-sentence "summary" you can repeat to the owner, its "state" and status "line" where a module reports one, setup progress with each unfinished task, the "admin_url" of the page and of each tab, how many tools it owns and how many of those you can call now, and which of its settings you may change ("agent_writable"). Use it first when the owner asks what is installed, what is left to set up, or where something lives. When you point the owner somewhere, give them the "admin_url" instead of describing clicks. Read-only. A Saddle module is an add-on plugin with its own page in the Saddle menu, such as Analytics; it is not a Divi module or a block on a page, which the saddle-divi-* and block tools handle.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => (object) array(),
				'properties' => (object) array(),
			),
			'execute_callback'    => array( 'Saddle_Module_Abilities', 'list_modules' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'manage_options', 'list-modules' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/get-module-settings',
		array(
			'label'               => __( 'Get a module\'s settings', 'saddle' ),
			'description'         => __( 'Returns the settings of one Saddle module, or of Saddle itself, with what each one means and its current value. Pass "module": "saddle" for Saddle\'s own settings (access level, sign-in, memory limits), or a module key from saddle/list-modules, such as "analytics". Each field has a label, a one-line "help", its "type" and allowed values, its "default", its current "value", and "admin_url": the exact page and field where the owner changes it. "agent" says who may change it: "write" means you can, through saddle/update-module-settings; "read" means only the owner can, so give them the admin_url. A secret such as an API key is never returned, only whether one is set and its last characters. Use this to explain a setting or to send the owner to it. Read-only. A Saddle module is an add-on plugin with its own page in the Saddle menu, such as Analytics; it is not a Divi module or a block on a page, which the saddle-divi-* and block tools handle.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'module' ),
				'properties' => array(
					'module' => array(
						'type'        => 'string',
						'description' => __( '"saddle" for Saddle\'s own settings, or a module key from saddle/list-modules.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Module_Abilities', 'get_module_settings' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'manage_options', 'get-module-settings' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);

	wp_register_ability(
		'saddle/update-module-settings',
		array(
			'label'               => __( 'Change a module\'s settings', 'saddle' ),
			'description'         => __( 'Changes settings of an installed Saddle module. Only settings whose "agent" is "write" in saddle/get-module-settings can be changed; every other setting, and all of Saddle\'s own (access level, pause, sign-in, keys), belongs to the owner and is refused with the admin_url to give them. Nothing you change here can alter what connected apps are allowed to do. Pass "module" and "values", an object of setting keys and new values, which are validated the same way the admin screen validates them. Because it overwrites settings, it previews first: the first call returns each setting from its current value to the new one, in plain words, and a confirm_token. Show the owner that and call again with the same "module", "values" and the token to apply it. The change is logged and can be reversed with saddle/undo-changes. A Saddle module is an add-on plugin with its own page in the Saddle menu, such as Analytics; it is not a Divi module or a block on a page, which the saddle-divi-* and block tools handle.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'module', 'values' ),
				'properties' => array(
					'module'        => array(
						'type'        => 'string',
						'description' => __( 'A module key from saddle/list-modules.', 'saddle' ),
					),
					'values'        => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'Setting keys and their new values, for example {"track_admins": true}.', 'saddle' ),
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => __( 'The single-use token returned by the preview call. Omit on the first call to receive a preview.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Module_Abilities', 'update_module_settings' ),
			'permission_callback' => Saddle_Capabilities::permission( 'admin', 'manage_options', 'update-module-settings' ),
			'meta'                => saddle_ability_meta( false, true, false, 'admin' ),
		)
	);
}

/**
 * Execute callbacks for the module abilities, and the context section that
 * tells every session where the owner's pages are.
 */
class Saddle_Module_Abilities {

	/**
	 * saddle/list-modules.
	 *
	 * @return array
	 */
	public static function list_modules() {
		return array( 'areas' => Saddle_Modules_View::all() );
	}

	/**
	 * The schema behind a `module` input, or a 404.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error `scope` and `schema`.
	 */
	private static function resolve( array $input ) {
		$scope  = isset( $input['module'] ) ? sanitize_key( (string) $input['module'] ) : '';
		$schema = '' === $scope ? null : Saddle_Settings_Registry::schema( $scope );
		if ( ! $schema ) {
			return new WP_Error(
				'saddle_unknown_module',
				__( 'No settings under that name. Pass "saddle" or a module key from saddle/list-modules; a module with no settings has nothing to get or change.', 'saddle' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'scope'  => $scope,
			'schema' => $schema,
		);
	}

	/**
	 * saddle/get-module-settings.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_module_settings( $input = null ) {
		$found = self::resolve( is_array( $input ) ? $input : array() );

		return is_wp_error( $found ) ? $found : Saddle_Settings_View::describe( $found['scope'], $found['schema'] );
	}

	/**
	 * saddle/update-module-settings. Always previewed, bound to the module and
	 * every value, and journaled so saddle/undo-changes can put it back.
	 *
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_module_settings( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$found = self::resolve( $input );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$scope  = $found['scope'];
		$schema = $found['schema'];

		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		if ( ! $values ) {
			return new WP_Error( 'saddle_missing_values', __( 'Pass "values": an object of setting keys and their new values.', 'saddle' ), array( 'status' => 400 ) );
		}

		foreach ( array_keys( $values ) as $key ) {
			$key = (string) $key;
			if ( isset( $schema['fields'][ $key ] ) && ! Saddle_Settings_Guard::agent_can_write( $scope, $schema, $key ) ) {
				$url = Saddle_Settings_View::admin_url( $scope, $key, $schema['fields'][ $key ] );

				return new WP_Error(
					'saddle_setting_owner_only',
					sprintf(
						/* translators: 1: setting label, 2: admin URL. */
						__( '"%1$s" can only be changed by the site owner, not by a connected app. Give them this link: %2$s', 'saddle' ),
						$schema['fields'][ $key ]['label'],
						$url
					),
					array(
						'status'    => 403,
						'field'     => $key,
						'admin_url' => $url,
					)
				);
			}
		}

		$clean = Saddle_Settings_Registry::validate( $schema, $values );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$before  = Saddle_Settings_Registry::values( $schema );
		$changes = array();
		foreach ( $clean as $key => $value ) {
			$field     = $schema['fields'][ $key ];
			$changes[] = array(
				'key'   => $key,
				'label' => $field['label'],
				'from'  => self::plain( $field, $before[ $key ] ),
				'to'    => self::plain( $field, $value ),
			);
		}

		$sentences = array();
		foreach ( $changes as $change ) {
			$sentences[] = sprintf(
				/* translators: 1: setting label, 2: old value, 3: new value. */
				__( '%1$s from %2$s to %3$s', 'saddle' ),
				$change['label'],
				$change['from'],
				$change['to']
			);
		}
		$title = '' !== $schema['title'] ? $schema['title'] : $scope;

		return Saddle_Approval::gate(
			array(
				'action'  => 'update-module-settings',
				'target'  => $scope,
				// Bind every value: a preview for one set of values must not
				// confirm into another.
				'bind'    => substr( hash( 'sha256', wp_json_encode( $clean ) ), 0, 16 ),
				'summary' => sprintf(
					/* translators: 1: module name, 2: the changes, in words. */
					__( 'Change %1$s settings: %2$s.', 'saddle' ),
					$title,
					implode( '; ', $sentences )
				),
				'preview' => array(
					'module'  => $scope,
					'title'   => $title,
					'changes' => $changes,
				),
				'input'   => $input,
				'execute' => static function () use ( $scope, $schema, $clean, $changes ) {
					$options = Saddle_Settings_Registry::store_options( $schema );
					Saddle_Journal::expect_options( $options );

					$saved = Saddle_Settings_Registry::write( $schema, $clean, true );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}

					return array(
						'updated'  => true,
						'module'   => $scope,
						'changes'  => $changes,
						'undoable' => (bool) $options,
						'settings' => Saddle_Settings_View::describe( $scope, $schema ),
					);
				},
			)
		);
	}

	/**
	 * A value in words, for a preview the owner reads.
	 *
	 * @param array $field Normalised field.
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function plain( array $field, $value ) {
		if ( 'secret' === $field['type'] ) {
			return __( '(hidden)', 'saddle' );
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'on', 'saddle' ) : __( 'off', 'saddle' );
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}

		return '' === (string) $value ? __( '(empty)', 'saddle' ) : (string) $value;
	}

	/**
	 * The section that tells every session where the owner's pages are.
	 * Runs on `saddle_context_sections`.
	 *
	 * @param array[] $sections Sections so far.
	 * @return array[]
	 */
	public static function context_section( $sections ) {
		$areas = Saddle_Modules::areas();
		$pages = array( $areas['home']['title'] );
		foreach ( Saddle_Modules::modules() as $module ) {
			$pages[] = $module['title'];
		}
		$pages[] = $areas['connections']['title'];
		$pages[] = $areas['services']['title'];
		$pages[] = $areas['context']['title'];
		$pages[] = $areas['settings']['title'];

		$sections[] = array(
			'id'       => 'owner-admin',
			'title'    => __( 'The owner\'s admin', 'saddle' ),
			'lines'    => array(
				sprintf(
					/* translators: %s: the owner's Saddle pages, comma separated. */
					__( 'Owner\'s admin: Saddle → %s.', 'saddle' ),
					implode( ', ', $pages )
				),
				__( 'To explain or change a setting, call saddle-get-module-settings and give the owner the admin_url instead of describing clicks.', 'saddle' ),
			),
			'priority' => 45,
		);

		return $sections;
	}
}
