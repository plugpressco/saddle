/**
 * Get Premium's plans (#373; Free to $299, 2026-11-02): Saddle Cloud's Free,
 * Go, Pro and Reach, one yearly price each with unlimited sites, as
 * saddle.to's pricing page shows them. The one place free Saddle names its
 * paid plans (non-negotiable #3, amended 2026-10-23 and 2026-11-02). No
 * React, no network: the prices live here, so a price change on saddle.to
 * needs a Saddle update. Keep them equal to saddle-cloud's
 * `src/shared/plans.ts` and the theme's pricing pattern.
 */
import { __, sprintf } from '@wordpress/i18n';

/** Where every paid plan is bought, and compared. */
export const STORE = 'https://saddle.to';

/** Saddle Cloud, where Free starts. */
export const CLOUD = 'https://cloud.saddle.to';

/**
 * Saddle Cloud's Connect a site page with this site's address filled in. A
 * plain link: the person signs up or signs in there, then this site shows
 * its own Allow screen. Saddle sends nothing to Cloud.
 *
 * @param {string} home This site's address.
 * @return {string} The link.
 */
export function cloudConnectUrl( home ) {
	const site = String( home || '' ).replace( /\/+$/, '' );
	return site
		? `${ CLOUD }/connect?site=${ encodeURIComponent( site ) }`
		: `${ CLOUD }/connect`;
}

/**
 * The plans, smallest first: each includes the one before it. `year` is the
 * price in US dollars, billed once a year; `calls` the AI agent calls a
 * month through Saddle Cloud; `credits` the SEO credits a month (Pro and
 * Reach); `go` the plan's checkout route on saddle.to (the sizes the Polar
 * links were made for; every plan has unlimited sites now). Every plan lists
 * four things it adds, two or three words each, so the cards line up
 * (Fahim, 2026-10-28: "not use sentce in table"). Its icon (Pro wears the
 * crown) sits on a tile of its tone.
 *
 * @return {Array<Object>} Plans.
 */
function plans() {
	return [
		{
			key: 'free',
			icon: 'globe',
			tone: 'lilac',
			name: __( 'Free', 'saddle' ),
			job: __( 'One AI connection for every site you run', 'saddle' ),
			year: 0,
			calls: 500,
			lead: __( 'What you get', 'saddle' ),
			items: [
				__( 'One AI connection', 'saddle' ),
				__( 'Unlimited sites', 'saddle' ),
				__( 'Updates on request', 'saddle' ),
				__( 'Activity log', 'saddle' ),
			],
		},
		{
			key: 'go',
			icon: 'flash',
			tone: 'sky',
			name: __( 'Go', 'saddle' ),
			job: __(
				'Build and run: updates, uptime and SEO actions',
				'saddle'
			),
			year: 49,
			calls: 10000,
			go: 'go-5',
			lead: __( 'Everything in Free, plus', 'saddle' ),
			items: [
				__( 'Site updates', 'saddle' ),
				__( 'Uptime monitoring', 'saddle' ),
				__( 'Site Health alerts', 'saddle' ),
				__( 'SEO actions', 'saddle' ),
			],
		},
		{
			key: 'pro',
			icon: 'crown',
			tone: 'pink',
			name: __( 'Pro', 'saddle' ),
			job: __(
				'Build, rank and grow: SEO research, Divi and WooCommerce',
				'saddle'
			),
			year: 99,
			calls: 50000,
			credits: 200,
			go: 'pro-20',
			lead: __( 'Everything in Go, plus', 'saddle' ),
			items: [
				__( 'Keyword tracking', 'saddle' ),
				__( 'Keyword research', 'saddle' ),
				__( 'Saddle Pro plugin', 'saddle' ),
				__( 'Whole Divi pages', 'saddle' ),
			],
		},
		{
			key: 'reach',
			icon: 'graph-up',
			tone: 'mint',
			name: __( 'Reach', 'saddle' ),
			job: __(
				'For agencies: ten times the SEO credits of Pro',
				'saddle'
			),
			year: 299,
			calls: 250000,
			credits: 2000,
			go: 'reach-50',
			lead: __( 'Everything in Pro, plus', 'saddle' ),
			items: [
				__( '10x SEO credits', 'saddle' ),
				__( '5x AI agent calls', 'saddle' ),
				__( 'Priority support', 'saddle' ),
				__( 'Made for agencies', 'saddle' ),
			],
		},
	];
}

// A count as saddle.to writes it: 1,000.
const count = ( n ) => n.toLocaleString( 'en-US' );

/**
 * The plan cards: the price a year, the button (Free's to Saddle Cloud with
 * this site filled in, the others to their checkout on saddle.to), the same
 * two numbers on every card (AI agent calls a month, SEO credits a month,
 * with `none` set where a plan has no credits), and the four things it adds.
 *
 * @param {string} home This site's address, for Free's link.
 * @return {Array<Object>} `{ key, icon, tone, name, job, yearly, href, buy,
 *         facts, lead, items, pro, free }`, smallest plan first.
 */
export function planCards( home = '' ) {
	return plans().map( ( p ) => {
		const credits = p.credits || 0;
		const free = 0 === p.year;
		return {
			key: p.key,
			icon: p.icon,
			tone: p.tone,
			name: p.name,
			job: p.job,
			yearly: p.year,
			href: free
				? cloudConnectUrl( home )
				: `${ STORE }/go/${ p.go }/`,
			buy: free
				? __( 'Start free', 'saddle' )
				: sprintf(
						/* translators: %s: a plan's name, such as Pro. */
						__( 'Choose %s', 'saddle' ),
						p.name
				  ),
			facts: [
				{
					key: 'calls',
					text: sprintf(
						/* translators: %s: a number of calls, such as 10,000. */
						__( '%s AI agent calls a month', 'saddle' ),
						count( p.calls )
					),
				},
				{
					key: 'credits',
					none: ! credits,
					text: credits
						? sprintf(
								/* translators: %s: a number of SEO credits, such as 2,000. */
								__( '%s SEO credits a month', 'saddle' ),
								count( credits )
						  )
						: __( 'No SEO credits', 'saddle' ),
				},
			],
			lead: p.lead,
			items: p.items,
			pro: 'pro' === p.key,
			free,
		};
	} );
}

/**
 * Get Premium's short answers, under the cards: the four questions people
 * ask before they choose, in saddle.to's words.
 *
 * @return {Array<{q:string,a:string}>} Questions and answers.
 */
export function questions() {
	return [
		{
			q: __( 'Do I need a plan?', 'saddle' ),
			a: __(
				'No. Saddle is free on every site, and so is Saddle Cloud’s Free plan. Paid plans add what runs for you: updates, uptime, SEO and Saddle Pro.',
				'saddle'
			),
		},
		{
			q: __( 'What is an AI agent call?', 'saddle' ),
			a: __(
				'One step your AI agent takes on a site through Saddle Cloud. A site you connect to your AI agent directly is never counted.',
				'saddle'
			),
		},
		{
			q: __( 'What is an SEO credit?', 'saddle' ),
			a: __(
				'Credits pay for Google data on Pro and Reach. A keyword search uses about 15. Unused credits carry over for a month.',
				'saddle'
			),
		},
		{
			q: __( 'Can I change or cancel?', 'saddle' ),
			a: __(
				'Yes, from your Saddle Cloud account. Moving up, you pay the difference. Every paid plan has a 7-day money-back guarantee.',
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
