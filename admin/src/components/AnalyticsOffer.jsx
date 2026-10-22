/**
 * Saddle → Analytics while Saddle Analytics is not on (#373): what it does,
 * and one button that installs it from WordPress.org and turns it on (or
 * turns on a copy already installed), through WordPress's own plugins
 * endpoint (analytics-addon.js). Then the same address opens Saddle
 * Analytics' own page. An owner who may not add plugins is told who can.
 */
import { useState } from '@wordpress/element';
import { Button, Notice, VisuallyHidden } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { addAnalytics, addError, analyticsOffer } from '../analytics-addon';
import { icons } from '../icons/kit';
import { AddonIcon } from './icons';

const DIRECTORY = 'https://wordpress.org/plugins/saddle-analytics/';

export default function AnalyticsOffer() {
	const [ offer ] = useState( analyticsOffer );
	const [ phase, setPhase ] = useState( 'idle' );
	const [ error, setError ] = useState( '' );
	const installed = 'inactive' === offer.state;

	const add = () => {
		setPhase( 'busy' );
		setError( '' );
		addAnalytics().then(
			() => setPhase( 'done' ),
			( e ) => {
				setError( addError( e ) );
				setPhase( 'failed' );
			}
		);
	};

	const points = [
		__( 'Visitors, pages and sources, without cookies', 'saddle' ),
		__( 'The visitors AI apps send you', 'saddle' ),
		__( 'The AI crawlers that read your site', 'saddle' ),
		__( 'Your AI app can read the numbers', 'saddle' ),
	];

	let action;
	if ( 'done' === phase ) {
		action = (
			<>
				<p className="saddle-offer__done" role="status">
					<icons.CheckCircle size={ 16 } />
					{ __( 'Saddle Analytics is on.', 'saddle' ) }
				</p>
				<Button variant="primary" href={ offer.url }>
					{ __( 'Open Analytics', 'saddle' ) }
				</Button>
			</>
		);
	} else if ( offer.canAdd ) {
		action = (
			<Button
				variant="primary"
				onClick={ add }
				disabled={ 'busy' === phase }
				aria-busy={ 'busy' === phase || undefined }
			>
				{ 'busy' === phase &&
					( installed
						? __( 'Turning it on…', 'saddle' )
						: __( 'Installing from WordPress.org…', 'saddle' ) ) }
				{ 'busy' !== phase &&
					( installed
						? __( 'Turn on Saddle Analytics', 'saddle' )
						: __( 'Install Saddle Analytics', 'saddle' ) ) }
			</Button>
		);
	} else {
		action = (
			<p className="saddle-offer__note">
				{ __(
					'Ask a site administrator to install Saddle Analytics.',
					'saddle'
				) }{ ' ' }
				<a href={ DIRECTORY } target="_blank" rel="noopener noreferrer">
					{ __( 'See it on WordPress.org', 'saddle' ) }
					<VisuallyHidden>
						{ ` ${ __( '(opens in a new tab)', 'saddle' ) }` }
					</VisuallyHidden>
				</a>
			</p>
		);
	}

	return (
		<section
			className="saddle-soft-card saddle-offer"
			aria-labelledby="saddle-offer-title"
		>
			<AddonIcon
				addon="analytics"
				className="saddle-offer__icon"
				width="72"
				height="72"
			/>
			<h2 className="saddle-offer__title" id="saddle-offer-title">
				{ __( 'See who visits your site', 'saddle' ) }
			</h2>
			<p className="saddle-offer__lede">
				{ __(
					'Saddle Analytics is free and counts visits without cookies. Add it and the stats show here.',
					'saddle'
				) }
			</p>
			<ul className="saddle-offer__points">
				{ points.map( ( point ) => (
					<li key={ point }>
						<icons.Check
							size={ 16 }
							className="saddle-plan__tick"
						/>
						{ point }
					</li>
				) ) }
			</ul>
			<div className="saddle-offer__action">{ action }</div>
			{ 'failed' === phase && (
				<Notice tone="danger">
					{ error }{ ' ' }
					<a href={ offer.manualUrl }>
						{ __( 'Open Plugins', 'saddle' ) }
					</a>
				</Notice>
			) }
		</section>
	);
}
