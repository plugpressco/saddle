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
	TextareaControl,
	TextControl,
	ToggleControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';

export default function Memory( { onChanged } ) {
	const [ entries, setEntries ] = useState( [] );
	const [ settings, setSettings ] = useState( null );
	const [ preview, setPreview ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
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
			.catch( ( e ) => setNotice( { type: 'error', message: e.message } ) )
			.finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const call = ( path, options ) =>
		api( path, options )
			.then( apply )
			.catch( ( e ) => setNotice( { type: 'error', message: e.message } ) );

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

	if ( loading ) {
		return <Spinner />;
	}

	const agentCount = entries.filter( ( e ) => e.source !== 'owner' ).length;

	return (
		<section className="saddle-guide__block">
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="saddle-guide__head">
				<h3>{ __( 'Memory', 'saddle' ) }</h3>
			</div>
			<p className="saddle-guide__hint">
				{ __(
					'Things worth knowing between sessions — saved by you here, or noted by your AI with its memory tools. Nothing an AI writes is served to future sessions unless you pin it (or turn on auto-include below); until then it’s only found when an AI searches its memory. Memory is background information — it can never change what your AI is allowed to do.',
					'saddle'
				) }
			</p>

			{ preview !== '' && (
				<>
					<Button
						variant="link"
						onClick={ () => setShowPreview( ( v ) => ! v ) }
					>
						{ showPreview
							? __( 'Hide what every session is told', 'saddle' )
							: __( 'Show what every session is told', 'saddle' ) }
					</Button>
					{ showPreview && (
						<pre className="saddle-code saddle-guide__system">
							{ preview }
						</pre>
					) }
				</>
			) }

			{ entries.length > 0 && (
				<ul className="saddle-skills">
					{ entries.map( ( entry ) => (
						<li key={ entry.key } className="saddle-skills__row">
							<div className="saddle-skills__main">
								<strong>{ entry.key }</strong>
								<span className="saddle-skills__desc">
									{ entry.source === 'owner'
										? __( 'You', 'saddle' )
										: sprintf(
												/* translators: %s: client name. */
												__( 'AI · %s', 'saddle' ),
												entry.client ||
													__( 'unknown', 'saddle' )
										  ) }
									{ ' · ' }
									{ entry.type }
									{ entry.pinned &&
										' · ' + __( 'pinned', 'saddle' ) }
								</span>
							</div>
							<div className="saddle-skills__controls">
								<ToggleControl
									__nextHasNoMarginBottom
									checked={ entry.pinned }
									onChange={ () =>
										call( `memory/${ entry.key }`, {
											method: 'POST',
											data: { pinned: ! entry.pinned },
										} )
									}
									label={ __( 'Pin', 'saddle' ) }
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
									isDestructive
									onClick={ () => {
										if (
											// eslint-disable-next-line no-alert
											window.confirm(
												sprintf(
													/* translators: %s: entry key. */
													__(
														'Forget “%s”? This cannot be undone.',
														'saddle'
													),
													entry.key
												)
											)
										) {
											call( `memory/${ entry.key }`, {
												method: 'DELETE',
											} );
										}
									} }
								>
									{ __( 'Delete', 'saddle' ) }
								</Button>
							</div>
							{ openKey === entry.key && (
								<pre className="saddle-code saddle-skills__body">
									{ entry.text }
								</pre>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ /* Owner adds a durable note (served by default — it's yours). */ }
			<div className="saddle-guide__actions saddle-guide__actions--stack">
				<TextareaControl
					label={ __( 'Add something to remember', 'saddle' ) }
					value={ draft.text }
					onChange={ ( text ) =>
						setDraft( ( d ) => ( { ...d, text } ) )
					}
					rows={ 2 }
					placeholder={ __(
						'e.g. The pricing page is “Plans” (page 42) — update it, never create a new one.',
						'saddle'
					) }
				/>
				<TextControl
					label={ __( 'Name (optional)', 'saddle' ) }
					value={ draft.key }
					onChange={ ( key ) =>
						setDraft( ( d ) => ( { ...d, key } ) )
					}
					placeholder={ __( 'e.g. pricing-page', 'saddle' ) }
				/>
				<Button
					variant="secondary"
					onClick={ addEntry }
					isBusy={ adding }
					disabled={ adding || ! draft.text.trim() }
				>
					{ __( 'Remember this', 'saddle' ) }
				</Button>
			</div>

			{ settings && (
				<div className="saddle-guide__actions saddle-guide__actions--stack">
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ settings.autoinject_agent }
						onChange={ ( value ) =>
							call( 'memory-settings', {
								method: 'POST',
								data: { autoinject_agent: value },
							} )
						}
						label={ __(
							'Auto-include AI-written memory (off is safest — pin entries instead)',
							'saddle'
						) }
					/>
					{ agentCount > 0 && (
						<Button
							variant="secondary"
							isDestructive
							onClick={ () => {
								if (
									// eslint-disable-next-line no-alert
									window.confirm(
										sprintf(
											/* translators: %d: entry count. */
											__(
												'Delete all %d AI-written memory entries? Your own entries are kept.',
												'saddle'
											),
											agentCount
										)
									)
								) {
									call( 'memory-clear-agent', {
										method: 'POST',
									} );
								}
							} }
						>
							{ __( 'Clear AI-written memory', 'saddle' ) }
						</Button>
					) }
				</div>
			) }
		</section>
	);
}
