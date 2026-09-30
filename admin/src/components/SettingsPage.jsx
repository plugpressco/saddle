/**
 * Settings — one page, no tabs (#285).
 *
 * - Safety: three switches that save the moment they are flipped.
 * - Services: Unsplash and the plugins Saddle can hand to the AI (Integrations).
 * - Advanced: rarely used things, each collapsed — single tools, sign-in for
 *   apps, memory limits, recent changes, the connection check.
 *
 * Who may do what is chosen per app on AI apps, not here.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Collapsible,
	HelpTip,
	Row,
	RowList,
	Switch,
	Tooltip,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { disabledSet, flipTool, groupTools } from '../tools-list';
import SectionHeader from './SectionHeader';
import Integrations from './Integrations';
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
	const [ saving, setSaving ] = useState( '' );

	useEffect( () => {
		api( 'preferences' )
			.then( ( res ) =>
				setPrefs( {
					drafts_only: !! res.drafts_only,
					rehearsal: !! res.rehearsal,
					domain_enforced: !! res.domain?.enforced,
				} )
			)
			.catch( () =>
				setPrefs( {
					drafts_only: false,
					rehearsal: false,
					domain_enforced: false,
				} )
			);
	}, [] );

	const flip = ( key ) => {
		setSaving( key );
		api( 'preferences', {
			method: 'POST',
			data: { [ key ]: ! prefs[ key ] },
		} )
			.then( ( res ) => {
				setPrefs( {
					drafts_only: !! res.drafts_only,
					rehearsal: !! res.rehearsal,
					domain_enforced: !! res.domain?.enforced,
				} );
				if ( 'rehearsal' === key && onRehearsalChanged ) {
					onRehearsalChanged( !! res.rehearsal );
				}
			} )
			.catch( () =>
				toast.error( __( 'Could not save that setting.', 'saddle' ) )
			)
			.finally( () => setSaving( '' ) );
	};

	const row = ( { key, id, title, help, meta, label } ) => (
		<Row
			key={ key }
			title={
				help ? <Labelled help={ help }>{ title }</Labelled> : title
			}
			description={ meta }
			actions={
				<Switch
					id={ id }
					checked={ !! prefs?.[ key ] }
					disabled={ ! prefs || saving === key }
					onChange={ () => flip( key ) }
					aria-label={ label || title }
				/>
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
 * Turn off single tools: a search, then every tool as a row with a switch,
 * grouped by area. A switch saves at once (the whole switched-off set goes to
 * `POST /abilities`); off stays off for every app.
 *
 * @param {Object}   props
 * @param {Array}    props.caps      The tools, from `GET /capabilities`.
 * @param {Function} props.onChanged Reloads the tools.
 */
function ToolSwitches( { caps, onChanged } ) {
	const [ query, setQuery ] = useState( '' );
	const [ off, setOff ] = useState( () => disabledSet( caps ) );
	const [ busy, setBusy ] = useState( false );

	// The server's answer wins whenever the tools reload.
	useEffect( () => setOff( disabledSet( caps ) ), [ caps ] );

	const groups = useMemo(
		() => groupTools( caps, query, __( 'Other', 'saddle' ) ),
		[ caps, query ]
	);

	const flip = ( short ) => {
		const before = off;
		const next = flipTool( off, short );
		setOff( next );
		setBusy( true );
		api( 'abilities', {
			method: 'POST',
			data: { disabled: [ ...next ] },
		} )
			.then( () => onChanged && onChanged() )
			.catch( ( e ) => {
				setOff( before );
				toast.error(
					e?.message || __( 'Could not save that change.', 'saddle' )
				);
			} )
			.finally( () => setBusy( false ) );
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
										disabled={ busy }
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
	return (
		<>
			<Safety onRehearsalChanged={ onRehearsalChanged } />
			<Integrations caps={ caps } onChanged={ loadCaps } />

			<section className="saddle-section">
				<SectionHeader title={ __( 'Advanced', 'saddle' ) } />
				<div className="saddle-adv">
					<Collapsible
						trigger={ sprintf(
							/* translators: %d: number of tools. */
							__( 'Turn off single tools (%d)', 'saddle' ),
							caps.length
						) }
					>
						<ToolSwitches caps={ caps } onChanged={ loadCaps } />
					</Collapsible>
					<Collapsible trigger={ __( 'Sign-in for apps', 'saddle' ) }>
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
					<Collapsible trigger={ __( 'Connection check', 'saddle' ) }>
						<div className="saddle-adv__check">
							<ConnectionHealth />
							<McpDiagnostics />
						</div>
					</Collapsible>
				</div>
			</section>

			{ children }
		</>
	);
}
