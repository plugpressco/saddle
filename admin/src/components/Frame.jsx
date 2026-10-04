/**
 * The frame Core draws on every Saddle page (#274, #280,
 * planning/MODULE-LAYOUT.md).
 *
 * A white header band across the full width: the breadcrumb
 * `[mark] Saddle / Page` (Home reads `[mark] Saddle`), the notices bell, and
 * the AI switch (#309): "AI on" or "Paused", which opens a small panel to
 * pause or resume every app. While paused, a strip under the header says so
 * on every Saddle page.
 *
 * Four levels, one control each. WordPress's Saddle submenu picks the page.
 * A page's tabs (a module's sections, `&tab=`) are the header's tab row, with
 * Settings last. A module section with two or more pages (`&sub=`) starts its
 * content with a row of page links (PageNav). A drill-in (`&view=`) opens one
 * item: the breadcrumb reads `Saddle / Module / Section / title`, and the tab
 * row and the page links step aside. No sidebar of Saddle's own sits beside
 * WordPress's menu (Fahim, 2026-10-05: top tabs, like Kit).
 *
 * One column: the header's content, the page and the footer line up on the
 * page's width (`--saddle-col`), so every row starts at the same edge. Then a
 * quiet footer. A module's content sits in the same frame, so every Saddle
 * page reads as one product. The logic is frame-logic.js.
 *
 * While the app is still loading the frame draws its shape only (P12): the
 * breadcrumb as plain text, a placeholder for the AI switch, and the page's
 * skeleton. Never a heading then: WordPress's common.js moves every `.notice`
 * to just after the first `.wrap h1` or `h2` once the page has loaded, and a
 * heading would pull the quarantined notices back into view.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	AppContent,
	Button,
	Popover,
	Skeleton,
	SkipLink,
	TabsList,
	TabsRoot,
	TabsTrigger,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { saddleData } from '../api';
import {
	frameHeader,
	isPlainClick,
	sectionTabs,
	tabsHaveIcons,
} from '../frame-logic';
import { NavIcon } from '../icons/iconoir';
import { icons } from '../icons/kit';
import { BrandMark } from './icons';
import NoticeItem from './NoticeItem';
import PageNav, { revealActive } from './PageNav';

// One content width for every page: sparse pages don't feel empty and the
// column never resizes between tabs. Home is wider, for its two columns.
const PAGE_WIDTH = 960;
const HOME_WIDTH = 1040;

// A page shows at most one tab row, the header's, and its tabs swap the one
// content column. So every tab controls that column, the tab panel, and the
// panel is named by the active tab. Kit tabs on their own point each tab at a
// panel of its own that Saddle never draws (QA 1.5.0 S5). A section's pages
// are links, not tabs.
const PANEL_ID = 'saddle-tabpanel';
const tabId = ( key ) => `saddle-tab-${ key }`;

/**
 * A row of tabs in the minimal style, each with its icon when every tab has
 * one: the header's row, a Core page's tabs or a module's sections.
 *
 * @param {Object}   props
 * @param {Array}    props.items    `[ { key, label, icon } ]`.
 * @param {string}   props.value    The active key.
 * @param {Function} props.onChange Called with a key.
 * @param {string}   props.label    The row's accessible name.
 */
function TabRow( { items, value, onChange, label } ) {
	const withIcons = tabsHaveIcons( items );
	return (
		<TabsRoot
			value={ value }
			onChange={ onChange }
			style={ { display: 'contents' } }
		>
			<TabsList aria-label={ label }>
				{ items.map( ( t ) => (
					<TabsTrigger
						key={ t.key }
						value={ t.key }
						id={ tabId( t.key ) }
						aria-controls={ PANEL_ID }
					>
						{ withIcons && <NavIcon name={ t.icon } /> }
						{ t.label }
					</TabsTrigger>
				) ) }
			</TabsList>
		</TabsRoot>
	);
}

/**
 * The breadcrumb in the header's h1. Every part but the last is a link: the
 * mark and "Saddle" go to Home; a module's name to its first section; a
 * section to itself with no item open. Those last two stay in the app on a
 * plain click, so the screen unmounts and can save what the owner typed.
 *
 * While the app loads it is a plain block, not a heading: WordPress's
 * common.js moves every notice after the first `.wrap h1` or `h2` once the
 * page is ready, and would pull the quarantined notices into view.
 *
 * @param {Object}   props
 * @param {Array}    props.crumbs  From frameHeader().
 * @param {Function} props.onCrumb Called with a crumb's key on a plain click;
 *                                 returns true when it navigated in the app.
 * @param {boolean}  props.plain   Draw a div instead of the h1.
 */
function Breadcrumb( { crumbs, onCrumb, plain = false } ) {
	const Title = plain ? 'div' : 'h1';
	const [ first, ...rest ] = crumbs;
	const homeInner = (
		<>
			<BrandMark />
			<span>{ first.label }</span>
		</>
	);

	return (
		<Title className="saddle-header__title">
			{ first.url ? (
				<a className="saddle-header__home" href={ first.url }>
					{ homeInner }
				</a>
			) : (
				<span
					className="saddle-header__home"
					aria-current={ rest.length ? undefined : 'page' }
				>
					{ homeInner }
				</span>
			) }
			{ rest.map( ( crumb, i ) => (
				<span
					key={ `${ crumb.key }-${ i }` }
					className="saddle-header__crumb"
				>
					<span className="saddle-header__sep" aria-hidden="true">
						/
					</span>
					{ crumb.url ? (
						<a
							className="saddle-header__link"
							href={ crumb.url }
							onClick={ ( event ) => {
								if (
									onCrumb &&
									isPlainClick( event ) &&
									onCrumb( crumb.key )
								) {
									event.preventDefault();
								}
							} }
						>
							{ crumb.label }
						</a>
					) : (
						<span aria-current="page">{ crumb.label }</span>
					) }
				</span>
			) ) }
		</Title>
	);
}

/**
 * The AI switch, on every Saddle page: "AI on" or "Paused". It opens a small
 * panel that says what the state means and pauses or resumes every app. The
 * one control that matters on every page lives in the header, not on a card.
 *
 * @param {Object}   props
 * @param {boolean}  props.paused
 * @param {boolean}  props.pausing  A save is in flight.
 * @param {Function} props.onToggle Pause or resume.
 */
function AiSwitch( { paused, pausing, onToggle } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<Popover
			className="saddle-ai__panel"
			open={ open }
			onOpenChange={ setOpen }
			align="end"
			trigger={
				<button
					type="button"
					className={ `saddle-ai${ paused ? ' is-paused' : '' }` }
				>
					<span className="saddle-ai__dot" aria-hidden="true" />
					<span>
						{ paused
							? __( 'Paused', 'saddle' )
							: __( 'AI on', 'saddle' ) }
					</span>
					<icons.ChevronDown size={ 12 } />
				</button>
			}
		>
			<p className="saddle-ai__head">
				{ paused
					? __( 'AI is paused', 'saddle' )
					: __( 'AI is on', 'saddle' ) }
			</p>
			<p className="saddle-ai__text">
				{ paused
					? __(
							'No app can read or change this site until you resume.',
							'saddle'
					  )
					: __(
							'Your apps can work on this site, each within the access you gave it.',
							'saddle'
					  ) }
			</p>
			<Button
				variant="secondary"
				size="sm"
				loading={ pausing }
				disabled={ pausing }
				onClick={ () => {
					onToggle();
					setOpen( false );
				} }
			>
				{ paused
					? __( 'Resume', 'saddle' )
					: __( 'Pause all apps', 'saddle' ) }
			</Button>
		</Popover>
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
					<NavIcon name="bell" size={ 20 } />
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
 * @param {string}   props.tab             The active tab (a module's section).
 * @param {string}   props.sub             The active page inside the tab, or ''.
 * @param {string}   props.view            The module's view inside the page, or ''.
 * @param {?Object}  props.drill           The screen's drill-in, `{ title, tab, sub, view }`.
 * @param {Function} props.onTab           Called with a tab key.
 * @param {Function} props.onSub           Called with a page key.
 * @param {Function} props.onBack          Leaves the drill-in for its page, in the app.
 * @param {Object}   props.status          { paused, pausing, onToggle }.
 * @param {boolean}  props.notices         Show the notices bell.
 * @param {boolean}  props.showTabs        Draw the page's tabs (first run doesn't).
 * @param {string=}  props.crumb           The name in the header, when it is not
 *                                         the page's own title (first run).
 * @param {Object=}  props.notice          The one notice shown under the header.
 * @param {Object[]} props.moreNotices     The rest, for the bell.
 * @param {Function} props.onDismissNotice Called with a dismissible notice.
 * @param {boolean}  props.loading         The app is still loading: draw the
 *                                         frame's shape (no heading, no bell,
 *                                         no AI switch) around a skeleton.
 * @param {*}        props.children        The page content.
 */
export default function Frame( {
	area,
	tab,
	sub = '',
	view = '',
	drill = null,
	onTab,
	onSub,
	onBack,
	status,
	notices = true,
	showTabs = true,
	crumb,
	notice = null,
	moreNotices = [],
	onDismissNotice,
	loading = false,
	children,
} ) {
	const home = ( saddleData.areas || [] ).find( ( a ) => a.key === 'home' );
	const head = frameHeader( {
		area,
		tab,
		sub,
		view,
		drill,
		crumb,
		home: { label: __( 'Saddle', 'saddle' ), url: home ? home.url : '' },
	} );
	const tabs = showTabs && head.showTabs ? sectionTabs( area.tabs ) : null;
	const current = ( area.tabs || [] ).find( ( t ) => t.key === tab );
	const pages = head.showSubtabs && current ? current.subtabs : null;
	const tabsRef = useRef( null );
	useEffect(
		() =>
			revealActive(
				tabsRef.current,
				'[role="tab"][data-state="active"]'
			),
		[ tab, tabs ]
	);

	// A module crumb opens the first section, a section crumb closes the
	// item; both in the app. Any other link loads its page.
	const onCrumb = ( key ) => {
		if ( 'module' === key && onTab && area.tabs && area.tabs[ 0 ] ) {
			onTab( area.tabs[ 0 ].key );
			return true;
		}
		if ( 'section' === key && onBack ) {
			onBack();
			return true;
		}
		return false;
	};

	// When this page draws the tab row, the content column is its tab panel.
	const panel = tabs
		? {
				role: 'tabpanel',
				id: PANEL_ID,
				'aria-labelledby': tabId( tab ),
				tabIndex: 0,
		  }
		: {};

	const width =
		'home' === area.key && 'overview' === tab ? HOME_WIDTH : PAGE_WIDTH;

	const content = (
		<main
			id="pp-main"
			className="saddle-frame"
			// Browser agents wait for this to know the screen is drawn, so it
			// is left off while the skeleton shows.
			data-saddle-screen={
				loading
					? undefined
					: [ area.key, tab, sub, view ].filter( Boolean ).join( '/' )
			}
			aria-busy={ loading || undefined }
		>
			<AppContent width={ width } { ...panel }>
				{ pages && (
					<PageNav
						pages={ pages }
						sub={ sub }
						label={ current.label }
						onPage={ onSub }
					/>
				) }
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
	);

	return (
		<div
			className="pp-app saddle-app saddle-app--frame"
			style={ { '--saddle-col': `${ width }px` } }
		>
			<SkipLink href="#pp-main">
				{ __( 'Skip to content', 'saddle' ) }
			</SkipLink>
			<header
				className={ `saddle-header${
					tabs ? ' saddle-header--tabs' : ''
				}` }
			>
				<div className="saddle-header__row">
					<Breadcrumb
						crumbs={ head.crumbs }
						onCrumb={ onCrumb }
						plain={ loading }
					/>
					<div className="saddle-header__actions">
						{ /* The bell moves other plugins' notices into itself
						     once; it waits until the page is drawn for good. */ }
						{ notices && ! loading && (
							<ForeignNotices
								extra={ moreNotices }
								onDismiss={ onDismissNotice }
							/>
						) }
						{ loading && (
							<Skeleton round width={ 76 } height={ 30 } />
						) }
						{ status && ! loading && <AiSwitch { ...status } /> }
					</div>
				</div>
				{ tabs && (
					<div className="saddle-header__tabs" ref={ tabsRef }>
						<TabRow
							items={ tabs }
							value={ tab }
							onChange={ onTab }
							label={ area.title }
						/>
					</div>
				) }
			</header>
			{ status && status.paused && (
				<div className="saddle-paused" role="status">
					<span>
						<strong>{ __( 'AI is paused.', 'saddle' ) }</strong>{ ' ' }
						{ __(
							'No app can read or change this site until you resume.',
							'saddle'
						) }
					</span>
					<Button
						variant="secondary"
						size="sm"
						loading={ status.pausing }
						disabled={ status.pausing }
						onClick={ status.onToggle }
					>
						{ __( 'Resume', 'saddle' ) }
					</Button>
				</div>
			) }
			{ content }
			<Footer area={ area } />
		</div>
	);
}
