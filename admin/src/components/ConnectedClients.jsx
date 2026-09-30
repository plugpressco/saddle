/**
 * AI apps → Connected (#285): one row per app that can reach the site.
 *
 * Each row is an app with its own access: a dropdown of Read only, Edit
 * content, Manage the site, changed on the spot (`POST
 * /connections/{id}/role`), and a ⋯ menu for the setup guide, a new key and
 * disconnecting. Keys and apps that signed in themselves are one list, from
 * the connection registry. The connect flow sits in a drawer opened by the
 * header button (ConnectApps). The endpoint test and server health checks
 * are their own collapsed section (ConnectionDetails), which Settings →
 * Advanced shows.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Collapsible,
	RowList,
	Row,
	Badge,
	Snippet,
	HelpTip,
	NativeSelect,
	DropdownMenu,
	DropdownItem,
	DropdownSeparator,
	IconButton,
	MoreHorizontalIcon,
	CopyButton,
	useConfirm,
	toast,
} from '@plugpress/ui';
import SectionHeader from './SectionHeader';
import { __, sprintf } from '@wordpress/i18n';
import { saddleData, api } from '../api';
import { APPS } from '../connect-apps';
import { relativeWhen } from '../activity-format';
import ConnectionHealth from './ConnectionHealth';
import McpDiagnostics from './McpDiagnostics';
import SetupGuideDrawer from './SetupGuideDrawer';
import { AppLogo, appKeyFromLabel } from './icons';

const MCP_URL = saddleData.mcpUrl || '';

/**
 * The three roles, in the order the dropdown lists them. The words match
 * `Saddle_Access::labels()`.
 */
export const ROLES = [
	{ key: 'read', label: __( 'Read only', 'saddle' ) },
	{ key: 'write', label: __( 'Edit content', 'saddle' ) },
	{ key: 'admin', label: __( 'Manage the site', 'saddle' ) },
];

const roleLabel = ( key ) =>
	( ROLES.find( ( r ) => r.key === key ) || {} ).label || key;

/**
 * What an app's role is. Until the server sends one (an older Core), a
 * grant's own level stands in, and a key falls back to the site's tier.
 *
 * @param {Object} c        A row from GET /connections.
 * @param {string} siteTier The site's tier.
 * @return {string} read, write or admin.
 */
const roleOf = ( c, siteTier ) =>
	c.role || ( 'oauth' === c.kind ? c.level : siteTier ) || 'read';

/**
 * The app as the owner knows it: what they named it, else the app Saddle
 * recognised, else what the app calls itself.
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} Label.
 */
function nameOf( c ) {
	const app = APPS.find( ( a ) => a.key === c.app );
	return c.name || ( app && app.label ) || c.client || __( 'App', 'saddle' );
}

/**
 * "Key ····4f2a · used 2 minutes ago" or "Signed in as fahim · used …".
 *
 * @param {Object} c A row from GET /connections.
 * @return {string} One line.
 */
function metaOf( c ) {
	let how = __( 'Key', 'saddle' );
	if ( 'oauth' === c.kind ) {
		how = sprintf(
			/* translators: %s: WordPress username. */
			__( 'Signed in as %s', 'saddle' ),
			c.user_login
		);
	} else if ( c.hint ) {
		how = sprintf(
			/* translators: %s: the last four characters of a key. */
			__( 'Key ····%s', 'saddle' ),
			c.hint
		);
	}
	const last = c.last_tool_at || c.last_seen_at;
	return [
		how,
		last
			? sprintf(
					/* translators: %s: how long ago, such as "2 minutes ago". */
					__( 'used %s', 'saddle' ),
					relativeWhen( new Date( last * 1000 ) )
			  )
			: __( 'not used yet', 'saddle' ),
	].join( ' · ' );
}

/**
 * @param {Object}   props
 * @param {Array}    props.clients          The keys (GET /clients); a change
 *                                          here reloads the list.
 * @param {Function} props.onClientsChanged Reloads the keys.
 * @param {Function} props.onClientRemoved  Drops a revoked key from the list.
 * @param {string}   props.siteTier         The site's level (older Core).
 * @param {Function} props.onConnect        Opens the connect drawer.
 */
export default function Apps( {
	clients,
	onClientsChanged,
	onClientRemoved,
	siteTier,
	onConnect,
} ) {
	const confirm = useConfirm();
	// The setup-guide drawer: { app, label, password? } — password only right
	// after a rotation (shown once), otherwise placeholder mode.
	const [ guide, setGuide ] = useState( null );
	// null until the list arrives.
	const [ rows, setRows ] = useState( null );

	const refresh = useCallback(
		() =>
			api( 'connections' )
				.then( ( res ) => setRows( res.connections || [] ) )
				.catch( () => setRows( ( prev ) => prev || [] ) ),
		[]
	);

	// A new key, a removed key, or the wizard closing changes `clients`.
	useEffect( () => {
		refresh();
	}, [ refresh, clients ] );

	const changeRole = ( c, role ) => {
		if ( role === roleOf( c, siteTier ) ) {
			return;
		}
		// Optimistic, then reconcile.
		setRows( ( list ) =>
			list.map( ( x ) => ( x.id === c.id ? { ...x, role } : x ) )
		);
		api( `connections/${ encodeURIComponent( c.id ) }/role`, {
			method: 'POST',
			data: { role },
		} )
			.then( () => {
				toast.success(
					sprintf(
						/* translators: 1: the app name, 2: its access, such as "Edit content". */
						__(
							'%1$s is now set to “%2$s”. Reopen the app to pick up its new tools.',
							'saddle'
						),
						nameOf( c ),
						roleLabel( role )
					)
				);
				refresh();
			} )
			.catch( ( e ) => {
				toast.error( e.message );
				refresh();
			} );
	};

	const askRotate = async ( c ) => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: the app name. */
				__( 'Rotate “%s”’s key?', 'saddle' ),
				nameOf( c )
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
		const uuid = c.id.replace( /^key:/, '' );
		api( `clients/${ uuid }/rotate`, { method: 'POST' } )
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
		const isKey = 'key' === c.kind;
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: the app name. */
				__( 'Disconnect “%s”?', 'saddle' ),
				nameOf( c )
			),
			description: isKey
				? __(
						'Its sign-in key stops working the moment you confirm — the app loses access to this site immediately. You can always connect the app again with a fresh key.',
						'saddle'
				  )
				: __(
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
		const path = isKey
			? `clients/${ c.id.replace( /^key:/, '' ) }`
			: `oauth-connections/${ c.id.replace( /^oauth:/, '' ) }`;

		// Optimistic, then reconcile: a failed refetch surfaces as a toast
		// rather than a silently stale row.
		setRows( ( list ) => list.filter( ( x ) => x.id !== c.id ) );
		api( path, { method: 'DELETE' } )
			.then( () => {
				if ( isKey && onClientRemoved ) {
					onClientRemoved( c.id.replace( /^key:/, '' ) );
				}
				toast.success(
					__(
						'Disconnected. That app’s sign-in no longer works.',
						'saddle'
					)
				);
				if ( isKey && onClientsChanged ) {
					onClientsChanged();
				}
				refresh();
			} )
			.catch( ( e ) => {
				toast.error( e.message );
				refresh();
			} );
	};

	return (
		<>
			<section className="saddle-stack saddle-apps">
				<SectionHeader
					title={ __( 'Connected', 'saddle' ) }
					actions={
						onConnect && (
							<Button
								variant="primary"
								size="sm"
								onClick={ onConnect }
							>
								{ __( 'Connect an app', 'saddle' ) }
							</Button>
						)
					}
				/>

				<RowList>
					{ null !== rows && 0 === rows.length && (
						<Row
							title={
								<span className="saddle-apps__empty">
									{ __( 'No apps connected yet.', 'saddle' ) }
								</span>
							}
						/>
					) }
					{ ( rows || [] ).map( ( c ) => (
						<Row
							key={ c.id }
							icon={
								<AppLogo
									app={
										c.app ||
										appKeyFromLabel( c.name || c.client )
									}
								/>
							}
							title={ nameOf( c ) }
							description={ metaOf( c ) }
							actions={
								<>
									<NativeSelect
										className="saddle-apps__access"
										value={ roleOf( c, siteTier ) }
										aria-label={ sprintf(
											/* translators: %s: the app name. */
											__( 'What %s can do', 'saddle' ),
											nameOf( c )
										) }
										onChange={ ( e ) =>
											changeRole( c, e.target.value )
										}
									>
										{ ROLES.map( ( r ) => (
											<option
												key={ r.key }
												value={ r.key }
											>
												{ r.label }
											</option>
										) ) }
									</NativeSelect>
									<DropdownMenu
										trigger={
											<IconButton
												aria-label={ sprintf(
													/* translators: %s: the app name. */
													__(
														'More for %s',
														'saddle'
													),
													nameOf( c )
												) }
											>
												<MoreHorizontalIcon
													size={ 16 }
												/>
											</IconButton>
										}
									>
										{ 'key' === c.kind && (
											<>
												<DropdownItem
													onSelect={ () =>
														setGuide( {
															app: appKeyFromLabel(
																c.name
															),
															label: nameOf( c ),
														} )
													}
												>
													{ __(
														'Setup guide',
														'saddle'
													) }
												</DropdownItem>
												<DropdownItem
													onSelect={ () =>
														askRotate( c )
													}
												>
													{ __(
														'Rotate key',
														'saddle'
													) }
												</DropdownItem>
												<DropdownSeparator />
											</>
										) }
										<DropdownItem
											danger
											onSelect={ () => askRevoke( c ) }
										>
											{ __( 'Disconnect', 'saddle' ) }
										</DropdownItem>
									</DropdownMenu>
								</>
							}
						/>
					) ) }
				</RowList>

				<Collapsible
					className="saddle-apps__levels"
					trigger={ __( 'What each access means', 'saddle' ) }
				>
					<ul className="saddle-apps__levels-list">
						<li>
							<strong>{ __( 'Read only', 'saddle' ) }</strong>
							{ ' · ' }
							{ __(
								'looks at everything, changes nothing.',
								'saddle'
							) }
						</li>
						<li>
							<strong>{ __( 'Edit content', 'saddle' ) }</strong>
							{ ' · ' }
							{ __(
								'writes and edits posts, pages, media and SEO. Asks you before it publishes or deletes.',
								'saddle'
							) }
						</li>
						<li>
							<strong>
								{ __( 'Manage the site', 'saddle' ) }
							</strong>
							{ ' · ' }
							{ __(
								'also menus, settings, plugins and themes. Asks you before anything big.',
								'saddle'
							) }
						</li>
						<li className="saddle-apps__levels-note">
							{ __( 'New apps start at Read only.', 'saddle' ) }
						</li>
					</ul>
				</Collapsible>
			</section>

			<p className="saddle-apps__address">
				<span>
					{ __( 'This site’s address for AI apps:', 'saddle' ) }
				</span>
				<code>{ MCP_URL }</code>
				<CopyButton value={ MCP_URL } size="sm" variant="link" />
			</p>

			{ guide && (
				<SetupGuideDrawer
					open={ !! guide }
					onOpenChange={ ( open ) => ! open && setGuide( null ) }
					app={ guide.app }
					label={ guide.label }
					password={ guide.password }
				/>
			) }
		</>
	);
}

/**
 * Connection details: the address, an endpoint test, the server health checks
 * and the request record. For troubleshooting, so it starts collapsed.
 */
export function ConnectionDetails() {
	const [ open, setOpen ] = useState( false );
	const [ test, setTest ] = useState( null );

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
		<section className="saddle-section saddle-details">
			<SectionHeader title={ __( 'Connection details', 'saddle' ) } />
			<Collapsible
				open={ open }
				onOpenChange={ setOpen }
				trigger={ __( 'Health and diagnostics', 'saddle' ) }
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
		</section>
	);
}
