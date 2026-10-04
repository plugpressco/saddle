/**
 * Memory — what Saddle remembers between AI sessions.
 *
 * The owner's governance surface for the memory store: one row per entry
 * (yours, or saved by an app), View opens the full text with Pin and Delete,
 * and the last row is the auto-include switch with a one-click clear of
 * agent-written memory. Agent-written entries are never served
 * automatically unless you pin them or turn the auto-include toggle on.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Spinner,
	Badge,
	Card,
	CardContent,
	Field,
	Input,
	Textarea,
	Switch,
	CodeBlock,
	HelpTip,
	RowList,
	Row,
	useConfirm,
	toast,
} from '@plugpress/ui';
import SectionHeader from './SectionHeader';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api';

export default function Memory( { onChanged } ) {
	const confirm = useConfirm();
	const [ entries, setEntries ] = useState( [] );
	const [ settings, setSettings ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ openKey, setOpenKey ] = useState( null );
	const [ draft, setDraft ] = useState( { key: '', text: '' } );
	const [ adding, setAdding ] = useState( false );
	// The add form opens from the header's button and closes on Cancel.
	const [ composing, setComposing ] = useState( false );

	const apply = ( res ) => {
		setEntries( res.entries || [] );
		setSettings( res.settings || null );
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
			setComposing( false );
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
				/* translators: %d: how many AI-written memory entries will be deleted. */
				_n(
					'This deletes %d AI-written entry. Your own entries are kept.',
					'This deletes %d AI-written entries. Your own entries are kept.',
					count,
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
		<section className="saddle-section saddle-memory">
			<SectionHeader
				id="memory"
				title={ __( 'Memory', 'saddle' ) }
				help={ __(
					'Notes kept between sessions, saved by you or by your AI. Pin an entry to tell every session.',
					'saddle'
				) }
				actions={
					<Button
						variant="secondary"
						size="sm"
						onClick={ () => setComposing( true ) }
						disabled={ composing }
					>
						{ __( 'Add memory', 'saddle' ) }
					</Button>
				}
			/>

			{ composing && (
				<Card>
					<CardContent>
						<div className="saddle-memory__compose">
							<Field
								label={ __(
									'Add something to remember',
									'saddle'
								) }
							>
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
											'e.g. The pricing page is “Plans” (page 42). Update it, never create a new one.',
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
							<div className="saddle-memory__compose-actions">
								<Button
									variant="secondary"
									onClick={ addEntry }
									loading={ adding }
									disabled={ adding || ! draft.text.trim() }
								>
									{ __( 'Remember this', 'saddle' ) }
								</Button>
								<Button
									variant="ghost"
									onClick={ () => {
										setComposing( false );
										setDraft( { key: '', text: '' } );
									} }
									disabled={ adding }
								>
									{ __( 'Cancel', 'saddle' ) }
								</Button>
							</div>
						</div>
					</CardContent>
				</Card>
			) }

			<RowList>
				{ entries.length === 0 && (
					<Row
						title={
							<span className="saddle-apps__empty">
								{ __( 'Nothing remembered yet.', 'saddle' ) }
							</span>
						}
					/>
				) }
				{ entries.map( ( entry ) => (
					<div key={ entry.key }>
						<Row
							title={ entry.key }
							description={
								<span
									className="saddle-memory__text"
									title={ entry.text }
								>
									{ 'owner' !== entry.source &&
										sprintf(
											/* translators: %s: app name. */
											__( 'Saved by %s · ', 'saddle' ),
											entry.client ||
												__( 'an app', 'saddle' )
										) }
									{ entry.text }
								</span>
							}
							actions={
								<>
									{ entry.pinned && (
										<Badge>
											{ __( 'Pinned', 'saddle' ) }
										</Badge>
									) }
									<Button
										variant="link"
										size="sm"
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
								</>
							}
						/>
						{ openKey === entry.key && (
							<div className="saddle-memory__open">
								<CodeBlock code={ entry.text } copy={ false } />
								<div className="saddle-memory__open-actions">
									<span className="saddle-memory__pin">
										<Switch
											checked={ entry.pinned }
											onChange={ () =>
												call( `memory/${ entry.key }`, {
													method: 'POST',
													data: {
														pinned: ! entry.pinned,
													},
												} )
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
										{ __( 'Pinned', 'saddle' ) }
									</span>
									<Button
										variant="link"
										size="sm"
										className="saddle-link-danger"
										onClick={ () => forgetEntry( entry ) }
									>
										{ __( 'Delete', 'saddle' ) }
									</Button>
								</div>
							</div>
						) }
					</div>
				) ) }
				{ settings && (
					<Row
						className="saddle-memory__setting"
						title={
							<label
								className="saddle-memory__setting-label"
								htmlFor="saddle-memory-autoinject"
							>
								{ __(
									'Auto-include AI-written memory',
									'saddle'
								) }
								<HelpTip>
									{ __(
										'Your own and pinned entries go to every new session. An entry an AI saved is found only when it searches, unless you pin it or turn this on. Off is safest, so pin the entries worth keeping. Memory never changes what an app may do.',
										'saddle'
									) }
								</HelpTip>
							</label>
						}
						actions={
							<>
								{ agentCount > 0 && (
									<Button
										variant="link"
										size="sm"
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
							</>
						}
					/>
				) }
			</RowList>
		</section>
	);
}
