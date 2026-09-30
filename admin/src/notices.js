/**
 * Which notice goes under the page header (#276).
 *
 * Pure, so it is unit-tested. A notice is the shape Saddle_Notices sends in
 * `saddleData.notices` (`{ id, severity, message, action, dismiss }`). The
 * app's own client-side notices use the same shape, with an `action` that may
 * carry `onClick` instead of `url`.
 */

// Most severe first. Matches Saddle_Notices::SEVERITIES.
export const SEVERITIES = [ 'error', 'warning', 'info', 'success' ];

// Severity → design-system Notice tone.
export const TONES = {
	error: 'danger',
	warning: 'warning',
	info: 'info',
	success: 'success',
};

const rank = ( notice ) => {
	const at = SEVERITIES.indexOf( notice && notice.severity );
	return at < 0 ? SEVERITIES.length : at;
};

/**
 * Split notices into the one the frame shows and the ones for the bell.
 *
 * The most severe wins; among equals the earlier one in the list does, so the
 * caller puts the app's own (live) notices first. Every notice is kept: the
 * rest keep their relative order.
 *
 * @param {Array<Object>} notices Client-side notices, then server notices.
 * @return {{ slot: Object|null, rest: Array<Object> }} The slot and the rest.
 */
export function pickSlot( notices ) {
	const list = ( Array.isArray( notices ) ? notices : [] ).filter(
		( n ) => n && n.id && n.message
	);
	if ( ! list.length ) {
		return { slot: null, rest: [] };
	}

	let best = 0;
	list.forEach( ( notice, i ) => {
		if ( rank( notice ) < rank( list[ best ] ) ) {
			best = i;
		}
	} );

	return {
		slot: list[ best ],
		rest: list.filter( ( _, i ) => i !== best ),
	};
}
