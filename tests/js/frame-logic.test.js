/**
 * The drill-in header (K4): what the frame draws for each state, the header
 * a screen gets, and which clicks on the back link stay in the app.
 */
import {
	drillHeader,
	frameHeader,
	tabsHaveIcons,
	isPlainClick,
} from '../../admin/src/frame-logic';

const crm = {
	key: 'crm',
	title: 'CRM',
	url: 'admin.php?page=saddle-crm',
	tabs: [
		{
			key: 'campaigns',
			label: 'Campaigns',
			url: 'admin.php?page=saddle-crm',
		},
		{
			key: 'contacts',
			label: 'Contacts',
			url: 'admin.php?page=saddle-crm&tab=contacts',
		},
	],
};

const onePage = {
	key: 'context',
	title: 'Context',
	url: 'admin.php?page=saddle-context',
	tabs: [
		{
			key: 'overview',
			label: 'Context',
			url: 'admin.php?page=saddle-context',
		},
	],
};

describe( 'frameHeader', () => {
	it( 'draws the tab row and the page title with no drill-in', () => {
		expect(
			frameHeader( {
				area: crm,
				tab: 'campaigns',
				view: '',
				drill: null,
			} )
		).toEqual( { showTabs: true, back: null, title: 'CRM' } );
	} );

	it( 'draws no tab row for a one-screen page', () => {
		expect(
			frameHeader( {
				area: onePage,
				tab: 'overview',
				view: '',
				drill: null,
			} )
		).toEqual( { showTabs: false, back: null, title: 'Context' } );
	} );

	it( 'keeps the tab row in a view the screen did not name', () => {
		expect(
			frameHeader( {
				area: crm,
				tab: 'campaigns',
				view: 'setup',
				drill: null,
			} )
		).toEqual( { showTabs: true, back: null, title: 'CRM' } );
	} );

	it( 'drills in: back to the tab, then the title, no tab row', () => {
		expect(
			frameHeader( {
				area: crm,
				tab: 'campaigns',
				view: 'setup',
				drill: {
					title: 'Spring sale',
					tab: 'campaigns',
					view: 'setup',
				},
			} )
		).toEqual( {
			showTabs: false,
			back: { label: 'Campaigns', url: 'admin.php?page=saddle-crm' },
			title: 'Spring sale',
		} );
	} );

	it( 'goes back to the current tab, not the first one', () => {
		const head = frameHeader( {
			area: crm,
			tab: 'contacts',
			view: 'person',
			drill: { title: 'Ada', tab: 'contacts', view: 'person' },
		} );
		expect( head.back ).toEqual( {
			label: 'Contacts',
			url: 'admin.php?page=saddle-crm&tab=contacts',
		} );
	} );

	it( 'ignores a drill-in once the view is closed', () => {
		expect(
			frameHeader( {
				area: crm,
				tab: 'campaigns',
				view: '',
				drill: {
					title: 'Spring sale',
					tab: 'campaigns',
					view: 'setup',
				},
			} )
		).toEqual( { showTabs: true, back: null, title: 'CRM' } );
	} );

	it( 'ignores a drill-in from another tab or view', () => {
		const drill = { title: 'Spring sale', tab: 'campaigns', view: 'setup' };
		expect(
			frameHeader( { area: crm, tab: 'contacts', view: 'setup', drill } )
				.back
		).toBeNull();
		expect(
			frameHeader( {
				area: crm,
				tab: 'campaigns',
				view: 'review',
				drill,
			} ).back
		).toBeNull();
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
					} ).back
				).toBeNull()
		);
	} );

	it( 'falls back to the page when the tab is unknown', () => {
		expect(
			frameHeader( {
				area: crm,
				tab: 'gone',
				view: 'x',
				drill: { title: 'Item' },
			} )
		).toEqual( {
			showTabs: false,
			back: { label: 'CRM', url: 'admin.php?page=saddle-crm' },
			title: 'Item',
		} );
	} );

	it( 'survives a page with no tabs', () => {
		expect(
			frameHeader( {
				area: { title: 'X' },
				tab: '',
				view: '',
				drill: null,
			} )
		).toEqual( { showTabs: false, back: null, title: 'X' } );
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
			view: 'setup',
		} );
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
		} );
		expect( head.showTabs ).toBe( false );
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
