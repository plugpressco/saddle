/**
 * Saddle admin: one app, mounted on each Saddle page (#274).
 *
 * Every page is a real wp-admin submenu page under the Saddle menu: Home, the
 * installed modules, Connections and Settings. The server says which page and
 * tab this is (`saddleData.area`, `saddleData.tab`) and lists every page
 * (`saddleData.areas`). Frame draws the header, the safety pill and the tabs;
 * screens.jsx draws the content. Switching tabs updates `&tab=` in place, so a
 * reload or a shared link lands on the same tab and Back steps between tabs.
 * Switching pages is an ordinary WordPress page load.
 *
 * First run (#269, #277) replaces Home until the owner finishes or skips it,
 * and `&setup=1` opens it again from Settings. Its state is the onboarding
 * record (`GET /onboarding`), not a flag.
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
	ConfirmProvider,
	Toaster,
	toast,
	Spinner,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from './api';
import FirstRun from './components/FirstRun';
import Tour from './components/Tour';
import AuthTrouble from './components/AuthTrouble';
import Frame from './components/Frame';
import { pickSlot } from './notices';
import Screen from './screens';
import { showFirstRun, tourDue } from './onboarding-logic';
import {
	areaUrl,
	findArea,
	legacyUrl,
	placeFor,
	resolveTab,
	withArg,
} from './routes';

const AREAS = saddleData.areas || [];

// The page this request is for. A build that predates the page list (or a
// broken one) still gets a working Home rather than a blank screen.
const CURRENT = findArea( AREAS, saddleData.area ) ||
	findArea( AREAS, 'home' ) || {
		key: 'home',
		title: __( 'Dashboard', 'saddle' ),
		url: window.location.href,
		tabs: [ { key: 'overview', label: __( 'Overview', 'saddle' ) } ],
	};

// `&setup=1` (Settings → Run setup again, and Home's Setup block) opens first
// run even when it is finished. `&step=try&app=claude` opens it on that step.
const setupParams = () => new URLSearchParams( window.location.search );

// The notices Core sent for this page and tab (Saddle_Notices), most severe
// first.
const SERVER_NOTICES = Array.isArray( saddleData.notices )
	? saddleData.notices
	: [];

const wantsWizard = () =>
	CURRENT.key === 'connections' &&
	new URLSearchParams( window.location.search ).has( 'add' );

export default function App() {
	const area = CURRENT;

	// An old `page=saddle#connect` address: go where it lives now, once,
	// before drawing anything.
	const redirect = useMemo(
		() =>
			area.key === 'home'
				? legacyUrl( AREAS, window.location.hash )
				: null,
		[ area ]
	);
	useEffect( () => {
		if ( redirect ) {
			window.location.replace( redirect );
		}
	}, [ redirect ] );

	const [ tab, setTabState ] = useState( () =>
		resolveTab( area, saddleData.tab )
	);
	const [ tier, setTier ] = useState( null );
	const [ caps, setCaps ] = useState( [] );
	const [ clients, setClients ] = useState( [] );
	// The onboarding record, or null until it arrives (or if its route failed,
	// in which case the old `onboarded` answer from /preferences stands in).
	const [ onboarding, setOnboarding ] = useState( null );
	const [ onboarded, setOnboarded ] = useState( true );
	const [ setupForced, setSetupForced ] = useState(
		() => CURRENT.key === 'home' && setupParams().has( 'setup' )
	);
	const [ tourDismissed, setTourDismissed ] = useState( false );
	// Set when first run ends here, so a slow answer to an earlier step can
	// never put it back on screen.
	const finishedHere = useRef( false );
	const [ paused, setPaused ] = useState( false );
	const [ rehearsal, setRehearsal ] = useState( false );
	const [ pausing, setPausing ] = useState( false );
	const [ domainWarning, setDomainWarning ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ serverNotices, setServerNotices ] = useState( SERVER_NOTICES );
	// A 401 means WordPress resolved nobody at all — the session never arrived,
	// rather than arriving without permission. Nothing on this screen can load,
	// so it gets its own view instead of an error strip over an empty frame.
	const [ authError, setAuthError ] = useState( false );
	const [ wizardOpen, setWizardOpen ] = useState( wantsWizard );
	// The app a key is being made for, when Connections → Apps sent the
	// owner to the key setup for one app.
	const [ wizardApp, setWizardApp ] = useState( null );

	const setTab = useCallback(
		( next ) => {
			const resolved = resolveTab( area, next );
			setTabState( resolved );
			setWizardOpen( false );
			const url = areaUrl( AREAS, area.key, resolved );
			if ( url && window.history && window.history.pushState ) {
				window.history.pushState( {}, '', url );
			}
		},
		[ area ]
	);

	// Back and Forward between tabs of this page.
	useEffect( () => {
		const onPop = () => {
			const params = new URLSearchParams( window.location.search );
			setTabState( resolveTab( area, params.get( 'tab' ) || '' ) );
			setWizardOpen( wantsWizard() );
		};
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ area ] );

	// Go somewhere a page component asked for: a tab here, or another page.
	// Components still speak the old section names ('connect', 'activity');
	// routes.js maps them.
	const navigate = useCallback(
		( target ) => {
			const place = placeFor( target );
			if ( ! place ) {
				return;
			}
			if ( place.area === area.key ) {
				setTab( place.tab );
				return;
			}
			const url = areaUrl( AREAS, place.area, place.tab );
			if ( url ) {
				window.location.assign( url );
			}
		},
		[ area, setTab ]
	);

	// One onboarding event to the server; the answer is the new state.
	const sendOnboarding = useCallback(
		( event ) =>
			api( 'onboarding', { method: 'POST', data: event } )
				.then( ( res ) => {
					const open = [ 'new', 'active' ].includes(
						res.first_run.state
					);
					if ( ! ( finishedHere.current && open ) ) {
						setOnboarding( res );
					}
				} )
				.catch( () => {} ),
		[]
	);

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
		if ( redirect ) {
			return;
		}

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
					url.pathname + url.search + url.hash
				);
			}
		}

		Promise.all( [
			loadCaps(),
			loadClients(),
			api( 'onboarding' )
				.then( setOnboarding )
				.catch( () => {} ),
			api( 'preferences' ).then( ( res ) => {
				setOnboarded( !! res.onboarded );
				setPaused( !! res.paused );
				setRehearsal( !! res.rehearsal );
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
	}, [ redirect, loadCaps, loadClients ] );

	// An anchor in the address (`#memory`) points into content that renders
	// after the data arrives, and the sections above it load their own data
	// and grow. So the jump is repeated as the page settles.
	useEffect( () => {
		const id = window.location.hash.replace( '#', '' );
		if ( loading || ! id ) {
			return;
		}
		const timers = [ 0, 400, 1200 ].map( ( delay ) =>
			setTimeout( () => {
				const el = document.getElementById( id );
				if ( el ) {
					el.scrollIntoView();
				}
			}, delay )
		);
		return () => timers.forEach( clearTimeout );
	}, [ loading ] );

	const activityLabel =
		( ( findArea( AREAS, 'home' ) || {} ).tabs || [] ).reduce(
			( found, t ) => ( 'activity' === t.key ? t.label : found ),
			''
		) || __( 'Activity', 'saddle' );

	const connectUrl = withArg(
		areaUrl( AREAS, 'connections', 'apps' ) || window.location.href,
		'add',
		'1'
	);

	const openWizard = ( appKey = null ) => {
		if ( area.key !== 'connections' ) {
			window.location.assign( connectUrl );
			return;
		}
		setTabState( 'apps' );
		setWizardApp( appKey );
		setWizardOpen( true );
		if ( window.history && window.history.pushState ) {
			window.history.pushState( {}, '', connectUrl );
		}
	};

	const closeWizard = () => {
		setWizardOpen( false );
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState(
				{},
				'',
				withArg( connectUrl, 'add', null )
			);
		}
		refreshClients();
	};

	// First run is over (finished or skipped): the events were already sent.
	// Show Home at once, and drop `&setup=1` so a reload does not reopen it.
	const finishOnboarding = () => {
		finishedHere.current = true;
		setOnboarded( true );
		setSetupForced( false );
		setOnboarding( ( prev ) =>
			prev && [ 'new', 'active' ].includes( prev.first_run.state )
				? {
						...prev,
						first_run: { ...prev.first_run, state: 'skipped' },
				  }
				: prev
		);
		if ( window.history && window.history.replaceState ) {
			let url = window.location.href;
			[ 'setup', 'step', 'app' ].forEach( ( name ) => {
				url = withArg( url, name, null );
			} );
			window.history.replaceState( {}, '', url );
		}
		setTab( 'overview' );
	};

	const hideSetup = () =>
		sendOnboarding( { event: 'setup.hide', module: 'home' } );

	const finishTour = () => {
		setTourDismissed( true );
		sendOnboarding( { event: 'tour.done' } );
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

	// Dismiss a server notice: gone at once, and back with a toast if the
	// server could not keep the dismissal.
	const dismissNotice = useCallback( ( notice ) => {
		setServerNotices( ( prev ) =>
			prev.filter( ( n ) => n.id !== notice.id )
		);
		api( `notices/${ encodeURIComponent( notice.id ) }/dismiss`, {
			method: 'POST',
		} ).catch( ( e ) => {
			setServerNotices( ( prev ) => [ ...prev, notice ] );
			toast.error( e.message );
		} );
	}, [] );

	// One slot under the header for the most severe notice; the rest go behind
	// the bell. The app's own notices come first so they win a tie: they are
	// about what is happening now.
	const { slot: slotNotice, rest: bellNotices } = pickSlot( [
		...( error
			? [ { id: 'app-error', severity: 'error', message: error } ]
			: [] ),
		...( domainWarning && ! wizardOpen
			? [
					{
						id: 'domain-drift',
						severity: 'warning',
						message: __(
							'This site’s address has changed since AI write access was turned on — often a sign of a staging clone or a migration carrying over live credentials. If that wasn’t intentional, review your connected apps and revoke anything unexpected.',
							'saddle'
						),
						action: {
							label: __(
								'This is expected — clear this warning',
								'saddle'
							),
							onClick: clearDomainWarning,
						},
					},
			  ]
			: [] ),
		...serverNotices,
	] );

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
	let view = null;

	// First run shows while it is unfinished (or asked for again). Without the
	// onboarding record the old flag decides.
	const firstRunOpen = onboarding
		? showFirstRun( onboarding.first_run, setupForced )
		: ! onboarded || setupForced;
	// Asked for again on a finished site, it starts from the top unless the
	// address names a step (Home's Setup block sends `step=try&app=…`).
	const firstRunStart = ( () => {
		const base = onboarding
			? onboarding.first_run
			: { state: 'new', step: '', app: '' };
		const params = setupParams();
		if (
			setupForced &&
			'try' === params.get( 'step' ) &&
			params.get( 'app' )
		) {
			return { ...base, step: 'try', app: params.get( 'app' ) };
		}
		return base;
	} )();

	if ( redirect ) {
		view = null;
	} else if ( loading ) {
		view = (
			<div className="pp-app saddle-app saddle-app--loading">
				<Spinner />
			</div>
		);
	} else if ( authError ) {
		view = <AuthTrouble onRetry={ () => window.location.reload() } />;
	} else if ( area.key === 'home' && firstRunOpen ) {
		// First run sits in the same frame as every page, without tabs. Every
		// notice waits behind the bell so nothing competes with the steps.
		view = (
			<Frame
				area={ area }
				tab="setup"
				showTabs={ false }
				crumb={ __( 'Setup', 'saddle' ) }
				status={ {
					tier,
					paused,
					rehearsal,
					href: areaUrl( AREAS, 'permissions' ),
				} }
				moreNotices={ [ slotNotice, ...bellNotices ].filter( Boolean ) }
				onDismissNotice={ dismissNotice }
			>
				<div className="saddle-app--setup">
					<FirstRun
						tier={ tier }
						clients={ clients }
						firstRun={ firstRunStart }
						send={ sendOnboarding }
						onTierSaved={ setTier }
						onClientsChanged={ refreshClients }
						onFinish={ finishOnboarding }
					/>
				</div>
			</Frame>
		);
	} else {
		view = (
			<Frame
				area={ area }
				tab={ tab }
				onTab={ setTab }
				status={ {
					tier,
					paused,
					rehearsal,
					href: areaUrl( AREAS, 'permissions' ),
				} }
				notices={ ! wizardOpen }
				notice={ slotNotice }
				moreNotices={ bellNotices }
				onDismissNotice={ dismissNotice }
			>
				<div
					className="saddle-tabpane"
					key={ `${ area.key }/${ tab }${
						wizardOpen ? '/add' : ''
					}` }
				>
					<Screen
						area={ area }
						tab={ tab }
						navigate={ navigate }
						tier={ tier }
						caps={ caps }
						clients={ clients }
						paused={ paused }
						pausing={ pausing }
						wizardOpen={ wizardOpen }
						wizardApp={ wizardApp }
						openWizard={ openWizard }
						closeWizard={ closeWizard }
						refreshClients={ refreshClients }
						removeClient={ removeClient }
						loadCaps={ loadCaps }
						onTierSaved={ handleTierSaved }
						onRehearsalChanged={ setRehearsal }
						onTogglePause={ togglePause }
						onboarding={ onboarding }
						onHideSetup={ hideSetup }
					/>
				</div>
				{ ! wizardOpen &&
					area.key === 'home' &&
					'overview' === tab &&
					! tourDismissed &&
					tourDue( onboarding ) && (
						<Tour
							activityLabel={ activityLabel }
							onFinish={ finishTour }
						/>
					) }
			</Frame>
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
