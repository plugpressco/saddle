/**
 * One of Saddle's own add-ons as a tile in Home's Add-ons (Saddle Analytics
 * in #367, a tile since #369; Saddle Rank in #409), while it is not on and
 * this owner may add it. One button installs it from WordPress.org and turns
 * it on without leaving the page (see addon.js); then the tile opens it. A
 * failure says why and links to the Plugins screen.
 */
import { useState } from '@wordpress/element';
import { Button } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { AddonIcon } from './icons';

/**
 * @param {Object}   props
 * @param {string}   props.addon     The module key, for its icon.
 * @param {string}   props.name      The product's name.
 * @param {string}   props.tagline   One short line about it.
 * @param {Function} props.getOffer  Where it stands (see addon.js).
 * @param {Function} props.add       Adds it; a promise.
 * @param {Function} props.errorText Says why adding failed.
 */
export default function AddonTile( {
	addon,
	name,
	tagline,
	getOffer,
	add: addIt,
	errorText,
} ) {
	// What the page knew when this tile first drew: it keeps drawing after
	// the add, to say it is on.
	const [ offer ] = useState( getOffer );
	const [ phase, setPhase ] = useState( 'idle' );
	const [ error, setError ] = useState( '' );

	if ( ! offer.canAdd ) {
		return null;
	}

	const installed = 'inactive' === offer.state;
	const add = () => {
		setPhase( 'busy' );
		setError( '' );
		addIt().then(
			() => setPhase( 'done' ),
			( e ) => {
				setError( errorText( e ) );
				setPhase( 'failed' );
			}
		);
	};

	let line = tagline;
	let action = (
		<Button
			variant="secondary"
			size="sm"
			disabled={ 'busy' === phase }
			aria-busy={ 'busy' === phase || undefined }
			onClick={ add }
		>
			{ installed
				? __( 'Turn on', 'saddle' )
				: __( 'Install', 'saddle' ) }
		</Button>
	);
	if ( 'busy' === phase ) {
		line = installed
			? __( 'Turning it on…', 'saddle' )
			: __( 'Installing from WordPress.org…', 'saddle' );
	} else if ( 'done' === phase ) {
		line = __( 'On', 'saddle' );
		action = (
			<Button variant="link" size="sm" href={ offer.url }>
				{ __( 'Open', 'saddle' ) }
			</Button>
		);
	} else if ( 'failed' === phase ) {
		line = error;
		action = (
			<Button variant="link" size="sm" href={ offer.manualUrl }>
				{ __( 'Open Plugins', 'saddle' ) }
			</Button>
		);
	}

	return (
		<li className="saddle-addon">
			<AddonIcon addon={ addon } className="saddle-addon__icon" />
			<span className="saddle-addon__text">
				<span className="saddle-addon__name">{ name }</span>
				<span className="saddle-addon__line">{ line }</span>
			</span>
			{ action }
		</li>
	);
}
