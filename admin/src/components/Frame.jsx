/**
 * The frame Core draws on every Saddle page (#274).
 *
 * What page this is, the one sentence that says what it is for, the safety
 * pill, other plugins' notices behind a bell, and one row of tabs. WordPress's
 * own left menu is the navigation: there is no sidebar inside the page. A
 * module's content sits inside the same frame, so every Saddle page reads as
 * one product.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	AppContent,
	PageHeader,
	Tabs,
	Tooltip,
	StatusDot,
	Badge,
	Popover,
	SkipLink,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { levelFor } from '../api';
import { BrandMark, IconBell } from './icons';
import NoticeItem from './NoticeItem';

// One content width for every page: sparse pages don't feel empty, the
// Permissions lanes still fit, and the column never resizes between tabs.
const PAGE_WIDTH = 960;

// Safety tone → design-system dot tone. Read-only is the calm state; any
// write power shows as "attention", paused as switched-off.
const DOT_TONES = {
	safe: 'success',
	active: 'warning',
	paused: 'neutral',
};

/**
 * What connected apps may do right now, always in view. A real link to the
 * place where it is changed.
 *
 * @param {Object}  props
 * @param {string}  props.tier
 * @param {boolean} props.paused
 * @param {boolean} props.rehearsal
 * @param {string}  props.href      Connections → Permissions.
 */
function StatusPill( { tier, paused, rehearsal, href } ) {
	const level = levelFor( tier );
	let tone = level.key === 'read' ? 'safe' : 'active';
	if ( paused ) {
		tone = 'paused';
	}
	return (
		<Tooltip
			content={ __(
				'Change this in Connections → Permissions',
				'saddle'
			) }
		>
			<a
				href={ href }
				className={ `saddle-status-pill saddle-status-pill--${ tone }` }
			>
				<StatusDot tone={ DOT_TONES[ tone ] } />
				<span>{ paused ? __( 'Paused', 'saddle' ) : level.title }</span>
				{ rehearsal && ! paused && (
					<Badge tone="info">{ __( 'Rehearsal', 'saddle' ) }</Badge>
				) }
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
 * @param {Object}   props
 * @param {Object}   props.area            The page, from saddleData.areas.
 * @param {string}   props.tab             The active tab.
 * @param {Function} props.onTab           Called with a tab key.
 * @param {string}   props.description     The one sentence for this tab.
 * @param {Object}   props.status          { tier, paused, rehearsal, href }.
 * @param {boolean}  props.notices         Show the notices bell.
 * @param {Object=}  props.notice          The one notice shown under the header.
 * @param {Object[]} props.moreNotices     The rest, for the bell.
 * @param {Function} props.onDismissNotice Called with a dismissible notice.
 * @param {*}        props.children        The page content.
 */
export default function Frame( {
	area,
	tab,
	onTab,
	description,
	status,
	notices = true,
	notice = null,
	moreNotices = [],
	onDismissNotice,
	children,
} ) {
	// A module's header names the product it comes from; Core's pages are
	// simply Saddle.
	const source =
		area.module && area.product
			? [ area.product, area.version ].filter( Boolean ).join( ' ' )
			: __( 'Saddle', 'saddle' );

	return (
		<div className="pp-app saddle-app saddle-app--frame">
			<SkipLink href="#pp-main">
				{ __( 'Skip to content', 'saddle' ) }
			</SkipLink>
			<main
				id="pp-main"
				className="saddle-frame"
				data-saddle-screen={ `${ area.key }/${ tab }` }
			>
				<AppContent width={ PAGE_WIDTH }>
					<PageHeader
						eyebrow={
							<span className="saddle-frame__source">
								<BrandMark />
								<span>{ source }</span>
							</span>
						}
						title={ area.title }
						description={ description }
						actions={
							<div className="saddle-frame__actions">
								{ notices && (
									<ForeignNotices
										extra={ moreNotices }
										onDismiss={ onDismissNotice }
									/>
								) }
								{ status.tier && <StatusPill { ...status } /> }
							</div>
						}
						tabs={
							area.tabs.length > 1 ? (
								<Tabs
									value={ tab }
									onChange={ onTab }
									aria-label={ area.title }
									items={ area.tabs.map( ( t ) => ( {
										value: t.key,
										label: t.label,
									} ) ) }
								/>
							) : null
						}
					/>
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
		</div>
	);
}
