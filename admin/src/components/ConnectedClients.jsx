/**
 * Connect tab — the steady state.
 *
 * A list of connected apps you can trust at a glance (what's connected, when it
 * last talked to the site, from where) plus one clear action: Connect an app,
 * which opens the guided wizard. The endpoint test and server health checks
 * live behind a disclosure — they're for troubleshooting, not for every visit.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Spinner,
	Collapsible,
	PageHeader,
	EmptyState,
	RowList,
	Row,
	StatusDot,
	Badge,
	Snippet,
	Card,
	CardContent,
	HelpTip,
	useConfirm,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { saddleData, api } from '../api';
import ConnectionHealth from './ConnectionHealth';
import McpDiagnostics from './McpDiagnostics';
import SetupGuideDrawer from './SetupGuideDrawer';
import { IconConnect, AppLogo, appKeyFromLabel } from './icons';

const MCP_URL = saddleData.mcpUrl || '';

// A title with an optional "?" beside it — the long explanation rides a tooltip
// so the page stays scannable instead of carrying a paragraph under every
// heading. Same idiom as Guidance's Heading and Dashboard's StatLabel.
const Titled = ( { children, help } ) => (
	<span className="saddle-apps__titled">
		{ children }
		{ help && <HelpTip>{ help }</HelpTip> }
	</span>
);

const DATE_FMT = new Intl.DateTimeFormat( undefined, {
	dateStyle: 'medium',
	timeStyle: 'short',
} );

const when = ( ts ) => ( ts ? DATE_FMT.format( new Date( ts * 1000 ) ) : null );

// A connection this old that has never made a request almost certainly never
// finished setup — the list says so, so stale keys don't linger unnoticed.
const NEVER_USED_STALE_SECONDS = 48 * 3600;

const isStaleNeverUsed = ( c ) =>
	! c.last_used &&
	c.created &&
	Date.now() / 1000 - c.created > NEVER_USED_STALE_SECONDS;

export default function Apps( {
	clients,
	loading,
	onConnect,
	onClientsChanged,
	onClientRemoved,
} ) {
	const confirm = useConfirm();
	const [ showAdvanced, setShowAdvanced ] = useState( false );
	const [ test, setTest ] = useState( null );
	// The setup-guide drawer: { app, label, password? } — password only right
	// after a rotation (shown once), otherwise placeholder mode.
	const [ guide, setGuide ] = useState( null );
	// Apps that connected through the sign-in screen rather than a pasted key.
	// Listed separately because they behave differently: nothing was ever
	// copied, and there is no key to rotate — only to take away.
	const [ oauthConnections, setOauthConnections ] = useState( [] );

	const refreshOauth = () =>
		api( 'oauth-connections' )
			.then( ( res ) => setOauthConnections( res || [] ) )
			.catch( () => setOauthConnections( [] ) );

	useEffect( () => {
		refreshOauth();
	}, [] );

	const revokeOauth = async ( c ) => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: the app name. */
				__( 'Disconnect “%s”?', 'saddle' ),
				c.name
			),
			description: __(
				'It loses access the moment you confirm — no waiting for anything to expire. To use it again you’ll approve it once more.',
				'saddle'
			),
			danger: true,
			confirmLabel: __( 'Disconnect', 'saddle' ),
			cancelLabel: __( 'Keep it connected', 'saddle' ),
		} );
		if ( ! ok ) {
			return;
		}

		// Optimistic, then reconcile — the same pattern the key list uses, so a
		// failed refetch surfaces as a toast rather than a silently stale row.
		setOauthConnections( ( list ) =>
			list.filter( ( x ) => x.id !== c.id )
		);

		api( `oauth-connections/${ c.id }`, { method: 'DELETE' } )
			.then( refreshOauth )
			.catch( ( e ) => {
				toast.error( e.message );
				refreshOauth();
			} );
	};

	const askRotate = async ( c ) => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: the app name. */
				__( 'Rotate “%s”’s key?', 'saddle' ),
				c.label || c.name
			),
			description: __(
				'The current key stops working the moment you confirm, and a fresh one is issued under the same name. You’ll paste the new setup into the app right after — until then it can’t connect.',
				'saddle'
			),
			danger: true,
			confirmLabel: __( 'Rotate key', 'saddle' ),
			cancelLabel: __( 'Keep the current key', 'saddle' ),
		} );
		if ( ! ok ) {
			return;
		}
		api( `clients/${ c.uuid }/rotate`, { method: 'POST' } )
			.then( ( res ) => {
				setGuide( {
					app: appKeyFromLabel( res.label || res.name ),
					label: res.label || res.name,
					password: res.password,
				} );
				if ( onClientsChanged ) {
					onClientsChanged();
				}
			} )
			.catch( ( e ) => toast.error( e.message ) );
	};

	const askRevoke = async ( c ) => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: the app name. */
				__( 'Disconnect “%s”?', 'saddle' ),
				c.label || c.name
			),
			description: __(
				'Its sign-in key stops working the moment you confirm — the app loses access to this site immediately. This can’t be undone, but you can always connect the app again with a fresh key.',
				'saddle'
			),
			danger: true,
			confirmLabel: __( 'Disconnect', 'saddle' ),
			cancelLabel: __( 'Keep it connected', 'saddle' ),
		} );
		if ( ! ok ) {
			return;
		}
		api( `clients/${ c.uuid }`, { method: 'DELETE' } )
			.then( () => {
				// Optimistic: the row disappears immediately on DELETE
				// success; the refetch below only reconciles with the server.
				if ( onClientRemoved ) {
					onClientRemoved( c.uuid );
				}
				toast.success(
					__(
						'Disconnected. That app’s sign-in no longer works.',
						'saddle'
					)
				);
				if ( onClientsChanged ) {
					onClientsChanged();
				}
			} )
			.catch( ( e ) => toast.error( e.message ) );
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
			<PageHeader
				title={
					<Titled
						help={ __(
							'Every app here has its own sign-in you can take away at any moment, and they all follow the same rules you set on Permissions.',
							'saddle'
						) }
					>
						{ __( 'Connected apps', 'saddle' ) }
					</Titled>
				}
				description={ __(
					'Apps you’ve let talk to this site.',
					'saddle'
				) }
				actions={
					<Button variant="primary" onClick={ onConnect }>
						{ __( 'Connect an app', 'saddle' ) }
					</Button>
				}
			/>

			{ loading && <Spinner /> }

			{ /* Empty only when BOTH kinds are absent — an OAuth-only site is
			     connected, whatever the key list says. */ }
			{ ! loading &&
				clients.length === 0 &&
				oauthConnections.length === 0 && (
					<EmptyState
						icon={ <IconConnect /> }
						title={ __( 'Nothing connected yet', 'saddle' ) }
						description={ __(
							'Connect Claude, Cursor, or another AI app — it takes about a minute.',
							'saddle'
						) }
						actions={
							<Button variant="primary" onClick={ onConnect }>
								{ __( 'Connect an app', 'saddle' ) }
							</Button>
						}
					/>
				) }

			{ /* Both kinds live in ONE card: they are one mental list ("what can
			     reach my site"), and two boxes for what is often two rows was
			     the clutter. The divider below carries the distinction. */ }
			{ ! loading &&
				( clients.length > 0 || oauthConnections.length > 0 ) && (
					<Card className="saddle-apps__card">
						<CardContent className="saddle-apps__cardbody">
							{ clients.length > 0 && (
								<RowList>
									{ clients.map( ( c ) => (
										<Row
											key={ c.uuid }
											icon={
												<AppLogo
													app={ appKeyFromLabel(
														c.label || c.name
													) }
												/>
											}
											title={
												<>
													<StatusDot
														tone={
															c.last_used
																? 'success'
																: 'neutral'
														}
													/>{ ' ' }
													{ c.label || c.name }
												</>
											}
											description={
												( c.last_used
													? sprintf(
															/* translators: %s: date and time. */
															__(
																'Last active %s',
																'saddle'
															),
															when( c.last_used )
													  )
													: __(
															'Hasn’t connected yet — it will on first use',
															'saddle'
													  ) ) +
												( isStaleNeverUsed( c )
													? ` · ${ __(
															'never connected — safe to disconnect',
															'saddle'
													  ) }`
													: '' ) +
												( c.last_ip
													? ` · ${ c.last_ip }`
													: '' ) +
												( c.hint
													? ` · ${ __(
															'key',
															'saddle'
													  ) } ····${ c.hint }`
													: '' )
											}
											actions={
												<>
													<Button
														variant="link"
														onClick={ () =>
															setGuide( {
																app: appKeyFromLabel(
																	c.label ||
																		c.name
																),
																label:
																	c.label ||
																	c.name,
															} )
														}
													>
														{ __(
															'Setup guide',
															'saddle'
														) }
													</Button>
													<Button
														variant="link"
														onClick={ () =>
															askRotate( c )
														}
													>
														{ __(
															'Rotate key',
															'saddle'
														) }
													</Button>
													<Button
														variant="link"
														className="saddle-link-danger"
														onClick={ () =>
															askRevoke( c )
														}
													>
														{ __(
															'Disconnect',
															'saddle'
														) }
													</Button>
												</>
											}
										/>
									) ) }
								</RowList>
							) }

							{ oauthConnections.length > 0 && (
								<>
									<div className="saddle-apps__divider">
										<Titled
											help={ __(
												'These connected through the sign-in screen instead of a pasted key — ChatGPT works this way. Disconnecting one takes effect immediately.',
												'saddle'
											) }
										>
											{ __(
												'Signed in themselves',
												'saddle'
											) }
										</Titled>
									</div>
									<RowList>
										{ oauthConnections.map( ( c ) => (
											<Row
												key={ c.id }
												icon={
													<AppLogo
														app={ appKeyFromLabel(
															c.name
														) }
													/>
												}
												title={
													<>
														<StatusDot
															tone={
																c.last_used
																	? 'success'
																	: 'neutral'
															}
														/>{ ' ' }
														{ c.name }
													</>
												}
												description={ sprintf(
													/* translators: 1: access level, 2: WordPress username. */
													__(
														'%1$s access, acting as %2$s',
														'saddle'
													),
													c.level,
													c.user_login
												) }
												actions={
													<>
														<Badge
															tone={
																c.verified
																	? undefined
																	: 'warning'
															}
														>
															{ c.verified
																? __(
																		'Verified',
																		'saddle'
																  )
																: __(
																		'Unverified',
																		'saddle'
																  ) }
														</Badge>
														<Button
															variant="ghost"
															size="sm"
															onClick={ () =>
																revokeOauth( c )
															}
														>
															{ __(
																'Disconnect',
																'saddle'
															) }
														</Button>
													</>
												}
											/>
										) ) }
									</RowList>
								</>
							) }

							{ /* The link stays a real link — a tooltip can hold
							     the explanation, but not something clickable. */ }
							{ clients.length > 0 &&
								window.saddleData?.profileUrl && (
									<p className="saddle-apps__cardfoot">
										<a
											href={
												window.saddleData.profileUrl
											}
										>
											{ __(
												'Manage these keys in WordPress',
												'saddle'
											) }
										</a>
										<HelpTip>
											{ __(
												'These keys also appear under Users → Profile → Application Passwords — the same keys, and either place works for revoking.',
												'saddle'
											) }
										</HelpTip>
									</p>
								) }
						</CardContent>
					</Card>
				) }

			<Collapsible
				className="saddle-apps__more"
				open={ showAdvanced }
				onOpenChange={ setShowAdvanced }
				trigger={ __( 'Connection details & health', 'saddle' ) }
			>
				<div className="saddle-apps__advanced">
					{ /* Label stacked ABOVE the control rather than ridden into
					     the DS Snippet's inline label bar, which squeezed the
					     title and the value into one short row. */ }
					<div className="saddle-apps__field">
						<span className="saddle-apps__fieldlabel">
							{ __(
								'Your site’s address for AI apps',
								'saddle'
							) }
						</span>
						<Snippet value={ MCP_URL } />
					</div>
					<div className="saddle-apps__test">
						<Button
							variant="secondary"
							onClick={ runTest }
							loading={ test && test.state === 'running' }
							disabled={ test && test.state === 'running' }
						>
							{ __( 'Test the endpoint', 'saddle' ) }
						</Button>
						{ test && test.state === 'ok' && (
							<Badge tone="success">
								{ test.count !== null
									? sprintf(
											/* translators: 1: tool count, 2: milliseconds. */
											__(
												'%1$d tools · %2$dms',
												'saddle'
											),
											test.count,
											test.ms
									  )
									: `${ __( 'Responding', 'saddle' ) } · ${
											test.ms
									  }ms` }
							</Badge>
						) }
						{ test && test.state === 'error' && (
							<Badge tone="danger">{ test.message }</Badge>
						) }
					</div>
					<ConnectionHealth />
					<p className="saddle-apps__transport">
						<span>{ __( 'Transport', 'saddle' ) }</span>
						<HelpTip>
							{ saddleData.adapter
								? __(
										'Saddle is using the MCP Adapter plugin, which is active on this site. Your tools, access levels and approvals are unchanged — this only affects how requests are carried.',
										'saddle'
								  )
								: __(
										'Saddle speaks MCP itself, so there is nothing else to install. If you ever add the separate MCP Adapter plugin, Saddle will use it automatically; your tools, access levels and approvals stay the same either way.',
										'saddle'
								  ) }
						</HelpTip>
					</p>
					<McpDiagnostics />
				</div>
			</Collapsible>

			{ guide && (
				<SetupGuideDrawer
					open={ !! guide }
					onOpenChange={ ( open ) => ! open && setGuide( null ) }
					app={ guide.app }
					label={ guide.label }
					password={ guide.password }
				/>
			) }
		</div>
	);
}
