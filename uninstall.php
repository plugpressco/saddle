<?php
/**
 * Uninstall cleanup for Saddle.
 *
 * Removes the access-tier option and any leftover approval tokens. Application
 * Passwords are intentionally NOT deleted here — they are core user data the
 * user may want to keep or revoke deliberately.
 *
 * @package Saddle
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Every option Saddle persists. Keep this list in sync with the option
// constants in class-saddle-capabilities.php and class-saddle-context.php.
$saddle_options = array(
	'saddle_access_tier',       // Saddle_Capabilities::OPTION.
	'saddle_onboarded',
	'saddle_user_context',      // Saddle_Context::USER_OPTION.
	'saddle_disabled_abilities', // Saddle_Capabilities::DISABLED_OPTION.
	'saddle_paused',            // Saddle_Capabilities::PAUSED_OPTION.
	'saddle_tier_domain',       // Saddle_Capabilities::TIER_DOMAIN_OPTION.
	'saddle_enforce_tier_domain', // Saddle_Capabilities::ENFORCE_DOMAIN_OPTION.
	'saddle_memory_recent_changes',
	'saddle_memory_recent_limit',
	'saddle_memory_max_entries',      // Saddle_Memory::OPTION_MAX_ENTRIES.
	'saddle_memory_autoinject_agent', // Saddle_Memory::OPTION_AUTOINJECT.
	'saddle_memory_core_budget',      // Saddle_Memory::OPTION_CORE_BUDGET.
	'saddle_preview_secret',          // Saddle_Preview::OPTION.
	'saddle_unsplash_access_key',     // Saddle_Unsplash::OPTION.
);
foreach ( $saddle_options as $saddle_option ) {
	delete_option( $saddle_option );
}

// Per-user data: admin theme preference, credential last-4 hints, and the
// issued-credential markers scoping keys on.
delete_metadata( 'user', 0, 'saddle_admin_theme', '', true );
delete_metadata( 'user', 0, 'saddle_client_hints', '', true );
delete_metadata( 'user', 0, 'saddle_issued_credentials', '', true );

// Clear scheduled GC.
$saddle_gc_timestamp = wp_next_scheduled( 'saddle_gc_tokens' );
if ( $saddle_gc_timestamp ) {
	wp_unschedule_event( $saddle_gc_timestamp, 'saddle_gc_tokens' );
}

// Remove the managed .htaccess block the connection self-check may have added.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-saddle-connection.php';
if ( class_exists( 'Saddle_Connection' ) ) {
	Saddle_Connection::remove_htaccess_fix();
}

// Remove any leftover approval tokens, activity-log entries, installed
// skills, and memory entries (private CPTs). Delete in fixed-size batches:
// approval tokens have no count cap (only a time-based GC), so a busy site
// could have accumulated many, and loading every id at once could exhaust
// memory during uninstall.
do {
	$saddle_posts = get_posts(
		array(
			'post_type'      => array( 'saddle_approval', 'saddle_log', 'saddle_skill', 'saddle_memory' ),
			'post_status'    => 'any',
			'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Fixed-size delete batch; the do/while drains the rest.
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$saddle_batch = count( $saddle_posts );
	foreach ( $saddle_posts as $saddle_post_id ) {
		wp_delete_post( (int) $saddle_post_id, true );
	}
} while ( 200 === $saddle_batch );

// Remove the Media Tags taxonomy's terms (and their relationships). The
// taxonomy isn't registered during uninstall, so re-register it minimally
// first or the term APIs refuse to touch it. The _saddle_unsplash_* postmeta
// (photo id, photographer, links) is deliberately KEPT — attribution
// provenance on the user's own media is user data, same stance as the
// Application Passwords note above.
register_taxonomy( 'saddle_media_tag', 'attachment' );
$saddle_terms = get_terms(
	array(
		'taxonomy'   => 'saddle_media_tag',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( is_array( $saddle_terms ) ) {
	foreach ( $saddle_terms as $saddle_term_id ) {
		wp_delete_term( (int) $saddle_term_id, 'saddle_media_tag' );
	}
}
