/**
 * Home's next step (#277, one line since #309): while setup is unfinished,
 * Saddle suggests the single next thing to do, in one sentence beside its
 * mark, with one button. It replaces the Setup checklist.
 *
 * The step is worked out from the connection registry, the onboarding state
 * and each module's unfinished tasks (`nextStep()`), never stored. Hiding it
 * hides setup for good (`setup.hide`); it is gone by itself once everything
 * is done.
 */
import { Button, IconButton, XIcon } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { nextStep, setupBlock } from '../onboarding-logic';
import { APPS } from '../connect-apps';
import { BrandMark } from './icons';
import { withArg } from '../routes';

/**
 * @param {Object}   props
 * @param {string}   props.tier        The site's tier.
 * @param {?Array}   props.areas       From parseModules(), or null while
 *                                     loading (nothing is drawn yet).
 * @param {?Array}   props.connections From GET /connections, or null while
 *                                     loading (nothing is drawn yet).
 * @param {Object}   props.onboarding  GET /onboarding.
 * @param {string}   props.homeUrl     Home's address, for reopening the welcome.
 * @param {Function} props.onConnect   Open Connect an app.
 * @param {Function} props.onNavigate  Go to another place (old section names).
 * @param {Function} props.onHide      Hide setup.
 */
export default function SetupBlock( {
	tier,
	connections,
	areas,
	onboarding,
	homeUrl,
	onConnect,
	onNavigate,
	onHide,
} ) {
	if ( null === connections || null === areas || ! onboarding ) {
		return null;
	}
	const step = nextStep(
		setupBlock( { connections, onboarding, tier, areas } ),
		connections
	);
	if ( ! step ) {
		return null;
	}

	const app = APPS.find( ( a ) => a.key === step.app );
	const label = app ? app.label : __( 'your AI', 'saddle' );
	let text;
	let action = null;

	switch ( step.id ) {
		case 'connect':
			text = __(
				'Connect your AI, and it can start working on this site.',
				'saddle'
			);
			action = (
				<Button variant="secondary" size="sm" onClick={ onConnect }>
					{ __( 'Connect', 'saddle' ) }
				</Button>
			);
			break;
		case 'try':
			text = sprintf(
				/* translators: %s: the app name. */
				__(
					'Want to try %s on this site? It takes a minute.',
					'saddle'
				),
				label
			);
			// The welcome reopens at "try it", for this app.
			action = (
				<Button
					variant="secondary"
					size="sm"
					href={ withArg(
						withArg(
							withArg( homeUrl, 'setup', '1' ),
							'step',
							'try'
						),
						'app',
						step.app
					) }
				>
					{ __( 'Continue', 'saddle' ) }
				</Button>
			);
			break;
		case 'choose':
			text = sprintf(
				/* translators: %s: the app name. */
				__(
					'%s can only look right now. Want it to edit content too?',
					'saddle'
				),
				label
			);
			action = (
				<Button
					variant="secondary"
					size="sm"
					onClick={ () => onNavigate( 'connect' ) }
				>
					{ __( 'Choose', 'saddle' ) }
				</Button>
			);
			break;
		default:
			text = sprintf(
				/* translators: 1: module name, 2: what to do. */
				__( '%1$s: %2$s', 'saddle' ),
				step.product,
				step.task.title
			);
			if ( step.task.action && step.task.action.url ) {
				action = (
					<Button
						variant="secondary"
						size="sm"
						href={ step.task.action.url }
						{ ...( step.task.action.external
							? { target: '_blank', rel: 'noreferrer' }
							: {} ) }
					>
						{ step.task.action.label }
					</Button>
				);
			}
	}

	return (
		<div className="saddle-nudge">
			<span className="saddle-nudge__mark" aria-hidden="true">
				<BrandMark />
			</span>
			<p className="saddle-nudge__text">{ text }</p>
			{ action }
			<IconButton
				size="sm"
				aria-label={ __( 'Hide this', 'saddle' ) }
				onClick={ onHide }
			>
				<XIcon size={ 16 } />
			</IconButton>
		</div>
	);
}
