/**
 * Services (#291) — everything Saddle can hand to the AI beyond core
 * WordPress, on a page of its own: Accounts (outside services with a key),
 * Plugins (found on this site) and Add-ons (plugins that bring tools).
 *
 * One row per record from `GET /services`; a row opens a drawer with the key
 * form (accounts) or the switch (third-party add-ons), what leaves the site,
 * the tools the apps get with their role, and the links. A key never comes
 * back from the server, only whether one is set and its last four characters.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Badge,
	Button,
	Drawer,
	Field,
	Input,
	Notice,
	RowList,
	Row,
	Switch,
	useConfirm,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import SectionHeader from './SectionHeader';
import {
	badgeFor,
	groupServices,
	initialOf,
	kindLabel,
	metaLine,
	replaceRecord,
	roleLabel,
} from '../services-logic';

// A neutral tile with the service's first letter, where there is no logo.
const Logo = ( { name } ) => (
	<span className="saddle-svc__logo" aria-hidden="true">
		{ initialOf( name ) }
	</span>
);

/**
 * "Your key": the form for an account.
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

	return (
		<div className="saddle-svc__block">
			{ ! showForm && (
				<h3 className="saddle-svc__h">
					{ __( 'Your key', 'saddle' ) }
				</h3>
			) }
			{ showForm ? (
				<>
					<Field
						label={ __( 'Your key', 'saddle' ) }
						hint={ sprintf(
							/* translators: %s: service name, such as Unsplash. */
							__(
								'Your key stays on this site; only %s sees it.',
								'saddle'
							),
							record.name
						) }
					>
						{ ( a11y ) => (
							<Input
								{ ...a11y }
								type="password"
								value={ draft }
								onChange={ ( e ) => setDraft( e.target.value ) }
								placeholder={ __(
									'Paste your access key',
									'saddle'
								) }
								autoComplete="off"
							/>
						) }
					</Field>
					{ error && <Notice tone="danger">{ error }</Notice> }
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
				</>
			) : (
				<>
					<div className="saddle-svc__key">
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
					{ error && <Notice tone="danger">{ error }</Notice> }
				</>
			) }
			{ cred.connector && cred.connectors_url && (
				<Notice tone="info">
					{ __( 'Also in', 'saddle' ) }{ ' ' }
					<a href={ cred.connectors_url }>
						{ __( 'Settings → Connectors', 'saddle' ) }
					</a>
					{ __(
						', WordPress’s own list of keys. Saving it in either place saves it in both.',
						'saddle'
					) }
				</Notice>
			) }
		</div>
	);
}

// The sentence under "Status" for a plugin or an add-on.
const statusText = ( record ) => {
	if ( 'plugin' === record.kind ) {
		return sprintf(
			/* translators: %s: plugin name. */
			__(
				'%s is active on this site. Saddle works inside it and sends nothing out.',
				'saddle'
			),
			record.name
		);
	}
	if ( 'third-party' === record.source ) {
		return __(
			'From another developer. Saddle can’t vouch for it, so its tools stay off until you turn them on.',
			'saddle'
		);
	}
	return __(
		'A PlugPress add-on. Always on while the plugin is active.',
		'saddle'
	);
};

/**
 * The drawer body for one record.
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
								__(
									'%s is on. Its tools follow each app’s access and approval rules.',
									'saddle'
								),
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

	const sends = record.sends || [];
	const tools = record.tools || [];
	const links = [
		[ __( 'Get a free key', 'saddle' ), record.credentials_url ],
		[ __( 'Terms', 'saddle' ), record.terms_url ],
		[ __( 'Privacy', 'saddle' ), record.privacy_url ],
	].filter( ( l ) => l[ 1 ] );

	return (
		<div className="saddle-svc__detail">
			{ 'account' === record.kind ? (
				<KeyForm record={ record } onSaved={ onChanged } />
			) : (
				<div className="saddle-svc__block">
					<h3 className="saddle-svc__h">
						{ __( 'Status', 'saddle' ) }
					</h3>
					{ record.description &&
						record.description !== kindLabel( record ) && (
							<p className="saddle-svc__text">
								{ record.description }
							</p>
						) }
					<p className="saddle-svc__text">{ statusText( record ) }</p>
					{ record.can_toggle && (
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
							<span>{ __( 'Let apps use it', 'saddle' ) }</span>
						</div>
					) }
				</div>
			) }

			<div className="saddle-svc__block">
				<h3 className="saddle-svc__h">
					{ __( 'What leaves your site', 'saddle' ) }
				</h3>
				{ sends.length ? (
					<ul className="saddle-svc__sends">
						{ sends.map( ( s ) => (
							<li key={ s.host }>
								<code>{ s.host }</code>
								<span>{ s.what }</span>
								<span>{ s.when }</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="saddle-svc__text">
						{ __(
							'Nothing. It works inside your WordPress.',
							'saddle'
						) }
					</p>
				) }
			</div>

			{ tools.length > 0 && (
				<div className="saddle-svc__block">
					<h3 className="saddle-svc__h">
						{ __( 'Tools your apps get', 'saddle' ) }
					</h3>
					<ul className="saddle-svc__tools">
						{ tools.map( ( t ) => (
							<li key={ t.name }>
								<span>
									{ t.title || t.name }
									<code>{ t.name }</code>
								</span>
								<span className="saddle-svc__role">
									{ roleLabel( t.role ) }
								</span>
							</li>
						) ) }
					</ul>
					<p className="saddle-svc__note">
						{ __(
							'Each app gets the tools its access allows, set on AI apps.',
							'saddle'
						) }
					</p>
				</div>
			) }

			{ links.length > 0 && (
				<div className="saddle-svc__links">
					{ links.map( ( [ label, url ] ) => (
						<a
							key={ url }
							href={ url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ label }
						</a>
					) ) }
				</div>
			) }
		</div>
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
		return (
			<section className="saddle-section">
				<RowList loading />
			</section>
		);
	}

	return (
		<>
			{ 0 === groups.length && (
				<Notice tone="info">
					{ __( 'No services found on this site.', 'saddle' ) }
				</Notice>
			) }
			{ groups.map( ( g ) => (
				<section key={ g.kind } className="saddle-section">
					<SectionHeader title={ g.title } description={ g.note } />
					<RowList>
						{ g.rows.map( ( r ) => {
							const badge = badgeFor( r );
							return (
								<Row
									key={ r.key }
									className="saddle-svc__row"
									icon={ <Logo name={ r.name } /> }
									title={ r.name }
									description={ metaLine( r ) }
									onClick={ () => setOpenKey( r.key ) }
									actions={
										badge.button ? (
											<Button
												variant="primary"
												size="sm"
												onClick={ ( e ) => {
													e.stopPropagation();
													setOpenKey( r.key );
												} }
											>
												{ badge.label }
											</Button>
										) : (
											<Badge tone={ badge.tone }>
												{ badge.label }
											</Badge>
										)
									}
								/>
							);
						} ) }
					</RowList>
				</section>
			) ) }

			<Drawer
				open={ !! open }
				onOpenChange={ ( next ) => ! next && setOpenKey( null ) }
				title={ open ? open.name : '' }
				size="md"
			>
				{ open && <Detail record={ open } onChanged={ update } /> }
			</Drawer>
		</>
	);
}
