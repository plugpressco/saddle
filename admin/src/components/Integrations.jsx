/**
 * Integrations — services and plugins Saddle can hand to the AI beyond core
 * WordPress.
 *
 * Three kinds of row, all served by GET /integrations: tools built into
 * Saddle (Unsplash, which needs the Access Key card, and the SEO/store
 * plugins it edits natively), PlugPress plugins whose tools Saddle wraps as
 * soon as they are active, and third-party plugins that enrolled through the
 * public `saddle_integrations` filter. Third-party rows start switched off;
 * the owner turns them on here. Per-tool on/off switches stay on the
 * Permissions screen.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardContent,
	RowList,
	Row,
	Badge,
	Switch,
	toast,
	PageHeader,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api } from '../api';
import UnsplashKeyCard from './UnsplashKeyCard';

const SOURCE_LABELS = {
	plugpress: __( 'PlugPress', 'saddle' ),
	'third-party': __( 'Third-party', 'saddle' ),
	'built-in': __( 'Built in', 'saddle' ),
};

// Integrations-category tools no listed row accounts for — an add-on that
// wraps tools through its own engine and doesn't add a row — grouped by the
// first segment of their name so they still show up.
const unlistedRows = ( caps, rows ) => {
	const listed = rows.map( ( r ) => `${ r.slug }-` );
	const counts = new Map();
	caps.forEach( ( c ) => {
		const short = c.short || '';
		if (
			'Integrations' !== c.category ||
			listed.some( ( p ) => short.startsWith( p ) )
		) {
			return;
		}
		const slug = short.split( '-' )[ 0 ];
		if ( slug ) {
			counts.set( slug, ( counts.get( slug ) || 0 ) + 1 );
		}
	} );
	return [ ...counts.entries() ].map( ( [ slug, tools ] ) => ( {
		slug,
		title: slug.charAt( 0 ).toUpperCase() + slug.slice( 1 ),
		description: '',
		author: '',
		url: '',
		source: '',
		enabled: true,
		tools,
	} ) );
};

const describe = ( row ) => {
	if ( ! row.author ) {
		return row.description;
	}
	/* translators: %s: plugin author. */
	const byline = sprintf( __( 'By %s', 'saddle' ), row.author );
	return (
		<>
			{ row.description && <>{ row.description } · </> }
			{ row.url ? (
				<a href={ row.url } target="_blank" rel="noopener noreferrer">
					{ byline }
				</a>
			) : (
				byline
			) }
		</>
	);
};

export default function Integrations( { caps, onChanged } ) {
	const [ rows, setRows ] = useState( null );
	const [ saving, setSaving ] = useState( '' );

	useEffect( () => {
		api( 'integrations' )
			.then( ( res ) => setRows( res.integrations || [] ) )
			.catch( ( e ) => {
				setRows( [] );
				toast.error( e.message );
			} );
	}, [] );

	const toggle = ( row, next ) => {
		setSaving( row.slug );
		api( 'integrations', {
			method: 'POST',
			data: { slug: row.slug, enabled: next },
		} )
			.then( ( res ) => {
				setRows( res.integrations || [] );
				toast.success(
					next
						? sprintf(
								/* translators: %s: plugin name. */
								__(
									'%s is on. Its tools follow your access level and approval rules.',
									'saddle'
								),
								row.title
						  )
						: sprintf(
								/* translators: %s: plugin name. */
								__( '%s is off.', 'saddle' ),
								row.title
						  )
				);
				if ( onChanged ) {
					onChanged();
				}
			} )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setSaving( '' ) );
	};

	const all = rows ? [ ...rows, ...unlistedRows( caps || [], rows ) ] : [];

	return (
		<div className="saddle-integrations">
			<PageHeader
				title={ __( 'Integrations', 'saddle' ) }
				description={ __(
					'Extra services your AI can use through Saddle — every tool still follows your access level and approval rules.',
					'saddle'
				) }
			/>

			<UnsplashKeyCard />

			<Card>
				<CardHeader
					title={ __( 'Connected plugins', 'saddle' ) }
					description={ __(
						'Plugins whose tools your AI can use through Saddle. Turn individual tools off on the Permissions screen.',
						'saddle'
					) }
				/>
				<CardContent>
					<RowList loading={ null === rows }>
						{ all.map( ( row ) => (
							<Row
								key={ row.slug }
								title={ row.title }
								description={ describe( row ) }
								actions={
									<>
										{ SOURCE_LABELS[ row.source ] && (
											<Badge>
												{ SOURCE_LABELS[ row.source ] }
											</Badge>
										) }
										<Badge>
											{ sprintf(
												/* translators: %d: number of tools. */
												_n(
													'%d tool',
													'%d tools',
													row.tools,
													'saddle'
												),
												row.tools
											) }
										</Badge>
										{ 'third-party' === row.source && (
											<Switch
												checked={ row.enabled }
												disabled={ saving === row.slug }
												onChange={ ( next ) =>
													toggle( row, next )
												}
												aria-label={ sprintf(
													/* translators: %s: plugin name. */
													__(
														'Let your AI use %s tools',
														'saddle'
													),
													row.title
												) }
											/>
										) }
									</>
								}
							/>
						) ) }
					</RowList>
					<p className="saddle-integrations__hint">
						{ __(
							'Plugins that support Saddle appear here once they are active. Third-party plugins stay off until you switch them on.',
							'saddle'
						) }
					</p>
				</CardContent>
			</Card>
		</div>
	);
}
