/**
 * Cookbook — what to actually type, once an app is connected.
 *
 * The gap this fills: every other screen here configures Saddle. None of them
 * answered the question people have thirty seconds after connecting, which is
 * what do I say. A customer got Saddle onto a production site, connected
 * Claude, published real posts, and called getting there "bit of messing
 * about" — he found the value in spite of the onboarding.
 *
 * Recipes come from the server (Saddle_Cookbook) rather than living here,
 * because the same array publishes to the docs site and two copies of a prompt
 * drift inside a release.
 *
 * Every card states what it needs BEFORE you paste it. A person who runs a
 * prompt and gets refused reads that as a bug in the product, so a recipe above
 * the site's level says so on its face and links to the screen that changes it.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Badge,
	Button,
	Card,
	CardContent,
	CardHeader,
	Notice,
	PageHeader,
	Spinner,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, levelFor, tierUnlocks } from '../api';

export default function Cookbook( { onNavigate } ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ copied, setCopied ] = useState( null );

	useEffect( () => {
		api( 'cookbook' )
			.then( setData )
			.catch( ( e ) =>
				setError(
					e?.message || __( 'Could not load the cookbook.', 'saddle' )
				)
			)
			.finally( () => setLoading( false ) );
	}, [] );

	// Clipboard, with an honest fallback. execCommand is deprecated but is the
	// only thing that works when the page is not a secure context, which a
	// local dev site over plain http is not — and that is exactly where people
	// try this first.
	const copy = async ( recipe, index ) => {
		try {
			if ( window.navigator.clipboard?.writeText ) {
				await window.navigator.clipboard.writeText( recipe.prompt );
			} else {
				const el = document.createElement( 'textarea' );
				el.value = recipe.prompt;
				el.setAttribute( 'readonly', '' );
				el.style.position = 'absolute';
				el.style.left = '-9999px';
				document.body.appendChild( el );
				el.select();
				document.execCommand( 'copy' );
				document.body.removeChild( el );
			}
			setCopied( index );
			setTimeout( () => setCopied( null ), 2000 );
		} catch ( e ) {
			toast.error(
				__(
					'Could not copy. Select the prompt and copy it by hand.',
					'saddle'
				)
			);
		}
	};

	const header = (
		<PageHeader
			title={ __( 'What can I ask Saddle to do?', 'saddle' ) }
			description={ __(
				'Prompts you can paste straight into Claude, ChatGPT or any connected app. Each one says what it needs before you run it.',
				'saddle'
			) }
		/>
	);

	if ( loading ) {
		return (
			<div className="saddle-cookbook">
				{ header }
				<Spinner />
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="saddle-cookbook">
				{ header }
				<Notice tone="danger">{ error }</Notice>
			</div>
		);
	}

	const {
		groups = {},
		recipes = [],
		site_tier: siteTier,
		has_pro: hasPro,
	} = data || {};

	return (
		<div className="saddle-cookbook">
			{ header }

			<Notice tone="info">
				{ sprintf(
					/* translators: %s: the site's current access level, e.g. "Just reading". */
					__(
						'Your site is set to %s. Prompts above that level are marked, and they will be refused until you raise it.',
						'saddle'
					),
					levelFor( siteTier ).title
				) }{ ' ' }
				{ onNavigate && (
					<Button
						variant="link"
						onClick={ () => onNavigate( 'permissions' ) }
					>
						{ __( 'Change the level', 'saddle' ) }
					</Button>
				) }
			</Notice>

			{ Object.entries( groups ).map( ( [ key, label ] ) => {
				const rows = recipes.filter( ( r ) => r.group === key );
				if ( ! rows.length ) {
					return null;
				}

				return (
					<Card key={ key } className="saddle-cookbook__group">
						<CardHeader title={ label } />
						<CardContent>
							{ rows.map( ( recipe ) => {
								// Two independent reasons a prompt will not run
								// right now, and they need different actions
								// from the reader, so never collapse them into
								// one "unavailable" badge.
								const needsLevel = ! tierUnlocks(
									siteTier,
									recipe.tier
								);
								const needsPro = recipe.pro && ! hasPro;
								const index = recipes.indexOf( recipe );

								return (
									<div
										key={ recipe.title }
										className="saddle-recipe"
									>
										<div className="saddle-recipe__head">
											<strong>{ recipe.title }</strong>
											<span className="saddle-recipe__tags">
												{ recipe.pro && (
													<Badge tone="accent">
														{ __(
															'Pro',
															'saddle'
														) }
													</Badge>
												) }
												<Badge
													tone={
														needsLevel
															? 'warning'
															: 'neutral'
													}
												>
													{
														levelFor( recipe.tier )
															.title
													}
												</Badge>
											</span>
										</div>

										<p className="saddle-recipe__prompt">
											{ recipe.prompt }
										</p>

										<p className="saddle-recipe__expect">
											{ recipe.expect }
										</p>

										{ needsPro && (
											<p className="saddle-recipe__blocked">
												{ __(
													'Needs Saddle Pro, which adds the Divi 5 tools.',
													'saddle'
												) }
											</p>
										) }
										{ needsLevel && (
											<p className="saddle-recipe__blocked">
												{ sprintf(
													/* translators: %s: required access level, e.g. "Reading & writing". */
													__(
														'Needs the access level raised to %s.',
														'saddle'
													),
													levelFor( recipe.tier )
														.title
												) }
											</p>
										) }

										<Button
											size="sm"
											variant="secondary"
											onClick={ () =>
												copy( recipe, index )
											}
										>
											{ copied === index
												? __( 'Copied', 'saddle' )
												: __(
														'Copy prompt',
														'saddle'
												  ) }
										</Button>
									</div>
								);
							} ) }
						</CardContent>
					</Card>
				);
			} ) }
		</div>
	);
}
