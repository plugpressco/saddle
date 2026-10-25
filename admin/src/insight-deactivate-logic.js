/**
 * The deactivation dialog's rules, apart from the page so they can be
 * tested: its reasons, which rows it answers for, what Send posts and how
 * long the page waits for it.
 */
import { __ } from '@wordpress/i18n';

/**
 * The dialog's choices, in its order. The keys are the API's
 * (Saddle_Insight::REASONS).
 *
 * @return {Array<{key:string,label:string}>} Reasons.
 */
export function reasons() {
	return [
		{
			key: 'not_what_i_needed',
			label: __( 'It didn’t do what I needed', 'saddle' ),
		},
		{
			key: 'hard_to_set_up',
			label: __( 'It was hard to set up', 'saddle' ),
		},
		{
			key: 'broke_something',
			label: __( 'It broke something or caused an error', 'saddle' ),
		},
		{
			key: 'found_another',
			label: __( 'I found another plugin', 'saddle' ),
		},
		{
			key: 'short_time',
			label: __( 'I only needed it for a short time', 'saddle' ),
		},
		{
			key: 'temporary',
			label: __( 'I’m only turning it off for a moment', 'saddle' ),
		},
		{ key: 'other', label: __( 'Something else', 'saddle' ) },
	];
}

/**
 * The Deactivate links the dialog answers for: a row of the Plugins table
 * whose plugin is one of the family's. Bulk actions are not links, so they
 * never open it.
 *
 * @param {Object}   plugins Plugin file → name, from the server.
 * @param {Document} doc     The page.
 * @return {Array<{link:HTMLAnchorElement,plugin:string,name:string}>} Links.
 */
export function familyLinks( plugins, doc ) {
	const out = [];
	doc.querySelectorAll( 'tr[data-plugin]' ).forEach( ( row ) => {
		const plugin = row.getAttribute( 'data-plugin' );
		const link = row.querySelector( '.row-actions .deactivate a' );
		if ( link && Object.hasOwn( plugins || {}, plugin ) ) {
			out.push( { link, plugin, name: plugins[ plugin ] } );
		}
	} );
	return out;
}

/**
 * What Send posts: the plugin, the reason, and what was typed, trimmed.
 *
 * @param {Object} answer         What the dialog holds.
 * @param {string} answer.plugin  Plugin file, e.g. saddle-rank/saddle-rank.php.
 * @param {string} answer.reason  One of reasons()' keys.
 * @param {string} answer.comment What they typed.
 * @param {string} answer.email   A reply address, or ''.
 * @return {Object} The request body.
 */
export function deactivationData( { plugin, reason, comment, email } ) {
	return {
		plugin,
		reason,
		comment: String( comment || '' )
			.trim()
			.slice( 0, 500 ),
		email: String( email || '' ).trim(),
	};
}

/**
 * Settles when the promise does or after `ms`, whichever is first, and never
 * rejects: the plugin turns off whether or not the answer arrived.
 *
 * @param {Promise} promise The send.
 * @param {number}  ms      How long to wait at most.
 * @return {Promise<void>} Settles.
 */
export function settleWithin( promise, ms ) {
	return new Promise( ( resolve ) => {
		const timer = setTimeout( resolve, ms );
		Promise.resolve( promise )
			.catch( () => {} )
			.then( () => {
				clearTimeout( timer );
				resolve();
			} );
	} );
}
