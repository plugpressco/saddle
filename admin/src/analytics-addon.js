/**
 * Saddle Analytics from Saddle's own pages (#367): the welcome asks, Home's
 * Add-ons offers it.
 *
 * The server says where it stands (`saddleData.analytics`: on, installed but
 * off, or missing, and whether this owner may add it). Adding it goes
 * through WordPress's own plugins REST endpoint, signed in as the owner:
 * `POST /wp/v2/plugins` downloads it from WordPress.org and turns it on, as
 * the block directory does; `POST /wp/v2/plugins/<plugin>` turns on a copy
 * that is already installed. Saddle writes no file.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { plainError, saddleData } from './api';

const SLUG = 'saddle-analytics';
const PLUGIN = 'saddle-analytics/saddle-analytics';

// Where it stands for the rest of this page's life. A successful add moves it
// to `active`, so the welcome and Home never offer it twice.
let current = {
	state: 'active',
	canAdd: false,
	url: '',
	manualUrl: '',
	...( saddleData.analytics || {} ),
};

/**
 * Saddle Analytics as this page knows it.
 *
 * @return {{state:string,canAdd:boolean,url:string,manualUrl:string}} State.
 */
export function analyticsOffer() {
	return current;
}

/**
 * Installs Saddle Analytics from WordPress.org when it is missing, and turns
 * it on.
 *
 * @return {Promise<Object>} WordPress's plugin record; rejects with its error.
 */
export function addAnalytics() {
	const activate = () =>
		apiFetch( {
			path: `/wp/v2/plugins/${ PLUGIN }`,
			method: 'POST',
			data: { status: 'active' },
		} );
	const run =
		'inactive' === current.state
			? activate()
			: apiFetch( {
					path: '/wp/v2/plugins',
					method: 'POST',
					data: { slug: SLUG, status: 'active' },
			  } ).catch( ( error ) => {
					// Installed since the page loaded (another tab, or by
					// hand): turn that copy on.
					if ( error && 'folder_exists' === error.code ) {
						return activate();
					}
					throw error;
			  } );

	return run.then(
		( plugin ) => {
			current = { ...current, state: 'active', canAdd: false };
			return plugin;
		},
		( error ) => {
			throw plainError( error );
		}
	);
}

/**
 * What to say when adding it failed: WordPress's own reason, else a
 * sentence of ours.
 *
 * @param {Object} error The rejection from addAnalytics().
 * @return {string} One sentence.
 */
export function addError( error ) {
	return (
		( error && error.message ) ||
		__( 'Saddle Analytics could not be added.', 'saddle' )
	);
}
