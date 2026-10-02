/**
 * The one line under a connected app's name on AI apps (#309).
 */
import { lastFact, metaLine } from '../../admin/src/apps-logic';

const now = () => Math.floor( Date.now() / 1000 );

describe( 'lastFact', () => {
	it( 'says when the app was last used once it has been', () => {
		expect(
			lastFact( {
				created_at: 100,
				last_seen_at: 300,
				last_tool_at: 200,
			} )
		).toEqual( { what: 'used', at: 200 } );
	} );

	it( 'falls back to the last request when no tool was called', () => {
		expect(
			lastFact( { created_at: 100, last_seen_at: 300, last_tool_at: 0 } )
		).toEqual( { what: 'used', at: 300 } );
	} );

	it( 'says when it connected while it has not been used', () => {
		expect(
			lastFact( { created_at: 100, last_seen_at: 0, last_tool_at: 0 } )
		).toEqual( { what: 'connected', at: 100 } );
	} );

	it( 'keeps the more recent of the two facts', () => {
		expect(
			lastFact( { created_at: 500, last_seen_at: 300, last_tool_at: 0 } )
		).toEqual( { what: 'connected', at: 500 } );
	} );

	it( 'returns null when neither time is known', () => {
		expect( lastFact( {} ) ).toBeNull();
		expect(
			lastFact( { created_at: 0, last_seen_at: 0, last_tool_at: 0 } )
		).toBeNull();
	} );
} );

describe( 'metaLine', () => {
	it( 'is one short fact, never the role or how the app signs in', () => {
		const row = {
			kind: 'oauth',
			role: 'write',
			role_label: 'Edit content',
			level: 'write',
			user_login: 'fahim',
			hint: '4f2a',
			created_at: now() - 3600,
			last_seen_at: now() - 120,
			last_tool_at: now() - 120,
		};
		const line = metaLine( row );
		expect( line ).toMatch( /^Last used / );
		expect( line ).not.toContain( '·' );
		expect( line ).not.toContain( 'Edit content' );
		expect( line ).not.toContain( 'fahim' );
		expect( line ).not.toContain( '4f2a' );
	} );

	it( 'says Connected for an app that has not been used', () => {
		expect( metaLine( { created_at: now() - 60 } ) ).toMatch(
			/^Connected /
		);
	} );

	it( 'is empty when nothing is known, so the row has one line', () => {
		expect( metaLine( {} ) ).toBe( '' );
	} );
} );
