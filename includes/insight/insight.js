/**
 * PlugPress Insight: the news card, "Stop sharing" and quick feedback. The
 * markup comes from PHP; this only answers clicks. Every click posts to the
 * site's own AJAX endpoint, and the site's server does the sending.
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

		// What is shared: a popover under its link, so the card stays one row.
		// A click outside, Escape or a scroll closes it.
		const what = bar.querySelector( '.plugpress-insight-bar__what' );
		const list = bar.querySelector( '.plugpress-insight-bar__list' );
		if ( what && list && 'function' === typeof list.showPopover ) {
			list.hidden = false;
			list.addEventListener( 'beforetoggle', ( event ) => {
				if ( 'open' === event.newState ) {
					const at = what.getBoundingClientRect();
					const width = Math.min( 360, window.innerWidth - 32 );
					list.style.top = at.bottom + 8 + 'px';
					list.style.left = Math.max( 16, Math.min( at.left, window.innerWidth - width - 16 ) ) + 'px';
				}
			} );
			window.addEventListener( 'scroll', () => list.matches( ':popover-open' ) && list.hidePopover(), { passive: true } );
		} else if ( what && list ) {
			// No popovers in this browser: the list opens in place.
			list.removeAttribute( 'popover' );
			what.setAttribute( 'aria-expanded', 'false' );
			what.addEventListener( 'click', () => {
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

	// Feedback, from each registered plugin's Deactivate link.
	const dialog = document.querySelector( '.plugpress-insight-dialog' );
	if ( ! dialog ) {
		return;
	}
	const form = dialog.querySelector( 'form' );
	const title = dialog.querySelector( 'h2' );
	const send = form.querySelector( '[type="submit"]' );
	const more = form.querySelector( '.plugpress-insight-dialog__more' );
	const boxes = form.querySelectorAll( '[data-for]' );
	let plugin = null;
	let href = '';
	let byKey = false;
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
				boxes.forEach( ( box ) => ( box.hidden = true ) );
				more.hidden = true;
				send.disabled = true;
				title.textContent = title.dataset.title.replace( '%s', plugin[ 1 ] );
				dialog.showModal();
			} );
		}
	} );

	// Arrow keys pick as they move, so a pick by key keeps focus in the
	// reasons and Tab goes on to the question. A tap focuses the question.
	form.addEventListener( 'keydown', () => ( byKey = true ) );
	form.addEventListener( 'pointerdown', () => ( byKey = false ) );

	// The picked reason shows its own question, and only that one, with the
	// reply box.
	form.addEventListener( 'change', ( event ) => {
		if ( 'reason' !== event.target.name ) {
			return;
		}
		send.disabled = false;
		let shown = null;
		boxes.forEach( ( box ) => {
			box.hidden = box.dataset.for !== event.target.value;
			shown = box.hidden ? shown : box;
		} );
		more.hidden = ! shown;
		if ( shown && ! byKey ) {
			shown.querySelector( 'input' ).focus();
		}
	} );

	dialog.querySelector( '.plugpress-insight-dialog__close' ).addEventListener( 'click', () => dialog.close() );
	dialog.querySelector( '.plugpress-insight-dialog__skip' ).addEventListener( 'click', leave );
	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		const reason = form.elements.reason.value;
		const box = form.querySelector( '[data-for="' + reason + '"] input' );
		send.disabled = true;
		// Deactivate once the answer is sent, or after 3 seconds at most.
		setTimeout( leave, 3000 );
		post( {
			do: 'deactivate',
			slug: plugin[ 0 ],
			reason,
			comment: box ? box.value : '',
			// The reply box shows only with a question.
			email: ! more.hidden && form.elements.reply.checked ? form.elements.reply.value : '',
		} ).finally( leave );
	} );
} )();
