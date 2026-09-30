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
 * bundle-size cost.
 *
 * The seams, all collected at mount:
 *
 * - `saddle.admin.settingsCards` — a Card on Settings → General.
 * - `saddle.admin.tabs` (v1) — was a whole page with a nav entry. Shell v2
 *   has no in-page nav (WordPress's Saddle submenu is the nav, #274), so each
 *   entry renders as a section on Settings → General, under its label:
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
 * - `saddle.admin.screens` — a module's content for one of its tabs:
 *   `{ module: 'analytics', tab: 'overview', Component }`. The Component gets
 *   `{ ui, kit, module, tab, navigate, api, shellVersion }`.
 * - `saddle.admin.homeCards` — a card on Home → Overview: `{ id, order,
 *   Component }`.
 * - `saddle.admin.connectionCards` — a card on Connections → Apps.
 * - `kit.SettingsForm` (feature `settings-form`): `<SettingsForm scope="key" />`
 *   draws a module's settings from its schema. Core mounts it on a module's
 *   Settings tab on its own; a module only needs it inside its own screen.
 * - The `saddle.admin.mount` ACTION, for a module that mounts its own app
 *   (`content => 'mount'` in its descriptor). It fires with an element React
 *   never touches, and `{ module, tab }`.
 *
 * Feature-detect, never compare versions: `window.saddleShell.has( 'screens' )`.
 */
import { applyFilters } from '@wordpress/hooks';
import {
	Badge,
	Button,
	CalloutCard,
	Card,
	CardContent,
	CardGrid,
	CardHeader,
	ChecklistItem,
	CodeBlock,
	Collapsible,
	EmptyState,
	Field,
	HelpTip,
	Input,
	LiveIndicator,
	NativeSelect,
	Notice,
	PageHeader,
	Row,
	RowList,
	Snippet,
	Spinner,
	StatCard,
	StatGrid,
	StatusDot,
	Switch,
	Tabs,
	Textarea,
	Tooltip,
	toast,
	useConfirm,
} from '@plugpress/ui';
import SectionHeader from './components/SectionHeader';
import SettingsForm from './components/SettingsForm';

export const SHELL_VERSION = 2;

// The design-system primitives an addon may use. Every symbol here is one
// this bundle already imports for itself, so exposing it costs nothing. The
// plan hands over the whole @plugpress/ui namespace instead; that waits until
// the library tree-shakes LicensePanel and UpgradeCard, because importing the
// namespace would put licence and upsell UI into free's bundle.
export const ui = {
	Badge,
	Button,
	CalloutCard,
	Card,
	CardContent,
	CardGrid,
	CardHeader,
	ChecklistItem,
	CodeBlock,
	Collapsible,
	EmptyState,
	Field,
	HelpTip,
	Input,
	LiveIndicator,
	NativeSelect,
	Notice,
	PageHeader,
	Row,
	RowList,
	Snippet,
	Spinner,
	StatCard,
	StatGrid,
	StatusDot,
	Switch,
	Tabs,
	Textarea,
	Tooltip,
	toast,
	useConfirm,
};

// Saddle's own pieces, shared so a module's page looks like Core's.
export const kit = {
	SectionHeader,
	SettingsForm,
};

const FEATURES = [
	'settingsCards',
	'tabs',
	'screens',
	'homeCards',
	'connectionCards',
	'mount',
	'settings-form',
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
 * A module's screens: `{ module, tab, Component }`.
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
			entry.Component
	);
}

/**
 * Cards contributed to Home → Overview or Connections → Apps.
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
