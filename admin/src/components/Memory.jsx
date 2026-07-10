/**
 * Memory — what Saddle remembers between AI sessions.
 *
 * The owner's governance surface for the memory store: see the exact block
 * served to every new session, review every entry with its provenance
 * (yours vs written by an AI), pin what should always be known, and clear
 * agent-written memory in one click. Agent-written entries are never served
 * automatically unless you pin them or turn the auto-include toggle on.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Spinner,
	Card,
	CardHeader,
	CardContent,
	Field,
	Input,
	Textarea,
	Switch,
	CodeBlock,
	RowList,
	Row,
	useConfirm,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';

export default function Memory( { onChanged } ) {
	const confirm = useConfirm();
	const [ entries, setEntries ] = useState( [] );
	const [ settings, setSettings ] = useState( null );
	const [ preview, setPreview ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ openKey, setOpenKey ] = useState( null );
	const [ showPreview, setShowPreview ] = useState( false );
	const [ draft, setDraft ] = useState( { key: '', text: '' } );
	const [ adding, setAdding ] = useState( false );

	const apply = ( res ) => {
		setEntries( res.entries || [] );
		setSettings( res.settings || null );
		setPreview( res.preview || '' );
		onChanged?.();
	};

	useEffect( () => {
		api( 'memory' )
			.then( apply )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const call = ( path, options ) =>
		api( path, options )
			.then( apply )
			.catch( ( e ) => toast.error( e.message ) );

	const addEntry = () => {
		if ( ! draft.text.trim() ) {
			return;
		}
		setAdding( true );
		call( 'memory', {
			method: 'POST',
			data: { key: draft.key, text: draft.text },
		} ).finally( () => {
			setAdding( false );
			setDraft( { key: '', text: '' } );
		} );
	};

	const forgetEntry = async ( entry ) => {
		const ok = await confirm( {
			title: sprintf(
				/* translators: %s: entry key. */
				__( 'Forget “%s”?', 'saddle' ),
				entry.key
			),
			description: __( 'This cannot be undone.', 'saddle' ),
			danger: true,
			confirmLabel: __( 'Forget', 'saddle' ),
			cancelLabel: __( 'Cancel', 'saddle' ),
		} );
		if ( ok ) {
			call( `memory/${ entry.key }`, { method: 'DELETE' } );
		}
	};

	const clearAgentMemory = async ( count ) => {
		const ok = await confirm( {
			title: __( 'Clear AI-written memory?', 'saddle' ),
			description: sprintf(
				/* translators: %d: entry count. */
				__(
					'Delete all %d AI-written memory entries? Your own entries are kept.',
					'saddle'
				),
				count
			),
			danger: true,
			confirmLabel: __( 'Clear it', 'saddle' ),
			cancelLabel: __( 'Cancel', 'saddle' ),
		} );
		if ( ok ) {
			call( 'memory-clear-agent', { method: 'POST' } );
		}
	};

	if ( loading ) {
		return <Spinner />;
	}

	const agentCount = entries.filter( ( e ) => e.source !== 'owner' ).length;

	return (
		<Card className="saddle-guide__block">
			<CardHeader
				title={ __( 'Memory', 'saddle' ) }
				description={ __(
					'Things worth knowing between sessions — saved by you here, or noted by your AI with its memory tools. Nothing an AI writes is served to future sessions unless you pin it (or turn on auto-include below); until then it’s only found when an AI searches its memory. Memory is background information — it can never change what your AI is allowed to do.',
					'saddle'
				) }
			/>
			<CardContent>
				{ preview !== '' && (
					<>
						<Button
							variant="link"
							onClick={ () => setShowPreview( ( v ) => ! v ) }
						>
							{ showPreview
								? __(
										'Hide what every session is told',
										'saddle'
								  )
								: __(
										'Show what every session is told',
										'saddle'
								  ) }
						</Button>
						{ showPreview && (
							<CodeBlock
								className="saddle-guide__system"
								code={ preview }
								copy={ false }
							/>
						) }
					</>
				) }

				{ entries.length > 0 && (
					<RowList className="saddle-rows">
						{ entries.map( ( entry ) => (
							<div key={ entry.key }>
								<Row
									title={ entry.key }
									description={
										( entry.source === 'owner'
											? __( 'You', 'saddle' )
											: sprintf(
													/* translators: %s: client name. */
													__( 'AI · %s', 'saddle' ),
													entry.client ||
														__(
															'unknown',
															'saddle'
														)
											  ) ) +
										' · ' +
										entry.type +
										( entry.pinned
											? ' · ' + __( 'pinned', 'saddle' )
											: '' )
									}
									actions={
										<>
											<Switch
												checked={ entry.pinned }
												onChange={ () =>
													call(
														`memory/${ entry.key }`,
														{
															method: 'POST',
															data: {
																pinned: ! entry.pinned,
															},
														}
													)
												}
												aria-label={ sprintf(
													/* translators: %s: entry key. */
													__(
														'Pin “%s” so every session is told it',
														'saddle'
													),
													entry.key
												) }
											/>
											<Button
												variant="link"
												onClick={ () =>
													setOpenKey(
														openKey === entry.key
															? null
															: entry.key
													)
												}
											>
												{ openKey === entry.key
													? __( 'Hide', 'saddle' )
													: __( 'View', 'saddle' ) }
											</Button>
											<Button
												variant="link"
												className="saddle-link-danger"
												onClick={ () =>
													forgetEntry( entry )
												}
											>
												{ __( 'Delete', 'saddle' ) }
											</Button>
										</>
									}
								/>
								{ openKey === entry.key && (
									<CodeBlock
										className="saddle-rows__body"
										code={ entry.text }
										copy={ false }
									/>
								) }
							</div>
						) ) }
					</RowList>
				) }

				{ /* Owner adds a durable note (served by default — it's yours). */ }
				<div className="saddle-guide__actions saddle-guide__actions--stack">
					<Field label={ __( 'Add something to remember', 'saddle' ) }>
						{ ( a11y ) => (
							<Textarea
								{ ...a11y }
								value={ draft.text }
								onChange={ ( e ) =>
									setDraft( ( d ) => ( {
										...d,
										text: e.target.value,
									} ) )
								}
								rows={ 2 }
								placeholder={ __(
									'e.g. The pricing page is “Plans” (page 42) — update it, never create a new one.',
									'saddle'
								) }
							/>
						) }
					</Field>
					<Field label={ __( 'Name (optional)', 'saddle' ) }>
						{ ( a11y ) => (
							<Input
								{ ...a11y }
								value={ draft.key }
								onChange={ ( e ) =>
									setDraft( ( d ) => ( {
										...d,
										key: e.target.value,
									} ) )
								}
								placeholder={ __(
									'e.g. pricing-page',
									'saddle'
								) }
							/>
						) }
					</Field>
					<Button
						variant="secondary"
						onClick={ addEntry }
						loading={ adding }
						disabled={ adding || ! draft.text.trim() }
					>
						{ __( 'Remember this', 'saddle' ) }
					</Button>
				</div>

				{ settings && (
					<div className="saddle-guide__actions saddle-guide__actions--stack">
						<label className="saddle-toggle-row">
							<Switch
								checked={ settings.autoinject_agent }
								onChange={ ( value ) =>
									call( 'memory-settings', {
										method: 'POST',
										data: { autoinject_agent: value },
									} )
								}
								aria-label={ __(
									'Auto-include AI-written memory',
									'saddle'
								) }
							/>
							<span>
								{ __(
									'Auto-include AI-written memory (off is safest — pin entries instead)',
									'saddle'
								) }
							</span>
						</label>
						{ agentCount > 0 && (
							<Button
								variant="secondary"
								className="saddle-link-danger"
								onClick={ () =>
									clearAgentMemory( agentCount )
								}
							>
								{ __( 'Clear AI-written memory', 'saddle' ) }
							</Button>
						) }
					</div>
				) }
			</CardContent>
		</Card>
	);
}
