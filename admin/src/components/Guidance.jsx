/**
 * Context — what every connected app knows about this site (#274).
 *
 * Was Settings → Guidance. Laid out like a context sheet an owner reads and
 * corrects, top to bottom:
 *  - About your site: the owner's instructions as named rows showing their
 *    text; Edit opens one field at a time (see context-fields.js — stored as
 *    sections of the one instructions text every agent reads).
 *  - Skills — playbook files (.md) you install; every app sees the list and
 *    reads one when a task matches.
 *  - Memory — what the apps noted as they worked, and what you pinned.
 *  - What apps see — what Saddle tells every app automatically, from the
 *    site itself (collapsed).
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	Badge,
	Textarea,
	Switch,
	CodeBlock,
	RowList,
	Row,
	Collapsible,
	Drawer,
	useConfirm,
	toast,
	HelpTip,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import { FIELDS, parseFields, serializeFields } from '../context-fields';
import Memory from './Memory';
import SectionHeader from './SectionHeader';

// Render the auto-generated context (lightweight markdown: `# headings`,
// `- bullets`, paragraphs) as a readable document instead of raw monospace, so
// a site owner sees a clean info sheet rather than developer output.
function renderContext( text ) {
	const nodes = [];
	let bullets = null;
	let key = 0;

	const flush = () => {
		if ( bullets ) {
			nodes.push(
				<ul key={ key++ } className="saddle-doc__list">
					{ bullets.map( ( b, i ) => (
						<li key={ i }>{ b }</li>
					) ) }
				</ul>
			);
			bullets = null;
		}
	};

	( text || '' ).split( '\n' ).forEach( ( raw ) => {
		const line = raw.replace( /\s+$/, '' );
		if ( line.startsWith( '# ' ) ) {
			flush();
			nodes.push(
				<h4 key={ key++ } className="saddle-doc__h">
					{ line.slice( 2 ) }
				</h4>
			);
		} else if ( line.startsWith( '## ' ) ) {
			flush();
			nodes.push(
				<h5 key={ key++ } className="saddle-doc__h saddle-doc__h--sub">
					{ line.slice( 3 ) }
				</h5>
			);
		} else if ( line.startsWith( '- ' ) ) {
			bullets = bullets || [];
			bullets.push( line.slice( 2 ) );
		} else if ( '' === line ) {
			flush();
		} else {
			flush();
			nodes.push(
				<p key={ key++ } className="saddle-doc__p">
					{ line }
				</p>
			);
		}
	} );
	flush();
	return nodes;
}

/**
 * One field of the context sheet as a row: its name, its text cut to one line
 * (or "Not written yet"), and Edit / Add. Edit opens the textarea under the
 * row; Save keeps it, Cancel drops it.
 *
 * @param {Object}   props
 * @param {Object}   props.field    From FIELDS.
 * @param {string}   props.saved    Saved text.
 * @param {boolean}  props.open     Whether the textarea is open.
 * @param {string}   props.draft    Text in the textarea.
 * @param {boolean}  props.saving   Whether a save is running.
 * @param {Function} props.onOpen   Opens this field.
 * @param {Function} props.onChange Called with the new text.
 * @param {Function} props.onSave   Saves the draft.
 * @param {Function} props.onCancel Closes without saving.
 */
function ContextField( {
	field,
	saved,
	open,
	draft,
	saving,
	onOpen,
	onChange,
	onSave,
	onCancel,
} ) {
	const id = `saddle-field-context-${ field.key }`;
	// Opening a field puts the cursor in it.
	useEffect( () => {
		if ( open ) {
			document.getElementById( id )?.focus();
		}
	}, [ open, id ] );
	const text = saved.replace( /\s+/g, ' ' ).trim();
	return (
		<div className="saddle-context-field">
			<Row
				title={
					<span className="saddle-context-field__label">
						{ field.label }
						<HelpTip>{ field.hint }</HelpTip>
					</span>
				}
				description={
					text ? (
						<span
							className="saddle-context-field__text"
							title={ saved }
						>
							{ text }
						</span>
					) : (
						<span className="saddle-context-field__empty">
							{ __( 'Not written yet', 'saddle' ) }
						</span>
					)
				}
				actions={
					! open && (
						<Button variant="link" size="sm" onClick={ onOpen }>
							{ text
								? __( 'Edit', 'saddle' )
								: __( 'Add', 'saddle' ) }
						</Button>
					)
				}
			/>
			{ open && (
				<div className="saddle-context-field__edit">
					<Textarea
						id={ id }
						value={ draft }
						onChange={ ( e ) => onChange( e.target.value ) }
						rows={ 4 }
						placeholder={ field.placeholder }
						aria-label={ field.label }
					/>
					<div className="saddle-context-field__actions">
						<Button
							variant="secondary"
							size="sm"
							onClick={ onSave }
							loading={ saving }
							disabled={ saving || draft === saved }
						>
							{ __( 'Save', 'saddle' ) }
						</Button>
						<Button
							variant="ghost"
							size="sm"
							onClick={ onCancel }
							disabled={ saving }
						>
							{ __( 'Cancel', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}

export default function Guidance() {
	const confirm = useConfirm();
	const [ system, setSystem ] = useState( '' );
	const [ savedFields, setSavedFields ] = useState( () => parseFields( '' ) );
	// The one field being edited, and what is typed into it so far.
	const [ editing, setEditing ] = useState( null );
	const [ draft, setDraft ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ loadError, setLoadError ] = useState( null );
	const [ showRaw, setShowRaw ] = useState( false );
	const [ skills, setSkills ] = useState( [] );
	const [ drawerSkill, setDrawerSkill ] = useState( null );
	const fileInput = useRef( null );

	useEffect( () => {
		api( 'context' )
			.then( ( res ) => {
				setSystem( res.system || '' );
				setSavedFields( parseFields( res.user ) );
			} )
			.catch( ( e ) => setLoadError( e.message ) )
			.finally( () => setLoading( false ) );
		api( 'skills' )
			.then( ( res ) => setSkills( res.skills || [] ) )
			.catch( () => {} );
	}, [] );

	// The context preview shows the skills index too — refresh it after any
	// skill change so the owner always sees exactly what agents will see.
	const refreshContext = () =>
		api( 'context' )
			.then( ( res ) => setSystem( res.system || '' ) )
			.catch( () => {} );

	const installSkillFile = ( file ) => {
		if ( ! file ) {
			return;
		}
		file.text().then( ( md ) => {
			api( 'skills', { method: 'POST', data: { md } } )
				.then( ( res ) => {
					setSkills( res.skills || [] );
					toast.success( __( 'Skill installed.', 'saddle' ) );
					refreshContext();
				} )
				.catch( ( e ) => toast.error( e.message ) );
		} );
	};

	const toggleSkill = ( skill ) => {
		api( `skills/${ skill.name }`, {
			method: 'POST',
			data: { enabled: ! skill.enabled },
		} )
			.then( ( res ) => {
				setSkills( res.skills || [] );
				refreshContext();
			} )
			.catch( ( e ) => toast.error( e.message ) );
	};

	const removeSkill = async ( skill ) => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: skill name. */
				__( 'Delete the skill “%s”?', 'saddle' ),
				skill.name
			),
			description: __( 'This cannot be undone.', 'saddle' ),
			danger: true,
			confirmLabel: __( 'Delete', 'saddle' ),
			cancelLabel: __( 'Cancel', 'saddle' ),
		} );
		if ( ! ok ) {
			return;
		}
		api( `skills/${ skill.name }`, { method: 'DELETE' } )
			.then( ( res ) => {
				setDrawerSkill( null );
				setSkills( res.skills || [] );
				refreshContext();
			} )
			.catch( ( e ) => toast.error( e.message ) );
	};

	const openField = ( key ) => {
		setEditing( key );
		setDraft( savedFields[ key ] );
	};

	const save = () => {
		setSaving( true );
		api( 'context', {
			method: 'POST',
			data: {
				user: serializeFields( { ...savedFields, [ editing ]: draft } ),
			},
		} )
			.then( ( res ) => {
				setSavedFields( parseFields( res.user ) );
				setEditing( null );
				toast.success( __( 'Saved.', 'saddle' ) );
				refreshContext();
			} )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const systemRegion = {
		role: 'region',
		tabIndex: 0,
		'aria-label': __( 'What your AI is told', 'saddle' ),
	};

	return (
		<div className="saddle-guide saddle-context">
			{ loadError && <Notice tone="danger">{ loadError }</Notice> }

			<section className="saddle-section">
				<SectionHeader title={ __( 'About your site', 'saddle' ) } />
				<RowList>
					{ FIELDS.map( ( field ) => (
						<ContextField
							key={ field.key }
							field={ field }
							saved={ savedFields[ field.key ] }
							open={ editing === field.key }
							draft={ draft }
							saving={ saving }
							onOpen={ () => openField( field.key ) }
							onChange={ setDraft }
							onSave={ save }
							onCancel={ () => setEditing( null ) }
						/>
					) ) }
				</RowList>
			</section>

			{ /* Skills — named playbooks agents load on demand */ }
			<section className="saddle-section">
				<SectionHeader
					title={ __( 'Skills', 'saddle' ) }
					help={ __(
						'Playbook files (.md) that teach your AI a job on this site, such as “how we publish a post” or “our SEO checklist”. Every connected app sees the list and reads a skill when a task matches. Only you can add skills, and a skill never gives an app more access than you set.',
						'saddle'
					) }
					actions={
						<Button
							variant="secondary"
							size="sm"
							onClick={ () => fileInput.current?.click() }
						>
							{ __( 'Add skill', 'saddle' ) }
						</Button>
					}
				/>
				<div>
					<input
						ref={ fileInput }
						type="file"
						accept=".md,text/markdown,text/plain"
						style={ { display: 'none' } }
						onChange={ ( e ) => {
							installSkillFile( e.target.files?.[ 0 ] );
							e.target.value = '';
						} }
					/>
					{ skills.length > 0 ? (
						<RowList>
							{ skills.map( ( skill ) => (
								<Row
									key={ skill.name }
									className="saddle-skill-row"
									title={ skill.name }
									description={
										<span
											className="saddle-skill-row__desc"
											title={ skill.description }
										>
											{ skill.description }
										</span>
									}
									actions={
										<>
											{ /* A bundled skill is provided by a
											     plugin, not stored, so the server
											     cannot toggle or delete it — both
											     calls 404. Show what it is instead
											     of a control that fails. */ }
											{ skill.builtin ? (
												<Badge>
													{ __(
														'Built in',
														'saddle'
													) }
												</Badge>
											) : (
												<Switch
													checked={ skill.enabled }
													onChange={ () =>
														toggleSkill( skill )
													}
													aria-label={ sprintf(
														/* translators: %s: skill name. */
														__(
															'Enable the skill “%s”',
															'saddle'
														),
														skill.name
													) }
												/>
											) }
											<Button
												variant="link"
												size="sm"
												onClick={ () =>
													setDrawerSkill( skill )
												}
											>
												{ __( 'View', 'saddle' ) }
											</Button>
										</>
									}
								/>
							) ) }
						</RowList>
					) : (
						<RowList>
							<Row
								title={
									<span className="saddle-apps__empty">
										{ __( 'No skills yet.', 'saddle' ) }
									</span>
								}
							/>
						</RowList>
					) }
				</div>
			</section>

			<Memory />

			{ /* Read-only, written by Saddle from the site itself — last, and
			     collapsed, because the owner corrects the parts above and only
			     reads this one. */ }
			<section className="saddle-section">
				<SectionHeader
					title={ __( 'What apps see', 'saddle' ) }
					help={ __(
						'Saddle writes this from your site’s pages, design and plugins, and from what each app may do. It stays current on its own, and you can’t edit it here.',
						'saddle'
					) }
				/>
				<Collapsible
					className="saddle-guide__reveal"
					trigger={ __( 'Show what your AI is told', 'saddle' ) }
				>
					{ /* A box that scrolls: a keyboard reaches it as one
					     named stop, so it scrolls with the arrow keys. The
					     exact text wraps, so nothing inside scrolls sideways. */ }
					{ showRaw ? (
						<CodeBlock
							className="saddle-guide__system"
							code={ system }
							wrap
							{ ...systemRegion }
						/>
					) : (
						<div
							className="saddle-doc saddle-guide__system"
							{ ...systemRegion }
						>
							{ renderContext( system ) }
						</div>
					) }
					<Button
						variant="link"
						className="saddle-guide__rawtoggle"
						onClick={ () => setShowRaw( ( v ) => ! v ) }
					>
						{ showRaw
							? __( 'Show readable view', 'saddle' )
							: __( 'View exact text', 'saddle' ) }
					</Button>
				</Collapsible>
			</section>

			{ /* One slide-over shows the full playbook for whichever skill the
			     owner opened — keeps long bodies out of the list. */ }
			<Drawer
				open={ !! drawerSkill }
				onOpenChange={ ( open ) => ! open && setDrawerSkill( null ) }
				title={ drawerSkill?.name }
				// Mounted while closed too; the kit wants a name either way.
				aria-label={ drawerSkill ? undefined : __( 'Skill', 'saddle' ) }
				description={ drawerSkill?.description }
				size="lg"
			>
				{ drawerSkill && (
					<>
						{ /* Wrapped: a line wider than the drawer would
						     scroll sideways where no keyboard can reach. */ }
						<CodeBlock
							code={ drawerSkill.body }
							copy={ false }
							wrap
						/>
						{ ! drawerSkill.builtin && (
							<Button
								variant="link"
								className="saddle-link-danger saddle-guide__delete"
								onClick={ () => removeSkill( drawerSkill ) }
							>
								{ __( 'Delete this skill', 'saddle' ) }
							</Button>
						) }
					</>
				) }
			</Drawer>
		</div>
	);
}
