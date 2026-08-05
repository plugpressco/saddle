/**
 * Settings — the switches and facts that aren't day-to-day work: the pause
 * switch (mirrored by the status pill in the sidebar), read-only connection
 * facts (endpoint, transport, domain), and where to find docs.
 *
 * Deliberately lean: the access level lives on Permissions (it IS that page's
 * job), memory behavior lives on Memory, integration keys on Integrations.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
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
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { collectSettingsCards, ui, SHELL_VERSION } from '../extensions';

export default function Settings( { paused, pausing, onTogglePause } ) {
	const [ domain, setDomain ] = useState( null );
	const [ oauth, setOauth ] = useState( null );
	const [ savingOauth, setSavingOauth ] = useState( false );
	const [ oauthError, setOauthError ] = useState( '' );
	// Addon-contributed cards (admin/src/extensions.js). Collected at mount:
	// addon bundles registered at script evaluation, before the app mounted.
	const extraCards = useMemo( collectSettingsCards, [] );

	useEffect( () => {
		api( 'preferences' )
			.then( ( res ) => setDomain( res.domain || null ) )
			.catch( () => setDomain( null ) );

		api( 'oauth-settings' )
			.then( setOauth )
			.catch( () => setOauth( null ) );
	}, [] );

	const domainMoved =
		domain && domain.recorded && domain.current !== domain.recorded;

	const saveOauth = ( changes ) => {
		setSavingOauth( true );
		setOauthError( '' );

		api( 'oauth-settings', { method: 'POST', data: changes } )
			.then( setOauth )
			.catch( ( err ) =>
				setOauthError(
					err?.message ||
						__( 'Could not save that setting.', 'saddle' )
				)
			)
			.finally( () => setSavingOauth( false ) );
	};

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
					title={ __( 'Sign-in for ChatGPT', 'saddle' ) }
					description={ __(
						'Most AI apps let you paste a sign-in key. ChatGPT does not — its connector screen has no field for one. Turn this on and ChatGPT can send you here to approve it instead, the same way “Sign in with Google” works. Off by default; everything else keeps working either way.',
						'saddle'
					) }
				/>
				<CardContent>
					{ oauth && ! oauth.ready && (
						<p className="saddle-settings__note">
							{ oauth.permalinks
								? __(
										'This needs your site to be served over HTTPS first — a sign-in token sent over plain HTTP can be read in transit.',
										'saddle'
								  )
								: __(
										'This needs pretty permalinks. Go to Settings → Permalinks, choose anything other than Plain, and come back.',
										'saddle'
								  ) }
						</p>
					) }

					<label
						className="saddle-toggle-row"
						htmlFor="saddle-oauth-switch"
					>
						<Switch
							id="saddle-oauth-switch"
							checked={ !! oauth?.enabled }
							disabled={ ! oauth || ! oauth.ready || savingOauth }
							onChange={ () =>
								saveOauth( { enabled: ! oauth.enabled } )
							}
							aria-label={ __(
								'Allow apps to sign in with your WordPress account',
								'saddle'
							) }
						/>
						<span>
							{ oauth?.enabled
								? __(
										'On — ChatGPT and other apps can ask to connect. You approve each one.',
										'saddle'
								  )
								: __(
										'Off — no app can start a sign-in, and nothing is published for them to find.',
										'saddle'
								  ) }
						</span>
					</label>

					{ oauthError && (
						<p className="saddle-settings__note">{ oauthError }</p>
					) }

					{ oauth?.enabled && (
						<RowList>
							<Row
								title={ __( 'Discoverable', 'saddle' ) }
								description={
									'ok' === oauth.discovery
										? __(
												'Apps can find this site’s sign-in details on their own.',
												'saddle'
										  )
										: __(
												'Apps may not be able to find the sign-in details automatically. This usually means WordPress lives in a subfolder, or your host blocks addresses starting with a dot. Most apps will still connect; ChatGPT may not.',
												'saddle'
										  )
								}
								actions={
									<Badge
										tone={
											'ok' === oauth.discovery
												? undefined
												: 'warning'
										}
									>
										{ 'ok' === oauth.discovery
											? __( 'Yes', 'saddle' )
											: __( 'Maybe not', 'saddle' ) }
									</Badge>
								}
							/>
							<Row
								title={ __(
									'Let apps register themselves',
									'saddle'
								) }
								description={ __(
									'Needed by ChatGPT. An app that registers still cannot do anything until you approve it on screen.',
									'saddle'
								) }
								actions={
									<Switch
										checked={ !! oauth.dcr }
										disabled={ savingOauth }
										onChange={ () =>
											saveOauth( { dcr: ! oauth.dcr } )
										}
										aria-label={ __(
											'Let apps register themselves',
											'saddle'
										) }
									/>
								}
							/>
							<Row
								title={ __( 'Check app identity', 'saddle' ) }
								description={ __(
									'When an app identifies itself by web address, Saddle fetches that address to confirm it vouches for the app. Turning this off means every app shows as unverified.',
									'saddle'
								) }
								actions={
									<Switch
										checked={ !! oauth.cimd }
										disabled={ savingOauth }
										onChange={ () =>
											saveOauth( { cimd: ! oauth.cimd } )
										}
										aria-label={ __(
											'Check app identity',
											'saddle'
										) }
									/>
								}
							/>
						</RowList>
					) }
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
