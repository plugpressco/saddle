/**
 * What each Saddle page shows, tab by tab (#274).
 *
 * Eight in-app screens became four pages:
 *
 * - Home: Overview (the old Dashboard) · Activity
 * - Connections: Apps (connect an app, and the connected ones) · Permissions
 *   (with Integrations as its section for third-party tools)
 * - Context: what every app knows — instructions as named fields, skills,
 *   memory, and what Saddle tells every app automatically
 * - Settings: one page, in sections
 *
 * A module's page shows the screen its own bundle registered for the tab
 * (`saddle.admin.screens`), or mounts its app into a slot
 * (`saddle.admin.mount`).
 */
import { useMemo, useEffect, useRef } from '@wordpress/element';
import { doAction } from '@wordpress/hooks';
import { Button, Notice, Row, RowList } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from './api';
import { findArea, withArg } from './routes';
import Dashboard from './components/Dashboard';
import Permissions from './components/Permissions';
import Context from './components/Guidance';
import ConnectApps from './components/ConnectApps';
import Apps, { ConnectionDetails } from './components/ConnectedClients';
import Integrations from './components/Integrations';
import Activity from './components/Activity';
import SettingsForm from './components/SettingsForm';
import SignInCard, { useOauthSettings } from './components/SignInCard';
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
 * Connections → Apps: the app picker, the connected apps, the one sign-in
 * control they share, and the troubleshooting details, collapsed, last.
 *
 * @param {Object}   props
 * @param {Function} props.openWizard     Opens the key setup.
 * @param {Function} props.refreshClients Reloads the connected apps.
 * @param {Function} props.removeClient   Drops a revoked app from the list.
 * @param {Array}    props.clients        Connected apps.
 * @param {string}   props.tier           The site's level.
 * @param {*}        props.navigate       Navigation helper.
 */
function AppsTab( {
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
			<ConnectApps
				oauth={ signIn.oauth }
				onKey={ openWizard }
				onConnected={ refreshClients }
			/>
			<Apps
				clients={ clients }
				loading={ false }
				onClientsChanged={ refreshClients }
				onClientRemoved={ removeClient }
				siteTier={ tier }
			/>
			<SignInCard { ...signIn } />
			<Cards where="connections" navigate={ navigate } />
			<ConnectionDetails />
		</>
	);
}

/**
 * Settings: Saddle's own settings by section, a Setup section, then whatever
 * other plugins add (a licence, say), each as a section of its own.
 *
 * @param {Object} props
 * @param {Array}  props.extTabs Sections contributed as v1 tabs.
 */
function SettingsScreen( { extTabs } ) {
	// Collected at mount: addon bundles registered before the app mounted.
	const cards = useMemo( collectSettingsCards, [] );
	const home = findArea( saddleData.areas, 'home' );

	return (
		<>
			<SettingsForm scope="saddle" screen="settings/general" />
			{ home && (
				<section className="saddle-stack">
					<SectionHeader title={ __( 'Setup', 'saddle' ) } />
					<RowList>
						<Row
							title={ __( 'Run setup again', 'saddle' ) }
							actions={
								<Button
									href={ withArg( home.url, 'setup', '1' ) }
									variant="link"
									size="sm"
								>
									{ __( 'Start', 'saddle' ) }
								</Button>
							}
						/>
					</RowList>
				</section>
			) }
			{ cards.map( ( card ) => (
				<section key={ card.id } className="saddle-stack">
					{ ( card.title || card.label ) && (
						<SectionHeader title={ card.title || card.label } />
					) }
					<card.Component ui={ ui } shellVersion={ SHELL_VERSION } />
				</section>
			) ) }
			{ extTabs.map( ( t ) => (
				<section key={ t.id } className="saddle-stack">
					<SectionHeader title={ t.label } />
					<t.Component ui={ ui } shellVersion={ SHELL_VERSION } />
				</section>
			) ) }
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
		pausing,
		wizardOpen,
		wizardApp,
		openWizard,
		closeWizard,
		refreshClients,
		removeClient,
		loadCaps,
		onTierSaved,
		onRehearsalChanged,
		onTogglePause,
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
						caps={ caps }
						onNavigate={ navigate }
						onConnect={ () => openWizard() }
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
					openWizard={ openWizard }
					refreshClients={ refreshClients }
					removeClient={ removeClient }
					clients={ clients }
					tier={ tier }
					navigate={ navigate }
				/>
			);

		case 'connections/permissions':
			return (
				<>
					<Permissions
						caps={ caps }
						savedTier={ tier }
						onTierSaved={ onTierSaved }
						onCapsChanged={ loadCaps }
						onRehearsalChanged={ onRehearsalChanged }
						paused={ paused }
						pausing={ pausing }
						onTogglePause={ onTogglePause }
					/>
					<Integrations caps={ caps } onChanged={ loadCaps } />
				</>
			);

		case 'settings/general':
			return <SettingsScreen extTabs={ extTabs } />;

		case 'context/overview':
			return <Context />;
	}

	return null;
}
