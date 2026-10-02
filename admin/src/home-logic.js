/**
 * Pure helpers for Home (#309): the week's numbers and the plugins the apps
 * can work inside. No React, no network.
 */
import { __ } from '@wordpress/i18n';

const WEEK_SECONDS = 7 * 24 * 60 * 60;

/**
 * The start of the last seven days, for GET /audit-log's `since`.
 *
 * @param {number} now Milliseconds, for tests.
 * @return {number} Unix seconds (UTC).
 */
export function weekStart( now = Date.now() ) {
	return Math.floor( now / 1000 ) - WEEK_SECONDS;
}

/**
 * The numbers in "This week", in order. A number that failed to load is left
 * out rather than drawn as a dash.
 *
 * @param {?Object} week `{ changes, blocked, waiting }`, each a count or null.
 * @return {Array<{key:string,label:string,value:number}>} Tiles.
 */
export function weekTiles( week ) {
	if ( ! week ) {
		return [];
	}
	return [
		{
			key: 'changes',
			label: __( 'Changes', 'saddle' ),
			value: week.changes,
		},
		{
			key: 'blocked',
			label: __( 'Blocked', 'saddle' ),
			value: week.blocked,
		},
		{
			key: 'waiting',
			label: __( 'Waiting for you', 'saddle' ),
			value: week.waiting,
		},
	].filter( ( t ) => Number.isInteger( t.value ) && t.value >= 0 );
}

/**
 * The plugins on this site the apps can work inside, for "Works with": the
 * Services page's plugin and add-on records that are on, with tools.
 *
 * @param {Array} records From GET /services.
 * @return {Array<Object>} The records, plugins first.
 */
export function worksWith( records ) {
	const list = Array.isArray( records ) ? records : [];
	const on = ( r ) =>
		r &&
		( 'plugin' === r.kind || 'addon' === r.kind ) &&
		( 'active' === r.status ||
			'detected' === r.status ||
			'ready' === r.status ) &&
		( r.tool_count || 0 ) > 0;
	return [
		...list.filter( ( r ) => on( r ) && 'plugin' === r.kind ),
		...list.filter( ( r ) => on( r ) && 'addon' === r.kind ),
	];
}
