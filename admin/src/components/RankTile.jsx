/**
 * Saddle Rank as a tile in Home's Add-ons (#409). See AddonTile.
 */
import { __ } from '@wordpress/i18n';
import { addRank, addRankError, rankOffer } from '../rank-addon';
import AddonTile from './AddonTile';

export default function RankTile() {
	return (
		<AddonTile
			addon="rank"
			name={ __( 'Saddle Rank', 'saddle' ) }
			tagline={ __( 'SEO and AI search visibility', 'saddle' ) }
			getOffer={ rankOffer }
			add={ addRank }
			errorText={ addRankError }
		/>
	);
}
