/**
 * First run (#269, v2 in #277). Saddle reads the site before it asks for
 * anything, connects the owner's AI, proves the connection with a read-only
 * prompt, and only then asks whether it may edit.
 *
 * Steps: the site read (0), which AI (app), connect, try it, and what Saddle
 * can do (choose). The step is stored with each change, so a reload resumes
 * where the owner was. "Skip setup" is on every step and leaves the app at
 * Read only: only the "Let it draft and edit content" button ever raises it,
 * and only for the app that was just connected (#285).
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	CalloutCard,
	CardRadioGroup,
	ChecklistItem,
	EyeIcon,
	Kbd,
	Notice,
	RefreshIcon,
	Row,
	RowList,
	ShieldCheckIcon,
	Snippet,
	StatCard,
	StatGrid,
	useReducedMotion,
} from '@plugpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { AppLogo } from './icons';
import ConnectWizard from './ConnectWizard';
import WaitingLine from './WaitingLine';
import { APPS } from '../connect-apps';
import { createPulse } from '../pulse';
import {
	connectStatus,
	resumeStep,
	stepAfter,
	timeoutTip,
	tryPrompt,
	tryStatus,
} from '../onboarding-logic';

// Time between lines, so each one can be read as it lands.
const BEAT = 700;

const SEO_NAMES = {
	yoast: 'Yoast SEO',
	'rank-math': 'Rank Math',
	aioseo: 'All in One SEO',
};

function siteLine( { site } ) {
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
	// Two whole sentences, each translated on its own.
	return [
		sprintf(
			/* translators: 1: site name, 2: WordPress version, 3: theme name. */
			__( '%1$s runs WordPress %2$s with the %3$s theme.', 'saddle' ),
			site.name,
			site.wp_version,
			site.theme
		),
		builder,
	].join( ' ' );
}

function pluginsLine( { seo, updates } ) {
	const waiting = updates.plugins + updates.themes;
	const parts = [];
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
	return parts.join( ' ' );
}

function findingLines( { findings } ) {
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
	return found;
}

// The six tiles. "Other" opens the rest of the catalog.
const TILES = [ 'claude', 'claude-code', 'chatgpt', 'codex', 'cursor' ];
const OTHER_APPS = [
	'vscode',
	'gemini-cli',
	'windsurf',
	'openclaw',
	'grok',
	'other',
];

// How long the try-it step waits for a tool call before it offers a tip.
const TRY_PATIENCE = 120000;
// Once the first call lands, keep listening this long so the next ones show.
const TRY_SETTLE = 6000;

const appMeta = ( key ) => APPS.find( ( a ) => a.key === key );

const errorText = ( e ) =>
	// apiFetch's raw invalid_json message ("not a valid JSON response") reads
	// like a site fault; name the likely actor.
	'invalid_json' === e.code
		? __(
				'A security layer at your host answered instead of WordPress. Reload and try again — if it keeps happening, ask your host to allow the WordPress REST API for signed-in administrators.',
				'saddle'
		  )
		: e.message;

/**
 * The try-it step: a read-only prompt to paste into the app, and a waiting
 * line that shows each call as it lands.
 *
 * @param {Object}   props
 * @param {string}   props.app    App key.
 * @param {Object}   props.look   GET /first-look, or null.
 * @param {Function} props.onDone The owner carries on; called with the id of
 *                                the connection that made the call, if known.
 */
function TryIt( { app, look, onDone } ) {
	const label = appMeta( app ).label;
	const pulse = useRef( null );
	const [ tried, setTried ] = useState( false );
	const [ slow, setSlow ] = useState( false );
	const connection = useRef( '' );

	if ( ! pulse.current ) {
		pulse.current = createPulse();
	}

	// Enter carries on once it works, unless focus is on a control of its own.
	useEffect( () => {
		if ( ! tried ) {
			return undefined;
		}
		const onKey = ( e ) => {
			if ( 'Enter' === e.key && document.body === e.target ) {
				onDone( connection.current );
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ tried, onDone ] );

	return (
		<div className="saddle-first-run__after">
			<div className="saddle-first-run__ask">
				<h2 className="saddle-first-run__question">
					{ sprintf(
						/* translators: %s: the app name. */
						__( 'Try it. Paste this into %s:', 'saddle' ),
						label
					) }
				</h2>
				<Snippet value={ tryPrompt( look ) } />
			</div>

			<WaitingLine
				check={ () =>
					pulse.current
						.poll()
						.then( ( { rows } ) =>
							tryStatus( { rows, app, appLabel: label } )
						)
				}
				initialText={ sprintf(
					/* translators: %s: the app name. */
					__( 'Waiting for %s to use Saddle…', 'saddle' ),
					label
				) }
				settleMs={ TRY_SETTLE }
				timeoutMs={ TRY_PATIENCE }
				onTimeout={ () => setSlow( true ) }
				onDone={ ( status ) => {
					connection.current = ( status && status.connection ) || '';
					setTried( true );
				} }
			/>

			{ slow && ! tried && (
				<CalloutCard
					tone="warning"
					title={ __( 'Nothing has arrived yet', 'saddle' ) }
					description={ timeoutTip( app, label ) }
				/>
			) }

			<div className="saddle-first-run__actions">
				{ tried ? (
					<Button
						variant="primary"
						onClick={ () => onDone( connection.current ) }
					>
						{ __( 'Continue', 'saddle' ) }
						<Kbd>↵</Kbd>
					</Button>
				) : (
					<Button variant="ghost" onClick={ () => onDone( '' ) }>
						{ __( 'Skip this step', 'saddle' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

/**
 * The last step: what the connected app can do, and the one real choice.
 *
 * The choice is about this app only: "Let it draft and edit content" sets the
 * role of the connection that just made its first call to Edit content. The
 * connection is the one the try step saw; when that is not known (a resume,
 * or the step was skipped) it is the app's newest connection.
 *
 * @param {Object}   props
 * @param {string}   props.app          App key.
 * @param {string}   props.connectionId The connection the try step saw, or ''.
 * @param {string}   props.tier         The site's tier now (older Core only).
 * @param {string}   props.siteName     For the heading.
 * @param {Function} props.onTierSaved  Called with the tier after a site-wide save.
 * @param {Function} props.onFinish     Called with the choice, `read` or `write`.
 */
function Choose( {
	app,
	connectionId,
	tier,
	siteName,
	onTierSaved,
	onFinish,
} ) {
	const label = appMeta( app ).label;
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	// The connection whose access this step sets; undefined until looked up,
	// null when there is none to find.
	const [ connection, setConnection ] = useState( undefined );
	const modules = ( saddleData.areas || [] )
		.filter( ( a ) => a.module )
		.map( ( a ) => a.title );

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
	const canEdit = perApp ? 'read' !== connection.role : 'read' !== tier;

	const allowEditing = () => {
		setSaving( true );
		setError( null );
		const saved = perApp
			? api(
					`connections/${ encodeURIComponent( connection.id ) }/role`,
					{
						method: 'POST',
						data: { role: 'write' },
					}
			  )
			: // No connection is known: fall back to the site-wide tier, as
			  // before roles were per app. Remove once every Core has roles.
			  api( 'preferences', {
					method: 'POST',
					data: { tier: 'write' },
			  } ).then( ( res ) => onTierSaved( res.tier ) );
		saved
			.then( () => onFinish( 'write' ) )
			.catch( ( e ) => {
				setError( errorText( e ) );
				setSaving( false );
			} );
	};

	return (
		<div className="saddle-first-run__after">
			<div className="saddle-first-run__ask">
				<h2 className="saddle-first-run__question">
					{ sprintf(
						/* translators: 1: the app name, 2: the site name. */
						__( 'Here’s what %1$s can do on %2$s.', 'saddle' ),
						label,
						siteName
					) }
				</h2>
				<RowList>
					<Row
						icon={ <EyeIcon size={ 18 } /> }
						title={ __( 'It can look', 'saddle' ) }
						description={
							modules.length
								? sprintf(
										/* translators: %s: module names, e.g. Analytics, SEO. */
										__(
											'Pages, posts, media and settings, and your modules: %s.',
											'saddle'
										),
										modules.join( ', ' )
								  )
								: __(
										'Pages, posts, media and settings.',
										'saddle'
								  )
						}
					/>
					<Row
						icon={ <ShieldCheckIcon size={ 18 } /> }
						title={ __( 'It asks first', 'saddle' ) }
						description={ __(
							'Deleting or changing many things shows you a preview and waits for your OK.',
							'saddle'
						) }
					/>
					<Row
						icon={ <RefreshIcon size={ 18 } /> }
						title={ __( 'You can undo', 'saddle' ) }
						description={ __(
							'Every change is listed on the Dashboard, in the Activity tab, and can be undone.',
							'saddle'
						) }
					/>
				</RowList>
			</div>

			{ error && (
				<Notice tone="danger" onDismiss={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="saddle-first-run__ask">
				<h2 className="saddle-first-run__question">
					{ canEdit
						? sprintf(
								/* translators: %s: the app name. */
								__(
									'Right now %s can draft and edit content.',
									'saddle'
								),
								label
						  )
						: sprintf(
								/* translators: %s: the app name. */
								__( 'Right now %s is read-only.', 'saddle' ),
								label
						  ) }
				</h2>
				<div className="saddle-first-run__actions">
					{ canEdit ? (
						<Button
							variant="primary"
							onClick={ () => onFinish( 'write' ) }
						>
							{ __( 'Go to Saddle', 'saddle' ) }
						</Button>
					) : (
						<>
							<Button
								variant="primary"
								onClick={ allowEditing }
								loading={ saving }
								disabled={ saving || undefined === connection }
							>
								{ __(
									'Let it draft and edit content',
									'saddle'
								) }
							</Button>
							<Button
								variant="ghost"
								onClick={ () => onFinish( 'read' ) }
								disabled={ saving }
							>
								{ __( 'Keep read-only', 'saddle' ) }
							</Button>
						</>
					) }
				</div>
				<p className="saddle-first-run__foot">
					{ __(
						'Skills and instructions live on the Context page, and you can change what each app may do on AI apps. Each module has its own page in the Saddle menu.',
						'saddle'
					) }
				</p>
			</div>
		</div>
	);
}

/**
 * The six tiles. "Other" swaps them for the rest of the catalog.
 *
 * @param {Object}   props
 * @param {Function} props.onPick Called with an app key.
 */
function AppTiles( { onPick } ) {
	const [ other, setOther ] = useState( false );
	const keys = other ? OTHER_APPS : TILES;
	const options = keys.map( ( key ) => ( {
		value: key,
		icon: <AppLogo app={ key } />,
		title: appMeta( key ).label,
		description: appMeta( key ).kind,
	} ) );
	if ( ! other ) {
		options.push( {
			value: '__other',
			icon: <AppLogo app="other" />,
			title: __( 'Other', 'saddle' ),
			description: __(
				'VS Code, Gemini CLI, Windsurf and more',
				'saddle'
			),
		} );
	}

	return (
		<div className="saddle-first-run__ask">
			<h2 className="saddle-first-run__question">
				{ __( 'Which AI do you use?', 'saddle' ) }
			</h2>
			<CardRadioGroup
				className="saddle-wizard__apps"
				aria-label={ __( 'Which AI do you use?', 'saddle' ) }
				options={ options }
				onChange={ ( value ) =>
					'__other' === value ? setOther( true ) : onPick( value )
				}
			/>
			{ other && (
				<Button variant="link" onClick={ () => setOther( false ) }>
					{ __( 'Back to the main apps', 'saddle' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {string}   props.tier             The site's tier (older Core only).
 * @param {Array}    props.clients          Keys, for the wizard's duplicate check.
 * @param {Object}   props.firstRun         `first_run` from GET /onboarding.
 * @param {Function} props.send             Posts one onboarding event.
 * @param {Function} props.onTierSaved      Called with the tier after a save.
 * @param {Function} props.onClientsChanged Reload the keys list.
 * @param {Function} props.onFinish         First run is over; go to the Dashboard.
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
	const storedApp = appMeta( firstRun.app ) ? firstRun.app : '';
	const resumed = useRef( resumeStep( { ...firstRun, app: storedApp } ) );
	const [ step, setStep ] = useState( resumed.current );
	const [ app, setApp ] = useState(
		'read' === resumed.current ? null : storedApp
	);
	// The connection the try step saw make its first call.
	const [ connectionId, setConnectionId ] = useState( '' );
	// The newest block on screen, kept in view the way a chat thread is.
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

	const lines = [];
	if ( look ) {
		lines.push( {
			key: 'site',
			label: __( 'Site read', 'saddle' ),
			hint: siteLine( look ),
			stats: look.content,
		} );
		lines.push( {
			key: 'plugins',
			label: __( 'Plugins checked', 'saddle' ),
			hint: pluginsLine( look ),
		} );
		const found = findingLines( look );
		lines.push(
			found.length
				? {
						key: 'work',
						label: __( 'Found work for your AI', 'saddle' ),
						hint: found.join( ' ' ),
				  }
				: {
						key: 'work',
						label: __( 'Nothing urgent', 'saddle' ),
						hint: __(
							'Your AI can start with whatever you have in mind.',
							'saddle'
						),
				  }
		);
		lines.push( {
			key: 'safe',
			label: __( 'Safe to start', 'saddle' ),
			hint: __(
				'Every app starts at Read only, and asks you before anything risky.',
				'saddle'
			),
		} );
	}

	// Reveal one line per beat, then the next step. All at once when the owner
	// prefers reduced motion, or when this is a resume.
	const total = lines.length + 1;
	const ready = !! look || lookFailed;
	const instant = reduced || 'read' !== resumed.current;
	useEffect( () => {
		if ( ! ready || shown >= total ) {
			return undefined;
		}
		if ( instant ) {
			setShown( total );
			return undefined;
		}
		const t = window.setTimeout( () => setShown( shown + 1 ), BEAT );
		return () => window.clearTimeout( t );
	}, [ ready, shown, total, instant ] );

	// The site read is done: move to choosing an AI, and remember it.
	const read = ready && shown >= total;
	useEffect( () => {
		if ( read && 'read' === step ) {
			setStep( 'app' );
			send( { event: 'first_run.step', step: 'app' } );
		}
	}, [ read, step, send ] );

	useEffect( () => {
		if ( newest.current ) {
			// A hidden tab never runs a smooth scroll; jump instead, so the
			// page is in the right place when the owner comes back to it.
			newest.current.scrollIntoView( {
				behavior: reduced || document.hidden ? 'auto' : 'smooth',
				block: 'nearest',
			} );
		}
	}, [ shown, step, reduced ] );

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
	const siteName = look ? look.site.name : __( 'this site', 'saddle' );

	const renderWaiting = ( { app: appKey, appLabel, keyId, connected } ) => {
		// One pulse reader per attempt: a new key is a new baseline.
		const id = keyId || 'address';
		if ( ! pulseForConnect.current || pulseForConnect.current.id !== id ) {
			pulseForConnect.current = {
				id,
				reader: createPulse( { ignoreExisting: true } ),
			};
		}
		const { reader } = pulseForConnect.current;

		return (
			<WaitingLine
				key={ id }
				check={ () =>
					reader.poll().then( ( { rows, pending } ) =>
						connectStatus( {
							rows,
							pending,
							app: appKey,
							appLabel,
							keyId,
						} )
					)
				}
				initialText={ sprintf(
					/* translators: %s: the app name. */
					__( 'Waiting for %s…', 'saddle' ),
					appLabel
				) }
				onDone={ connected }
			/>
		);
	};

	const afterApp = 'read' !== step && 'app' !== step;

	return (
		<div className="saddle-first-run">
			<div className="saddle-first-run__bar">
				<Button variant="link" onClick={ skip }>
					{ __( 'Skip setup', 'saddle' ) }
				</Button>
			</div>

			<div className="saddle-first-run__column">
				<h2 className="saddle-first-run__title">
					{ __( 'Hi, I’m Saddle.', 'saddle' ) }
				</h2>
				<p className="saddle-first-run__lead">
					{ __(
						'I let your AI work on this site, and I ask you before anything risky. Let me look around first.',
						'saddle'
					) }
				</p>

				<div
					className="saddle-first-run__lines"
					role="status"
					aria-live="polite"
				>
					{ ! ready && (
						<ChecklistItem
							status="active"
							label={ __( 'Reading your site…', 'saddle' ) }
						/>
					) }
					{ lines.slice( 0, shown ).map( ( line, i ) => (
						<div
							key={ line.key }
							ref={
								i === shown - 1 && shown <= lines.length
									? newest
									: undefined
							}
							className="saddle-first-run__line"
						>
							<ChecklistItem
								status="done"
								label={ line.label }
								hint={ line.hint }
							/>
							{ line.stats && (
								<StatGrid
									className="saddle-first-run__stats"
									columns={ 3 }
									divided
								>
									<StatCard
										flush
										label={ __( 'Pages', 'saddle' ) }
										value={ line.stats.pages }
									/>
									<StatCard
										flush
										label={ __( 'Posts', 'saddle' ) }
										value={ line.stats.posts }
									/>
									<StatCard
										flush
										label={ __( 'Media', 'saddle' ) }
										value={ line.stats.media }
									/>
								</StatGrid>
							) }
						</div>
					) ) }
					{ afterApp && label && (
						<div className="saddle-first-run__line">
							<ChecklistItem
								status="done"
								label={ sprintf(
									/* translators: %s: the app name. */
									__( 'You use %s', 'saddle' ),
									label
								) }
							/>
						</div>
					) }
					{ [ 'try', 'choose' ].includes( step ) && (
						<div className="saddle-first-run__line">
							<ChecklistItem
								status="done"
								label={ sprintf(
									/* translators: %s: the app name. */
									__( '%s connected', 'saddle' ),
									label
								) }
							/>
						</div>
					) }
					{ 'choose' === step && (
						<div className="saddle-first-run__line">
							<ChecklistItem
								status="done"
								label={ __( 'It works', 'saddle' ) }
							/>
						</div>
					) }
				</div>

				<div ref={ 'read' === step ? undefined : newest } key={ step }>
					{ 'app' === step && <AppTiles onPick={ pick } /> }

					{ 'connect' === step && app && (
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
					) }

					{ 'try' === step && app && (
						<TryIt
							app={ app }
							look={ look }
							onDone={ ( id ) => {
								setConnectionId( id || '' );
								goTo( stepAfter( 'try' ) );
							} }
						/>
					) }

					{ 'choose' === step && app && (
						<Choose
							app={ app }
							connectionId={ connectionId }
							tier={ tier }
							siteName={ siteName }
							onTierSaved={ onTierSaved }
							onFinish={ finish }
						/>
					) }
				</div>
			</div>
		</div>
	);
}
