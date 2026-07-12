/**
 * Guidance — how your AI should behave.
 *
 * Three parts, in plain terms:
 *  - "What your AI knows" — the read-only context Saddle writes automatically
 *    from your site and active plugins, shown for transparency.
 *  - "Your instructions" — free text you write for every connected AI.
 *  - "Skills" — named playbook files (.md) you install; every AI sees the
 *    list and reads a playbook when a task matches it.
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	Card,
	CardHeader,
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
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import Memory from './Memory';
import HelpTip from './HelpTip';

// A card title with an optional "?" help affordance beside it — keeps the long
// explanation off the page while staying one hover/tap away.
function Heading( { children, help } ) {
	return (
		<span className="saddle-guide__heading">
			{ children }
			{ help && <HelpTip label={ help } /> }
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

export default function Guidance() {
	const confirm = useConfirm();
	const [ system, setSystem ] = useState( '' );
	const [ user, setUser ] = useState( '' );
	const [ savedUser, setSavedUser ] = useState( '' );
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
				setUser( res.user || '' );
				setSavedUser( res.user || '' );
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
		api( 'context', { method: 'POST', data: { user } } )
			.then( ( res ) => {
				setUser( res.user || '' );
				setSavedUser( res.user || '' );
				toast.success( __( 'Instructions saved.', 'saddle' ) );
			} )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const dirty = user !== savedUser;

	return (
		<div className="saddle-guide">
			{ loadError && <Notice tone="danger">{ loadError }</Notice> }

			<h2 className="saddle-guide__title">
				{ __( 'How your AI should behave', 'saddle' ) }
			</h2>
			<p className="saddle-guide__lead">
				{ __(
					'Every connected AI is told the same things about your site and follows the same instructions from you.',
					'saddle'
				) }
			</p>

			{ /* Read-only, auto-generated — kept collapsed so it never crowds
			     the page; the detail lives one click away. */ }
			<Card className="saddle-guide__block">
				<CardHeader
					title={
						<Heading
							help={ __(
								'Saddle writes this from your site and its active plugins and keeps it current. It’s shown for transparency — you don’t edit it here.',
								'saddle'
							) }
						>
							{ __( 'What your AI knows', 'saddle' ) }
						</Heading>
					}
					actions={
						<Badge>
							{ __( 'Automatic · read-only', 'saddle' ) }
						</Badge>
					}
				/>
				<CardContent>
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
				</CardContent>
			</Card>

			{ /* Editable owner instructions */ }
			<Card className="saddle-guide__block">
				<CardHeader
					title={
						<Heading
							help={ __(
								'Rules or preferences every connected AI follows — e.g. “Always save new posts as drafts,” or “Write in a warm, friendly tone.” Leave blank if you have none.',
								'saddle'
							) }
						>
							{ __( 'Your instructions', 'saddle' ) }
						</Heading>
					}
				/>
				<CardContent>
					<Textarea
						aria-label={ __( 'Your instructions', 'saddle' ) }
						value={ user }
						onChange={ ( e ) => setUser( e.target.value ) }
						rows={ 6 }
						placeholder={ __(
							'e.g. Always save new posts as drafts for me to review.',
							'saddle'
						) }
					/>
					<div className="saddle-guide__actions">
						<Button
							variant="primary"
							onClick={ save }
							loading={ saving }
							disabled={ saving || ! dirty }
						>
							{ __( 'Save instructions', 'saddle' ) }
						</Button>
					</div>
				</CardContent>
			</Card>

			{ /* Skills — named playbooks agents load on demand */ }
			<Card className="saddle-guide__block">
				<CardHeader
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
				<CardContent>
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
						<RowList className="saddle-rows">
							{ skills.map( ( skill ) => (
								<Row
									key={ skill.name }
									title={ skill.name }
									description={ skill.description }
									actions={
										<>
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
											<Button
												variant="link"
												onClick={ () =>
													setDrawerSkill( skill )
												}
											>
												{ __( 'View', 'saddle' ) }
											</Button>
											<Button
												variant="link"
												className="saddle-link-danger"
												onClick={ () =>
													removeSkill( skill )
												}
											>
												{ __( 'Delete', 'saddle' ) }
											</Button>
										</>
									}
								/>
							) ) }
						</RowList>
					) : (
						<p className="saddle-card__empty">
							{ __(
								'No skills yet. Add a .md playbook to teach your AI a repeatable job.',
								'saddle'
							) }
						</p>
					) }
				</CardContent>
			</Card>

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

			{ /* Memory — governed cross-session store; pinning changes the
			     injected context, so refresh the preview above on change. */ }
			<Memory onChanged={ refreshContext } />
		</div>
	);
}
