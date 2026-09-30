/**
 * Human action names for the Home activity preview (#281).
 */
import { actionLabel } from '../../admin/src/activity-format';

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
					summary: 'Connected an app with OAuth — Claude',
				},
				caps
			)
		).toBe( 'Connected an app with OAuth — Claude' );
	} );

	it( 'falls back to the summary when there is no action', () => {
		expect(
			actionLabel( { action: '', summary: 'Something' }, caps )
		).toBe( 'Something' );
		expect( actionLabel( {}, caps ) ).toBe( '—' );
	} );
} );
