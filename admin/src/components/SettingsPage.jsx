/**
 * Settings — one page, no tabs (#285).
 *
 * - Safety: three switches that save the moment they are flipped. A switch
 *   moves at once, says "Saved." when the server agrees, and moves back if it
 *   doesn't (R9, P16).
 * - Advanced: rarely used things, each collapsed — single tools, sign-in for
 *   apps, memory limits, recent changes, the connection check.
 *   `&section=signin` (the connect drawer's "Turn it on") opens Sign-in for
 *   apps and scrolls to it. The connection check runs only once opened: it
 *   calls the site back, which on a one-request-at-a-time server would hold
 *   the rest of the page.
 *
 * Who may do what is chosen per app on AI apps, not here.
 */
import {
	useState,
	useEffect,
	useMemo,
	useRef,
	useCallback,
} from '@wordpress/element';
import {
	Collapsible,
	HelpTip,
	Row,
	RowList,
	Skeleton,
	Switch,
	Tooltip,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { disabledSet, flipTool, groupTools } from '../tools-list';
import { runnableTools, safetyPrefs } from '../settings-logic';
import SectionHeader from './SectionHeader';
import SettingsForm from './SettingsForm';
import SignInCard, { useOauthSettings } from './SignInCard';
import ConnectionHealth from './ConnectionHealth';
import McpDiagnostics from './McpDiagnostics';

// A label with its longer explanation behind a "?".
const Labelled = ( { children, help } ) => (
	<span className="saddle-settings__label">
		{ children }
		<HelpTip>{ help }</HelpTip>
	</span>
);

/**
 * Safety: drafts-only, rehearsal and the domain check, each saved on flip
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
			</RowList>
		</section>
	);
}

/**
 * Turn off single tools: a search, then every tool that can run on this site
 * as a row with a switch, grouped by area. A switch moves at once and saves
 * (the whole switched-off set goes to `POST /abilities`); off stays off for
 * every app. A tool for a plugin that isn't active is left out of the list,
 * and its own on or off is kept.
 *
 * @param {Object}   props
 * @param {Array}    props.caps      Every tool, from `GET /capabilities`.
 * @param {Array}    props.listed    The tools to list (runnableTools()).
 * @param {Function} props.onChanged Reloads the tools.
 */
function ToolSwitches( { caps, listed, onChanged } ) {
	const [ query, setQuery ] = useState( '' );
	const [ off, setOff ] = useState( () => disabledSet( caps ) );
	// Saves on their way. The tools reload only when the last one is back,
	// so a reload never shows a switch from before a newer flip.
	const pending = useRef( 0 );

	// The server's answer wins whenever the tools reload.
	useEffect( () => setOff( disabledSet( caps ) ), [ caps ] );

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

	const flip = ( short ) => {
		const next = flipTool( off, short );
		setOff( next );
		pending.current += 1;
		api( 'abilities', {
			method: 'POST',
			data: { disabled: [ ...next ] },
		} )
			.then( () => toast.success( __( 'Saved.', 'saddle' ) ) )
			.catch( ( e ) => {
				// Put back this one switch; any other flip stands.
				setOff( ( now ) => flipTool( now, short ) );
				toast.error(
					e?.message || __( 'Could not save that change.', 'saddle' )
				);
			} )
			.finally( settle );
	};

	return (
		<div className="saddle-tools">
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
									<Switch
										checked={ ! off.has( tool.short ) }
										onChange={ () => flip( tool.short ) }
										aria-label={ tool.label }
									/>
								}
							/>
						) ) }
					</RowList>
				</div>
			) ) }
		</div>
	);
}

// Sign-in for apps: the one control, drawn by SignInCard. Its own heading is
// hidden here (the disclosure says it).
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
	const signInRef = useRef( null );
	// The connection check mounts when first opened and stays after.
	const [ checkOpened, setCheckOpened ] = useState( false );
	const tools = useMemo( () => runnableTools( caps ), [ caps ] );

	// Bring Sign-in for apps into view. Safety above loads its own data and
	// grows, so the jump repeats as the page settles, as App does for an
	// anchor.
	useEffect( () => {
		if ( ! signInAsked ) {
			return undefined;
		}
		const timers = [ 0, 400, 1200 ].map( ( delay ) =>
			setTimeout( () => {
				if ( signInRef.current ) {
					signInRef.current.scrollIntoView( { block: 'start' } );
				}
			}, delay )
		);
		return () => timers.forEach( clearTimeout );
	}, [ signInAsked ] );

	return (
		<>
			<Safety onRehearsalChanged={ onRehearsalChanged } />

			<section className="saddle-section">
				<SectionHeader title={ __( 'Advanced', 'saddle' ) } />
				<div className="saddle-adv">
					<Collapsible
						trigger={ sprintf(
							/* translators: %d: number of tools. */
							__( 'Turn off single tools (%d)', 'saddle' ),
							tools.length
						) }
					>
						<ToolSwitches
							caps={ caps }
							listed={ tools }
							onChanged={ loadCaps }
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
