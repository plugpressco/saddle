/**
 * Pure helpers for "Needs your OK" (#287): the wording of a request and how
 * long ago it was asked. No React, no network.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * "3 min ago" for a Unix timestamp, against `now` (Unix seconds).
 *
 * @param {number} createdAt Unix seconds.
 * @param {number} now       Unix seconds.
 * @return {string} Relative time.
 */
export function askedAgo( createdAt, now = Math.floor( Date.now() / 1000 ) ) {
	const seconds = Math.max( 0, now - createdAt );
	if ( seconds < 60 ) {
		return __( 'just now', 'saddle' );
	}
	const minutes = Math.floor( seconds / 60 );
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: minutes. */
			_n( '%d min ago', '%d min ago', minutes, 'saddle' ),
			minutes
		);
	}
	const hours = Math.floor( minutes / 60 );
	return sprintf(
		/* translators: %d: hours. */
		_n( '%d hour ago', '%d hours ago', hours, 'saddle' ),
		hours
	);
}

/**
 * Newest first, then by id so two asked in one second keep a stable order.
 * Does not mutate its argument.
 *
 * @param {Object[]} approvals Rows from GET /approvals.
 * @return {Object[]} Sorted copy.
 */
export function sortApprovals( approvals ) {
	return [ ...( approvals || [] ) ].sort(
		( a, b ) => b.created_at - a.created_at || b.id - a.id
	);
}

/**
 * "Claude wants to publish “Spring sale”": the app, then the gate's one-line
 * summary with its first letter lowered so it reads as a sentence.
 *
 * @param {Object} approval A row from GET /approvals.
 * @return {string} Title.
 */
export function requestTitle( approval ) {
	const summary = String( approval.summary || '' ).trim();
	const app = approval.app || __( 'An app', 'saddle' );
	if ( ! summary ) {
		return sprintf(
			/* translators: %s: app name. */
			__( '%s wants to make a change', 'saddle' ),
			app
		);
	}
	// Keep acronyms and quoted names: only lower a plain capitalised word.
	const lowered = /^[A-Z][a-z]/.test( summary )
		? summary.charAt( 0 ).toLowerCase() + summary.slice( 1 )
		: summary;
	return sprintf(
		/* translators: 1: app name, 2: what it wants to do, e.g. "publish “Spring sale”". */
		__( '%1$s wants to %2$s', 'saddle' ),
		app,
		lowered
	);
}

/**
 * The before/after pair of a stored preview, when it has one.
 *
 * @param {*} preview The stored preview.
 * @return {{before: *, after: *}|null} The pair, or null.
 */
export function beforeAfter( preview ) {
	if (
		preview &&
		typeof preview === 'object' &&
		( 'before' in preview || 'after' in preview )
	) {
		return { before: preview.before ?? null, after: preview.after ?? null };
	}
	return null;
}

/**
 * A preview value as readable text.
 *
 * @param {*} value Any JSON value.
 * @return {string} Text.
 */
export function showValue( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}
	return typeof value === 'string' ? value : JSON.stringify( value, null, 2 );
}
