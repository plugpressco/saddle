/**
 * A module section's pages: a row of links at the top of the content
 * (`&sub=`), drawn when a section has two or more. The section itself is a
 * tab in the header's tab row, so the page sits under one column with no
 * sidebar beside WordPress's own menu (Fahim, 2026-10-05).
 *
 * Each page is a 16px Iconoir icon and its label (an empty slot of the same
 * size when the module names none). The current page is a small raised
 * chip. Each item is a real link, so a middle click or "Copy link" works; a
 * plain click stays in the app. When the row is wider than the column it
 * scrolls sideways.
 */
import { useEffect, useRef } from '@wordpress/element';
import { isPlainClick } from '../frame-logic';
import { NavIcon } from '../icons/iconoir';

/**
 * In a row that scrolls sideways (the page links and the header's tab row on
 * a narrow screen), bring the active item into view, so the owner sees where
 * they are. Does nothing when the row does not scroll.
 *
 * @param {?Element} row      The scrolling element.
 * @param {string}   selector The active item, inside it.
 */
export function revealActive( row, selector ) {
	if ( ! row || row.scrollWidth <= row.clientWidth ) {
		return;
	}
	const active = row.querySelector( selector );
	if ( active ) {
		row.scrollLeft +=
			active.getBoundingClientRect().left -
			row.getBoundingClientRect().left -
			16;
	}
}

/**
 * @param {Object}   props
 * @param {Array}    props.pages  The section's pages: `[ { key, label, url, icon } ]`.
 * @param {string}   props.sub    The active page.
 * @param {string}   props.label  The row's accessible name (the section).
 * @param {Function} props.onPage Called with a page key on a plain click.
 */
export default function PageNav( { pages, sub, label, onPage } ) {
	const listRef = useRef( null );
	useEffect( () => revealActive( listRef.current, '.is-active' ), [ sub ] );

	return (
		<nav className="saddle-pages" aria-label={ label }>
			<ul className="saddle-pages__list" ref={ listRef }>
				{ pages.map( ( p ) => {
					const active = p.key === sub;
					return (
						<li key={ p.key }>
							<a
								className={ `saddle-pages__item${
									active ? ' is-active' : ''
								}` }
								href={ p.url }
								aria-current={ active ? 'page' : undefined }
								onClick={ ( event ) => {
									if ( onPage && isPlainClick( event ) ) {
										event.preventDefault();
										onPage( p.key );
									}
								} }
							>
								{ p.icon ? (
									<NavIcon name={ p.icon } />
								) : (
									<span
										className="saddle-nav-icon"
										aria-hidden="true"
									/>
								) }
								<span>{ p.label }</span>
							</a>
						</li>
					);
				} ) }
			</ul>
		</nav>
	);
}
