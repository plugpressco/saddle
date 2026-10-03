/**
 * Connection health — the server-side half of "Check it's working".
 *
 * The browser test above this can pass while external AI apps still get
 * "unauthorized": the #1 cause is the web server stripping the Authorization
 * header before PHP sees it (the browser test authenticates with cookies, so
 * it never notices). Saddle probes for that server-side and, on Apache/
 * LiteSpeed, can write the standard forwarding rule itself.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, CodeBlock, CalloutCard } from '@plugpress/ui';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

export default function ConnectionHealth() {
	const [ report, setReport ] = useState( null );
	const [ checking, setChecking ] = useState( true );
	const [ fixing, setFixing ] = useState( false );
	const [ fixOutcome, setFixOutcome ] = useState( null ); // 'fixed' | 'still_stripped' | error message
	const [ showSnippets, setShowSnippets ] = useState( false );

	const check = () => {
		setChecking( true );
		api( 'self-check' )
			.then( setReport )
			.catch( () => setReport( { status: 'unknown' } ) )
			.finally( () => setChecking( false ) );
	};

	useEffect( check, [] );

	const applyFix = () => {
		setFixing( true );
		setFixOutcome( null );
		api( 'fix-auth-header', { method: 'POST' } )
			.then( ( res ) => {
				if ( res.auth_header === 'ok' ) {
					setFixOutcome( 'fixed' );
				} else {
					setFixOutcome( 'still_stripped' );
					setShowSnippets( true );
				}
				check();
			} )
			.catch( ( e ) => {
				setFixOutcome(
					e.message ||
						__( 'The automatic fix didn’t work.', 'saddle' )
				);
				setShowSnippets( true );
			} )
			.finally( () => setFixing( false ) );
	};

	if ( checking && ! report ) {
		return (
			<p className="saddle-health saddle-health--checking">
				<Spinner />
				{ __( 'Checking your server setup…', 'saddle' ) }
			</p>
		);
	}

	// Application Passwords being off already has its own warning at the top of
	// this tab — don't say it twice.
	if ( ! report || report.status === 'app_passwords_off' ) {
		return null;
	}

	if ( report.status === 'ok' || fixOutcome === 'fixed' ) {
		return (
			<p className="saddle-health saddle-health--ok">
				{ fixOutcome === 'fixed'
					? __(
							'Fixed. Sign-in details now reach WordPress, so your AI apps can connect.',
							'saddle'
					  )
					: __(
							'Server check passed. Sign-in details reach WordPress.',
							'saddle'
					  ) }
			</p>
		);
	}

	if ( report.status === 'unknown' ) {
		return (
			<p className="saddle-health saddle-health--muted">
				{ __(
					'Saddle couldn’t check your server. If AI apps report “unauthorized” with the right password, ask your host to pass the Authorization header through to WordPress.',
					'saddle'
				) }
			</p>
		);
	}

	// Nothing is broken here — the dashboard already works around this by sending
	// its sign-in token in the address as well as the header. So this is a calm
	// note for the host, not a warning, and there is deliberately no "Fix it for
	// me": the .htaccess rule forwards the Authorization header only, and a
	// security layer that strips at the edge sits upstream of Apache anyway.
	if ( report.status === 'nonce_header_stripped' ) {
		return (
			<CalloutCard
				className="saddle-health"
				tone="default"
				title={ __(
					'Your host is removing one of WordPress’s headers',
					'saddle'
				) }
				description={ __(
					'A security or firewall layer on your hosting strips the X-WP-Nonce header from requests. Saddle works around it, but other plugins’ settings screens may break. Ask your host to let that header through.',
					'saddle'
				) }
			>
				<Button variant="link" onClick={ check } disabled={ checking }>
					{ checking
						? __( 'Checking…', 'saddle' )
						: __( 'Check again', 'saddle' ) }
				</Button>
			</CalloutCard>
		);
	}

	// status === 'auth_header_stripped' | 'bearer_header_stripped'
	const snippets = report.fix_snippet || {};

	// The partial case is the one that costs people weeks, so it gets its own
	// words rather than the generic warning. Pasted-key apps keep working, so
	// every obvious test passes and the natural conclusion — "sign-in headers
	// are fine here" — is wrong. Only apps that sign in through Saddle break,
	// and ChatGPT is the one that can only connect that way.
	const bearerOnly = report.status === 'bearer_header_stripped';

	return (
		<CalloutCard
			className="saddle-health"
			tone="warning"
			title={
				bearerOnly
					? __(
							'Your server is blocking apps that sign in through Saddle',
							'saddle'
					  )
					: __( 'Your server is blocking app sign-ins', 'saddle' )
			}
			description={
				bearerOnly
					? __(
							'Apps connected with a pasted key work. Apps that sign in through Saddle, such as ChatGPT, send a different header. Your web server removes it before WordPress sees it. Those apps finish signing in, then report that the site has no actions. The rule below lets that header through.',
							'saddle'
					  )
					: __(
							'An AI app sends its password in a sign-in header. Your web server removes that header before WordPress sees it. Every connection then fails as “unauthorized”, even with the right password. The test above can still pass, because your browser signs in another way.',
							'saddle'
					  )
			}
		>
			{ report.htaccess_fixable && fixOutcome !== 'still_stripped' && (
				<div className="saddle-health__actions">
					<Button
						variant="primary"
						onClick={ applyFix }
						loading={ fixing }
						disabled={ fixing }
					>
						{ fixing
							? __( 'Fixing…', 'saddle' )
							: __( 'Fix it for me', 'saddle' ) }
					</Button>
					<Button
						variant="link"
						onClick={ () => setShowSnippets( ! showSnippets ) }
					>
						{ showSnippets
							? __( 'Hide the rule', 'saddle' )
							: __( 'See what this adds', 'saddle' ) }
					</Button>
				</div>
			) }

			{ fixOutcome === 'still_stripped' && (
				<p className="saddle-health__body">
					{ __(
						'Saddle added the rule, but the header still isn’t arriving. A proxy or your host’s own configuration removes it first. Send the rule below to your host and ask them to allow the Authorization header.',
						'saddle'
					) }
				</p>
			) }

			{ typeof fixOutcome === 'string' &&
				fixOutcome !== 'fixed' &&
				fixOutcome !== 'still_stripped' && (
					<p className="saddle-health__body saddle-health__error">
						{ fixOutcome }
					</p>
				) }

			{ ! report.htaccess_fixable && (
				<p className="saddle-health__body">
					{ __(
						'Saddle can’t edit this server’s configuration automatically. Add the matching rule below yourself, or send it to your hosting support.',
						'saddle'
					) }
				</p>
			) }

			{ ( showSnippets || ! report.htaccess_fixable ) && (
				<>
					{ snippets.apache && (
						<CodeBlock
							dark
							label={ __(
								'Apache or LiteSpeed: add to .htaccess',
								'saddle'
							) }
							code={ snippets.apache }
						/>
					) }
					{ snippets.nginx && (
						<CodeBlock
							dark
							label={ __(
								'nginx: add to the PHP location block',
								'saddle'
							) }
							code={ snippets.nginx }
						/>
					) }
				</>
			) }

			<Button variant="link" onClick={ check } disabled={ checking }>
				{ checking
					? __( 'Checking…', 'saddle' )
					: __( 'Check again', 'saddle' ) }
			</Button>
		</CalloutCard>
	);
}
