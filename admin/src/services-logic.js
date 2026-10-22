/**
 * Pure helpers for the accounts and add-on switches on Settings (#291; the
 * Services page until #369): each row's status line, the key link, the
 * tool count and the role labels. No React, no network.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

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
 * "3 tools": the label of a plugin's collapsed tool list, counting the
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
 * The role names, matching Agent setup (`Saddle_Access::labels()`).
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
