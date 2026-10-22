/**
 * Saddle → Get Pro (#373): Saddle Cloud's Go, Pro and Reach plans for 5, 20
 * or 50 sites, after saddle.to's pricing page. A size picker, three cards
 * (the price a month, billed yearly, the button to that plan's checkout on
 * saddle.to, what the plan adds), and the money-back line. Every button
 * opens saddle.to in a new tab; the page itself sends nothing anywhere.
 *
 * The one place free Saddle names its paid plans (non-negotiable #3,
 * amended 2026-10-23). Saddle shows it only while Saddle Pro is not active
 * (`Saddle_Modules::offers_pro()`), and no agent is told about it.
 */
import { useState } from '@wordpress/element';
import { Button, SegmentedControl, VisuallyHidden } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { icons } from '../icons/kit';
import { FIRST_SIZE, SIZES, STORE, planCards, price } from '../get-pro-logic';

// Said once for every link that leaves the page.
const NEW_TAB = __( '(opens in a new tab)', 'saddle' );

export default function GetPro() {
	const [ size, setSize ] = useState( String( FIRST_SIZE ) );
	const cards = planCards( Number( size ) );

	return (
		<div className="saddle-plans">
			<div className="saddle-plans__head">
				<p className="saddle-plans__lede">
					{ __(
						'Saddle stays free. A plan adds Saddle Cloud, one AI connection for all your sites, and Saddle Pro on Pro and Reach.',
						'saddle'
					) }
				</p>
				<SegmentedControl
					className="saddle-plans__sizes"
					aria-label={ __( 'How many sites', 'saddle' ) }
					options={ SIZES.map( ( s ) => ( {
						value: String( s ),
						label: sprintf(
							/* translators: %d: a number of sites. */
							__( '%d sites', 'saddle' ),
							s
						),
					} ) ) }
					value={ size }
					onChange={ setSize }
				/>
			</div>

			<div className="saddle-plans__cards">
				{ cards.map( ( c ) => (
					<section
						key={ c.key }
						className={ `saddle-soft-card saddle-plan${
							c.pro ? ' is-pro' : ''
						}` }
						aria-labelledby={ `saddle-plan-${ c.key }` }
					>
						<div className="saddle-plan__head">
							<h2
								className="saddle-soft-card__title"
								id={ `saddle-plan-${ c.key }` }
							>
								{ c.name }
							</h2>
							{ c.pro && (
								<span className="saddle-chip saddle-chip--pink">
									{ __( 'Saddle Pro', 'saddle' ) }
								</span>
							) }
						</div>
						<p className="saddle-plan__job">{ c.job }</p>
						<p className="saddle-plan__price">
							<span className="saddle-plan__amount">
								{ price( c.monthly ) }
							</span>
							<span className="saddle-plan__per">
								{ __( 'a month', 'saddle' ) }
							</span>
						</p>
						<p className="saddle-plan__billed">
							{ sprintf(
								/* translators: %s: the price for a year, such as $228. */
								__( 'Billed yearly, %s a year', 'saddle' ),
								price( c.yearly )
							) }
						</p>
						<Button
							className="saddle-plan__buy"
							variant={ c.pro ? 'primary' : 'secondary' }
							href={ c.href }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ sprintf(
								/* translators: %s: a plan's name, such as Pro. */
								__( 'Choose %s', 'saddle' ),
								c.name
							) }
							<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
						</Button>
						<p className="saddle-plan__lead">{ c.lead }</p>
						<ul className="saddle-plan__items">
							{ c.items.map( ( item ) => (
								<li key={ item }>
									<icons.Check
										size={ 16 }
										className="saddle-plan__tick"
									/>
									{ item }
								</li>
							) ) }
						</ul>
					</section>
				) ) }
			</div>

			<p className="saddle-plans__foot">
				<span className="saddle-plans__refund">
					<icons.ShieldCheck size={ 16 } />
					{ __( '7-day money-back guarantee', 'saddle' ) }
				</span>
				<a
					href={ `${ STORE }/pricing/` }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Compare plans on saddle.to', 'saddle' ) }
					<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
				</a>
			</p>
		</div>
	);
}
