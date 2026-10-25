/**
 * Opt-in usage data and Saddle news, for the Overview card and Settings.
 *
 * The server says where the site stands (`saddleData.insight`: whether it may
 * run at all, the owner's answer and the parts left on). Every answer goes
 * through `POST saddle/v1/insight`; the server makes the one call to
 * insight.plugpress.io, never this page.
 */
import { __ } from '@wordpress/i18n';
import { api, saddleData } from './api';

// Where it stands for the rest of this page's life, so the card and
// Settings agree after either one changes it.
let current = {
	enabled: false,
	state: '',
	parts: { site: true, plugins: true, profile: false },
	email: '',
	...( saddleData.insight || {} ),
};

/**
 * The opt-in as this page knows it.
 *
 * @return {{enabled:boolean,state:string,parts:Object,email:string}} State.
 */
export function insightState() {
	return current;
}

/**
 * Whether Overview shows the card: insight may run here and nobody has
 * answered it yet.
 *
 * @param {Object} state From insightState().
 * @return {boolean} Show it.
 */
export function shouldAsk( state ) {
	return !! state.enabled && '' === state.state;
}

/**
 * What each part sends, in the card's order. The versions, the language and
 * the Saddle plugins always go with an opt-in; these can be switched off.
 *
 * @return {Array<{key:string,label:string,detail:string}>} Parts.
 */
export function insightParts() {
	return [
		{
			key: 'site',
			label: __( 'Website info', 'saddle' ),
			detail: __( 'Its address and title', 'saddle' ),
		},
		{
			key: 'plugins',
			label: __( 'Plugins and themes', 'saddle' ),
			detail: __(
				'Their names and versions, and which are on',
				'saddle'
			),
		},
		{
			key: 'profile',
			label: __( 'Your name and email', 'saddle' ),
			detail: __( 'So we can write to you about your site', 'saddle' ),
		},
	];
}

/**
 * Whether a typed address looks like one, before the server checks it.
 *
 * @param {string} email What was typed.
 * @return {boolean} Plausible.
 */
export function plausibleEmail( email ) {
	return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( String( email || '' ).trim() );
}

/**
 * Sends the owner's answer: 'allow' (with the parts and, for news, an
 * address), 'skip', 'off', or 'news' alone.
 *
 * @param {string} choice  allow, skip, off or news.
 * @param {Object} [parts] The parts left on, for allow.
 * @param {string} [email] An address for Saddle news, or ''.
 * @return {Promise<Object>} The new state, with `news` for a news answer.
 */
export function answerInsight( choice, parts, email = '' ) {
	const data = { choice, email: String( email || '' ).trim() };
	if ( parts ) {
		data.parts = parts;
	}
	return api( 'insight', { method: 'POST', data } ).then( ( res ) => {
		current = { ...current, ...res };
		return res;
	} );
}
