/**
 * Saddle admin: one app, mounted on each Saddle page (#274).
 *
 * Every page is a real wp-admin submenu page under the Saddle menu: Home,
 * the installed modules, AI apps, Services, Context and Settings. The server
 * says which page, tab and page inside the tab this is (`saddleData.area`,
 * `.tab`, `.sub`) and lists every page (`saddleData.areas`). Frame draws the
 * header, the AI on / Paused pill, a module's sidebar and its icon tab row;
 * screens.jsx draws the content. Switching a tab (`&tab=`), a page inside it
 * (`&sub=`) or an item (`&view=`) updates the address in place, so a reload
 * or a shared link lands on the same screen and Back steps between them.
 * Switching pages is an ordinary WordPress page load.
 *
 * First run (#269, #277) replaces the Dashboard until the owner finishes or skips it,
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
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from './api';
import FirstRun from './components/FirstRun';
import Tour from './components/Tour';
import AuthTrouble from './components/AuthTrouble';
import Frame from './components/Frame';
import { pickSlot } from './notices';
import { canonicalSearch, drillHeader } from './frame-logic';
import Screen, { ScreenSkeleton } from './screens';
import { showFirstRun, tourDue } from './onboarding-logic';
import {
	areaUrl,
	findArea,
	legacyUrl,
	readRoute,
	routeUrl,
	placeFor,
	resolveSub,
	resolveTab,
	withArg,
} from './routes';

const AREAS = saddleData.areas || [];

// The page this request is for. A build that predates the page list (or a
// broken one) still gets a working Home rather than a blank screen.
const CURRENT = findArea( AREAS, saddleData.area ) ||
	findArea( AREAS, 'home' ) || {
		key: 'home',
		title: __( 'Overview', 'saddle' ),
		url: window.location.href,
		tabs: [ { key: 'overview', label: __( 'Overview', 'saddle' ) } ],
	};

// `&setup=1` (Settings → Run setup again, and the Dashboard's Setup block) opens first
// run even when it is finished. `&step=try&app=claude` opens it on that step.
const setupParams = () => new URLSearchParams( window.location.search );

// The notices Core sent for this page and tab (Saddle_Notices), most severe
// first.
const SERVER_NOTICES = Array.isArray( saddleData.notices )
	? saddleData.notices
	: [];

// Agent setup: `&add=1` brings its apps into view, `&add=claude` opens that
// app's steps, and `&key=cursor` also makes a key for it at once.
const addParam = () =>
	CURRENT.key === 'connections'
		? new URLSearchParams( window.location.search ).get( 'add' )
		: null;
const keyParam = () =>
	CURRENT.key === 'connections'
		? new URLSearchParams( window.location.search ).get( 'key' )
		: null;
const appParam = ( value ) => ( value && '1' !== value ? value : null );

/**
 * What the address asks Agent setup to show (#369): an app's steps, and
 * whether to make a key for it at once.
 *
 * @return {?Object} `{ app, key, n }`, or null.
 */
const setupAsk = () => {
	const key = keyParam();
	if ( null !== key ) {
		return { app: appParam( key ), key: true, n: 0 };
	}
	const add = addParam();
	return null !== add ? { app: appParam( add ), key: false, n: 0 } : null;
};

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

	// Where we are: the tab, the page inside it, a view and the module's own
	// query arguments. Read from the address, not from the server's copy, so
	// a module that rewrote an old `#/…` link with replaceState before mount
	// lands on the right screen. A page without the argument falls back to
	// what the server resolved. An empty or unknown page is the tab's first.
	const [ route, setRoute ] = useState( () => {
		const here = readRoute( window.location.search );
		const first = resolveTab( area, here.tab || saddleData.tab );
		const serverSub =
			first === saddleData.tab && ! here.tab ? saddleData.sub : '';
		return {
			tab: first,
			sub: resolveSub( area, first, here.sub || serverSub || '' ),
			view: here.view,
			args: here.args,
		};
	} );
	const { tab, sub, view: routeView, args: routeArgs } = route;
	const setTabState = useCallback(
		( next ) =>
			setRoute( {
				tab: next,
				sub: resolveSub( area, next, '' ),
				view: '',
				args: {},
			} ),
		[ area ]
	);
	// A module screen's drill-in (K4): the title it set for this tab, page
	// and view. The header handed to the screen is bound to them, so the
	// title ends with the view; the screen's unmount clears it too.
	const [ drill, setDrill ] = useState( null );
	const header = useMemo(
		() => drillHeader( setDrill, tab, routeView, sub ),
		[ tab, routeView, sub ]
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
	const [ pausing, setPausing ] = useState( false );
	const [ domainWarning, setDomainWarning ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ serverNotices, setServerNotices ] = useState( SERVER_NOTICES );
	// A 401 means WordPress resolved nobody at all — the session never arrived,
	// rather than arriving without permission. Nothing on this screen can load,
	// so it gets its own view instead of an error strip over an empty frame.
	const [ authError, setAuthError ] = useState( false );
	// The app Agent setup shows the steps for, when something asked for one.
	const [ setupFocus, setSetupFocus ] = useState( setupAsk );

	const setTab = useCallback(
		( next ) => {
			const resolved = resolveTab( area, next );
			setTabState( resolved );
			const url = areaUrl( AREAS, area.key, resolved );
			if ( url && window.history && window.history.pushState ) {
				window.history.pushState( {}, '', url );
			}
		},
		[ area, setTabState ]
	);

	// A page inside a tab (`&sub=`), or a module's deep screen (`&view=` and
	// its own arguments), with no reload. The tab stays unless one is named;
	// the page stays unless one is named or the tab changes. A view and args
	// last only while named, so changing the page closes the view.
	const goRoute = useCallback(
		( next ) => {
			const resolved = resolveTab( area, next.tab || route.tab );
			let wanted = '';
			if ( 'sub' in next ) {
				wanted = next.sub || '';
			} else if ( resolved === route.tab ) {
				wanted = route.sub;
			}
			const target = {
				tab: resolved,
				sub: resolveSub( area, resolved, wanted ),
				view: next.view || '',
				args: next.args || {},
			};
			setRoute( target );
			const url = routeUrl( AREAS, area.key, target );
			if ( url && window.history && window.history.pushState ) {
				window.history.pushState( {}, '', url );
			}
		},
		[ area, route.tab, route.sub ]
	);

	// An address that named an unknown tab or page shows the first one; the
	// address drops the bad value too (P20), once, as the page opens.
	useEffect( () => {
		if ( redirect || ! window.history || ! window.history.replaceState ) {
			return;
		}
		const fixed = canonicalSearch( window.location.search, route );
		if ( null !== fixed ) {
			window.history.replaceState(
				window.history.state,
				'',
				window.location.pathname + fixed + window.location.hash
			);
		}
		// Only the address the page was opened with; every later move writes
		// a resolved address itself.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Back and Forward between tabs, pages and views of this page.
	useEffect( () => {
		const onPop = () => {
			const here = readRoute( window.location.search );
			const resolved = resolveTab( area, here.tab );
			setRoute( {
				tab: resolved,
				sub: resolveSub( area, resolved, here.sub ),
				view: here.view,
				args: here.args,
			} );
			setSetupFocus( setupAsk() );
		};
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ area ] );

	// Go somewhere a page component asked for: a tab, a page or a view here,
	// or another page. Components still speak the old section names
	// ('connect', 'activity'); routes.js maps them.
	const navigate = useCallback(
		( target ) => {
			const place = placeFor( target );
			if ( ! place ) {
				return;
			}
			if ( ! place.area || place.area === area.key ) {
				if ( 'view' in place ) {
					goRoute( place );
				} else {
					setTab( place.tab );
				}
				return;
			}
			const url = routeUrl( AREAS, place.area, place );
			if ( url ) {
				window.location.assign( url );
			}
		},
		[ area, setTab, goRoute ]
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

	const connectBase =
		areaUrl( AREAS, 'connections', 'apps' ) || window.location.href;

	// An app's steps on Agent setup, from any page: there it shows them at
	// once, anywhere else it goes there.
	const openConnect = ( appKey = null ) => {
		const url = withArg( connectBase, 'add', appKey || '1' );
		if ( area.key !== 'connections' ) {
			window.location.assign( url );
			return;
		}
		setTabState( 'apps' );
		setSetupFocus( { app: appKey, key: false, n: Date.now() } );
		if ( window.history && window.history.pushState ) {
			window.history.pushState( {}, '', url );
		}
	};

	// First run is over (finished or skipped): the events were already sent.
	// Show the Dashboard at once, and drop `&setup=1` so a reload does not reopen it.
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

	// The owner expected the address change: the server records this address
	// as the one the apps' access holds on, which clears the warning.
	const clearDomainWarning = () => {
		api( 'preferences', {
			method: 'POST',
			data: { confirm_domain: true },
		} )
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
		...( domainWarning
			? [
					{
						id: 'domain-drift',
						severity: 'warning',
						message: __(
							'This site’s address changed after AI write access was turned on, which can mean a copy or migration carried over live credentials. If you didn’t expect that, review your AI apps and disconnect any you don’t recognise.',
							'saddle'
						),
						action: {
							label: __( 'Mark as expected', 'saddle' ),
							onClick: clearDomainWarning,
						},
					},
			  ]
			: [] ),
		...serverNotices,
	] );

	// One provider mount for every state (loading / setup / main): tooltips,
	// the useConfirm dialog, and toasts are available everywhere, and portaled
	// overlays pick up tokens from the pp-scope body class.
	let view = null;

	// First run shows while it is unfinished (or asked for again). Without the
	// onboarding record the old flag decides.
	const firstRunOpen = onboarding
		? showFirstRun( onboarding.first_run, setupForced )
		: ! onboarded || setupForced;
	// The stored run, with the step the address names applied (Home's next
	// step sends `&step=try&app=…`). Where it opens is FirstRun's choice
	// (openingStep): a finished run asked for again starts at the top.
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
		// The frame's shape and the page's, not a lone spinner (P12).
		view = (
			<Frame
				area={ area }
				tab={ tab }
				sub={ sub }
				view={ routeView }
				onTab={ setTab }
				onSub={ ( next ) => goRoute( { sub: next } ) }
				loading
			>
				<ScreenSkeleton area={ area } tab={ tab } />
			</Frame>
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
				crumb={ __( 'Welcome', 'saddle' ) }
				status={ { paused, pausing, onToggle: togglePause } }
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
				sub={ sub }
				view={ routeView }
				drill={ drill }
				onTab={ setTab }
				onSub={ ( next ) => goRoute( { sub: next } ) }
				onBack={ () => goRoute( { sub } ) }
				status={ { paused, pausing, onToggle: togglePause } }
				notices
				notice={ slotNotice }
				moreNotices={ bellNotices }
				onDismissNotice={ dismissNotice }
			>
				<div
					className="saddle-tabpane"
					key={ `${ area.key }/${ tab }${
						routeView ? `/${ routeView }` : ''
					}` }
				>
					<Screen
						area={ area }
						tab={ tab }
						sub={ sub }
						view={ routeView }
						args={ routeArgs }
						navigate={ navigate }
						header={ header }
						tier={ tier }
						caps={ caps }
						clients={ clients }
						paused={ paused }
						pausing={ pausing }
						setupFocus={ setupFocus }
						onSetupFocus={ setSetupFocus }
						openConnect={ openConnect }
						refreshClients={ refreshClients }
						removeClient={ removeClient }
						onTogglePause={ togglePause }
						loadCaps={ loadCaps }
					/>
				</div>
				{ area.key === 'home' &&
					'overview' === tab &&
					! tourDismissed &&
					tourDue( onboarding ) && <Tour onFinish={ finishTour } /> }
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
