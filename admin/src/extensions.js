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
 * Two seams so far, both collected at mount:
 *
 * - `saddle.admin.settingsCards` — a Card rendered inside the Settings page.
 * - `saddle.admin.tabs` — a whole page of its own, with a nav entry:
 *
 *   addFilter( 'saddle.admin.tabs', 'my-addon/page', ( tabs ) => [
 *       ...tabs,
 *       { id: 'my-page', label: 'My page', icon: 'key', group: 'footer',
 *         order: 10, requiresShell: 1, Component: MyPage },
 *   ] );
 *
 *   `icon` is a string key from ICONS below (icons can't cross the bundle
 *   boundary unresolved); `group` is a NAV_GROUPS key from App.jsx
 *   ('top' | 'ai' | 'connect' | 'monitor') or 'footer' (the default —
 *   next to Settings). The page routes at `#<id>`.
 */
import { applyFilters } from '@wordpress/hooks';
import {
	Badge,
	Button,
	Card,
	CardContent,
	CardHeader,
	Field,
	Input,
	KeyIcon,
	Notice,
	PageHeader,
	PlugIcon,
	Row,
	RowList,
	SettingsIcon,
	ShieldIcon,
	Snippet,
	Spinner,
	Switch,
	toast,
	useConfirm,
} from '@plugpress/ui';

export const SHELL_VERSION = 1;

export const ui = {
	Badge,
	Button,
	Card,
	CardContent,
	CardHeader,
	Field,
	Input,
	Notice,
	PageHeader,
	Row,
	RowList,
	Snippet,
	Spinner,
	Switch,
	toast,
	useConfirm,
};

// Nav icons an extension tab may pick by key. A tiny, deliberate set — every
// entry costs bundle bytes, so it grows only when a real tab needs one.
export const ICONS = {
	key: KeyIcon,
	plug: PlugIcon,
	settings: SettingsIcon,
	shield: ShieldIcon,
};

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
 * Contributed whole-page tabs, validated and ordered.
 *
 * @return {Array} Entries of shape { id, label, icon, group, order,
 *                 requiresShell, Component } — icon resolved to a component,
 *                 group defaulted to 'footer'.
 */
export function collectTabs() {
	const tabs = applyFilters( 'saddle.admin.tabs', [], {
		shellVersion: SHELL_VERSION,
		ui,
		icons: Object.keys( ICONS ),
	} );

	return ( Array.isArray( tabs ) ? tabs : [] )
		.filter( usable( 'tab', [ 'id', 'label', 'Component' ] ) )
		.map( ( tab ) => ( {
			...tab,
			group: tab.group || 'footer',
			Icon: ICONS[ tab.icon ] || ICONS.plug,
		} ) )
		.sort( byOrder );
}
