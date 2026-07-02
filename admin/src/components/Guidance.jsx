/**
 * Guidance — how your AI should behave.
 *
 * Two parts, in plain terms:
 *  - "What your AI knows" — the read-only context Saddle writes automatically
 *    from your site and active plugins, shown for transparency.
 *  - "Your instructions" — free text you write for every connected AI.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	TextareaControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

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
	}, [] );

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
		</div>
	);
}
