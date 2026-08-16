/**
 * React entry point for the Saddle admin UI.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import App from './App';
import {
	createNonceQueryMiddleware,
	ensureNonceMiddleware,
} from './nonce-fallback';

// PlugPress design system: shared tokens + components, then Saddle's monochrome
// accent, then Saddle's own styles (which now alias the --pp-* tokens).
// The resolver can't follow the package `exports` map for CSS; webpack can.
// eslint-disable-next-line import/no-unresolved
import '@plugpress/ui/ui.css';
// eslint-disable-next-line import/no-unresolved
import '@plugpress/ui/tokens/accents/saddle.css';
import './style.scss';

const data = window.saddleData || {};

// Authenticate REST calls with the logged-in admin's cookie + nonce, and route
// relative paths through the site's REST root.
//
// Registration order is the reverse of execution order (`apiFetch.use` unshifts),
// so this reads bottom-up: the root-URL middleware resolves `path` into an
// absolute `url`, then the nonce goes on as both a header and a query parameter.
// Sending it twice is what keeps the dashboard signed in on hosts whose security
// layer strips `X-WP-Nonce` — see nonce-fallback.js for why there must be exactly
// one nonce object behind both copies.
apiFetch.use(
	createNonceQueryMiddleware( { root: data.root, homeUrl: data.homeUrl } )
);
ensureNonceMiddleware( data.nonce );
if ( data.root ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( data.root ) );
}

const mount = () => {
	const el = document.getElementById( 'saddle-root' );
	if ( el ) {
		createRoot( el ).render( <App /> );
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
