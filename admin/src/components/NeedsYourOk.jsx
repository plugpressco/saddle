/**
 * Needs your OK (#287): changes an AI app has previewed that the owner can
 * approve or reject here, instead of (or as well as) in the chat.
 *
 * Renders nothing when nothing is waiting. The approval itself is recorded by
 * the server; the app's own confirm then goes through or is refused.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Drawer,
	KeyValueList,
	Notice,
	Row,
	RowList,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';
import SectionHeader from './SectionHeader';
import {
	askedAgo,
	sortApprovals,
	requestTitle,
	previewRows,
} from '../approvals-logic';

/**
 * @param {Object}   props
 * @param {Function} props.onChange Called after an approve or reject, so the
 *                                  page can refresh its counts and activity.
 */
export default function NeedsYourOk( { onChange } ) {
	const [ items, setItems ] = useState( [] );
	const [ busy, setBusy ] = useState( 0 );
	const [ review, setReview ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = useCallback( () => {
		api( 'approvals' )
			.then( ( res ) => setItems( sortApprovals( res.approvals ) ) )
			.catch( () => setItems( [] ) );
	}, [] );

	useEffect( load, [ load ] );

	const decide = ( item, decision ) => {
		setBusy( item.id );
		setError( null );
		api( `approvals/${ item.id }`, {
			method: 'POST',
			data: { decision },
		} )
			.then( () => {
				setReview( null );
				setItems( ( prev ) =>
					prev.filter( ( i ) => i.id !== item.id )
				);
				if ( onChange ) {
					onChange();
				}
			} )
			.catch( ( e ) => {
				setError( e.message );
				// Expired or already decided: drop it from the list.
				load();
			} )
			.finally( () => setBusy( 0 ) );
	};

	if ( ! items.length && ! error ) {
		return null;
	}

	const rows = review ? previewRows( review.preview ) : [];

	const buttons = ( item ) => (
		<>
			<Button
				variant="secondary"
				size="sm"
				disabled={ busy === item.id }
				onClick={ () => decide( item, 'reject' ) }
			>
				{ __( 'Reject', 'saddle' ) }
			</Button>
			<Button
				size="sm"
				disabled={ busy === item.id }
				onClick={ () => decide( item, 'approve' ) }
			>
				{ __( 'Approve', 'saddle' ) }
			</Button>
		</>
	);

	return (
		<section className="saddle-needs-ok">
			<SectionHeader title={ __( 'Needs your OK', 'saddle' ) } />
			{ error && (
				<Notice status="error" onDismiss={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<RowList>
				{ items.map( ( item ) => (
					<Row
						key={ item.id }
						title={ requestTitle( item ) }
						description={ sprintf(
							/* translators: %s: how long ago, e.g. "3 min ago". */
							__( 'Asked %s · waits until you decide', 'saddle' ),
							askedAgo( item.created_at )
						) }
						actions={
							<>
								<Button
									variant="link"
									onClick={ () => setReview( item ) }
								>
									{ __( 'Review', 'saddle' ) }
								</Button>
								{ buttons( item ) }
							</>
						}
					/>
				) ) }
			</RowList>

			<Drawer
				open={ !! review }
				onOpenChange={ ( open ) => ! open && setReview( null ) }
				title={ review ? requestTitle( review ) : '' }
				size="md"
			>
				{ review && (
					<div className="saddle-doc saddle-doc--bare">
						{ /* The title already says what the app asks; the
						     details are the preview as plain rows, with
						     "was → becomes" for a change. */ }
						{ rows.length > 0 && (
							<KeyValueList
								className="saddle-needs-ok__details"
								items={ rows.map( ( r ) => ( {
									label: r.label,
									value:
										'before' in r
											? sprintf(
													/* translators: 1: the value now, 2: the value after the change. */
													__(
														'%1$s → %2$s',
														'saddle'
													),
													r.before ||
														__(
															'(empty)',
															'saddle'
														),
													r.value ||
														__(
															'(empty)',
															'saddle'
														)
											  )
											: r.value,
								} ) ) }
							/>
						) }
						<div className="saddle-needs-ok__actions">
							{ buttons( review ) }
						</div>
					</div>
				) }
			</Drawer>
		</section>
	);
}
