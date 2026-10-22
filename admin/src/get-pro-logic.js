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
 * price in US dollars, billed once a year, by size.
 *
 * @return {Array<Object>} Plans.
 */
function plans() {
	return [
		{
			key: 'go',
			name: __( 'Go', 'saddle' ),
			job: __( 'Keep every site updated and online.', 'saddle' ),
			year: { 5: 60, 20: 144, 50: 300 },
			lead: __( 'What you get:', 'saddle' ),
			items: [
				__( 'One AI connection for all your sites', 'saddle' ),
				__(
					'Plugin, theme and WordPress updates on every site',
					'saddle'
				),
				__( 'Uptime monitoring and Site Health alerts', 'saddle' ),
				__( 'Google Search Console and Site Audit', 'saddle' ),
				__( 'Email support', 'saddle' ),
			],
		},
		{
			key: 'pro',
			name: __( 'Pro', 'saddle' ),
			job: __( 'Build with Divi and WooCommerce.', 'saddle' ),
			year: { 5: 108, 20: 228, 50: 468 },
			lead: __( 'Everything in Go, plus:', 'saddle' ),
			items: [
				__( 'Saddle Pro on every site', 'saddle' ),
				__( 'Build or copy a whole Divi page in one step', 'saddle' ),
				__( 'Divi design system, Library and Theme Builder', 'saddle' ),
				__( 'Pages held to your design brief', 'saddle' ),
				__(
					'Site-wide changes and WooCommerce bulk edits, with undo',
					'saddle'
				),
			],
		},
		{
			key: 'reach',
			name: __( 'Reach', 'saddle' ),
			job: __( 'Get found on Google and in AI answers.', 'saddle' ),
			year: { 5: 228, 20: 468, 50: 948 },
			credits: { 5: 2000, 20: 4500, 50: 9000 },
			lead: __( 'Everything in Pro, plus:', 'saddle' ),
			items: [
				__( 'Keyword research with live Google data', 'saddle' ),
				__( 'Weekly rankings, with Google’s AI Overview', 'saddle' ),
				__( 'Competitors checked every week', 'saddle' ),
				__( 'Article plans your AI writes as drafts', 'saddle' ),
				__( 'Credit packs when you need more', 'saddle' ),
			],
		},
	];
}

/**
 * The plan cards for one size: the price a month (the yearly price over
 * twelve, as saddle.to shows it), the price a year, the checkout link, and
 * what the plan adds. Reach leads its list with the month's SEO credits. An
 * unknown size falls back to the first size.
 *
 * @param {number} size 5, 20 or 50.
 * @return {Array<Object>} `{ key, name, job, monthly, yearly, href, lead,
 *         items, pro }`, smallest plan first.
 */
export function planCards( size ) {
	const sites = SIZES.includes( size ) ? size : FIRST_SIZE;
	return plans().map( ( p ) => {
		const yearly = p.year[ sites ];
		const items = p.credits
			? [
					sprintf(
						/* translators: %s: a number of SEO credits, such as 4,500. */
						__( '%s SEO credits a month', 'saddle' ),
						p.credits[ sites ].toLocaleString( 'en-US' )
					),
					...p.items,
			  ]
			: p.items;
		return {
			key: p.key,
			name: p.name,
			job: p.job,
			monthly: yearly / 12,
			yearly,
			href: `${ STORE }/go/${ p.key }-${ sites }/`,
			lead: p.lead,
			items,
			pro: 'pro' === p.key,
		};
	} );
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
