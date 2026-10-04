/**
 * Human action names for the Home activity preview (#281).
 */
import {
	actionLabel,
	canUndo,
	madeBy,
	rowText,
	rowTitle,
	undoOutcome,
	undoPlan,
	wasUndone,
} from '../../admin/src/activity-format';

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

describe( 'rowText and rowTitle (R6)', () => {
	it( 'shows a change by its summary and an attempt by the tool name', () => {
		expect(
			rowText(
				{ type: 'executed', summary: 'Moved post #7 to the trash.' },
				caps
			)
		).toBe( 'Moved post #7 to the trash.' );
		expect(
			rowText(
				{ type: 'denied', action: 'update-option', target: 'tier' },
				caps
			)
		).toBe( 'Blocked · Update option · needs Manage the site' );
	} );

	it( 'puts the whole line in the tooltip, then who did it', () => {
		const long =
			'Owner rejected: Move post #7 "Summer pop-up at the market" to the trash. It can be restored from Trash. (Claude Code)';
		expect( rowTitle( long, 'by you' ) ).toBe( `${ long }\nby you` );
		expect( rowTitle( 'Created post #3', '' ) ).toBe( 'Created post #3' );
		expect( rowTitle( '', '' ) ).toBeUndefined();
	} );
} );

describe( 'canUndo and wasUndone', () => {
	it( 'offers Undo only for a recorded change with its log id', () => {
		expect(
			canUndo( { id: 12, type: 'executed', undo: 'available' } )
		).toBe( true );
		// Entries from before the log carried a type are changes.
		expect( canUndo( { id: 12, undo: 'available' } ) ).toBe( true );
	} );

	it( 'leaves out attempts, undone and unrecorded changes, and rows without an id', () => {
		expect( canUndo( { id: 12, type: 'denied', undo: 'available' } ) ).toBe(
			false
		);
		expect(
			canUndo( { id: 12, type: 'rehearsed', undo: 'available' } )
		).toBe( false );
		expect( canUndo( { id: 12, type: 'executed', undo: 'undone' } ) ).toBe(
			false
		);
		expect(
			canUndo( { id: 12, type: 'executed', undo: 'not-recorded' } )
		).toBe( false );
		// An older server sends neither field: no Undo, rather than a broken one.
		expect( canUndo( { type: 'executed', summary: 'x' } ) ).toBe( false );
		expect(
			canUndo( { id: '12', type: 'executed', undo: 'available' } )
		).toBe( false );
		expect( canUndo( null ) ).toBe( false );
	} );

	it( 'knows an undone change', () => {
		expect( wasUndone( { undo: 'undone' } ) ).toBe( true );
		expect( wasUndone( { undo: 'available' } ) ).toBe( false );
		expect( wasUndone( undefined ) ).toBe( false );
	} );
} );

describe( 'undoPlan', () => {
	it( 'lists what comes back with the token when the entry is ready', () => {
		const res = {
			entries: [
				{
					id: 5,
					status: 'ready',
					steps: [ 'Put back the title “One”.' ],
				},
			],
			ready: 1,
			confirm_token: 'abc',
		};
		expect( undoPlan( res, 5 ) ).toEqual( {
			ready: true,
			steps: [ 'Put back the title “One”.' ],
			reasons: [],
			token: 'abc',
		} );
	} );

	it( 'gives undo’s own reasons when it is skipped', () => {
		const res = {
			entries: [
				{
					id: 5,
					status: 'skipped',
					steps: [],
					reasons: [ 'The post was changed since.' ],
				},
			],
			ready: 0,
		};
		expect( undoPlan( res, 5 ) ).toEqual( {
			ready: false,
			steps: [],
			reasons: [ 'The post was changed since.' ],
			token: '',
		} );
	} );

	it( 'is never ready without a token', () => {
		const res = { entries: [ { id: 5, status: 'ready', steps: [ 'x' ] } ] };
		expect( undoPlan( res, 5 ).ready ).toBe( false );
		expect( undoPlan( res, 5 ).reasons ).toHaveLength( 1 );
	} );

	it( 'says so when the entry is not in the answer', () => {
		const plan = undoPlan( { entries: [], unknown: [ 5 ] }, 5 );
		expect( plan.ready ).toBe( false );
		expect( plan.reasons[ 0 ] ).toMatch( /no longer in the activity log/ );
		expect( undoPlan( null, 5 ).ready ).toBe( false );
	} );
} );

describe( 'undoOutcome', () => {
	it( 'reports a done undo', () => {
		expect( undoOutcome( { undone: 1, entries: [] } ) ).toEqual( {
			done: true,
			message: 'Change undone.',
		} );
	} );

	it( 'gives the reason a confirmed undo stopped', () => {
		const res = {
			undone: 0,
			entries: [
				{ id: 5, status: 'failed', reasons: [ 'The post is locked.' ] },
			],
		};
		expect( undoOutcome( res ) ).toEqual( {
			done: false,
			message: 'The post is locked.',
		} );
		expect( undoOutcome( {} ).done ).toBe( false );
	} );
} );
