/**
 * Pure helpers for the Services page (#291): grouping the records, each
 * row's status line, the drawer's one line and key link, the tool count and
 * the role labels. No React, no network.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The sections, in the order the page shows them, one per `kind`.
 *
 * @return {Array<{kind:string,title:string}>} Sections.
 */
export function sections() {
	return [
		{ kind: 'account', title: __( 'Accounts', 'saddle' ) },
		{ kind: 'plugin', title: __( 'Plugins', 'saddle' ) },
		{ kind: 'addon', title: __( 'Add-ons', 'saddle' ) },
	];
}

/**
 * The records grouped by kind, in section order. A kind with no records is
 * left out; a record of an unknown kind is ignored. When only one kind has
 * records, its `title` is empty: the page then draws no heading.
 *
 * @param {Array} records From GET /services.
 * @return {Array<{kind:string,title:string,rows:Array}>} Groups.
 */
export function groupServices( records ) {
	const list = Array.isArray( records ) ? records : [];
	const groups = sections()
		.map( ( s ) => ( {
			...s,
			rows: list.filter( ( r ) => r && r.kind === s.kind ),
		} ) )
		.filter( ( g ) => g.rows.length > 0 );
	return groups.length > 1
		? groups
		: groups.map( ( g ) => ( { ...g, title: '' } ) );
}

/**
 * A row's one status line, from `status`. A plugin Saddle found on the site
 * (`detected`) is as usable as an add-on that is on, so both read "Active".
 *
 * @param {Object} record A service record.
 * @return {string} "Not set up", "Ready", "Active", "Off", or '' when unknown.
 */
export function statusLabel( record ) {
	switch ( record && record.status ) {
		case 'needs_key':
			return __( 'Not set up', 'saddle' );
		case 'ready':
			return __( 'Ready', 'saddle' );
		case 'detected':
		case 'active':
			return __( 'Active', 'saddle' );
		case 'off':
			return __( 'Off', 'saddle' );
	}
	return '';
}

/**
 * The drawer's one line of what a service is: its own description, or what
 * kind of service it is when it has none.
 *
 * @param {Object} record A service record.
 * @return {string} Line.
 */
export function summaryOf( record ) {
	if ( ! record ) {
		return '';
	}
	const d = String( record.description || '' ).trim();
	if ( d ) {
		return d;
	}
	if ( 'account' === record.kind ) {
		return __( 'Outside service', 'saddle' );
	}
	if ( 'plugin' === record.kind ) {
		return __( 'Plugin', 'saddle' );
	}
	return 'third-party' === record.source
		? __( 'Third-party add-on', 'saddle' )
		: __( 'PlugPress add-on', 'saddle' );
}

/**
 * Where an account's key comes from, as a link. Null for anything that is
 * not an account, or an account that names no such page.
 *
 * @param {Object} record A service record.
 * @return {{url:string,label:string}|null} Link.
 */
export function keyLink( record ) {
	if ( ! record || 'account' !== record.kind || ! record.credentials_url ) {
		return null;
	}
	return {
		url: record.credentials_url,
		label: sprintf(
			/* translators: %s: service name, such as Unsplash. */
			__( 'Get a key from %s', 'saddle' ),
			record.name
		),
	};
}

/**
 * "3 tools": the label of the drawer's collapsed tool list, counting the
 * tools it lists.
 *
 * @param {Object} record A service record.
 * @return {string} Tool count.
 */
export function toolCount( record ) {
	const n = ( ( record && record.tools ) || [] ).length;
	return sprintf(
		/* translators: %d: number of tools. */
		_n( '%d tool', '%d tools', n, 'saddle' ),
		n
	);
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
 * The same list with one record replaced (matched by key).
 *
 * @param {Array}  records The list.
 * @param {Object} next    The updated record.
 * @return {Array} New list.
 */
export function replaceRecord( records, next ) {
	return ( records || [] ).map( ( r ) => ( r.key === next.key ? next : r ) );
}
