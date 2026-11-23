/**
 * Takedown Desk forms, no dependencies. The request form posts JSON to REST and
 * swaps to the confirmation in place; without JavaScript it posts to admin-post.php.
 */
( function () {
	'use strict';

	var cfg = window.tkdeskForm || {};
	var t = cfg.i18n || {};
	// Bot check: a proof of work fetched on first use (never cached in the page) and solved with WebCrypto.
	var canPow = !! ( cfg.pow && cfg.rest && window.fetch && window.crypto && window.crypto.subtle && window.TextEncoder );
	var proof = null;
	// The server files a form sent under 3 s after it was made as spam (autofill is that fast), so wait that out.
	var loaded = Date.now();
	// Google reCAPTCHA, when the site owner chose it instead of the built-in check.
	var cap = cfg.captcha && cfg.captcha.key ? cfg.captcha : null;

	// Google's script calls this once it has loaded: v2 shows its checkbox in every form.
	window.tkdeskCaptchaLoad = function () {
		if ( cap && 'v2' === cap.v ) {
			document.querySelectorAll( '.tkd-form [data-tkd-captcha="v2"] .tkd-captcha__widget' ).forEach( function ( box ) {
				box.closest( 'form' ).tkdCaptcha = window.grecaptcha.render( box, { sitekey: cap.key } );
			} );
		}
	};

	// A token for this form, or a rejection with the message to show. v3 asks Google for a fresh one each time.
	function captcha( form ) {
		var g = window.grecaptcha;
		if ( ! g || ( 'v2' === cap.v && undefined === form.tkdCaptcha ) ) {
			return Promise.reject( t.captchaLoad );
		}
		if ( 'v2' === cap.v ) {
			return g.getResponse( form.tkdCaptcha ) ? Promise.resolve( g.getResponse( form.tkdCaptcha ) ) : Promise.reject( t.captcha );
		}
		return new Promise( function ( ok, fail ) {
			g.ready( function () {
				g.execute( cap.key, { action: form.classList.contains( 'tkd-review' ) ? 'tkdesk_review' : 'tkdesk_request' } ).then( ok, function () {
					fail( t.captchaLoad );
				} );
			} );
		} );
	}

	// A v2 token counts once, so the box is cleared after every try.
	function captchaReset( form ) {
		if ( cap && 'v2' === cap.v && window.grecaptcha && undefined !== form.tkdCaptcha ) {
			window.grecaptcha.reset( form.tkdCaptcha );
		}
	}

	function busy( form, on, text ) {
		var btn = form.querySelector( '.tkd-button' );
		var live = form.querySelector( '.tkd-live' );
		form.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		if ( live ) {
			live.textContent = on && text ? text : '';
		}
		if ( ! btn ) {
			return;
		}
		if ( on ) {
			if ( ! btn.hasAttribute( 'data-label' ) ) {
				btn.setAttribute( 'data-label', btn.textContent );
			}
			btn.textContent = text || btn.textContent;
		} else if ( btn.hasAttribute( 'data-label' ) ) {
			btn.textContent = btn.getAttribute( 'data-label' );
			btn.removeAttribute( 'data-label' );
		}
		btn.disabled = on;
	}

	// Find n with sha256( salt + n ) = challenge, 1000 digests at a time. Only globals that workers have too.
	// ponytail: matches on the first 32 bits; a rare false match is refused by the server and solved again.
	function find( c ) {
		var enc = new TextEncoder();
		var word = parseInt( c.challenge.slice( 0, 8 ), 16 );
		function batch( from ) {
			var list = [];
			for ( var n = from; n < from + 1000 && n <= c.maxnumber; n++ ) {
				list.push( crypto.subtle.digest( 'SHA-256', enc.encode( c.salt + n ) ) );
			}
			if ( ! list.length ) {
				return -1;
			}
			return Promise.all( list ).then( function ( out ) {
				for ( var i = 0; i < out.length; i++ ) {
					if ( new DataView( out[ i ] ).getUint32( 0 ) === word ) {
						return from + i;
					}
				}
				return batch( from + 1000 );
			} );
		}
		return Promise.resolve( batch( 0 ) );
	}

	// Solve in a Web Worker made from find() so typing never stutters; on the page if workers are blocked.
	function work( c ) {
		return new Promise( function ( ok, fail ) {
			var w = new window.Worker( URL.createObjectURL( new Blob( [ 'onmessage=function(e){(' + find + ')(e.data).then(postMessage,function(){postMessage(-1)})}' ], { type: 'text/javascript' } ) ) );
			w.onmessage = function ( e ) {
				w.terminate();
				ok( e.data );
			};
			w.onerror = function () {
				w.terminate();
				fail();
			};
			w.postMessage( c );
		} ).catch( function () {
			return find( c );
		} );
	}

	function solve() {
		var p = window.fetch( cfg.rest + 'challenge', { cache: 'no-store' } ).then( function ( res ) {
			return res.json();
		} ).then( function ( c ) {
			return work( c ).then( function ( n ) {
				if ( n < 0 ) {
					throw new Error( 'unsolved' );
				}
				c.number = n;
				return JSON.stringify( c );
			} );
		} );
		p.catch( function () {
			proof = proof === p ? null : proof;
		} );
		proof = p;
		return p;
	}

	function reset( form ) {
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( input ) {
			var err = document.getElementById( input.id + '-error' );
			var ids = ( input.getAttribute( 'aria-describedby' ) || '' ).replace( input.id + '-error', '' ).trim();
			input.removeAttribute( 'aria-invalid' );
			if ( ids ) {
				input.setAttribute( 'aria-describedby', ids );
			} else {
				input.removeAttribute( 'aria-describedby' );
			}
			if ( err ) {
				err.hidden = true;
			}
		} );
		form.querySelectorAll( '.tkd-summary, .tkd-notice' ).forEach( function ( box ) {
			box.hidden = true;
		} );
	}

	function showErrors( form, fields ) {
		var summary = form.querySelector( '.tkd-summary' );
		var list = summary.querySelector( 'ul' );
		list.textContent = '';
		Object.keys( fields ).forEach( function ( name ) {
			var text = String( fields[ name ] );
			var input = form.elements[ name ];
			var err = input && input.id ? document.getElementById( input.id + '-error' ) : null;
			var li = document.createElement( 'li' );
			var link = document.createElement( 'a' );
			link.href = '#tkd-' + name;
			link.textContent = text;
			li.appendChild( link );
			list.appendChild( li );
			if ( err ) {
				err.lastChild.textContent = text;
				err.hidden = false;
				if ( input.closest( 'details' ) ) {
					input.closest( 'details' ).open = true;
				}
				input.setAttribute( 'aria-invalid', 'true' );
				input.setAttribute( 'aria-describedby', ( ( input.getAttribute( 'aria-describedby' ) || '' ) + ' ' + err.id ).trim() );
			}
		} );
		summary.hidden = false;
		summary.focus();
	}

	function showNotice( form, text ) {
		var box = form.querySelector( '.tkd-notice' );
		box.lastChild.textContent = text;
		box.hidden = false;
		box.focus();
	}

	// Empty required fields and a malformed email are caught before sending, with the server's own messages.
	function check( form ) {
		var fields = {};
		form.querySelectorAll( '[required]' ).forEach( function ( el ) {
			var v = el.validity;
			if ( t.fields && t.fields[ el.name ] && ( v.valueMissing || ( 'email' === el.type && v.typeMismatch ) ) ) {
				fields[ el.name ] = v.valueMissing ? t.fields[ el.name ] : t.email || t.fields[ el.name ];
			}
		} );
		return fields;
	}

	// Confirmation from the server-rendered template. Response data only goes into textContent or an
	// http(s) href; a part whose value is empty (no email sent, no status link) is left out.
	function showDone( form, data ) {
		var tpl = form.parentNode.querySelector( '.tkd-done-tpl' );
		var box = tpl.content.firstElementChild.cloneNode( true );
		box.querySelectorAll( '[data-tkd-fill], [data-tkd-href]' ).forEach( function ( node ) {
			var href = node.getAttribute( 'data-tkd-href' );
			var value = String( data[ href || node.getAttribute( 'data-tkd-fill' ) ] || '' );
			if ( ! value || ( href && ! /^https?:\/\//.test( value ) ) ) {
				( node.closest( '[data-tkd-need]' ) || node ).remove();
			} else if ( href ) {
				node.href = value;
			} else {
				node.textContent = value;
			}
		} );
		form.parentNode.replaceChild( box, form );
		box.querySelector( '.tkd-title' ).focus( { preventScroll: true } );
		box.scrollIntoView( { block: 'start' } );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var data = {};
		var headers = { 'Content-Type': 'application/json' };

		if ( ! form.classList || ! form.classList.contains( 'tkd-form' ) ) {
			return;
		}
		if ( 'true' === form.getAttribute( 'aria-busy' ) ) {
			event.preventDefault();
			return;
		}
		busy( form, true, t.sending );
		if ( ! form.hasAttribute( 'data-tkd-rest' ) || ! cfg.rest || ! window.fetch ) {
			// A plain post (the review form): get the reCAPTCHA token first, then send the form as it is.
			if ( cap && ! form.tkdCaptchaDone ) {
				event.preventDefault();
				captcha( form ).then(
					function ( token ) {
						var field = form.querySelector( 'input[name="g-recaptcha-response"]' );
						if ( field ) {
							field.value = token; // v3; v2 keeps its own field in the widget.
						}
						form.tkdCaptchaDone = true;
						form.submit(); // Skips the submit event, so this runs once.
					},
					function ( msg ) {
						busy( form, false );
						showNotice( form, msg );
					}
				);
			}
			return;
		}
		event.preventDefault();
		reset( form );
		data = check( form );
		if ( Object.keys( data ).length ) {
			busy( form, false );
			showErrors( form, data );
			return;
		}

		new FormData( form ).forEach( function ( value, key ) {
			data[ key ] = value;
		} );
		delete data.action;
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		send( form, data, headers, true );
	} );

	// Waits for the proof (still solving: "Checking..."), posts, and on an expired or rejected proof
	// solves a new one and sends once more before showing any error.
	function send( form, data, headers, retry ) {
		busy( form, true, canPow ? t.checking : t.sending );
		Promise.all( [
			canPow ? proof || solve() : '',
			new Promise( function ( ok ) {
				setTimeout( ok, loaded + 3500 - Date.now() );
			} ),
			cap ? captcha( form ) : '',
		] )
			.then( function ( got ) {
				if ( got[ 0 ] ) {
					data.tkdesk_pow = got[ 0 ];
				}
				if ( got[ 2 ] ) {
					data[ 'g-recaptcha-response' ] = got[ 2 ];
				}
				busy( form, true, t.sending );
				return window.fetch( cfg.rest + 'requests', {
					method: 'POST',
					headers: headers,
					body: JSON.stringify( data ),
				} );
			} )
			.then( function ( res ) {
				return res.json().catch( function () {
					return {};
				} ).then( function ( json ) {
					return { status: res.status, json: json || {} };
				} );
			} )
			.then( function ( r ) {
				var fields = r.json.data && r.json.data.fields;
				if ( r.status >= 200 && r.status < 300 && r.json.public_id ) {
					showDone( form, r.json );
					return;
				}
				captchaReset( form );
				if ( canPow ) {
					solve(); // A proof counts once: have the next one ready.
					if ( retry && 'tkdesk_bot_check' === r.json.code ) {
						send( form, data, headers, false );
						return;
					}
				}
				busy( form, false );
				if ( fields && Object.keys( fields ).length ) {
					showErrors( form, fields );
				} else if ( 429 === r.status ) {
					showNotice( form, t.rate );
				} else {
					showNotice( form, ( 400 === r.status && r.json.message ) || t.server );
				}
			} )
			.catch( function ( err ) {
				proof = null;
				busy( form, false );
				// A reCAPTCHA problem rejects with its own message; anything else is a network or server error.
				showNotice( form, 'string' === typeof err ? err : t.server );
			} );
	}

	if ( canPow ) {
		document.querySelectorAll( '.tkd-form[data-tkd-rest]' ).forEach( function ( form ) {
			var live = document.createElement( 'span' );
			var q = form.querySelector( '.tkd-q' );
			// The proof of work replaces the no-JavaScript question.
			if ( q ) {
				q.remove();
			}
			live.className = 'tkd-sr tkd-live';
			live.setAttribute( 'aria-live', 'polite' );
			form.appendChild( live );
		} );
		document.addEventListener( 'focusin', function ( event ) {
			if ( ! proof && event.target.form && event.target.form.hasAttribute( 'data-tkd-rest' ) ) {
				solve();
			}
		} );
	}

	// "Use this sentence": fills the statement once and leaves it editable.
	document.querySelectorAll( '.tkd-chip[data-tkd-text]' ).forEach( function ( chip ) {
		chip.hidden = false;
		chip.addEventListener( 'click', function () {
			var box = chip.form.elements.statement;
			var text = chip.getAttribute( 'data-tkd-text' );
			if ( box.value.indexOf( text ) < 0 ) {
				box.value = ( box.value.trim() ? box.value.trim() + ' ' : '' ) + text;
			}
			box.focus();
		} );
	} );

	// Quick exit: moved to the start of the page so the keyboard reaches it first. Leaving clears what was
	// typed, hides the page and replaces it in the history, so Back does not return here. Escape twice does the same.
	var exit = document.querySelector( '[data-tkd-exit]' );
	var esc = -1e4;
	function leave( event ) {
		event.preventDefault();
		document.querySelectorAll( '.tkd-form input:not([type="hidden"]), .tkd-form textarea' ).forEach( function ( field ) {
			if ( 'checkbox' === field.type || 'radio' === field.type ) {
				field.checked = false;
			} else {
				field.value = '';
			}
		} );
		document.body.style.display = 'none';
		window.location.replace( exit.href );
	}
	if ( exit ) {
		document.body.insertBefore( exit, document.body.firstChild );
		exit.addEventListener( 'click', leave );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				if ( event.timeStamp - esc < 1000 ) {
					leave( event );
				}
				esc = event.timeStamp;
			}
		} );
	}

	// Back/forward cache: a restored page must not keep a disabled button.
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.tkd-form[aria-busy="true"]' ).forEach( function ( form ) {
			busy( form, false );
		} );
	} );
}() );
