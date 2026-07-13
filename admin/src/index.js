/**
 * React entry point for the Saddle admin UI.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import App from './App';

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
if ( data.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( data.nonce ) );
}
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
