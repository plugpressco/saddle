/**
 * What the AI can use beyond core WordPress, on Settings (#369; a page of its
 * own from #291 until then).
 *
 * - **Accounts:** outside services with a key, such as Unsplash. A row says
 *   what the service is for and whether it is set up; "Add key" or "Change
 *   key" opens the form right under it, with where the key comes from and
 *   the hosts it sends to. A key never comes back from the server, only
 *   whether one is set and its last four characters.
 * - **Plugins that add tools:** another developer's plugin stays off until
 *   the owner switches it on (non-negotiable #2). Each row has its switch,
 *   what it sends off the site, and its tools, folded. Drawn only when such
 *   a plugin is installed.
 *
 * Saddle's own add-ons and the plugins Saddle edits need nothing from the
 * owner; Home lists them under Add-ons and Works with.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Collapsible,
	ErrorText,
	Field,
	Input,
	RowList,
	Row,
	Switch,
	VisuallyHidden,
	useConfirm,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import SectionHeader from './SectionHeader';
import {
	keyLink,
	replaceRecord,
	roleLabel,
	toolCount,
} from '../services-logic';
import { rowLine } from '../services-page-logic';

/**
 * GET /services once, and a way to put back a record a change returned.
 *
 * @return {{records: ?Array, update: Function}} `records` is null until the
 *         list arrives, and an empty list when it can't.
 */
export function useServices() {
	const [ records, setRecords ] = useState( null );
	useEffect( () => {
		let alive = true;
		api( 'services' )
			.then( ( res ) => alive && setRecords( res.services || [] ) )
			.catch( () => alive && setRecords( [] ) );
		return () => {
			alive = false;
		};
	}, [] );
	return {
		records,
		update: ( next ) =>
			setRecords( ( list ) => replaceRecord( list, next ) ),
	};
}

/**
 * The hosts a service sends to: the one privacy fact an owner needs before
 * adding a key or switching it on (non-negotiable #1).
 *
 * @param {Object} props
 * @param {Object} props.record A service record.
 */
function Sends( { record } ) {
	const hosts = ( record.sends || [] ).map( ( s ) => s.host );
	if ( ! hosts.length ) {
		return null;
	}
	return (
		<p className="saddle-svc__sends">
			{ sprintf(
				/* translators: %s: host names, e.g. "api.unsplash.com, images.unsplash.com". */
				__( 'Sends to %s', 'saddle' ),
				hosts.join( ', ' )
			) }
		</p>
	);
}

/**
 * A service's tools, folded under their count.
 *
 * @param {Object} props
 * @param {Object} props.record A service record.
 */
function Tools( { record } ) {
	const tools = record.tools || [];
	if ( ! tools.length ) {
		return null;
	}
	return (
		<Collapsible
			className="saddle-svc__tools"
			trigger={ toolCount( record ) }
		>
			<ul className="saddle-svc__tool-list">
				{ tools.map( ( t ) => (
					<li key={ t.name } title={ t.name }>
						<span>{ t.title || t.name }</span>
						<span className="saddle-svc__role">
							{ roleLabel( t.role ) }
						</span>
					</li>
				) ) }
			</ul>
		</Collapsible>
	);
}

/**
 * The key form for an account, or the key it has with Change and Remove.
 *
 * @param {Object}   props
 * @param {Object}   props.record  The account.
 * @param {Function} props.onSaved Receives the updated record.
 */
function KeyForm( { record, onSaved } ) {
	const confirm = useConfirm();
	const [ draft, setDraft ] = useState( '' );
	const [ changing, setChanging ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const cred = record.credential || {};
	const showForm = ! cred.configured || changing;
	const link = keyLink( record );

	const save = ( key ) => {
		setSaving( true );
		setError( '' );
		api( `services/${ encodeURIComponent( record.key ) }/key`, {
			method: 'POST',
			data: { key },
		} )
			.then( ( res ) => {
				onSaved( res );
				setDraft( '' );
				setChanging( false );
				toast.success(
					key
						? sprintf(
								/* translators: %s: service name, such as Unsplash. */
								__( '%s key saved.', 'saddle' ),
								record.name
						  )
						: sprintf(
								/* translators: %s: service name, such as Unsplash. */
								__( '%s key removed.', 'saddle' ),
								record.name
						  )
				);
			} )
			.catch( ( e ) =>
				setError(
					e?.message || __( 'Could not save that key.', 'saddle' )
				)
			)
			.finally( () => setSaving( false ) );
	};

	const remove = async () => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: service name, such as Unsplash. */
				__( 'Remove the %s key?', 'saddle' ),
				record.name
			),
			description: __(
				'Your apps lose this service’s tools until a new key is added. Anything already saved on your site is kept.',
				'saddle'
			),
			danger: true,
			confirmLabel: __( 'Remove', 'saddle' ),
			cancelLabel: __( 'Cancel', 'saddle' ),
		} );
		if ( ok ) {
			save( '' );
		}
	};

	if ( ! showForm ) {
		return (
			<div className="saddle-svc__key">
				<div className="saddle-svc__key-row">
					<span className="saddle-svc__key-label">
						{ __( 'Your key', 'saddle' ) }
					</span>
					<code>{ `····${ cred.hint || '' }` }</code>
					<span className="saddle-svc__actions">
						<Button
							variant="secondary"
							size="sm"
							onClick={ () => setChanging( true ) }
						>
							{ __( 'Change', 'saddle' ) }
						</Button>
						<Button
							variant="link"
							size="sm"
							className="saddle-link-danger"
							onClick={ remove }
							disabled={ saving }
						>
							{ __( 'Remove', 'saddle' ) }
						</Button>
					</span>
				</div>
				{ error && <ErrorText>{ error }</ErrorText> }
			</div>
		);
	}

	return (
		<div className="saddle-svc__key">
			<Field label={ __( 'Your key', 'saddle' ) } error={ error }>
				{ ( a11y ) => (
					<Input
						{ ...a11y }
						type="password"
						value={ draft }
						onChange={ ( e ) => setDraft( e.target.value ) }
						placeholder={ __( 'Paste your access key', 'saddle' ) }
						autoComplete="off"
					/>
				) }
			</Field>
			{ link && (
				<p className="saddle-svc__get">
					<a
						href={ link.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ link.label }
						<VisuallyHidden>
							{ __( '(opens in a new tab)', 'saddle' ) }
						</VisuallyHidden>
					</a>
				</p>
			) }
			<div className="saddle-svc__actions">
				<Button
					variant="primary"
					size="sm"
					onClick={ () => save( draft.trim() ) }
					loading={ saving }
					disabled={ saving || ! draft.trim() }
				>
					{ __( 'Save key', 'saddle' ) }
				</Button>
				{ changing && (
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => {
							setChanging( false );
							setDraft( '' );
							setError( '' );
						} }
						disabled={ saving }
					>
						{ __( 'Cancel', 'saddle' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

/**
 * One account: its name and line, and its key form under it once opened.
 *
 * @param {Object}   props
 * @param {Object}   props.record  The account.
 * @param {Function} props.onSaved Receives the updated record.
 */
function AccountRow( { record, onSaved } ) {
	const needsKey = 'needs_key' === record.status;
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<Row
				className="saddle-svc__row"
				title={ record.name }
				description={ rowLine( record ) }
				actions={
					<Button
						variant={ needsKey && ! open ? 'secondary' : 'link' }
						size="sm"
						aria-expanded={ open }
						onClick={ () => setOpen( ( o ) => ! o ) }
					>
						{ open && __( 'Close', 'saddle' ) }
						{ ! open &&
							( needsKey
								? __( 'Add key', 'saddle' )
								: __( 'Change key', 'saddle' ) ) }
					</Button>
				}
			/>
			{ open && (
				<div className="saddle-svc__detail">
					<KeyForm
						record={ record }
						onSaved={ ( next ) => {
							onSaved( next );
							if ( 'needs_key' !== next.status ) {
								setOpen( false );
							}
						} }
					/>
					<Sends record={ record } />
				</div>
			) }
		</>
	);
}

/**
 * Settings → Accounts: outside services with a key. Draws nothing until the
 * list arrives, or when there is no account.
 *
 * @param {Object}   props
 * @param {?Array}   props.records   From useServices().
 * @param {Function} props.onChanged Receives an updated record.
 */
export function Accounts( { records, onChanged } ) {
	const accounts = ( records || [] ).filter( ( r ) => 'account' === r.kind );
	if ( ! accounts.length ) {
		return null;
	}
	return (
		<section className="saddle-section" id="accounts">
			<SectionHeader title={ __( 'Accounts', 'saddle' ) } />
			<RowList>
				{ accounts.map( ( r ) => (
					<AccountRow
						key={ r.key }
						record={ r }
						onSaved={ onChanged }
					/>
				) ) }
			</RowList>
		</section>
	);
}

/**
 * The plugins whose tools wait for the owner: another developer's plugin
 * that adds tools to Saddle.
 *
 * @param {?Array} records From useServices().
 * @return {Array} The records with a switch.
 */
export function switchable( records ) {
	return ( records || [] ).filter(
		( r ) => 'account' !== r.kind && r.can_toggle
	);
}

/**
 * One plugin that adds tools: its switch, what it sends and its tools.
 *
 * @param {Object}   props
 * @param {Object}   props.record    The add-on.
 * @param {Function} props.onChanged Receives the updated record.
 */
function AddonSwitch( { record, onChanged } ) {
	const [ busy, setBusy ] = useState( false );
	// Optimistic: the switch moves at once and goes back if the save fails.
	const [ on, setOn ] = useState( !! record.enabled );
	useEffect( () => setOn( !! record.enabled ), [ record.enabled ] );

	const toggle = ( next ) => {
		setOn( next );
		setBusy( true );
		api( `services/${ encodeURIComponent( record.key ) }/enabled`, {
			method: 'POST',
			data: { enabled: next },
		} )
			.then( ( res ) => {
				onChanged( res );
				toast.success(
					next
						? sprintf(
								/* translators: %s: plugin name. */
								__( '%s is on.', 'saddle' ),
								record.name
						  )
						: sprintf(
								/* translators: %s: plugin name. */
								__( '%s is off.', 'saddle' ),
								record.name
						  )
				);
			} )
			.catch( ( e ) => {
				setOn( ! next );
				toast.error(
					e?.message || __( 'Could not save that change.', 'saddle' )
				);
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="saddle-svc__addon">
			<div className="saddle-svc__switch">
				<Switch
					checked={ on }
					disabled={ busy }
					onChange={ toggle }
					aria-label={ sprintf(
						/* translators: %s: plugin name. */
						__( 'Let apps use %s', 'saddle' ),
						record.name
					) }
				/>
				<span className="saddle-svc__name" aria-hidden="true">
					{ record.name }
				</span>
			</div>
			<Sends record={ record } />
			<Tools record={ record } />
		</div>
	);
}

/**
 * Settings → Advanced → Plugins that add tools: one switch each.
 *
 * @param {Object}   props
 * @param {Array}    props.records   From switchable().
 * @param {Function} props.onChanged Receives an updated record.
 */
export function AddonSwitches( { records, onChanged } ) {
	return (
		<div className="saddle-svc__addons">
			{ records.map( ( r ) => (
				<AddonSwitch
					key={ r.key }
					record={ r }
					onChanged={ onChanged }
				/>
			) ) }
		</div>
	);
}
