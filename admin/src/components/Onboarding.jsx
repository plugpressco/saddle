/**
 * First-run setup — two calm steps: Welcome → Choose a safety level — then it
 * hands straight into the connect wizard, so the first session ends with the
 * user's AI actually talking to their site, not with more settings.
 */
import { useState } from '@wordpress/element';
import { Button, Notice } from '../ui';
import { __ } from '@wordpress/i18n';
import { api, LEVELS } from '../api';
import { LevelIcon, BrandMark } from './icons';

export default function Onboarding( { tier, onTierSaved, onFinish } ) {
	const [ step, setStep ] = useState( 1 );
	const [ choice, setChoice ] = useState(
		tier === 'write' ? 'write' : 'read'
	);
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	// Save the chosen level, then hand off — into the connect wizard by
	// default, or straight to Home if they'd rather connect later.
	const saveLevel = ( { connect } ) => {
		setSaving( true );
		setError( null );
		api( 'settings', { method: 'POST', data: { tier: choice } } )
			.then( ( res ) => {
				onTierSaved( res.tier );
				onFinish( { connect } );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<div className="saddle-setup">
			<div className="saddle-setup__head">
				<span className="saddle-setup__mark" aria-hidden="true">
					<BrandMark />
				</span>
				<span className="saddle-setup__kicker">
					{ __( 'Set up Saddle', 'saddle' ) }
				</span>
				<span className="saddle-setup__steps">
					{ /* translators: 1: current step, 2: total steps. */ }
					{ `${ step } / 2` }
				</span>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ step === 1 && (
				<div className="saddle-setup__body">
					<h1 className="saddle-setup__title">
						{ __(
							'Let an AI help with your content — safely.',
							'saddle'
						) }
					</h1>
					<p className="saddle-setup__lead">
						{ __(
							'Saddle lets an AI assistant like Claude read and (if you allow it) edit your posts, pages, and media. You stay in control of what it can touch, everything runs on your own site, and nothing is ever deleted without asking you first.',
							'saddle'
						) }
					</p>
					<ul className="saddle-promises">
						<li>{ __( 'You choose what it can do', 'saddle' ) }</li>
						<li>
							{ __( 'Nothing leaves your website', 'saddle' ) }
						</li>
						<li>
							{ __( 'Deleting always asks first', 'saddle' ) }
						</li>
					</ul>
					<div className="saddle-setup__actions">
						<Button
							variant="primary"
							onClick={ () => setStep( 2 ) }
						>
							{ __( 'Get started', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }

			{ step === 2 && (
				<div className="saddle-setup__body">
					<h1 className="saddle-setup__title">
						{ __( 'How much should your AI help?', 'saddle' ) }
					</h1>
					<p className="saddle-setup__lead">
						{ __( 'You can change this anytime.', 'saddle' ) }
					</p>

					<div
						className="saddle-levels"
						role="radiogroup"
						aria-label={ __( 'Safety level', 'saddle' ) }
					>
						{ LEVELS.map( ( lvl ) => (
							<button
								key={ lvl.key }
								type="button"
								role="radio"
								aria-checked={ choice === lvl.key }
								className={ `saddle-levelcard${
									choice === lvl.key ? ' is-active' : ''
								}` }
								onClick={ () => setChoice( lvl.key ) }
							>
								<span className="saddle-levelcard__glyph">
									<LevelIcon name={ lvl.icon } />
								</span>
								<span className="saddle-levelcard__title">
									{ lvl.title }
								</span>
								<span className="saddle-levelcard__desc">
									{ lvl.short }
								</span>
								{ lvl.recommended && (
									<span className="saddle-levelcard__rec">
										{ __(
											'Recommended to start',
											'saddle'
										) }
									</span>
								) }
							</button>
						) ) }
					</div>

					<div className="saddle-setup__actions">
						<Button
							variant="tertiary"
							onClick={ () => setStep( 1 ) }
							disabled={ saving }
						>
							{ __( 'Back', 'saddle' ) }
						</Button>
						<Button
							variant="link"
							onClick={ () => saveLevel( { connect: false } ) }
							disabled={ saving }
						>
							{ __( 'Finish without connecting', 'saddle' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => saveLevel( { connect: true } ) }
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Continue — connect an app', 'saddle' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
