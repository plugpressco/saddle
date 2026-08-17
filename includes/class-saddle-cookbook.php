<?php
/**
 * Ready-to-paste prompts, grouped by the job the owner is trying to do.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cookbook: what to actually type once an app is connected.
 *
 * Saddle's docs answer what it is, how to connect it, and what it refuses to do.
 * None of them answered the question people have thirty seconds later, which is
 * what do I say. The evidence this exists for is a customer who got Saddle onto
 * a production site, connected Claude, published real posts, and described
 * reaching that as "bit of messing about" — value found in spite of the
 * onboarding rather than because of it.
 *
 * Data only, on purpose. The same recipes render in wp-admin and publish to the
 * docs site, and two hand-maintained copies of the same prompt drift within a
 * release. Nothing here renders anything; see Cookbook.jsx and the REST route.
 *
 * Rules for anything added here:
 *
 * - The prompt is what a person pastes, verbatim, with no editing beyond a URL
 *   or a title. If it needs explaining before it works, it is not a recipe yet.
 * - It is written in the owner's words. No tool names, no "ability", no MCP.
 *   The agent knows its own tools; the person does not, and should not have to.
 * - Every prompt is RUN before it ships. A cookbook whose recipes fail is worse
 *   than no cookbook, and this is the file most likely to rot as tools change.
 * - `tier` and `pro` are stated so a refusal never reads as a bug. A person who
 *   pastes a prompt and is told no has been failed by this file, not by Saddle.
 */
class Saddle_Cookbook {

	/**
	 * Groups, in the order someone meets them: write something, shape a page,
	 * check it, then teach the thing your way.
	 *
	 * @return array<string,string> Group key => human title.
	 */
	public static function groups() {
		return array(
			'writing'  => __( 'Writing', 'saddle' ),
			'pages'    => __( 'Pages and design', 'saddle' ),
			'divi'     => __( 'Divi', 'saddle' ),
			'images'   => __( 'Images', 'saddle' ),
			'checking' => __( 'Checking your work', 'saddle' ),
			'teaching' => __( 'Teaching it your way', 'saddle' ),
		);
	}

	/**
	 * Every recipe.
	 *
	 * Shape, per row:
	 *   group  string  A key from groups().
	 *   title  string  The job, in the owner's words.
	 *   prompt string  The exact text to paste.
	 *   tier   string  Lowest access level that can run it: read|write|admin.
	 *                  Same vocabulary as Saddle_Capabilities, so this screen and
	 *                  the Permissions screen never disagree about a word.
	 *   expect string  What comes back, so success is distinguishable from a
	 *                  wrong turn.
	 *   pro    bool    Needs Saddle Pro. Optional, defaults false.
	 *   uses   array   Ability names the prompt leans on. Never shown to anyone:
	 *                  it exists so CookbookTest can assert every one of them is
	 *                  still registered. Rename a tool and the suite fails, which
	 *                  is the only defence against this file quietly promising
	 *                  something the product stopped being able to do. Not a
	 *                  contract about what the agent will actually call, and not
	 *                  exhaustive; one representative ability per claim is enough
	 *                  to catch the rename.
	 *
	 * @return array[]
	 */
	public static function recipes() {
		$recipes = array(

			/* ------------------------------------------------------------ writing */

			array(
				'group'  => 'writing',
				'title'  => __( 'See what is already on the site', 'saddle' ),
				'prompt' => __( 'Show me my ten most recent posts with their titles, status and dates.', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'A list you can check against wp-admin. This is the safest first thing to try, because it proves the connection works without changing anything.', 'saddle' ),
				'uses'   => array( 'saddle/list-posts' ),
			),
			array(
				'group'  => 'writing',
				'title'  => __( 'Turn my notes into a draft', 'saddle' ),
				'prompt' => __( 'Here are my rough notes. Turn them into a blog post in my site\'s usual voice, and save it as a draft so I can review it before it goes live. Do not publish it.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'A draft post you open in the editor. Say "publish it" once you are happy.', 'saddle' ),
				'uses'   => array( 'saddle/create-post' ),
			),
			array(
				'group'  => 'writing',
				'title'  => __( 'Rewrite a page so it reads better', 'saddle' ),
				'prompt' => __( 'Read my About page and rewrite it to be clearer and shorter, keeping every fact the same. Show me the new version before you save anything.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'The rewrite in the chat first. Nothing is saved until you say so.', 'saddle' ),
				'uses'   => array( 'saddle/get-page', 'saddle/update-page' ),
			),
			array(
				'group'  => 'writing',
				'title'  => __( 'Find every page that mentions something', 'saddle' ),
				'prompt' => __( 'Search my site for every post or page that mentions our old phone number, and list them with links so I can see what needs updating.', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'A list of matches with links. Useful before a rebrand, a price change, or a move.', 'saddle' ),
				'uses'   => array( 'saddle/search-content' ),
			),
			array(
				'group'  => 'writing',
				'title'  => __( 'Undo something that went wrong', 'saddle' ),
				'prompt' => __( 'Show me the revision history for my Pricing page so I can see what changed and when.', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'The list of saved revisions. Every change Saddle makes is a normal WordPress revision, so the editor can restore any of them.', 'saddle' ),
				'uses'   => array( 'saddle/list-post-revisions' ),
			),

			/* -------------------------------------------------------------- pages */

			array(
				'group'  => 'pages',
				'title'  => __( 'Build a page that matches my site', 'saddle' ),
				'prompt' => __( 'Build me a new Services page: a short intro, three services with a sentence each, and a contact button at the bottom. Use my theme\'s own colours, fonts and spacing rather than inventing any. Save it as a draft.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'A draft page built from real editor blocks, styled from your theme. It opens and edits in WordPress exactly like something you made by hand.', 'saddle' ),
				'uses'   => array( 'saddle/create-page', 'saddle/set-blocks', 'saddle/get-design-system' ),
			),
			array(
				'group'  => 'pages',
				'title'  => __( 'Change one thing without touching the rest', 'saddle' ),
				'prompt' => __( 'On my home page, change the big heading to "Roofing you can forget about" and leave everything else exactly as it is.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'Just that heading changes. Blocks are addressed one at a time, so the rest of the layout is untouched.', 'saddle' ),
				'uses'   => array( 'saddle/get-blocks', 'saddle/edit-block' ),
			),
			array(
				'group'  => 'pages',
				'title'  => __( 'Add a section to a page I already have', 'saddle' ),
				'prompt' => __( 'Add a frequently asked questions section to the bottom of my Pricing page, with the five questions customers actually ask us. Match the styling of the rest of the page.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'A new section at the end, in your existing styling. Ask it to move the section if you want it somewhere else.', 'saddle' ),
				'uses'   => array( 'saddle/get-blocks', 'saddle/add-block', 'saddle/list-section-recipes' ),
			),
			array(
				'group'  => 'pages',
				'title'  => __( 'Find out what my design system actually is', 'saddle' ),
				'prompt' => __( 'What colours, font sizes and spacing does my theme define? List them the way you would use them, and tell me anything that looks inconsistent.', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'Your real palette and type scale. Worth running once so you know what it will build with.', 'saddle' ),
				'uses'   => array( 'saddle/get-design-system', 'saddle/get-design-tokens' ),
			),

			/* --------------------------------------------------------------- divi */

			array(
				'group'  => 'divi',
				'title'  => __( 'Build a Divi page properly', 'saddle' ),
				'prompt' => __( 'Build a new Divi page for our winter offer: a hero with a heading and a button, three feature cards, and a call to action band. Use real Divi modules and my existing global colours and presets, not a code module with HTML in it. Save it as a draft.', 'saddle' ),
				'tier'   => 'write',
				'pro'    => true,
				'expect' => __( 'A draft page that opens in the Divi Visual Builder and edits normally. If you get a code module full of HTML, say so and ask it to rebuild with real modules.', 'saddle' ),
				'uses'   => array( 'saddle/divi-set-page' ),
			),
			array(
				'group'  => 'divi',
				'title'  => __( 'Restyle the whole site at once', 'saddle' ),
				'prompt' => __( 'Change our Divi global accent colour to a warmer orange and update the button preset to match, so every page picks it up. Show me what will change before you do it.', 'saddle' ),
				'tier'   => 'write',
				'pro'    => true,
				'expect' => __( 'A preview of the change, then one edit that carries across every page using those globals. This is the thing that is painful by hand.', 'saddle' ),
				'uses'   => array( 'saddle/divi-get-page' ),
			),
			array(
				'group'  => 'divi',
				'title'  => __( 'Fix a page that looks wrong', 'saddle' ),
				'prompt' => __( 'Look at my Divi home page, tell me what is off about the spacing and alignment, and fix the worst of it. Show me the list before you change anything.', 'saddle' ),
				'tier'   => 'write',
				'pro'    => true,
				'expect' => __( 'A list of concrete problems pointed at specific modules, then fixes you approved.', 'saddle' ),
				'uses'   => array( 'saddle/divi-get-page' ),
			),

			/* ------------------------------------------------------------- images */

			array(
				'group'  => 'images',
				'title'  => __( 'Find a photo without leaving the chat', 'saddle' ),
				'prompt' => __( 'Find me a photo of a quiet workshop bench for the top of my About page, add it to my media library, and set it as the featured image.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'A properly licensed photo in your library with the photographer credited automatically. Needs a free Unsplash key under Saddle then Integrations.', 'saddle' ),
				'uses'   => array( 'saddle/unsplash-search', 'saddle/unsplash-import', 'saddle/update-media' ),
			),
			array(
				'group'  => 'images',
				'title'  => __( 'Fix missing alt text across the site', 'saddle' ),
				'prompt' => __( 'Find images in my media library with no alt text, and write proper alt text for each one describing what is actually in the picture.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'Alt text written and saved. This is an accessibility job most sites never get round to, and it is tedious by hand.', 'saddle' ),
				'uses'   => array( 'saddle/list-media', 'saddle/update-media' ),
			),

			/* ----------------------------------------------------------- checking */

			array(
				'group'  => 'checking',
				'title'  => __( 'Let it check its own work', 'saddle' ),
				'prompt' => __( 'Check the page you just built, tell me the score and what is wrong with it, fix what you can, then check it again.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'A score out of 100 with named problems, then a second score after the fixes. Worth asking every time it builds something, and the single most useful habit here.', 'saddle' ),
				'uses'   => array( 'saddle/verify-page', 'saddle/lint-page' ),
			),
			array(
				'group'  => 'checking',
				'title'  => __( 'See it before anyone else does', 'saddle' ),
				'prompt' => __( 'Give me a preview link for that draft so I can look at it in my real theme before it goes live.', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'A temporary private link that search engines will not index. The checks are server-side, so this is how you judge the actual pixels.', 'saddle' ),
				'uses'   => array( 'saddle/get-preview-url' ),
			),

			/* ----------------------------------------------------------- teaching */

			array(
				'group'  => 'teaching',
				'title'  => __( 'Make drafts the default', 'saddle' ),
				'prompt' => __( 'From now on, always save new posts as drafts for me to review, and never publish anything unless I say the word publish.', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'Save this on the Guidance screen instead and it applies to every conversation, in every app, without you repeating it.', 'saddle' ),
				'uses'   => array( 'saddle/get-instructions' ),
			),
			array(
				'group'  => 'teaching',
				'title'  => __( 'Teach it your house style', 'saddle' ),
				'prompt' => __( 'Remember that we write in British English, we never use exclamation marks, and we call our customers clients rather than users.', 'saddle' ),
				'tier'   => 'write',
				'expect' => __( 'Facts that survive into the next conversation, so you stop re-explaining your own site every time you start a chat.', 'saddle' ),
				'uses'   => array( 'saddle/remember' ),
			),
			array(
				'group'  => 'teaching',
				'title'  => __( 'Catch up on what changed', 'saddle' ),
				'prompt' => __( 'What has changed on my site recently, and which of it did an AI do rather than a person?', 'saddle' ),
				'tier'   => 'read',
				'expect' => __( 'A summary of recent changes. Every change Saddle makes is recorded, including the ones it was refused.', 'saddle' ),
				'uses'   => array( 'saddle/recall-changes' ),
			),
		);

		foreach ( $recipes as $i => $recipe ) {
			$recipes[ $i ]['pro'] = ! empty( $recipe['pro'] );
		}

		/**
		 * Filter the cookbook recipes.
		 *
		 * The seam an addon uses to contribute its own, rather than this file
		 * growing builder-specific knowledge it has no business holding.
		 *
		 * @param array[] $recipes Recipe rows.
		 */
		return apply_filters( 'saddle_cookbook_recipes', $recipes );
	}

	/**
	 * Recipes plus the facts the UI needs to label them honestly: the site's
	 * current level, and whether Pro is actually here.
	 *
	 * Pro recipes are NOT removed when Pro is absent. Free carries no
	 * builder-specific code, and a row of prose naming Divi is not that — it is
	 * the only honest way to show a Divi user what they would get, and hiding it
	 * would make the cookbook look thinner than the product is. The UI labels
	 * them; it does not pretend they are unavailable forever.
	 *
	 * @return array
	 */
	public static function payload() {
		return array(
			'groups'    => self::groups(),
			'recipes'   => self::recipes(),
			// get_site_tier(), not get_tier(): this is a screen reporting
			// configuration to the owner, and the house rule is that the ceiling
			// a credential carries belongs to call decisions, never to what the
			// dashboard says the site is set to.
			'site_tier' => class_exists( 'Saddle_Capabilities' ) ? Saddle_Capabilities::get_site_tier() : 'read',
			// Pro's own version constant, checked at the point of use. A presence
			// check is not builder-specific code, and there is no filter to
			// invent for something Pro already declares about itself.
			'has_pro'   => defined( 'SADDLE_PRO_VERSION' ),
		);
	}
}
