/**
 * Get Pro's plans (#373): Saddle Cloud's Go, Pro and Reach, one yearly price
 * each with its sites included, as saddle.to's pricing page shows them
 * (flat pricing, 2026-10-27). The one place free Saddle names its paid plans
 * (non-negotiable #3, amended 2026-10-23). No React, no network: the prices
 * live here, so a price change on saddle.to needs a Saddle update. Keep them
 * equal to saddle-cloud's `src/shared/plans.ts` and the theme's pricing
 * pattern.
 */
import { __, sprintf } from '@wordpress/i18n';

/** Where every plan is bought, and compared. */
export const STORE = 'https://saddle.to';

/**
 * The plans, smallest first: each includes the one before it. `year` is the
 * price in US dollars, billed once a year; `calls` the AI-app calls a day
 * through Saddle Cloud; `credits` the SEO credits a month (Reach only).
 * Every plan lists four things it adds, so the cards line up. Its icon (Pro
 * wears the crown) sits on a tile of its tone.
 *
 * @return {Array<Object>} Plans.
 */
function plans() {
	return [
		{
			key: 'go',
			icon: 'flash',
			tone: 'sky',
			name: __( 'Go', 'saddle' ),
			job: __(
				'Updates, uptime and SEO actions on every site',
				'saddle'
			),
			year: 49,
			sites: 5,
			calls: 150,
			lead: __( 'What you get', 'saddle' ),
			items: [
				__( 'Updates on every site', 'saddle' ),
				__( 'Uptime and health alerts', 'saddle' ),
				__( 'SEO actions from Search Console', 'saddle' ),
				__( 'Keyword tracking from Search Console', 'saddle' ),
			],
		},
		{
			key: 'pro',
			icon: 'crown',
			tone: 'pink',
			name: __( 'Pro', 'saddle' ),
			job: __( 'Divi and WooCommerce for your AI app', 'saddle' ),
			year: 99,
			sites: 20,
			calls: 3000,
			lead: __( 'Everything in Go, plus', 'saddle' ),
			items: [
				__( 'Saddle Pro on every site', 'saddle' ),
				__( 'Whole Divi pages in one step', 'saddle' ),
				__( 'WooCommerce bulk edits', 'saddle' ),
				__( 'Undo for site-wide changes', 'saddle' ),
			],
		},
		{
			key: 'reach',
			icon: 'graph-up',
			tone: 'mint',
			name: __( 'Reach', 'saddle' ),
			job: __(
				'Grow your audience: research, competitors and weekly checks',
				'saddle'
			),
			year: 249,
			sites: 50,
			calls: 4000,
			credits: 1000,
			lead: __( 'Everything in Pro, plus', 'saddle' ),
			items: [
				__( 'Keyword research with Google data', 'saddle' ),
				__( 'Weekly Google checks with AI Overviews', 'saddle' ),
				__( 'Competitors checked every week', 'saddle' ),
				__( 'Article drafts your AI writes', 'saddle' ),
			],
		},
	];
}

// A count as saddle.to writes it: 1,000.
const count = ( n ) => n.toLocaleString( 'en-US' );

/**
 * The plan cards: the price a year, the sites it includes, the checkout
 * link, the same two numbers on every card (AI-app calls a day, SEO credits
 * a month, with `none` set where a plan has no credits), and the four things
 * it adds.
 *
 * @return {Array<Object>} `{ key, icon, tone, name, job, yearly, sites,
 *         href, facts, lead, items, pro }`, smallest plan first.
 */
export function planCards() {
	return plans().map( ( p ) => {
		const credits = p.credits || 0;
		return {
			key: p.key,
			icon: p.icon,
			tone: p.tone,
			name: p.name,
			job: p.job,
			yearly: p.year,
			sites: p.sites,
			href: `${ STORE }/go/${ p.key }-${ p.sites }/`,
			facts: [
				{
					key: 'calls',
					text: sprintf(
						/* translators: %s: a number of calls, such as 3,000. */
						__( '%s AI-app calls a day', 'saddle' ),
						count( p.calls )
					),
				},
				{
					key: 'credits',
					none: ! credits,
					text: credits
						? sprintf(
								/* translators: %s: a number of SEO credits, such as 1,000. */
								__( '%s SEO credits a month', 'saddle' ),
								count( credits )
						  )
						: __( 'No SEO credits', 'saddle' ),
				},
			],
			lead: p.lead,
			items: p.items,
			pro: 'pro' === p.key,
		};
	} );
}

/**
 * Get Pro's short answers, under the cards: the four questions people ask
 * before they choose, in saddle.to's words.
 *
 * @return {Array<{q:string,a:string}>} Questions and answers.
 */
export function questions() {
	return [
		{
			q: __( 'Do I need a plan?', 'saddle' ),
			a: __(
				'No. Saddle is free on every site. A plan lets one AI app work on all your sites through Saddle Cloud.',
				'saddle'
			),
		},
		{
			q: __( 'What is an AI-app call?', 'saddle' ),
			a: __(
				'One request your AI app sends through Saddle Cloud. A site you connect to your AI app directly is never counted.',
				'saddle'
			),
		},
		{
			q: __( 'What is an SEO credit?', 'saddle' ),
			a: __(
				'Credits pay for Google data on Reach. A keyword search uses about 15. Unused credits carry over for a month.',
				'saddle'
			),
		},
		{
			q: __( 'Can I change or cancel?', 'saddle' ),
			a: __(
				'Yes, from your Saddle Cloud account. Moving up, you pay the difference. Every plan has a 7-day money-back guarantee.',
				'saddle'
			),
		},
	];
}

/**
 * A price in US dollars, whole when it is whole: "$49", "$8.50".
 *
 * @param {number} usd Dollars.
 * @return {string} Price.
 */
export function price( usd ) {
	const amount = Number.isInteger( usd ) ? String( usd ) : usd.toFixed( 2 );
	return sprintf(
		/* translators: %s: an amount in US dollars, such as 49. */
		__( '$%s', 'saddle' ),
		amount
	);
}
