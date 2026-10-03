/**
 * A module's sections: the left sidebar on every module page
 * (planning/MODULE-LAYOUT.md, M4).
 *
 * One item per section, each a 16px Iconoir icon and its label (an empty
 * slot of the same size when the module names none, so the labels line
 * up), Settings last after a hairline. Each item is a real link, so a middle click or
 * "Copy link" works; a plain click stays in the app. No brand colour, no
 * badges and no module name: the header already names the module. Below
 * 782px the list becomes one row that scrolls sideways above the content.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { isPlainClick, sidebarItems } from '../frame-logic';
import { NavIcon } from '../icons/iconoir';

/**
 * In a row that scrolls sideways (the sidebar and the icon tab row below
 * 782px), bring the active item into view, so the owner sees where they are.
 * Does nothing when the row does not scroll.
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
 * @param {Object}   props.area      The module's page, from saddleData.areas.
 * @param {string}   props.tab       The active section.
 * @param {Function} props.onSection Called with a section key on a plain click.
 */
export default function SectionNav( { area, tab, onSection } ) {
	const { items, settings } = sidebarItems( area.tabs );
	const listRef = useRef( null );
	useEffect( () => revealActive( listRef.current, '.is-active' ), [ tab ] );

	const item = ( t ) => {
		const active = t.key === tab;
		return (
			<li key={ t.key }>
				<a
					className={ `saddle-sections__item${
						active ? ' is-active' : ''
					}` }
					href={ t.url }
					aria-current={ active ? 'page' : undefined }
					onClick={ ( event ) => {
						if ( onSection && isPlainClick( event ) ) {
							event.preventDefault();
							onSection( t.key );
						}
					} }
				>
					{ t.icon ? (
						<NavIcon name={ t.icon } />
					) : (
						<span className="saddle-nav-icon" aria-hidden="true" />
					) }
					<span>{ t.label }</span>
				</a>
			</li>
		);
	};

	return (
		<nav
			className="saddle-sections"
			aria-label={ area.title || __( 'Sections', 'saddle' ) }
		>
			<ul className="saddle-sections__list" ref={ listRef }>
				{ items.map( item ) }
				{ settings && items.length > 0 && (
					<li className="saddle-sections__rule" aria-hidden="true" />
				) }
				{ settings && item( settings ) }
			</ul>
		</nav>
	);
}
