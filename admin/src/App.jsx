/**
 * Saddle admin — a guided, person-first workspace.
 *
 * First run shows a short setup that flows straight into connecting the first
 * app. After that, four plain-language sections: Home (status + next step),
 * Permissions (what the AI can do), Guidance (what it's told), and Connect
 * (the apps). Connecting an app is a focused, full-panel wizard — one step at
 * a time — not a page of forms. All the protocol machinery stays out of sight.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api } from './api';
import TopBar from './components/TopBar';
import Onboarding from './components/Onboarding';
import Home from './components/Home';
import Permissions from './components/Permissions';
import Guidance from './components/Guidance';
import Apps from './components/ConnectedClients';
import ConnectWizard from './components/ConnectWizard';

const TABS = [
	{ name: 'home', title: __( 'Home', 'saddle' ) },
	{ name: 'permissions', title: __( 'Permissions', 'saddle' ) },
	{ name: 'guidance', title: __( 'Guidance', 'saddle' ) },
	{ name: 'connect', title: __( 'Connect', 'saddle' ) },
];

// The URL hash is the single source of truth for the active section, so a
// reload keeps you on the same page and the browser back button works
// between sections.
const tabFromHash = () => {
	const h = window.location.hash.replace( '#', '' );
	return TABS.some( ( t ) => t.name === h ) ? h : 'home';
};

export default function App() {
	const [ tier, setTier ] = useState( null );
	const [ tiers, setTiers ] = useState( [ 'read', 'write', 'admin' ] );
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
				if ( res.tiers ) {
					setTiers( res.tiers );
				}
			} ),
		[]
	);

	const loadClients = useCallback(
		() =>
			api( 'clients' )
				.then( ( res ) => setClients( res.clients || [] ) )
				.catch( () => {} ),
		[]
	);

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
		loadClients();
	};

	const finishOnboarding = ( { connect } = {} ) => {
		setOnboarded( true );
		api( 'settings', { method: 'POST', data: { onboarded: true } } ).catch(
			() => {}
		);
		if ( connect ) {
			openWizard();
		} else {
			setTab( 'home' );
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

	if ( loading ) {
		return (
			<div className="saddle-app saddle-app--loading">
				<Spinner />
			</div>
		);
	}

	if ( ! onboarded ) {
		return (
			<div className="saddle-app saddle-app--setup">
				<Onboarding
					tier={ tier }
					onTierSaved={ setTier }
					onFinish={ finishOnboarding }
				/>
			</div>
		);
	}

	return (
		<div className="saddle-app">
			<TopBar
				tier={ tier }
				tabs={ wizardOpen ? null : TABS }
				active={ tab }
				onSelect={ setTab }
				paused={ paused }
				onTogglePause={ togglePause }
				pausing={ pausing }
			/>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ domainWarning && ! wizardOpen && (
				<Notice
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __(
								'This is expected — clear this warning',
								'saddle'
							),
							onClick: clearDomainWarning,
						},
					] }
				>
					{ __(
						'This site’s address has changed since AI write access was turned on — often a sign of a staging clone or a migration carrying over live credentials. If that wasn’t intentional, review your connected apps and revoke anything unexpected.',
						'saddle'
					) }
				</Notice>
			) }

			<div className="saddle-panel">
				{ wizardOpen ? (
					<ConnectWizard
						tier={ tier }
						onExit={ closeWizard }
						onClientsChanged={ loadClients }
					/>
				) : (
					<div className="saddle-tabpane" key={ tab }>
						{ tab === 'home' && (
							<Home
								tier={ tier }
								clients={ clients }
								onNavigate={ setTab }
								onConnect={ openWizard }
								paused={ paused }
								onResume={ togglePause }
							/>
						) }
						{ tab === 'permissions' && (
							<Permissions
								caps={ caps }
								tiers={ tiers }
								savedTier={ tier }
								onTierSaved={ handleTierSaved }
								onCapsChanged={ loadCaps }
							/>
						) }
						{ tab === 'guidance' && <Guidance /> }
						{ tab === 'connect' && (
							<Apps
								clients={ clients }
								loading={ false }
								onConnect={ openWizard }
								onClientsChanged={ loadClients }
							/>
						) }
					</div>
				) }
			</div>
		</div>
	);
}
