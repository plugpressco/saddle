/**
 * Settings — the switches and facts that aren't day-to-day work: the pause
 * switch (mirrored by the status pill in the sidebar), read-only connection
 * facts (endpoint, transport, domain), and where to find docs.
 *
 * Deliberately lean: the access level lives on Permissions (it IS that page's
 * job), memory behavior lives on Memory, integration keys on Integrations.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardContent,
	Switch,
	Snippet,
	RowList,
	Row,
	Badge,
	Button,
	PageHeader,
	ExternalLinkIcon,
	StarIcon,
} from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api, saddleData } from '../api';

export default function Settings( { paused, pausing, onTogglePause } ) {
	const [ domain, setDomain ] = useState( null );

	useEffect( () => {
		api( 'settings' )
			.then( ( res ) => setDomain( res.domain || null ) )
			.catch( () => setDomain( null ) );
	}, [] );

	const domainMoved =
		domain && domain.recorded && domain.current !== domain.recorded;

	return (
		<div className="saddle-settings">
			<PageHeader
				title={ __( 'Settings', 'saddle' ) }
				description={ __(
					'Site-wide switches and connection facts. Access levels live on Permissions; integration keys on Integrations.',
					'saddle'
				) }
			/>

			<Card>
				<CardHeader
					title={ __( 'AI access', 'saddle' ) }
					description={ __(
						'The master switch. Pausing blocks every tool call from every connected app until you resume — nothing is disconnected or forgotten.',
						'saddle'
					) }
				/>
				<CardContent>
					<label
						className="saddle-toggle-row"
						htmlFor="saddle-pause-switch"
					>
						<Switch
							id="saddle-pause-switch"
							checked={ ! paused }
							disabled={ pausing }
							onChange={ onTogglePause }
							aria-label={ __(
								'Saddle is answering connected apps',
								'saddle'
							) }
						/>
						<span>
							{ paused
								? __(
										'Paused — every request is refused until you resume.',
										'saddle'
								  )
								: __(
										'Active — connected apps can use their allowed tools.',
										'saddle'
								  ) }
						</span>
					</label>
				</CardContent>
			</Card>

			<Card>
				<CardHeader
					title={ __( 'Connection', 'saddle' ) }
					description={ __(
						'Read-only facts about how apps reach this site. Connect and test apps on the Connections screen.',
						'saddle'
					) }
				/>
				<CardContent>
					{ saddleData.mcpUrl && (
						<Snippet
							label={ __( 'MCP endpoint', 'saddle' ) }
							value={ saddleData.mcpUrl }
						/>
					) }
					<RowList>
						<Row
							title={ __( 'Transport', 'saddle' ) }
							actions={
								<Badge>
									{ saddleData.adapter
										? __( 'Official MCP adapter', 'saddle' )
										: __( 'Built-in fallback', 'saddle' ) }
								</Badge>
							}
						/>
						{ domain && (
							<Row
								title={ __( 'Site address', 'saddle' ) }
								description={
									domainMoved
										? __(
												'The address has changed since write access was granted — review connected apps if this wasn’t a planned move.',
												'saddle'
										  )
										: undefined
								}
								actions={
									<Badge
										tone={
											domainMoved ? 'warning' : undefined
										}
									>
										{ domain.current }
									</Badge>
								}
							/>
						) }
					</RowList>
				</CardContent>
			</Card>

			<Card>
				<CardHeader
					title={ __( 'About', 'saddle' ) }
					description={
						saddleData.version
							? `Saddle v${ saddleData.version }`
							: undefined
					}
				/>
				<CardContent>
					<div className="saddle-settings__links">
						{ saddleData.docsUrl && (
							<Button
								href={ saddleData.docsUrl }
								target="_blank"
								rel="noreferrer"
								variant="ghost"
								size="sm"
							>
								<ExternalLinkIcon size={ 14 } />
								{ __( 'Documentation', 'saddle' ) }
							</Button>
						) }
						{ saddleData.rateUrl && (
							<Button
								href={ saddleData.rateUrl }
								target="_blank"
								rel="noreferrer"
								variant="secondary"
								size="sm"
							>
								<StarIcon size={ 14 } />
								{ __( 'Rate Saddle', 'saddle' ) }
							</Button>
						) }
					</div>
				</CardContent>
			</Card>
		</div>
	);
}
