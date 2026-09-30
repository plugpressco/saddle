/**
 * Home's Setup block (#277): what is left to do, in one list.
 *
 * Three Core tasks (connect an app, try a first prompt, choose what it can
 * do) are worked out from the connection registry and the onboarding state,
 * never stored. Each installed module adds its own unfinished tasks (Home
 * fetches `GET /modules` once and passes `areas`). One compact row each; the
 * section says how many are done, can be hidden, and is gone once everything
 * is done.
 */
import { Button, Row, RowList, StatusDot } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { setupBlock } from '../onboarding-logic';
import SectionHeader from './SectionHeader';
import { withArg } from '../routes';

/**
 * @param {Object}   props
 * @param {string}   props.tier        The site's tier.
 * @param {?Array}   props.areas       From parseModules(), or null while
 *                                     loading (nothing is drawn yet).
 * @param {?Array}   props.connections From GET /connections, or null while
 *                                     loading (nothing is drawn yet).
 * @param {Object}   props.onboarding  GET /onboarding.
 * @param {string}   props.homeUrl     Home's address, for re-opening setup.
 * @param {Function} props.onConnect   Open the connect wizard.
 * @param {Function} props.onNavigate  Go to another place (old section names).
 * @param {Function} props.onHide      Hide the block.
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

	const block = setupBlock( { connections, onboarding, tier, areas } );
	if ( ! block.visible ) {
		return null;
	}

	// Trying a prompt reopens first run at that step, for the app that is
	// connected but has not used Saddle yet.
	const untried = connections.find( ( c ) => ! c.first_tool_at && c.app );
	const tryApp = ( untried || connections[ 0 ] || {} ).app || '';
	const tryUrl = withArg(
		withArg( withArg( homeUrl, 'setup', '1' ), 'step', 'try' ),
		'app',
		tryApp
	);

	const coreAction = ( id ) => {
		switch ( id ) {
			case 'connect':
				return (
					<Button variant="link" size="sm" onClick={ onConnect }>
						{ __( 'Connect', 'saddle' ) }
					</Button>
				);
			case 'try':
				return connections.length ? (
					<Button variant="link" size="sm" href={ tryUrl }>
						{ __( 'Try it', 'saddle' ) }
					</Button>
				) : null;
			default:
				return (
					<Button
						variant="link"
						size="sm"
						onClick={ () => onNavigate( 'permissions' ) }
					>
						{ __( 'Choose', 'saddle' ) }
					</Button>
				);
		}
	};

	return (
		<section className="saddle-stack saddle-setup">
			<SectionHeader
				title={ __( 'Setup', 'saddle' ) }
				description={ sprintf(
					/* translators: 1: steps done, 2: steps in all. */
					__( '%1$d of %2$d done', 'saddle' ),
					block.done,
					block.total
				) }
				actions={
					<Button variant="link" size="sm" onClick={ onHide }>
						{ __( 'Hide', 'saddle' ) }
					</Button>
				}
			/>
			<RowList>
				{ block.core.map( ( task ) => (
					<Row
						key={ task.id }
						icon={
							<StatusDot
								tone={ task.done ? 'success' : 'neutral' }
							/>
						}
						title={ task.title }
						actions={ task.done ? null : coreAction( task.id ) }
					/>
				) ) }
				{ block.modules.map( ( { module, product, task } ) => (
					<Row
						key={ `${ module }/${ task.id }` }
						icon={ <StatusDot tone="neutral" /> }
						title={ sprintf(
							/* translators: 1: module name, 2: what to do. */
							__( '%1$s: %2$s', 'saddle' ),
							product,
							task.title
						) }
						description={ task.line || undefined }
						actions={
							task.action && task.action.url ? (
								<Button
									variant="link"
									size="sm"
									href={ task.action.url }
									{ ...( task.action.external
										? {
												target: '_blank',
												rel: 'noreferrer',
										  }
										: {} ) }
								>
									{ task.action.label }
								</Button>
							) : null
						}
					/>
				) ) }
			</RowList>
		</section>
	);
}
