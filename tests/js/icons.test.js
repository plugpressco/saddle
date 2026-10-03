/**
 * The Iconoir set (K2, K3): every generated entry resolves to a file, NavIcon
 * draws nothing for an unknown name, and `ui.icons` keeps every key the kit's
 * icon set had, with the kit's props.
 */
const fs = require( 'fs' );
const path = require( 'path' );

// Jest cannot load an .svg file as a module; svgr does that in the build.
// Each file is mocked as a component that carries its name, so a test can
// tell which file an entry points at.
const mockDir = path.join( __dirname, '../../assets/icons' );
const mockFiles = fs
	.readdirSync( mockDir )
	.filter( ( file ) => file.endsWith( '.svg' ) );
mockFiles.forEach( ( mockFile ) => {
	jest.mock( `../../assets/icons/${ mockFile }`, () => {
		const mockIcon = ( props ) => ( { type: 'svg', props } );
		mockIcon.file = mockFile;
		return { ReactComponent: mockIcon };
	} );
} );

const { byName, uiNames, NavIcon } = require( '../../admin/src/icons/iconoir' );
const { icons, kitIcon } = require( '../../admin/src/icons/kit' );

// The 59 keys `ui.icons` had when it was the kit's set. None may disappear
// (Analytics has no fallback for a missing key).
const KIT_KEYS = [
	'Activity',
	'AlertCircle',
	'AlertTriangle',
	'ArrowLeft',
	'ArrowRight',
	'ArrowUpRight',
	'BarChart',
	'Bell',
	'BookOpen',
	'Check',
	'CheckCircle',
	'ChevronDown',
	'ChevronLeft',
	'ChevronRight',
	'ChevronUp',
	'Clock',
	'Copy',
	'Download',
	'ExternalLink',
	'Eye',
	'EyeOff',
	'FileText',
	'Filter',
	'Globe',
	'Grip',
	'Help',
	'Home',
	'Inbox',
	'Info',
	'Key',
	'Link',
	'List',
	'Loader',
	'Lock',
	'Mail',
	'Minus',
	'MoreHorizontal',
	'MoreVertical',
	'Pencil',
	'Plug',
	'Plus',
	'Refresh',
	'Search',
	'Send',
	'Settings',
	'Shield',
	'ShieldCheck',
	'Sparkles',
	'Star',
	'Trash',
	'TrendingUp',
	'Upload',
	'User',
	'Users',
	'Wand',
	'X',
	'XCircle',
	'Zap',
	'Dashboard',
];

describe( 'byName', () => {
	it( 'has one entry per file in assets/icons, each pointing at its file', () => {
		const names = mockFiles.map( ( file ) => file.slice( 0, -4 ) ).sort();
		expect( Object.keys( byName ).sort() ).toEqual( names );
		names.forEach( ( name ) => {
			expect( typeof byName[ name ] ).toBe( 'function' );
			expect( byName[ name ].file ).toBe( `${ name }.svg` );
		} );
	} );

	it( 'holds the menu, tab and header icons from the brief', () => {
		[
			'home-simple-door',
			'graph-up',
			'search-engine',
			'send-mail',
			'sparks',
			'puzzle',
			'brain',
			'settings',
			'dashboard-dots',
			'reports',
			'eye',
			'search-window',
			'multiple-pages',
			'link',
			'mail-out',
			'group',
			'nav-arrow-left',
			'bell',
		].forEach( ( name ) => expect( byName[ name ] ).toBeDefined() );
	} );

	it( 'holds every section and page icon in the module layout maps', () => {
		[
			'dashboard-dots',
			'clipboard-check',
			'eye',
			'quote-message',
			'activity',
			'chat-lines',
			'shield-check',
			'page',
			'search-window',
			'home-simple-door',
			'share-android',
			'view-grid',
			'archive',
			'check-circle',
			'multiple-pages',
			'media-image',
			'link',
			'link-xmark',
			'warning-triangle',
			'repeat',
			'settings',
			'calendar',
			'import',
			'mail-out',
			'group',
			'user',
			'filter',
			'send',
			'page-edit',
			'sparks',
			'lock',
			'reports',
			'globe',
			'walking',
			'cpu',
		].forEach( ( name ) => expect( byName[ name ] ).toBeDefined() );
	} );
} );

describe( 'NavIcon', () => {
	it( 'draws the named icon at 16px, hidden from assistive technology', () => {
		const el = NavIcon( { name: 'settings' } );
		expect( el.type ).toBe( byName.settings );
		expect( el.props ).toMatchObject( {
			width: 16,
			height: 16,
			'aria-hidden': 'true',
			focusable: 'false',
			className: 'saddle-nav-icon',
		} );
		expect( NavIcon( { name: 'bell', size: 20 } ).props.width ).toBe( 20 );
	} );

	it( 'draws nothing for an unknown or missing name', () => {
		expect( NavIcon( { name: 'no-such-icon' } ) ).toBeNull();
		expect( NavIcon( { name: '' } ) ).toBeNull();
		expect( NavIcon( {} ) ).toBeNull();
		expect( NavIcon( { name: 'toString' } ) ).toBeNull();
		expect( NavIcon( { name: '__proto__' } ) ).toBeNull();
	} );
} );

describe( 'ui.icons', () => {
	it( 'keeps all 59 of the kit keys', () => {
		expect( KIT_KEYS ).toHaveLength( 59 );
		KIT_KEYS.forEach( ( key ) => {
			expect( typeof icons[ key ] ).toBe( 'function' );
		} );
	} );

	it( 'draws every key with a file that exists', () => {
		Object.keys( uiNames ).forEach( ( key ) => {
			expect( byName[ uiNames[ key ] ] ).toBeDefined();
			expect( Object.keys( icons ) ).toContain( key );
		} );
		expect( Object.keys( icons ).sort() ).toEqual(
			Object.keys( uiNames ).sort()
		);
	} );

	it( 'carries the keys added on request', () => {
		expect( uiNames.Grid ).toBe( 'view-grid' );
		expect( uiNames.ArrowLeftRight ).toBe( 'arrow-separate' );
	} );

	it( 'maps the keys the brief names', () => {
		expect( uiNames ).toMatchObject( {
			AlertCircle: 'warning-circle',
			BarChart: 'stats-report',
			ChevronDown: 'nav-arrow-down',
			ExternalLink: 'open-new-window',
			Inbox: 'mail-in',
			Loader: 'refresh-double',
			MoreHorizontal: 'more-horiz',
			Sparkles: 'sparks',
			Users: 'group',
			X: 'xmark',
			Zap: 'flash',
			Dashboard: 'dashboard-dots',
		} );
	} );
} );

describe( 'kitIcon', () => {
	const Search = icons.Search;

	it( 'takes the kit’s props: size, strokeWidth, className', () => {
		const el = Search( { size: 16, className: 'x', strokeWidth: 2 } );
		expect( el.type ).toBe( byName.search );
		expect( el.props ).toMatchObject( {
			width: 16,
			height: 16,
			strokeWidth: 2,
			className: 'x',
			'aria-hidden': true,
			focusable: 'false',
		} );
	} );

	it( 'defaults to the kit’s 18px box and Iconoir’s 1.5 stroke', () => {
		const el = Search( {} );
		expect( el.props.width ).toBe( 18 );
		expect( el.props.height ).toBe( 18 );
		expect( el.props.strokeWidth ).toBe( 1.5 );
	} );

	it( 'is announced when it has a label', () => {
		const el = Search( { 'aria-label': 'Search' } );
		expect( el.props[ 'aria-label' ] ).toBe( 'Search' );
		expect( el.props[ 'aria-hidden' ] ).toBeUndefined();
	} );

	it( 'passes colour and other attributes through', () => {
		const el = Search( { color: '#b32d2e', 'data-x': '1' } );
		expect( el.props.color ).toBe( '#b32d2e' );
		expect( el.props[ 'data-x' ] ).toBe( '1' );
	} );

	it( 'names itself for React’s dev tools', () => {
		expect( kitIcon( byName.search, 'Search' ).displayName ).toBe(
			'SearchIcon'
		);
	} );
} );
