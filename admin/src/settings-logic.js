/**
 * Pure helpers for Saddle → Settings: which tools "Turn off single tools"
 * lists, and the Safety switches' values. No React, no network.
 */

/**
 * The tools "Turn off single tools" lists: the ones that can run on this
 * site. A tool for a plugin that isn't active (Yoast, Rank Math, AIOSEO,
 * Divi 5, WooCommerce) comes with `available: false` and is left out; a tool
 * without the flag, from an older server, is listed.
 *
 * @param {Array} caps Every tool, from `GET /capabilities`.
 * @return {Array} The tools to list.
 */
export const runnableTools = ( caps ) =>
	( Array.isArray( caps ) ? caps : [] ).filter(
		( t ) => !! t && false !== t.available
	);

/**
 * The three Safety values, from GET or POST /preferences.
 *
 * @param {Object} res The answer.
 * @return {{drafts_only: boolean, rehearsal: boolean, domain_enforced: boolean}} Values.
 */
export const safetyPrefs = ( res ) => ( {
	drafts_only: !! ( res && res.drafts_only ),
	rehearsal: !! ( res && res.rehearsal ),
	domain_enforced: !! ( res && res.domain && res.domain.enforced ),
} );
