/**
 * Shared formatting for audit-log entries. Both the Home "Recent activity"
 * preview and the full Activity screen read the same `audit-log` records, so
 * they share one set of pure helpers here — same timestamp parsing, same
 * labels — instead of each re-deriving (and drifting on) them. No JSX.
 */
import { __ } from '@wordpress/i18n';

const WHEN_FMT = new Intl.DateTimeFormat( undefined, {
	dateStyle: 'medium',
	timeStyle: 'short',
} );
const RELATIVE_FMT = new Intl.RelativeTimeFormat( undefined, {
	numeric: 'auto',
} );

/**
 * Parse a stored GMT timestamp ("YYYY-MM-DD HH:MM:SS") into a Date. Normalizes
 * to ISO 8601 (space → "T", trailing "Z") so every caller parses identically.
 *
 * @param {string} gmt Timestamp from the audit-log API.
 * @return {Date|null} Parsed date, or null if empty/invalid.
 */
export const parseEntryDate = ( gmt ) => {
	if ( ! gmt ) {
		return null;
	}
	const iso = gmt.replace( ' ', 'T' );
	const d = new Date( iso.endsWith( 'Z' ) ? iso : `${ iso }Z` );
	return isNaN( d.getTime() ) ? null : d;
};

/**
 * "5 minutes ago" for fresh entries, the plain date once it's history.
 *
 * @param {Date|null} d Parsed entry date.
 * @return {string} Human relative/absolute time, or '' when there is no date.
 */
export const relativeWhen = ( d ) => {
	if ( ! d ) {
		return '';
	}
	const mins = Math.round( ( d.getTime() - Date.now() ) / 60000 );
	if ( mins > -1 ) {
		return RELATIVE_FMT.format( 0, 'minute' ); // "now"-ish
	}
	if ( mins > -60 ) {
		return RELATIVE_FMT.format( mins, 'minute' );
	}
	const hours = Math.round( mins / 60 );
	if ( hours > -24 ) {
		return RELATIVE_FMT.format( hours, 'hour' );
	}
	const days = Math.round( hours / 24 );
	if ( days > -7 ) {
		return RELATIVE_FMT.format( days, 'day' );
	}
	return WHEN_FMT.format( d );
};

/**
 * Day-group heading ("Today" / "Yesterday" / "Mon, Jul 7").
 *
 * @param {Date} d Parsed entry date.
 * @return {string} Group label.
 */
export const dayLabel = ( d ) => {
	const today = new Date();
	const that = new Date( d.getFullYear(), d.getMonth(), d.getDate() );
	const now = new Date(
		today.getFullYear(),
		today.getMonth(),
		today.getDate()
	);
	const days = Math.round( ( now - that ) / 86400000 );
	if ( days === 0 ) {
		return __( 'Today', 'saddle' );
	}
	if ( days === 1 ) {
		return __( 'Yesterday', 'saddle' );
	}
	return d.toLocaleDateString( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		...( d.getFullYear() !== today.getFullYear()
			? { year: 'numeric' }
			: {} ),
	} );
};

/**
 * Wall-clock time for an entry ("3:04 PM").
 *
 * @param {Date} d Parsed entry date.
 * @return {string} Localized time.
 */
export const clock = ( d ) =>
	d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );

const VERBS = {
	create: __( 'Created', 'saddle' ),
	update: __( 'Updated', 'saddle' ),
	delete: __( 'Deleted', 'saddle' ),
	upload: __( 'Uploaded', 'saddle' ),
};

/**
 * Short one-line label for the compact Home feed, derived from action + target.
 * The stored summary doubles as verbose approval-preview text, so Home keeps it
 * only as a hover title. Denied entries carry a "Blocked" badge already, so the
 * redundant "Blocked: " prefix is stripped.
 *
 * @param {Object} entry Audit-log entry.
 * @return {string} Compact label.
 */
export const shortLabel = ( entry ) => {
	if ( entry.type === 'denied' ) {
		return (
			( entry.summary || '' ).replace( /^Blocked:\s*/i, '' ) ||
			__( 'Refused', 'saddle' )
		);
	}
	const m = ( entry.action || '' ).match(
		/^(create|update|delete|upload)[-_](post|page|media|category|tag)/
	);
	if ( m && VERBS[ m[ 1 ] ] ) {
		const at = entry.target ? ` #${ entry.target }` : '';
		return `${ VERBS[ m[ 1 ] ] } ${ m[ 2 ] }${ at }`;
	}
	return entry.summary || entry.action || '—';
};

/**
 * Group entries into consecutive day buckets, preserving the API's newest-first
 * order. Each item is the entry augmented with its parsed `d` (Date|null).
 *
 * @param {Object[]} entries Audit-log entries in API order.
 * @return {{label: string, items: Object[]}[]} Day groups.
 */
export const groupByDay = ( entries ) => {
	const groups = [];
	entries.forEach( ( e ) => {
		const d = parseEntryDate( e.date );
		const label = d ? dayLabel( d ) : __( 'Earlier', 'saddle' );
		const last = groups[ groups.length - 1 ];
		if ( last && last.label === label ) {
			last.items.push( { ...e, d } );
		} else {
			groups.push( { label, items: [ { ...e, d } ] } );
		}
	} );
	return groups;
};
