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
	TextareaControl,
	ToggleControl,
	Button,
	Notice,
	Spinner,
} from '../ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import Memory from './Memory';

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
	const [ system, setSystem ] = useState( '' );
	const [ user, setUser ] = useState( '' );
	const [ savedUser, setSavedUser ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ showRaw, setShowRaw ] = useState( false );
	const [ skills, setSkills ] = useState( [] );
	const [ openSkill, setOpenSkill ] = useState( null );
	const fileInput = useRef( null );

	useEffect( () => {
		api( 'context' )
			.then( ( res ) => {
				setSystem( res.system || '' );
				setUser( res.user || '' );
				setSavedUser( res.user || '' );
			} )
			.catch( ( e ) =>
				setNotice( { type: 'error', message: e.message } )
			)
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
					setNotice( {
						type: 'success',
						message: __( 'Skill installed.', 'saddle' ),
					} );
					refreshContext();
				} )
				.catch( ( e ) =>
					setNotice( { type: 'error', message: e.message } )
				);
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
			.catch( ( e ) =>
				setNotice( { type: 'error', message: e.message } )
			);
	};

	const removeSkill = ( skill ) => {
		api( `skills/${ skill.name }`, { method: 'DELETE' } )
			.then( ( res ) => {
				setSkills( res.skills || [] );
				refreshContext();
			} )
			.catch( ( e ) =>
				setNotice( { type: 'error', message: e.message } )
			);
	};

	const save = () => {
		setSaving( true );
		setNotice( null );
		api( 'context', { method: 'POST', data: { user } } )
			.then( ( res ) => {
				setUser( res.user || '' );
				setSavedUser( res.user || '' );
				setNotice( {
					type: 'success',
					message: __( 'Instructions saved.', 'saddle' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { type: 'error', message: e.message } )
			)
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	const dirty = user !== savedUser;

	return (
		<div className="saddle-guide">
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<h2 className="saddle-guide__title">
				{ __( 'How your AI should behave', 'saddle' ) }
			</h2>
			<p className="saddle-guide__lead">
				{ __(
					'Every connected AI is told the same things about your site and follows the same instructions from you.',
					'saddle'
				) }
			</p>

			{ /* Read-only, auto-generated */ }
			<section className="saddle-guide__block">
				<div className="saddle-guide__head">
					<h3>{ __( 'What your AI knows', 'saddle' ) }</h3>
					<span className="saddle-guide__badge">
						{ __( 'Automatic · read-only', 'saddle' ) }
					</span>
				</div>
				<p className="saddle-guide__hint">
					{ __(
						'Saddle writes this from your site and its active plugins, and updates it automatically. It’s shown so you can see exactly what your AI is told — you don’t edit it here.',
						'saddle'
					) }
				</p>
				{ showRaw ? (
					<pre className="saddle-code saddle-guide__system">
						{ system }
					</pre>
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
			</section>

			{ /* Editable owner instructions */ }
			<section className="saddle-guide__block">
				<div className="saddle-guide__head">
					<h3>{ __( 'Your instructions', 'saddle' ) }</h3>
				</div>
				<p className="saddle-guide__hint">
					{ __(
						'Add rules or preferences for every connected AI. For example: “Always save new posts as drafts,” or “Write in a warm, friendly tone.” Leave blank if you have none.',
						'saddle'
					) }
				</p>
				<TextareaControl
					label={ __( 'Your instructions', 'saddle' ) }
					hideLabelFromVision
					value={ user }
					onChange={ setUser }
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
						isBusy={ saving }
						disabled={ saving || ! dirty }
					>
						{ __( 'Save instructions', 'saddle' ) }
					</Button>
				</div>
			</section>

			{ /* Skills — named playbooks agents load on demand */ }
			<section className="saddle-guide__block">
				<div className="saddle-guide__head">
					<h3>{ __( 'Skills', 'saddle' ) }</h3>
				</div>
				<p className="saddle-guide__hint">
					{ __(
						'Skills are playbook files (.md) that teach your AI how to do specific jobs on this site — “how we publish a post”, “our SEO checklist”. Every connected AI sees the list of skills and reads one when a task matches. Only you can add them; a skill can never grant your AI more access than the level you chose.',
						'saddle'
					) }
				</p>

				{ skills.length > 0 && (
					<ul className="saddle-skills">
						{ skills.map( ( skill ) => (
							<li
								key={ skill.name }
								className="saddle-skills__row"
							>
								<div className="saddle-skills__main">
									<strong>{ skill.name }</strong>
									<span className="saddle-skills__desc">
										{ skill.description }
									</span>
								</div>
								<div className="saddle-skills__controls">
									<ToggleControl
										__nextHasNoMarginBottom
										checked={ skill.enabled }
										onChange={ () => toggleSkill( skill ) }
										label={ __( 'On', 'saddle' ) }
									/>
									<Button
										variant="link"
										onClick={ () =>
											setOpenSkill(
												openSkill === skill.name
													? null
													: skill.name
											)
										}
									>
										{ openSkill === skill.name
											? __( 'Hide', 'saddle' )
											: __( 'View', 'saddle' ) }
									</Button>
									<Button
										variant="link"
										isDestructive
										onClick={ () => {
											if (
												// eslint-disable-next-line no-alert
												window.confirm(
													sprintf(
														/* translators: %s: skill name. */
														__(
															'Delete the skill “%s”? This cannot be undone.',
															'saddle'
														),
														skill.name
													)
												)
											) {
												removeSkill( skill );
											}
										} }
									>
										{ __( 'Delete', 'saddle' ) }
									</Button>
								</div>
								{ openSkill === skill.name && (
									<pre className="saddle-code saddle-skills__body">
										{ skill.body }
									</pre>
								) }
							</li>
						) ) }
					</ul>
				) }

				<div className="saddle-guide__actions">
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
					<Button
						variant="secondary"
						onClick={ () => fileInput.current?.click() }
					>
						{ __( 'Add skill (.md file)', 'saddle' ) }
					</Button>
				</div>
			</section>

			{ /* Memory — governed cross-session store; pinning changes the
			     injected context, so refresh the preview above on change. */ }
			<Memory onChanged={ refreshContext } />
		</div>
	);
}
