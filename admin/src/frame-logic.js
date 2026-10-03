/**
 * The frame's header as plain logic (K4, the drill-in), so it can be tested
 * without React.
 *
 * A module screen opens a third level with `&view=` inside a tab. While it is
 * open, the screen may call `props.header.drillIn( { title } )`. The header
 * then hides the tab row and reads: back icon, the tab's label as a link to
 * the tab with no view, `/`, the title. The drill-in belongs to the tab and
 * view it was set on, so it ends on its own when the view closes or the tab
 * changes; Core also clears it when the screen unmounts.
 */

/**
 * What the header draws.
 *
 * @param {Object}  props
 * @param {Object}  props.area  The page: `{ title, url, tabs: [ { key, label, url } ] }`.
 * @param {string}  props.tab   The active tab.
 * @param {string}  props.view  The view inside the tab, or ''.
 * @param {?Object} props.drill What the screen asked for: `{ title, tab, view }`
 *                              (as `drillHeader()` records it), or null.
 * @return {{showTabs: boolean, back: ?{label: string, url: string}, title: string}} The header.
 */
export function frameHeader( { area, tab, view, drill } ) {
	const tabs = area && Array.isArray( area.tabs ) ? area.tabs : [];
	const title = ( area && area.title ) || '';

	const drilled =
		!! view &&
		!! drill &&
		typeof drill.title === 'string' &&
		'' !== drill.title &&
		( undefined === drill.tab || drill.tab === tab ) &&
		( undefined === drill.view || drill.view === view );

	if ( ! drilled ) {
		return { showTabs: tabs.length > 1, back: null, title };
	}

	const current = tabs.find( ( t ) => t.key === tab );
	return {
		showTabs: false,
		back: {
			label: current && current.label ? current.label : title,
			url: ( current && current.url ) || ( area && area.url ) || '',
		},
		title: drill.title,
	};
}

/**
 * The `header` a screen gets, bound to the tab and view it was drawn for.
 *
 * `drillIn( { title } )` records the title for this tab and view;
 * `drillIn()` with no title, and `clear()`, remove it. Neither touches a
 * drill-in another tab or view recorded, so a screen that unmounts after its
 * successor mounted cannot erase the successor's title.
 *
 * @param {Function} set  A state setter that takes an updater, like React's.
 * @param {string}   tab  The tab the screen is drawn for.
 * @param {string}   view The view the screen is drawn for, or ''.
 * @return {{drillIn: Function, clear: Function}} The header.
 */
export function drillHeader( set, tab, view ) {
	const mine = ( drill ) =>
		!! drill && drill.tab === tab && drill.view === view;
	const clear = () => set( ( prev ) => ( mine( prev ) ? null : prev ) );

	const drillIn = ( options ) => {
		const raw = options && options.title;
		const title =
			typeof raw === 'string' || typeof raw === 'number'
				? String( raw ).trim()
				: '';
		if ( ! title ) {
			clear();
			return;
		}
		set( ( prev ) =>
			mine( prev ) && prev.title === title ? prev : { title, tab, view }
		);
	};

	return { drillIn, clear };
}

/**
 * Whether a click on a link should stay in the app: the main button, no
 * modifier key (those open a new tab or window), and nothing else has
 * handled it.
 *
 * @param {Object} event The click event.
 * @return {boolean} True to navigate in the app.
 */
export function isPlainClick( event ) {
	return (
		!! event &&
		! event.defaultPrevented &&
		( undefined === event.button || 0 === event.button ) &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.shiftKey &&
		! event.altKey
	);
}
