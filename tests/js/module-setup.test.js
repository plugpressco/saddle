/**
 * A module's setup tasks: only the unfinished ones draw, and nothing once
 * every task is done (K8).
 */
import { pendingTasks } from '../../admin/src/components/ModuleSetup';

describe( 'pendingTasks', () => {
	it( 'keeps the unfinished tasks in order', () => {
		const tasks = [
			{ id: 'a', title: 'A', done: true },
			{ id: 'b', title: 'B', done: false },
			{ id: 'c', title: 'C', done: false },
		];
		expect( pendingTasks( tasks ).map( ( t ) => t.id ) ).toEqual( [
			'b',
			'c',
		] );
	} );

	it( 'is empty when everything is done, or there are no tasks', () => {
		expect( pendingTasks( [ { id: 'a', done: true } ] ) ).toEqual( [] );
		expect( pendingTasks( null ) ).toEqual( [] );
		expect( pendingTasks( undefined ) ).toEqual( [] );
	} );
} );
