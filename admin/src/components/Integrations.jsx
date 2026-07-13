/**
 * Integrations — services Saddle can hand to the AI beyond core WordPress.
 *
 * Two kinds live here: native integrations that need owner setup (Unsplash's
 * Access Key card), and first-party partner plugins (Waggle, Knovia) whose
 * tools Saddle wraps automatically when the plugin is active — those are
 * detected, never configured here. Per-tool on/off switches stay on the
 * Permissions screen; this page answers "what is connected and how big is it".
 */
import {
	Card,
	CardHeader,
	CardContent,
	RowList,
	Row,
	Badge,
	PageHeader,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import UnsplashKeyCard from './UnsplashKeyCard';

// Presentation for the integration prefixes the capability catalog can
// contain. `detected` copy is only shown for partner plugins that appear.
const KNOWN = {
	waggle: {
		title: __( 'Waggle', 'saddle' ),
		description: __(
			'SEO & AEO tools from the Waggle plugin, wrapped with Saddle’s safety model.',
			'saddle'
		),
	},
	knovia: {
		title: __( 'Knovia', 'saddle' ),
		description: __(
			'Documentation tools from the Knovia plugin, wrapped with Saddle’s safety model.',
			'saddle'
		),
	},
	unsplash: {
		title: __( 'Unsplash', 'saddle' ),
		description: __(
			'Stock-photo search and import, built into Saddle. Needs the Access Key above.',
			'saddle'
		),
	},
};

// Group the Integrations-category capabilities by their prefix (waggle-…,
// knovia-…, unsplash-…) into { key, count } rows.
const detectIntegrations = ( caps ) => {
	const counts = new Map();
	caps.forEach( ( c ) => {
		// The category label comes verbatim from the server catalog.
		if ( 'Integrations' !== c.category ) {
			return;
		}
		const prefix = ( c.short || '' ).split( '-' )[ 0 ];
		if ( ! prefix ) {
			return;
		}
		counts.set( prefix, ( counts.get( prefix ) || 0 ) + 1 );
	} );
	return [ ...counts.entries() ].map( ( [ key, count ] ) => ( {
		key,
		count,
		...( KNOWN[ key ] || { title: key, description: '' } ),
	} ) );
};

export default function Integrations( { caps } ) {
	const detected = detectIntegrations( caps || [] );
	const partnersMissing = ! detected.some(
		( d ) => d.key === 'waggle' || d.key === 'knovia'
	);

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
					title={ __( 'Detected integrations', 'saddle' ) }
					description={ __(
						'Tools these integrations currently add. Turn individual tools off on the Permissions screen.',
						'saddle'
					) }
				/>
				<CardContent>
					<RowList>
						{ detected.map( ( d ) => (
							<Row
								key={ d.key }
								title={ d.title }
								description={ d.description }
								actions={
									<Badge>
										{ sprintf(
											/* translators: %d: number of tools. */
											_n(
												'%d tool',
												'%d tools',
												d.count,
												'saddle'
											),
											d.count
										) }
									</Badge>
								}
							/>
						) ) }
					</RowList>
					{ partnersMissing && (
						<p className="saddle-integrations__hint">
							{ __(
								'Partner plugins are detected automatically: install Waggle (SEO) or Knovia (docs) and their tools appear here — no setup needed.',
								'saddle'
							) }
						</p>
					) }
				</CardContent>
			</Card>
		</div>
	);
}
