/**
 * Per-app setup guide, as a drawer — the three things that make a connection
 * work: where to paste, what to paste, and how to say hello. Opens in three
 * modes: with a fresh key (right after a rotation — real ready-to-paste
 * setup, shown once), without one (reference mode with a placeholder, since
 * Saddle never stores a key it could re-show), or for the address path,
 * where there is no key at all and the setup is always the real thing.
 */
import {
	Drawer,
	Button,
	CodeBlock,
	Snippet,
	Notice,
	CalloutCard,
	useCopy,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { AppLogo } from './icons';
import {
	APPS,
	buildConfig,
	buildGuideConfig,
	installLinks,
	MCP_URL,
	HELLO_PROMPT,
	howFor,
} from '../connect-apps';

export default function SetupGuideDrawer( {
	open,
	onOpenChange,
	app, // key from APPS ('claude-code', …); unknown keys fall back to 'other'
	label, // the connection's display name
	password, // fresh raw key (rotation) — omit for placeholder mode
	mode = 'key', // 'key' or 'address'
} ) {
	const { copied, copy } = useCopy();
	const meta = APPS.find( ( a ) => a.key === app ) || APPS[ APPS.length - 1 ];
	const byAddress = 'address' === mode;
	const live = byAddress || !! password;
	let config;
	if ( byAddress ) {
		config = buildGuideConfig( meta.key, 'address' );
	} else if ( password ) {
		config = buildConfig( meta.key, password, 'key' );
	} else {
		config = buildGuideConfig( meta.key, 'key' );
	}
	// Only a real setup gets install links; placeholder mode has no key.
	const links = live
		? installLinks( meta.key, password, byAddress ? 'address' : 'key' )
		: [];

	return (
		<Drawer
			open={ open }
			onOpenChange={ onOpenChange }
			title={ sprintf(
				/* translators: %s: the app name. */
				__( 'Set up %s', 'saddle' ),
				label || meta.label
			) }
			size="lg"
		>
			<div className="saddle-setup-guide">
				<div className="saddle-setup-guide__app">
					<AppLogo app={ meta.key } />
					<span>{ meta.label }</span>
				</div>

				{ byAddress && (
					<CalloutCard
						title={ __( 'Connects by address', 'saddle' ) }
						description={ __(
							'There is no key for this connection. The app registers itself and you approve it on screen; disconnecting it here ends its access.',
							'saddle'
						) }
					/>
				) }
				{ ! byAddress && password && (
					<Notice tone="warning">
						{ __(
							'This fresh key appears only this once — paste it into the app before closing. Saddle keeps just its last four characters.',
							'saddle'
						) }
					</Notice>
				) }
				{ ! byAddress && ! password && (
					<CalloutCard
						title={ __( 'Keys are shown only once', 'saddle' ) }
						description={ __(
							'This guide uses a placeholder where the key goes. To get a real, ready-to-paste setup, use “Rotate key” on the connection — the old key stops working and a fresh one appears here.',
							'saddle'
						) }
					/>
				) }

				<section className="saddle-setup-guide__step">
					<h3>{ __( '1. Where it goes', 'saddle' ) }</h3>
					<p>{ howFor( meta, mode ) }</p>
				</section>

				<section className="saddle-setup-guide__step">
					<h3>{ __( '2. The setup', 'saddle' ) }</h3>
					<CodeBlock
						dark
						copy={ false }
						label={
							live
								? __( 'Ready to paste', 'saddle' )
								: __(
										'Template — replace the placeholder with your key',
										'saddle'
								  )
						}
						code={ config }
					/>
					{ live && (
						<Button
							variant="primary"
							onClick={ () => copy( config ) }
						>
							{ copied
								? __( 'Copied ✓', 'saddle' )
								: __( 'Copy setup', 'saddle' ) }
						</Button>
					) }
					{ links.map( ( link ) => (
						<Button
							key={ link.key }
							variant="secondary"
							href={ link.href }
						>
							{ link.label }
						</Button>
					) ) }
					<Snippet
						label={ __( 'Endpoint', 'saddle' ) }
						value={ MCP_URL }
					/>
				</section>

				<section className="saddle-setup-guide__step">
					<h3>{ __( '3. Say hello', 'saddle' ) }</h3>
					<p>{ meta.next }</p>
					<Snippet
						label={ __( 'Try asking', 'saddle' ) }
						value={ HELLO_PROMPT }
					/>
				</section>
			</div>
		</Drawer>
	);
}
