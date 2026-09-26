# Build a Saddle integration

Saddle is a WordPress MCP server. An AI app connects to it and gets a set of
tools for the site. Any plugin can add its own tools to that set. This page
shows how.

You write two small pieces of code. You don't call Saddle, and you don't need
Saddle to be installed: without it, your code simply does nothing.

Once your plugin enrols, Saddle wraps every tool in its own safety model:

- **Access levels.** A read tool works at the `read` level. Anything else
  needs `write`. The site owner picks the level.
- **Approval gate.** A destructive tool first returns a preview and a
  single-use token. The action runs only when the agent calls again with that
  token, within 15 minutes, with exactly the same arguments.
- **Owner controls.** The pause switch and the per-tool switches on the
  Permissions screen apply to your tools too.
- **Activity log.** Every call that changes something is logged.
- **Your own permission check still runs.** Saddle can only narrow what your
  tool allows, never widen it.

**Third-party integrations start switched off.** Your plugin appears on the
owner's **Saddle > Integrations** screen with a "Third-party" label and a
switch. Nothing reaches an agent until the owner turns it on.

---

## Step 1: register your tools

Register each tool on the WordPress Abilities API (WordPress 6.9 or later),
in your own namespace:

```php
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category(
		'acme',
		array(
			'label'       => __( 'Acme Forms', 'acme' ),
			'description' => __( 'Form entries and reports.', 'acme' ),
		)
	);
} );

add_action( 'wp_abilities_api_init', function () {
	wp_register_ability(
		'acme/get-report',
		array(
			'label'               => __( 'Get form report', 'acme' ),
			'description'         => __( 'Returns the entry count and completion rate for one form. Use it when the user asks how a form is performing.', 'acme' ),
			'category'            => 'acme',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'form_id' ),
			),
			'execute_callback'    => 'acme_get_report',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
} );
```

Keep the default priority (10). Saddle wraps tools at priority 30, so a tool
registered at 30 or later is missed.

### The annotations decide how Saddle treats a tool

| Annotation | Effect in Saddle |
|---|---|
| `readonly: true` | Read level. Needs the WordPress `read` capability. |
| `readonly` false or missing | Write level. Needs `edit_posts` as well as your own check. |
| `destructive: true` | Goes through the approval gate. Saddle adds a `confirm_token` field to the input. |
| `idempotent` | Passed through to the agent. |

**Mark every tool that deletes or overwrites anything as `destructive`.** A
missing annotation means no approval gate. The site owner can force the gate
onto a tool (see `force_destructive` below), but don't rely on that.

### Write the description for the agent

The agent reads `description` to decide when to call your tool, so write it as
documentation: what the tool does, what it returns, and when to use it. Saddle
appends "(Provided by the Acme Forms plugin through Saddle.)" and, for a
destructive tool, how the confirmation works.

---

## Step 2: enrol in Saddle

Add one entry to the `saddle_integrations` filter:

```php
add_filter( 'saddle_integrations', function ( $integrations ) {
	$integrations['acme'] = array(
		'prefix'      => 'acme/',
		'title'       => 'Acme Forms',
		'description' => __( 'Form entries and reports.', 'acme' ),
		'author'      => 'Acme Inc.',
		'url'         => 'https://example.com/acme-forms',
	);
	return $integrations;
} );
```

That's it. Saddle wraps every ability whose name starts with `prefix`:

```
acme/get-report  ->  saddle/acme-get-report   (MCP clients see: saddle-acme-get-report)
```

### Catalog entry fields

| Field | Required | Notes |
|---|---|---|
| key (`acme`) | yes | Your slug. Lowercase letters, digits and hyphens. It becomes part of every tool name. |
| `prefix` | yes | Your ability namespace with its slash, e.g. `acme/`. |
| `title` | yes | Your plugin's name, shown to the owner and the agent. |
| `description` | no | One short line for the Integrations screen. Plain text. |
| `author` | no | Shown as "By …" on the Integrations screen. Plain text. |
| `url` | no | Your plugin's homepage. `http` or `https` only. Saddle never fetches it. |
| `force_destructive` | no | Tool short names (e.g. `array( 'purge' )`) to put behind the approval gate even without the annotation. |

### What Saddle rejects

Saddle skips an entry, with a `_doing_it_wrong` notice (visible with
`WP_DEBUG` on), when:

- the slug or prefix isn't lowercase letters, digits and hyphens, or the
  prefix doesn't end in one slash;
- the prefix is `saddle/`, `core/` or `mcp-adapter/`;
- the slug is one Saddle uses for its own tools: `divi`, `yoast`,
  `rank-math`, `aioseo`, `wc` or `unsplash`.

If `saddle/<your-slug>-<tool>` already exists, that one tool is not exposed,
again with a notice. Pick a distinctive slug.

---

## What the site owner sees

On **Saddle > Integrations**, your plugin appears once it is active:

- title, description and "By {author}" linked to your `url`;
- a **Third-party** label and the number of tools it adds;
- a switch, **off** by default.

Until the owner turns it on, no agent can see your tools. The agent's
instructions say your plugin is installed but switched off, so it can tell the
user where to turn it on instead of guessing.

The owner's switch is the only way on. The `saddle_integration_enabled`
filter can only turn an integration **off**, and your plugin must not write
Saddle's options to switch itself on.

---

## Optional extras

### Give the agent guidance

Add a section to the instructions every connected agent reads. Keep it short:
it is sent at the start of every session.

```php
add_filter( 'saddle_context_sections', function ( $sections ) {
	// Only once the owner switched us on and our tools were wrapped.
	if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'saddle/acme-get-report' ) ) {
		return $sections;
	}
	$sections[] = array(
		'id'       => 'acme-forms',
		'title'    => 'Acme Forms',
		'lines'    => array(
			'- Check a form with saddle/acme-get-report before suggesting changes to it.',
		),
		'priority' => 45,
	);
	return $sections;
} );
```

`title` is the heading text without `#`. Sections sort by `priority` (lower
first, default 50).

### Ship a playbook

A skill is a Markdown playbook the agent loads on demand with
`saddle/get-skill`. Only its name and description ride along in the
instructions.

```php
add_filter( 'saddle_builtin_skills', function ( $skills ) {
	if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'saddle/acme-get-report' ) ) {
		return $skills;
	}
	$skills[] = array(
		'name'        => 'acme-form-audit',
		'description' => 'Audit a form: completion rate, drop-off field, spam share.',
		'when_to_use' => 'the user asks why a form performs badly',
		'body'        => "1. Call saddle/acme-get-report with the form_id.\n2. ...",
		'source'      => 'Acme Forms',
	);
	return $skills;
} );
```

The body is passed to the agent byte for byte, so placeholders like `<form_id>`
survive.

### Make the log readable

For log lines and approval previews, Saddle names the item a tool acts on by
looking for an input key such as `id`, `post_id` or `doc_id`. If your key is
different, add it:

```php
add_filter( 'saddle_integration_target_keys', function ( $keys ) {
	$keys[] = 'form_id';
	return $keys;
} );
```

---

## Detecting Saddle

You don't need to: both filters are inert without Saddle. To show a hint in
your own settings screen, check:

```php
if ( defined( 'SADDLE_VERSION' ) ) {
	// Saddle is active.
}
```

---

## Checklist before you ship

- [ ] Every tool has `readonly` and `destructive` set on purpose.
- [ ] Everything that deletes or overwrites is `destructive: true`.
- [ ] Your `permission_callback` is a real capability check.
- [ ] Descriptions say what the tool does, what it returns and when to use it.
- [ ] Abilities register at the default priority, not 30 or later.
- [ ] With Saddle active and `WP_DEBUG` on, there is no `_doing_it_wrong`
      notice from Saddle.
- [ ] Your plugin shows on Saddle > Integrations, switched off, with the right
      tool count.
- [ ] After switching it on: your read tools work at the `read` level, write
      tools only at `write`, and a destructive tool returns a preview first.

PlugPress's Mailyard plugin enrols with exactly these two steps, and you can
use it as a working reference.
