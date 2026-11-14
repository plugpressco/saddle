<?php
/**
 * Divi 5 helper abilities that pair several attrs Divi needs together.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the helper abilities. Hooked to `wp_abilities_api_init` at
 * priority 30 through saddle_register_ability_once(), like the other Divi
 * abilities, so rehearsal, Ask first, the log and undo wrap them.
 */
function saddle_register_divi_extra_abilities() {

	saddle_register_ability_once(
		'saddle/divi-add-interaction',
		array(
			'label'               => __( 'Add a Divi interaction', 'saddle' ),
			'description'         => __( 'Adds a Divi 5 interaction to a page: when a visitor acts on one module (the trigger), something happens to another (the target), for example "click this button, show that row". Writes all three parts Divi needs in one call, which are easy to get wrong by hand: the entry in the trigger module\'s module.decoration.interactions (desktop only, appended to the ones it has; never replaced), the trigger mark module.decoration.interactionTrigger, and the target mark module.decoration.interactionTarget. Get both addresses from divi-get-page; they may be the same module (a button that changes its own class). triggers: click, mouseEnter, mouseExit, viewportEnter, viewportExit, load. effects: toggleVisibility, addVisibility (show), removeVisibility (hide), toggleAttribute, addAttribute, removeAttribute, scrollToElement. The attribute effects need attribute_name (such as "class", "data-state" or "aria-expanded") and attribute_value; the value joins the attribute\'s space-separated list, so addAttribute "class" "is-open" adds that class. To remove an interaction, pass post_id and remove_id (the id this tool returned). The trigger module must have an interactions setting (most Divi modules do; module packs often do not). Saved as a revision; the answer carries the interaction id, the changed nodes and any "warnings".', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'         => array( 'type' => 'integer' ),
					'trigger_address' => array(
						'type'        => 'string',
						'description' => __( 'Address of the module the visitor acts on, from divi-get-page, e.g. "0.0.0.1".', 'saddle' ),
					),
					'target_address'  => array(
						'type'        => 'string',
						'description' => __( 'Address of the module that changes. May equal trigger_address.', 'saddle' ),
					),
					'trigger'         => array(
						'type'        => 'string',
						'enum'        => Saddle_Divi_Interactions::TRIGGERS,
						'description' => __( 'What the visitor does. Default "click". viewportEnter and viewportExit fire when the trigger scrolls in or out of view; load fires once the page loads.', 'saddle' ),
					),
					'effect'          => array(
						'type'        => 'string',
						'enum'        => Saddle_Divi_Interactions::EFFECTS,
						'description' => __( 'What happens to the target. Default "toggleVisibility".', 'saddle' ),
					),
					'attribute_name'  => array(
						'type'        => 'string',
						'description' => __( 'For the attribute effects: the attribute, such as "class" or "data-state". Event handlers (on…), href, src, action and style are refused.', 'saddle' ),
					),
					'attribute_value' => array(
						'type'        => 'string',
						'description' => __( 'For the attribute effects: the value added, removed or toggled, such as "is-open".', 'saddle' ),
					),
					'delay'           => array(
						'type'        => array( 'integer', 'string' ),
						'description' => __( 'Wait before the effect, in milliseconds (300) or as "300ms" or "0.3s". 0 to 60000, default 0.', 'saddle' ),
					),
					'label'           => array(
						'type'        => 'string',
						'description' => __( 'Optional name for the interaction in the Visual Builder\'s list.', 'saddle' ),
					),
					'remove_id'       => array(
						'type'        => 'string',
						'description' => __( 'Remove the interaction with this id instead of adding one. The other inputs are ignored.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Extra_Abilities', 'add_interaction' ),
			'permission_callback' => Saddle_Capabilities::permission( 'write', 'edit_posts', 'divi-add-interaction' ),
			'meta'                => saddle_ability_meta( false, false, false, 'write' ),
		)
	);

	// The permission_callback gates the TOOL. The TARGET is checked in the
	// callback the way get-preview-url checks it: an unpublished or
	// password-protected post needs edit_post, a published one read_post.
	saddle_register_ability_once(
		'saddle/divi-get-rendered-page',
		array(
			'label'               => __( 'Get the rendered Divi page', 'saddle' ),
			'description'         => __( 'Returns the real HTML of a page\'s content area and the CSS Divi prints for it, in one size-capped answer, so you can check your work without a browser. The site loads its own short-lived preview of the post (drafts included) the way a logged-out visitor\'s browser would, then keeps only the content area (Divi\'s et-l--post element, else entry-content), with scripts removed, and Divi\'s own style blocks: the page\'s module styles, the site\'s color and font variables (:root) and the theme customizer. The theme\'s linked stylesheets are listed by id, not included. Use it after building or editing to confirm classes, text and the CSS each module got; it is not a screenshot, so for pixels and phone widths open saddle/get-preview-url in your own browser. Only this site\'s own address is requested. Some hosts block a site from requesting itself; the error then says so.', 'saddle' ),
			'category'            => 'saddle',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id'     => array( 'type' => 'integer' ),
					'max_html_kb' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => Saddle_Divi_Rendered_Page::HTML_KB_MAX,
						'description' => __( 'Largest HTML returned, in KB. Default 48, at most 256. The HTML is cut at a tag boundary and "html_truncated" says so.', 'saddle' ),
					),
					'max_css_kb'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => Saddle_Divi_Rendered_Page::CSS_KB_MAX,
						'description' => __( 'Largest CSS returned, in KB. Default 24, at most 128. Cut at a rule boundary; "css_truncated" says so.', 'saddle' ),
					),
				),
			),
			'execute_callback'    => array( 'Saddle_Divi_Extra_Abilities', 'get_rendered_page' ),
			'permission_callback' => Saddle_Capabilities::permission( 'read', 'read', 'divi-get-rendered-page' ),
			'meta'                => saddle_ability_meta( true, false, true, 'read' ),
		)
	);
}

/**
 * Callbacks for the Divi helper abilities. They write through the same
 * validated save path as divi-edit-module (Saddle_Divi_Abilities).
 */
class Saddle_Divi_Extra_Abilities {

	use Saddle_Mutation_Log;

	/**
	 * saddle/divi-add-interaction.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function add_interaction( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = Saddle_Divi_Abilities::edit_guard( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$tree = Saddle_Divi_Tree::parse( $post->post_content );

		$remove_id = isset( $input['remove_id'] ) ? trim( (string) $input['remove_id'] ) : '';
		if ( '' !== $remove_id ) {
			return self::remove_interaction( $post, $tree, $remove_id );
		}

		$trigger_address = isset( $input['trigger_address'] ) ? trim( (string) $input['trigger_address'] ) : '';
		$target_address  = isset( $input['target_address'] ) ? trim( (string) $input['target_address'] ) : '';
		if ( '' === $trigger_address || '' === $target_address ) {
			return new WP_Error( 'saddle_missing_address', __( 'Give "trigger_address" and "target_address" from divi-get-page (they may be the same), or "remove_id" to remove an interaction.', 'saddle' ) );
		}

		$spec = Saddle_Divi_Interactions::spec( $input );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
		$added = Saddle_Divi_Interactions::add( $tree, $trigger_address, $target_address, $spec );
		if ( is_wp_error( $added ) ) {
			return $added;
		}

		$modules = Saddle_Divi_Abilities::persist_public( $post, $added['tree'] );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		self::log(
			'divi-add-interaction',
			$post->ID,
			sprintf(
				/* translators: 1: effect, 2: trigger address, 3: target address, 4: post ID. */
				__( 'Added a %1$s interaction from %2$s to %3$s on Divi page #%4$d.', 'saddle' ),
				$added['item']['effect'],
				$trigger_address,
				$target_address,
				$post->ID
			)
		);

		$warnings = array();
		foreach ( $added['patches'] as $address => $patch ) {
			$node     = Saddle_Divi_Tree::get( $added['tree'], (string) $address );
			$warnings = array_merge( $warnings, Saddle_Divi_Echo::check( (string) $node['blockName'], array(), $patch, (string) $address ) );
		}

		$result = array_merge(
			array(
				'id'          => $post->ID,
				'interaction' => array(
					'id'              => $added['item']['id'],
					'trigger'         => $added['item']['trigger'],
					'effect'          => $added['item']['effect'],
					'trigger_address' => $trigger_address,
					'target_address'  => $target_address,
					'trigger_class'   => $added['item']['triggerClass'],
					'target_class'    => $added['item']['target']['targetClass'],
				),
				'modules'     => $modules,
				'note'        => __( 'Interactions run only on the front end. Open saddle/get-preview-url and try it in your browser.', 'saddle' ),
			),
			Saddle_Divi_Abilities::view_extras( $post->ID, $added['tree'], array_keys( $added['patches'] ) )
		);
		if ( $warnings ) {
			$result['warnings'] = array_values( array_unique( $warnings ) );
		}
		return $result;
	}

	/**
	 * saddle/divi-get-rendered-page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_rendered_page( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! Saddle_Divi::is_active() ) {
			return new WP_Error( 'saddle_no_divi', __( 'Divi 5 is not active on this site.', 'saddle' ) );
		}
		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post || ! in_array( $post->post_type, Saddle_Post_Types::content_types(), true ) ) {
			return new WP_Error( 'saddle_not_found', __( 'No post or page with that ID.', 'saddle' ), array( 'status' => 404 ) );
		}
		// The site reads its own preview, which shows drafts: the same bar as
		// get-preview-url, and a password-protected post counts as unpublished.
		$cap = 'publish' === $post->post_status && '' === (string) $post->post_password ? 'read_post' : 'edit_post';
		if ( ! current_user_can( $cap, $post->ID ) ) {
			return new WP_Error( 'saddle_forbidden', __( 'You cannot view this post as rendered.', 'saddle' ), array( 'status' => 403 ) );
		}

		$read = Saddle_Divi_Rendered_Page::read(
			$post,
			isset( $input['max_html_kb'] ) ? (int) $input['max_html_kb'] : Saddle_Divi_Rendered_Page::HTML_KB,
			isset( $input['max_css_kb'] ) ? (int) $input['max_css_kb'] : Saddle_Divi_Rendered_Page::CSS_KB
		);
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		return array_merge(
			array( 'id' => $post->ID ),
			$read,
			array( 'note' => __( 'The content area as a logged-out visitor\'s browser receives it, scripts removed, with the CSS Divi printed for this page. Not a screenshot: open saddle/get-preview-url in your browser for pixels and phone widths.', 'saddle' ) )
		);
	}

	/**
	 * The remove_id branch of divi-add-interaction.
	 *
	 * @param WP_Post $post Target post.
	 * @param array[] $tree Its tree.
	 * @param string  $id   Interaction id.
	 * @return array|WP_Error
	 */
	private static function remove_interaction( WP_Post $post, array $tree, $id ) {
		$removed = Saddle_Divi_Interactions::remove( $tree, $id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		$modules = Saddle_Divi_Abilities::persist_public( $post, $removed['tree'] );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		self::log(
			'divi-add-interaction',
			$post->ID,
			sprintf(
				/* translators: 1: interaction id, 2: module address, 3: post ID. */
				__( 'Removed interaction %1$s from %2$s on Divi page #%3$d.', 'saddle' ),
				$id,
				$removed['address'],
				$post->ID
			)
		);

		return array_merge(
			array(
				'id'              => $post->ID,
				'removed'         => $id,
				'trigger_address' => $removed['address'],
				'modules'         => $modules,
			),
			Saddle_Divi_Abilities::view_extras( $post->ID, $removed['tree'], $removed['changed'] )
		);
	}
}
