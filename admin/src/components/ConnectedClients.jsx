/**
 * Connect tab — the steady state.
 *
 * A list of connected apps you can trust at a glance (what's connected, when it
 * last talked to the site, from where) plus one clear action: Connect an app,
 * which opens the guided wizard. The endpoint test and server health checks
 * live behind a disclosure — they're for troubleshooting, not for every visit.
 */
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { saddleData, api } from '../api';
import ConnectionHealth from './ConnectionHealth';
import { IconConnect, AppLogo, appKeyFromLabel } from './icons';

const MCP_URL = saddleData.mcpUrl || '';

const DATE_FMT = new Intl.DateTimeFormat( undefined, {
	dateStyle: 'medium',
	timeStyle: 'short',
} );

const when = ( ts ) => ( ts ? DATE_FMT.format( new Date( ts * 1000 ) ) : null );

export default function Apps( {
	clients,
	loading,
	onConnect,
	onClientsChanged,
} ) {
	const [ notice, setNotice ] = useState( null );
	const [ confirmRevoke, setConfirmRevoke ] = useState( null );
	const [ showAdvanced, setShowAdvanced ] = useState( false );
	const [ test, setTest ] = useState( null );

	const revoke = ( uuid ) => {
		setConfirmRevoke( null );
		api( `clients/${ uuid }`, { method: 'DELETE' } )
			.then( () => {
				setNotice( {
					type: 'success',
					message: __(
						'Disconnected. That app’s sign-in no longer works.',
						'saddle'
					),
				} );
				if ( onClientsChanged ) {
					onClientsChanged();
				}
			} )
			.catch( ( e ) =>
				setNotice( { type: 'error', message: e.message } )
			);
	};

	// Live round-trip against the MCP endpoint using the admin session. The
	// adapter's HTTP transport requires an MCP session, so initialize first to
	// get the Mcp-Session-Id, then list tools with it.
	const runTest = () => {
		setTest( { state: 'running' } );
		const started = window.performance ? window.performance.now() : 0;
		const accept = 'application/json, text/event-stream';
		const elapsed = () =>
			window.performance
				? Math.round( window.performance.now() - started )
				: null;

		apiFetch( {
			url: MCP_URL,
			method: 'POST',
			parse: false, // need the raw Response to read the session header
			headers: { Accept: accept },
			data: {
				jsonrpc: '2.0',
				id: 0,
				method: 'initialize',
				params: {
					protocolVersion: '2025-11-25',
					capabilities: {},
					clientInfo: { name: 'Saddle Admin', version: '1' },
				},
			},
		} )
			.then( ( resp ) => {
				const sid = resp.headers.get( 'Mcp-Session-Id' );
				return resp.json().then( ( init ) => ( { sid, init } ) );
			} )
			.then( ( { sid, init } ) => {
				if ( init && init.error ) {
					throw new Error( init.error.message );
				}
				if ( ! sid ) {
					setTest( { state: 'ok', count: null, ms: elapsed() } );
					return;
				}
				return apiFetch( {
					url: MCP_URL,
					method: 'POST',
					headers: { Accept: accept, 'Mcp-Session-Id': sid },
					data: { jsonrpc: '2.0', id: 1, method: 'tools/list' },
				} ).then( ( res ) => {
					if ( res && res.error ) {
						throw new Error( res.error.message );
					}
					const count =
						res && res.result && Array.isArray( res.result.tools )
							? res.result.tools.length
							: null;
					setTest( { state: 'ok', count, ms: elapsed() } );
				} );
			} )
			.catch( ( e ) =>
				setTest( { state: 'error', message: e.message } )
			);
	};

	return (
		<div className="saddle-apps">
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="saddle-apps__head">
				<div>
					<h2 className="saddle-apps__title">
						{ __( 'Connected apps', 'saddle' ) }
					</h2>
					<p className="saddle-apps__lead">
						{ __(
							'Every app here has its own sign-in you can take away at any moment. They all follow the same rules from Permissions.',
							'saddle'
						) }
					</p>
				</div>
				<Button variant="primary" onClick={ onConnect }>
					{ __( 'Connect an app', 'saddle' ) }
				</Button>
			</div>

			{ loading && <Spinner /> }

			{ ! loading && clients.length === 0 && (
				<button
					type="button"
					className="saddle-apps__empty"
					onClick={ onConnect }
				>
					<span
						className="saddle-apps__empty-icon"
						aria-hidden="true"
					>
						<IconConnect />
					</span>
					<span className="saddle-apps__empty-title">
						{ __( 'Nothing connected yet', 'saddle' ) }
					</span>
					<span className="saddle-apps__empty-sub">
						{ __(
							'Connect Claude, Cursor, or another AI app — it takes about a minute.',
							'saddle'
						) }
					</span>
				</button>
			) }

			{ ! loading && clients.length > 0 && (
				<ul className="saddle-apps__list">
					{ clients.map( ( c ) => (
						<li key={ c.uuid } className="saddle-appsrow">
							<span
								className={ `saddle-appsrow__dot${
									c.last_used ? ' is-live' : ''
								}` }
								aria-hidden="true"
							/>
							<span
								className="saddle-appsrow__logo"
								aria-hidden="true"
							>
								<AppLogo
									app={ appKeyFromLabel( c.label || c.name ) }
								/>
							</span>
							<span className="saddle-appsrow__name">
								{ c.label || c.name }
							</span>
							<span className="saddle-appsrow__meta">
								{ c.last_used
									? sprintf(
											/* translators: %s: date and time. */
											__( 'Last active %s', 'saddle' ),
											when( c.last_used )
									  )
									: __(
											'Hasn’t connected yet — it will on first use',
											'saddle'
									  ) }
								{ c.last_ip ? ` · ${ c.last_ip }` : '' }
								{ c.hint
									? ` · ${ __( 'key', 'saddle' ) } ····${
											c.hint
									  }`
									: '' }
							</span>
							<Button
								variant="link"
								isDestructive
								className="saddle-appsrow__action"
								onClick={ () => setConfirmRevoke( c ) }
							>
								{ __( 'Disconnect', 'saddle' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }

			{ clients.length > 0 && (
				<p className="saddle-apps__footnote">
					{ __(
						'These keys also appear under Users → Profile → Application Passwords — same keys, either place works for revoking.',
						'saddle'
					) }{ ' ' }
					{ window.saddleData?.profileUrl && (
						<a href={ window.saddleData.profileUrl }>
							{ __( 'View there', 'saddle' ) }
						</a>
					) }
				</p>
			) }

			{ confirmRevoke && (
				<Modal
					title={ sprintf(
						/* translators: %s: the app name. */
						__( 'Disconnect “%s”?', 'saddle' ),
						confirmRevoke.label || confirmRevoke.name
					) }
					onRequestClose={ () => setConfirmRevoke( null ) }
					className="saddle-confirm"
					size="small"
				>
					<p className="saddle-confirm__body">
						{ __(
							'Its sign-in key stops working the moment you confirm — the app loses access to this site immediately. This can’t be undone, but you can always connect the app again with a fresh key.',
							'saddle'
						) }
					</p>
					<div className="saddle-confirm__actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirmRevoke( null ) }
						>
							{ __( 'Keep it connected', 'saddle' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => revoke( confirmRevoke.uuid ) }
						>
							{ __( 'Disconnect', 'saddle' ) }
						</Button>
					</div>
				</Modal>
			) }

			<button
				type="button"
				className="saddle-disclosure"
				aria-expanded={ showAdvanced }
				onClick={ () => setShowAdvanced( ( v ) => ! v ) }
			>
				<span className="saddle-disclosure__caret" aria-hidden="true">
					{ showAdvanced ? '▾' : '▸' }
				</span>
				{ __( 'Connection details & health', 'saddle' ) }
			</button>

			{ showAdvanced && (
				<div className="saddle-apps__advanced">
					<div className="saddle-apps__endpoint">
						<span className="saddle-apps__endpoint-label">
							{ __(
								'Your site’s address for AI apps',
								'saddle'
							) }
						</span>
						<code>{ MCP_URL }</code>
					</div>
					<div className="saddle-apps__test">
						<Button
							variant="secondary"
							onClick={ runTest }
							isBusy={ test && test.state === 'running' }
							disabled={ test && test.state === 'running' }
						>
							{ __( 'Test the endpoint', 'saddle' ) }
						</Button>
						{ test && test.state === 'ok' && (
							<span className="saddle-testresult saddle-testresult--ok">
								{ test.count !== null
									? `✓ ${ sprintf(
											/* translators: 1: tool count, 2: milliseconds. */
											__(
												'%1$d tools · %2$dms',
												'saddle'
											),
											test.count,
											test.ms
									  ) }`
									: `✓ ${ __( 'Responding', 'saddle' ) } · ${
											test.ms
									  }ms` }
							</span>
						) }
						{ test && test.state === 'error' && (
							<span className="saddle-testresult saddle-testresult--err">
								{ `✕ ${ test.message }` }
							</span>
						) }
					</div>
					<ConnectionHealth />
					<p className="saddle-apps__transport">
						{ saddleData.adapter
							? __(
									'Endpoint powered by the WordPress MCP Adapter.',
									'saddle'
							  )
							: __(
									'Endpoint powered by Saddle’s built-in MCP transport.',
									'saddle'
							  ) }
					</p>
				</div>
			) }
		</div>
	);
}
