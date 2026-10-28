/**
 * PlugPress Insight: the news bar, "Stop sharing" and quick feedback. The
 * markup comes from PHP; this only answers clicks. Every click posts to the
 * site's own admin-ajax.php, and the site's server does the sending.
 */
( function () {
	const config = window.plugpressInsight;
	if ( ! config ) {
		return;
	}

	function post( data ) {
		const body = new FormData();
		body.append( 'action', 'plugpress_insight' );
		body.append( '_ajax_nonce', config.nonce );
		Object.keys( data ).forEach( ( key ) => body.append( key, data[ key ] ) );
		return fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body } );
	}

	// The news bar: Subscribe sends what it says, the × sends nothing.
	const bar = document.querySelector( '.plugpress-insight-bar' );
	if ( bar ) {
		const form = bar.querySelector( 'form' );
		const email = form.elements.email;
		const usage = bar.querySelector( '[name="usage"]' );
		const send = form.querySelector( 'button' );
		const ready = () => {
			send.disabled = ! email.checkValidity() || ( ! email.value && ! usage.checked );
		};
		const fade = ( wait ) => {
			setTimeout( () => bar.classList.add( 'is-gone' ), wait );
			setTimeout( () => bar.remove(), wait + 400 );
		};
		email.addEventListener( 'input', ready );
		usage.addEventListener( 'change', ready );
		ready();

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			send.disabled = true;
			post( { do: 'allow', slug: bar.dataset.slug, email: email.value, usage: usage.checked ? '1' : '0' } ).finally( () => {
				const text = bar.querySelector( '.plugpress-insight-bar__text' );
				text.children[ 0 ].textContent = bar.dataset.done;
				text.children[ 1 ].textContent = email.value ? bar.dataset.thanks : bar.dataset.shared;
				bar.classList.add( 'is-done' );
				fade( 5000 );
			} );
		} );
		bar.querySelector( '.plugpress-insight-bar__close' ).addEventListener( 'click', () => {
			post( { do: 'skip' } );
			fade( 0 );
		} );
	}

	// "Stop sharing site info" under a plugin on the Plugins screen.
	document.querySelectorAll( '[data-plugpress-insight-off]' ).forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			post( { do: 'off' } ).finally( () => {
				document.querySelectorAll( '[data-plugpress-insight-off]' ).forEach( ( other ) => {
					other.replaceWith( other.dataset.plugpressInsightOff );
				} );
			} );
		} );
	} );

	// Quick feedback, from each registered plugin's Deactivate link.
	const dialog = document.querySelector( '.plugpress-insight-dialog' );
	if ( ! dialog ) {
		return;
	}
	const form = dialog.querySelector( 'form' );
	const title = dialog.querySelector( 'h2' );
	const send = form.querySelector( '[type="submit"]' );
	let plugin = null;
	let href = '';
	const leave = () => {
		window.location.href = href;
	};

	Object.keys( config.plugins ).forEach( ( basename ) => {
		const link = document.querySelector( 'tr[data-plugin="' + CSS.escape( basename ) + '"] .deactivate a' );
		if ( link ) {
			link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				plugin = config.plugins[ basename ];
				href = link.href;
				form.reset();
				form.querySelectorAll( '[data-for]' ).forEach( ( box ) => ( box.hidden = true ) );
				send.disabled = true;
				title.textContent = title.dataset.title.replace( '%s', plugin[ 1 ] );
				dialog.showModal();
			} );
		}
	} );

	// The picked reason opens its own question.
	form.addEventListener( 'change', ( event ) => {
		if ( 'reason' !== event.target.name ) {
			return;
		}
		send.disabled = false;
		form.querySelectorAll( '[data-for]' ).forEach( ( box ) => {
			box.hidden = box.dataset.for !== event.target.value;
			if ( ! box.hidden ) {
				box.focus();
			}
		} );
	} );

	dialog.querySelector( '.plugpress-insight-dialog__close' ).addEventListener( 'click', () => dialog.close() );
	dialog.querySelector( '.plugpress-insight-dialog__skip' ).addEventListener( 'click', leave );
	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		const reason = form.elements.reason.value;
		const box = form.querySelector( '[data-for="' + reason + '"]' );
		send.disabled = true;
		// Deactivate once the answer is sent, or after 3 seconds at most.
		setTimeout( leave, 3000 );
		post( {
			do: 'deactivate',
			slug: plugin[ 0 ],
			reason,
			comment: box ? box.value : '',
			email: form.elements.reply.checked ? form.elements.reply.value : '',
		} ).finally( leave );
	} );
} )();
