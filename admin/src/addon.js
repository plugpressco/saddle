/**
 * Adding one of Saddle's own add-ons from Saddle's pages (#367 for Saddle
 * Analytics, #409 for Saddle Rank).
 *
 * The server says where each stands (`saddleData.<key>`: on, installed but
 * off, or missing, and whether this owner may add it). Adding it goes
 * through WordPress's own plugins REST endpoint, signed in as the owner:
 * `POST /wp/v2/plugins` downloads it from WordPress.org and turns it on, as
 * the block directory does; `POST /wp/v2/plugins/<plugin>` turns on a copy
 * that is already installed. Saddle writes no file.
 */
import apiFetch from '@wordpress/api-fetch';
import { plainError, saddleData } from './api';

/**
 * One add-on's state and its add action.
 *
 * @param {string} key  The key in saddleData, such as `rank`.
 * @param {string} slug The WordPress.org slug, also its folder and main file.
 * @return {{offer:Function,add:Function}} Where it stands, and how to add it.
 */
export function createAddon( key, slug ) {
	const plugin = `${ slug }/${ slug }`;

	// Where it stands for the rest of this page's life. A successful add
	// moves it to `active`, so the welcome and Home never offer it twice.
	let current = {
		state: 'active',
		canAdd: false,
		url: '',
		manualUrl: '',
		...( saddleData[ key ] || {} ),
	};

	const activate = () =>
		apiFetch( {
			path: `/wp/v2/plugins/${ plugin }`,
			method: 'POST',
			data: { status: 'active' },
		} );

	return {
		offer: () => current,
		add: () => {
			const run =
				'inactive' === current.state
					? activate()
					: apiFetch( {
							path: '/wp/v2/plugins',
							method: 'POST',
							data: { slug, status: 'active' },
					  } ).catch( ( error ) => {
							// Installed since the page loaded (another tab,
							// or by hand): turn that copy on.
							if ( error && 'folder_exists' === error.code ) {
								return activate();
							}
							throw error;
					  } );

			return run.then(
				( result ) => {
					current = { ...current, state: 'active', canAdd: false };
					return result;
				},
				( error ) => {
					throw plainError( error );
				}
			);
		},
	};
}
