/**
 * Pure helpers for the Services page (#291): grouping the records, the
 * one-line meta, the status badge and the role labels. No React, no network.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The sections, in the order the page shows them, one per `kind`.
 *
 * @return {Array<{kind:string,title:string,note:string}>} Sections.
 */
export function sections() {
	return [
		{
			kind: 'account',
			title: __( 'Accounts', 'saddle' ),
			note: __( 'Outside services you sign up for.', 'saddle' ),
		},
		{
			kind: 'plugin',
			title: __( 'Plugins', 'saddle' ),
			note: __(
				'Found on this site. Saddle works inside them.',
				'saddle'
			),
		},
		{
			kind: 'addon',
			title: __( 'Add-ons', 'saddle' ),
			note: __( 'Plugins that give your apps more tools.', 'saddle' ),
		},
	];
}

/**
 * The records grouped by kind, in section order. A section with no records
 * is left out; a record of an unknown kind is ignored.
 *
 * @param {Array} records From GET /services.
 * @return {Array<{kind:string,title:string,note:string,rows:Array}>} Groups.
 */
export function groupServices( records ) {
	const list = Array.isArray( records ) ? records : [];
	return sections()
		.map( ( s ) => ( {
			...s,
			rows: list.filter( ( r ) => r && r.kind === s.kind ),
		} ) )
		.filter( ( g ) => g.rows.length > 0 );
}

/**
 * The role names, matching AI apps (`Saddle_Access::labels()`).
 *
 * @param {string} role read, write or admin.
 * @return {string} Label; an unknown role comes back as it is.
 */
export function roleLabel( role ) {
	switch ( role ) {
		case 'read':
			return __( 'Read only', 'saddle' );
		case 'write':
			return __( 'Edit content', 'saddle' );
		case 'admin':
			return __( 'Manage the site', 'saddle' );
	}
	return role || '';
}

/**
 * "3 tools".
 *
 * @param {Object} record A service record.
 * @return {string} Tool count.
 */
export function toolCount( record ) {
	// An add-on that is off registers no tools; the server still counts them.
	const n =
		typeof record.tool_count === 'number'
			? record.tool_count
			: ( record.tools || [] ).length;
	return sprintf(
		/* translators: %d: number of tools. */
		_n( '%d tool', '%d tools', n, 'saddle' ),
		n
	);
}

/**
 * Where a record's data goes, in a few words.
 *
 * @param {Object} record A service record.
 * @return {string} Line.
 */
export function whereDataGoes( record ) {
	if ( 'needs_key' === record.status ) {
		return __( 'needs your key', 'saddle' );
	}
	const hosts = ( record.sends || [] )
		.map( ( s ) => s.host )
		.filter( Boolean );
	if ( ! hosts.length ) {
		return __( 'nothing leaves your site', 'saddle' );
	}
	return sprintf(
		/* translators: %s: a host name, such as api.unsplash.com. */
		__( 'sends data to %s', 'saddle' ),
		hosts[ 0 ]
	);
}

/**
 * The row's meta line: "Stock photos · needs your key · 2 tools".
 *
 * @param {Object} record A service record.
 * @return {string} Line.
 */
export function metaLine( record ) {
	return [ record.description, whereDataGoes( record ), toolCount( record ) ]
		.filter( Boolean )
		.join( ' · ' );
}

/**
 * What a row shows at its right edge. An account with no key gets a primary
 * button (`button: true`) instead of a badge.
 *
 * @param {Object} record A service record.
 * @return {{label:string,tone:string,button:boolean}} Badge.
 */
export function badgeFor( record ) {
	switch ( record.status ) {
		case 'needs_key':
			return {
				label: __( 'Add key', 'saddle' ),
				tone: 'neutral',
				button: true,
			};
		case 'ready':
			return {
				label: __( 'Ready', 'saddle' ),
				tone: 'success',
				button: false,
			};
		case 'detected':
			return {
				label: __( 'Detected', 'saddle' ),
				tone: 'neutral',
				button: false,
			};
		case 'active':
			return {
				label:
					'third-party' === record.source
						? __( 'On', 'saddle' )
						: __( 'Active', 'saddle' ),
				tone: 'success',
				button: false,
			};
	}
	return { label: __( 'Off', 'saddle' ), tone: 'neutral', button: false };
}

/**
 * The first letter of a name, for the neutral tile used where a service has
 * no logo.
 *
 * @param {string} name Service name.
 * @return {string} One uppercase character, or "?".
 */
export function initialOf( name ) {
	const ch = String( name || '' )
		.trim()
		.charAt( 0 );
	return ch ? ch.toUpperCase() : '?';
}

/**
 * The same list with one record replaced (matched by key).
 *
 * @param {Array}  records The list.
 * @param {Object} next    The updated record.
 * @return {Array} New list.
 */
export function replaceRecord( records, next ) {
	return ( records || [] ).map( ( r ) => ( r.key === next.key ? next : r ) );
}
