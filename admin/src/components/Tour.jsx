/**
 * The three-stop tour (#277), shown once per user right after first run:
 * the AI switch, the Saddle menu, Home's activity feed. Never more than three.
 *
 * The things it points at are not all React's: the Saddle menu is
 * WordPress's own. So each stop finds its element in the page and a small
 * fixed anchor is drawn over it for `Coachmark` to attach to. A stop whose
 * element is missing (a collapsed menu on a phone, a tab renamed) is dropped
 * rather than pointing at nothing.
 *
 * The tour renders into <body>, not inside the page: the page's content
 * column is a containing block for fixed elements, so an anchor drawn inside
 * it would sit offset from what it covers. <body> carries `pp-scope`, so the
 * kit's tokens still apply.
 */
import {
	useState,
	useEffect,
	useLayoutEffect,
	createPortal,
} from '@wordpress/element';
import { Coachmark } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';

const visible = ( el ) => {
	if ( ! el ) {
		return null;
	}
	const rect = el.getBoundingClientRect();
	return rect.width > 0 && rect.height > 0 ? el : null;
};

/**
 * @return {Object[]} The stops, each with a `find` for its element.
 */
function stopsFor() {
	return [
		{
			id: 'status',
			find: () => visible( document.querySelector( '.saddle-ai' ) ),
			title: __( 'Pause your AI from here', 'saddle' ),
			description: __(
				'It shows AI on or Paused at the top of every Saddle page. Click it to pause every app at once.',
				'saddle'
			),
			side: 'bottom',
			align: 'end',
		},
		{
			id: 'menu',
			find: () =>
				visible( document.getElementById( 'toplevel_page_saddle' ) ),
			title: __( 'Everything else is in the Saddle menu', 'saddle' ),
			description: __(
				'Modules, AI apps, Services, Context and Settings.',
				'saddle'
			),
			side: 'right',
			align: 'start',
		},
		{
			id: 'activity',
			find: () =>
				visible( document.querySelector( '#activity h2, #activity' ) ),
			title: __( 'Every change shows up here', 'saddle' ),
			description: __(
				'Changes your AI made and what Saddle blocked, newest first.',
				'saddle'
			),
			side: 'top',
			align: 'start',
		},
	];
}

/**
 * A fixed box laid over an element, so a popover can attach to it.
 *
 * @param {Object}   props
 * @param {Function} props.find     Returns the element to cover.
 * @param {*}        props.children The coachmark's own content builder.
 */
function Anchored( { find, children } ) {
	const [ box, setBox ] = useState( null );

	useLayoutEffect( () => {
		const place = () => {
			const el = find();
			if ( ! el ) {
				setBox( null );
				return;
			}
			const r = el.getBoundingClientRect();
			setBox( {
				left: r.left,
				top: r.top,
				width: r.width,
				height: r.height,
			} );
		};
		const el = find();
		if ( el && el.scrollIntoView ) {
			el.scrollIntoView( { block: 'nearest' } );
		}
		place();
		window.addEventListener( 'resize', place );
		window.addEventListener( 'scroll', place, true );
		return () => {
			window.removeEventListener( 'resize', place );
			window.removeEventListener( 'scroll', place, true );
		};
	}, [ find ] );

	return box ? children( box ) : null;
}

/**
 * @param {Object}   props
 * @param {Function} props.onFinish Called once, on the last Next, Skip,
 *                                  Escape or a click outside.
 */
export default function Tour( { onFinish } ) {
	const [ stops, setStops ] = useState( null );
	const [ index, setIndex ] = useState( 0 );

	// After the page has laid out, keep only the stops that can be found.
	useEffect( () => {
		const t = window.setTimeout( () => {
			const found = stopsFor().filter( ( s ) => s.find() );
			setStops( found );
			if ( ! found.length ) {
				onFinish();
			}
		}, 400 );
		return () => window.clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, on arrival.
	}, [] );

	if ( ! stops || ! stops.length ) {
		return null;
	}

	const stop = stops[ index ];
	const last = index === stops.length - 1;

	return createPortal(
		<Anchored key={ stop.id } find={ stop.find }>
			{ ( box ) => (
				<Coachmark
					open
					title={ stop.title }
					description={ stop.description }
					step={ { index: index + 1, count: stops.length } }
					side={ stop.side }
					align={ stop.align }
					nextLabel={ last ? __( 'Done', 'saddle' ) : undefined }
					onNext={ () =>
						last ? onFinish() : setIndex( index + 1 )
					}
					onDismiss={ onFinish }
				>
					<span
						className="saddle-tour__anchor"
						style={ {
							left: box.left,
							top: box.top,
							width: box.width,
							height: box.height,
						} }
					/>
				</Coachmark>
			) }
		</Anchored>,
		document.body
	);
}
