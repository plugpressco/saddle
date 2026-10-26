/**
 * Overview's opt-in card, once per site: share usage data with PlugPress,
 * and get Saddle news. Nothing is sent until the owner picks "Allow and
 * continue"; Skip sends nothing. Either answer hides the card for good, and
 * Settings changes it later (insight.js).
 */
import { useState } from '@wordpress/element';
import { Button, Checkbox, Field, Input, Notice } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import {
	answerInsight,
	insightParts,
	insightState,
	plausibleEmail,
	shouldAsk,
} from '../insight';
import { icons } from '../icons/kit';

export default function InsightCard( { onSettings } ) {
	const [ state ] = useState( insightState );
	const [ parts, setParts ] = useState( () => ( { ...state.parts } ) );
	const [ news, setNews ] = useState( false );
	const [ email, setEmail ] = useState( state.email || '' );
	const [ phase, setPhase ] = useState( 'ask' );
	const [ error, setError ] = useState( '' );

	if ( ! shouldAsk( state ) && 'ask' === phase ) {
		return null;
	}

	const emailBad = news && ! plausibleEmail( email );

	const answer = ( choice ) => {
		setPhase( 'busy' );
		setError( '' );
		answerInsight(
			choice,
			'allow' === choice ? parts : undefined,
			'allow' === choice && news ? email : ''
		).then(
			() => setPhase( 'allow' === choice ? 'allowed' : 'skipped' ),
			( e ) => {
				setError(
					e?.message || __( 'Could not save your answer.', 'saddle' )
				);
				setPhase( 'ask' );
			}
		);
	};

	if ( 'skipped' === phase ) {
		return null;
	}

	if ( 'allowed' === phase ) {
		return (
			<section className="saddle-soft-card saddle-insight" role="status">
				<p className="saddle-insight__done">
					<icons.CheckCircle size={ 16 } />
					{ __(
						'Thanks. You can turn this off in Settings.',
						'saddle'
					) }
				</p>
				{ news && (
					<p className="saddle-insight__note">
						{ __(
							'Check your inbox to confirm Saddle news.',
							'saddle'
						) }
					</p>
				) }
			</section>
		);
	}

	const busy = 'busy' === phase;

	return (
		<section
			className="saddle-soft-card saddle-insight"
			aria-labelledby="saddle-insight-title"
		>
			<div className="saddle-insight__head">
				<span
					className="saddle-tile saddle-tile--sky"
					aria-hidden="true"
				>
					<icons.BarChart size={ 20 } />
				</span>
				<div>
					<h2
						className="saddle-soft-card__title"
						id="saddle-insight-title"
					>
						{ __( 'Help make Saddle better', 'saddle' ) }
					</h2>
					<p className="saddle-insight__lede">
						{ __(
							'Share how this site runs Saddle with PlugPress, so we test against what you use. Nothing is sent until you choose Allow.',
							'saddle'
						) }
					</p>
				</div>
			</div>

			<div className="saddle-insight__sends">
				<p className="saddle-insight__always">
					{ __(
						'Always sent: your WordPress and PHP versions, language and timezone, the Saddle plugins you use, and a random id for this site. Then once a week while it is on, only when something changed.',
						'saddle'
					) }
				</p>
				<ul className="saddle-insight__parts">
					{ insightParts().map( ( part ) => (
						<li key={ part.key }>
							<Checkbox
								checked={ !! parts[ part.key ] }
								onChange={ ( e ) =>
									setParts( ( p ) => ( {
										...p,
										[ part.key ]: e.target.checked,
									} ) )
								}
								label={
									<>
										<strong>{ part.label }</strong>
										{ ': ' }
										{ part.detail }
									</>
								}
							/>
						</li>
					) ) }
				</ul>
			</div>

			<div className="saddle-insight__news">
				<Checkbox
					checked={ news }
					onChange={ ( e ) => setNews( e.target.checked ) }
					label={ __( 'Email me Saddle news', 'saddle' ) }
				/>
				{ news && (
					<Field
						label={ __( 'Email', 'saddle' ) }
						hint={ __(
							'About one email a month: new features and tips. You confirm by email first and can leave any time.',
							'saddle'
						) }
						error={
							emailBad
								? __( 'Enter an email address.', 'saddle' )
								: ''
						}
					>
						{ ( a11y ) => (
							<Input
								{ ...a11y }
								type="email"
								autoComplete="email"
								value={ email }
								onChange={ ( e ) => setEmail( e.target.value ) }
							/>
						) }
					</Field>
				) }
			</div>

			<div className="saddle-insight__actions">
				<Button
					variant="primary"
					onClick={ () => answer( 'allow' ) }
					disabled={ busy || emailBad }
					aria-busy={ busy || undefined }
				>
					{ __( 'Allow and continue', 'saddle' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => answer( 'skip' ) }
					disabled={ busy }
				>
					{ __( 'Skip', 'saddle' ) }
				</Button>
				{ onSettings && (
					<Button variant="link" size="sm" onClick={ onSettings }>
						{ __( 'Change it later in Settings', 'saddle' ) }
					</Button>
				) }
			</div>

			{ error && <Notice tone="danger">{ error }</Notice> }
		</section>
	);
}
