/**
 * The Services page's rows and sections (1.5.0 release QA P23), on top of
 * services-logic.js. No React, no network.
 */
import { __, sprintf } from '@wordpress/i18n';
import { groupServices, sections, statusLabel } from './services-logic';

/**
 * The records grouped by kind, each section named even when it is the only
 * one: a page of one unnamed list doesn't say what the list is.
 *
 * @param {Array} records From GET /services.
 * @return {Array<{kind:string,title:string,rows:Array}>} Groups.
 */
export function namedGroups( records ) {
	const titles = {};
	sections().forEach( ( s ) => {
		titles[ s.kind ] = s.title;
	} );
	return groupServices( records ).map( ( g ) => ( {
		...g,
		title: g.title || titles[ g.kind ] || '',
	} ) );
}

/**
 * A row's line under its name: the status, and for an outside account what
 * it is for first ("Stock photos · Not set up"), since its name alone may not
 * say.
 *
 * @param {Object} record A service record.
 * @return {string} Line.
 */
export function rowLine( record ) {
	const status = statusLabel( record );
	const what =
		record && 'account' === record.kind
			? String( record.description || '' ).trim()
			: '';
	if ( ! what ) {
		return status;
	}
	if ( ! status ) {
		return what;
	}
	return sprintf(
		/* translators: 1: what a service is for, such as "Stock photos", 2: its status, such as "Not set up". */
		__( '%1$s · %2$s', 'saddle' ),
		what,
		status
	);
}
