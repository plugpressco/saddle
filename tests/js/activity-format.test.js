/**
 * Human action names for the Home activity preview (#281).
 */
import { actionLabel, madeBy } from '../../admin/src/activity-format';

const caps = [
	{ short: 'update-option', label: 'Update option', tier: 'admin' },
	{ short: 'create-post', label: 'Create post', tier: 'write' },
];

describe( 'actionLabel', () => {
	it( 'uses the tool label for an executed call', () => {
		expect( actionLabel( { action: 'create-post' }, caps ) ).toBe(
			'Create post'
		);
		expect( actionLabel( { action: 'saddle/create-post' }, caps ) ).toBe(
			'Create post'
		);
	} );

	it( 'strips denied- and says what a blocked call needed', () => {
		expect(
			actionLabel(
				{
					action: 'denied-update-option',
					type: 'denied',
					target: 'tier',
				},
				caps
			)
		).toBe( 'Blocked · Update option · needs Manage the site' );
	} );

	it( 'blocks without a level when the reason was something else', () => {
		expect(
			actionLabel(
				{
					action: 'denied-create-post',
					type: 'denied',
					target: 'paused',
				},
				caps
			)
		).toBe( 'Blocked · Create post' );
	} );

	it( 'marks a rehearsal', () => {
		expect(
			actionLabel( { action: 'create-post', type: 'rehearsed' }, caps )
		).toBe( 'Rehearsed · Create post' );
	} );

	it( 'falls back to a plain version of the tool name', () => {
		expect( actionLabel( { action: 'list-menus' }, [] ) ).toBe(
			'List menus'
		);
		expect( actionLabel( { action: 'list-menus' }, undefined ) ).toBe(
			'List menus'
		);
	} );

	it( 'uses the summary for an event that is not a tool', () => {
		expect(
			actionLabel(
				{
					action: 'oauth-authorized',
					summary: 'Connected Claude (Read only)',
				},
				caps
			)
		).toBe( 'Connected Claude (Read only)' );
	} );

	it( 'falls back to the summary when there is no action', () => {
		expect(
			actionLabel( { action: '', summary: 'Something' }, caps )
		).toBe( 'Something' );
		expect( actionLabel( {}, caps ) ).toBe( '' );
	} );
} );

// QA 1.5.0 P2: the owner's own lines read "via admin", as if an app were
// named after the WordPress login.
describe( 'madeBy', () => {
	it( 'names the app that made a change', () => {
		expect( madeBy( { app: 'Claude Code', user: 'admin' }, 'admin' ) ).toBe(
			'via Claude Code'
		);
	} );

	it( 'says "by you" for the person looking', () => {
		expect( madeBy( { app: '', user: 'admin' }, 'admin' ) ).toBe(
			'by you'
		);
	} );

	it( 'names another WordPress user by login, never as "via"', () => {
		const line = madeBy( { app: '', user: 'jane' }, 'admin' );
		expect( line ).toBe( 'by jane' );
		expect( line ).not.toMatch( /^via/ );
	} );

	it( 'uses the display name when the log has one', () => {
		expect(
			madeBy( { app: '', user: 'jane', user_name: 'Jane Doe' }, 'admin' )
		).toBe( 'by Jane Doe' );
	} );

	it( 'is empty when no one is named', () => {
		expect( madeBy( { app: '', user: '' }, 'admin' ) ).toBe( '' );
		expect( madeBy( null, 'admin' ) ).toBe( '' );
	} );
} );
