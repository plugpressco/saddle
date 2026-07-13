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
	Collapsible,
	Drawer,
	Field,
	Input,
	Textarea,
	Switch,
	CodeBlock,
	RowList,
	Row,
	useConfirm,
	toast,
	PageHeader,
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
	const [ howOpen, setHowOpen ] = useState( false );
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
		<div className="saddle-memory">
			<PageHeader
				title={ __( 'Memory', 'saddle' ) }
				description={ __(
					'Things worth knowing between sessions — saved by you here, or noted by your AI as it works.',
					'saddle'
				) }
				actions={
					<Button
						variant="secondary"
						size="sm"
						onClick={ () => setHowOpen( true ) }
					>
						{ __( 'How memory works', 'saddle' ) }
					</Button>
				}
			/>

			<Card>
				<CardHeader
					title={ __( 'Memories', 'saddle' ) }
					description={ __(
						'Every entry with its provenance — yours, or written by an AI. Pin an entry so every session is told it.',
						'saddle'
					) }
				/>
				<CardContent>
					{ entries.length === 0 && (
						<p className="saddle-memory__empty">
							{ __(
								'Nothing remembered yet — add the first note below, or let your AI save things as it works.',
								'saddle'
							) }
						</p>
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
														__(
															'AI · %s',
															'saddle'
														),
														entry.client ||
															__(
																'unknown',
																'saddle'
															)
												  ) ) +
											' · ' +
											entry.type +
											( entry.pinned
												? ' · ' +
												  __( 'pinned', 'saddle' )
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
															openKey ===
																entry.key
																? null
																: entry.key
														)
													}
												>
													{ openKey === entry.key
														? __( 'Hide', 'saddle' )
														: __(
																'View',
																'saddle'
														  ) }
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
				</CardContent>
			</Card>

			<Card>
				<CardHeader
					title={ __( 'Add something to remember', 'saddle' ) }
					description={ __(
						'Your own notes are told to every session automatically.',
						'saddle'
					) }
				/>
				<CardContent>
					<div className="saddle-memory__compose">
						<Field label={ __( 'What to remember', 'saddle' ) }>
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
									rows={ 3 }
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
				</CardContent>
			</Card>

			{ settings && (
				<Card>
					<CardHeader
						title={ __( 'AI-written memory', 'saddle' ) }
						description={ __(
							'Entries an AI saved on its own are only found when it searches — unless you pin them, or turn this on.',
							'saddle'
						) }
					/>
					<CardContent>
						<div className="saddle-guide__actions saddle-guide__actions--stack">
							<label
								className="saddle-toggle-row"
								htmlFor="saddle-memory-autoinject"
							>
								<Switch
									id="saddle-memory-autoinject"
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
									{ __(
										'Clear AI-written memory',
										'saddle'
									) }
								</Button>
							) }
						</div>
					</CardContent>
				</Card>
			) }

			{ preview !== '' && (
				<Card>
					<CardHeader
						title={ __( 'What every session is told', 'saddle' ) }
						description={ __(
							'The exact memory block a new AI session starts with.',
							'saddle'
						) }
					/>
					<CardContent>
						<Collapsible
							trigger={ __( 'Show the exact text', 'saddle' ) }
						>
							<CodeBlock
								className="saddle-guide__system"
								code={ preview }
								copy={ false }
							/>
						</Collapsible>
					</CardContent>
				</Card>
			) }

			<Drawer
				open={ howOpen }
				onOpenChange={ setHowOpen }
				title={ __( 'How memory works', 'saddle' ) }
				size="md"
			>
				<div className="saddle-doc saddle-doc--bare">
					<p className="saddle-doc__p">
						{ __(
							'Memory is background information your AI carries between sessions — saved by you on this page, or noted by an AI with its memory tools while it works. It saves you re-explaining your site every time.',
							'saddle'
						) }
					</p>
					<h3 className="saddle-doc__h">
						{ __( 'What every session is told', 'saddle' ) }
					</h3>
					<ul className="saddle-doc__list">
						<li>
							{ __(
								'Your own entries — always included.',
								'saddle'
							) }
						</li>
						<li>
							{ __(
								'Pinned entries — always included, whoever wrote them.',
								'saddle'
							) }
						</li>
						<li>
							{ __(
								'Everything else — only found when an AI searches its memory.',
								'saddle'
							) }
						</li>
					</ul>
					<h3 className="saddle-doc__h">
						{ __( 'AI-written entries', 'saddle' ) }
					</h3>
					<p className="saddle-doc__p">
						{ __(
							'Entries an AI saved on its own are never served to future sessions automatically — you stay in control of what becomes standing knowledge. Pin the ones worth keeping, or turn on auto-include if you trust the whole set (off is safest). “Clear AI-written memory” removes them all at once; your own entries are kept.',
							'saddle'
						) }
					</p>
					<h3 className="saddle-doc__h">
						{ __( 'Safety', 'saddle' ) }
					</h3>
					<p className="saddle-doc__p">
						{ __(
							'Memory is background information only. It can never change what your AI is allowed to do — the access level and per-tool permissions always win.',
							'saddle'
						) }
					</p>
				</div>
			</Drawer>
		</div>
	);
}
