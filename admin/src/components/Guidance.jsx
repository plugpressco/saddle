/**
 * Context — what every connected app knows about this site (#274).
 *
 * Was Settings → Guidance. Laid out like a context sheet an owner fills in
 * and corrects, top to bottom:
 *  - Named fields for the owner's instructions: about the site, the current
 *    goal, voice, rules. Stored as sections of the one instructions text
 *    (see context-fields.js), which every agent reads.
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
	Card,
	CardContent,
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
// A card title with an optional "?" help affordance beside it — keeps the long
// explanation off the page while staying one hover/tap away. Rides the DS
// HelpTip (content via children; 14px icon).
function Heading( { children, help } ) {
	return (
		<span className="saddle-guide__heading">
			{ children }
			{ help && <HelpTip>{ help }</HelpTip> }
		</span>
	);
}

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
 * One field of the context sheet: its name, a line on what belongs there, and
 * an example as the placeholder. "Not saved" shows only while it is true.
 *
 * @param {Object}   props
 * @param {Object}   props.field    From FIELDS.
 * @param {string}   props.value    Current text.
 * @param {string}   props.saved    Saved text.
 * @param {Function} props.onChange Called with the new text.
 */
function ContextField( { field, value, saved, onChange } ) {
	const id = `saddle-field-context-${ field.key }`;
	const unsaved = value !== saved;
	return (
		<div className="saddle-context-field">
			<div className="saddle-context-field__head">
				<label htmlFor={ id } className="saddle-context-field__label">
					{ field.label }
				</label>
				{ unsaved && (
					<span className="saddle-context-field__state">
						{ __( 'Not saved', 'saddle' ) }
					</span>
				) }
			</div>
			<p className="saddle-context-field__hint">{ field.hint }</p>
			<Textarea
				id={ id }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				rows={ 3 }
				placeholder={ field.placeholder }
			/>
		</div>
	);
}

export default function Guidance() {
	const confirm = useConfirm();
	const [ system, setSystem ] = useState( '' );
	const [ fields, setFields ] = useState( () => parseFields( '' ) );
	const [ savedFields, setSavedFields ] = useState( () => parseFields( '' ) );
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
				setFields( parseFields( res.user ) );
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
				setSkills( res.skills || [] );
				refreshContext();
			} )
			.catch( ( e ) => toast.error( e.message ) );
	};

	const save = () => {
		setSaving( true );
		api( 'context', {
			method: 'POST',
			data: { user: serializeFields( fields ) },
		} )
			.then( ( res ) => {
				setFields( parseFields( res.user ) );
				setSavedFields( parseFields( res.user ) );
				toast.success( __( 'Context saved.', 'saddle' ) );
				refreshContext();
			} )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const dirty = FIELDS.some(
		( f ) => fields[ f.key ] !== savedFields[ f.key ]
	);

	return (
		<div className="saddle-guide saddle-context">
			{ loadError && <Notice tone="danger">{ loadError }</Notice> }

			<section className="saddle-section">
				<SectionHeader title={ __( 'Instructions', 'saddle' ) } />
				<Card>
					<CardContent className="saddle-context__fields">
						{ FIELDS.map( ( field ) => (
							<ContextField
								key={ field.key }
								field={ field }
								value={ fields[ field.key ] }
								saved={ savedFields[ field.key ] }
								onChange={ ( text ) =>
									setFields( ( prev ) => ( {
										...prev,
										[ field.key ]: text,
									} ) )
								}
							/>
						) ) }
						<div className="saddle-guide__actions">
							<Button
								variant="primary"
								onClick={ save }
								loading={ saving }
								disabled={ saving || ! dirty }
							>
								{ __( 'Save changes', 'saddle' ) }
							</Button>
						</div>
					</CardContent>
				</Card>
			</section>

			{ /* Skills — named playbooks agents load on demand */ }
			<section className="saddle-section">
				<SectionHeader
					title={
						<Heading
							help={ __(
								'Playbook files (.md) that teach your AI specific jobs on this site — “how we publish a post”, “our SEO checklist.” Every connected AI sees the list and reads one when a task matches. Only you can add them, and a skill can never grant more access than the level you chose.',
								'saddle'
							) }
						>
							{ __( 'Skills', 'saddle' ) }
						</Heading>
					}
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
									title={ skill.name }
									description={ skill.description }
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
														'Bundled',
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
												onClick={ () =>
													setDrawerSkill( skill )
												}
											>
												{ __( 'View', 'saddle' ) }
											</Button>
											{ ! skill.builtin && (
												<Button
													variant="link"
													className="saddle-link-danger"
													onClick={ () =>
														removeSkill( skill )
													}
												>
													{ __( 'Delete', 'saddle' ) }
												</Button>
											) }
										</>
									}
								/>
							) ) }
						</RowList>
					) : (
						<p className="saddle-context__empty">
							{ __( 'No skills yet.', 'saddle' ) }
						</p>
					) }
				</div>
			</section>

			<Memory />

			{ /* Read-only, written by Saddle from the site itself — last, and
			     collapsed, because the owner corrects the parts above and only
			     reads this one. */ }
			<section className="saddle-section">
				<SectionHeader
					title={
						<Heading
							help={ __(
								'Saddle writes this from your site and its active plugins and keeps it current: its pages, design, plugins and what each app may do. It’s shown for transparency; you don’t edit it here.',
								'saddle'
							) }
						>
							{ __( 'What apps see', 'saddle' ) }
						</Heading>
					}
				/>
				<Collapsible
					className="saddle-guide__reveal"
					trigger={ __( 'Show what your AI is told', 'saddle' ) }
				>
					{ showRaw ? (
						<CodeBlock
							className="saddle-guide__system"
							code={ system }
						/>
					) : (
						<div className="saddle-doc saddle-guide__system">
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
				description={ drawerSkill?.description }
				size="lg"
			>
				{ drawerSkill && (
					<CodeBlock code={ drawerSkill.body } copy={ false } />
				) }
			</Drawer>
		</div>
	);
}
