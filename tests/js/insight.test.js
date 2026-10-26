/**
 * Opt-in usage data and Saddle news (Saddle_Insight): when Overview asks,
 * what the answer posts, and the deactivation dialog's rules.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	deactivationData,
	familyLinks,
	reasons,
	settleWithin,
} from '../../admin/src/insight-deactivate-logic';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// The module reads `saddleData.insight` once, when it loads.
function load( insight ) {
	window.saddleData = { insight };
	let mod;
	jest.isolateModules( () => {
		mod = require( '../../admin/src/insight' );
	} );
	return mod;
}

describe( 'the opt-in card', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'asks only while nobody has answered and insight may run', () => {
		expect(
			load( { enabled: true, state: '' } ).shouldAsk( {
				enabled: true,
				state: '',
			} )
		).toBe( true );
		const { shouldAsk } = load( {} );
		expect( shouldAsk( { enabled: true, state: 'in' } ) ).toBe( false );
		expect( shouldAsk( { enabled: true, state: 'out' } ) ).toBe( false );
		expect( shouldAsk( { enabled: false, state: '' } ) ).toBe( false );
	} );

	it( 'starts with the name and email part off', () => {
		expect( load( {} ).insightState().parts ).toEqual( {
			site: true,
			plugins: true,
			profile: false,
		} );
	} );

	it( 'posts the answer to Saddle, never to PlugPress', async () => {
		apiFetch.mockResolvedValue( { enabled: true, state: 'in' } );
		const { answerInsight, insightState } = load( {
			enabled: true,
			state: '',
		} );
		await answerInsight(
			'allow',
			{ site: true, plugins: false, profile: false },
			' me@example.com '
		);

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: 'saddle/v1/insight',
			method: 'POST',
			data: {
				choice: 'allow',
				email: 'me@example.com',
				parts: { site: true, plugins: false, profile: false },
			},
		} );
		expect( insightState().state ).toBe( 'in' );
	} );
} );

describe( 'the deactivation dialog', () => {
	it( 'offers the seven reasons the API knows, in order', () => {
		expect( reasons().map( ( r ) => r.key ) ).toEqual( [
			'not_what_i_needed',
			'hard_to_set_up',
			'broke_something',
			'found_another',
			'short_time',
			'temporary',
			'other',
		] );
	} );

	it( 'answers only for the family’s Deactivate links', () => {
		document.body.innerHTML = `
			<table><tbody id="the-list">
				<tr data-plugin="saddle-rank/saddle-rank.php"><td><div class="row-actions"><span class="deactivate"><a href="#rank">Deactivate</a></span></div></td></tr>
				<tr data-plugin="akismet/akismet.php"><td><div class="row-actions"><span class="deactivate"><a href="#akismet">Deactivate</a></span></div></td></tr>
				<tr data-plugin="saddle-blocks/saddle-blocks.php"><td><div class="row-actions"><span class="activate"><a href="#blocks">Activate</a></span></div></td></tr>
			</tbody></table>`;
		const links = familyLinks(
			{
				'saddle-rank/saddle-rank.php': 'Saddle Rank',
				'saddle-blocks/saddle-blocks.php': 'Saddle Blocks',
			},
			document
		);
		expect( links.map( ( l ) => [ l.plugin, l.name ] ) ).toEqual( [
			[ 'saddle-rank/saddle-rank.php', 'Saddle Rank' ],
		] );
	} );

	it( 'sends what was typed, trimmed, and cuts the comment at 500', () => {
		expect(
			deactivationData( {
				plugin: 'saddle/saddle.php',
				reason: 'other',
				comment: `  ${ 'x'.repeat( 600 ) }  `,
				email: ' me@example.com ',
			} )
		).toEqual( {
			plugin: 'saddle/saddle.php',
			reason: 'other',
			comment: 'x'.repeat( 500 ),
			email: 'me@example.com',
		} );
	} );

	it( 'lets the plugin turn off when the send fails or hangs', async () => {
		jest.useFakeTimers();
		const hung = settleWithin( new Promise( () => {} ), 3000 );
		jest.advanceTimersByTime( 3000 );
		await expect( hung ).resolves.toBeUndefined();
		jest.useRealTimers();

		await expect(
			settleWithin( Promise.reject( new Error( 'offline' ) ), 3000 )
		).resolves.toBeUndefined();
	} );
} );
