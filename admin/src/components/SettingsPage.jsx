/**
 * Settings — one page, no tabs (#285).
 *
 * - Safety: four switches that save the moment they are flipped. A switch
 *   moves at once, says "Saved." when the server agrees, and moves back if it
 *   doesn't (R9, P16).
 * - Accounts: outside services with a key, such as Unsplash (#369; the
 *   Services page until then).
 * - Usage data and news: the opt-in to PlugPress's usage data, and Saddle
 *   news (Saddle_Insight).
 * - Advanced: rarely used things, each collapsed — what each tool may do
 *   (Allow, Ask first or Off, #364), sign-in for apps, plugins that add
 *   tools (only when another developer's is installed), memory limits,
 *   recent changes, the connection check. `&section=signin` (Agent setup's
 *   "Turn it on") opens Sign-in for apps and scrolls to it;
 *   `&section=services` (the old Services page's address) brings Accounts
 *   into view and opens the plugins. The connection check runs only once
 *   opened: it calls the site back, which on a one-request-at-a-time server
 *   would hold the rest of the page.
 *
 * Who may do what is chosen per app on Agent setup, not here.
 */
import {
	useState,
	useEffect,
	useMemo,
	useRef,
	useCallback,
} from '@wordpress/element';
import {
	Badge,
	Button,
	Collapsible,
	HelpTip,
	Row,
	RowList,
	Select,
	Skeleton,
	Switch,
	Tooltip,
	toast,
} from '@plugpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { groupTools, modeMap, modesFor, withMode } from '../tools-list';
import { runnableTools, safetyPrefs } from '../settings-logic';
import SectionHeader from './SectionHeader';
import SettingsForm from './SettingsForm';
import SignInCard, { useOauthSettings } from './SignInCard';
import ConnectionHealth from './ConnectionHealth';
import McpDiagnostics from './McpDiagnostics';
import { Accounts, AddonSwitches, switchable, useServices } from './Services';
import InsightSettings from './InsightSettings';

// A label with its longer explanation behind a "?".
const Labelled = ( { children, help } ) => (
	<span className="saddle-settings__label">
		{ children }
		<HelpTip>{ help }</HelpTip>
	</span>
);

/**
 * Safety: drafts-only, rehearsal, the domain check and major WordPress
 * releases (#359), each saved on flip
 * through `POST /preferences`.
 *
 * @param {Object}   props
 * @param {Function} props.onRehearsalChanged Tells the frame (its status pill)
 *                                            the new rehearsal value.
 */
function Safety( { onRehearsalChanged } ) {
	const [ prefs, setPrefs ] = useState( null );
	// How many flips of each switch are on their way, so an answer to an
	// older flip never moves a switch the owner has flipped again since.
	const pending = useRef( {} );

	useEffect( () => {
		api( 'preferences' )
			.then( ( res ) => setPrefs( safetyPrefs( res ) ) )
			.catch( () => setPrefs( safetyPrefs( {} ) ) );
	}, [] );

	// Optimistic: the switch moves at once and stays usable (R9). The server's
	// answer confirms it with "Saved." (P16) or puts it back with the error.
	const flip = ( key ) => {
		const next = ! prefs[ key ];
		pending.current[ key ] = ( pending.current[ key ] || 0 ) + 1;
		setPrefs( ( p ) => ( { ...p, [ key ]: next } ) );
		api( 'preferences', {
			method: 'POST',
			data: { [ key ]: next },
		} )
			.then( ( res ) => {
				pending.current[ key ] -= 1;
				if ( pending.current[ key ] === 0 ) {
					const saved = safetyPrefs( res );
					setPrefs( ( p ) => ( { ...p, [ key ]: saved[ key ] } ) );
					if ( 'rehearsal' === key && onRehearsalChanged ) {
						onRehearsalChanged( saved.rehearsal );
					}
				}
				toast.success( __( 'Saved.', 'saddle' ) );
			} )
			.catch( ( e ) => {
				pending.current[ key ] -= 1;
				if ( pending.current[ key ] === 0 ) {
					setPrefs( ( p ) => ( { ...p, [ key ]: ! next } ) );
				}
				toast.error(
					e?.message || __( 'Could not save that setting.', 'saddle' )
				);
			} );
	};

	const row = ( { key, id, title, help, meta, label } ) => (
		<Row
			key={ key }
			title={
				help ? <Labelled help={ help }>{ title }</Labelled> : title
			}
			description={ meta }
			actions={
				prefs ? (
					<Switch
						id={ id }
						checked={ !! prefs[ key ] }
						onChange={ () => flip( key ) }
						aria-label={ label || title }
					/>
				) : (
					// Its state is not known yet: a placeholder, not a
					// switch that reads Off.
					<Skeleton round width={ 32 } height={ 18 } />
				)
			}
		/>
	);

	return (
		<section className="saddle-section">
			<SectionHeader title={ __( 'Safety', 'saddle' ) } />
			<RowList>
				{ row( {
					key: 'drafts_only',
					id: 'saddle-drafts-only-switch',
					title: __( 'Publishing needs my OK', 'saddle' ),
					help: __(
						'New posts and pages an app asks to publish or schedule are saved as drafts. Publishing or scheduling an existing item needs your confirmation. Edits to content that is already live still go through.',
						'saddle'
					),
					meta: __(
						'Apps save drafts; you approve what goes live.',
						'saddle'
					),
				} ) }
				{ row( {
					key: 'rehearsal',
					id: 'saddle-rehearsal-switch',
					title: __( 'Practice mode', 'saddle' ),
					help: __(
						'Apps can try anything their access allows and nothing is saved. Each tool that would change the site says what it would have done, and the attempt shows in Activity as rehearsed. Reading works as usual.',
						'saddle'
					),
					meta: __(
						'Apps try changes but nothing is saved.',
						'saddle'
					),
				} ) }
				{ row( {
					key: 'domain_enforced',
					id: 'saddle-domain-switch',
					title: __( 'Stop writes if the site moves', 'saddle' ),
					help: __(
						'If the site address changes, for example after copying it to another domain, apps are refused writes until you confirm their access again.',
						'saddle'
					),
					meta: __(
						'After an address change, confirm access again.',
						'saddle'
					),
				} ) }
				{ row( {
					key: 'core_major_updates',
					// The address an app gives the owner when it is refused
					// a major release (the settings registry's field id).
					id: 'saddle-field-saddle-core_major_updates',
					title: __( 'Major WordPress releases', 'saddle' ),
					help: __(
						'Lets an app update WordPress to a new major version, such as 7.1 to 7.2, after you approve its preview. A major release has no automatic rollback and upgrades the database. Minor and security releases never need this. WordPress’s own “automatic updates for all new versions” in Dashboard → Updates is a separate setting.',
						'saddle'
					),
					meta: __(
						'Apps can update WordPress to a new major version.',
						'saddle'
					),
				} ) }
			</RowList>
		</section>
	);
}

// The three modes, as the menu names them (#364).
const MODE_LABELS = {
	allow: __( 'Allow', 'saddle' ),
	ask: __( 'Ask first', 'saddle' ),
	off: __( 'Off', 'saddle' ),
};

/**
 * What each tool may do: a search, a one-click recommended set, then every
 * tool that can run on this site as a row with Allow, Ask first or Off,
 * grouped by area (#364). A choice shows at once and saves through
 * `POST /tool-modes`; it holds for every app, under each app's own role. A
 * tool for a plugin that isn't active is left out of the list, and its mode
 * is kept.
 *
 * @param {Object}   props
 * @param {Array}    props.caps      Every tool, from `GET /capabilities`.
 * @param {Array}    props.listed    The tools to list (runnableTools()).
 * @param {Function} props.onChanged Reloads the tools.
 * @param {Function} props.onApplied Called after the recommended set, which
 *                                   also turns on a Safety switch.
 */
function ToolModes( { caps, listed, onChanged, onApplied } ) {
	const [ query, setQuery ] = useState( '' );
	const [ modes, setModes ] = useState( () => modeMap( caps ) );
	const [ applying, setApplying ] = useState( false );
	// Saves on their way. The tools reload only when the last one is back,
	// so a reload never shows a mode from before a newer choice.
	const pending = useRef( 0 );

	// The server's answer wins whenever the tools reload.
	useEffect( () => setModes( modeMap( caps ) ), [ caps ] );

	const groups = useMemo(
		() => groupTools( listed, query, __( 'Other', 'saddle' ) ),
		[ listed, query ]
	);

	const settle = useCallback( () => {
		pending.current -= 1;
		if ( 0 === pending.current && onChanged ) {
			onChanged();
		}
	}, [ onChanged ] );

	const choose = ( short, mode ) => {
		const before = modes.get( short );
		if ( before === mode ) {
			return;
		}
		setModes( ( now ) => withMode( now, short, mode ) );
		pending.current += 1;
		api( 'tool-modes', {
			method: 'POST',
			data: { tool: short, mode },
		} )
			.then( () => toast.success( __( 'Saved.', 'saddle' ) ) )
			.catch( ( e ) => {
				// Put back this one tool; any other choice stands.
				setModes( ( now ) => withMode( now, short, before ) );
				toast.error(
					e?.message || __( 'Could not save that change.', 'saddle' )
				);
			} )
			.finally( settle );
	};

	const applyRecommended = () => {
		setApplying( true );
		api( 'tool-modes/recommended', { method: 'POST' } )
			.then( ( res ) => {
				const moved = ( res && res.moved ) || [];
				toast.success(
					moved.length
						? sprintf(
								/* translators: %d: number of tools. */
								_n(
									'%d tool now asks you first. Publishing needs your OK.',
									'%d tools now ask you first. Publishing needs your OK.',
									moved.length,
									'saddle'
								),
								moved.length
						  )
						: __(
								'Already set. Publishing needs your OK.',
								'saddle'
						  )
				);
				if ( onApplied ) {
					onApplied();
				}
				if ( onChanged ) {
					onChanged();
				}
			} )
			.catch( ( e ) =>
				toast.error(
					e?.message || __( 'Could not save that change.', 'saddle' )
				)
			)
			.finally( () => setApplying( false ) );
	};

	return (
		<div className="saddle-tools">
			<p className="saddle-tools__intro">
				{ __(
					'Ask first: the app shows you the change and it waits under Needs your OK on Overview. It runs only after you approve it there.',
					'saddle'
				) }
			</p>
			<RowList>
				<Row
					title={ __( 'Recommended set', 'saddle' ) }
					description={ __(
						'Ask first for plugins, themes, updates, settings and anything that deletes. Publishing needs your OK. Content edits stay allowed. Nothing you turned off is turned on.',
						'saddle'
					) }
					actions={
						<Button
							variant="secondary"
							size="sm"
							onClick={ applyRecommended }
							disabled={ applying }
						>
							{ __( 'Use it', 'saddle' ) }
						</Button>
					}
				/>
			</RowList>
			<input
				type="search"
				className="saddle-tools__search"
				value={ query }
				onChange={ ( e ) => setQuery( e.target.value ) }
				placeholder={ __( 'Search tools…', 'saddle' ) }
				aria-label={ __( 'Search tools', 'saddle' ) }
			/>
			{ 0 === groups.length && (
				<p className="saddle-tools__empty">
					{ __( 'No tools match.', 'saddle' ) }
				</p>
			) }
			{ groups.map( ( { category, tools } ) => (
				<div key={ category } className="saddle-tools__group">
					<h3 className="saddle-tools__area">{ category }</h3>
					<RowList>
						{ tools.map( ( tool ) => (
							<Row
								key={ tool.short }
								title={
									<Tooltip content={ tool.description }>
										<span>{ tool.label }</span>
									</Tooltip>
								}
								actions={
									<>
										{ tool.new &&
											'ask' ===
												modes.get( tool.short ) && (
												<Tooltip
													content={ __(
														'Added since you last chose. It asks you first until you pick.',
														'saddle'
													) }
												>
													<span>
														<Badge>
															{ __(
																'New',
																'saddle'
															) }
														</Badge>
													</span>
												</Tooltip>
											) }
										<Select
											className="saddle-tools__mode"
											size="sm"
											options={ modesFor( tool ).map(
												( m ) => ( {
													value: m,
													label: MODE_LABELS[ m ],
												} )
											) }
											value={ modes.get( tool.short ) }
											onChange={ ( mode ) =>
												choose( tool.short, mode )
											}
											aria-label={ sprintf(
												/* translators: %s: tool name. */
												__(
													'What %s may do',
													'saddle'
												),
												tool.label
											) }
										/>
									</>
								}
							/>
						) ) }
					</RowList>
				</div>
			) ) }
		</div>
	);
}

// Sign-in for apps: the one control, drawn by SignInCard. The disclosure
// names it, so SignInCard draws no heading of its own (#293).
function SignIn() {
	const signIn = useOauthSettings();
	return <SignInCard { ...signIn } />;
}

/**
 * Whether the address asks for one Advanced section (`&section=signin`).
 *
 * @param {string} name Section name.
 * @return {boolean} True when `section` names it.
 */
const wantsSection = ( name ) =>
	name === new URLSearchParams( window.location.search ).get( 'section' );

// The memory limits and the recent-changes list, from the settings schema.
const MEMORY_KEYS = [ 'memory_max_entries', 'memory_core_budget' ];
const RECENT_KEYS = [ 'memory_recent_changes', 'memory_recent_limit' ];

/**
 * @param {Object}   props
 * @param {Array}    props.caps               The tools.
 * @param {Function} props.loadCaps           Reloads the tools.
 * @param {Function} props.onRehearsalChanged See Safety.
 * @param {*}        props.children           Sections other plugins add, before the
 *                                            closing "Run setup again".
 */
export default function SettingsPage( {
	caps,
	loadCaps,
	onRehearsalChanged,
	children,
} ) {
	const [ signInAsked ] = useState( () => wantsSection( 'signin' ) );
	const [ servicesAsked ] = useState( () => wantsSection( 'services' ) );
	const signInRef = useRef( null );
	const services = useServices();
	const addons = switchable( services.records );
	// The connection check mounts when first opened and stays after.
	const [ checkOpened, setCheckOpened ] = useState( false );
	// The recommended set turns on a Safety switch; remounting Safety reloads it.
	const [ safetyKey, setSafetyKey ] = useState( 0 );
	const tools = useMemo( () => runnableTools( caps ), [ caps ] );

	// Bring Sign-in for apps, or Accounts, into view. Safety above loads its
	// own data and grows, so the jump repeats as the page settles, as App
	// does for an anchor.
	useEffect( () => {
		if ( ! signInAsked && ! servicesAsked ) {
			return undefined;
		}
		const timers = [ 0, 400, 1200 ].map( ( delay ) =>
			setTimeout( () => {
				const el = signInAsked
					? signInRef.current
					: document.getElementById( 'accounts' );
				if ( el ) {
					el.scrollIntoView( { block: 'start' } );
				}
			}, delay )
		);
		return () => timers.forEach( clearTimeout );
	}, [ signInAsked, servicesAsked ] );

	return (
		<>
			<Safety
				key={ safetyKey }
				onRehearsalChanged={ onRehearsalChanged }
			/>

			<Accounts
				records={ services.records }
				onChanged={ services.update }
			/>

			<InsightSettings />

			<section className="saddle-section">
				<SectionHeader title={ __( 'Advanced', 'saddle' ) } />
				<div className="saddle-adv">
					<Collapsible
						trigger={ sprintf(
							/* translators: %d: number of tools. */
							__( 'What each tool may do (%d)', 'saddle' ),
							tools.length
						) }
					>
						<ToolModes
							caps={ caps }
							listed={ tools }
							onChanged={ loadCaps }
							onApplied={ () => setSafetyKey( ( k ) => k + 1 ) }
						/>
					</Collapsible>
					<Collapsible
						ref={ signInRef }
						id="saddle-adv-signin"
						defaultOpen={ signInAsked }
						trigger={ __( 'Sign-in for apps', 'saddle' ) }
					>
						<div className="saddle-adv__signin">
							<SignIn />
						</div>
					</Collapsible>
					{ addons.length > 0 && (
						<Collapsible
							defaultOpen={ servicesAsked }
							trigger={ sprintf(
								/* translators: %d: number of plugins. */
								__( 'Plugins that add tools (%d)', 'saddle' ),
								addons.length
							) }
						>
							<AddonSwitches
								records={ addons }
								onChanged={ services.update }
							/>
						</Collapsible>
					) }
					<Collapsible trigger={ __( 'Memory limits', 'saddle' ) }>
						<SettingsForm
							scope="saddle"
							screen="settings/general"
							keys={ MEMORY_KEYS }
							bare
						/>
					</Collapsible>
					<Collapsible trigger={ __( 'Recent changes', 'saddle' ) }>
						<SettingsForm
							scope="saddle"
							screen="settings/general"
							keys={ RECENT_KEYS }
							bare
						/>
					</Collapsible>
					<Collapsible
						trigger={ __( 'Connection check', 'saddle' ) }
						onOpenChange={ ( open ) =>
							open && setCheckOpened( true )
						}
					>
						{ checkOpened && (
							<div className="saddle-adv__check">
								<ConnectionHealth />
								<McpDiagnostics />
							</div>
						) }
					</Collapsible>
				</div>
			</section>

			{ children }
		</>
	);
}
