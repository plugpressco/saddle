import {
	badgeFor,
	groupServices,
	initialOf,
	metaLine,
	replaceRecord,
	roleLabel,
	toolCount,
	kindLabel,
} from '../../admin/src/services-logic';

const unsplash = {
	key: 'unsplash',
	name: 'Unsplash',
	description: 'Stock photos',
	kind: 'account',
	source: 'built-in',
	status: 'needs_key',
	sends: [ { host: 'api.unsplash.com', what: 'x', when: 'y' } ],
	tools: [ { name: 'a' }, { name: 'b' } ],
};
const yoast = {
	key: 'yoast',
	name: 'Yoast SEO',
	description: 'SEO plugin',
	kind: 'plugin',
	source: 'built-in',
	status: 'detected',
	sends: [],
	tools: [ { name: 'a' } ],
};

describe( 'groupServices', () => {
	it( 'groups by kind in page order and drops empty sections', () => {
		const groups = groupServices( [ yoast, unsplash ] );
		expect( groups.map( ( g ) => g.kind ) ).toEqual( [
			'account',
			'plugin',
		] );
		expect( groups[ 0 ].title ).toBe( 'Accounts' );
		expect( groups[ 1 ].rows ).toEqual( [ yoast ] );
	} );
	it( 'copes with nothing, and ignores unknown kinds', () => {
		expect( groupServices( null ) ).toEqual( [] );
		expect( groupServices( [ { key: 'x', kind: 'weird' } ] ) ).toEqual(
			[]
		);
	} );
} );

describe( 'metaLine', () => {
	it( 'says kind, where data goes and the tool count', () => {
		expect( metaLine( unsplash ) ).toBe(
			'Stock photos · needs your key · 2 tools'
		);
		expect( metaLine( { ...unsplash, status: 'ready' } ) ).toBe(
			'Stock photos · sends data to api.unsplash.com · 2 tools'
		);
		expect( metaLine( yoast ) ).toBe(
			'SEO plugin · nothing leaves your site · 1 tool'
		);
	} );
	it( 'names the kind when there is no description', () => {
		expect( metaLine( { ...yoast, description: '' } ) ).toBe(
			'Plugin · nothing leaves your site · 1 tool'
		);
	} );
} );

describe( 'badgeFor', () => {
	it( 'maps each status', () => {
		expect( badgeFor( unsplash ) ).toMatchObject( {
			label: 'Add key',
			button: true,
		} );
		expect( badgeFor( { status: 'ready' } ) ).toMatchObject( {
			label: 'Ready',
			tone: 'success',
		} );
		expect( badgeFor( yoast ).label ).toBe( 'Detected' );
		expect( badgeFor( { status: 'off' } ).label ).toBe( 'Off' );
	} );
	it( 'says Active for PlugPress add-ons and On for approved third-party', () => {
		expect(
			badgeFor( { status: 'active', source: 'plugpress' } ).label
		).toBe( 'Active' );
		expect(
			badgeFor( { status: 'active', source: 'third-party' } ).label
		).toBe( 'On' );
	} );
} );

describe( 'roleLabel, initialOf, replaceRecord', () => {
	it( 'names the roles as AI apps does', () => {
		expect( roleLabel( 'read' ) ).toBe( 'Read only' );
		expect( roleLabel( 'write' ) ).toBe( 'Edit content' );
		expect( roleLabel( 'admin' ) ).toBe( 'Manage the site' );
		expect( roleLabel( 'other' ) ).toBe( 'other' );
	} );
	it( 'takes a tile letter', () => {
		expect( initialOf( 'unsplash' ) ).toBe( 'U' );
		expect( initialOf( '' ) ).toBe( '?' );
	} );
	it( 'replaces one record by key', () => {
		const next = { ...unsplash, status: 'ready' };
		expect( replaceRecord( [ yoast, unsplash ], next ) ).toEqual( [
			yoast,
			next,
		] );
	} );
} );

describe( 'toolCount', () => {
	it( 'uses the server count, so an add-on that is off still says how many', () => {
		expect( toolCount( { tools: [], tool_count: 3 } ) ).toBe( '3 tools' );
	} );
	it( 'falls back to the tool list', () => {
		expect( toolCount( { tools: [ { name: 'a' } ] } ) ).toBe( '1 tool' );
	} );
} );

describe( 'kindLabel', () => {
	it( 'keeps a short description and swaps a long one for the kind', () => {
		expect(
			kindLabel( { kind: 'account', description: 'Stock photos' } )
		).toBe( 'Stock photos' );
		const long =
			'This site’s own traffic analytics — visitors, sources, pages, AI referrals and AI crawlers. Read-only.';
		expect(
			kindLabel( {
				kind: 'addon',
				source: 'plugpress',
				description: long,
			} )
		).toBe( 'PlugPress add-on' );
		expect(
			kindLabel( {
				kind: 'addon',
				source: 'third-party',
				description: long,
			} )
		).toBe( 'Third-party add-on' );
		expect( kindLabel( { kind: 'plugin', description: '' } ) ).toBe(
			'Plugin'
		);
	} );
} );
