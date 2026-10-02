/**
 * Pure helpers for Home (#309): the week at a glance, the prompts worth
 * trying on this site, and the plugins the apps can work inside. No React,
 * no network.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { parseEntryDate } from './activity-format';

const WEEK = 7 * 24 * 60 * 60 * 1000;

/**
 * How many of `entries` happened in the last seven days, and whether the
 * page held more than it could count.
 *
 * Entries come newest first from GET /audit-log; `date` is the log's own
 * "Y-m-d H:i:s" in UTC.
 *
 * @param {Object[]} entries One page of the log, newest first.
 * @param {number}   perPage The page size asked for.
 * @param {number}   now     Milliseconds, for tests.
 * @return {{count:number, more:boolean}} `more` when every entry on a full
 *         page is inside the week, so the real number is higher.
 */
export function weekCount( entries, perPage, now = Date.now() ) {
	const list = Array.isArray( entries ) ? entries : [];
	const inWeek = list.filter( ( e ) => {
		const d = parseEntryDate( e && e.date );
		return !! d && now - d.getTime() <= WEEK;
	} );
	return {
		count: inWeek.length,
		more: inWeek.length > 0 && inWeek.length === perPage,
	};
}

/**
 * A count for a stat tile: "12", or "100+" when the page was full.
 *
 * @param {{count:number, more:boolean}} c From weekCount().
 * @return {string} Text.
 */
export function countText( c ) {
	return c.more ? `${ c.count }+` : String( c.count );
}

/**
 * Prompts worth trying on this site, from what first-look found: the work it
 * noticed first, then safe everyday ones. Each is a whole prompt the owner
 * copies into their app; none of them changes anything without showing the
 * owner first.
 *
 * @param {?Object} look GET /first-look, or null.
 * @param {number}  max  How many to return.
 * @return {Array<{key:string,title:string,prompt:string}>} Ideas.
 */
export function askIdeas( look, max = 3 ) {
	const findings = ( look && look.findings ) || {};
	const updates = ( look && look.updates ) || {};
	const ideas = [];

	if ( findings.missing_alt > 0 ) {
		ideas.push( {
			key: 'alt',
			title: sprintf(
				/* translators: %d: number of images. */
				_n(
					'Add alt text to %d image',
					'Add alt text to %d images',
					findings.missing_alt,
					'saddle'
				),
				findings.missing_alt
			),
			prompt: __(
				'Find the images on this site that have no alt text, and suggest a short, accurate alt text for each. Show me the list before you change anything.',
				'saddle'
			),
		} );
	}
	if ( findings.missing_description > 0 ) {
		ideas.push( {
			key: 'descriptions',
			title: sprintf(
				/* translators: %d: number of pages and posts. */
				_n(
					'Write %d search description',
					'Write %d search descriptions',
					findings.missing_description,
					'saddle'
				),
				findings.missing_description
			),
			prompt: __(
				'List the pages and posts that have no search description, and write one for each in under 155 characters. Show me before you save them.',
				'saddle'
			),
		} );
	}
	const waiting = ( updates.plugins || 0 ) + ( updates.themes || 0 );
	if ( waiting > 0 ) {
		ideas.push( {
			key: 'updates',
			title: sprintf(
				/* translators: %d: number of updates. */
				_n(
					'Review %d update',
					'Review %d updates',
					waiting,
					'saddle'
				),
				waiting
			),
			prompt: __(
				'Tell me which plugins and themes have updates waiting and what changed in each. Don’t update anything yet.',
				'saddle'
			),
		} );
	}

	ideas.push(
		{
			key: 'links',
			title: __( 'Check for broken links', 'saddle' ),
			prompt: __(
				'Look through my pages and posts for broken links and tell me what you find. Don’t change anything.',
				'saddle'
			),
		},
		{
			key: 'tour',
			title: __( 'Get a tour of your site', 'saddle' ),
			prompt: __(
				'Tell me about this WordPress site: what it is about, its main pages, and three things you would improve first. Don’t change anything.',
				'saddle'
			),
		},
		{
			key: 'draft',
			title: __( 'Draft a post', 'saddle' ),
			prompt: __(
				'Draft a short blog post for this site about a topic you think my readers would like, in the voice of my existing posts. Save it as a draft and tell me its title.',
				'saddle'
			),
		}
	);

	return ideas.slice( 0, Math.max( 0, max ) );
}

/**
 * The plugins on this site the apps can work inside, for "Works with": the
 * Services page's plugin and add-on records that are on, with tools.
 *
 * @param {Array} records From GET /services.
 * @return {Array<Object>} The records, plugins first.
 */
export function worksWith( records ) {
	const list = Array.isArray( records ) ? records : [];
	const on = ( r ) =>
		r &&
		( 'plugin' === r.kind || 'addon' === r.kind ) &&
		( 'active' === r.status ||
			'detected' === r.status ||
			'ready' === r.status ) &&
		( r.tool_count || 0 ) > 0;
	return [
		...list.filter( ( r ) => on( r ) && 'plugin' === r.kind ),
		...list.filter( ( r ) => on( r ) && 'addon' === r.kind ),
	];
}
