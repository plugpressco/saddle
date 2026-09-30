/**
 * The logic behind Settings → Advanced → "Turn off single tools", kept apart
 * from the markup so it can be tested on its own: which tools match a search,
 * how they group by area, and what switching one changes.
 *
 * A tool is one entry of `GET capabilities`: `{ short, label, description,
 * category, ... }`.
 */

/**
 * Whether a tool matches a free-text search over its name, id and
 * description. An empty search matches every tool.
 *
 * @param {Object} tool  Tool.
 * @param {string} query Search text.
 * @return {boolean} Whether it matches.
 */
export function toolMatches( tool, query ) {
	const q = String( query || '' )
		.trim()
		.toLowerCase();
	if ( ! q ) {
		return true;
	}
	return [ tool.label, tool.short, tool.description ].some( ( text ) =>
		String( text || '' )
			.toLowerCase()
			.includes( q )
	);
}

/**
 * The tools that match a search, grouped by area. Areas sort by name with
 * `fallback` (tools with no area) last; tools sort by label inside an area.
 *
 * @param {Array}  tools    All tools.
 * @param {string} query    Search text.
 * @param {string} fallback Name for tools that have no area.
 * @return {Array<{category: string, tools: Array}>} Non-empty groups.
 */
export function groupTools( tools, query, fallback = 'Other' ) {
	const map = new Map();
	( tools || [] )
		.filter( ( t ) => toolMatches( t, query ) )
		.forEach( ( t ) => {
			const key = t.category || fallback;
			if ( ! map.has( key ) ) {
				map.set( key, [] );
			}
			map.get( key ).push( t );
		} );

	return [ ...map.entries() ]
		.map( ( [ category, list ] ) => ( {
			category,
			tools: [ ...list ].sort( ( a, b ) =>
				String( a.label ).localeCompare( String( b.label ) )
			),
		} ) )
		.sort( ( a, b ) => {
			if ( a.category === fallback || b.category === fallback ) {
				return a.category === fallback ? 1 : -1;
			}
			return a.category.localeCompare( b.category );
		} );
}

/**
 * The short names of the tools the owner has switched off.
 *
 * @param {Array} tools All tools.
 * @return {Set<string>} Short names.
 */
export const disabledSet = ( tools ) =>
	new Set(
		( tools || [] )
			.filter( ( t ) => false === t.enabled )
			.map( ( t ) => t.short )
	);

/**
 * The set after switching one tool, without touching the original.
 *
 * @param {Set<string>} set   Switched-off short names.
 * @param {string}      short Tool to flip.
 * @return {Set<string>} New set.
 */
export function flipTool( set, short ) {
	const next = new Set( set );
	if ( next.has( short ) ) {
		next.delete( short );
	} else {
		next.add( short );
	}
	return next;
}
