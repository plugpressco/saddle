/**
 * What each Saddle page shows, tab by tab (#274).
 *
 * Five pages (#285, #291):
 *
 * - Dashboard: Overview · Activity
 * - AI apps: the connected apps, each with its own access, and Connect an app
 * - Context: what every app knows — instructions as named fields, skills,
 *   memory, and what Saddle tells every app automatically
 * - Services: Accounts, Plugins and Add-ons, each row opening a drawer
 * - Settings: one page, in sections
 *
 * A module's page shows the screen its own bundle registered for the tab
 * (`saddle.admin.screens`), or mounts its app into a slot
 * (`saddle.admin.mount`).
 */
import { useMemo, useEffect, useRef } from '@wordpress/element';
import { doAction } from '@wordpress/hooks';
import { Button, Drawer, Notice } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from './api';
import { findArea, withArg } from './routes';
import Dashboard from './components/Dashboard';
import Context from './components/Guidance';
import ConnectApps from './components/ConnectApps';
import Apps from './components/ConnectedClients';
import Activity from './components/Activity';
import Services from './components/Services';
import SettingsForm from './components/SettingsForm';
import SettingsPage from './components/SettingsPage';
import { useOauthSettings } from './components/SignInCard';
import ConnectWizard from './components/ConnectWizard';
import SectionHeader from './components/SectionHeader';
import {
	collectCards,
	collectSettingsCards,
	collectScreens,
	collectTabs,
	kit,
	ui,
	SHELL_VERSION,
} from './extensions';

/**
 * Cards another plugin added to this spot.
 *
 * @param {Object} props
 * @param {string} props.where    `home` or `connections`.
 * @param {*}      props.navigate Navigation helper handed to each card.
 */
function Cards( { where, navigate } ) {
	const cards = useMemo( () => collectCards( where ), [ where ] );
	return cards.map( ( c ) => (
		<c.Component
			key={ c.id }
			ui={ ui }
			kit={ kit }
			navigate={ navigate }
			shellVersion={ SHELL_VERSION }
		/>
	) );
}

/**
 * The element a `mount` module draws its own app into. React renders the
 * empty element and never touches what goes inside it.
 *
 * @param {Object} props
 * @param {string} props.module Module key.
 * @param {string} props.tab    Tab key.
 */
function MountSlot( { module, tab } ) {
	const ref = useRef( null );
	useEffect( () => {
		if ( ref.current ) {
			doAction( 'saddle.admin.mount', ref.current, { module, tab } );
		}
	}, [ module, tab ] );
	return <div id="saddle-module-slot" ref={ ref } />;
}

/**
 * A module's content for one tab.
 *
 * @param {Object}   props
 * @param {Object}   props.area     The module's page.
 * @param {string}   props.tab      The tab.
 * @param {Function} props.navigate Navigation helper.
 */
function ModuleScreen( { area, tab, navigate } ) {
	const screens = useMemo( collectScreens, [] );

	if ( area.content === 'mount' ) {
		return <MountSlot module={ area.key } tab={ tab } />;
	}

	const entry = screens.find(
		( s ) => s.module === area.key && s.tab === tab
	);
	if ( ! entry && 'settings' === tab ) {
		// Core appends a Settings tab to a module that has settings; when the
		// module drew nothing there, the schema draws it.
		return <SettingsForm scope={ area.key } />;
	}
	if ( ! entry ) {
		return (
			<Notice tone="warning">
				{ sprintf(
					/* translators: %s: module name, such as Saddle Analytics. */
					__(
						'%s did not add this screen. Updating it may add it.',
						'saddle'
					),
					area.product || area.title
				) }
			</Notice>
		);
	}

	return (
		<entry.Component
			ui={ ui }
			kit={ kit }
			module={ area.key }
			tab={ tab }
			navigate={ navigate }
			api={ api }
			shellVersion={ SHELL_VERSION }
		/>
	);
}

/**
 * AI apps: the connected apps, each with its own access, and the Connect an
 * app drawer that the header button opens.
 *
 * @param {Object}   props
 * @param {Function} props.openConnect    Opens the Connect drawer.
 * @param {Function} props.closeConnect   Closes it.
 * @param {boolean}  props.connectOpen    Whether it is open.
 * @param {?string}  props.connectApp     The app it opens on.
 * @param {Function} props.openWizard     Opens the key setup.
 * @param {Function} props.refreshClients Reloads the connected apps.
 * @param {Function} props.removeClient   Drops a revoked app from the list.
 * @param {Array}    props.clients        Connected apps (keys).
 * @param {string}   props.tier           The site's level.
 * @param {*}        props.navigate       Navigation helper.
 */
function AppsTab( {
	openConnect,
	closeConnect,
	connectOpen,
	connectApp,
	openWizard,
	refreshClients,
	removeClient,
	clients,
	tier,
	navigate,
} ) {
	const signIn = useOauthSettings();

	return (
		<>
			<Apps
				clients={ clients }
				onClientsChanged={ refreshClients }
				onClientRemoved={ removeClient }
				siteTier={ tier }
				onConnect={ () => openConnect() }
			/>
			<Cards where="connections" navigate={ navigate } />
			<Drawer
				open={ connectOpen }
				onOpenChange={ ( open ) => ! open && closeConnect() }
				title={ __( 'Connect an app', 'saddle' ) }
				size="md"
			>
				<ConnectApps
					oauth={ signIn.oauth }
					onKey={ ( app ) => {
						closeConnect();
						openWizard( app );
					} }
					onConnected={ refreshClients }
					initialApp={ connectApp }
				/>
			</Drawer>
		</>
	);
}

/**
 * Settings: Safety and Advanced (SettingsPage), then whatever other
 * plugins add (a licence, say), each as a section of its own, and a plain link
 * to run setup again at the end.
 *
 * @param {Object}   props
 * @param {Array}    props.extTabs            Sections contributed as v1 tabs.
 * @param {Array}    props.caps               The tools.
 * @param {Function} props.loadCaps           Reloads the tools.
 * @param {Function} props.onRehearsalChanged Tells the frame about practice mode.
 */
function SettingsScreen( { extTabs, caps, loadCaps, onRehearsalChanged } ) {
	// Collected at mount: addon bundles registered before the app mounted.
	const cards = useMemo( collectSettingsCards, [] );
	const home = findArea( saddleData.areas, 'home' );

	return (
		<>
			<SettingsPage
				caps={ caps }
				loadCaps={ loadCaps }
				onRehearsalChanged={ onRehearsalChanged }
			>
				{ cards.map( ( card ) => (
					<section key={ card.id } className="saddle-stack">
						{ ( card.title || card.label ) && (
							<SectionHeader title={ card.title || card.label } />
						) }
						<card.Component
							ui={ ui }
							shellVersion={ SHELL_VERSION }
						/>
					</section>
				) ) }
				{ extTabs.map( ( t ) => (
					<section key={ t.id } className="saddle-stack">
						<SectionHeader title={ t.label } />
						<t.Component ui={ ui } shellVersion={ SHELL_VERSION } />
					</section>
				) ) }
			</SettingsPage>
			{ home && (
				<div>
					<Button
						href={ withArg( home.url, 'setup', '1' ) }
						variant="link"
						size="sm"
					>
						{ __( 'Run setup again', 'saddle' ) }
					</Button>
				</div>
			) }
		</>
	);
}

/**
 * The content of one tab of one page.
 *
 * @param {Object} props See App.jsx for each prop; they are the app's state.
 */
export default function Screen( props ) {
	const {
		area,
		tab,
		navigate,
		tier,
		caps,
		clients,
		paused,
		wizardOpen,
		wizardApp,
		openWizard,
		closeWizard,
		refreshClients,
		removeClient,
		connectOpen,
		connectApp,
		openConnect,
		closeConnect,
		pausing,
		onTogglePause,
		loadCaps,
		onRehearsalChanged,
		onboarding,
		onHideSetup,
	} = props;
	const extTabs = useMemo( collectTabs, [] );

	if ( area.module ) {
		return <ModuleScreen area={ area } tab={ tab } navigate={ navigate } />;
	}

	switch ( `${ area.key }/${ tab }` ) {
		case 'home/overview':
			return (
				<>
					<Dashboard
						tier={ tier }
						clients={ clients }
						paused={ paused }
						pausing={ pausing }
						onTogglePause={ onTogglePause }
						caps={ caps }
						onNavigate={ navigate }
						onConnect={ () => openConnect() }
						onboarding={ onboarding }
						onHideSetup={ onHideSetup }
						homeUrl={ area.url }
					/>
					<Cards where="home" navigate={ navigate } />
				</>
			);

		case 'home/activity':
			return <Activity caps={ caps } />;

		case 'connections/apps':
			return wizardOpen ? (
				<ConnectWizard
					tier={ tier }
					clients={ clients }
					onExit={ closeWizard }
					onClientsChanged={ refreshClients }
					initialApp={ wizardApp }
				/>
			) : (
				<AppsTab
					openConnect={ openConnect }
					closeConnect={ closeConnect }
					connectOpen={ connectOpen }
					connectApp={ connectApp }
					openWizard={ openWizard }
					refreshClients={ refreshClients }
					removeClient={ removeClient }
					clients={ clients }
					tier={ tier }
					navigate={ navigate }
				/>
			);

		case 'settings/general':
			return (
				<SettingsScreen
					extTabs={ extTabs }
					caps={ caps }
					loadCaps={ loadCaps }
					onRehearsalChanged={ onRehearsalChanged }
				/>
			);

		case 'services/overview':
			return <Services />;

		case 'context/overview':
			return <Context />;
	}

	return null;
}
