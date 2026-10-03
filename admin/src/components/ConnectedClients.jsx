/**
 * AI apps → Connected (#285, #309): one row per app that can reach the site.
 *
 * Each row is an app with its own access: a dropdown of Read only, Edit
 * content, Manage the site, changed on the spot (`POST
 * /connections/{id}/role`), one line under its name (when it was last used
 * or connected), and a ⋯ menu for the setup guide, a new key and
 * disconnecting. Keys and apps that signed in themselves are one list, from
 * the connection registry. With nothing connected the page shows a light
 * tile per app instead (QuickStart); a tile, the "Another MCP app" link, or
 * the section button once an app is connected, opens the connect drawer
 * (ConnectApps). The site's address sits on one line under either. The
 * endpoint test and server health checks are their own collapsed section
 * (ConnectionDetails), which Settings → Advanced shows.
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
	Select,
	DropdownMenu,
	DropdownItem,
	DropdownSeparator,
	IconButton,
	CopyButton,
	useConfirm,
	toast,
} from '@plugpress/ui';
import SectionHeader from './SectionHeader';
import { __, sprintf } from '@wordpress/i18n';
import { saddleData, api, connectionPath } from '../api';
import { APPS, APP_GROUPS } from '../connect-apps';
import { metaLine } from '../apps-logic';
import ConnectionHealth from './ConnectionHealth';
import McpDiagnostics from './McpDiagnostics';
import SetupGuideDrawer from './SetupGuideDrawer';
import { AppLogo, appKeyFromLabel } from './icons';
import { icons } from '../icons/kit';

const MCP_URL = saddleData.mcpUrl || '';

/**
 * The three roles, in the order the dropdown lists them. The words match
 * `Saddle_Access::labels()`; each `hint` is what that role adds, shown under
 * its name in the open list, so the choice explains itself where it is made.
 */
export const ROLES = [
	{
		key: 'read',
		label: __( 'Read only', 'saddle' ),
		hint: __( 'Looks at everything, changes nothing', 'saddle' ),
	},
	{
		key: 'write',
		label: __( 'Edit content', 'saddle' ),
		hint: __( 'Posts, pages, media, menus and SEO', 'saddle' ),
	},
	{
		key: 'admin',
		label: __( 'Manage the site', 'saddle' ),
		hint: __( 'Also plugins, themes, updates and settings', 'saddle' ),
	},
];

// The open list shows each role's hint under its name; the closed box shows
// the name only (the hint is hidden there in CSS).
const ROLE_OPTIONS = ROLES.map( ( r ) => ( {
	value: r.key,
	label: (
		<span className="saddle-role">
			<span className="saddle-role__name">{ r.label }</span>
			<span className="saddle-role__hint">{ r.hint }</span>
		</span>
	),
} ) );

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
 * The site's address for AI apps on one quiet line: a label, the address in
 * a code chip, and Copy.
 */
function AddressLine() {
	if ( ! MCP_URL ) {
		return null;
	}
	return (
		<p className="saddle-apps__address">
			<span className="saddle-apps__address-label">
				{ __( 'Address', 'saddle' ) }
			</span>
			<code className="saddle-apps__url">{ MCP_URL }</code>
			<CopyButton
				value={ MCP_URL }
				variant="link"
				copiedLabel={ __( 'Copied', 'saddle' ) }
			>
				{ __( 'Copy', 'saddle' ) }
			</CopyButton>
		</p>
	);
}

/**
 * Nothing connected yet: a light tile per app, logo and name, in the connect
 * drawer's groups. A tile opens that app's setup; "Another MCP app" opens
 * the generic one.
 *
 * @param {Object}   props
 * @param {Function} props.onPick Called with an app key.
 */
function QuickStart( { onPick } ) {
	const byKey = Object.fromEntries( APPS.map( ( a ) => [ a.key, a ] ) );
	return (
		<section className="saddle-stack saddle-quick">
			<SectionHeader title={ __( 'Connect an app', 'saddle' ) } />
			{ APP_GROUPS.map( ( g ) => (
				<div key={ g.key } className="saddle-quick__group">
					<h3
						className="saddle-quick__group-title"
						id={ `saddle-quick-${ g.key }` }
					>
						{ g.label }
					</h3>
					<div
						className="saddle-quick__cards"
						role="group"
						aria-labelledby={ `saddle-quick-${ g.key }` }
					>
						{ g.apps
							.filter( ( key ) => byKey[ key ] )
							.map( ( key ) => (
								<button
									key={ key }
									type="button"
									className="saddle-quick__card"
									onClick={ () => onPick( key ) }
								>
									<AppLogo app={ key } />
									<span className="saddle-quick__name">
										{ byKey[ key ].label }
									</span>
								</button>
							) ) }
					</div>
				</div>
			) ) }
			<div className="saddle-quick__more">
				<p className="saddle-quick__other">
					<Button variant="link" onClick={ () => onPick( 'other' ) }>
						{ __( 'Another MCP app', 'saddle' ) }
					</Button>
				</p>
				<AddressLine />
			</div>
		</section>
	);
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
		api( connectionPath( c.id, 'role' ), {
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

	const none = null !== rows && 0 === rows.length;

	return (
		<>
			{ none && onConnect && (
				<QuickStart onPick={ ( key ) => onConnect( key ) } />
			) }

			{ ! ( none && onConnect ) && (
				<section className="saddle-stack saddle-apps">
					<SectionHeader
						title={ __( 'Connected', 'saddle' ) }
						actions={
							onConnect && (
								<Button
									variant="primary"
									size="sm"
									onClick={ () => onConnect() }
								>
									{ __( 'Connect an app', 'saddle' ) }
								</Button>
							)
						}
					/>

					<RowList loading={ null === rows } loadingRows={ 1 }>
						{ null !== rows && 0 === rows.length && (
							<Row
								title={
									<span className="saddle-apps__empty">
										{ __(
											'No apps connected yet.',
											'saddle'
										) }
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
											appKeyFromLabel(
												c.name || c.client
											)
										}
									/>
								}
								title={ nameOf( c ) }
								description={ metaLine( c ) }
								actions={
									<>
										<Select
											className="saddle-apps__access"
											contentClassName="saddle-role__list"
											options={ ROLE_OPTIONS }
											value={ roleOf( c, siteTier ) }
											aria-label={ sprintf(
												/* translators: %s: the app name. */
												__(
													'What %s can do',
													'saddle'
												),
												nameOf( c )
											) }
											onChange={ ( role ) =>
												changeRole( c, role )
											}
										/>
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
													<icons.MoreHorizontal
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
																label: nameOf(
																	c
																),
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
												onSelect={ () =>
													askRevoke( c )
												}
											>
												{ __( 'Disconnect', 'saddle' ) }
											</DropdownItem>
										</DropdownMenu>
									</>
								}
							/>
						) ) }
					</RowList>
					<AddressLine />
				</section>
			) }

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
