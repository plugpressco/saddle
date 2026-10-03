/**
 * Services (#291) — everything Saddle can hand to the AI beyond core
 * WordPress, on a page of its own: Accounts (outside services with a key),
 * Plugins (found on this site) and Add-ons (plugins that bring tools).
 *
 * One row per record from `GET /services`: the name and one status line. An
 * account with no key has one button, "Add key"; any other row is itself the
 * button that opens the drawer. The drawer holds one line of what the
 * service is, the key form (accounts) or the switch (third-party add-ons),
 * where the key comes from, and the tools, folded. A key never comes back
 * from the server, only whether one is set and its last four characters.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	Collapsible,
	Drawer,
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
import { icons } from '../icons/kit';
import {
	groupServices,
	keyLink,
	replaceRecord,
	roleLabel,
	statusLabel,
	summaryOf,
	toolCount,
} from '../services-logic';

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
 * The drawer body for one record. Its one line of what the service is sits
 * in the drawer's head, under the name.
 *
 * @param {Object}   props
 * @param {Object}   props.record    The service.
 * @param {Function} props.onChanged Receives an updated record.
 */
function Detail( { record, onChanged } ) {
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

	const tools = record.tools || [];

	return (
		<div className="saddle-svc__detail">
			{ 'account' === record.kind && (
				<KeyForm record={ record } onSaved={ onChanged } />
			) }

			{ 'account' !== record.kind && record.can_toggle && (
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
					<span aria-hidden="true">
						{ __( 'Let apps use it', 'saddle' ) }
					</span>
				</div>
			) }

			{ /* The one privacy fact an owner needs before adding a key: which
			     hosts this service sends to (non-negotiable #1). */ }
			{ ( record.sends || [] ).length > 0 && (
				<p className="saddle-svc__sends">
					{ sprintf(
						/* translators: %s: host names, e.g. "api.unsplash.com, images.unsplash.com". */
						__( 'Sends to %s', 'saddle' ),
						record.sends.map( ( s ) => s.host ).join( ', ' )
					) }
				</p>
			) }

			{ tools.length > 0 && (
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
			) }
		</div>
	);
}

/**
 * One service: its name and status. An account with no key gets "Add key";
 * any other row is the button that opens the drawer.
 *
 * @param {Object}   props
 * @param {Object}   props.record The service.
 * @param {Function} props.onOpen Opens its drawer.
 */
function ServiceRow( { record, onOpen } ) {
	const status = statusLabel( record );

	if ( 'needs_key' === record.status ) {
		return (
			<Row
				className="saddle-svc__row"
				title={ record.name }
				description={ status }
				actions={
					<Button variant="secondary" size="sm" onClick={ onOpen }>
						{ __( 'Add key', 'saddle' ) }
					</Button>
				}
			/>
		);
	}

	// No `actions`, so the kit makes the whole row the button (role, tab
	// stop, Enter and Space); the chevron sits inside its body.
	return (
		<Row
			className="saddle-svc__row"
			onClick={ onOpen }
			aria-haspopup="dialog"
		>
			<span className="saddle-svc__line">
				<span className="saddle-svc__name">
					<span className="pp-rowlist__title">{ record.name }</span>
					{ status && (
						<span className="pp-rowlist__description">
							{ status }
						</span>
					) }
				</span>
				<icons.ChevronRight size={ 16 } className="saddle-svc__go" />
			</span>
		</Row>
	);
}

export default function Services() {
	// null until the list arrives.
	const [ records, setRecords ] = useState( null );
	const [ openKey, setOpenKey ] = useState( null );

	useEffect( () => {
		api( 'services' )
			.then( ( res ) => setRecords( res.services || [] ) )
			.catch( ( e ) => {
				setRecords( [] );
				toast.error( e.message );
			} );
	}, [] );

	const groups = useMemo( () => groupServices( records ), [ records ] );
	const open = ( records || [] ).find( ( r ) => r.key === openKey );
	const update = ( next ) =>
		setRecords( ( list ) => replaceRecord( list, next ) );

	if ( null === records ) {
		return <RowList loading />;
	}

	if ( 0 === groups.length ) {
		return (
			<p className="saddle-svc__empty">
				{ __( 'Nothing here yet.', 'saddle' ) }
			</p>
		);
	}

	return (
		<>
			{ groups.map( ( g ) => (
				<section key={ g.kind } className="saddle-section">
					{ g.title && <SectionHeader title={ g.title } /> }
					<RowList>
						{ g.rows.map( ( r ) => (
							<ServiceRow
								key={ r.key }
								record={ r }
								onOpen={ () => setOpenKey( r.key ) }
							/>
						) ) }
					</RowList>
				</section>
			) ) }

			<Drawer
				open={ !! open }
				onOpenChange={ ( next ) => ! next && setOpenKey( null ) }
				title={ open ? open.name : '' }
				description={ open ? summaryOf( open ) : undefined }
				closeLabel={ __( 'Close', 'saddle' ) }
				size="md"
			>
				{ open && <Detail record={ open } onChanged={ update } /> }
			</Drawer>
		</>
	);
}
