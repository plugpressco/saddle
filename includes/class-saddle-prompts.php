<?php
/**
 * The site's enabled Skills, served as MCP prompts.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * One MCP prompt per enabled skill (#178), so Claude Desktop, Cursor and VS
 * Code offer each playbook as a slash command instead of waiting for the
 * agent to think of calling saddle/get-skill.
 *
 * A prompt is the same data get-skill returns — name, description, and the
 * body verbatim (Markdown-as-data, never escaped) as one user message — so it
 * answers to exactly the same gate: whoever may call saddle/get-skill right
 * now (signed in, not paused, tier, the tool not switched off) may list and
 * get the prompts, and nobody else. Both transports read from here.
 */
class Saddle_Prompts {

	/**
	 * Whether the current request may see the prompts.
	 *
	 * Pause is checked on its own: is_callable_now() leaves it out because a
	 * paused site keeps its tools listed (ChatGPT freezes a tool list, so a
	 * hidden tool would stay hidden after resume). Prompts have no such
	 * client, and a paused site serves nothing, so here pause hides them.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return ! Saddle_Capabilities::is_paused() && Saddle_Capabilities::is_callable_now( 'saddle/get-skill' );
	}

	/**
	 * The prompt list: every enabled skill.
	 *
	 * @return array[] Each { name, description }.
	 */
	public static function listing() {
		$prompts = array();
		foreach ( Saddle_Skills::all( false ) as $skill ) {
			if ( empty( $skill['enabled'] ) ) {
				continue;
			}
			$prompts[] = array(
				'name'        => $skill['name'],
				'description' => self::description( $skill ),
			);
		}
		return $prompts;
	}

	/**
	 * One prompt's messages.
	 *
	 * @param string $name Skill name.
	 * @return array|WP_Error { description, messages }.
	 */
	public static function get( $name ) {
		$skill = Saddle_Skills::find( (string) $name );
		if ( ! $skill || empty( $skill['enabled'] ) ) {
			return new WP_Error( 'saddle_prompt_not_found', __( 'No enabled skill with that name.', 'saddle' ) );
		}
		return array(
			'description' => self::description( $skill ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => (string) $skill['body'],
					),
				),
			),
		);
	}

	/**
	 * Prompt configurations for the MCP Adapter's create_server(): the same
	 * list, with get() as the handler and allowed() as the permission.
	 *
	 * @return array[]
	 */
	public static function adapter_configs() {
		$configs = array();
		foreach ( self::listing() as $prompt ) {
			$name      = $prompt['name'];
			$configs[] = array(
				'name'        => $name,
				'description' => $prompt['description'],
				'handler'     => static function () use ( $name ) {
					return Saddle_Prompts::get( $name );
				},
				'permission'  => array( __CLASS__, 'allowed' ),
			);
		}
		return $configs;
	}

	/**
	 * The adapter builds its prompt list before authentication; this narrows
	 * it at dispatch, the same moment tools/list is narrowed.
	 *
	 * @param array $prompts Prompt DTOs.
	 * @return array
	 */
	public static function filter_adapter_list( $prompts ) {
		return self::allowed() ? $prompts : array();
	}

	/**
	 * A skill's description with its "use when" hint.
	 *
	 * @param array $skill Skill.
	 * @return string
	 */
	private static function description( array $skill ) {
		$description = (string) $skill['description'];
		if ( ! empty( $skill['when_to_use'] ) ) {
			/* translators: 1: skill description, 2: when to use it. */
			$description = sprintf( __( '%1$s Use when: %2$s', 'saddle' ), $description, $skill['when_to_use'] );
		}
		return $description;
	}
}
