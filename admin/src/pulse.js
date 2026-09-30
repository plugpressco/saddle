/**
 * A reader for `GET /connections/pulse`, for a waiting line (#277).
 *
 * The route returns only what changed since the `since` it is given, plus the
 * server's own clock in `now`. A reader keeps the last `now` (never the
 * browser's clock) and merges what comes back: connections by id, apps that
 * have asked to connect by client id.
 *
 * `ignoreExisting` is for the connect step: the first answer is what was
 * already there before this screen opened, so it is used only to set `since`.
 * The try-it step keeps it, because the connection it watches may already
 * exist.
 */
import { api } from './api';

/**
 * @param {Object}  options
 * @param {boolean} options.ignoreExisting Treat the first answer as a baseline.
 * @return {{poll: Function}} `poll()` fetches once and resolves
 *         `{ rows, pending }`, everything merged so far.
 */
export function createPulse( { ignoreExisting = false } = {} ) {
	let since = 0;
	let first = true;
	const rows = new Map();
	const pending = new Map();

	return {
		poll() {
			return api( `connections/pulse?since=${ since }` ).then(
				( res ) => {
					const skip = first && ignoreExisting;
					first = false;
					since = res.now || since;
					if ( ! skip ) {
						( res.connections || [] ).forEach( ( row ) =>
							rows.set( row.id, row )
						);
						( res.pending || [] ).forEach( ( p ) =>
							pending.set( p.client_id, p )
						);
					}
					return {
						rows: [ ...rows.values() ],
						pending: [ ...pending.values() ],
					};
				}
			);
		},
	};
}
