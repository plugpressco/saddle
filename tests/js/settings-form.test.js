/**
 * Which settings show, what changed, what is wrong with a draft (#276).
 */
import {
	agentWritableLabels,
	changedValues,
	draftProblems,
	fieldForHash,
	fieldId,
	groupBySection,
	isDirty,
	needsDisclosure,
	renderableFields,
	splitFields,
	startDraft,
} from '../../admin/src/settings-form';

const f = ( key, extra = {} ) => ( {
	key,
	type: 'boolean',
	default: false,
	label: key,
	help: '',
	level: 'basic',
	agent: 'read',
	control: 'auto',
	screen: 'demo/settings',
	value: false,
	...extra,
} );

describe( 'renderableFields', () => {
	const fields = [
		f( 'a' ),
		f( 'b', { control: 'custom' } ),
		f( 'c', { screen: 'settings/general' } ),
	];

	it( 'skips fields a bespoke component owns', () => {
		expect( renderableFields( fields ).map( ( x ) => x.key ) ).toEqual( [
			'a',
			'c',
		] );
	} );

	it( 'limits to one screen when asked', () => {
		expect(
			renderableFields( fields, 'settings/general' ).map( ( x ) => x.key )
		).toEqual( [ 'c' ] );
	} );

	it( 'copes with nothing', () => {
		expect( renderableFields( undefined ) ).toEqual( [] );
	} );
} );

describe( 'groupBySection', () => {
	it( 'groups in order of first appearance', () => {
		const groups = groupBySection( [
			f( 'a', { section: 'Memory' } ),
			f( 'b', { section: 'Security' } ),
			f( 'c', { section: 'Memory' } ),
		] );
		expect( groups.map( ( g ) => g.section ) ).toEqual( [
			'Memory',
			'Security',
		] );
		expect( groups[ 0 ].fields.map( ( x ) => x.key ) ).toEqual( [
			'a',
			'c',
		] );
	} );

	it( 'puts fields with no section first, in one unnamed group', () => {
		const groups = groupBySection( [
			f( 'a', { section: 'Memory' } ),
			f( 'b' ),
			f( 'c', { section: '' } ),
		] );
		expect( groups.map( ( g ) => g.section ) ).toEqual( [ '', 'Memory' ] );
		expect( groups[ 0 ].fields.map( ( x ) => x.key ) ).toEqual( [
			'b',
			'c',
		] );
	} );

	it( 'splits each group on its own', () => {
		const groups = groupBySection( [
			f( 'a', { section: 'One' } ),
			f( 'b', { section: 'One', level: 'advanced' } ),
			f( 'c', { section: 'Two', level: 'advanced' } ),
		] );
		const [ one, two ] = groups.map( ( g ) => splitFields( g.fields ) );
		expect( one.disclosure ).toBe( true );
		expect( two.disclosure ).toBe( false );
		expect( two.basic.map( ( x ) => x.key ) ).toEqual( [ 'c' ] );
	} );

	it( 'copes with nothing', () => {
		expect( groupBySection( undefined ) ).toEqual( [] );
	} );
} );

describe( 'splitFields', () => {
	it( 'puts advanced fields behind the disclosure when basic ones exist', () => {
		const s = splitFields( [ f( 'a' ), f( 'b', { level: 'advanced' } ) ] );
		expect( s.basic.map( ( x ) => x.key ) ).toEqual( [ 'a' ] );
		expect( s.advanced.map( ( x ) => x.key ) ).toEqual( [ 'b' ] );
		expect( s.disclosure ).toBe( true );
	} );

	it( 'shows an all-advanced set directly, with no disclosure', () => {
		const s = splitFields( [
			f( 'a', { level: 'advanced' } ),
			f( 'b', { level: 'advanced' } ),
		] );
		expect( s.basic ).toHaveLength( 2 );
		expect( s.advanced ).toEqual( [] );
		expect( s.disclosure ).toBe( false );
	} );

	it( 'has no disclosure when everything is basic', () => {
		expect( splitFields( [ f( 'a' ) ] ).disclosure ).toBe( false );
	} );
} );

describe( 'changedValues', () => {
	const fields = [
		f( 'flag', { value: true } ),
		f( 'count', { type: 'integer', value: 50, minimum: 10, maximum: 100 } ),
		f( 'name', { type: 'string', value: 'x' } ),
		f( 'token', {
			type: 'secret',
			value: { configured: true, hint: '····wxyz' },
		} ),
	];

	it( 'is empty for an untouched draft', () => {
		const draft = startDraft( fields );
		expect( changedValues( fields, draft ) ).toEqual( {} );
		expect( isDirty( fields, draft ) ).toBe( false );
	} );

	it( 'starts a secret empty and a number as text', () => {
		const draft = startDraft( fields );
		expect( draft.token ).toBe( '' );
		expect( draft.count ).toBe( '50' );
	} );

	it( 'returns only what changed, typed for the server', () => {
		const draft = {
			...startDraft( fields ),
			flag: false,
			count: '60',
		};
		expect( changedValues( fields, draft ) ).toEqual( {
			flag: false,
			count: 60,
		} );
		expect( isDirty( fields, draft ) ).toBe( true );
	} );

	it( 'sends a secret only once something is typed', () => {
		const draft = { ...startDraft( fields ), token: 'abc123' };
		expect( changedValues( fields, draft ) ).toEqual( { token: 'abc123' } );
	} );

	it( 'treats typing the saved number back as no change', () => {
		const draft = { ...startDraft( fields ), count: '50' };
		expect( isDirty( fields, draft ) ).toBe( false );
	} );
} );

describe( 'draftProblems', () => {
	const fields = [
		f( 'count', { type: 'integer', value: 50, minimum: 10, maximum: 100 } ),
	];

	it.each( [
		[ '', 'number' ],
		[ 'abc', 'number' ],
		[ '12.5', 'number' ],
		[ '5', 'min' ],
		[ '500', 'max' ],
	] )( 'flags %p as %s', ( raw, code ) => {
		expect( draftProblems( fields, { count: raw } ) ).toEqual( {
			count: code,
		} );
	} );

	it( 'accepts a number in range', () => {
		expect( draftProblems( fields, { count: '75' } ) ).toEqual( {} );
	} );
} );

describe( 'the deep link', () => {
	const fields = [ f( 'a' ), f( 'b', { level: 'advanced' } ) ];
	const split = splitFields( fields );

	it( 'builds the control id', () => {
		expect( fieldId( 'demo', 'a' ) ).toBe( 'saddle-field-demo-a' );
	} );

	it( 'finds the field a hash points at, for a scope with dashes too', () => {
		expect(
			fieldForHash( '#saddle-field-rank-math-b', 'rank-math', fields )
		).toBe( fields[ 1 ] );
		expect(
			fieldForHash( '#saddle-field-other-b', 'demo', fields )
		).toBeNull();
		expect( fieldForHash( '', 'demo', fields ) ).toBeNull();
	} );

	it( 'opens the disclosure only for an advanced field', () => {
		expect( needsDisclosure( split, fields[ 1 ] ) ).toBe( true );
		expect( needsDisclosure( split, fields[ 0 ] ) ).toBe( false );
	} );
} );

describe( 'agentWritableLabels', () => {
	it( 'names the fields an agent may ask to change', () => {
		expect(
			agentWritableLabels( [
				f( 'a', { label: 'Count admin visits', agent: 'write' } ),
				f( 'b' ),
			] )
		).toEqual( [ 'Count admin visits' ] );
	} );
} );
