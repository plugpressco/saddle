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
import { __ } from '@wordpress/i18n';
import { createAddon } from './addon';

const analytics = createAddon( 'analytics', 'saddle-analytics' );

/**
 * Saddle Analytics as this page knows it.
 *
 * @return {{state:string,canAdd:boolean,url:string,manualUrl:string}} State.
 */
export const analyticsOffer = analytics.offer;

/**
 * Installs Saddle Analytics from WordPress.org when it is missing, and turns
 * it on.
 *
 * @return {Promise<Object>} WordPress's plugin record; rejects with its error.
 */
export const addAnalytics = analytics.add;

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
		__( 'SaddleWP Analytics could not be added.', 'saddle' )
	);
}
