/**
 * The frame's header and layout (M4 in planning/MODULE-LAYOUT.md, K4): the
 * breadcrumb for each page and state, when the sidebar and the icon tab row
 * show, the header a screen gets, which screen draws a page, and which
 * clicks on a link stay in the app.
 */
import {
	canonicalSearch,
	drillHeader,
	findScreen,
	frameHeader,
	sidebarItems,
	tabsHaveIcons,
	isPlainClick,
} from '../../admin/src/frame-logic';
import { readRoute, resolveSub, resolveTab } from '../../admin/src/routes';

const HOME = { label: 'Saddle', url: 'admin.php?page=saddle' };

const crm = {
	key: 'crm',
	title: 'CRM',
	module: true,
	url: 'admin.php?page=saddle-crm',
	tabs: [
		{
			key: 'campaigns',
			label: 'Campaigns',
			url: 'admin.php?page=saddle-crm',
			subtabs: [],
		},
		{
			key: 'contacts',
			label: 'Contacts',
			url: 'admin.php?page=saddle-crm&tab=contacts',
			subtabs: [
				{
					key: 'contacts',
					label: 'Contacts',
					url: 'admin.php?page=saddle-crm&tab=contacts',
					icon: 'user',
				},
				{
					key: 'groups',
					label: 'Groups',
					url: 'admin.php?page=saddle-crm&tab=contacts&sub=groups',
					icon: 'group',
				},
			],
		},
		{
			key: 'settings',
			label: 'Settings',
			url: 'admin.php?page=saddle-crm&tab=settings',
		},
	],
};

const context = {
	key: 'context',
	title: 'Context',
	module: false,
	url: 'admin.php?page=saddle-context',
	tabs: [
		{
			key: 'overview',
			label: 'Context',
			url: 'admin.php?page=saddle-context',
			subtabs: [],
		},
	],
};

const home = {
	key: 'home',
	title: 'Home',
	module: false,
	url: 'admin.php?page=saddle',
	tabs: [ { key: 'overview', label: 'Home', url: 'admin.php?page=saddle' } ],
};

const labels = ( head ) => head.crumbs.map( ( c ) => c.label );

describe( 'frameHeader: the breadcrumb', () => {
	it( 'reads just Saddle on Home, as the current page', () => {
		const head = frameHeader( { area: home, tab: 'overview', home: HOME } );
		expect( head.crumbs ).toEqual( [
			{ key: 'home', label: 'Saddle', url: '' },
		] );
		expect( head.title ).toBe( 'Saddle' );
	} );

	it( 'reads Saddle / Page on a Core page, Saddle a link to Home', () => {
		const head = frameHeader( {
			area: context,
			tab: 'overview',
			home: HOME,
		} );
		expect( head.crumbs ).toEqual( [
			{ key: 'home', label: 'Saddle', url: 'admin.php?page=saddle' },
			{ key: 'current', label: 'Context', url: '' },
		] );
	} );

	it( 'reads Saddle / Module on a module page, whatever the section', () => {
		expect(
			labels(
				frameHeader( {
					area: crm,
					tab: 'contacts',
					sub: 'groups',
					home: HOME,
				} )
			)
		).toEqual( [ 'Saddle', 'CRM' ] );
	} );

	it( 'names first run as Welcome', () => {
		const head = frameHeader( {
			area: home,
			tab: 'setup',
			crumb: 'Welcome',
			home: HOME,
		} );
		expect( labels( head ) ).toEqual( [ 'Saddle', 'Welcome' ] );
		expect( head.crumbs[ 0 ].url ).toBe( 'admin.php?page=saddle' );
		expect( head.showSidebar ).toBe( false );
	} );

	it( 'drills in: Saddle / Module / Section / title, all but the last links', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'campaigns',
			view: 'setup',
			drill: {
				title: 'Spring sale',
				tab: 'campaigns',
				sub: '',
				view: 'setup',
			},
			home: HOME,
		} );
		expect( head.crumbs ).toEqual( [
			{ key: 'home', label: 'Saddle', url: 'admin.php?page=saddle' },
			{ key: 'module', label: 'CRM', url: 'admin.php?page=saddle-crm' },
			{
				key: 'section',
				label: 'Campaigns',
				url: 'admin.php?page=saddle-crm',
			},
			{ key: 'current', label: 'Spring sale', url: '' },
		] );
		expect( head.title ).toBe( 'Spring sale' );
		expect( head.drilled ).toBe( true );
	} );

	it( 'sends the section crumb back to the page the item was opened from', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'contacts',
			sub: 'groups',
			view: 'group',
			drill: {
				title: 'VIP',
				tab: 'contacts',
				sub: 'groups',
				view: 'group',
			},
			home: HOME,
		} );
		expect( head.crumbs[ 2 ] ).toEqual( {
			key: 'section',
			label: 'Contacts',
			url: 'admin.php?page=saddle-crm&tab=contacts&sub=groups',
		} );
	} );

	it( 'ignores a drill-in once the view is closed', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'campaigns',
			view: '',
			drill: { title: 'Spring sale', tab: 'campaigns', view: 'setup' },
			home: HOME,
		} );
		expect( labels( head ) ).toEqual( [ 'Saddle', 'CRM' ] );
		expect( head.drilled ).toBe( false );
	} );

	it( 'ignores a drill-in from another tab, page or view', () => {
		const drill = {
			title: 'VIP',
			tab: 'contacts',
			sub: 'groups',
			view: 'group',
		};
		const at = ( tab, sub, view ) =>
			frameHeader( { area: crm, tab, sub, view, drill, home: HOME } )
				.drilled;
		expect( at( 'contacts', 'groups', 'group' ) ).toBe( true );
		expect( at( 'campaigns', 'groups', 'group' ) ).toBe( false );
		expect( at( 'contacts', 'contacts', 'group' ) ).toBe( false );
		expect( at( 'contacts', 'groups', 'other' ) ).toBe( false );
	} );

	it( 'ignores an empty or non-string title', () => {
		[ { title: '' }, { title: null }, { title: 42 }, {} ].forEach(
			( drill ) =>
				expect(
					frameHeader( {
						area: crm,
						tab: 'campaigns',
						view: 'setup',
						drill,
						home: HOME,
					} ).drilled
				).toBe( false )
		);
	} );

	it( 'falls back to the module when the tab is unknown', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'gone',
			view: 'x',
			drill: { title: 'Item' },
			home: HOME,
		} );
		expect( head.crumbs[ 2 ] ).toEqual( {
			key: 'section',
			label: 'CRM',
			url: 'admin.php?page=saddle-crm',
		} );
	} );

	it( 'survives a page with no tabs and no home', () => {
		const head = frameHeader( { area: { title: 'X' }, tab: '' } );
		expect( labels( head ) ).toEqual( [ '', 'X' ] );
		expect( head.showTabs ).toBe( false );
		expect( head.showSidebar ).toBe( false );
		expect( head.showSubtabs ).toBe( false );
	} );
} );

describe( 'frameHeader: sidebar and tab rows', () => {
	it( 'gives a module the sidebar and no header tab row', () => {
		const head = frameHeader( { area: crm, tab: 'campaigns', home: HOME } );
		expect( head.showSidebar ).toBe( true );
		expect( head.showTabs ).toBe( false );
		expect( head.showSubtabs ).toBe( false );
	} );

	it( 'draws the icon tab row in a section with two or more pages', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'contacts',
			sub: 'contacts',
			home: HOME,
		} );
		expect( head.showSubtabs ).toBe( true );
	} );

	it( 'hides the icon tab row in a drill-in, and keeps the sidebar', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'contacts',
			sub: 'groups',
			view: 'group',
			drill: {
				title: 'VIP',
				tab: 'contacts',
				sub: 'groups',
				view: 'group',
			},
			home: HOME,
		} );
		expect( head.showSubtabs ).toBe( false );
		expect( head.showSidebar ).toBe( true );
	} );

	it( 'keeps a Core page with two tabs on its header row, no sidebar', () => {
		const twoTabs = {
			...context,
			tabs: [
				...context.tabs,
				{ key: 'more', label: 'More', url: 'x', subtabs: [] },
			],
		};
		const head = frameHeader( {
			area: twoTabs,
			tab: 'overview',
			home: HOME,
		} );
		expect( head.showTabs ).toBe( true );
		expect( head.showSidebar ).toBe( false );
	} );

	it( 'draws no tab row for a one-screen Core page', () => {
		const head = frameHeader( {
			area: context,
			tab: 'overview',
			home: HOME,
		} );
		expect( head.showTabs ).toBe( false );
		expect( head.showSidebar ).toBe( false );
	} );
} );

describe( 'sidebarItems', () => {
	it( 'keeps the module order and moves Settings last', () => {
		const { items, settings } = sidebarItems( [
			{ key: 'overview' },
			{ key: 'settings' },
			{ key: 'links' },
		] );
		expect( items.map( ( t ) => t.key ) ).toEqual( [
			'overview',
			'links',
		] );
		expect( settings ).toEqual( { key: 'settings' } );
	} );

	it( 'has no Settings when the module has none', () => {
		expect( sidebarItems( [ { key: 'overview' } ] ).settings ).toBeNull();
		expect( sidebarItems( null ) ).toEqual( { items: [], settings: null } );
	} );
} );

describe( 'findScreen', () => {
	const A = () => null;
	const B = () => null;
	const C = () => null;
	const screens = [
		{ module: 'rank', tab: 'visibility', Component: A },
		{ module: 'rank', tab: 'visibility', sub: 'traffic', Component: B },
		{ module: 'rank', tab: 'overview', sub: 'audit', Component: C },
		{ module: 'crm', tab: 'visibility', sub: 'answers', Component: C },
	];

	it( 'picks module, tab and page first', () => {
		expect(
			findScreen( screens, 'rank', 'visibility', 'traffic' ).Component
		).toBe( B );
	} );

	it( 'falls back to the screen for the whole tab', () => {
		expect(
			findScreen( screens, 'rank', 'visibility', 'answers' ).Component
		).toBe( A );
		expect(
			findScreen( screens, 'rank', 'visibility', '' ).Component
		).toBe( A );
	} );

	it( 'never uses a screen registered for another page', () => {
		expect(
			findScreen( screens, 'rank', 'overview', 'summary' )
		).toBeNull();
		expect( findScreen( screens, 'rank', 'overview', '' ) ).toBeNull();
		expect(
			findScreen( screens, 'rank', 'overview', 'audit' ).Component
		).toBe( C );
	} );

	it( 'survives no screens', () => {
		expect( findScreen( null, 'rank', 'overview', '' ) ).toBeNull();
	} );
} );

describe( 'drillHeader', () => {
	// A setter that behaves like React's: it takes a value or an updater.
	const store = ( initial = null ) => {
		const box = { value: initial };
		box.set = ( next ) => {
			box.value = typeof next === 'function' ? next( box.value ) : next;
		};
		return box;
	};

	it( 'records the title for its own tab and view', () => {
		const s = store();
		drillHeader( s.set, 'campaigns', 'setup' ).drillIn( {
			title: '  Spring sale ',
		} );
		expect( s.value ).toEqual( {
			title: 'Spring sale',
			tab: 'campaigns',
			sub: '',
			view: 'setup',
		} );
	} );

	it( 'binds the title to the page as well', () => {
		const s = store();
		const groups = drillHeader( s.set, 'contacts', 'group', 'groups' );
		const segments = drillHeader( s.set, 'contacts', 'group', 'segments' );
		groups.drillIn( { title: 'VIP' } );
		expect( s.value ).toEqual( {
			title: 'VIP',
			tab: 'contacts',
			sub: 'groups',
			view: 'group',
		} );
		segments.clear();
		expect( s.value.title ).toBe( 'VIP' );
		groups.clear();
		expect( s.value ).toBeNull();
	} );

	it( 'keeps the same object when the title has not changed', () => {
		const s = store();
		const header = drillHeader( s.set, 'campaigns', 'setup' );
		header.drillIn( { title: 'Spring sale' } );
		const first = s.value;
		header.drillIn( { title: 'Spring sale' } );
		expect( s.value ).toBe( first );
		header.drillIn( { title: 'Summer sale' } );
		expect( s.value.title ).toBe( 'Summer sale' );
	} );

	it( 'clears with no title, and with clear()', () => {
		const s = store();
		const header = drillHeader( s.set, 'campaigns', 'setup' );
		header.drillIn( { title: 'Spring sale' } );
		header.drillIn( { title: '' } );
		expect( s.value ).toBeNull();
		header.drillIn( { title: 'Spring sale' } );
		header.drillIn();
		expect( s.value ).toBeNull();
		header.drillIn( { title: 'Spring sale' } );
		header.clear();
		expect( s.value ).toBeNull();
	} );

	it( 'never clears a drill-in another view set', () => {
		const s = store();
		const setup = drillHeader( s.set, 'campaigns', 'setup' );
		const review = drillHeader( s.set, 'campaigns', 'review' );

		// The next view's screen mounts and names itself, then the old
		// screen's unmount runs.
		review.drillIn( { title: 'Spring sale' } );
		setup.clear();
		expect( s.value ).toEqual( {
			title: 'Spring sale',
			tab: 'campaigns',
			sub: '',
			view: 'review',
		} );
	} );

	it( 'accepts a number as a title', () => {
		const s = store();
		drillHeader( s.set, 'contacts', 'person' ).drillIn( { title: 12 } );
		expect( s.value.title ).toBe( '12' );
	} );

	it( 'feeds frameHeader end to end', () => {
		const s = store();
		drillHeader( s.set, 'campaigns', 'setup' ).drillIn( {
			title: 'Spring sale',
		} );
		const head = frameHeader( {
			area: crm,
			tab: 'campaigns',
			view: 'setup',
			drill: s.value,
			home: HOME,
		} );
		expect( head.drilled ).toBe( true );
		expect( head.title ).toBe( 'Spring sale' );
	} );
} );

describe( 'isPlainClick', () => {
	const click = ( extra = {} ) => ( {
		button: 0,
		metaKey: false,
		ctrlKey: false,
		shiftKey: false,
		altKey: false,
		defaultPrevented: false,
		...extra,
	} );

	it( 'keeps a plain left click in the app', () => {
		expect( isPlainClick( click() ) ).toBe( true );
	} );

	it( 'leaves new-tab and new-window clicks to the browser', () => {
		expect( isPlainClick( click( { metaKey: true } ) ) ).toBe( false );
		expect( isPlainClick( click( { ctrlKey: true } ) ) ).toBe( false );
		expect( isPlainClick( click( { shiftKey: true } ) ) ).toBe( false );
		expect( isPlainClick( click( { altKey: true } ) ) ).toBe( false );
		expect( isPlainClick( click( { button: 1 } ) ) ).toBe( false );
	} );

	it( 'leaves a click someone else handled', () => {
		expect( isPlainClick( click( { defaultPrevented: true } ) ) ).toBe(
			false
		);
		expect( isPlainClick( null ) ).toBe( false );
	} );
} );

describe( 'tabsHaveIcons', () => {
	it( 'is true only when every tab names an icon', () => {
		expect(
			tabsHaveIcons( [
				{ key: 'overview', icon: 'dashboard-dots' },
				{ key: 'settings', icon: 'settings' },
			] )
		).toBe( true );
		expect(
			tabsHaveIcons( [
				{ key: 'overview', icon: 'dashboard-dots' },
				{ key: 'content', icon: '' },
				{ key: 'settings', icon: 'settings' },
			] )
		).toBe( false );
		expect( tabsHaveIcons( [ { key: 'overview' } ] ) ).toBe( false );
	} );

	it( 'is false for no tabs', () => {
		expect( tabsHaveIcons( null ) ).toBe( false );
		expect( tabsHaveIcons( [] ) ).toBe( false );
	} );
} );

describe( 'canonicalSearch (P20)', () => {
	const area = {
		key: 'fieldnotes',
		tabs: [
			{ key: 'overview', label: 'Overview' },
			{
				key: 'reports',
				label: 'Reports',
				subtabs: [
					{ key: 'sources', label: 'Sources' },
					{ key: 'pages', label: 'Pages' },
				],
			},
		],
	};

	// What App draws for an address: the same three calls it makes.
	const drawn = ( search ) => {
		const here = readRoute( search );
		const tab = resolveTab( area, here.tab );
		return { tab, sub: resolveSub( area, tab, here.sub ) };
	};
	const fix = ( search ) => canonicalSearch( search, drawn( search ) );

	it( 'leaves a good address alone', () => {
		expect( fix( '?page=saddle-fieldnotes' ) ).toBeNull();
		expect(
			fix( '?page=saddle-fieldnotes&tab=reports&sub=pages' )
		).toBeNull();
		expect( fix( '?page=saddle-fieldnotes&tab=reports' ) ).toBeNull();
	} );

	it( 'drops an unknown page and keeps the tab', () => {
		expect( fix( '?page=saddle-fieldnotes&tab=reports&sub=nope' ) ).toBe(
			'?page=saddle-fieldnotes&tab=reports'
		);
	} );

	it( 'drops an unknown tab, and a page the first tab doesn’t have', () => {
		expect( fix( '?page=saddle-fieldnotes&tab=nope' ) ).toBe(
			'?page=saddle-fieldnotes'
		);
		expect( fix( '?page=saddle-fieldnotes&tab=nope&sub=pages' ) ).toBe(
			'?page=saddle-fieldnotes'
		);
		expect( fix( '?page=saddle-fieldnotes&tab=' ) ).toBe(
			'?page=saddle-fieldnotes'
		);
	} );

	it( 'writes a page the way the app read it', () => {
		expect( fix( '?page=saddle-fieldnotes&tab=reports&sub=Pages' ) ).toBe(
			'?page=saddle-fieldnotes&tab=reports&sub=pages'
		);
	} );

	it( 'keeps every other argument', () => {
		expect(
			fix( '?page=saddle-fieldnotes&tab=nope&view=alpha&campaign=12' )
		).toBe( '?page=saddle-fieldnotes&view=alpha&campaign=12' );
		expect(
			canonicalSearch( '?page=saddle&tab=bogus&setup=1', {
				tab: 'overview',
				sub: '',
			} )
		).toBe( '?page=saddle&setup=1' );
	} );

	it( 'drops a page on a tab that has none', () => {
		expect( fix( '?page=saddle-fieldnotes&sub=pages' ) ).toBe(
			'?page=saddle-fieldnotes'
		);
	} );
} );
