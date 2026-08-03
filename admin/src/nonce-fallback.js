/**
 * Keeping the dashboard signed in on hosts that strip custom request headers.
 *
 * This screen authenticates with the logged-in admin's cookie plus a `wp_rest`
 * nonce, and apiFetch sends that nonce as an `X-WP-Nonce` request header. Some
 * hosting security layers — 20i's StackProtect is the one that surfaced this,
 * and some Sucuri/Cloudflare rule sets do it too — drop non-standard request
 * headers at the edge, before PHP ever sees them.
 *
 * WordPress reads a missing nonce as "nobody is signed in" rather than as an
 * error: `rest_cookie_check_errors()` calls `wp_set_current_user( 0 )` and
 * returns true. Every `manage_options` route then answers **401**, because
 * `rest_authorization_required_code()` is `is_user_logged_in() ? 403 : 401`,
 * and the dashboard renders blank against a perfectly valid session.
 *
 * Core accepts the same nonce as a `_wpnonce` query parameter, so we send it
 * both ways. Two things about that are easy to get wrong:
 *
 *   1. Core reads `$_REQUEST['_wpnonce']` **before** the header — the query
 *      parameter does not back the header up, it *overrules* it. A stale value
 *      in the query string therefore beats a fresh header and hard-fails the
 *      request with `rest_cookie_invalid_nonce`.
 *   2. apiFetch refreshes the nonce on its own when that 403 comes back, by
 *      assigning to `apiFetch.nonceMiddleware.nonce` and replaying the request.
 *
 * Together those mean there must be exactly **one** nonce object, and we must
 * read it at request time rather than closing over a copy. A second middleware
 * holding a snapshot of `saddleData.nonce` would go stale, outrank the fresh
 * header, and turn apiFetch's self-healing retry into a loop that never
 * recovers.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * The one live nonce holder on the apiFetch singleton.
 *
 * Core's `wp-api-fetch` inline script normally creates this already — that
 * script is a declared dependency of this bundle, so it has run by now — and
 * it is the object apiFetch's own refresh path writes to. We only fill the gap
 * if it is genuinely missing; registering a second one just creates a shadow
 * copy that nothing ever refreshes.
 *
 * @param {string} fallbackNonce Nonce from the page, used only if core left none.
 * @return {Object|null} The live nonce middleware, or null if we have no nonce.
 */
export const ensureNonceMiddleware = ( fallbackNonce ) => {
	if ( ! apiFetch.nonceMiddleware && fallbackNonce ) {
		apiFetch.nonceMiddleware =
			apiFetch.createNonceMiddleware( fallbackNonce );
		apiFetch.use( apiFetch.nonceMiddleware );
	}
	return apiFetch.nonceMiddleware || null;
};

/**
 * The nonce as of right now. Never cache the result.
 *
 * @return {string} The current `wp_rest` nonce, or '' if there is none.
 */
export const currentNonce = () =>
	( apiFetch.nonceMiddleware && apiFetch.nonceMiddleware.nonce ) || '';

/**
 * Append `_wpnonce` to a path or URL, leaving it alone if it is already there.
 *
 * The separator matters beyond tidiness: on sites using plain permalinks
 * `rest_url()` is `https://example.com/?rest_route=/`, so the target routinely
 * already carries a query string.
 *
 * @param {string} target Path or absolute URL.
 * @param {string} nonce  Nonce to append.
 * @return {string} The target, with the nonce on it.
 */
const withNonce = ( target, nonce ) => {
	if ( typeof target !== 'string' || '' === target ) {
		return target;
	}
	if ( /[?&]_wpnonce=/.test( target ) ) {
		return target;
	}
	const separator = -1 === target.indexOf( '?' ) ? '?' : '&';
	return `${ target }${ separator }_wpnonce=${ encodeURIComponent( nonce ) }`;
};

/**
 * Whether this request is aimed at our own REST API.
 *
 * A nonce is a CSRF token, so it must never ride along to somebody else's host.
 * A relative `path` is ours by definition; an absolute `url` has to sit under
 * the REST root this page was rendered with — or be the `?rest_route=` form of
 * it under the site root, which api.js falls back to when a host WAF blocks
 * the pretty REST path.
 *
 * @param {Object} options Request options.
 * @param {string} root    This site's REST root.
 * @param {string} homeUrl This site's root, base of the ?rest_route= form.
 * @return {boolean} True when the nonce may be attached.
 */
const isOwnRest = ( options, root, homeUrl ) => {
	if ( typeof options.url === 'string' ) {
		if ( !! root && 0 === options.url.indexOf( root ) ) {
			return true;
		}
		return (
			!! homeUrl &&
			0 === options.url.indexOf( homeUrl ) &&
			-1 !== options.url.indexOf( 'rest_route=' )
		);
	}
	return typeof options.path === 'string';
};

/**
 * Middleware that puts the nonce in the query string as well as the header.
 *
 * Decorates `path` and `url` both, and that is load-bearing rather than belt
 * and braces. Core's `wp-api-fetch` script registers its own root-URL
 * middleware, which runs *after* this one and rebuilds `url` from `path`
 * whenever `path` is still a string — so decorating only `url` gets silently
 * undone on every relative-path request, which is all of them. Decorating
 * `path` is also the more correct target on sites using plain permalinks,
 * where the root-URL middleware rewrites `?` to `&` while composing the URL.
 *
 * @param {Object} config         Configuration.
 * @param {string} config.root    This site's REST root, from `saddleData.root`.
 * @param {string} config.homeUrl Site root, from `saddleData.homeUrl`.
 * @return {Function} An apiFetch middleware.
 */
export const createNonceQueryMiddleware =
	( { root, homeUrl } ) =>
	( options, next ) => {
		const nonce = currentNonce();
		if ( '' === nonce || ! isOwnRest( options, root, homeUrl ) ) {
			return next( options );
		}

		const decorated = { ...options };
		if ( typeof decorated.path === 'string' ) {
			decorated.path = withNonce( decorated.path, nonce );
		}
		if ( typeof decorated.url === 'string' ) {
			decorated.url = withNonce( decorated.url, nonce );
		}
		return next( decorated );
	};
