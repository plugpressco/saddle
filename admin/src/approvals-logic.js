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
 * "current_status" → "Current status".
 *
 * @param {string} key A preview field name.
 * @return {string} A label.
 */
function labelFor( key ) {
	const words = String( key ).replace( /[_-]+/g, ' ' ).trim();
	return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
}

/**
 * One preview value as short text: Yes/No for booleans, a list joined with
 * commas, anything deeper as compact JSON.
 *
 * @param {*} value Any JSON value.
 * @return {string} Text.
 */
function shortValue( value ) {
	if ( typeof value === 'boolean' ) {
		return value ? __( 'Yes', 'saddle' ) : __( 'No', 'saddle' );
	}
	if (
		Array.isArray( value ) &&
		value.every( ( v ) => typeof v !== 'object' || v === null )
	) {
		return value.join( ', ' );
	}
	if ( value !== null && typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}

/**
 * A stored preview as label/value rows the owner can read, instead of JSON.
 * A before/after preview gives one row per field that changes, with both
 * values. Empty values are left out.
 *
 * @param {*} preview The stored preview.
 * @return {Array<{label: string, value: string, before?: string}>} Rows.
 */
export function previewRows( preview ) {
	const pair = beforeAfter( preview );
	if ( pair && isPlain( pair.before ) && isPlain( pair.after ) ) {
		const keys = [
			...new Set( [
				...Object.keys( pair.before ),
				...Object.keys( pair.after ),
			] ),
		];
		return keys
			.filter(
				( k ) =>
					JSON.stringify( pair.before[ k ] ) !==
					JSON.stringify( pair.after[ k ] )
			)
			.map( ( k ) => ( {
				label: labelFor( k ),
				before: shortValue( pair.before[ k ] ?? '' ),
				value: shortValue( pair.after[ k ] ?? '' ),
			} ) );
	}
	if ( ! isPlain( preview ) ) {
		return [];
	}
	return Object.keys( preview )
		.filter( ( k ) => preview[ k ] !== null && preview[ k ] !== '' )
		.map( ( k ) => ( {
			label: labelFor( k ),
			value: shortValue( preview[ k ] ),
		} ) );
}

function isPlain( value ) {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}
