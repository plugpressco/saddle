/**
 * Saddle → Get Pro (#373; clearer the same day; flat pricing 2026-10-27):
 * Saddle Cloud's Go, Pro and Reach plans, one yearly price each, after
 * saddle.to's pricing page.
 *
 * Centred: a heading and one line. Then the plans as one table of three
 * cards whose rows line up (subgrid): the plan's icon (Pro wears the crown)
 * and what it is for, the price a year, the sites it includes, the button
 * to that plan's checkout on saddle.to, the same two numbers on every card
 * (AI-app calls a day, SEO credits a month), then the four things it adds. Then four short answers and the money-back
 * line. Every button opens saddle.to in a new tab; the page sends nothing.
 *
 * The one place free Saddle names its paid plans (non-negotiable #3,
 * amended 2026-10-23). Saddle shows it only while Saddle Pro is not installed
 * (`Saddle_Modules::offers_pro()`), and no agent is told about it.
 */
import { Button, VisuallyHidden } from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { icons } from '../icons/kit';
import { NavIcon } from '../icons/iconoir';
import { STORE, planCards, price, questions } from '../get-pro-logic';

// Said once for every link that leaves the page.
const NEW_TAB = __( '(opens in a new tab)', 'saddle' );

/**
 * One plan, as a column of the table.
 *
 * @param {Object} props
 * @param {Object} props.plan From planCards().
 */
function Plan( { plan } ) {
	const id = `saddle-plan-${ plan.key }`;
	return (
		<section
			className={ `saddle-plan${ plan.pro ? ' is-pro' : '' }` }
			aria-labelledby={ id }
		>
			<div className="saddle-plan__head">
				<span
					className={ `saddle-tile saddle-tile--${ plan.tone }` }
					aria-hidden="true"
				>
					<NavIcon name={ plan.icon } size={ 22 } />
				</span>
				<div className="saddle-plan__name">
					<h2 className="saddle-plan__title" id={ id }>
						{ plan.name }
					</h2>
					<p className="saddle-plan__job">{ plan.job }</p>
				</div>
			</div>

			<p className="saddle-plan__price">
				<span className="saddle-plan__amount">
					{ price( plan.yearly ) }
				</span>
				<span className="saddle-plan__per">
					{ __( '/ year', 'saddle' ) }
				</span>
			</p>

			<p className="saddle-plan__billed">
				{ sprintf(
					/* translators: %d: a number of sites, such as 20. */
					__( '%d sites included', 'saddle' ),
					plan.sites
				) }
			</p>

			<Button
				className="saddle-plan__buy"
				variant={ plan.pro ? 'primary' : 'secondary' }
				href={ plan.href }
				target="_blank"
				rel="noopener noreferrer"
			>
				{ sprintf(
					/* translators: %s: a plan's name, such as Pro. */
					__( 'Choose %s', 'saddle' ),
					plan.name
				) }
				<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
			</Button>

			<ul className="saddle-plan__facts">
				{ plan.facts.map( ( f ) => (
					<li key={ f.key } className={ f.none ? 'is-none' : '' }>
						{ f.text }
					</li>
				) ) }
			</ul>

			<div className="saddle-plan__adds">
				<p className="saddle-plan__lead">{ plan.lead }</p>
				<ul className="saddle-plan__items">
					{ plan.items.map( ( item ) => (
						<li key={ item }>
							<icons.Check
								size={ 16 }
								className="saddle-plan__tick"
							/>
							{ item }
						</li>
					) ) }
				</ul>
			</div>
		</section>
	);
}

export default function GetPro() {
	const plans = planCards();

	return (
		<div className="saddle-plans">
			<div className="saddle-plans__intro">
				<h2 className="saddle-plans__title">
					{ __( 'One AI connection for all your sites', 'saddle' ) }
				</h2>
				<p className="saddle-plans__lede">
					{ __(
						'Saddle is free and stays free. Pick a plan to work on every site from one AI app.',
						'saddle'
					) }
				</p>
			</div>

			<div className="saddle-plans__table">
				{ plans.map( ( plan ) => (
					<Plan key={ plan.key } plan={ plan } />
				) ) }
			</div>

			<p className="saddle-plans__refund">
				<icons.ShieldCheck size={ 16 } />
				{ __( '7-day money-back guarantee on every plan', 'saddle' ) }
			</p>

			<section className="saddle-plans__faq">
				<h2 className="saddle-plans__faq-title">
					{ __( 'Questions', 'saddle' ) }
				</h2>
				<dl className="saddle-plans__answers">
					{ questions().map( ( item ) => (
						<div key={ item.q } className="saddle-plans__answer">
							<dt>{ item.q }</dt>
							<dd>{ item.a }</dd>
						</div>
					) ) }
				</dl>
				<a
					className="saddle-plans__compare"
					href={ `${ STORE }/pricing/` }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Compare every detail on saddle.to', 'saddle' ) }
					<VisuallyHidden>{ ` ${ NEW_TAB }` }</VisuallyHidden>
				</a>
			</section>
		</div>
	);
}
