/**
 * First run (#269). Saddle reads the site before it asks for anything, then
 * connects the owner's AI, then asks for edit rights in the light of what it
 * found, and ends on a first prompt made from those findings.
 *
 * It replaces a welcome screen and an up-front safety-level choice. The
 * owner is never asked to decide something before they have seen a reason
 * to, and a new install stays at read unless they click "Let it edit".
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	ChecklistItem,
	Notice,
	Snippet,
	StatCard,
	StatGrid,
	useReducedMotion,
} from '@plugpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, levelFor } from '../api';
import { BrandMark } from './icons';
import ConnectWizard from './ConnectWizard';
import { HELLO_PROMPT } from '../connect-apps';

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

// The first thing to ask, made from what Saddle found.
function firstPrompt( look, canEdit ) {
	const findings = look ? look.findings : {};
	if ( findings.missing_alt > 0 ) {
		return canEdit
			? __(
					'Find the images on my site with no alt text and write alt text for each one.',
					'saddle'
			  )
			: __( 'Which images on my site have no alt text?', 'saddle' );
	}
	if ( findings.missing_description > 0 ) {
		return canEdit
			? __(
					'Find my pages and posts with no search description and write one for each.',
					'saddle'
			  )
			: __(
					'Which of my pages and posts have no search description?',
					'saddle'
			  );
	}
	return HELLO_PROMPT;
}

export default function FirstRun( {
	tier,
	clients,
	onTierSaved,
	onClientsChanged,
	onFinish,
} ) {
	const reduced = useReducedMotion();
	const [ look, setLook ] = useState( null );
	const [ lookFailed, setLookFailed ] = useState( false );
	const [ shown, setShown ] = useState( 0 );
	const [ app, setApp ] = useState( null );
	const [ decided, setDecided ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	// The newest block on screen, kept in view the way a chat thread is.
	const newest = useRef( null );

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
			hint: levelFor( tier ).one,
		} );
	}

	// Reveal one line per beat, then the connect step. All at once when the
	// owner prefers reduced motion.
	const total = lines.length + 1;
	const ready = !! look || lookFailed;
	useEffect( () => {
		if ( ! ready || shown >= total ) {
			return undefined;
		}
		if ( reduced ) {
			setShown( total );
			return undefined;
		}
		const t = window.setTimeout( () => setShown( shown + 1 ), BEAT );
		return () => window.clearTimeout( t );
	}, [ ready, shown, total, reduced ] );

	useEffect( () => {
		if ( newest.current ) {
			// A hidden tab never runs a smooth scroll; jump instead, so the
			// page is in the right place when the owner comes back to it.
			newest.current.scrollIntoView( {
				behavior: reduced || document.hidden ? 'auto' : 'smooth',
				block: 'nearest',
			} );
		}
	}, [ shown, app, decided, reduced ] );

	const canEdit = 'read' !== tier;

	const allowEditing = () => {
		setSaving( true );
		setError( null );
		api( 'preferences', { method: 'POST', data: { tier: 'write' } } )
			.then( ( res ) => {
				onTierSaved( res.tier );
				setDecided( true );
			} )
			.catch( ( e ) =>
				setError(
					// apiFetch's raw invalid_json message ("not a valid JSON
					// response") reads like a site fault; name the likely actor.
					'invalid_json' === e.code
						? __(
								'A security layer at your host answered instead of WordPress. Reload and try again — if it keeps happening, ask your host to allow the WordPress REST API for signed-in administrators.',
								'saddle'
						  )
						: e.message
				)
			)
			.finally( () => setSaving( false ) );
	};

	// The dashboard opens at its top, not at wherever this page was scrolled.
	const finish = () => {
		window.scrollTo( 0, 0 );
		onFinish( { connect: false } );
	};

	return (
		<div className="saddle-first-run">
			<div className="saddle-first-run__bar">
				<span className="saddle-first-run__mark" aria-hidden="true">
					<BrandMark />
				</span>
				<Button variant="link" onClick={ finish }>
					{ app
						? __( 'Go to Saddle', 'saddle' )
						: __( 'Skip setup', 'saddle' ) }
				</Button>
			</div>

			<div className="saddle-first-run__column">
				<h1 className="saddle-first-run__title">
					{ __( 'Hi, I’m Saddle.', 'saddle' ) }
				</h1>
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
				</div>

				{ ready && shown >= total && ! app && (
					<div ref={ newest }>
						<ConnectWizard
							embedded
							tier={ tier }
							clients={ clients }
							onClientsChanged={ onClientsChanged }
							onConnected={ setApp }
							onExit={ finish }
						/>
					</div>
				) }

				{ app && (
					<div className="saddle-first-run__after" ref={ newest }>
						<ChecklistItem
							status="done"
							label={ sprintf(
								/* translators: %s: the app name. */
								__( '%s connected', 'saddle' ),
								app.label
							) }
						/>

						{ error && (
							<Notice
								tone="danger"
								onDismiss={ () => setError( null ) }
							>
								{ error }
							</Notice>
						) }

						{ ! canEdit && ! decided && (
							<div className="saddle-first-run__ask">
								<h2 className="saddle-first-run__question">
									{ __(
										'Right now it can look, not touch.',
										'saddle'
									) }
								</h2>
								<p className="saddle-first-run__lead">
									{ __(
										'Let it create and edit content too? Deleting always asks you first, and every change is logged in Activity. You can change this anytime in Permissions.',
										'saddle'
									) }
								</p>
								<div className="saddle-first-run__actions">
									<Button
										variant="primary"
										onClick={ allowEditing }
										loading={ saving }
										disabled={ saving }
									>
										{ __(
											'Let it edit content',
											'saddle'
										) }
									</Button>
									<Button
										variant="ghost"
										onClick={ () => setDecided( true ) }
										disabled={ saving }
									>
										{ __( 'Keep it read-only', 'saddle' ) }
									</Button>
								</div>
							</div>
						) }

						{ ( canEdit || decided ) && (
							<div className="saddle-first-run__ask">
								<h2 className="saddle-first-run__question">
									{ sprintf(
										/* translators: %s: the app name. */
										__( 'Try this in %s', 'saddle' ),
										app.label
									) }
								</h2>
								<Snippet
									value={ firstPrompt( look, canEdit ) }
								/>
								<div className="saddle-first-run__actions">
									<Button
										variant="primary"
										onClick={ finish }
									>
										{ __( 'Go to Saddle', 'saddle' ) }
									</Button>
								</div>
							</div>
						) }
					</div>
				) }
			</div>
		</div>
	);
}
