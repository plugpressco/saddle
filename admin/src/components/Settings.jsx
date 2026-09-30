/**
 * Settings → General: read-only connection facts (endpoint, transport,
 * domain), the cards other plugins add, and where to find docs.
 *
 * Every switch lives on the screen it is about: pause, the access level,
 * rehearsal and drafts-only on Connections → Permissions; sign-in for apps on
 * Connections → Apps; the rarely-touched ones on Settings → Advanced.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardContent,
	Snippet,
	RowList,
	Row,
	Badge,
	Button,
	ExternalLinkIcon,
	StarIcon,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { findArea, withArg } from '../routes';
import { collectSettingsCards, ui, SHELL_VERSION } from '../extensions';

export default function Settings() {
	const [ domain, setDomain ] = useState( null );
	// Addon-contributed cards (admin/src/extensions.js). Collected at mount:
	// addon bundles registered at script evaluation, before the app mounted.
	const extraCards = useMemo( collectSettingsCards, [] );

	useEffect( () => {
		api( 'preferences' )
			.then( ( res ) => setDomain( res.domain || null ) )
			.catch( () => setDomain( null ) );
	}, [] );

	const domainMoved =
		domain && domain.recorded && domain.current !== domain.recorded;

	return (
		<div className="saddle-settings">
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

			{ extraCards.map( ( { id, Component } ) => (
				<Component
					key={ id }
					ui={ ui }
					shellVersion={ SHELL_VERSION }
				/>
			) ) }

			{ /* The only home for the version stamp and the outbound links —
			     the nav rail's footer is navigation, nothing else. */ }
			<Card>
				<CardHeader
					title={ __( 'About', 'saddle' ) }
					description={
						saddleData.version
							? sprintf(
									/* translators: %s: plugin version number. */
									__( 'Saddle v%s', 'saddle' ),
									saddleData.version
							  )
							: undefined
					}
				/>
				<CardContent>
					<div className="saddle-settings__links">
						{ findArea( saddleData.areas, 'home' ) && (
							<Button
								href={ withArg(
									findArea( saddleData.areas, 'home' ).url,
									'setup',
									'1'
								) }
								variant="ghost"
								size="sm"
							>
								{ __( 'Run setup again', 'saddle' ) }
							</Button>
						) }
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
