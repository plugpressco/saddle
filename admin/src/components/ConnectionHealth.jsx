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
							'✓ Fixed — sign-in details now reach WordPress. Your AI apps can connect.',
							'saddle'
					  )
					: __(
							'✓ Server check passed — sign-in details reach WordPress correctly.',
							'saddle'
					  ) }
			</p>
		);
	}

	if ( report.status === 'unknown' ) {
		return (
			<p className="saddle-health saddle-health--muted">
				{ __(
					'We couldn’t verify your server automatically. If AI apps report “unauthorized” even with the right password, ask your host to pass the Authorization header through to WordPress.',
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
					'Something on your hosting — usually a security or firewall layer — strips the X-WP-Nonce header from requests. Saddle works around it, so this dashboard is fine. But the same layer may interfere with other plugins’ settings screens, so it’s worth asking your host to let that header through.',
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

	// status === 'auth_header_stripped'
	const snippets = report.fix_snippet || {};

	return (
		<CalloutCard
			className="saddle-health"
			tone="warning"
			title={ __( 'Your server is blocking app sign-ins', 'saddle' ) }
			description={ __(
				'When an AI app connects, it sends its password in a sign-in header. Your web server removes that header before WordPress can see it, so every connection will fail as “unauthorized” — even with the right password. (The test above can still pass, because your browser signs in a different way.)',
				'saddle'
			) }
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
						'Saddle added the rule, but the header still isn’t arriving — something earlier in the chain (a proxy or your host’s own config) is removing it. Send the rule below to your hosting support and ask them to allow the Authorization header.',
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
								'Apache / LiteSpeed — add to .htaccess',
								'saddle'
							) }
							code={ snippets.apache }
						/>
					) }
					{ snippets.nginx && (
						<CodeBlock
							dark
							label={ __(
								'nginx — add to the PHP location block',
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
