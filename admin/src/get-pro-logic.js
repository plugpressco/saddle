/**
 * Get Pro's plans (#373): Saddle Cloud's Go, Pro and Reach, each for 5, 20
 * or 50 sites, as saddle.to's pricing page shows them. The one place free
 * Saddle names its paid plans (non-negotiable #3, amended 2026-10-23). No
 * React, no network: the prices live here, so a price change on saddle.to
 * needs a Saddle update. Keep them equal to saddle-cloud's
 * `src/shared/plans.ts` and the theme's pricing pattern.
 */
import { __, sprintf } from '@wordpress/i18n';

/** The sizes every plan is sold in, by sites. */
export const SIZES = [ 5, 20, 50 ];

/** The size the page opens on. */
export const FIRST_SIZE = 20;

/** Where every plan is bought, and compared. */
export const STORE = 'https://saddle.to';

/**
 * The plans, smallest first: each includes the one before it. `year` is the
 * price in US dollars, billed once a year; `calls` the AI-app calls a day
 * through Saddle Cloud; `credits` the SEO credits a month (Reach only); all
 * by size. Every plan lists four things it adds, so the cards line up. Its
 * icon (Pro wears the crown) sits on a tile of its tone.
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
			job: __( 'Keep your sites updated and online', 'saddle' ),
			year: { 5: 60, 20: 144, 50: 300 },
			calls: { 5: 250, 20: 1000, 50: 2500 },
			lead: __( 'What you get', 'saddle' ),
			items: [
				__( 'Updates on every site', 'saddle' ),
				__( 'Uptime and health alerts', 'saddle' ),
				__( 'Search Console and site audit', 'saddle' ),
				__( 'Email support', 'saddle' ),
			],
		},
		{
			key: 'pro',
			icon: 'crown',
			tone: 'pink',
			name: __( 'Pro', 'saddle' ),
			job: __( 'Build with Divi and WooCommerce', 'saddle' ),
			year: { 5: 108, 20: 228, 50: 468 },
			calls: { 5: 2000, 20: 5000, 50: 10000 },
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
			job: __( 'Get found on Google and in AI answers', 'saddle' ),
			year: { 5: 228, 20: 468, 50: 948 },
			calls: { 5: 2000, 20: 5000, 50: 10000 },
			credits: { 5: 2000, 20: 4500, 50: 9000 },
			lead: __( 'Everything in Pro, plus', 'saddle' ),
			items: [
				__( 'Keyword research with Google data', 'saddle' ),
				__( 'Weekly rankings and AI Overviews', 'saddle' ),
				__( 'Competitors checked every week', 'saddle' ),
				__( 'Article drafts your AI writes', 'saddle' ),
			],
		},
	];
}

// A count as saddle.to writes it: 4,500.
const count = ( n ) => n.toLocaleString( 'en-US' );

/**
 * The plan cards for one size: the price a month (the yearly price over
 * twelve, as saddle.to shows it) and a year, the checkout link, the same
 * three numbers on every card (sites, AI-app calls a day, SEO credits a
 * month, with `none` set where a plan has no credits), and the four things
 * it adds. An unknown size falls back to the first size.
 *
 * @param {number} size 5, 20 or 50.
 * @return {Array<Object>} `{ key, icon, tone, name, job, monthly, yearly,
 *         href, facts, lead, items, pro }`, smallest plan first.
 */
export function planCards( size ) {
	const sites = SIZES.includes( size ) ? size : FIRST_SIZE;
	return plans().map( ( p ) => {
		const yearly = p.year[ sites ];
		const credits = p.credits ? p.credits[ sites ] : 0;
		return {
			key: p.key,
			icon: p.icon,
			tone: p.tone,
			name: p.name,
			job: p.job,
			monthly: yearly / 12,
			yearly,
			href: `${ STORE }/go/${ p.key }-${ sites }/`,
			facts: [
				{
					key: 'sites',
					text: sprintf(
						/* translators: %d: a number of sites. */
						__( '%d sites', 'saddle' ),
						sites
					),
				},
				{
					key: 'calls',
					text: sprintf(
						/* translators: %s: a number of calls, such as 5,000. */
						__( '%s AI-app calls a day', 'saddle' ),
						count( p.calls[ sites ] )
					),
				},
				{
					key: 'credits',
					none: ! credits,
					text: credits
						? sprintf(
								/* translators: %s: a number of SEO credits, such as 4,500. */
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
 * A price in US dollars, whole when it is whole: "$19", "$8.50".
 *
 * @param {number} usd Dollars.
 * @return {string} Price.
 */
export function price( usd ) {
	const amount = Number.isInteger( usd ) ? String( usd ) : usd.toFixed( 2 );
	return sprintf(
		/* translators: %s: an amount in US dollars, such as 19. */
		__( '$%s', 'saddle' ),
		amount
	);
}
