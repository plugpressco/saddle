/**
 * Settings → Usage data: the opt-in switch and Saddle news, for anyone who
 * answered Overview's card and wants to change it, or skipped it. Turning
 * usage data off tells PlugPress to forget what identifies this site.
 */
import { useState } from '@wordpress/element';
import {
	Button,
	Field,
	Input,
	Row,
	RowList,
	Switch,
	toast,
} from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { answerInsight, insightState, plausibleEmail } from '../insight';
import SectionHeader from './SectionHeader';

export default function InsightSettings() {
	const [ state, setState ] = useState( insightState );
	const [ busy, setBusy ] = useState( false );
	const [ email, setEmail ] = useState( state.email || '' );
	const [ sent, setSent ] = useState( false );

	if ( ! state.enabled ) {
		return null;
	}

	const on = 'in' === state.state;

	const flip = () => {
		setBusy( true );
		answerInsight( on ? 'off' : 'allow', on ? undefined : state.parts )
			.then( ( res ) => {
				setState( ( s ) => ( { ...s, ...res } ) );
				toast.success(
					on
						? __( 'Usage data is off.', 'saddle' )
						: __( 'Usage data is on.', 'saddle' )
				);
			} )
			.catch( ( e ) =>
				toast.error(
					e?.message || __( 'Could not save that setting.', 'saddle' )
				)
			)
			.finally( () => setBusy( false ) );
	};

	const subscribe = () => {
		setBusy( true );
		answerInsight( 'news', undefined, email )
			.then( ( res ) => {
				if ( res.news ) {
					setSent( true );
				} else {
					toast.error(
						__(
							'Saddle news could not be reached. Try again later.',
							'saddle'
						)
					);
				}
			} )
			.catch( ( e ) =>
				toast.error(
					e?.message || __( 'Could not subscribe.', 'saddle' )
				)
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<section className="saddle-section" id="usage-data">
			<SectionHeader title={ __( 'Usage data and news', 'saddle' ) } />
			<RowList>
				<Row
					title={ __( 'Share usage data with PlugPress', 'saddle' ) }
					description={ __(
						'Your WordPress and PHP versions, language, timezone and the Saddle plugins you use, once a week when something changed. Off sends nothing.',
						'saddle'
					) }
					actions={
						<Switch
							id="saddle-insight-switch"
							checked={ on }
							disabled={ busy }
							onChange={ flip }
							aria-label={ __(
								'Share usage data with PlugPress',
								'saddle'
							) }
						/>
					}
				/>
				<Row
					title={ __( 'Saddle news', 'saddle' ) }
					description={
						sent
							? __( 'Check your inbox to confirm.', 'saddle' )
							: __(
									'About one email a month: new features and tips. Only the address you type is sent.',
									'saddle'
							  )
					}
					actions={
						sent ? null : (
							<div className="saddle-insight__subscribe">
								<Field label={ __( 'Email', 'saddle' ) }>
									{ ( a11y ) => (
										<Input
											{ ...a11y }
											type="email"
											autoComplete="email"
											value={ email }
											onChange={ ( e ) =>
												setEmail( e.target.value )
											}
										/>
									) }
								</Field>
								<Button
									variant="secondary"
									onClick={ subscribe }
									disabled={
										busy || ! plausibleEmail( email )
									}
								>
									{ __( 'Subscribe', 'saddle' ) }
								</Button>
							</div>
						)
					}
				/>
			</RowList>
		</section>
	);
}
