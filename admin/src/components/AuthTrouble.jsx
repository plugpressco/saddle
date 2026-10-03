/**
 * What we show instead of a blank dashboard when WordPress won't accept the
 * session.
 *
 * Every Saddle admin route is gated on `manage_options`, and WordPress answers
 * a failed gate with 401 when it resolved *no user* and 403 when it resolved a
 * user without the capability. So a 401 here never means "you're not an admin"
 * — it means the credentials this page sent didn't survive the trip. Several
 * unrelated things do that, and they need opposite fixes, so we ask the server
 * which ones arrived before saying anything.
 *
 * The probe route is public precisely so it still answers while every other
 * route is refusing us.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, CalloutCard, CodeBlock } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { probeAuth, verdictFor } from '../auth-probe';
import { saddleData } from '../api';

// Each verdict gets a title, an explanation in plain words, and — where the
// fix belongs to somebody else — the exact sentence to forward to them.
const ADVICE = {
	nonce_header_stripped: () => ( {
		tone: 'warning',
		title: __( 'Your host is removing a WordPress header', 'saddle' ),
		body: __(
			'A security or firewall layer on your hosting strips the X-WP-Nonce header before WordPress sees it. Saddle also sends its sign-in token another way, so reloading may fix this. If it keeps happening, ask your host to let that header through.',
			'saddle'
		),
		forHost: __(
			'Please allow the X-WP-Nonce request header through to WordPress for this site. Your security layer is currently stripping it, which signs admin users out of plugin settings screens.',
			'saddle'
		),
	} ),
	nonce_stripped_both: () => ( {
		tone: 'danger',
		title: __(
			'Your host is blocking this site’s admin screens',
			'saddle'
		),
		body: __(
			'The sign-in token WordPress uses for admin requests isn’t reaching it, as a header or in the address. Saddle can’t work around that. Your host needs to stop filtering these requests.',
			'saddle'
		),
		forHost: __(
			'Please stop filtering requests to /wp-json/ on this site. The X-WP-Nonce header and the _wpnonce parameter are both being removed, which signs administrators out of every plugin settings screen.',
			'saddle'
		),
	} ),
	cookie_stripped: () => ( {
		tone: 'danger',
		title: __( 'Your login isn’t reaching WordPress', 'saddle' ),
		body: __(
			'A caching or security layer is dropping your WordPress login cookie from requests to the site’s API. The requests arrive as if nobody is signed in, and Saddle can’t work around that.',
			'saddle'
		),
		forHost: __(
			'Please exclude /wp-json/ from caching and cookie stripping on this site. WordPress login cookies are not reaching the REST API, so signed-in administrators are treated as logged out.',
			'saddle'
		),
	} ),
	session_invalid: () => ( {
		tone: 'warning',
		title: __( 'Your sign-in has expired', 'saddle' ),
		body: __(
			'Your credentials reached WordPress, but it no longer recognises them. The session may have timed out, or the site was copied from another one, which changes the keys WordPress signs sessions with. Sign in again to fix it.',
			'saddle'
		),
		forHost: '',
	} ),
	credentials_fine: () => ( {
		tone: 'warning',
		title: __( 'Something else refused the request', 'saddle' ),
		body: __(
			'Your sign-in reached WordPress and it recognises you. The usual cause is a security plugin filtering the site’s API. Deactivate security plugins one at a time to find it.',
			'saddle'
		),
		forHost: '',
	} ),
	unreachable: () => ( {
		tone: 'danger',
		title: __( 'Saddle can’t reach this site’s API', 'saddle' ),
		body: __(
			'Even the check that needs no sign-in didn’t answer, so requests to /wp-json/ are being blocked or redirected before WordPress handles them. Your host or a security plugin is the place to look.',
			'saddle'
		),
		forHost: __(
			'Please allow requests to /wp-json/ on this site. They are currently being blocked before WordPress can handle them, which breaks the WordPress REST API and any plugin that relies on it.',
			'saddle'
		),
	} ),
};

export default function AuthTrouble( { onRetry } ) {
	const [ probe, setProbe ] = useState( null );
	const [ checking, setChecking ] = useState( true );

	const check = () => {
		setChecking( true );
		probeAuth()
			.then( setProbe )
			.finally( () => setChecking( false ) );
	};

	useEffect( check, [] );

	if ( checking && ! probe ) {
		return (
			<div className="pp-app saddle-app saddle-app--loading">
				<Spinner />
			</div>
		);
	}

	const advice = ADVICE[ verdictFor( probe ) ]();

	return (
		<div className="pp-app saddle-app saddle-app--setup">
			<CalloutCard
				className="saddle-health"
				tone={ advice.tone }
				title={ advice.title }
				description={ advice.body }
			>
				{ advice.forHost && (
					<CodeBlock
						/* `wrap` because this is a sentence to forward, not
						   code — horizontal scrolling would hide the half of
						   it that says what to actually do. */
						wrap
						label={ __( 'Send this to your host', 'saddle' ) }
						code={ advice.forHost }
					/>
				) }

				<div className="saddle-health__actions">
					<Button
						variant="primary"
						onClick={ onRetry }
						disabled={ checking }
					>
						{ __( 'Try again', 'saddle' ) }
					</Button>
					{ /* Rendered only when we have somewhere to send them —
					     `disabled` means nothing on the anchor Button becomes
					     when it carries an href, and an empty href would just
					     reload this page. */ }
					{ !! saddleData.loginUrl && (
						<Button variant="link" href={ saddleData.loginUrl }>
							{ __( 'Sign in again', 'saddle' ) }
						</Button>
					) }
					<Button
						variant="link"
						onClick={ check }
						disabled={ checking }
					>
						{ checking
							? __( 'Checking…', 'saddle' )
							: __( 'Check again', 'saddle' ) }
					</Button>
				</div>
			</CalloutCard>
		</div>
	);
}
