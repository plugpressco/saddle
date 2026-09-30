/**
 * Which notice goes in the frame's one slot (#276).
 */
import { pickSlot, TONES } from '../../admin/src/notices';

const n = ( id, severity, extra = {} ) => ( {
	id,
	severity,
	message: `Message ${ id }`,
	action: null,
	dismiss: false,
	...extra,
} );

describe( 'pickSlot', () => {
	it( 'is empty when there is nothing to show', () => {
		expect( pickSlot( [] ) ).toEqual( { slot: null, rest: [] } );
		expect( pickSlot( undefined ) ).toEqual( { slot: null, rest: [] } );
	} );

	it( 'puts a lone notice in the slot', () => {
		const one = n( 'a', 'info' );
		expect( pickSlot( [ one ] ) ).toEqual( { slot: one, rest: [] } );
	} );

	it( 'chooses the most severe notice', () => {
		const { slot, rest } = pickSlot( [
			n( 'ok', 'success' ),
			n( 'info', 'info' ),
			n( 'warn', 'warning' ),
			n( 'err', 'error' ),
		] );
		expect( slot.id ).toBe( 'err' );
		expect( rest.map( ( x ) => x.id ) ).toEqual( [ 'ok', 'info', 'warn' ] );
	} );

	it( 'lets an error strip beat a server warning', () => {
		const client = [ n( 'client-error', 'error' ) ];
		const server = [ n( 'server-warning', 'warning' ) ];
		expect( pickSlot( [ ...client, ...server ] ).slot.id ).toBe(
			'client-error'
		);
	} );

	it( 'lets a server error beat the domain warning', () => {
		const { slot, rest } = pickSlot( [
			n( 'domain-drift', 'warning' ),
			n( 'server-error', 'error' ),
		] );
		expect( slot.id ).toBe( 'server-error' );
		expect( rest.map( ( x ) => x.id ) ).toEqual( [ 'domain-drift' ] );
	} );

	it( 'keeps list order among equal severities', () => {
		const { slot, rest } = pickSlot( [
			n( 'first', 'warning' ),
			n( 'second', 'warning' ),
			n( 'third', 'warning' ),
		] );
		expect( slot.id ).toBe( 'first' );
		expect( rest.map( ( x ) => x.id ) ).toEqual( [ 'second', 'third' ] );
	} );

	it( 'ranks an unknown severity last and drops unusable entries', () => {
		const { slot, rest } = pickSlot( [
			n( 'odd', 'loud' ),
			null,
			{ id: 'no-message', severity: 'error' },
			n( 'ok', 'success' ),
		] );
		expect( slot.id ).toBe( 'ok' );
		expect( rest.map( ( x ) => x.id ) ).toEqual( [ 'odd' ] );
	} );

	it( 'does not change the list it was given', () => {
		const list = [ n( 'a', 'info' ), n( 'b', 'error' ) ];
		pickSlot( list );
		expect( list.map( ( x ) => x.id ) ).toEqual( [ 'a', 'b' ] );
	} );
} );

describe( 'TONES', () => {
	it( 'maps every severity to a design-system tone', () => {
		expect( TONES ).toEqual( {
			error: 'danger',
			warning: 'warning',
			info: 'info',
			success: 'success',
		} );
	} );
} );
