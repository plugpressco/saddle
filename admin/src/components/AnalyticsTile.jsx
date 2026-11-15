/**
 * Saddle Analytics as a tile in Home's Add-ons (#367; a tile since #369).
 * See AddonTile.
 */
import { __ } from '@wordpress/i18n';
import { addAnalytics, addError, analyticsOffer } from '../analytics-addon';
import AddonTile from './AddonTile';

export default function AnalyticsTile() {
	return (
		<AddonTile
			addon="analytics"
			name={ __( 'SaddleWP Analytics', 'saddle' ) }
			tagline={ __( 'Visitor stats, no cookies', 'saddle' ) }
			getOffer={ analyticsOffer }
			add={ addAnalytics }
			errorText={ addError }
		/>
	);
}
