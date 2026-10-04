/**
 * Pure helpers for Skills on Context (P14): which skill a file names, so an
 * upload can ask before it replaces one, and the file an edit in place
 * saves. No React, no network. The server (Saddle_Skills::parse) stays the
 * judge of what a valid skill is.
 */

/**
 * A name as WordPress's sanitize_title() would store it, for the plain names
 * skills use: lower case, spaces to dashes, nothing but a-z, 0-9, _ and -.
 *
 * @param {string} raw Name from the frontmatter.
 * @return {string} Slug.
 */
export function skillSlug( raw ) {
	return String( raw || '' )
		.normalize( 'NFKD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.trim()
		.replace( /\s+/g, '-' )
		.replace( /[^a-z0-9_-]/g, '' )
		.replace( /-+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

/**
 * The name a skill file gives itself in its frontmatter, as stored.
 *
 * @param {string} md The file's text.
 * @return {string} The name, or '' when the file names none.
 */
export function skillName( md ) {
	const text = String( md || '' )
		.replace( /\r\n/g, '\n' )
		.trim();
	const match = text.match( /^---\n([\s\S]*?)\n---/ );
	if ( ! match ) {
		return '';
	}
	for ( const line of match[ 1 ].split( '\n' ) ) {
		const kv = line.trim().match( /^name\s*:\s*(.+)$/i );
		if ( kv ) {
			return skillSlug( kv[ 1 ] );
		}
	}
	return '';
}

/**
 * The installed skill an upload would replace: an owner skill of the same
 * name, or a built-in one it would stand in for. Null when the name is new.
 *
 * @param {Array}  skills From GET /skills.
 * @param {string} md     The uploaded file's text.
 * @return {?Object} The skill.
 */
export function skillToReplace( skills, md ) {
	const name = skillName( md );
	if ( ! name ) {
		return null;
	}
	return (
		( Array.isArray( skills ) ? skills : [] ).find(
			( s ) => s && s.name === name
		) || null
	);
}

/**
 * The file an edit in place saves: the skill's own frontmatter (name,
 * description, when to use) over the new text. POST /skills with it updates
 * the skill of that name in place.
 *
 * @param {Object} skill The skill being edited.
 * @param {string} body  Its new text.
 * @return {string} The file.
 */
export function skillMarkdown( skill, body ) {
	const one = ( v ) =>
		String( v || '' )
			.replace( /\s+/g, ' ' )
			.trim();
	const lines = [
		'---',
		`name: ${ one( skill.name ) }`,
		`description: ${ one( skill.description ) }`,
	];
	if ( one( skill.when_to_use ) ) {
		lines.push( `when_to_use: ${ one( skill.when_to_use ) }` );
	}
	lines.push( '---', '', String( body || '' ).trim(), '' );
	return lines.join( '\n' );
}
