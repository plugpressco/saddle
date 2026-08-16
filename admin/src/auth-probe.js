/**
 * Ask the server which of our credentials actually reached it.
 *
 * Used only when an admin REST call comes back 401, which means WordPress
 * resolved no user at all. Several very different things produce that one
 * status code, and they need opposite fixes — a stripped nonce header is
 * Saddle's problem to work around, a stripped login cookie is the host's.
 *
 * Deliberately a raw `fetch` rather than `apiFetch`: this must not pass through
 * the nonce middleware (which would mask the very thing we're measuring), and
 * it needs to send the nonce *both* ways in one request so a single round trip
 * tells us which way survived.
 */
import { saddleData, ns } from './api';
import { currentNonce } from './nonce-fallback';

export const probeAuth = () => {
	const root = saddleData.root || '';
	const nonce = currentNonce() || saddleData.nonce || '';
	const path = ns( 'auth-probe' );
	const url = `${ root }${ path }${
		-1 === `${ root }${ path }`.indexOf( '?' ) ? '?' : '&'
	}_wpnonce=${ encodeURIComponent( nonce ) }`;

	return window
		.fetch( url, {
			credentials: 'include',
			headers: {
				'X-WP-Nonce': nonce,
				// Echoed back, so we can tell "X-WP-Nonce specifically" apart
				// from "every custom header is being dropped".
				'X-Saddle-Probe': '1',
			},
		} )
		.then( ( res ) => ( res.ok ? res.json() : null ) )
		.catch( () => null );
};

/**
 * Turn a probe result into one of the situations we have advice for.
 *
 * @param {Object|null} probe Parsed probe response, or null if it failed.
 * @return {string} A verdict key.
 */
export const verdictFor = ( probe ) => {
	if ( ! probe ) {
		return 'unreachable';
	}
	if ( ! probe.cookie ) {
		return 'cookie_stripped';
	}
	if ( ! probe.nonce_header && ! probe.nonce_query ) {
		return 'nonce_stripped_both';
	}
	// Checked before `identified`: the probe sends the nonce both ways, so when
	// only the header is being stripped the query copy still signs us in — and
	// the missing header is the finding, not the success.
	if ( ! probe.nonce_header ) {
		return 'nonce_header_stripped';
	}
	if ( ! probe.identified ) {
		return 'session_invalid';
	}
	return 'credentials_fine';
};
