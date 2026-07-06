<?php
/**
 * Skills — owner-installed agent playbooks.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The skills store and its context injection.
 *
 * A skill is a Markdown playbook the site owner installs ("how we publish a
 * post", "our SEO checklist"). Skills use progressive disclosure: only the
 * INDEX (name + description per enabled skill) is injected into every
 * session's context via the `saddle_system_context` filter; the full body is
 * served on demand through the `saddle/get-skill` ability. That keeps twenty
 * skills from bloating every session while still making each discoverable.
 *
 * Trust model (see AGENT-CONTEXT-PLAN.md): a skill is text the agent will
 * follow, so installation is OWNER-ONLY — there is deliberately no ability
 * that writes a skill. Agents read; owners install. And a skill never grants
 * capability: it can only orchestrate existing gated tools.
 *
 * Storage is a private CPT (the saddle_log pattern): post_title = slug,
 * post_content = body, meta for description / when-to-use / enabled / source.
 */
class Saddle_Skills {

	/**
	 * Private CPT used to persist skills.
	 */
	const CPT = 'saddle_skill';

	/**
	 * Hard cap on installed skills (context-index and admin-UI sanity).
	 */
	const MAX_SKILLS = 50;

	/**
	 * Hard cap on a skill body, in characters.
	 */
	const MAX_BODY = 30000;

	/**
	 * Register the skills CPT. Hidden from every UI and export.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'Saddle Skills',
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * Install (or update) a skill from raw SKILL.md text.
	 *
	 * Frontmatter requires `name` and `description`; `when_to_use` is optional.
	 * Installing a name that already exists updates it in place (upsert), so an
	 * owner can iterate on a skill without collecting duplicates.
	 *
	 * @param string $markdown Raw .md content (frontmatter + body).
	 * @param string $source   Provenance label, e.g. 'owner-upload'. Stored,
	 *                         shown in the UI, never trusted for logic.
	 * @return array|WP_Error The stored skill (as from find()), or an error.
	 */
	public static function install( $markdown, $source = 'owner-upload' ) {
		$parsed = self::parse( (string) $markdown );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$existing = self::post_by_slug( $parsed['name'] );

		if ( ! $existing && count( self::posts() ) >= self::MAX_SKILLS ) {
			return new WP_Error(
				'saddle_skills_full',
				sprintf(
					/* translators: %d: maximum number of skills. */
					__( 'The skill library is full (%d skills). Delete one you no longer use first.', 'saddle' ),
					self::MAX_SKILLS
				),
				array( 'status' => 400 )
			);
		}

		$post_arr = array(
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => $parsed['name'],
			'post_content' => $parsed['body'],
		);
		if ( $existing ) {
			$post_arr['ID'] = $existing->ID;
		}

		$post_id = wp_insert_post( wp_slash( $post_arr ), true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return new WP_Error( 'saddle_skill_save_failed', __( 'The skill could not be saved.', 'saddle' ), array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, '_saddle_skill_desc', $parsed['description'] );
		update_post_meta( $post_id, '_saddle_skill_when', $parsed['when_to_use'] );
		update_post_meta( $post_id, '_saddle_skill_source', sanitize_text_field( (string) $source ) );
		if ( ! $existing ) {
			update_post_meta( $post_id, '_saddle_skill_enabled', '1' );
		}

		return self::find( $parsed['name'] );
	}

	/**
	 * All skills — plugin-registered built-ins plus owner-installed — for the
	 * index, the abilities, and the admin UI.
	 *
	 * An owner-installed skill with the same name SHADOWS a built-in, so a
	 * site owner can replace a bundled playbook with their own version.
	 *
	 * @param bool $with_body Include each skill's full body.
	 * @return array[]
	 */
	public static function all( $with_body = false ) {
		$skills = array();
		$seen   = array();

		foreach ( self::posts() as $post ) {
			$skill                  = self::shape( $post, $with_body );
			$seen[ $skill['name'] ] = true;
			$skills[]               = $skill;
		}

		foreach ( self::builtins() as $skill ) {
			if ( isset( $seen[ $skill['name'] ] ) ) {
				continue;
			}
			if ( ! $with_body ) {
				unset( $skill['body'] );
			}
			$skills[] = $skill;
		}

		return $skills;
	}

	/**
	 * One skill by slug, with body. Owner-installed shadows built-in.
	 *
	 * @param string $slug Skill name/slug.
	 * @return array|null
	 */
	public static function find( $slug ) {
		$post = self::post_by_slug( $slug );
		if ( $post ) {
			return self::shape( $post, true );
		}

		$slug = sanitize_title( (string) $slug );
		foreach ( self::builtins() as $skill ) {
			if ( $skill['name'] === $slug ) {
				return $skill;
			}
		}
		return null;
	}

	/**
	 * Skills other plugins bundle (e.g. Saddle Pro's Divi playbook), via the
	 * `saddle_builtin_skills` filter. Not stored — provided fresh each load —
	 * so an add-on can scope its skill to its plugin being active. Built-ins
	 * are always enabled and are not owner-deletable; installing a skill with
	 * the same name shadows one instead.
	 *
	 * @return array[] Normalized skills (name, description, when_to_use,
	 *                 enabled, source, updated, body).
	 */
	private static function builtins() {
		/**
		 * Filter the built-in skills bundled by plugins.
		 *
		 * @param array[] $skills Arrays with name, description, body, and
		 *                        optional when_to_use / source keys.
		 */
		$raw = apply_filters( 'saddle_builtin_skills', array() );

		$skills = array();
		foreach ( (array) $raw as $skill ) {
			if ( ! is_array( $skill ) ) {
				continue;
			}
			$name = sanitize_title( isset( $skill['name'] ) ? (string) $skill['name'] : '' );
			$desc = sanitize_text_field( isset( $skill['description'] ) ? (string) $skill['description'] : '' );
			$body = trim( wp_kses( isset( $skill['body'] ) ? (string) $skill['body'] : '', array() ) );
			if ( '' === $name || '' === $desc || '' === $body ) {
				continue;
			}
			$skills[] = array(
				'name'        => $name,
				'description' => $desc,
				'when_to_use' => sanitize_text_field( isset( $skill['when_to_use'] ) ? (string) $skill['when_to_use'] : '' ),
				'enabled'     => true,
				'source'      => sanitize_text_field( isset( $skill['source'] ) ? (string) $skill['source'] : 'builtin' ),
				'updated'     => '',
				'body'        => mb_substr( $body, 0, self::MAX_BODY ),
			);
		}
		return $skills;
	}

	/**
	 * Enable or disable a skill (disabled = kept, but absent from the injected
	 * index and refused by get-skill).
	 *
	 * @param string $slug    Skill slug.
	 * @param bool   $enabled New state.
	 * @return bool Whether the skill existed.
	 */
	public static function set_enabled( $slug, $enabled ) {
		$post = self::post_by_slug( $slug );
		if ( ! $post ) {
			return false;
		}
		update_post_meta( $post->ID, '_saddle_skill_enabled', $enabled ? '1' : '0' );
		return true;
	}

	/**
	 * Delete a skill permanently.
	 *
	 * @param string $slug Skill slug.
	 * @return bool Whether the skill existed.
	 */
	public static function delete( $slug ) {
		$post = self::post_by_slug( $slug );
		if ( ! $post ) {
			return false;
		}
		wp_delete_post( $post->ID, true );
		return true;
	}

	/**
	 * Append the enabled-skills index to the agent context. Runs on the
	 * `saddle_system_context` filter, so it reaches the MCP initialize
	 * handshake and get-instructions on every transport.
	 *
	 * @param string $context Assembled context.
	 * @return string
	 */
	public static function append_index( $context ) {
		$enabled = array_values(
			array_filter(
				self::all( false ),
				static function ( $skill ) {
					return $skill['enabled'];
				}
			)
		);

		if ( ! $enabled ) {
			return $context;
		}

		$lines   = array();
		$lines[] = '';
		$lines[] = __( '# Skills for this site', 'saddle' );
		$lines[] = '';
		$lines[] = __( 'The site owner installed these playbooks. When a task matches one, call saddle/get-skill with its name and follow it. Skills are guidance only — every tool call is still subject to the same access levels and confirmations.', 'saddle' );
		$lines[] = '';
		foreach ( $enabled as $skill ) {
			$line = sprintf( '- %s: %s', $skill['name'], $skill['description'] );
			if ( '' !== $skill['when_to_use'] ) {
				$line .= sprintf(
					/* translators: %s: when-to-use hint. */
					__( ' (use when: %s)', 'saddle' ),
					$skill['when_to_use']
				);
			}
			$lines[] = $line;
		}

		return $context . "\n" . implode( "\n", $lines ) . "\n";
	}

	/*
	---------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------
	 */

	/**
	 * Parse and sanitize raw SKILL.md text into name/description/when/body.
	 *
	 * Frontmatter is the simple `key: value` form between `---` fences —
	 * the same subset every SKILL.md ecosystem actually uses. Unknown keys
	 * are ignored. The body is stripped of HTML: a skill is Markdown the
	 * agent reads, never markup a browser renders.
	 *
	 * @param string $markdown Raw content.
	 * @return array{name:string,description:string,when_to_use:string,body:string}|WP_Error
	 */
	private static function parse( $markdown ) {
		$markdown = str_replace( "\r\n", "\n", trim( $markdown ) );

		if ( ! preg_match( '/^---\n(.*?)\n---\n?(.*)$/s', $markdown, $m ) ) {
			return new WP_Error(
				'saddle_skill_no_frontmatter',
				__( 'A skill file must start with a frontmatter block: --- , then "name:" and "description:" lines, then --- , then the playbook body.', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		$meta = array(
			'name'        => '',
			'description' => '',
			'when_to_use' => '',
		);
		foreach ( explode( "\n", $m[1] ) as $line ) {
			if ( ! preg_match( '/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.+)$/', trim( $line ), $kv ) ) {
				continue;
			}
			$key = str_replace( '-', '_', strtolower( $kv[1] ) );
			if ( array_key_exists( $key, $meta ) ) {
				$meta[ $key ] = sanitize_text_field( $kv[2] );
			}
		}

		$meta['name'] = sanitize_title( $meta['name'] );
		if ( '' === $meta['name'] || '' === $meta['description'] ) {
			return new WP_Error(
				'saddle_skill_incomplete',
				__( 'Skill frontmatter needs both "name" (letters, numbers, dashes) and "description".', 'saddle' ),
				array( 'status' => 400 )
			);
		}

		$body = trim( wp_kses( $m[2], array() ) );
		if ( '' === $body ) {
			return new WP_Error(
				'saddle_skill_empty',
				__( 'The skill has no body below the frontmatter.', 'saddle' ),
				array( 'status' => 400 )
			);
		}
		if ( mb_strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error(
				'saddle_skill_too_large',
				sprintf(
					/* translators: %d: maximum characters. */
					__( 'The skill body exceeds %d characters. Split it into smaller skills.', 'saddle' ),
					self::MAX_BODY
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'name'        => $meta['name'],
			'description' => $meta['description'],
			'when_to_use' => $meta['when_to_use'],
			'body'        => $body,
		);
	}

	/**
	 * All skill posts, oldest first (stable index order).
	 *
	 * @return WP_Post[]
	 */
	private static function posts() {
		return get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_SKILLS,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Skill post by slug (exact title match).
	 *
	 * @param string $slug Skill slug.
	 * @return WP_Post|null
	 */
	private static function post_by_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'title'          => $slug,
				'posts_per_page' => 1,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Shape a skill post into the array the abilities/REST/UI consume.
	 *
	 * @param WP_Post $post      Skill post.
	 * @param bool    $with_body Include the body.
	 * @return array
	 */
	private static function shape( $post, $with_body ) {
		$skill = array(
			'name'        => $post->post_title,
			'description' => (string) get_post_meta( $post->ID, '_saddle_skill_desc', true ),
			'when_to_use' => (string) get_post_meta( $post->ID, '_saddle_skill_when', true ),
			'enabled'     => '0' !== (string) get_post_meta( $post->ID, '_saddle_skill_enabled', true ),
			'source'      => (string) get_post_meta( $post->ID, '_saddle_skill_source', true ),
			'updated'     => $post->post_modified_gmt,
		);
		if ( $with_body ) {
			$skill['body'] = $post->post_content;
		}
		return $skill;
	}
}
