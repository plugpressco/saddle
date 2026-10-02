/**
 * Pure helpers for AI apps (#309): the one line under a connected app's
 * name. No React, no network.
 */
import { __, sprintf } from '@wordpress/i18n';
import { relativeWhen } from './activity-format';

/**
 * The one fact a connected app's row carries under its name: when it was
 * last used or when it connected, whichever is more recent. Never the role
 * (the dropdown beside the row shows it) and never how the app signs in.
 *
 * "Used" is the app's last tool call, else its last request.
 *
 * @param {Object} c A row from GET /connections; times are Unix seconds.
 * @return {{what: string, at: number}|null} `used` or `connected` and when,
 *                                           or null when neither is known.
 */
export function lastFact( c ) {
	const used = Number( c.last_tool_at || c.last_seen_at ) || 0;
	const connected = Number( c.created_at ) || 0;
	if ( used && used >= connected ) {
		return { what: 'used', at: used };
	}
	if ( connected ) {
		return { what: 'connected', at: connected };
	}
	return null;
}

/**
 * "Last used 2 minutes ago" or "Connected yesterday", or '' when nothing is
 * known (the row then has no second line).
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
	return 'used' === fact.what
		? sprintf(
				/* translators: %s: when, such as "2 minutes ago". */
				__( 'Last used %s', 'saddle' ),
				when
		  )
		: sprintf(
				/* translators: %s: when, such as "yesterday". */
				__( 'Connected %s', 'saddle' ),
				when
		  );
}
