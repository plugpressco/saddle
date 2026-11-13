/**
 * Get Premium's plans (#373): Saddle Cloud's Free, Go, Pro and Ultimate, one
 * yearly price each, sold by sites since 2026-11-09 (Go 1 site, Pro up to 3,
 * Ultimate up to 10), as saddle.to's pricing page shows them. The one place free Saddle names its
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
 * Whether Saddle Cloud is connected to this site already: a row from GET
 * /connections that Saddle recognised as Cloud (Saddle_Connection_Apps). The
 * offers stop then.
 *
 * @param {Object[]} rows `connections`.
 * @return {boolean} Connected.
 */
export function connectedToCloud( rows ) {
	return ( rows || [] ).some( ( row ) => 'saddle-cloud' === row.app );
}

/**
 * The plans, smallest first: each includes the one before it. `year` is the
 * price in US dollars, billed once a year; `sites` how many sites it covers;
 * `calls` Free's AI agent calls a month through Saddle Cloud, `hour` a paid
 * plan's AI agent calls an hour (no monthly cap); `credits` the SEO credits a
 * month; `go` the plan's checkout route on saddle.to (the routes keep their
 * old names). Each plan lists what it adds in two to four words (Fahim,
 * 2026-10-28: "not use sentce in table"). Its icon (Pro wears the crown)
 * sits on a tile of its tone. Reach is shown as Ultimate, as on saddle.to;
 * its key and route keep the old word.
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
			job: __( 'Try a SaddleWP account on one site', 'saddle' ),
			year: 0,
			sites: 1,
			calls: 500,
			lead: __( 'What you get', 'saddle' ),
			items: [
				__( 'One AI connection', 'saddle' ),
				__( 'Updates on request', 'saddle' ),
				__( 'Activity log', 'saddle' ),
				__( 'No card needed', 'saddle' ),
			],
		},
		{
			key: 'go',
			icon: 'flash',
			tone: 'sky',
			name: __( 'Go', 'saddle' ),
			job: __( 'For your own website', 'saddle' ),
			year: 49,
			sites: 1,
			hour: 100,
			credits: 500,
			go: 'go-5',
			lead: __( 'Everything in Free, plus', 'saddle' ),
			items: [
				__( 'SaddleWP Pro plugin', 'saddle' ),
				__( 'Updates in one click', 'saddle' ),
				__( 'Uptime alerts', 'saddle' ),
				__( 'Every SEO tool', 'saddle' ),
			],
		},
		{
			key: 'pro',
			icon: 'crown',
			tone: 'pink',
			name: __( 'Pro', 'saddle' ),
			job: __( 'For a few sites, yours or your clients’', 'saddle' ),
			year: 99,
			sites: 3,
			hour: 200,
			credits: 1000,
			go: 'pro-20',
			lead: __( 'Everything in Go, plus', 'saddle' ),
			items: [
				__( 'Up to 3 sites', 'saddle' ),
				__( 'More SEO credits', 'saddle' ),
				__( 'More AI agent calls', 'saddle' ),
			],
		},
		{
			key: 'reach',
			icon: 'graph-up',
			tone: 'mint',
			name: __( 'Ultimate', 'saddle' ),
			job: __( 'For freelancers and small agencies', 'saddle' ),
			year: 199,
			sites: 10,
			hour: 500,
			credits: 2500,
			go: 'reach-50',
			lead: __( 'Everything in Pro, plus', 'saddle' ),
			items: [
				__( 'Up to 10 sites', 'saddle' ),
				__( 'More SEO credits', 'saddle' ),
				__( 'More AI agent calls', 'saddle' ),
			],
		},
	];
}

// A count as saddle.to writes it: 1,000.
const count = ( n ) => n.toLocaleString( 'en-US' );

/**
 * The plan cards: the price a year, the sites it covers, the button (Free's
 * to Saddle Cloud with this site filled in, the others to their checkout on
 * saddle.to), the same two numbers on every card (AI agent calls, SEO credits
 * a month, with `none` set where a plan has no credits), and what it adds.
 *
 * @param {string} home This site's address, for Free's link.
 * @return {Array<Object>} `{ key, icon, tone, name, job, yearly, sites, href,
 *         buy, facts, lead, items, pro, free }`, smallest plan first.
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
			sites:
				1 === p.sites
					? __( '1 site', 'saddle' )
					: sprintf(
							/* translators: %d: a number of sites, such as 3. */
							__( 'Up to %d sites', 'saddle' ),
							p.sites
					  ),
			href: free ? cloudConnectUrl( home ) : `${ STORE }/go/${ p.go }/`,
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
					text: p.hour
						? sprintf(
								/* translators: %s: a number of calls, such as 200. */
								__( '%s AI agent calls an hour', 'saddle' ),
								count( p.hour )
						  )
						: sprintf(
								/* translators: %s: a number of calls, such as 500. */
								__( '%s AI agent calls a month', 'saddle' ),
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
				'No. SaddleWP is free on every site, and your SaddleWP account’s Free plan is free on one. Paid plans add what runs for you: updates, uptime, SEO and SaddleWP Pro.',
				'saddle'
			),
		},
		{
			q: __( 'What is an AI agent call?', 'saddle' ),
			a: __(
				'One step your AI agent takes on a site through your SaddleWP account. A site you connect to your AI agent directly is never counted.',
				'saddle'
			),
		},
		{
			q: __( 'What is an SEO credit?', 'saddle' ),
			a: __(
				'Credits pay for Google data on every paid plan. A keyword search uses about 15. Unused credits carry over for a month.',
				'saddle'
			),
		},
		{
			q: __( 'Can I change or cancel?', 'saddle' ),
			a: __(
				'Yes, from your SaddleWP account. Moving up, you pay the difference. Every paid plan has a 7-day money-back guarantee.',
				'saddle'
			),
		},
		{
			q: __( 'I run more than 10 sites', 'saddle' ),
			a: __(
				'Write to team@saddle.to and we will set up a plan for the sites and SEO credits you need.',
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
