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
 * "children_move_up" → "children move up".
 *
 * @param {string} key A field name.
 * @return {string} The words.
 */
function words( key ) {
	return String( key ).replace( /[_-]+/g, ' ' ).trim();
}

/**
 * "Children move up": a field name as a label, when there is no better one.
 *
 * @param {string} key A preview field name.
 * @return {string} A label.
 */
function plainLabel( key ) {
	const text = words( key );
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}

/**
 * The preview fields the owner reads, in the owner's words. A field not
 * listed keeps a plain version of its name.
 *
 * @return {Object} Labels by field name.
 */
const fieldLabels = () => ( {
	type: __( 'Type', 'saddle' ),
	title: __( 'Title', 'saddle' ),
	status: __( 'Status', 'saddle' ),
	current_status: __( 'Status now', 'saddle' ),
	new_status: __( 'New status', 'saddle' ),
	recoverable: __( 'Can be restored', 'saddle' ),
	changes: __( 'What changes', 'saddle' ),
	mime_type: __( 'File type', 'saddle' ),
	url: __( 'File', 'saddle' ),
	menu: __( 'Menu', 'saddle' ),
	children: __( 'Blocks inside', 'saddle' ),
	children_move_up: __( 'Sub-items that move up', 'saddle' ),
	name: __( 'Setting', 'saddle' ),
	current_value: __( 'Value now', 'saddle' ),
	new_value: __( 'New value', 'saddle' ),
	module: __( 'Module', 'saddle' ),
	items: __( 'Items', 'saddle' ),
	note: __( 'Note', 'saddle' ),
} );

/**
 * Fields that are for the app, not the owner: ids, tool names, block
 * addresses, and a design spec as code. The title already names the item.
 */
const HIDDEN = [ 'id', 'tool', 'address', 'spec', 'store' ];

/**
 * @param {string} key A preview field name.
 * @return {string} Its label.
 */
function labelFor( key ) {
	const known = fieldLabels();
	return Object.prototype.hasOwnProperty.call( known, key )
		? known[ key ]
		: plainLabel( key );
}

/**
 * WordPress's post statuses, as the post list names them.
 *
 * @return {Object} Words by status.
 */
const statusWords = () => ( {
	publish: __( 'Published', 'saddle' ),
	draft: __( 'Draft', 'saddle' ),
	'auto-draft': __( 'Draft', 'saddle' ),
	pending: __( 'Pending review', 'saddle' ),
	private: __( 'Private', 'saddle' ),
	future: __( 'Scheduled', 'saddle' ),
	trash: __( 'In the trash', 'saddle' ),
} );

/**
 * Content types, as the admin menu names them.
 *
 * @return {Object} Words by type.
 */
const typeWords = () => ( {
	post: __( 'Post', 'saddle' ),
	page: __( 'Page', 'saddle' ),
	attachment: __( 'Media', 'saddle' ),
	plugin: __( 'Plugin', 'saddle' ),
	theme: __( 'Theme', 'saddle' ),
} );

/**
 * One field's value in words: a status or a type by its name in WordPress
 * (anything else as stored), and the fields a change touches as a list of
 * names, not their contents.
 *
 * @param {string} key   A preview field name.
 * @param {*}      value Its value.
 * @return {string} Text.
 */
function readable( key, value ) {
	if ( typeof value === 'string' ) {
		const map =
			{
				status: statusWords(),
				current_status: statusWords(),
				new_status: statusWords(),
				type: typeWords(),
			}[ key ] || null;
		if ( map && Object.prototype.hasOwnProperty.call( map, value ) ) {
			return map[ value ];
		}
	}
	if ( 'changes' === key && isPlain( value ) ) {
		return Object.keys( value ).map( words ).join( ', ' );
	}
	return shortValue( value );
}

/**
 * Whether a field is left out: an internal one, or the second way a delete
 * preview says it can't be undone ("Can be restored" already says it).
 *
 * @param {Object} preview The flat preview.
 * @param {string} key     A field name.
 * @return {boolean} True to leave it out.
 */
function hidden( preview, key ) {
	if ( HIDDEN.includes( key ) ) {
		return true;
	}
	return (
		'will_delete_permanently' === key &&
		Object.prototype.hasOwnProperty.call( preview, 'recoverable' )
	);
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
 * values. Empty values and internal fields (ids, tool names) are left out,
 * and labels and values use WordPress's words ("Status now: Published").
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
					! HIDDEN.includes( k ) &&
					JSON.stringify( pair.before[ k ] ) !==
						JSON.stringify( pair.after[ k ] )
			)
			.map( ( k ) => ( {
				label: labelFor( k ),
				before: readable( k, pair.before[ k ] ?? '' ),
				value: readable( k, pair.after[ k ] ?? '' ),
			} ) );
	}
	if ( ! isPlain( preview ) ) {
		return [];
	}
	return Object.keys( preview )
		.filter(
			( k ) =>
				preview[ k ] !== null &&
				preview[ k ] !== '' &&
				! hidden( preview, k )
		)
		.map( ( k ) => ( {
			label: labelFor( k ),
			value: readable( k, preview[ k ] ),
		} ) );
}

function isPlain( value ) {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}
