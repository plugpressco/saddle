/**
 * The owner's instructions as named fields, for the Context page (#274).
 *
 * They are stored where they always were: the one `saddle_user_context`
 * text, which reaches every agent under "## Site owner's instructions". Each
 * field is a `### Heading` section inside it, so the agent reads the same
 * labels the owner filled in, and nothing about storage or the agent's
 * context changes shape.
 *
 * Headings are stored in English whatever the admin's language, because
 * they are what the agent reads and what the page parses back. Text under
 * no known heading (everything written before this page existed) is kept
 * word for word in "Other instructions".
 */
import { __ } from '@wordpress/i18n';

export const FIELDS = [
	{
		key: 'about',
		heading: 'About this site',
		label: __( 'About this site', 'saddle' ),
		hint: __(
			'What the site is, who it is for, and what they come to do.',
			'saddle'
		),
		placeholder: __(
			'e.g. A bakery in Leeds. Most visitors want the opening hours, the menu, or to order a cake.',
			'saddle'
		),
	},
	{
		key: 'goal',
		heading: 'Current goal',
		label: __( 'Current goal', 'saddle' ),
		hint: __(
			'What you are working toward right now, and by when.',
			'saddle'
		),
		placeholder: __(
			'e.g. More cake orders before December. The new Shop pages come first.',
			'saddle'
		),
	},
	{
		key: 'voice',
		heading: 'Voice and style',
		label: __( 'Voice and style', 'saddle' ),
		hint: __(
			'How the writing should sound, and words to avoid.',
			'saddle'
		),
		placeholder: __(
			'e.g. Warm and short. British spelling. Never say “artisanal”.',
			'saddle'
		),
	},
	{
		key: 'rules',
		heading: 'Rules',
		label: __( 'Rules', 'saddle' ),
		hint: __(
			'What your AI must always or never do on this site.',
			'saddle'
		),
		placeholder: __(
			'e.g. Save new posts as drafts for me to review. Never edit the homepage.',
			'saddle'
		),
	},
	{
		key: 'other',
		heading: 'Other instructions',
		label: __( 'Other instructions', 'saddle' ),
		hint: __(
			'Anything else every connected app should follow.',
			'saddle'
		),
		placeholder: '',
	},
];

const HEADING = /^###\s+(.+?)\s*$/;

/**
 * Split the stored instructions into the named fields.
 *
 * @param {string} text The stored instructions.
 * @return {Object} Field key → text.
 */
export function parseFields( text ) {
	const values = {};
	const keyFor = {};
	FIELDS.forEach( ( f ) => {
		values[ f.key ] = '';
		keyFor[ f.heading.toLowerCase() ] = f.key;
	} );

	// Text before any known heading is what was written before this page
	// existed; it belongs to "Other instructions".
	let current = 'other';
	let buffer = [];
	const flush = () => {
		const body = buffer.join( '\n' ).trim();
		if ( body ) {
			values[ current ] = values[ current ]
				? `${ values[ current ] }\n\n${ body }`
				: body;
		}
		buffer = [];
	};

	String( text || '' )
		.split( '\n' )
		.forEach( ( line ) => {
			const match = line.match( HEADING );
			const key = match && keyFor[ match[ 1 ].toLowerCase() ];
			if ( key ) {
				flush();
				current = key;
				return;
			}
			// An unknown heading stays part of the text it sits in.
			buffer.push( line );
		} );
	flush();

	return values;
}

/**
 * Join the fields back into the stored instructions. Empty fields leave no
 * heading behind.
 *
 * @param {Object} values Field key → text.
 * @return {string} The instructions to store.
 */
export function serializeFields( values ) {
	return FIELDS.map( ( f ) => {
		const body = String( ( values && values[ f.key ] ) || '' ).trim();
		return body ? `### ${ f.heading }\n${ body }` : '';
	} )
		.filter( Boolean )
		.join( '\n\n' );
}
