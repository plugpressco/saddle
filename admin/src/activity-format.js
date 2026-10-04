/**
 * Shared formatting for audit-log entries. Both the Home "Recent activity"
 * preview and the full Activity screen read the same `audit-log` records, so
 * they share one set of pure helpers here — same timestamp parsing, same
 * labels — instead of each re-deriving (and drifting on) them. No JSX.
 */
import { __, sprintf } from '@wordpress/i18n';
import { bareToolName } from './onboarding-logic';

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
	return entry.summary || entry.action || '';
};

const TIER_NAMES = {
	read: __( 'Read only', 'saddle' ),
	write: __( 'Edit content', 'saddle' ),
	admin: __( 'Manage the site', 'saddle' ),
};

/**
 * An entry's action in words the owner knows: the tool's own label ("Update
 * module settings"), not its name. A blocked call says why when it was the
 * access level: "Blocked · Update module settings · needs Admin".
 *
 * @param {Object}   entry Audit-log entry.
 * @param {Object[]} caps  The capabilities list (`GET /capabilities`).
 * @return {string} Label.
 */
export const actionLabel = ( entry, caps ) => {
	const short = bareToolName(
		String( entry.action || '' ).replace( /^denied-/, '' )
	);
	const cap = ( Array.isArray( caps ) ? caps : [] ).find(
		( c ) => c.short === short
	);
	let name = cap && cap.label ? cap.label : '';
	const called = 'denied' === entry.type || 'rehearsed' === entry.type;
	// Not a tool Saddle knows (an event such as "oauth-authorized"): its
	// stored summary already says it in words.
	if ( ! name && ! called && entry.summary ) {
		return entry.summary;
	}
	if ( ! name && short ) {
		const plain = short.replace( /[-_]+/g, ' ' );
		name = plain.charAt( 0 ).toUpperCase() + plain.slice( 1 );
	}
	if ( ! name ) {
		return entry.summary || '';
	}

	if ( entry.type === 'denied' ) {
		const need = 'tier' === entry.target && cap && TIER_NAMES[ cap.tier ];
		return need
			? sprintf(
					/* translators: 1: a tool's name, 2: the access level it needs. */
					__( 'Blocked · %1$s · needs %2$s', 'saddle' ),
					name,
					need
			  )
			: sprintf(
					/* translators: %s: a tool's name. */
					__( 'Blocked · %s', 'saddle' ),
					name
			  );
	}
	if ( entry.type === 'rehearsed' ) {
		return sprintf(
			/* translators: %s: a tool's name. */
			__( 'Rehearsed · %s', 'saddle' ),
			name
		);
	}
	return name;
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

/**
 * The line a feed row shows: a blocked or rehearsed call as the tool's own
 * name ("Blocked · Update option · needs Manage the site"), anything else as
 * the summary the log stored.
 *
 * @param {Object}   entry Audit-log entry.
 * @param {Object[]} caps  The capabilities list.
 * @return {string} Text.
 */
export const rowText = ( entry, caps ) =>
	'denied' === entry.type || 'rehearsed' === entry.type
		? actionLabel( entry, caps )
		: String( entry.summary || '' );

/**
 * A row's tooltip: the whole line, which the row may cut short, and who
 * made it on the next line.
 *
 * @param {string} text The row's line.
 * @param {string} who  From madeBy(), or ''.
 * @return {string|undefined} The tooltip, or undefined when both are empty.
 */
export const rowTitle = ( text, who ) =>
	[ text, who ].filter( Boolean ).join( '\n' ) || undefined;

/**
 * Whether the owner can undo an entry from the feed: a change that ran,
 * with something recorded to put back. Reads the entry's log `id` and its
 * `undo` state ('available', 'undone' or 'not-recorded') from GET /audit-log.
 *
 * @param {Object} entry Audit-log entry.
 * @return {boolean} True when Undo applies.
 */
export const canUndo = ( entry ) =>
	!! entry &&
	'executed' === ( entry.type || 'executed' ) &&
	'available' === entry.undo &&
	Number.isInteger( entry.id ) &&
	entry.id > 0;

/**
 * Whether an entry was undone already.
 *
 * @param {Object} entry Audit-log entry.
 * @return {boolean} True when it was.
 */
export const wasUndone = ( entry ) => !! entry && 'undone' === entry.undo;

/**
 * What an undo preview (POST /undo without a token) says about one entry:
 * what comes back, or why nothing can.
 *
 * @param {Object} res The preview.
 * @param {number} id  The entry's log id.
 * @return {{ready: boolean, steps: string[], reasons: string[], token: string}} The plan.
 */
export const undoPlan = ( res, id ) => {
	const entries = res && Array.isArray( res.entries ) ? res.entries : [];
	const report = entries.find( ( e ) => e && e.id === id );
	if ( ! report ) {
		return {
			ready: false,
			steps: [],
			reasons: [
				__( 'This change is no longer in the activity log.', 'saddle' ),
			],
			token: '',
		};
	}
	const token = res && res.confirm_token ? String( res.confirm_token ) : '';
	if ( 'ready' === report.status && token ) {
		return {
			ready: true,
			steps: Array.isArray( report.steps ) ? report.steps : [],
			reasons: [],
			token,
		};
	}
	const reasons = Array.isArray( report.reasons ) ? report.reasons : [];
	return {
		ready: false,
		steps: [],
		reasons: reasons.length
			? reasons
			: [ __( 'This change can’t be undone.', 'saddle' ) ],
		token: '',
	};
};

/**
 * What a confirmed undo (POST /undo with the token) did: done, or the
 * reason it stopped.
 *
 * @param {Object} res The answer.
 * @return {{done: boolean, message: string}} The outcome.
 */
export const undoOutcome = ( res ) => {
	if ( res && res.undone > 0 ) {
		return { done: true, message: __( 'Change undone.', 'saddle' ) };
	}
	const report = ( ( res && res.entries ) || [] ).find(
		( e ) => e && Array.isArray( e.reasons ) && e.reasons.length
	);
	return {
		done: false,
		message: report
			? report.reasons[ 0 ]
			: __( 'Nothing was undone. Try again.', 'saddle' ),
	};
};

/**
 * Who made an entry, in words: "via Claude Code" for an app; "by you" for
 * the person looking, who acted in wp-admin (an approval, a new role); "by
 * Jane" for another WordPress user, by display name when the log has it.
 *
 * @param {Object} entry Audit-log entry.
 * @param {string} me    The login of the person looking (saddleData.user).
 * @return {string} The line, or '' when the entry names no one.
 */
export const madeBy = ( entry, me = '' ) => {
	if ( entry && entry.app ) {
		return sprintf(
			/* translators: %s: the app that made the change, such as "Claude Code". */
			__( 'via %s', 'saddle' ),
			entry.app
		);
	}
	if ( ! entry || ! entry.user ) {
		return '';
	}
	if ( me && entry.user === me ) {
		return __( 'by you', 'saddle' );
	}
	return sprintf(
		/* translators: %s: the name of the person who made the change. */
		__( 'by %s', 'saddle' ),
		entry.user_name || entry.user
	);
};
