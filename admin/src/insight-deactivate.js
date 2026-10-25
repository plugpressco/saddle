/**
 * The Plugins screen's deactivation dialog for the Saddle family
 * (Saddle_Insight_Admin::enqueue_dialog). Deactivate on one of their rows
 * asks why before WordPress turns the plugin off:
 *
 * - Skip and deactivate: follows WordPress's own link at once. Nothing is sent.
 * - Send and deactivate: posts the answer to Saddle, which passes it to
 *   PlugPress, then follows the link, waiting 3 seconds at most.
 * - Escape or Close: nothing happens, and the plugin stays on.
 *
 * Built from DOM nodes with text content only; nothing typed is ever parsed
 * as markup.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	deactivationData,
	familyLinks,
	reasons,
	settleWithin,
} from './insight-deactivate-logic';
import './insight-deactivate.scss';

const config = window.saddleInsight || { path: '', plugins: {} };
const WAIT = 3000;

function el( tag, attrs = {}, children = [] ) {
	const node = document.createElement( tag );
	Object.entries( attrs ).forEach( ( [ key, value ] ) => {
		if ( 'text' === key ) {
			node.textContent = value;
		} else if ( true === value ) {
			node.setAttribute( key, '' );
		} else if ( false !== value && null !== value ) {
			node.setAttribute( key, value );
		}
	} );
	children.forEach( ( child ) => child && node.appendChild( child ) );
	return node;
}

function buildDialog() {
	const title = el( 'h2', { id: 'saddle-insight-dialog-title' } );
	const choices = reasons().map( ( r ) =>
		el( 'label', { class: 'saddle-insight-dialog__choice' }, [
			el( 'input', {
				type: 'radio',
				name: 'saddle-insight-reason',
				value: r.key,
			} ),
			el( 'span', { text: r.label } ),
		] )
	);
	const comment = el( 'textarea', {
		rows: '3',
		maxlength: '500',
		id: 'saddle-insight-comment',
	} );
	const email = el( 'input', {
		type: 'email',
		autocomplete: 'email',
		id: 'saddle-insight-email',
	} );
	const skip = el( 'button', {
		type: 'button',
		class: 'button',
		text: __( 'Skip and deactivate', 'saddle' ),
	} );
	const send = el( 'button', {
		type: 'submit',
		class: 'button button-primary',
		disabled: true,
		text: __( 'Send and deactivate', 'saddle' ),
	} );
	const close = el( 'button', {
		type: 'button',
		class: 'saddle-insight-dialog__close',
		'aria-label': __( 'Close, and keep the plugin on', 'saddle' ),
		text: '×',
	} );
	const form = el( 'form', { method: 'dialog' }, [
		close,
		title,
		el( 'fieldset', {}, [
			el( 'legend', {
				class: 'screen-reader-text',
				text: __( 'Reason', 'saddle' ),
			} ),
			...choices,
		] ),
		el( 'label', {
			for: 'saddle-insight-comment',
			class: 'saddle-insight-dialog__label',
			text: __( 'Anything we should know? (optional)', 'saddle' ),
		} ),
		comment,
		el( 'label', {
			for: 'saddle-insight-email',
			class: 'saddle-insight-dialog__label',
			text: __( 'Email, if you’d like a reply (optional)', 'saddle' ),
		} ),
		email,
		el( 'p', {
			class: 'saddle-insight-dialog__note',
			text: __(
				'Send shares your answer, this plugin’s version, and your WordPress and PHP versions and language with PlugPress. Skip sends nothing.',
				'saddle'
			),
		} ),
		el( 'div', { class: 'saddle-insight-dialog__actions' }, [
			skip,
			send,
		] ),
	] );
	const dialog = el(
		'dialog',
		{
			class: 'saddle-insight-dialog',
			'aria-labelledby': 'saddle-insight-dialog-title',
		},
		[ form ]
	);
	document.body.appendChild( dialog );

	form.addEventListener( 'change', () => {
		send.disabled = ! form.querySelector(
			'input[name="saddle-insight-reason"]:checked'
		);
	} );
	close.addEventListener( 'click', () => dialog.close() );

	return { dialog, form, title, comment, email, skip, send };
}

function init() {
	const links = familyLinks( config.plugins, document );
	if ( ! links.length || ! config.path ) {
		return;
	}
	let ui = null;
	let current = null;

	links.forEach( ( entry ) => {
		entry.link.addEventListener( 'click', ( event ) => {
			// Ctrl, Cmd or Shift opens the link as the person asked.
			if (
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.button !== 0
			) {
				return;
			}
			event.preventDefault();
			if ( ! ui ) {
				ui = buildDialog();
				ui.skip.addEventListener( 'click', () => {
					window.location.href = current.link.href;
				} );
				ui.form.addEventListener( 'submit', ( e ) => {
					e.preventDefault();
					const picked = ui.form.querySelector(
						'input[name="saddle-insight-reason"]:checked'
					);
					if ( ! picked ) {
						return;
					}
					ui.send.disabled = true;
					ui.skip.disabled = true;
					ui.send.textContent = __( 'Sending…', 'saddle' );
					const sending = apiFetch( {
						path: config.path,
						method: 'POST',
						data: deactivationData( {
							plugin: current.plugin,
							reason: picked.value,
							comment: ui.comment.value,
							email: ui.email.value,
						} ),
					} );
					settleWithin( sending, WAIT ).then( () => {
						window.location.href = current.link.href;
					} );
				} );
			}
			current = entry;
			ui.form.reset();
			ui.send.disabled = true;
			ui.skip.disabled = false;
			ui.send.textContent = __( 'Send and deactivate', 'saddle' );
			ui.title.textContent = sprintf(
				/* translators: %s: plugin name, such as Saddle Rank. */
				__( 'Before you go, what made you turn off %s?', 'saddle' ),
				entry.name
			);
			ui.dialog.showModal();
			ui.form.querySelector( 'input[type="radio"]' ).focus();
		} );
	} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
