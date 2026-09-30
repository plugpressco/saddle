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
 * - Settings: General
 *
 * A module's page shows the screen its own bundle registered for the tab
 * (`saddle.admin.screens`), or mounts its app into a slot
 * (`saddle.admin.mount`).
 */
import { useMemo, useEffect, useRef } from '@wordpress/element';
import { doAction } from '@wordpress/hooks';
import { Notice } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from './api';
import Dashboard from './components/Dashboard';
import Permissions from './components/Permissions';
import Context from './components/Guidance';
import ConnectApps from './components/ConnectApps';
import Apps from './components/ConnectedClients';
import Integrations from './components/Integrations';
import Activity from './components/Activity';
import Settings from './components/Settings';
import ConnectWizard from './components/ConnectWizard';
import SectionHeader from './components/SectionHeader';
import {
	collectCards,
	collectScreens,
	collectTabs,
	kit,
	ui,
	SHELL_VERSION,
} from './extensions';

/**
 * The one sentence under a page's title: what this tab is for. Home's
 * Overview has none here, because its own first line is that sentence.
 *
 * @param {Object} area The page.
 * @param {string} tab  The tab.
 * @return {string|null} Sentence.
 */
export function describe( area, tab ) {
	switch ( `${ area.key }/${ tab }` ) {
		case 'home/activity':
			return __(
				'Everything your connected apps have changed through Saddle — and every attempt that was blocked. Reading is never logged; only changes are.',
				'saddle'
			);
		case 'connections/apps':
			return __( 'Apps you’ve let talk to this site.', 'saddle' );
		case 'connections/permissions':
			return __(
				'Pick how much your connected apps are allowed to do. You can change this whenever you like.',
				'saddle'
			);
		case 'context/overview':
			return __(
				'What Claude, ChatGPT and every connected app know about this site. They read it before they work, so correct anything that looks wrong.',
				'saddle'
			);
		case 'settings/general':
			return __(
				'How Saddle behaves on this site: the switches you rarely touch, and the facts about its connection.',
				'saddle'
			);
	}

	// A module's first tab says what the module is for.
	return area.module && area.tabs[ 0 ] && area.tabs[ 0 ].key === tab
		? area.summary || null
		: null;
}

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
						onNavigate={ navigate }
						onConnect={ () => openWizard() }
					/>
					<Cards where="home" navigate={ navigate } />
				</>
			);

		case 'home/activity':
			return <Activity />;

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
				<>
					<ConnectApps
						onKey={ openWizard }
						onConnected={ refreshClients }
					/>
					<Apps
						clients={ clients }
						loading={ false }
						// The app picker is right above the list on this page.
						onConnect={ () => {
							const picker =
								document.getElementById( 'saddle-connect' );
							if ( picker ) {
								picker.scrollIntoView();
							}
						} }
						onClientsChanged={ refreshClients }
						onClientRemoved={ removeClient }
						siteTier={ tier }
					/>
					<Cards where="connections" navigate={ navigate } />
				</>
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
					/>
					<Integrations caps={ caps } onChanged={ loadCaps } />
				</>
			);

		case 'settings/general':
			return (
				<>
					<Settings
						paused={ paused }
						pausing={ pausing }
						onTogglePause={ onTogglePause }
					/>
					{ extTabs.map( ( t ) => (
						<section key={ t.id } className="saddle-stack">
							<SectionHeader title={ t.label } />
							<t.Component
								ui={ ui }
								shellVersion={ SHELL_VERSION }
							/>
						</section>
					) ) }
				</>
			);

		case 'context/overview':
			return <Context />;
	}

	return null;
}
