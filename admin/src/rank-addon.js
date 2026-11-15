/**
 * Saddle Rank from Saddle's own pages (#409): the welcome asks, Home's
 * Add-ons offers it. The same owner-side install as Saddle Analytics, for
 * the one slug `saddle-rank` (see addon.js).
 */
import { __ } from '@wordpress/i18n';
import { createAddon } from './addon';

const rank = createAddon( 'rank', 'saddle-rank' );

/**
 * Saddle Rank as this page knows it.
 *
 * @return {{state:string,canAdd:boolean,url:string,manualUrl:string}} State.
 */
export const rankOffer = rank.offer;

/**
 * Installs Saddle Rank from WordPress.org when it is missing, and turns it on.
 *
 * @return {Promise<Object>} WordPress's plugin record; rejects with its error.
 */
export const addRank = rank.add;

/**
 * What to say when adding it failed: WordPress's own reason, else a sentence
 * of ours.
 *
 * @param {Object} error The rejection from addRank().
 * @return {string} One sentence.
 */
export function addRankError( error ) {
	return (
		( error && error.message ) ||
		__( 'SaddleWP Rank could not be added.', 'saddle' )
	);
}
