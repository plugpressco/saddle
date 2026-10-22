/**
 * Saddle Analytics as a row in Home's Add-ons (#367), while it is not on and
 * this owner may add it. One button installs it from WordPress.org and turns
 * it on without leaving the page (see analytics-addon.js); then the row
 * opens it. A failure says why and links to the Plugins screen.
 */
import { useState } from '@wordpress/element';
import { Button, Row, StatusDot } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { addAnalytics, addError, analyticsOffer } from '../analytics-addon';

export default function AnalyticsRow() {
	// What the page knew when this row first drew: it keeps drawing after
	// the add, to say it is on.
	const [ offer ] = useState( analyticsOffer );
	const [ phase, setPhase ] = useState( 'idle' );
	const [ error, setError ] = useState( '' );

	if ( ! offer.canAdd ) {
		return null;
	}

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

	let description = __( 'Visitor stats, no cookies', 'saddle' );
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
		description = installed
			? __( 'Turning it on…', 'saddle' )
			: __( 'Installing from WordPress.org…', 'saddle' );
	} else if ( 'done' === phase ) {
		description = __( 'On', 'saddle' );
		action = (
			<Button variant="link" size="sm" href={ offer.url }>
				{ __( 'Open', 'saddle' ) }
			</Button>
		);
	} else if ( 'failed' === phase ) {
		description = error;
		action = (
			<Button variant="link" size="sm" href={ offer.manualUrl }>
				{ __( 'Open Plugins', 'saddle' ) }
			</Button>
		);
	}

	return (
		<Row
			icon={
				<StatusDot
					tone={
						{ done: 'success', failed: 'danger' }[ phase ] ||
						'neutral'
					}
				/>
			}
			title={ __( 'Saddle Analytics', 'saddle' ) }
			description={ description }
			actions={ action }
		/>
	);
}
