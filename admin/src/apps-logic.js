/**
 * Pure helpers for AI apps (#309): the one line under a connected app's
 * name, and which apps have really connected. No React, no network.
 */
import { __, sprintf } from '@wordpress/i18n';
import { relativeWhen } from './activity-format';

/**
 * When the app last reached the site: its last tool call, else its last
 * request. 0 when it never has. A key that was made but never pasted into
 * an app has neither.
 *
 * @param {Object} c A row from GET /connections; times are Unix seconds.
 * @return {number} Unix seconds, or 0.
 */
export function usedAt( c ) {
	return Number( c && ( c.last_tool_at || c.last_seen_at ) ) || 0;
}

/**
 * Whether an app has ever reached the site with its key or sign-in.
 *
 * @param {Object} c A row from GET /connections.
 * @return {boolean} True once the app has made a request.
 */
export function hasBeenUsed( c ) {
	return usedAt( c ) > 0;
}

/**
 * The apps that have connected for real, by app key, for the green dot on
 * the Connect an app tiles. A key that was never used does not count.
 *
 * @param {Array} connections Rows from GET /connections.
 * @return {Set<string>} App keys.
 */
export function usedAppKeys( connections ) {
	return new Set(
		( Array.isArray( connections ) ? connections : [] )
			.filter( ( c ) => c && c.app && hasBeenUsed( c ) )
			.map( ( c ) => c.app )
	);
}

/**
 * The one fact a connected app's row carries under its name: when it was
 * last used or when it connected, whichever is more recent, or when its key
 * was made while no app has used it yet. Never the role (the dropdown
 * beside the row shows it).
 *
 * @param {Object} c A row from GET /connections; times are Unix seconds.
 * @return {{what: string, at: number}|null} `used`, `connected` or `unused`
 *                                           and when, or null when nothing
 *                                           is known.
 */
export function lastFact( c ) {
	const used = usedAt( c );
	const created = Number( c && c.created_at ) || 0;
	if ( ! used ) {
		return created ? { what: 'unused', at: created } : null;
	}
	if ( used >= created ) {
		return { what: 'used', at: used };
	}
	return { what: 'connected', at: created };
}

/**
 * "Last used 2 minutes ago", "Connected yesterday" or "Key made 11 minutes
 * ago, not used yet", or '' when nothing is known (the row then has no
 * second line).
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} One short line.
 */
export function metaLine( c ) {
	const fact = lastFact( c );
	if ( ! fact ) {
		return '';
	}
	const when = relativeWhen( new Date( fact.at * 1000 ) );
	if ( 'used' === fact.what ) {
		return sprintf(
			/* translators: %s: when, such as "2 minutes ago". */
			__( 'Last used %s', 'saddle' ),
			when
		);
	}
	if ( 'connected' === fact.what ) {
		return sprintf(
			/* translators: %s: when, such as "yesterday". */
			__( 'Connected %s', 'saddle' ),
			when
		);
	}
	return 'oauth' === c.kind
		? sprintf(
				/* translators: %s: when, such as "11 minutes ago". */
				__( 'Signed in %s, not used yet', 'saddle' ),
				when
		  )
		: sprintf(
				/* translators: %s: when, such as "11 minutes ago". */
				__( 'Key made %s, not used yet', 'saddle' ),
				when
		  );
}
