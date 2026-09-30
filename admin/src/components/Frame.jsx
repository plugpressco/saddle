/**
 * The frame Core draws on every Saddle page (#274, #280).
 *
 * A white header band across the full width: the mark and "Saddle / Page",
 * the AI on / Paused pill and the notices bell, and the page's tabs inside the band.
 * Then the page, and a quiet footer. WordPress's own left menu is the
 * navigation: there is no sidebar inside the page. A module's content sits in
 * the same frame, so every Saddle page reads as one product.
 *
 * Never draw the frame while the app is still loading: WordPress's common.js
 * moves every `.notice` to just after the first `.wrap h1` once the page has
 * loaded, and the header's h1 would pull the quarantined notices back into
 * view.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	AppContent,
	Tabs,
	Tooltip,
	StatusDot,
	Popover,
	SkipLink,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { saddleData } from '../api';
import { BrandMark, IconBell } from './icons';
import NoticeItem from './NoticeItem';

// One content width for every page: sparse pages don't feel empty and the
// column never resizes between tabs.
const PAGE_WIDTH = 960;

/**
 * Whether AI access is on, always in view: "AI on" or "Paused". A real link
 * to the Dashboard, where the switch is. What each app may do is chosen per
 * app on AI apps, so the pill no longer names a level.
 *
 * @param {Object}  props
 * @param {boolean} props.paused
 * @param {string}  props.href   The Dashboard.
 */
function StatusPill( { paused, href } ) {
	return (
		<Tooltip
			content={ __(
				'Turn AI access on or off on the Dashboard',
				'saddle'
			) }
		>
			<a
				href={ href }
				className={ `saddle-status-pill saddle-status-pill--${
					paused ? 'paused' : 'on'
				}` }
			>
				<StatusDot tone={ paused ? 'neutral' : 'success' } />
				<span>
					{ paused
						? __( 'Paused', 'saddle' )
						: __( 'AI on', 'saddle' ) }
				</span>
			</a>
		</Tooltip>
	);
}

/**
 * Other plugins' admin notices, captured server-side into a hidden container
 * (see Saddle_Settings::setup_notice_quarantine), surfaced behind a quiet
 * disclosure instead of piling above the page. Nodes are MOVED into the panel
 * — not re-rendered — so their own dismiss buttons and handlers keep working.
 *
 * Saddle's own notices that did not win the frame's one slot (#276) sit above
 * them as their own section, and the count includes them.
 *
 * @param {Object}    props
 * @param {Object[]=} props.extra     Saddle notices for the bell.
 * @param {Function=} props.onDismiss Called with a dismissible notice.
 */
function ForeignNotices( { extra = [], onDismiss } ) {
	const [ count, setCount ] = useState( 0 );
	const [ open, setOpen ] = useState( false );

	// A holder this component owns and keeps for its whole life. The popover
	// unmounts its content on close, so notices parented straight to the panel
	// would be destroyed on the first close — and these are the plugins' OWN
	// nodes, moved rather than copied, so losing them loses their dismiss
	// handlers with them. Parking them here keeps them alive while detached.
	const holderRef = useRef( null );
	if ( ! holderRef.current ) {
		holderRef.current = document.createElement( 'div' );
		holderRef.current.className = 'saddle-foreign__list';
	}

	useEffect( () => {
		const source = document.getElementById( 'saddle-foreign-notices' );
		if ( ! source ) {
			return;
		}
		setCount( source.children.length );
		while ( source.firstChild ) {
			holderRef.current.appendChild( source.firstChild );
		}
	}, [] );

	// A callback ref, not a ref object read from an effect: the popover mounts
	// its panel lazily when opened, so an effect keyed on `open` can run on a
	// tick where the panel does not exist yet.
	const mountList = useCallback( ( node ) => {
		if ( node && holderRef.current.parentNode !== node ) {
			node.appendChild( holderRef.current );
		}
	}, [] );

	const total = count + extra.length;
	if ( ! total ) {
		return null;
	}

	const foreignLabel = sprintf(
		/* translators: %d: number of notices. */
		_n(
			'%d notice from other plugins',
			'%d notices from other plugins',
			count,
			'saddle'
		),
		count
	);
	const label = extra.length
		? sprintf(
				/* translators: %d: number of notices. */
				_n( '%d notice', '%d notices', total, 'saddle' ),
				total
		  )
		: foreignLabel;

	return (
		<Popover
			className="saddle-foreign"
			open={ open }
			onOpenChange={ setOpen }
			align="end"
			trigger={
				<button
					type="button"
					className="saddle-foreign__button"
					aria-label={ label }
				>
					<IconBell />
					<span>{ total }</span>
				</button>
			}
		>
			{ extra.length > 0 && (
				<div className="saddle-foreign__saddle">
					<div className="saddle-foreign__head">
						{ __( 'From Saddle', 'saddle' ) }
					</div>
					{ extra.map( ( notice ) => (
						<NoticeItem
							key={ notice.id }
							notice={ notice }
							onDismiss={
								notice.dismiss && onDismiss
									? onDismiss
									: undefined
							}
						/>
					) ) }
				</div>
			) }
			{ count > 0 && (
				<>
					<div className="saddle-foreign__head">{ foreignLabel }</div>
					<div ref={ mountList } />
				</>
			) }
		</Popover>
	);
}

/**
 * The footer strip on every page: which build this is, and where to read more.
 *
 * @param {Object} props
 * @param {Object} props.area The page; a module page also names its product.
 */
function Footer( { area } ) {
	const parts = [
		sprintf(
			/* translators: %s: plugin version. */
			__( 'Saddle %s', 'saddle' ),
			saddleData.version || ''
		).trim(),
	];
	if ( area.module && area.product ) {
		parts.push(
			[ area.product, area.version ].filter( Boolean ).join( ' ' )
		);
	}

	return (
		<footer className="saddle-footer">
			<span className="saddle-footer__brand">
				<BrandMark />
				{ parts.join( ' · ' ) }
			</span>
			{ saddleData.docsUrl && (
				<a href={ saddleData.docsUrl } target="_blank" rel="noreferrer">
					{ __( 'Docs', 'saddle' ) }
				</a>
			) }
			{ saddleData.rateUrl && (
				<a href={ saddleData.rateUrl } target="_blank" rel="noreferrer">
					{ __( 'Rate Saddle', 'saddle' ) }
				</a>
			) }
		</footer>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.area            The page, from saddleData.areas.
 * @param {string}   props.tab             The active tab.
 * @param {Function} props.onTab           Called with a tab key.
 * @param {Object}   props.status          { paused, href }.
 * @param {boolean}  props.notices         Show the notices bell.
 * @param {boolean}  props.showTabs        Draw the page's tabs (first run doesn't).
 * @param {string=}  props.crumb           The page name after "Saddle /", when it
 *                                         is not the page's own title.
 * @param {Object=}  props.notice          The one notice shown under the header.
 * @param {Object[]} props.moreNotices     The rest, for the bell.
 * @param {Function} props.onDismissNotice Called with a dismissible notice.
 * @param {*}        props.children        The page content.
 */
export default function Frame( {
	area,
	tab,
	onTab,
	status,
	notices = true,
	showTabs = true,
	crumb,
	notice = null,
	moreNotices = [],
	onDismissNotice,
	children,
} ) {
	const home = ( saddleData.areas || [] ).find( ( a ) => a.key === 'home' );
	const tabs = showTabs && area.tabs.length > 1 ? area.tabs : null;

	return (
		<div className="pp-app saddle-app saddle-app--frame">
			<SkipLink href="#pp-main">
				{ __( 'Skip to content', 'saddle' ) }
			</SkipLink>
			<header
				className={ `saddle-header${
					tabs ? ' saddle-header--tabs' : ''
				}` }
			>
				<div className="saddle-header__row">
					<h1 className="saddle-header__title">
						<a
							className="saddle-header__home"
							href={ home ? home.url : undefined }
						>
							<BrandMark />
							<span>{ __( 'Saddle', 'saddle' ) }</span>
						</a>
						<span className="saddle-header__sep" aria-hidden="true">
							/
						</span>
						<span aria-current="page">{ crumb || area.title }</span>
					</h1>
					<div className="saddle-header__actions">
						{ notices && (
							<ForeignNotices
								extra={ moreNotices }
								onDismiss={ onDismissNotice }
							/>
						) }
						{ status && <StatusPill { ...status } /> }
					</div>
				</div>
				{ tabs && (
					<div className="saddle-header__tabs">
						<Tabs
							value={ tab }
							onChange={ onTab }
							aria-label={ area.title }
							items={ tabs.map( ( t ) => ( {
								value: t.key,
								label: t.label,
							} ) ) }
						/>
					</div>
				) }
			</header>
			<main
				id="pp-main"
				className="saddle-frame"
				data-saddle-screen={ `${ area.key }/${ tab }` }
			>
				<AppContent width={ PAGE_WIDTH }>
					{ notice && (
						<NoticeItem
							className="saddle-frame__notice"
							notice={ notice }
							onDismiss={
								notice.dismiss && onDismissNotice
									? onDismissNotice
									: undefined
							}
						/>
					) }
					{ children }
				</AppContent>
			</main>
			<Footer area={ area } />
		</div>
	);
}
