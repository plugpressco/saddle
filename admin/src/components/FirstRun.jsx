/**
 * The welcome (#309; first run since #269, v2 in #277): a conversation.
 *
 * Saddle talks beside its mark, a line at a time, and asks one question at a
 * time. The owner answers with quick replies, which then show as the owner's
 * own messages. Saddle reads the site before it asks anything, connects the
 * owner's AI, proves the connection with a read-only prompt, and only then
 * asks how much the app may do.
 *
 * Steps: the site read (0), which AI (app), connect, try it, and how much it
 * may do (choose). The step is stored with each change, so a reload rebuilds
 * the conversation up to where the owner was. "Skip for now" leaves the app
 * at Read only: only the owner's pick on the last step ever raises it, and
 * only for the app that was just connected (#285).
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	CalloutCard,
	Notice,
	Snippet,
	useReducedMotion,
} from '@plugpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, connectionPath } from '../api';
import ConnectWizard from './ConnectWizard';
import WaitingLine from './WaitingLine';
import { APPS } from '../connect-apps';
import { createPulse } from '../pulse';
import {
	connectPulseOptions,
	connectStatus,
	isBrowserPreview,
	openingStep,
	resumeConnect,
	stepAfter,
	timeoutTip,
	tryPrompt,
	tryStatus,
} from '../onboarding-logic';
import { ROLES } from './ConnectedClients';
import { AppLogo, BrandMark, appKeyFromLabel } from './icons';

// Time between Saddle's lines, so each one can be read as it lands.
const BEAT = 900;

// The apps offered first; "More…" shows the rest.
const FIRST_APPS = [ 'claude', 'chatgpt', 'claude-code', 'codex', 'cursor' ];

const SEO_NAMES = {
	yoast: 'Yoast SEO',
	'rank-math': 'Rank Math',
	aioseo: 'All in One SEO',
};

/**
 * What Saddle says about the site, as one or two messages: what it found,
 * then the first piece of work it noticed, if any. Whole sentences, each
 * translated on its own.
 *
 * @param {Object} look GET /first-look.
 * @return {string[]} Messages.
 */
function lookLines( look ) {
	const { site, findings = {}, seo, updates } = look;
	let builder;
	if ( 'divi5' === site.builder ) {
		builder = __(
			'Pages are built with Divi 5, and your AI can edit them module by module.',
			'saddle'
		);
	} else if ( 'blocks' === site.builder ) {
		builder = __(
			'It’s a block theme, so your AI can edit pages, templates and patterns.',
			'saddle'
		);
	} else {
		builder = __( 'Your AI can edit your pages and posts.', 'saddle' );
	}

	const parts = [
		sprintf(
			/* translators: %s: site name. */
			__( 'I had a quick look around %s.', 'saddle' ),
			site.name
		),
		builder,
	];
	if ( seo && SEO_NAMES[ seo ] ) {
		parts.push(
			sprintf(
				/* translators: %s: SEO plugin name. */
				__(
					'%s is active, so your AI can write titles and descriptions too.',
					'saddle'
				),
				SEO_NAMES[ seo ]
			)
		);
	}
	const waiting = updates ? updates.plugins + updates.themes : 0;
	parts.push(
		waiting
			? sprintf(
					/* translators: %d: number of updates. */
					_n(
						'%d update is waiting.',
						'%d updates are waiting.',
						waiting,
						'saddle'
					),
					waiting
			  )
			: __( 'Everything is up to date.', 'saddle' )
	);

	const found = [];
	if ( findings.missing_alt > 0 ) {
		found.push(
			sprintf(
				/* translators: %d: number of images. */
				_n(
					'%d image has no alt text.',
					'%d images have no alt text.',
					findings.missing_alt,
					'saddle'
				),
				findings.missing_alt
			)
		);
	}
	if ( findings.missing_description > 0 ) {
		found.push(
			sprintf(
				/* translators: %d: number of pages and posts. */
				_n(
					'%d page or post has no search description of its own.',
					'%d pages and posts have no search description of their own.',
					findings.missing_description,
					'saddle'
				),
				findings.missing_description
			)
		);
	}

	const lines = [ parts.join( ' ' ) ];
	if ( found.length ) {
		lines.push(
			sprintf(
				/* translators: %s: one or two sentences, e.g. "12 images have no alt text." */
				__( 'Something your AI could start with: %s', 'saddle' ),
				found.join( ' ' )
			)
		);
	}
	return lines;
}

// How long a waiting step waits before it offers help: the connect step its
// checks, the try-it step a tip.
const PATIENCE = 120000;
// Once the first call lands, keep listening this long so the next ones show.
const TRY_SETTLE = 6000;

// WordPress.org's Live Preview runs the whole site in the visitor's browser,
// where no AI app can reach it.
const IN_PREVIEW = isBrowserPreview( window.location.hostname );

const appMeta = ( key ) => APPS.find( ( a ) => a.key === key );

const errorText = ( e ) =>
	// apiFetch's raw invalid_json message ("not a valid JSON response") reads
	// like a site fault; name the likely actor.
	'invalid_json' === e.code
		? __(
				'A security layer at your host answered instead of WordPress. Reload, and if it keeps happening, ask your host to allow the WordPress REST API for signed-in administrators.',
				'saddle'
		  )
		: e.message;

/* ------------------------------------------------------------------------ */
/* The conversation's pieces                                                */
/* ------------------------------------------------------------------------ */

/**
 * One message from Saddle, beside its mark. A message that follows another
 * of Saddle's own hides the mark, the way a chat thread groups them.
 *
 * @param {Object}  props
 * @param {boolean} props.cont     It follows another of Saddle's messages.
 * @param {boolean} props.typing   Draw the typing dots instead of the content.
 * @param {*}       props.children The message.
 */
function Say( { cont = false, typing = false, children } ) {
	return (
		<div className={ `saddle-chat__say${ cont ? ' is-cont' : '' }` }>
			<span className="saddle-chat__avatar" aria-hidden="true">
				<BrandMark />
			</span>
			<div className="saddle-chat__body">
				{ typing ? (
					<span
						className="saddle-chat__typing"
						role="img"
						aria-label={ __( 'Saddle is typing', 'saddle' ) }
					>
						<i />
						<i />
						<i />
					</span>
				) : (
					children
				) }
			</div>
		</div>
	);
}

/**
 * One of the owner's answers, on the right.
 *
 * @param {Object} props
 * @param {*}      props.children The answer.
 */
function You( { children } ) {
	return (
		<div className="saddle-chat__you">
			<span className="saddle-chat__bubble">{ children }</span>
		</div>
	);
}

/**
 * A finished thing, with a tick: "Claude is connected."
 *
 * @param {Object} props
 * @param {*}      props.children What finished.
 */
function Done( { children } ) {
	return <p className="saddle-chat__done">{ children }</p>;
}

/**
 * Which AI: the common apps as quick replies, and "More…" for the rest.
 *
 * @param {Object}   props
 * @param {Function} props.onPick Called with an app key.
 */
function AppChips( { onPick } ) {
	const [ more, setMore ] = useState( false );
	const keys = more
		? [
				...FIRST_APPS,
				...APPS.map( ( a ) => a.key ).filter(
					( k ) => ! FIRST_APPS.includes( k )
				),
		  ]
		: FIRST_APPS;

	return (
		<div
			className="saddle-chat__replies"
			role="group"
			aria-label={ __( 'Which AI do you use?', 'saddle' ) }
		>
			{ keys
				.filter( ( k ) => appMeta( k ) )
				.map( ( k ) => (
					<button
						key={ k }
						type="button"
						className="saddle-chat__chip"
						onClick={ () => onPick( k ) }
					>
						<AppLogo app={ k } width="18" height="18" />
						{ appMeta( k ).label }
					</button>
				) ) }
			{ ! more && (
				<button
					type="button"
					className="saddle-chat__chip saddle-chat__chip--more"
					onClick={ () => setMore( true ) }
				>
					{ __( 'More…', 'saddle' ) }
				</button>
			) }
		</div>
	);
}

/**
 * The try-it step: a read-only prompt to paste into the app, and a waiting
 * line that shows each call as it lands. Moves on by itself once it works.
 *
 * @param {Object}   props
 * @param {string}   props.app    App key.
 * @param {Object}   props.look   GET /first-look, or null.
 * @param {Function} props.onDone Called with the final status (or null when
 *                                skipped): its `connection` and `items`.
 */
function TryIt( { app, look, onDone } ) {
	const label = appMeta( app ).label;
	const pulse = useRef( null );
	const [ slow, setSlow ] = useState( false );
	// A call has landed: the tip is no longer true, even while the line
	// keeps listening for the next calls.
	const [ arrived, setArrived ] = useState( false );

	if ( ! pulse.current ) {
		pulse.current = createPulse();
	}

	return (
		<Say>
			<p>
				{ sprintf(
					/* translators: %s: the app name. */
					__( 'Let’s try it. Paste this into %s:', 'saddle' ),
					label
				) }
			</p>
			<Snippet value={ tryPrompt( look ) } />
			<WaitingLine
				check={ () =>
					pulse.current.poll().then( ( { rows } ) => {
						const status = tryStatus( {
							rows,
							app,
							appLabel: label,
						} );
						if ( 'done' === status.phase ) {
							setArrived( true );
						}
						return status;
					} )
				}
				initialText={ sprintf(
					/* translators: %s: the app name. */
					__( 'Waiting for %s to ask me something…', 'saddle' ),
					label
				) }
				settleMs={ TRY_SETTLE }
				timeoutMs={ PATIENCE }
				onTimeout={ () => setSlow( true ) }
				onDone={ ( status ) => onDone( status || null ) }
			/>
			{ slow && ! arrived && (
				<CalloutCard
					tone="warning"
					title={ __( 'Nothing has arrived yet', 'saddle' ) }
					description={ timeoutTip( app, label ) }
				/>
			) }
			<div>
				<Button variant="link" onClick={ () => onDone( null ) }>
					{ __( 'Skip this step', 'saddle' ) }
				</Button>
			</div>
		</Say>
	);
}

/**
 * The last question: how much the connected app may do. Read only, Edit
 * content or Manage the site, each with its one-line hint; the app starts at
 * Read only and only this pick raises it.
 *
 * The choice is about this app only: it sets the role of the connection that
 * just made its first call. The connection is the one the try step saw; when
 * that is not known (a resume, or the step was skipped) it is the app's
 * newest connection. With no connection to find, it falls back to the site's
 * tier, as before roles were per app.
 *
 * @param {Object}   props
 * @param {string}   props.app          App key.
 * @param {string}   props.connectionId The connection the try step saw, or ''.
 * @param {string}   props.tier         The site's tier now (older Core only).
 * @param {Function} props.onTierSaved  Called with the tier after a site-wide save.
 * @param {Function} props.onChosen     Called with `read`, `write` or `admin`
 *                                      once the choice is saved.
 */
function Choose( { app, connectionId, tier, onTierSaved, onChosen } ) {
	const label = appMeta( app ).label;
	const [ saving, setSaving ] = useState( '' );
	const [ error, setError ] = useState( null );
	// The connection whose access this step sets; undefined until looked up,
	// null when there is none to find.
	const [ connection, setConnection ] = useState( undefined );

	useEffect( () => {
		let alive = true;
		api( 'connections' )
			.then( ( res ) => {
				const rows = res.connections || [];
				const newest = ( row ) =>
					Math.max( row.last_seen_at || 0, row.created_at || 0 );
				const found =
					rows.find( ( row ) => row.id === connectionId ) ||
					rows
						.filter( ( row ) => row.app === app )
						.sort( ( x, y ) => newest( y ) - newest( x ) )[ 0 ];
				if ( alive ) {
					setConnection( found || null );
				}
			} )
			.catch( () => alive && setConnection( null ) );
		return () => {
			alive = false;
		};
	}, [ app, connectionId ] );

	// Per-app access when the server reports a role for the connection; the
	// site's tier otherwise (no connection known, or a Core without roles).
	const perApp = !! connection && !! connection.role;
	const current = perApp ? connection.role : tier || 'read';

	const pick = ( role ) => {
		if ( 'read' === role || role === current ) {
			onChosen( role );
			return;
		}
		setSaving( role );
		setError( null );
		const saved = perApp
			? api( connectionPath( connection.id, 'role' ), {
					method: 'POST',
					data: { role },
			  } )
			: // No connection is known: fall back to the site-wide tier, as
			  // before roles were per app. Remove once every Core has roles.
			  api( 'preferences', {
					method: 'POST',
					data: { tier: role },
			  } ).then( ( res ) => onTierSaved( res.tier ) );
		saved
			.then( () => onChosen( role ) )
			.catch( ( e ) => {
				setError( errorText( e ) );
				setSaving( '' );
			} );
	};

	if ( undefined === connection ) {
		return (
			<Say cont>
				<p className="saddle-chat__muted">
					{ sprintf(
						/* translators: %s: the app name. */
						__( 'Checking what %s may do…', 'saddle' ),
						label
					) }
				</p>
			</Say>
		);
	}

	return (
		<>
			<Say cont>
				<p>
					{ 'read' === current
						? sprintf(
								/* translators: %s: the app name. */
								__(
									'Right now %s can only look. How much should it do?',
									'saddle'
								),
								label
						  )
						: sprintf(
								/* translators: %s: the app name. */
								__(
									'%s can already do more than look. Keep it that way, or change it:',
									'saddle'
								),
								label
						  ) }
				</p>
				{ error && (
					<Notice tone="danger" onDismiss={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
			</Say>
			<div
				className="saddle-chat__options"
				role="group"
				aria-label={ sprintf(
					/* translators: %s: the app name. */
					__( 'What %s can do', 'saddle' ),
					label
				) }
			>
				{ ROLES.map( ( r ) => (
					<button
						key={ r.key }
						type="button"
						className={ `saddle-chat__option${
							r.key === current ? ' is-current' : ''
						}` }
						disabled={ !! saving }
						aria-busy={ saving === r.key || undefined }
						onClick={ () => pick( r.key ) }
					>
						<strong>{ r.label }</strong>
						<span>{ r.hint }</span>
						{ r.key === current && 'read' !== current && (
							<small>{ __( 'Now', 'saddle' ) }</small>
						) }
					</button>
				) ) }
			</div>
		</>
	);
}

/**
 * What Saddle says once the owner has chosen.
 *
 * @param {string} role  `read`, `write` or `admin`.
 * @param {string} label The app's name.
 * @return {string} One or two sentences.
 */
function endLine( role, label ) {
	switch ( role ) {
		case 'write':
			return sprintf(
				/* translators: %s: the app name. */
				__(
					'Done. %s can now draft and edit your content. Anything big still waits for your OK, and every change shows up on Home.',
					'saddle'
				),
				label
			);
		case 'admin':
			return sprintf(
				/* translators: %s: the app name. */
				__(
					'Done. %s can now help run the site. Anything big still waits for your OK, and every change shows up on Home.',
					'saddle'
				),
				label
			);
		default:
			return sprintf(
				/* translators: %s: the app name. */
				__(
					'Done. %s can look around, and you can give it more on AI apps any time.',
					'saddle'
				),
				label
			);
	}
}

/* ------------------------------------------------------------------------ */
/* The welcome                                                              */
/* ------------------------------------------------------------------------ */

/**
 * @param {Object}   props
 * @param {string}   props.tier             The site's tier (older Core only).
 * @param {Array}    props.clients          Keys, for the wizard's duplicate check.
 * @param {Object}   props.firstRun         `first_run` from GET /onboarding.
 * @param {Function} props.send             Posts one onboarding event.
 * @param {Function} props.onTierSaved      Called with the tier after a save.
 * @param {Function} props.onClientsChanged Reload the keys list.
 * @param {Function} props.onFinish         The welcome is over; go Home.
 */
export default function FirstRun( {
	tier,
	clients,
	firstRun,
	send,
	onTierSaved,
	onClientsChanged,
	onFinish,
} ) {
	const reduced = useReducedMotion();
	const [ look, setLook ] = useState( null );
	const [ lookFailed, setLookFailed ] = useState( false );
	const [ shown, setShown ] = useState( 0 );

	// Where a reload resumes. The site read is always shown again, all at once.
	// "Run setup again" starts at the top (openingStep).
	const storedApp = appMeta( firstRun.app ) ? firstRun.app : '';
	const resumed = useRef(
		resumeConnect(
			openingStep(
				{ ...firstRun, app: storedApp },
				window.location.search
			),
			storedApp,
			clients,
			appKeyFromLabel
		)
	);
	const [ step, setStep ] = useState( resumed.current );
	const [ app, setApp ] = useState(
		'read' === resumed.current ? null : storedApp
	);
	// What the try step saw: the connection that made the first call, and
	// the calls in plain words.
	const [ tried, setTried ] = useState( null );
	// The owner's answer to the last question, once saved.
	const [ chosen, setChosen ] = useState( '' );
	// The newest part of the conversation, kept in view the way a chat is.
	const newest = useRef( null );
	const pulseForConnect = useRef( null );

	useEffect( () => {
		let alive = true;
		api( 'first-look' )
			.then( ( res ) => alive && setLook( res ) )
			.catch( () => alive && setLookFailed( true ) );
		return () => {
			alive = false;
		};
	}, [] );

	// Saddle's opening lines: hello, what it found, and the first question.
	const ready = !! look || lookFailed;
	const intro = [
		__(
			'Hi, I’m Saddle. I let your AI work on this site, and I ask you before anything risky.',
			'saddle'
		),
		...( look ? lookLines( look ) : [] ),
		__( 'Which AI do you use?', 'saddle' ),
	];
	// The hello needs nothing; the rest waits for the site read.
	const available = ready ? intro.length : 1;
	const instant = reduced || 'read' !== resumed.current;

	// One line per beat, with the typing dots in between. All at once when
	// the owner prefers reduced motion, or when this is a resume.
	useEffect( () => {
		if ( shown >= available ) {
			return undefined;
		}
		if ( instant ) {
			setShown( available );
			return undefined;
		}
		const t = window.setTimeout( () => setShown( shown + 1 ), BEAT );
		return () => window.clearTimeout( t );
	}, [ shown, available, instant ] );

	// The opening is done: ask which AI, and remember it.
	const opened = ready && shown >= intro.length;
	useEffect( () => {
		if ( opened && 'read' === step ) {
			setStep( 'app' );
			send( { event: 'first_run.step', step: 'app' } );
		}
	}, [ opened, step, send ] );

	useEffect( () => {
		if ( newest.current ) {
			// A hidden tab never runs a smooth scroll; jump instead, so the
			// page is in the right place when the owner comes back to it.
			newest.current.scrollIntoView( {
				behavior: reduced || document.hidden ? 'auto' : 'smooth',
				block: 'nearest',
			} );
		}
	}, [ shown, step, chosen, reduced ] );

	const goTo = ( next, extra = {} ) => {
		setStep( next );
		send( { event: 'first_run.step', step: next, ...extra } );
	};

	const pick = ( key ) => {
		setApp( key );
		goTo( 'connect', { app: key } );
	};

	const skip = () => {
		window.scrollTo( 0, 0 );
		send( { event: 'first_run.skip' } );
		onFinish();
	};

	const finish = ( choice ) => {
		window.scrollTo( 0, 0 );
		send( { event: 'first_run.done', tier_choice: choice } );
		onFinish();
	};

	const label = app && appMeta( app ) ? appMeta( app ).label : '';
	const role = ROLES.find( ( r ) => r.key === chosen );

	const renderWaiting = ( {
		app: appKey,
		appLabel,
		keyId,
		connected,
		onSlow,
	} ) => {
		// Nothing can arrive in the browser preview: no line to wait on.
		if ( IN_PREVIEW ) {
			return null;
		}
		// One pulse reader per attempt: a new key is a new baseline.
		const id = keyId || 'address';
		if ( ! pulseForConnect.current || pulseForConnect.current.id !== id ) {
			pulseForConnect.current = {
				id,
				reader: createPulse( connectPulseOptions( keyId ) ),
				phase: 'waiting',
			};
		}
		const attempt = pulseForConnect.current;
		// The checks say nothing has reached the site. Once the app has asked
		// to connect, that is no longer true.
		const slow = () => {
			if ( 'waiting' === attempt.phase && onSlow ) {
				onSlow();
			}
		};

		return (
			<WaitingLine
				key={ id }
				check={ () =>
					attempt.reader.poll().then( ( { rows, pending } ) => {
						const status = connectStatus( {
							rows,
							pending,
							app: appKey,
							appLabel,
							keyId,
						} );
						attempt.phase = status.phase;
						return status;
					} )
				}
				initialText={ sprintf(
					/* translators: %s: the app name. */
					__( 'I’ll know the moment %s connects…', 'saddle' ),
					appLabel
				) }
				timeoutMs={ PATIENCE }
				onTimeout={ slow }
				onDone={ connected }
			/>
		);
	};

	const afterApp = 'read' !== step && 'app' !== step;
	const moreIntro = shown < ( ready ? intro.length : 2 );

	return (
		<div className="saddle-chat">
			<div className="saddle-chat__bar">
				<Button variant="link" onClick={ skip }>
					{ __( 'Skip for now', 'saddle' ) }
				</Button>
			</div>

			<div
				className="saddle-chat__thread"
				role="log"
				aria-live="polite"
				aria-label={ __( 'Welcome', 'saddle' ) }
			>
				{ intro.slice( 0, shown ).map( ( text, i ) => (
					<div
						key={ `intro-${ i }` }
						ref={ i === shown - 1 ? newest : undefined }
					>
						<Say cont={ i > 0 }>
							<p>{ text }</p>
						</Say>
					</div>
				) ) }
				{ moreIntro && ! instant && <Say cont={ shown > 0 } typing /> }

				<div ref={ 'read' === step ? undefined : newest } key={ step }>
					{ 'app' === step && <AppChips onPick={ pick } /> }

					{ afterApp && label && (
						<You>
							<AppLogo app={ app } width="18" height="18" />
							{ label }
						</You>
					) }

					{ 'connect' === step && app && (
						<Say>
							<p>
								{ sprintf(
									/* translators: %s: the app name. */
									__( 'Let’s connect %s.', 'saddle' ),
									label
								) }
							</p>
							{ IN_PREVIEW && (
								<CalloutCard
									title={ __(
										'This preview runs in your browser',
										'saddle'
									) }
									description={ __(
										'So no AI app can connect to it. To connect one, install Saddle on your own site.',
										'saddle'
									) }
								>
									<Button variant="primary" onClick={ skip }>
										{ __(
											'Skip and look around',
											'saddle'
										) }
									</Button>
								</CalloutCard>
							) }
							<ConnectWizard
								embedded
								presetApp={ app }
								tier={ tier }
								clients={ clients }
								onClientsChanged={ onClientsChanged }
								renderWaiting={ renderWaiting }
								onBack={ () => goTo( 'app' ) }
								onConnected={ () => goTo( 'try' ) }
								onExit={ skip }
							/>
						</Say>
					) }

					{ [ 'try', 'choose' ].includes( step ) && label && (
						<Say>
							<Done>
								{ sprintf(
									/* translators: %s: the app name. */
									__( '%s is connected.', 'saddle' ),
									label
								) }
							</Done>
						</Say>
					) }

					{ 'try' === step && app && (
						<TryIt
							app={ app }
							look={ look }
							onDone={ ( status ) => {
								setTried( status );
								goTo( stepAfter( 'try' ) );
							} }
						/>
					) }

					{ 'choose' === step && tried && (
						<Say cont>
							<Done>{ __( 'It works.', 'saddle' ) }</Done>
							{ tried.items && tried.items.length > 0 && (
								<ul className="saddle-chat__calls">
									{ tried.items.map( ( item, i ) => (
										<li key={ i }>{ item }</li>
									) ) }
								</ul>
							) }
						</Say>
					) }

					{ 'choose' === step && app && ! chosen && (
						<Choose
							app={ app }
							connectionId={ ( tried && tried.connection ) || '' }
							tier={ tier }
							onTierSaved={ onTierSaved }
							onChosen={ setChosen }
						/>
					) }

					{ 'choose' === step && role && (
						<>
							<You>{ role.label }</You>
							<Say>
								<p>{ endLine( chosen, label ) }</p>
								<div>
									<Button
										variant="primary"
										onClick={ () => finish( chosen ) }
									>
										{ __( 'Finish', 'saddle' ) }
									</Button>
								</div>
							</Say>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
