/**
 * PlugPress Insight: the news card, "Stop sharing" and quick feedback. The
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

	// The card: Allow shares site info (and the email, if its box is ticked),
	// Subscribe sends the email, No thanks and the × send nothing.
	const bar = document.querySelector( '.plugpress-insight-bar' );
	if ( bar && bar.dataset.slot ) {
		// Into the plugin's own page once it draws the slot; hidden until then,
		// so a page that leaves the slot out (a welcome screen) shows no card.
		const place = () => {
			const slot = document.querySelector( bar.dataset.slot );
			if ( slot ) {
				slot.prepend( bar );
				bar.classList.add( 'is-placed' );
			}
			return !! slot;
		};
		if ( ! place() ) {
			const watch = new MutationObserver( () => place() && watch.disconnect() );
			watch.observe( document.body, { childList: true, subtree: true } );
		}
	}
	if ( bar ) {
		const form = bar.querySelector( 'form' );
		const email = form.elements.email;
		const news = form.elements.news; // Only on the site-info card.
		const send = form.querySelector( '[type="submit"]' );
		const product = bar.dataset.product;
		const wanted = () => ! news || news.checked;
		const ready = () => {
			send.disabled = wanted() && ! ( email.value && email.checkValidity() );
		};
		const fade = ( wait ) => {
			setTimeout( () => bar.classList.add( 'is-gone' ), wait );
			setTimeout( () => bar.remove(), wait + 400 );
		};
		email.addEventListener( 'input', () => {
			if ( news ) {
				news.checked = !! email.value;
			}
			ready();
		} );
		if ( news ) {
			news.addEventListener( 'change', ready );
		}
		ready();

		const what = bar.querySelector( '.plugpress-insight-bar__what' );
		if ( what ) {
			what.addEventListener( 'click', () => {
				const list = bar.querySelector( '.plugpress-insight-bar__list' );
				list.hidden = ! list.hidden;
				what.setAttribute( 'aria-expanded', String( ! list.hidden ) );
			} );
		}

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			send.disabled = true;
			const address = wanted() ? email.value : '';
			post( { do: news ? 'allow' : 'subscribe', product, email: address } ).finally( () => {
				const text = bar.querySelector( '.plugpress-insight-bar__text' );
				text.replaceChildren( document.createElement( 'strong' ), document.createElement( 'span' ) );
				text.children[ 0 ].textContent = bar.dataset.done;
				text.children[ 1 ].textContent = address ? bar.dataset.confirm : bar.dataset.stop;
				bar.classList.add( 'is-done' );
				fade( 5000 );
			} );
		} );
		bar.querySelector( '.plugpress-insight-bar__skip' ).addEventListener( 'click', () => {
			post( { do: 'skip', product } );
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
