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
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	TooltipProvider,
	Tooltip,
	ConfirmProvider,
	Toaster,
	toast,
	Spinner,
	Notice,
	Button,
	Collapsible,
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
	ExternalLinkIcon,
	StarIcon,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api, levelFor, saddleData } from './api';
import { BrandMark } from './components/icons';
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
		title: __( 'Guidance', 'saddle' ),
		icon: <BookOpenIcon />,
	},
	{ name: 'memory', title: __( 'Memory', 'saddle' ), icon: <InboxIcon /> },
	{
		name: 'connect',
		title: __( 'Connections', 'saddle' ),
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

// Sidebar grouping — labeled sections so eight items don't read as a flat
// wall. Names reference TABS (routing stays keyed by name); Settings lives in
// the rail footer.
const NAV_GROUPS = [
	{ key: 'top', label: '', items: [ 'dashboard' ] },
	{
		key: 'ai',
		label: __( 'Your AI', 'saddle' ),
		items: [ 'permissions', 'guidance', 'memory' ],
	},
	{
		key: 'connect',
		label: __( 'Connect', 'saddle' ),
		items: [ 'connect', 'integrations' ],
	},
	{ key: 'monitor', label: __( 'Monitor', 'saddle' ), items: [ 'activity' ] },
];
const NAV_FOOTER = [ 'settings' ];

// One content width for every page — 960 sits between the DS content (760)
// and wide (1080) presets: sparse pages stop feeling empty, Permissions'
// three lanes still fit, and the column never resizes between sections.
const PAGE_WIDTH = 960;

// Resolve a tab name to the { value, label, icon } shape AppNav renders.
// String labels matter: they become the native tooltips when the rail
// collapses to icons below wp-admin's 782px breakpoint.
const navItem = ( name ) => {
	const t = TABS.find( ( tab ) => tab.name === name );
	return { value: t.name, label: t.title, icon: t.icon };
};
const navItems = NAV_GROUPS.map( ( g ) => ( {
	heading: g.label || undefined,
	items: g.items.map( navItem ),
} ) );
const navFooter = NAV_FOOTER.map( navItem );

// Legacy-hash aliases — old bookmarks must keep resolving after renames.
const ALIASES = { home: 'dashboard' };

// The URL hash is the single source of truth for the active section, so a
// reload keeps you on the same page and the browser back button works
// between sections.
const tabFromHash = () => {
	const raw = window.location.hash.replace( '#', '' );
	const h = ALIASES[ raw ] || raw;
	return TABS.some( ( t ) => t.name === h ) ? h : 'dashboard';
};

// Safety tone → design-system dot tone. Read-only is the calm state; any
// write power shows as "attention", paused as switched-off.
const DOT_TONES = {
	safe: 'success',
	active: 'warning',
	paused: 'neutral',
};

// The slim sticky bar above the content column: page context on the left
// (nav group · page title), the always-visible safety-status pill on the
// right. The pill is a real button — it jumps to Settings, where the
// controls it reflects live.
function TopBar( { tab, tier, paused, onNavigate } ) {
	const t = TABS.find( ( x ) => x.name === tab );
	const group = NAV_GROUPS.find( ( g ) => g.items.includes( tab ) );
	const level = levelFor( tier );
	let tone = level.key === 'read' ? 'safe' : 'active';
	if ( paused ) {
		tone = 'paused';
	}
	return (
		<header className="saddle-topbar">
			<div className="saddle-topbar__context">
				{ !! group?.label && (
					<>
						<span className="saddle-topbar__group">
							{ group.label }
						</span>
						<span className="saddle-topbar__sep" aria-hidden="true">
							·
						</span>
					</>
				) }
				<span className="saddle-topbar__title">{ t?.title }</span>
			</div>
			<Tooltip
				content={ __( 'Change this on the Settings page', 'saddle' ) }
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
	const holderRef = useRef( null );
	const movedRef = useRef( false );

	useEffect( () => {
		const source = document.getElementById( 'saddle-foreign-notices' );
		if ( source ) {
			setCount( source.children.length );
		}
	}, [] );

	useEffect( () => {
		if ( ! open || movedRef.current || ! holderRef.current ) {
			return;
		}
		const source = document.getElementById( 'saddle-foreign-notices' );
		if ( ! source ) {
			return;
		}
		while ( source.firstChild ) {
			holderRef.current.appendChild( source.firstChild );
		}
		movedRef.current = true;
	}, [ open ] );

	if ( ! count ) {
		return null;
	}

	// Collapsible keeps its panel mounted (hidden attr) when closed, so the
	// moved notice nodes — with their own dismiss handlers — survive toggling.
	return (
		<Collapsible
			className="saddle-foreign"
			open={ open }
			onOpenChange={ setOpen }
			trigger={ sprintf(
				/* translators: %d: number of notices. */
				_n(
					'%d notice from other plugins',
					'%d notices from other plugins',
					count,
					'saddle'
				),
				count
			) }
		>
			<div ref={ holderRef } className="saddle-foreign__list" />
		</Collapsible>
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
	const [ tab, setTabState ] = useState( tabFromHash );
	const [ wizardOpen, setWizardOpen ] = useState( false );

	// Navigating writes the hash; state follows the hashchange event, so
	// back/forward and direct #links all land in the same code path.
	const setTab = ( name ) => {
		if ( name === tabFromHash() ) {
			setTabState( name );
		} else {
			window.location.hash = name;
		}
	};

	useEffect( () => {
		const onHash = () => {
			const next = tabFromHash();
			setTabState( next );
			// Leaving #connect (e.g. browser back) also dismisses the wizard.
			if ( next !== 'connect' ) {
				setWizardOpen( false );
			}
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

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
			api( 'settings' ).then( ( res ) => {
				setOnboarded( !! res.onboarded );
				setPaused( !! res.paused );
				setDomainWarning( !! res.domain_warning );
			} ),
		] )
			.catch( ( e ) => setError( e.message ) )
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
		api( 'settings', { method: 'POST', data: { onboarded: true } } ).catch(
			() => {}
		);
		if ( connect ) {
			openWizard();
		} else {
			setTab( 'dashboard' );
		}
	};

	const togglePause = () => {
		const next = ! paused;
		setPausing( true );
		api( 'settings', { method: 'POST', data: { paused: next } } )
			.then( ( res ) => setPaused( !! res.paused ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setPausing( false ) );
	};

	// Re-saving the current tier re-confirms it on this domain, clearing the
	// warning — the same effect as visiting Permissions and pressing Save.
	const clearDomainWarning = () => {
		api( 'settings', { method: 'POST', data: { tier } } )
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
							items={ navItems }
							value={ tab }
							onChange={ setTab }
							footer={
								<>
									{ navFooter.map( ( item ) => (
										<button
											key={ item.value }
											type="button"
											className="pp-nav__item"
											aria-current={
												tab === item.value
													? 'page'
													: undefined
											}
											title={ item.label }
											onClick={ () =>
												setTab( item.value )
											}
										>
											<span
												className="pp-nav__icon"
												aria-hidden="true"
											>
												{ item.icon }
											</span>
											<span className="pp-nav__label">
												{ item.label }
											</span>
										</button>
									) ) }
									{ saddleData.docsUrl && (
										<a
											className="pp-nav__item"
											href={ saddleData.docsUrl }
											target="_blank"
											rel="noreferrer"
											title={ __( 'Docs', 'saddle' ) }
										>
											<span
												className="pp-nav__icon"
												aria-hidden="true"
											>
												<ExternalLinkIcon />
											</span>
											<span className="pp-nav__label">
												{ __( 'Docs', 'saddle' ) }
											</span>
										</a>
									) }
									{ saddleData.rateUrl && (
										<a
											className="pp-nav__item"
											href={ saddleData.rateUrl }
											target="_blank"
											rel="noreferrer"
											title={ __(
												'Rate Saddle',
												'saddle'
											) }
										>
											<span
												className="pp-nav__icon"
												aria-hidden="true"
											>
												<StarIcon />
											</span>
											<span className="pp-nav__label">
												{ __(
													'Rate Saddle',
													'saddle'
												) }
											</span>
										</a>
									) }
									{ saddleData.version && (
										<span className="saddle-rail-version">
											{ `Saddle v${ saddleData.version }` }
										</span>
									) }
								</>
							}
						/>
					}
				>
					<TopBar
						tab={ tab }
						tier={ tier }
						paused={ paused }
						onNavigate={ setTab }
					/>
					<AppContent width={ PAGE_WIDTH }>
						{ ! wizardOpen && <ForeignNotices /> }

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
