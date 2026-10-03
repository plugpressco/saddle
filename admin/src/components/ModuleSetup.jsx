/**
 * A module's unfinished setup tasks, drawn by Core above the first tab of the
 * module's page (family look, K8).
 *
 * The tasks come from the descriptor's `setup` callable, already cleaned by
 * Saddle_Modules::setup(). One block of rows: the task's title, its `line` as
 * the meta, its action as a button (a tab or a URL). Nothing at all once every
 * task is done, and no status chip in the header.
 *
 * An action that names a tab (and maybe a page, a view and args) carries them
 * as `route`. Its button is still a real link; a plain click opens it in the
 * app, without a reload.
 */
import { Button, Row, RowList } from '@plugpress/ui';
import { isPlainClick } from '../frame-logic';

/**
 * The tasks still to do.
 *
 * @param {?Array} tasks Tasks from saddleData.setup.
 * @return {Array} Unfinished tasks, in the module's order.
 */
export function pendingTasks( tasks ) {
	return ( Array.isArray( tasks ) ? tasks : [] ).filter(
		( task ) => task && ! task.done
	);
}

/**
 * @param {Object}    props
 * @param {?Array}    props.tasks    Tasks from saddleData.setup.
 * @param {Function=} props.navigate The app's navigate, for in-app actions.
 */
export default function ModuleSetup( { tasks, navigate } ) {
	const pending = pendingTasks( tasks );
	if ( ! pending.length ) {
		return null;
	}

	return (
		<RowList className="saddle-module-setup">
			{ pending.map( ( task ) => (
				<Row
					key={ task.id }
					title={ task.title }
					description={ task.line || undefined }
					actions={
						task.action ? (
							<Button
								variant="secondary"
								size="sm"
								href={ task.action.url }
								onClick={
									task.action.route && navigate
										? ( event ) => {
												if ( isPlainClick( event ) ) {
													event.preventDefault();
													navigate( {
														...task.action.route,
													} );
												}
										  }
										: undefined
								}
								target={
									task.action.external ? '_blank' : undefined
								}
								rel={
									task.action.external
										? 'noopener noreferrer'
										: undefined
								}
							>
								{ task.action.label }
							</Button>
						) : undefined
					}
				/>
			) ) }
		</RowList>
	);
}
