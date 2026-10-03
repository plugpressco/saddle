/**
 * The admin app's extension seam — how an addon's bundle contributes UI.
 *
 * Contract (shell v1, mirrored from Mailyard's proven shell pattern):
 * an addon enqueues its own script on the `saddle_admin_enqueue` PHP action
 * with a dependency on Saddle's handle, and registers via wp.hooks at module
 * evaluation — before the app mounts on DOMContentLoaded:
 *
 *   addFilter( 'saddle.admin.settingsCards', 'my-addon/thing', ( cards ) => [
 *       ...cards,
 *       { id: 'my-card', order: 10, requiresShell: 1, Component: MyCard },
 *   ] );
 *
 * Each Component renders as `<Component ui={ ui } shellVersion={ n } />`
 * inside the Settings page. The `ui` context hands across the design-system
 * primitives listed below so addon bundles ship zero @plugpress/ui of their
 * own — both bundles share the one externalized React, so component
 * references cross the boundary fine.
 *
 * The `ui` object is a PUBLIC CONTRACT once any addon ships against it:
 * only ever add symbols under the same shell version — removing or renaming
 * one is a breaking change and bumps SHELL_VERSION (and the PHP
 * SADDLE_SHELL_VERSION define with it). Every symbol here is already
 * imported elsewhere in this bundle, so exposing them adds zero bytes; do
 * not add primitives the app doesn't otherwise use without accepting the
 * bundle-size cost. The 'ui-wide' feature (family look, 2026-10) widened the
 * list on purpose: a sibling's own copy of the kit cannot reach Core's
 * TooltipProvider, ConfirmProvider or Toaster, so the screens use these.
 *
 * The seams, all collected at mount:
 *
 * - `saddle.admin.settingsCards` — a Card on Settings → General.
 * - `saddle.admin.tabs` (v1) — was a whole page with a nav entry. Shell v2
 *   has no in-page nav on Core's pages (WordPress's Saddle submenu picks the
 *   page, #274; only a module's page has a sidebar), so each entry renders as
 *   a section on Settings → General, under its label:
 *
 *   addFilter( 'saddle.admin.tabs', 'my-addon/page', ( tabs ) => [
 *       ...tabs,
 *       { id: 'my-page', label: 'My page', order: 10, requiresShell: 1,
 *         Component: MyPage },
 *   ] );
 *
 * Shell v2 adds, for modules registered with the `saddle_modules` PHP filter
 * (Saddle_Modules):
 *
 * - `saddle.admin.screens` — a module's content for one of its tabs, or for
 *   one page inside a tab: `{ module: 'analytics', tab: 'overview', Component }`
 *   or `{ module: 'analytics', tab: 'reports', sub: 'sources', Component }`.
 *   Core picks the most specific match: module, tab and sub, then the entry
 *   for the tab with no sub. The Component gets `{ ui, kit, module, tab, sub,
 *   view, args, navigate, header, api, shellVersion }`. `sub` is the page
 *   (the tab's first when the address names none or an unknown one; '' for
 *   a tab with no pages), `view` ('' when absent) and `args` (the other query
 *   args, strings) come from
 *   `admin.php?page=saddle-{key}&tab={tab}&sub={sub}&view={view}&campaign=12`.
 *   `navigate( { tab, sub, view, args } )` inside the module changes the
 *   address with pushState and no reload (Back works). `navigate( { tab } )`
 *   opens the tab's first page and clears view and args; `navigate( { sub } )`
 *   opens a page of this tab and clears the view; `navigate( { view, args } )`
 *   keeps the tab and the page. A view and args last only while named.
 *   Feature `view`.
 * - Pages inside a tab (feature `subtabs`): the descriptor's `subtabs` and
 *   `subtab_icons` (see Saddle_Modules::modules()). Core draws the module's
 *   tabs as a left sidebar and a tab's pages as an icon tab row. A module
 *   registers per-page screens only when `saddleShell.has( 'subtabs' )`;
 *   otherwise it registers one screen per tab. The pane is keyed by tab and
 *   view, not by page: one Component shared by a tab's pages keeps its state
 *   and gets the new `sub`; different Components remount.
 * - `header.drillIn( { title } )` (feature `drill-in`, K4): while a `&view=`
 *   is open, a screen names the item it shows. The breadcrumb then reads
 *   `Saddle / Module / Section / title`, and the icon tab row steps aside.
 *   The module and section parts are real links; a plain click navigates in
 *   the app, so the screen unmounts and can save on unmount. Core clears the
 *   title when the view closes, the page or tab changes or the screen
 *   unmounts. Call it as `props.header?.drillIn?.( { title } )`; older Core
 *   has no `header`.
 * - `ui.icons`: Iconoir icons under the kit's old names
 *   and props (`size`, `strokeWidth`, `color`, `className`, `aria-label`).
 *   See icons/kit.js; the names come from scripts/icons.mjs.
 * - `saddle.admin.homeCards` — a card on Dashboard → Overview: `{ id, order,
 *   Component }`.
 * - `saddle.admin.connectionCards` — a card on AI apps.
 * - `kit.SettingsForm` (feature `settings-form`): `<SettingsForm scope="key" />`
 *   draws a module's settings from its schema. Core mounts it on a module's
 *   Settings tab on its own; a module only needs it inside its own screen.
 * - The `saddle.admin.mount` ACTION, for a module that mounts its own app
 *   (`content => 'mount'` in its descriptor). It fires with an element React
 *   never touches, and `{ module, tab }`.
 *
 * - `kit.WaitingLine` (feature `waiting-line`): a status line that polls
 *   `check()` (3 s, backing off to 30 s, paused in a hidden tab) and says the
 *   finest true thing while a task waits. See components/WaitingLine.jsx.
 *
 * Feature-detect, never compare versions: `window.saddleShell.has( 'screens' )`.
 */
import { applyFilters } from '@wordpress/hooks';
import {
	ApplyBar,
	Badge,
	BarList,
	BulkBar,
	Button,
	CalloutCard,
	Card,
	CardContent,
	CardFooter,
	CardGrid,
	CardHeader,
	Checkbox,
	ChecklistItem,
	Chip,
	CodeBlock,
	Collapsible,
	DataTable,
	Dialog,
	Drawer,
	DropdownItem,
	DropdownMenu,
	EmptyState,
	ErrorText,
	Field,
	FilterTabs,
	HelpTip,
	Hint,
	IconButton,
	Input,
	KeyValueList,
	Label,
	LiveIndicator,
	Meter,
	NativeSelect,
	Notice,
	PageHeader,
	Pagination,
	Popover,
	ProgressBar,
	RadioGroup,
	Row,
	RowList,
	SearchInput,
	SegmentedControl,
	Select,
	Skeleton,
	SkeletonCard,
	SkeletonTable,
	SkeletonText,
	Snippet,
	Spinner,
	StatCard,
	StatGrid,
	StatusDot,
	Switch,
	Tabs,
	Textarea,
	toast,
	Tooltip,
	useConfirm,
	VisuallyHidden,
} from '@plugpress/ui';
import SectionHeader from './components/SectionHeader';
import SettingsForm from './components/SettingsForm';
import WaitingLine from './components/WaitingLine';
import { icons } from './icons/kit';

export const SHELL_VERSION = 2;

// `ui.icons`: Iconoir icons under the kit's old names (`ui.icons.ChevronRight`),
// with the kit's props. A module takes its icons from here, never from lucide
// or its own copy. The keys never disappear; new ones are added on request.
export { icons };

// The design-system primitives an addon may use. Every symbol here is one
// this bundle already imports for itself, so exposing it costs nothing. The
// plan hands over the whole @plugpress/ui namespace instead; that waits until
// the library tree-shakes LicensePanel and UpgradeCard, because importing the
// namespace would put licence and upsell UI into free's bundle.
//
// PageHeader stays in this contract (shell v1 addons may use it), but a module
// screen should not draw one: Core's header band already names the page
// ("Saddle / Analytics") and Core draws its sections and pages. Start a screen
// with its first block instead.
export const ui = {
	ApplyBar,
	Badge,
	BarList,
	BulkBar,
	Button,
	CalloutCard,
	Card,
	CardContent,
	CardFooter,
	CardGrid,
	CardHeader,
	Checkbox,
	ChecklistItem,
	Chip,
	CodeBlock,
	Collapsible,
	DataTable,
	Dialog,
	Drawer,
	DropdownItem,
	DropdownMenu,
	EmptyState,
	ErrorText,
	Field,
	FilterTabs,
	HelpTip,
	Hint,
	IconButton,
	Input,
	KeyValueList,
	Label,
	LiveIndicator,
	Meter,
	NativeSelect,
	Notice,
	PageHeader,
	Pagination,
	Popover,
	ProgressBar,
	RadioGroup,
	Row,
	RowList,
	SearchInput,
	SegmentedControl,
	Select,
	Skeleton,
	SkeletonCard,
	SkeletonTable,
	SkeletonText,
	Snippet,
	Spinner,
	StatCard,
	StatGrid,
	StatusDot,
	Switch,
	Tabs,
	Textarea,
	toast,
	Tooltip,
	useConfirm,
	VisuallyHidden,
	icons,
};

// Saddle's own pieces, shared so a module's page looks like Core's.
export const kit = {
	SectionHeader,
	SettingsForm,
	WaitingLine,
};

const FEATURES = [
	'settingsCards',
	'tabs',
	'screens',
	'homeCards',
	'connectionCards',
	'mount',
	'settings-form',
	'waiting-line',
	'ui-wide',
	'view',
	'drill-in',
	'subtabs',
];

// What this shell supports, for addons that feature-detect. Set when the
// bundle evaluates, which is before any addon bundle that depends on it.
if ( typeof window !== 'undefined' ) {
	window.saddleShell = {
		version: SHELL_VERSION,
		has: ( feature ) => FEATURES.includes( feature ),
	};
}

// Shared validation: entries an addon's bundle handed through a filter.
const usable = ( kind, requiredKeys ) => ( entry ) => {
	if ( ! entry || requiredKeys.some( ( k ) => ! entry[ k ] ) ) {
		return false;
	}
	if ( ( entry.requiresShell ?? 1 ) > SHELL_VERSION ) {
		// eslint-disable-next-line no-console
		console.warn(
			`[saddle] ${ kind } "${ entry.id }" needs shell v${ entry.requiresShell }, this is v${ SHELL_VERSION } — skipped. Update Saddle.`
		);
		return false;
	}
	return true;
};

const byOrder = ( a, b ) => ( a.order ?? 50 ) - ( b.order ?? 50 );

/**
 * Contributed Settings cards, validated and ordered.
 *
 * @return {Array} Entries of shape { id, order, requiresShell, Component }.
 */
export function collectSettingsCards() {
	const cards = applyFilters( 'saddle.admin.settingsCards', [], {
		shellVersion: SHELL_VERSION,
		ui,
	} );

	return ( Array.isArray( cards ) ? cards : [] )
		.filter( usable( 'settings card', [ 'id', 'Component' ] ) )
		.sort( byOrder );
}

/**
 * Contributed v1 tabs, validated and ordered. Rendered as sections on
 * Settings → General now that there is no in-page nav.
 *
 * @return {Array} Entries of shape { id, label, order, requiresShell,
 *                 Component }.
 */
export function collectTabs() {
	const tabs = applyFilters( 'saddle.admin.tabs', [], {
		shellVersion: SHELL_VERSION,
		ui,
		icons: [],
	} );

	return ( Array.isArray( tabs ) ? tabs : [] )
		.filter( usable( 'tab', [ 'id', 'label', 'Component' ] ) )
		.sort( byOrder );
}

/**
 * A module's screens: `{ module, tab, sub, Component }` (`sub` optional).
 *
 * @return {Array} Validated entries.
 */
export function collectScreens() {
	const screens = applyFilters( 'saddle.admin.screens', [], {
		shellVersion: SHELL_VERSION,
		ui,
		kit,
	} );

	return ( Array.isArray( screens ) ? screens : [] ).filter(
		( entry ) =>
			entry &&
			typeof entry.module === 'string' &&
			typeof entry.tab === 'string' &&
			( undefined === entry.sub || typeof entry.sub === 'string' ) &&
			entry.Component
	);
}

/**
 * Cards contributed to Dashboard → Overview or AI apps.
 *
 * @param {string} where `home` or `connections`.
 * @return {Array} Entries of shape { id, order, Component }.
 */
export function collectCards( where ) {
	const hook =
		where === 'home'
			? 'saddle.admin.homeCards'
			: 'saddle.admin.connectionCards';
	const cards = applyFilters( hook, [], {
		shellVersion: SHELL_VERSION,
		ui,
		kit,
	} );

	return ( Array.isArray( cards ) ? cards : [] )
		.filter( usable( 'card', [ 'id', 'Component' ] ) )
		.sort( byOrder );
}
