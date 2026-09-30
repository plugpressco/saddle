/**
 * Sign-in for apps: the one control for OAuth on this site. It lives on
 * Connections → Apps, beside the apps it lets in. On or off, whether the site
 * can do it yet, whether apps can discover it, and the two registration
 * switches all sit here; nothing else on the page flips them.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Switch, RowList, Row, Badge, HelpTip } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import SectionHeader from './SectionHeader';

/**
 * The state behind the card, shared with the Apps tab so the hint above the
 * app picker follows the switch.
 *
 * @return {Object} `{ oauth, saving, error, save }`.
 */
export function useOauthSettings() {
	const [ oauth, setOauth ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		api( 'oauth-settings' )
			.then( setOauth )
			.catch( () => setOauth( { enabled: false, ready: false } ) );
	}, [] );

	const save = useCallback( ( changes ) => {
		setSaving( true );
		setError( '' );
		api( 'oauth-settings', { method: 'POST', data: changes } )
			.then( setOauth )
			.catch( ( err ) =>
				setError(
					err?.message ||
						__( 'Could not save that setting.', 'saddle' )
				)
			)
			.finally( () => setSaving( false ) );
	}, [] );

	return { oauth, saving, error, save };
}

/**
 * What the discovery probe found, in the owner's language.
 *
 * 'slow' is called out separately because it fails identically to a missing
 * document from the app's side, but for the opposite reason — the document is
 * there and correct, the site just answers too late — so the subfolder and
 * blocked-path advice below would send the owner chasing the wrong thing.
 *
 * @param {string} state Probe result: 'ok' | 'slow' | 'unreachable' | 'unknown'.
 * @return {string} Description for the Discoverable row.
 */
const DISCOVERY_NOTE = ( state ) => {
	if ( 'ok' === state ) {
		return __(
			'Apps can find this site’s sign-in details on their own.',
			'saddle'
		);
	}

	if ( 'slow' === state ) {
		return __(
			'This site answers too slowly for some apps to finish connecting. The sign-in details are correct and in the right place, but ChatGPT gives up after a few seconds and then reports that this site doesn’t support signing in. A page cache or a faster host usually fixes it.',
			'saddle'
		);
	}

	return __(
		'Apps may not be able to find the sign-in details automatically. This usually means WordPress lives in a subfolder, or your host blocks addresses starting with a dot. Most apps will still connect; ChatGPT may not.',
		'saddle'
	);
};

const DISCOVERY_BADGE = ( state ) => {
	if ( 'ok' === state ) {
		return __( 'Yes', 'saddle' );
	}

	if ( 'slow' === state ) {
		return __( 'Too slow', 'saddle' );
	}

	return __( 'Maybe not', 'saddle' );
};

/**
 * A row label with its long explanation behind a "?".
 *
 * @param {Object} props
 * @param {*}      props.children The label.
 * @param {*}      props.help     The explanation.
 */
const Labelled = ( { children, help } ) => (
	<span className="saddle-signin__label">
		{ children }
		<HelpTip>{ help }</HelpTip>
	</span>
);

/**
 * @param {Object}   props
 * @param {Object}   props.oauth  The settings from useOauthSettings().
 * @param {boolean}  props.saving Whether a save is running.
 * @param {string}   props.error  Last error.
 * @param {Function} props.save   Saves `{ enabled, dcr, cimd }` changes.
 */
export default function SignInCard( { oauth, saving, error, save } ) {
	let state = __( 'Off', 'saddle' );
	if ( oauth && ! oauth.ready ) {
		state = oauth.permalinks
			? __( 'Needs HTTPS first.', 'saddle' )
			: __(
					'Needs pretty permalinks: Settings → Permalinks, anything but Plain.',
					'saddle'
			  );
	} else if ( oauth?.enabled ) {
		state = __( 'On. You approve each app.', 'saddle' );
	}

	return (
		<section className="saddle-section" id="saddle-signin">
			<SectionHeader
				title={
					<Labelled
						help={ __(
							'With this on, an app needs only this site’s address: it opens your browser and you approve it here, the same way “Sign in with Google” works. Claude, ChatGPT, Claude Code, Codex, Cursor, VS Code and Gemini CLI all connect this way, and ChatGPT can connect no other way. Off by default; pasted keys keep working either way.',
							'saddle'
						) }
					>
						{ __( 'Sign-in for apps', 'saddle' ) }
					</Labelled>
				}
			/>

			<RowList>
				<Row
					title={ __( 'Let apps sign in', 'saddle' ) }
					description={ state }
					actions={
						<Switch
							id="saddle-oauth-switch"
							checked={ !! oauth?.enabled }
							disabled={ ! oauth || ! oauth.ready || saving }
							onChange={ () =>
								save( { enabled: ! oauth.enabled } )
							}
							aria-label={ __(
								'Allow apps to sign in with your WordPress account',
								'saddle'
							) }
						/>
					}
				/>
				{ oauth?.enabled && (
					<>
						<Row
							title={
								<Labelled
									help={ DISCOVERY_NOTE( oauth.discovery ) }
								>
									{ __( 'Discoverable', 'saddle' ) }
								</Labelled>
							}
							actions={
								<Badge
									tone={
										'ok' === oauth.discovery
											? undefined
											: 'warning'
									}
								>
									{ DISCOVERY_BADGE( oauth.discovery ) }
								</Badge>
							}
						/>
						<Row
							title={
								<Labelled
									help={ __(
										'Needed to connect by address. An app that registers still cannot do anything until you approve it on screen.',
										'saddle'
									) }
								>
									{ __(
										'Let apps register themselves',
										'saddle'
									) }
								</Labelled>
							}
							actions={
								<Switch
									checked={ !! oauth.dcr }
									disabled={ saving }
									onChange={ () =>
										save( { dcr: ! oauth.dcr } )
									}
									aria-label={ __(
										'Let apps register themselves',
										'saddle'
									) }
								/>
							}
						/>
						<Row
							title={
								<Labelled
									help={ __(
										'When an app identifies itself by web address, Saddle fetches that address to confirm it vouches for the app. Turning this off means every app shows as unverified.',
										'saddle'
									) }
								>
									{ __( 'Check app identity', 'saddle' ) }
								</Labelled>
							}
							actions={
								<Switch
									checked={ !! oauth.cimd }
									disabled={ saving }
									onChange={ () =>
										save( { cimd: ! oauth.cimd } )
									}
									aria-label={ __(
										'Check app identity',
										'saddle'
									) }
								/>
							}
						/>
					</>
				) }
			</RowList>

			{ error && <p className="saddle-settings__note">{ error }</p> }
		</section>
	);
}
