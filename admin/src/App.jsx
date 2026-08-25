/**
 * Saddle admin — a guided, person-first workspace on the DS sidebar shell.
 *
 * First run shows a short setup that flows straight into connecting the first
 * app. After that, the grouped rail (AppShell/AppNav — the same shell Waggle
 * ships): Dashboard, then Your AI (Permissions, Guidance, Memory), Connect
 * (Connections, Integrations), Monitor (Activity), and Settings pinned in the
 * rail footer. A slim sticky top bar above the content carries the page
 * context and the always-visible safety-status pill. Each page owns its own
 * PageHeader; the rail carries no page title. Connecting an app is a focused,
 * full-panel wizard — one step at a time — not a page of forms.
 */
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';
import {
	TooltipProvider,
	Tooltip,
	ConfirmProvider,
	Toaster,
	toast,
	Spinner,
	Notice,
	Button,
	Popover,
	SkipLink,
	AppShell,
	AppNav,
	AppContent,
	StatusDot,
	DashboardIcon,
	ShieldIcon,
	BookOpenIcon,
	InboxIcon,
	LinkIcon,
	PlugIcon,
	ActivityIcon,
	SettingsIcon,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api, levelFor } from './api';
import { BrandMark, IconBell } from './components/icons';
import Onboarding from './components/Onboarding';
import Dashboard from './components/Dashboard';
import Permissions from './components/Permissions';
import Guidance from './components/Guidance';
import Memory from './components/Memory';
import Apps from './components/ConnectedClients';
import Integrations from './components/Integrations';
import Activity from './components/Activity';
import Settings from './components/Settings';
import ConnectWizard from './components/ConnectWizard';
import AuthTrouble from './components/AuthTrouble';
import { collectTabs, ui as extensionUi, SHELL_VERSION } from './extensions';

const TABS = [
	{
		name: 'dashboard',
		title: __( 'Dashboard', 'saddle' ),
		icon: <DashboardIcon />,
	},
	{
		name: 'permissions',
		title: __( 'Permissions', 'saddle' ),
		icon: <ShieldIcon />,
	},
	{
		name: 'guidance',
		title: __( 'Instructions', 'saddle' ),
		icon: <BookOpenIcon />,
	},
	{ name: 'memory', title: __( 'Memory', 'saddle' ), icon: <InboxIcon /> },
	{
		name: 'connect',
		title: __( 'Apps', 'saddle' ),
		icon: <LinkIcon />,
	},
	{
		name: 'integrations',
		title: __( 'Integrations', 'saddle' ),
		icon: <PlugIcon />,
	},
	{
		name: 'activity',
		title: __( 'Activity', 'saddle' ),
		icon: <ActivityIcon />,
	},
	{
		name: 'settings',
		title: __( 'Settings', 'saddle' ),
		icon: <SettingsIcon />,
	},
];

// The rail, in order. One flat list rather than labeled sections: seven items
// is not a wall, and the headings were three more things to read before you
// could read the thing you came for. Names reference TABS (routing stays keyed
// by name); Settings sits in the rail footer.
const NAV_MAIN = [
	'dashboard',
	'permissions',
	'guidance',
	'memory',
	'connect',
	'integrations',
	'activity',
];
const NAV_FOOTER = [ 'settings' ];

// One content width for every page — 960 sits between the DS content (760)
// and wide (1080) presets: sparse pages stop feeling empty, Permissions'
// three lanes still fit, and the column never resizes between sections.
const PAGE_WIDTH = 960;

// Resolve a tab name to the { value, label, icon } shape AppNav renders.
// String labels matter: they become the native tooltips when the rail
// collapses to icons below wp-admin's 782px breakpoint. The nav lists are
// built per mount (not at module scope) so extension tabs — registered by
// addon bundles that evaluate after this one — are included.
const navItem = ( name, all ) => {
	const t = all.find( ( tab ) => tab.name === name );
	return { value: t.name, label: t.title, icon: t.icon };
};

// Legacy-hash aliases — old bookmarks must keep resolving after renames.
const ALIASES = { home: 'dashboard' };

// The URL hash is the single source of truth for the active section, so a
// reload keeps you on the same page and the browser back button works
// between sections.
const tabFromHash = ( extraNames = [] ) => {
	const raw = window.location.hash.replace( '#', '' );
	const h = ALIASES[ raw ] || raw;
	return TABS.some( ( t ) => t.name === h ) || extraNames.includes( h )
		? h
		: 'dashboard';
};

// Safety tone → design-system dot tone. Read-only is the calm state; any
// write power shows as "attention", paused as switched-off.
const DOT_TONES = {
	safe: 'success',
	active: 'warning',
	paused: 'neutral',
};

// The slim sticky bar above the content column: the page title on the left,
// the always-visible safety-status pill on the right. The pill is a real
// button — it jumps to Settings, where the controls it reflects live.
function TopBar( { tab, tier, paused, onNavigate, notices } ) {
	const t = TABS.find( ( x ) => x.name === tab );
	const level = levelFor( tier );
	let tone = level.key === 'read' ? 'safe' : 'active';
	if ( paused ) {
		tone = 'paused';
	}
	return (
		<header className="saddle-topbar">
			<div className="saddle-topbar__context">
				<span className="saddle-topbar__title">{ t?.title }</span>
			</div>
			<div className="saddle-topbar__right">
				{ notices && <ForeignNotices /> }
				<Tooltip
					content={ __(
						'Change this on the Settings page',
						'saddle'
					) }
				>
					<button
						type="button"
						className={ `saddle-status-pill saddle-status-pill--${ tone }` }
						onClick={ () => onNavigate( 'settings' ) }
					>
						<StatusDot tone={ DOT_TONES[ tone ] } />
						<span>
							{ paused ? __( 'Paused', 'saddle' ) : level.title }
						</span>
					</button>
				</Tooltip>
			</div>
		</header>
	);
}

/**
 * Other plugins' admin notices, captured server-side into a hidden container
 * (see Saddle_Settings::setup_notice_quarantine), surfaced behind a quiet
 * disclosure instead of piling above the app. Nodes are MOVED into the panel
 * — not re-rendered — so their own dismiss buttons and handlers keep working.
 */
function ForeignNotices() {
	const [ count, setCount ] = useState( 0 );
	const [ open, setOpen ] = useState( false );

	// A holder this component owns and keeps for its whole life. The popover
	// unmounts its content on close, so notices parented straight to the panel
	// would be destroyed on the first close — and these are the plugins' OWN
	// nodes, moved rather than copied, so losing them loses their dismiss
	// handlers with them. Parking them here keeps them alive while detached.
	const holderRef = useRef( null );
	if ( ! holderRef.current ) {
		holderRef.current = document.createElement( 'div' );
		holderRef.current.className = 'saddle-foreign__list';
	}

	useEffect( () => {
		const source = document.getElementById( 'saddle-foreign-notices' );
		if ( ! source ) {
			return;
		}
		setCount( source.children.length );
		while ( source.firstChild ) {
			holderRef.current.appendChild( source.firstChild );
		}
	}, [] );

	// A callback ref, not a ref object read from an effect: the popover mounts
	// its panel lazily when opened, so an effect keyed on `open` can run on a
	// tick where the panel does not exist yet — which is exactly why the list
	// came up empty the first time.
	const mountList = useCallback( ( node ) => {
		if ( node && holderRef.current.parentNode !== node ) {
			node.appendChild( holderRef.current );
		}
	}, [] );

	if ( ! count ) {
		return null;
	}

	const label = sprintf(
		/* translators: %d: number of notices. */
		_n(
			'%d notice from other plugins',
			'%d notices from other plugins',
			count,
			'saddle'
		),
		count
	);

	// The panel stays mounted once opened so the moved notice nodes — carrying
	// their own dismiss handlers — survive closing and reopening the popover.
	return (
		<Popover
			className="saddle-foreign"
			open={ open }
			onOpenChange={ setOpen }
			align="end"
			trigger={
				<button
					type="button"
					className="saddle-foreign__button"
					aria-label={ label }
				>
					<IconBell />
					<span>{ count }</span>
				</button>
			}
		>
			<div className="saddle-foreign__head">{ label }</div>
			<div ref={ mountList } />
		</Popover>
	);
}

export default function App() {
	const [ tier, setTier ] = useState( null );
	const [ caps, setCaps ] = useState( [] );
	const [ clients, setClients ] = useState( [] );
	const [ onboarded, setOnboarded ] = useState( true );
	const [ paused, setPaused ] = useState( false );
	const [ pausing, setPausing ] = useState( false );
	const [ domainWarning, setDomainWarning ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	// A 401 means WordPress resolved nobody at all — the session never arrived,
	// rather than arriving without permission. Nothing on this screen can load,
	// so it gets its own view instead of an error strip over an empty shell.
	const [ authError, setAuthError ] = useState( false );
	// Extension tabs (admin/src/extensions.js) — collected at mount, after
	// every addon bundle registered its filters at script evaluation.
	const extTabs = useMemo( collectTabs, [] );
	const extNames = useMemo( () => extTabs.map( ( t ) => t.id ), [ extTabs ] );
	const navItems = useMemo( () => {
		const allTabs = [
			...TABS,
			...extTabs.map( ( t ) => ( {
				name: t.id,
				title: t.label,
				icon: <t.Icon />,
			} ) ),
		];
		// A contributed tab joins the main list unless it asked for the footer.
		// The groups it used to be able to name are gone, so anything that is
		// not explicitly 'footer' lands in the one list rather than nowhere.
		const extMain = extTabs
			.filter( ( t ) => t.group !== 'footer' )
			.map( ( t ) => t.id );
		const extFooter = extTabs
			.filter( ( t ) => t.group === 'footer' )
			.map( ( t ) => t.id );

		return [
			...[ ...NAV_MAIN, ...extMain ].map( ( name ) =>
				navItem( name, allTabs )
			),
			// A real DS group rather than hand-rolled buttons in the `footer`
			// slot: `footer: true` is what puts it in .pp-nav__bottom, and it
			// brings aria-current and the collapsed-rail tooltips with it.
			// Extension footer entries sit above Settings, which stays last.
			{
				footer: true,
				items: [ ...extFooter, ...NAV_FOOTER ].map( ( name ) =>
					navItem( name, allTabs )
				),
			},
		];
	}, [ extTabs ] );
	const [ tab, setTabState ] = useState( () => tabFromHash( extNames ) );
	const [ wizardOpen, setWizardOpen ] = useState( false );

	// Navigating writes the hash; state follows the hashchange event, so
	// back/forward and direct #links all land in the same code path.
	const setTab = ( name ) => {
		if ( name === tabFromHash( extNames ) ) {
			setTabState( name );
		} else {
			window.location.hash = name;
		}
	};

	useEffect( () => {
		const onHash = () => {
			const next = tabFromHash( extNames );
			setTabState( next );
			// Leaving #connect (e.g. browser back) also dismisses the wizard.
			if ( next !== 'connect' ) {
				setWizardOpen( false );
			}
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [ extNames ] );

	const loadCaps = useCallback(
		() =>
			api( 'capabilities' ).then( ( res ) => {
				setCaps( res.capabilities || [] );
				setTier( res.current_tier );
			} ),
		[]
	);

	const loadClients = useCallback(
		() =>
			api( 'clients' ).then( ( res ) => setClients( res.clients || [] ) ),
		[]
	);

	// Refresh that surfaces failures — a raced/failed refetch must not
	// silently leave a stale connections list on screen.
	const refreshClients = useCallback(
		() =>
			loadClients().catch( ( e ) =>
				toast.error(
					sprintf(
						/* translators: %s: error message. */
						__(
							'Couldn’t refresh the connections list: %s',
							'saddle'
						),
						e.message
					)
				)
			),
		[ loadClients ]
	);

	// Optimistic removal — the revoked row disappears the moment the DELETE
	// succeeds, independent of the reconciling refetch.
	const removeClient = useCallback( ( uuid ) => {
		setClients( ( prev ) => prev.filter( ( c ) => c.uuid !== uuid ) );
	}, [] );

	useEffect( () => {
		// Older Saddle versions returned from core's Authorize screen with the
		// credential in the URL. That flow is gone, but scrub any such params a
		// stale bookmark or tab might still carry — secrets don't belong in URLs.
		if ( window.history && window.history.replaceState ) {
			const url = new URL( window.location.href );
			const legacy = [
				'password',
				'user_login',
				'site_url',
				'connected',
				'rejected',
			];
			if ( legacy.some( ( p ) => url.searchParams.has( p ) ) ) {
				legacy.forEach( ( p ) => url.searchParams.delete( p ) );
				window.history.replaceState(
					{},
					'',
					url.pathname + url.search
				);
			}
		}

		Promise.all( [
			loadCaps(),
			loadClients(),
			api( 'preferences' ).then( ( res ) => {
				setOnboarded( !! res.onboarded );
				setPaused( !! res.paused );
				setDomainWarning( !! res.domain_warning );
			} ),
		] )
			.catch( ( e ) => {
				// A 401 is WordPress rejecting our auth. `invalid_json` is
				// something answering before WordPress did — a host WAF or
				// security layer — after the ?rest_route= fallback failed too
				// (see api.js). Both deserve the auth-trouble screen and its
				// probe, not a raw error strip.
				if (
					e &&
					( ( e.data && e.data.status === 401 ) ||
						'invalid_json' === e.code )
				) {
					setAuthError( true );
				} else {
					setError( e.message );
				}
			} )
			.finally( () => setLoading( false ) );
	}, [ loadCaps, loadClients ] );

	const openWizard = () => {
		setTab( 'connect' );
		setWizardOpen( true );
	};

	const closeWizard = () => {
		setWizardOpen( false );
		refreshClients();
	};

	const finishOnboarding = ( { connect } = {} ) => {
		setOnboarded( true );
		api( 'preferences', {
			method: 'POST',
			data: { onboarded: true },
		} ).catch( () => {} );
		if ( connect ) {
			openWizard();
		} else {
			setTab( 'dashboard' );
		}
	};

	const togglePause = () => {
		const next = ! paused;
		setPausing( true );
		api( 'preferences', { method: 'POST', data: { paused: next } } )
			.then( ( res ) => setPaused( !! res.paused ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setPausing( false ) );
	};

	// Re-saving the current tier re-confirms it on this domain, clearing the
	// warning — the same effect as visiting Permissions and pressing Save.
	const clearDomainWarning = () => {
		api( 'preferences', { method: 'POST', data: { tier } } )
			.then( ( res ) => setDomainWarning( !! res.domain_warning ) )
			.catch( ( e ) => setError( e.message ) );
	};

	// A tier save from Permissions also re-confirms the domain — carry that
	// through instead of leaving a stale warning up after the user just fixed it.
	const handleTierSaved = ( newTier, warning ) => {
		setTier( newTier );
		if ( typeof warning === 'boolean' ) {
			setDomainWarning( warning );
		}
	};

	// One provider mount for every state (loading / setup / main): tooltips,
	// the useConfirm dialog, and toasts are available everywhere, and portaled
	// overlays pick up tokens from the pp-scope body class.
	let view;

	if ( loading ) {
		view = (
			<div className="pp-app saddle-app saddle-app--loading">
				<Spinner />
			</div>
		);
	} else if ( authError ) {
		view = <AuthTrouble onRetry={ () => window.location.reload() } />;
	} else if ( ! onboarded ) {
		view = (
			<div className="pp-app saddle-app saddle-app--setup">
				<Onboarding
					tier={ tier }
					onTierSaved={ setTier }
					onFinish={ finishOnboarding }
				/>
			</div>
		);
	} else {
		view = (
			<div className="pp-app saddle-app">
				<SkipLink href="#pp-main">
					{ __( 'Skip to content', 'saddle' ) }
				</SkipLink>
				<AppShell
					variant="sidebar"
					nav={
						<AppNav
							aria-label={ __( 'Saddle sections', 'saddle' ) }
							brand={
								<>
									<BrandMark />
									<span>{ __( 'Saddle', 'saddle' ) }</span>
								</>
							}
							// Navigation only. Docs, Rate Saddle and the version
							// stamp all live in Settings → About; repeating them
							// here made a five-item footer out of a two-item one.
							items={ navItems }
							value={ tab }
							onChange={ setTab }
						/>
					}
				>
					<TopBar
						tab={ tab }
						tier={ tier }
						paused={ paused }
						onNavigate={ setTab }
						notices={ ! wizardOpen }
					/>
					<AppContent width={ PAGE_WIDTH }>
						{ error && <Notice tone="danger">{ error }</Notice> }

						{ domainWarning && ! wizardOpen && (
							<Notice tone="warning">
								{ __(
									'This site’s address has changed since AI write access was turned on — often a sign of a staging clone or a migration carrying over live credentials. If that wasn’t intentional, review your connected apps and revoke anything unexpected.',
									'saddle'
								) }
								<span className="saddle-notice__actions">
									<Button
										variant="link"
										size="sm"
										onClick={ clearDomainWarning }
									>
										{ __(
											'This is expected — clear this warning',
											'saddle'
										) }
									</Button>
								</span>
							</Notice>
						) }

						{ wizardOpen ? (
							<ConnectWizard
								tier={ tier }
								clients={ clients }
								onExit={ closeWizard }
								onClientsChanged={ refreshClients }
							/>
						) : (
							<div className="saddle-tabpane" key={ tab }>
								{ tab === 'dashboard' && (
									<Dashboard
										tier={ tier }
										clients={ clients }
										onNavigate={ setTab }
										onConnect={ openWizard }
									/>
								) }
								{ tab === 'permissions' && (
									<Permissions
										caps={ caps }
										savedTier={ tier }
										onTierSaved={ handleTierSaved }
										onCapsChanged={ loadCaps }
									/>
								) }
								{ tab === 'guidance' && <Guidance /> }
								{ tab === 'memory' && <Memory /> }
								{ tab === 'activity' && <Activity /> }
								{ tab === 'connect' && (
									<Apps
										clients={ clients }
										loading={ false }
										onConnect={ openWizard }
										onClientsChanged={ refreshClients }
										onClientRemoved={ removeClient }
										siteTier={ tier }
									/>
								) }
								{ tab === 'integrations' && (
									<Integrations caps={ caps } />
								) }
								{ tab === 'settings' && (
									<Settings
										paused={ paused }
										pausing={ pausing }
										onTogglePause={ togglePause }
									/>
								) }
								{ extTabs.map(
									( t ) =>
										tab === t.id && (
											<t.Component
												key={ t.id }
												ui={ extensionUi }
												shellVersion={ SHELL_VERSION }
											/>
										)
								) }
							</div>
						) }
					</AppContent>
				</AppShell>
			</div>
		);
	}

	return (
		<TooltipProvider>
			<ConfirmProvider>
				{ view }
				<Toaster />
			</ConfirmProvider>
		</TooltipProvider>
	);
}
