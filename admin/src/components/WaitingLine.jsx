/**
 * The waiting line (#277): a status line that watches for something to
 * happen and says the finest true thing it knows while it waits.
 *
 * Saddle uses it for "Waiting for Claude…" → "Claude asked to connect…" →
 * "Claude connected", and for each tool call landing on the try-it step. It is
 * in `kit` so a module can use it for its own setup task (Analytics waiting
 * for a first visit).
 *
 * It calls `check()` and draws what comes back. It polls every 3 seconds,
 * backing off by half each quiet poll up to 30 seconds, resets to 3 when the
 * answer changes, and does not poll while the tab is hidden. It stops when
 * the phase is `done` (after `settleMs`, so a few more lines can land), and
 * calls `onTimeout` once if nothing finishes in `timeoutMs`.
 *
 * `check` resolves `{ phase: 'waiting' | 'asked' | 'done', text, items? }`:
 * `items` are finished lines shown above `text` once `done`.
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { ChecklistItem, LiveIndicator } from '@plugpress/ui';
import { nextDelay } from '../onboarding-logic';

/**
 * @param {Object}   props
 * @param {Function} props.check       Async: resolves the status object.
 * @param {Function} props.onDone      Called once with the final status.
 * @param {Function} props.onTimeout   Called once when `timeoutMs` passes
 *                                     without `done`.
 * @param {number}   props.timeoutMs   Give up after this long; 0 never.
 * @param {number}   props.settleMs    Keep polling this long after `done`.
 * @param {string}   props.initialText What to say before the first answer.
 */
export default function WaitingLine( {
	check,
	onDone,
	onTimeout,
	timeoutMs = 0,
	settleMs = 0,
	initialText = '',
} ) {
	const [ status, setStatus ] = useState( {
		phase: 'waiting',
		text: initialText,
		items: [],
	} );

	// Latest callbacks, so a parent re-rendering never restarts the poll.
	const latest = useRef( {} );
	latest.current = { check, onDone, onTimeout };

	useEffect( () => {
		let alive = true;
		let timer = null;
		let delay = 0;
		let lastKey = '';
		let doneAt = 0;
		let finished = false;
		let timedOut = false;
		const startedAt = Date.now();

		const schedule = ( wait ) => {
			window.clearTimeout( timer );
			timer = window.setTimeout( poll, wait ); // eslint-disable-line no-use-before-define
		};

		function poll() {
			if ( ! alive ) {
				return;
			}
			// A hidden tab does not poll; it catches up the moment it is shown.
			if ( document.hidden ) {
				return;
			}
			Promise.resolve( latest.current.check() )
				.then( ( next ) => {
					if ( ! alive || ! next ) {
						return;
					}
					const key = JSON.stringify( [
						next.phase,
						next.text,
						next.items,
					] );
					const changed = key !== lastKey;
					lastKey = key;
					setStatus( { items: [], ...next } );
					delay = nextDelay( delay, changed );

					if ( 'done' === next.phase ) {
						if ( ! doneAt ) {
							doneAt = Date.now();
						}
						if ( Date.now() - doneAt >= settleMs ) {
							if ( ! finished ) {
								finished = true;
								if ( latest.current.onDone ) {
									latest.current.onDone( next );
								}
							}
						}
					}
				} )
				.catch( () => {
					// A failed poll is a quiet one: try again, slower.
					delay = nextDelay( delay, false );
				} )
				.finally( () => {
					if ( ! alive || finished ) {
						return;
					}
					if (
						timeoutMs &&
						! timedOut &&
						Date.now() - startedAt >= timeoutMs
					) {
						timedOut = true;
						if ( latest.current.onTimeout ) {
							latest.current.onTimeout();
						}
					}
					schedule( delay );
				} );
		}

		const onVisible = () => {
			if ( ! document.hidden && ! finished ) {
				delay = nextDelay( 0, true );
				schedule( 0 );
			}
		};
		document.addEventListener( 'visibilitychange', onVisible );

		// The timeout still fires in a tab nobody is looking at.
		let timeoutTimer = null;
		if ( timeoutMs ) {
			timeoutTimer = window.setTimeout( () => {
				if ( alive && ! finished && ! timedOut ) {
					timedOut = true;
					if ( latest.current.onTimeout ) {
						latest.current.onTimeout();
					}
				}
			}, timeoutMs );
		}

		schedule( 0 );

		return () => {
			alive = false;
			window.clearTimeout( timer );
			window.clearTimeout( timeoutTimer );
			document.removeEventListener( 'visibilitychange', onVisible );
		};
		// One loop for the life of the component.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ timeoutMs, settleMs ] );

	const done = 'done' === status.phase;

	return (
		<div
			className="saddle-waiting"
			role="status"
			aria-live="polite"
			data-phase={ status.phase }
		>
			{ done &&
				( status.items || [] ).map( ( item ) => (
					<div key={ item } className="saddle-waiting__line">
						<ChecklistItem status="done" label={ item } />
					</div>
				) ) }
			{ done ? (
				<div className="saddle-waiting__line">
					<ChecklistItem status="done" label={ status.text } />
				</div>
			) : (
				status.text && <LiveIndicator>{ status.text }</LiveIndicator>
			) }
		</div>
	);
}
