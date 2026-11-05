/**
 * The one line under a connected agent's name on Agent setup (#309), and which
 * agents count as connected.
 */
import {
	hasBeenUsed,
	lastFact,
	metaLine,
	opensSiteSteps,
	usedAppKeys,
} from '../../admin/src/apps-logic';

const now = () => Math.floor( Date.now() / 1000 );

describe( 'lastFact', () => {
	it( 'says when the agent was last used once it has been', () => {
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

	it( 'says a key was never used rather than that it connected', () => {
		expect(
			lastFact( { created_at: 100, last_seen_at: 0, last_tool_at: 0 } )
		).toEqual( { what: 'unused', at: 100 } );
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
	it( 'is one short fact, never the role or how the agent signs in', () => {
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

	// QA 1.5.0 S3: a key made and never pasted read "Connected 11 minutes ago".
	it( 'says a key that was never used is not used yet', () => {
		const line = metaLine( {
			kind: 'key',
			created_at: now() - 660,
			last_seen_at: 0,
			last_tool_at: 0,
		} );
		expect( line ).toMatch( /^Key made .+, not used yet$/ );
		expect( line ).not.toMatch( /Connected/ );
	} );

	it( 'says an agent that signed in but never called is not used yet', () => {
		expect( metaLine( { kind: 'oauth', created_at: now() - 60 } ) ).toMatch(
			/^Signed in .+, not used yet$/
		);
	} );

	it( 'says Connected when the connection is newer than its last use', () => {
		expect(
			metaLine( {
				kind: 'key',
				created_at: now() - 60,
				last_seen_at: now() - 600,
			} )
		).toMatch( /^Connected / );
	} );

	it( 'is empty when nothing is known, so the row has one line', () => {
		expect( metaLine( {} ) ).toBe( '' );
	} );
} );

describe( 'usedAppKeys', () => {
	it( 'counts only agents that reached the site', () => {
		const keys = usedAppKeys( [
			{ app: 'claude', created_at: 100 },
			{ app: 'cursor', created_at: 100, last_seen_at: 200 },
			{ app: 'codex', created_at: 100, last_tool_at: 150 },
			{ app: '', created_at: 100, last_seen_at: 200 },
		] );
		expect( [ ...keys ].sort() ).toEqual( [ 'codex', 'cursor' ] );
	} );

	it( 'copes with a missing list', () => {
		expect( usedAppKeys( null ).size ).toBe( 0 );
	} );

	it( 'agrees with hasBeenUsed', () => {
		expect( hasBeenUsed( { last_seen_at: 1 } ) ).toBe( true );
		expect( hasBeenUsed( { created_at: 1 } ) ).toBe( false );
		expect( hasBeenUsed( null ) ).toBe( false );
	} );
} );

describe( 'opensSiteSteps', () => {
	it( 'waits behind Connect this site when nothing asked for an agent', () => {
		expect( opensSiteSteps( null ) ).toBe( false );
		// `&add=1`: Overview's "Connect an app" shows both ways first.
		expect( opensSiteSteps( { app: null, key: false, n: 0 } ) ).toBe(
			false
		);
	} );

	it( 'opens for one agent, a key, or a key to replace', () => {
		expect( opensSiteSteps( { app: 'claude', key: false, n: 0 } ) ).toBe(
			true
		);
		expect( opensSiteSteps( { app: null, key: true, n: 0 } ) ).toBe( true );
		expect( opensSiteSteps( { app: 'cursor', rotate: 'abc', n: 1 } ) ).toBe(
			true
		);
	} );
} );
