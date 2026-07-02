/**
 * Small shared helpers for the Saddle admin app.
 */
import apiFetch from '@wordpress/api-fetch';

export const saddleData = window.saddleData || {};

// Build a namespaced REST path, e.g. ns( 'settings' ).
export const ns = ( path ) => `${ saddleData.ns || 'saddle/v1' }/${ path }`;

// apiFetch against a namespaced Saddle route.
export const api = ( path, options = {} ) =>
	apiFetch( { path: ns( path ), ...options } );

// Tier ordering. Higher rank = more power.
export const TIER_RANK = { read: 0, write: 1, admin: 2 };

// Whether a site at `siteTier` unlocks an ability requiring `abilityTier`.
export const tierUnlocks = ( siteTier, abilityTier ) =>
	( TIER_RANK[ siteTier ] ?? 0 ) >= ( TIER_RANK[ abilityTier ] ?? 0 );

// The two safety levels we present to people. "admin" exists in the backend
// but behaves like "write" today, so we keep the human-facing choice to two.
export const LEVELS = [
	{
		key: 'read',
		icon: 'read',
		title: 'Just reading',
		one: 'Your AI can read your content, but can’t change or delete anything.',
		short: 'Reads posts, pages, and media. Makes no changes.',
		recommended: true,
	},
	{
		key: 'write',
		icon: 'write',
		title: 'Reading & writing',
		one: 'Your AI can create and edit content. Deleting always asks you first.',
		short: 'Creates and edits content. Every deletion previews and asks first.',
		recommended: false,
	},
];

// Map any backend tier to the human-facing level key (admin → write).
export const levelKey = ( tier ) => ( tier === 'read' ? 'read' : 'write' );

// Find the level descriptor for a backend tier.
export const levelFor = ( tier ) =>
	LEVELS.find( ( l ) => l.key === levelKey( tier ) ) || LEVELS[ 0 ];
