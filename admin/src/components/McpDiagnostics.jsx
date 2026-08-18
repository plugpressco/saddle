/**
 * Client traffic — what connected apps actually sent, and what they got back.
 *
 * This exists because "my app connected but says it can't do anything" is
 * produced by two different failures that look identical from outside: a
 * request refused before it reaches the tools handler, and a request that
 * succeeds and returns nothing. The served tool count is what tells them
 * apart — and an app whose requests never arrive leaves no rows at all, which
 * is the one thing no amount of server-side logging can otherwise show.
 *
 * Recording is off by default and stops on its own, because this is a
 * debugging instrument rather than a log.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Spinner,
	Badge,
	CodeBlock,
	Card,
	CardHeader,
	CardContent,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';

export default function McpDiagnostics() {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ copied, setCopied ] = useState( false );
	const [ showReport, setShowReport ] = useState( false );

	const load = () =>
		api( 'mcp-diagnostics' )
			.then( setState )
			.catch( () => setState( null ) );

	useEffect( () => {
		load();
	}, [] );

	// While recording, the interesting rows arrive from another process
	// entirely — the connected app — so the panel has to poll to show them.
	useEffect( () => {
		if ( ! state?.recording ) {
			return undefined;
		}
		const timer = setInterval( load, 5000 );
		return () => clearInterval( timer );
	}, [ state?.recording ] );

	const send = ( body ) => {
		setBusy( true );
		api( 'mcp-diagnostics', { method: 'POST', data: body } )
			.then( setState )
			.catch( () => {} )
			.finally( () => setBusy( false ) );
	};

	const copyReport = () => {
		if ( ! state?.report ) {
			return;
		}
		window.navigator.clipboard?.writeText( state.report ).then( () => {
			setCopied( true );
			window.setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	if ( ! state ) {
		return (
			<p className="saddle-health saddle-health--checking">
				<Spinner />
				{ __( 'Loading client traffic…', 'saddle' ) }
			</p>
		);
	}

	const health = state.health || {};
	const entries = state.entries || [];

	return (
		<Card>
			<CardHeader
				title={ __( 'Client traffic', 'saddle' ) }
				description={ __(
					'If a connected app says it can’t see any tools, record its next attempt here — this shows what it asked for and what Saddle sent back.',
					'saddle'
				) }
			/>
			<CardContent>
				<p className="saddle-mcp-diag__health">
					{ health.registered === undefined
						? __(
								'No app has asked for the tool list yet. Requests are recorded below either way.',
								'saddle'
						  )
						: sprintf(
								/* translators: 1: number of tools that loaded, 2: number installed. */
								__(
									'%1$d of the %2$d tools installed here loaded correctly. How many an app is offered depends on its access level — the requests below show that number.',
									'saddle'
								),
								health.registered,
								health.expected
						  ) }
				</p>

				{ health.degraded && (
					<p className="saddle-mcp-diag__degraded">
						{ health.missing?.length
							? sprintf(
									/* translators: %s: comma-separated tool names. */
									__(
										'These tools didn’t load, so apps can’t call them: %s. Reload this page; if they’re still missing, send the report below.',
										'saddle'
									),
									health.missing.join( ', ' )
							  )
							: __(
									'No tools loaded at all, which is never normal. Reload this page; if it persists, send the report below.',
									'saddle'
							  ) }
					</p>
				) }

				<div className="saddle-mcp-diag__actions">
					<Button
						variant={ state.recording ? 'secondary' : 'primary' }
						disabled={ busy }
						onClick={ () =>
							send( { recording: ! state.recording } )
						}
					>
						{ state.recording
							? __( 'Stop recording', 'saddle' )
							: __( 'Record the next hour', 'saddle' ) }
					</Button>

					{ entries.length > 0 && (
						<>
							<Button variant="link" onClick={ copyReport }>
								{ copied
									? __( 'Copied', 'saddle' )
									: __( 'Copy report', 'saddle' ) }
							</Button>
							<Button
								variant="link"
								onClick={ () => setShowReport( ! showReport ) }
							>
								{ showReport
									? __( 'Hide report', 'saddle' )
									: __( 'Show report', 'saddle' ) }
							</Button>
							<Button
								variant="link"
								disabled={ busy }
								onClick={ () => send( { clear: true } ) }
							>
								{ __( 'Clear', 'saddle' ) }
							</Button>
						</>
					) }
				</div>

				{ state.recording && (
					<p className="saddle-mcp-diag__hint">
						{ __(
							'Recording. Now ask the app to refresh its actions, or run any request from it — then come back here.',
							'saddle'
						) }
					</p>
				) }

				{ entries.length === 0 ? (
					<p className="saddle-mcp-diag__empty">
						{ state.recording
							? __( 'Nothing has arrived yet.', 'saddle' )
							: __(
									'Nothing recorded. If an app is misbehaving, start recording and then retry it from the app.',
									'saddle'
							  ) }
					</p>
				) : (
					<table className="saddle-mcp-diag__table">
						<thead>
							<tr>
								<th>{ __( 'When', 'saddle' ) }</th>
								<th>{ __( 'Asked for', 'saddle' ) }</th>
								<th>{ __( 'Signed in with', 'saddle' ) }</th>
								<th>{ __( 'Result', 'saddle' ) }</th>
								<th>{ __( 'App', 'saddle' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ entries.map( ( entry, index ) => (
								<tr key={ `${ entry.time }-${ index }` }>
									<td>
										{ new Date(
											entry.time * 1000
										).toLocaleTimeString() }
									</td>
									<td>
										{ ( entry.methods || [] ).join(
											', '
										) ||
											entry.method ||
											'—' }
									</td>
									<td>
										{ /* The column that answers "was it
										     refused because the key was wrong,
										     or because none arrived?" — which a
										     401 alone cannot. */ }
										{ entry.auth === 'absent' ? (
											<Badge tone="warning">
												{ __(
													'nothing sent',
													'saddle'
												) }
											</Badge>
										) : (
											entry.scheme || '—'
										) }
									</td>
									<td>
										{ entry.status >= 200 &&
										entry.status < 300 ? (
											<Badge tone="success">
												{ entry.tools !== undefined
													? sprintf(
															/* translators: %d: number of tools sent. */
															__(
																'%d tools sent',
																'saddle'
															),
															entry.tools
													  )
													: __( 'OK', 'saddle' ) }
											</Badge>
										) : (
											<Badge tone="danger">
												{ sprintf(
													/* translators: %d: HTTP status code. */
													__(
														'refused (%d)',
														'saddle'
													),
													entry.status
												) }
											</Badge>
										) }
									</td>
									<td>{ entry.client || '—' }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }

				{ showReport && state.report && (
					<CodeBlock>{ state.report }</CodeBlock>
				) }
			</CardContent>
		</Card>
	);
}
