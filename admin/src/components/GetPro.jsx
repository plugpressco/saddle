/**
 * Saddle → Get Premium (#373, Get Pro until 2026-11-02): Saddle Cloud's
 * Free, Go, Pro and Ultimate plans, one yearly price each, sold by sites
 * since 2026-11-09, after saddle.to's pricing page.
 *
 * Centred: a heading and one line. Then the plans as one table of four
 * cards whose rows line up (subgrid): the plan's icon (Pro wears the crown)
 * and what it is for, the price a year, the sites it covers, the button
 * (Free's to Saddle Cloud with this site filled in, the others to their
 * checkout on saddle.to), the same two numbers on every card (AI agent calls
 * an hour or a month, SEO credits a month), then what it adds. Then four
 * short answers and the money-back line. Every button opens a new tab; the
 * page sends nothing.
 *
 * The one place free Saddle names its paid plans (non-negotiable #3,
 * amended 2026-10-23 and 2026-11-02). Saddle shows it only while Saddle Pro
 * is not installed (`Saddle_Modules::offers_pro()`), and no agent is told
 * about it.
 */
import { Button, VisuallyHidden } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { icons } from '../icons/kit';
import { NavIcon } from '../icons/iconoir';
import { saddleData } from '../api';
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

			<p className="saddle-plan__billed">{ plan.sites }</p>

			<Button
				className="saddle-plan__buy"
				variant={ plan.pro ? 'primary' : 'secondary' }
				href={ plan.href }
				target="_blank"
				rel="noopener noreferrer"
			>
				{ plan.buy }
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
	const plans = planCards( saddleData.homeUrl );

	return (
		<div className="saddle-plans">
			<div className="saddle-plans__intro">
				<h2 className="saddle-plans__title">
					{ __(
						'Build, rank and grow every site from one AI agent',
						'saddle'
					) }
				</h2>
				<p className="saddle-plans__lede">
					{ __(
						'Saddle is free and stays free. Saddle Cloud connects one AI agent to all your sites, free to start.',
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
				{ __(
					'7-day money-back guarantee on every paid plan',
					'saddle'
				) }
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
