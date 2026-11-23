/**
 * Takedown Desk admin app: request inbox, "Log a request" dialog, request detail.
 *
 * Plain ES2017, no build step, no framework. Uses only wp.i18n, wp.apiFetch, wp.hooks and wp.a11y.
 * The DOM is built with createElement + textContent; server and requester text never touches innerHTML.
 *
 * Extension points (wp.hooks filters):
 * - tkdesk.tabs            ( [ { id, label, icon, render( panel, args ) } ] )  Day 3 adds log, settings, setup.
 * - tkdesk.inbox.filters   ( [ { id, label, alert } ], counts )
 * - tkdesk.request.panels  ( Node[], request )  appended to the right column of the detail screen.
 * - tkdesk.request.actions ( [ { id, label, icon, run( request ) } ], request )  the "More actions" menu.
 * - tkdesk.copies.badges   ( [ { text, tone } ], item )
 */
( function ( wp, cfg ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.i18n || ! wp.hooks ) {
		return;
	}

	let root = null; // #tkdesk-app, set in start().
	const { __, _n, sprintf } = wp.i18n;
	const hooks = wp.hooks;
	const NS = '/' + String( cfg.restNamespace || 'takedown-desk/v1' ).replace( /^\/+|\/+$/g, '' ) + '/admin/';
	const WINDOW = 172800; // The 48-hour removal window, in seconds.
	const OPEN = [ 'new', 'in_review', 'needs_info' ];
	const RING = 2 * Math.PI * 16; // Clock ring circumference (r = 16 in a 40 x 40 box).
	const FLIP = [ 'arrow', 'back', 'prev', 'next' ]; // Directional icons mirrored in right-to-left languages.

	/* ---------- Icons (24 px strokes, built as inline SVG) ----------
	 * Icon paths from Lucide, https://lucide.dev, ISC License:
	 * Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of Feather (MIT).
	 * All other copyright (c) for Lucide are held by Lucide Contributors 2022.
	 * Permission to use, copy, modify, and/or distribute this software for any purpose with or without
	 * fee is hereby granted, provided that the above copyright notice and this permission notice appear
	 * in all copies. THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
	 * REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS. IN NO
	 * EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR
	 * ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF
	 * CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR
	 * PERFORMANCE OF THIS SOFTWARE.
	 */
	const CIRCLE = 'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0z';
	const ICONS = {
		shield: [ 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z', 'm9 12 2 2 4-4' ],
		inbox: [ 'M22 12h-6l-2 3h-4l-2-3H2', 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z' ],
		clock: [ CIRCLE, 'M12 6v6l4 2' ],
		hourglass: [ 'M5 22h14', 'M5 2h14', 'M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22', 'M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2' ],
		alert: [ 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3', 'M12 9v4', 'M12 17h.01' ],
		octagon: [ 'M12 16h.01', 'M12 8v4', 'M15.312 2a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586l-4.688-4.688A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2z' ],
		check: [ 'M20 6 9 17l-5-5' ],
		done: [ CIRCLE, 'm9 12 2 2 4-4' ],
		x: [ 'M18 6 6 18', 'm6 6 12 12' ],
		plus: [ 'M5 12h14', 'M12 5v14' ],
		search: [ 'm21 21-4.34-4.34', 'M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0z' ],
		info: [ CIRCLE, 'M12 16v-4', 'M12 8h.01' ],
		arrow: [ 'M5 12h14', 'm12 5 7 7-7 7' ],
		back: [ 'm12 19-7-7 7-7', 'M19 12H5' ],
		ext: [ 'M15 3h6v6', 'M10 14 21 3', 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6' ],
		edit: [ 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z' ],
		eye: [ 'M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0', 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z' ],
		copy: [ 'M10 8h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2z', 'M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2' ],
		more: [ 'M13 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0z', 'M20 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0z', 'M6 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0z' ],
		undo: [ 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'M3 3v5h5' ],
		ask: [ 'M7.9 20A9 9 0 1 0 4 16.1L2 22z', 'M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3', 'M12 17h.01' ],
		ban: [ CIRCLE, 'm4.9 4.9 14.2 14.2' ],
		file: [ 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z', 'M14 2v4a2 2 0 0 0 2 2h4', 'M16 13H8', 'M16 17H8', 'M10 9H8' ],
		layers: [ 'M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z', 'M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12', 'M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17' ],
		link: [ 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71', 'M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71' ],
		refresh: [ 'M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8', 'M21 3v5h-5', 'M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16', 'M8 16H3v5' ],
		flag: [ 'M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 7.333 2q2 0 3.067-.8A1 1 0 0 1 20 4v10a1 1 0 0 1-.4.8A6 6 0 0 1 16 16c-3 0-5-2-8-2a6 6 0 0 0-4 1.528' ],
		note: [ 'M15.5 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V8.5z', 'M15 3v6h6' ],
		prev: [ 'm15 18-6-6 6-6' ],
		next: [ 'm9 18 6-6-6-6' ],
		up: [ 'm18 15-6-6-6 6' ],
		down: [ 'm6 9 6 6 6-6' ],
		sort: [ 'm7 15 5 5 5-5', 'm7 9 5-5 5 5' ],
		scale: [ 'm16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z', 'm2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1z', 'M7 21h10', 'M12 3v18', 'M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2' ],
		cloud: [ 'M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z' ],
		send: [ 'M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z', 'm21.854 2.147-10.94 10.939' ],
		trash: [ 'M3 6h18', 'M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6', 'M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2' ],
		list: [ 'M3 12h.01', 'M3 18h.01', 'M3 6h.01', 'M8 12h13', 'M8 18h13', 'M8 6h13' ],
		gear: [ 'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z', 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z' ],
		download: [ 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm7 10 5 5 5-5', 'M12 15V3' ],
		image: [ 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z', 'M11 9a2 2 0 1 1-4 0 2 2 0 0 1 4 0z', 'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21' ],
		users: [ 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75' ],
		copyright: [ CIRCLE, 'M14.83 14.83a4 4 0 1 1 0-5.66' ],
	};

	/* ---------- DOM helpers ---------- */
	let nextId = 0;
	const uid = () => 'tkd-u' + ++nextId;

	function add( node, kids ) {
		kids.forEach( ( kid ) => {
			if ( Array.isArray( kid ) ) {
				add( node, kid );
			} else if ( kid || 0 === kid ) {
				node.append( kid ); // Strings become text nodes.
			}
		} );
		return node;
	}

	function el( tag, props, ...kids ) {
		const node = document.createElement( tag );
		let value;
		Object.keys( props || {} ).forEach( ( key ) => {
			const v = props[ key ];
			if ( null === v || undefined === v || false === v ) {
				return;
			}
			if ( 'class' === key ) {
				node.className = v;
			} else if ( 'text' === key ) {
				node.textContent = v;
			} else if ( 'value' === key ) {
				value = v;
			} else if ( 'function' === typeof v ) {
				node.addEventListener( key.slice( 2 ), v );
			} else {
				node.setAttribute( key, true === v ? '' : v );
			}
		} );
		add( node, kids );
		if ( undefined !== value ) {
			node.value = value; // After the children, so a select can pick one of its options.
		}
		return node;
	}

	function svg( tag, attrs ) {
		const node = document.createElementNS( 'http://www.w3.org/2000/svg', tag );
		Object.keys( attrs ).forEach( ( key ) => node.setAttribute( key, attrs[ key ] ) );
		return node;
	}

	function icon( name ) {
		const node = svg( 'svg', { class: 'tkd-ico' + ( FLIP.includes( name ) ? ' tkd-ico--flip' : '' ), viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' } );
		( ICONS[ name ] || [] ).forEach( ( d ) => node.append( svg( 'path', { d } ) ) );
		return node;
	}

	// The Takedown Desk mark (small-size cut, 256 grid): yellow, green, orange, blue.
	const MARK = [
		[ '#F2C23A', 'M60 28H117V88H60A30 30 0 0 1 60 28Z' ],
		[ '#2E9E4C', 'M139 28H196A30 30 0 0 1 196 88H139Z' ],
		[ '#E8672B', 'M82 110H117V223L40 146H82Z' ],
		[ '#4F5BFF', 'M139 110H174V146H216L139 223Z' ],
	];
	function brandMark() {
		const node = svg( 'svg', { class: 'tkd-brand__svg', viewBox: '0 0 256 256', 'aria-hidden': 'true', focusable: 'false' } );
		MARK.forEach( ( m ) => node.append( svg( 'path', { fill: m[ 0 ], d: m[ 1 ] } ) ) );
		return node;
	}

	const speak = ( msg, politeness ) => wp.a11y && wp.a11y.speak( msg, politeness || 'polite' );
	const api = ( path, method, data ) => wp.apiFetch( { path: NS + path, method: method || 'GET', data } );
	const isOpen = ( r ) => OPEN.includes( r.status );
	// Copyright (DMCA) notices: their clock is a target the owner sets in business days, not the Act's 48 hours.
	const isDmca = ( r ) => 'dmca' === ( r && r.type );
	const windowOf = ( r ) => ( isDmca( r ) && Math.max( 0, r.due_at - r.received_at ) ) || WINDOW;
	// Browsers drop control characters and spaces around a scheme and tabs or newlines inside it ("java\tscript:").
	const safe = ( url ) => ( ! url || /^(javascript|data|vbscript):/i.test( String( url ).replace( /[\x00-\x20]+/g, '' ) ) ? '#' : url );
	const muted = ( text ) => el( 'span', { class: 'tkd-muted', text } );
	const errMsg = ( e ) => ( e && e.message ) || __( 'Something went wrong. Try again.', 'takedown-desk' );

	// Read (and with `value`, first write) a JSON object in localStorage or sessionStorage. Storage can be off or full.
	function remember( storage, key, value ) {
		try {
			if ( undefined !== value ) {
				window[ storage ].setItem( key, JSON.stringify( value ) );
			}
			return JSON.parse( window[ storage ].getItem( key ) ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function btn( label, o ) {
		o = o || {};
		const ic = o.icon ? icon( o.icon ) : null;
		return el(
			'button',
			{
				type: o.type || 'button',
				id: o.id,
				class: 'tkd-btn tkd-btn--' + ( o.kind || 'secondary' ),
				disabled: o.disabled,
				'data-autofocus': o.autofocus,
				onclick: o.onclick,
			},
			o.end ? null : ic,
			el( 'span', { class: 'tkd-btn__label', text: label } ),
			o.end ? ic : null
		);
	}

	function busy( button, on ) {
		if ( button ) {
			button.disabled = on;
			button.classList.toggle( 'is-busy', on );
		}
	}

	function linkChip( label, href, external, ic ) {
		return el(
			'a',
			{ class: 'tkd-linkchip', href: safe( href ), target: external ? '_blank' : null, rel: external ? 'noopener noreferrer' : null },
			ic ? icon( ic ) : null,
			el( 'span', { text: label } ),
			external ? el( 'span', { class: 'tkd-sr', text: __( '(opens in a new tab)', 'takedown-desk' ) } ) : null,
			ic ? null : icon( external ? 'ext' : 'arrow' )
		);
	}

	function cardHead( ic, title, tone, id, extra ) {
		return el(
			'div',
			{ class: 'tkd-card__head' },
			el( 'span', { class: 'tkd-chip-ico tkd-chip-ico--' + ( tone || 'blue' ) }, icon( ic ) ),
			el( 'h2', { class: 'tkd-card__title', id, tabindex: '-1', text: title } ),
			extra ? el( 'div', { class: 'tkd-card__extra' }, extra ) : null
		);
	}

	function art( ic ) {
		return el(
			'div',
			{ class: 'tkd-art', 'aria-hidden': 'true' },
			el( 'span', { class: 'tkd-art__halo' } ),
			el( 'span', { class: 'tkd-art__tile' }, icon( ic ) ),
			el( 'span', { class: 'tkd-art__badge' }, icon( 'clock' ) )
		);
	}

	function errorCard( msg, retry ) {
		return el(
			'div',
			{ class: 'tkd-card tkd-alert tkd-alert--error', role: 'alert' },
			el( 'span', { class: 'tkd-alert__ico' }, icon( 'octagon' ) ),
			el( 'p', { class: 'tkd-alert__msg', text: msg } ),
			btn( __( 'Try again', 'takedown-desk' ), { icon: 'refresh', onclick: retry } )
		);
	}

	const view = ( enter, ...kids ) => el( 'div', { class: 'tkd-view' + ( enter ? ' is-enter' : '' ) }, kids );
	const para = ( text ) => el( 'p', { class: 'tkd-pre', text } );

	// Info tip: a disclosure button that shows a short plain-words note; Escape closes it.
	function infoTip( label, text ) {
		const id = uid();
		const tip = el( 'span', { id, class: 'tkd-tip', hidden: true, text } );
		const toggle = ( on ) => {
			tip.hidden = ! on;
			b.setAttribute( 'aria-expanded', String( on ) );
		};
		const b = el( 'button', { type: 'button', class: 'tkd-iconbtn tkd-tipbtn', 'aria-label': label, 'aria-expanded': 'false', 'aria-controls': id, onclick: () => toggle( tip.hidden ) }, icon( 'info' ) );
		return el(
			'span',
			{
				class: 'tkd-tipwrap',
				onkeydown: ( e ) => {
					if ( 'Escape' === e.key && ! tip.hidden ) {
						toggle( false );
						b.focus();
					}
				},
			},
			b,
			tip
		);
	}

	function copyText( input ) {
		const manual = () => {
			input.focus();
			input.select();
			toast( __( 'Selected. Copy it with your keyboard.', 'takedown-desk' ), 'info' );
		};
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( input.value ).then( () => toast( __( 'Copied.', 'takedown-desk' ) ), manual );
		} else {
			manual();
		}
	}

	function copyBox( label, value ) {
		const id = uid();
		const input = el( 'input', { id, class: 'tkd-input tkd-mono', readonly: true, value } );
		return el(
			'div',
			{ class: 'tkd-field' },
			el( 'label', { for: id, class: 'tkd-label', text: label } ),
			el( 'div', { class: 'tkd-copybox' }, input, btn( __( 'Copy', 'takedown-desk' ), { icon: 'copy', onclick: () => copyText( input ) } ) )
		);
	}

	function pager( page, total, perPage, go ) {
		const pages = Math.ceil( total / perPage );
		if ( pages < 2 ) {
			return null;
		}
		return el(
			'nav',
			{ class: 'tkd-pager', 'aria-label': __( 'Pages', 'takedown-desk' ) },
			btn( __( 'Previous', 'takedown-desk' ), { icon: 'prev', disabled: page <= 1, onclick: () => go( page - 1 ) } ),
			/* translators: 1: current page, 2: number of pages. */
			el( 'span', { class: 'tkd-pager__text', text: sprintf( __( 'Page %1$d of %2$d', 'takedown-desk' ), page, pages ) } ),
			btn( __( 'Next', 'takedown-desk' ), { icon: 'next', end: true, disabled: page >= pages, onclick: () => go( page + 1 ) } )
		);
	}

	// Keep the wp-admin menu bubble and the admin-bar item in step with the list counts.
	function syncCounts( counts ) {
		if ( ! counts ) {
			api( 'requests?status=open&per_page=1' ).then( ( res ) => syncCounts( res.counts || {} ), () => {} );
			return;
		}
		const open = counts.open || 0;
		const menu = document.querySelector( '#toplevel_page_takedown-desk .wp-menu-name' );
		let bubble = menu && menu.querySelector( '.awaiting-mod' );
		if ( menu && ! open && bubble ) {
			bubble.remove();
		} else if ( menu && open ) {
			if ( ! bubble ) {
				bubble = el( 'span', {}, el( 'span', { class: 'pending-count' } ) );
				menu.append( ' ', bubble );
			}
			bubble.className = 'awaiting-mod count-' + open;
			bubble.firstChild.textContent = String( open );
		}
		const node = document.getElementById( 'wp-admin-bar-tkdesk' );
		const rootBar = document.getElementById( 'wp-admin-bar-root-default' );
		if ( ! open ) {
			if ( node ) {
				node.remove();
			}
			return;
		}
		/* translators: %d: number of open requests. */
		const label = el( 'span', { text: sprintf( __( 'Takedown: %d open', 'takedown-desk' ), open ) } );
		if ( counts.overdue ) {
			Object.assign( label.style, { background: '#d63638', color: '#fff', borderRadius: '2px', padding: '2px 6px' } );
		}
		const link = el( 'a', { class: 'ab-item', href: 'admin.php?page=takedown-desk' }, label );
		if ( node ) {
			node.replaceChildren( link );
		} else if ( rootBar ) {
			rootBar.append( el( 'li', { id: 'wp-admin-bar-tkdesk' }, link ) );
		}
	}

	/* ---------- Toasts (top right; errors stay until dismissed) ---------- */
	const toasts = el( 'div', { class: 'tkd-toasts' } );

	// undo: optional function; the toast then offers Undo for 8 seconds.
	function toast( msg, type, undo ) {
		const error = 'error' === type;
		const node = el( 'div', { class: 'tkd-toast tkd-toast--' + ( type || 'success' ), role: error ? 'alert' : 'status' } );
		const close = () => {
			node.classList.add( 'is-leaving' );
			setTimeout( () => node.remove(), 180 );
		};
		toasts.append( node );
		// Content goes in after the live region is in the page, so screen readers announce it.
		requestAnimationFrame( () =>
			add( node, [
				el( 'span', { class: 'tkd-toast__ico' }, icon( error ? 'octagon' : 'done' ) ),
				el( 'p', { class: 'tkd-toast__msg', text: msg } ),
				undo
					? el( 'button', { type: 'button', class: 'tkd-toast__undo', text: __( 'Undo', 'takedown-desk' ), onclick: () => {
						close();
						undo();
					} } )
					: null,
				el( 'button', { type: 'button', class: 'tkd-iconbtn', 'aria-label': __( 'Dismiss notification', 'takedown-desk' ), onclick: close }, icon( 'x' ) ),
			] )
		);
		if ( ! error ) {
			setTimeout( close, undo ? 8000 : 6000 );
		}
	}

	/* ---------- Time ---------- */
	let offset = cfg.now ? cfg.now - Date.now() / 1000 : 0; // Server clock minus browser clock, in seconds.
	const now = () => Date.now() / 1000 + offset;

	function sync( r ) {
		if ( r && isOpen( r ) && r.due_at && r.clock ) {
			offset = r.due_at - r.clock.remaining - Date.now() / 1000;
		}
	}

	const pad = ( n ) => String( n ).padStart( 2, '0' );

	// "45 hours 4 minutes": the clock in words, for screen readers.
	function words( s ) {
		const m = Math.floor( Math.abs( s ) / 60 );
		const h = Math.floor( m / 60 );
		/* translators: %d: number of hours. */
		const hours = h ? sprintf( _n( '%d hour', '%d hours', h, 'takedown-desk' ), h ) + ' ' : '';
		/* translators: %d: number of minutes. */
		return hours + sprintf( _n( '%d minute', '%d minutes', m % 60, 'takedown-desk' ), m % 60 );
	}

	// "45 h 4 min left" or "2 h 55 min late", whole minutes (the clocks change once a minute).
	function timeLeft( rem ) {
		const t = duration( Math.floor( Math.abs( rem ) / 60 ) * 60 );
		return rem < 0
			? /* translators: %s: time past the deadline, e.g. 2 h 55 min. */ sprintf( __( '%s late', 'takedown-desk' ), t )
			: /* translators: %s: time left, e.g. 45 h 4 min. */ sprintf( __( '%s left', 'takedown-desk' ), t );
	}

	function duration( s ) {
		const m = Math.max( 0, Math.round( s / 60 ) );
		return m >= 60
			? /* translators: 1: hours, 2: minutes. */ sprintf( __( '%1$d h %2$d min', 'takedown-desk' ), Math.floor( m / 60 ), m % 60 )
			: /* translators: %d: minutes. */ sprintf( __( '%d min', 'takedown-desk' ), m );
	}

	const dateFormats = {};

	// Site timezone; `withYear` for the record of actions.
	function fmtDate( t, withYear ) {
		const ms = 'number' === typeof t || /^\d+$/.test( t ) ? t * 1000 : Date.parse( String( t ).replace( ' ', 'T' ) + 'Z' ); // DB datetimes are GMT.
		if ( ! t || isNaN( ms ) ) {
			return '';
		}
		const k = withYear ? 'y' : 'n';
		if ( ! dateFormats[ k ] ) {
			const o = { year: withYear ? 'numeric' : undefined, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
			try {
				dateFormats[ k ] = new Intl.DateTimeFormat( document.documentElement.lang || undefined, Object.assign( { timeZone: cfg.tz || undefined }, o ) );
			} catch ( e ) {
				dateFormats[ k ] = new Intl.DateTimeFormat( undefined, o ); // Offset-only site timezones on older browsers.
			}
		}
		return dateFormats[ k ].format( new Date( ms ) );
	}

	function where( url ) {
		try {
			const u = new URL( url, window.location.href );
			return u.pathname + u.search + u.hash;
		} catch ( e ) {
			return url;
		}
	}

	/* ---------- Clock: ring + "45 h 4 min left" + state label; one ticker, once a minute, for every clock on screen ---------- */
	const STATES = {
		ok: [ 'clock', __( 'On track', 'takedown-desk' ) ],
		warn: [ 'hourglass', __( 'Under 24 h left', 'takedown-desk' ) ],
		urgent: [ 'alert', __( 'Under 8 h left', 'takedown-desk' ) ],
		overdue: [ 'octagon', __( 'Overdue', 'takedown-desk' ) ],
	};
	let clocks = [];
	// Warn at half the window and urgent at the last sixth: 24 h and 8 h of 48, the reminder points of every type.
	const stateOf = ( rem, win ) => {
		if ( rem < 0 ) {
			return 'overdue';
		}
		if ( rem <= win / 6 ) {
			return 'urgent';
		}
		return rem <= win / 2 ? 'warn' : 'ok';
	};
	const fill = ( bar, frac ) => {
		bar.style.strokeDashoffset = ( RING * ( 1 - frac ) ).toFixed( 2 );
	};

	// `quiet`: a second clock for the same request (the Decide bar) that does not announce state changes again.
	function clock( r, big, quiet ) {
		if ( 'spam' === r.status ) {
			return muted( __( 'No deadline', 'takedown-desk' ) );
		}
		const bar = svg( 'circle', { class: 'tkd-ring__bar', cx: 20, cy: 20, r: 16, 'stroke-dasharray': RING.toFixed( 2 ) } );
		const ring = svg( 'svg', { class: 'tkd-ring', viewBox: '0 0 40 40', 'aria-hidden': 'true', focusable: 'false' } );
		ring.append( svg( 'circle', { class: 'tkd-ring__track', cx: 20, cy: 20, r: 16 } ), bar );
		const cls = 'tkd-clock' + ( big ? ' tkd-clock--lg' : '' );
		const label = el( 'span', { class: 'tkd-clock__label' } );
		if ( ! isOpen( r ) ) {
			const removed = 'removed' === r.status;
			fill( bar, 1 );
			add( label, [ icon( removed ? 'done' : 'ban' ), el( 'span', { text: ( r.clock && r.clock.closed_text ) || r.status_label || '' } ) ] );
			return el( 'div', { class: cls, 'data-state': removed ? 'done' : 'closed' }, ring, el( 'div', { class: 'tkd-clock__text' }, label ) );
		}
		const time = el( 'span', { class: 'tkd-clock__time', 'aria-hidden': 'true' } );
		const sr = el( 'span', { class: 'tkd-sr' } );
		const node = el( 'div', { class: cls }, ring, el( 'div', { class: 'tkd-clock__text' }, time, sr, label ) );
		const c = { r, node, time, sr, label, bar, state: '', quiet };
		clocks.push( c );
		tick( c );
		return node;
	}

	function tick( c ) {
		const rem = c.r.due_at ? c.r.due_at - now() : ( c.r.clock && c.r.clock.remaining ) || 0;
		const dm = isDmca( c.r );
		const win = windowOf( c.r );
		const state = stateOf( rem, win );
		const t = words( rem );
		let past = /* translators: %s: time in words, e.g. 2 hours 55 minutes. */ sprintf( __( '%s past the deadline.', 'takedown-desk' ), t );
		if ( dm ) {
			past = /* translators: %s: time in words, e.g. 2 hours 55 minutes. */ sprintf( __( '%s past the target.', 'takedown-desk' ), t );
		}
		c.time.textContent = timeLeft( rem );
		c.sr.textContent = 'overdue' === state ? past : /* translators: %s: time in words, e.g. 45 hours 4 minutes. */ sprintf( __( '%s left.', 'takedown-desk' ), t );
		fill( c.bar, 'overdue' === state ? 1 : Math.min( 1, rem / win ) );
		if ( state === c.state ) {
			return;
		}
		const first = ! c.state;
		c.state = state;
		c.node.setAttribute( 'data-state', state );
		let text = STATES[ state ][ 1 ];
		if ( dm ) {
			text = 'overdue' === state ? __( 'Past target', 'takedown-desk' ) : __( 'Target', 'takedown-desk' );
		}
		c.label.replaceChildren( icon( STATES[ state ][ 0 ] ), el( 'span', { text } ) );
		if ( first || c.quiet ) {
			return;
		}
		if ( dm ) {
			speak(
				'overdue' === state
					? /* translators: %s: request ID. */ sprintf( __( 'Request %s is past its target.', 'takedown-desk' ), c.r.public_id )
					: /* translators: 1: request ID, 2: time in words, e.g. 20 hours 4 minutes. */ sprintf( __( 'Request %1$s has %2$s left.', 'takedown-desk' ), c.r.public_id, t )
			);
		} else if ( 'overdue' === state ) {
			/* translators: %s: request ID. */
			speak( sprintf( __( 'Request %s is overdue.', 'takedown-desk' ), c.r.public_id ) );
		} else if ( 'ok' !== state ) {
			const h = 'urgent' === state ? 8 : 24;
			/* translators: 1: request ID, 2: number of hours. */
			speak( sprintf( _n( 'Request %1$s has %2$d hour left.', 'Request %1$s has %2$d hours left.', h, 'takedown-desk' ), c.r.public_id, h ) );
		}
	}

	// Once a minute: the clocks show whole minutes, and text that changes every second distracts (WCAG 2.2.2).
	setInterval( () => clocks.forEach( tick ), 60000 );

	/* ---------- Chips ---------- */
	// New is filled, so unopened work stands out; overdue shows in the clock and the row edge, never instead of the status.
	const STATUS = {
		new: [ 'new', __( 'New', 'takedown-desk' ) ],
		in_review: [ 'blue', __( 'In review', 'takedown-desk' ) ],
		needs_info: [ 'amber', __( 'Waiting for details', 'takedown-desk' ) ],
		removed: [ 'green', __( 'Removed', 'takedown-desk' ) ],
		declined: [ 'neutral', __( 'Not removed', 'takedown-desk' ) ],
		spam: [ 'neutral', __( 'Spam', 'takedown-desk' ) ],
	};

	function statusChip( r ) {
		const s = STATUS[ r.status ] || [ 'neutral', r.status_label ];
		return el( 'span', { class: 'tkd-chip tkd-chip--' + s[ 0 ] }, el( 'span', { class: 'tkd-chip__dot', 'aria-hidden': 'true' } ), s[ 1 ] );
	}

	const testChip = () => el( 'span', { class: 'tkd-chip tkd-chip--purple', text: __( 'Test request', 'takedown-desk' ) } );
	const autoChip = () => el( 'span', { class: 'tkd-chip tkd-chip--blue' }, icon( 'shield' ), __( 'Auto-hidden', 'takedown-desk' ) );
	const copyChip = () => el( 'span', { class: 'tkd-chip tkd-chip--orange' }, icon( 'copyright' ), __( 'Copyright', 'takedown-desk' ) );

	// Auto-hidden state, then the advisory abuse signals in amber (signals.php). Advice only: nothing is declined.
	function signalChips( r ) {
		const s = r.signals || {};
		const list = s.list || [];
		return s.auto_hidden || list.length
			? el( 'span', { class: 'tkd-chips tkd-req__flags' }, s.auto_hidden ? autoChip() : null, list.map( ( x ) => el( 'span', { class: 'tkd-chip tkd-chip--amber' }, icon( 'flag' ), x.label ) ) )
			: null;
	}

	/* ---------- People: avatars and the assign menu ---------- */
	const TONES = [ 'blue', 'green', 'amber', 'orange' ]; // The brand colors; each person keeps one, picked by user ID.

	// A colored initial. No profile pictures: WordPress loads them from a third-party service.
	function avatar( p ) {
		return el( 'span', {
			class: 'tkd-avatar tkd-avatar--' + TONES[ ( parseInt( p.id, 10 ) || 0 ) % TONES.length ],
			'aria-hidden': 'true',
			// Drawn by CSS (::before), so the letter is not part of a button's visible label (WCAG 2.5.3).
			'data-initial': p.initial || ( Array.from( String( p.name || '' ).trim() )[ 0 ] || '?' ).toUpperCase(),
		} );
	}

	function who( a ) {
		return a ? el( 'span', { class: 'tkd-who' }, avatar( a ), a.name ) : muted( __( 'Unassigned', 'takedown-desk' ) );
	}

	let closeMenu = null; // Closes the assign menu that is open; there is only ever one.

	/*
	 * Assign menu: a button (aria-haspopup="listbox") opens a listbox that takes focus and points at the
	 * active option with aria-activedescendant. The list is fixed in the app root, so no card clips it; it
	 * opens below the button and flips above when there is no room. Picking saves at once: o.save( userId )
	 * returns the request promise, o.done( response ) runs after it; a failed save puts the old person back.
	 */
	function assignMenu( o ) {
		let current = o.current || null;
		let active = 0;
		const key = ( p ) => ( p ? p.id : 0 );
		const choices = [ null, ...o.people ];
		if ( current && ! o.people.some( ( p ) => p.id === current.id ) ) {
			choices.push( current );
		}
		const nameOf = ( p ) => ( p ? p.name : __( 'Unassigned', 'takedown-desk' ) );
		const face = ( p ) => ( p ? avatar( p ) : el( 'span', { class: 'tkd-avatar tkd-avatar--none', 'aria-hidden': 'true' } ) );
		const valueId = uid();
		const list = el( 'ul', { id: uid(), class: 'tkd-pop', role: 'listbox', tabindex: '-1', 'aria-labelledby': o.labelId, onkeydown: keys } );
		const button = el( 'button', {
			type: 'button',
			id: o.id,
			class: 'tkd-pick' + ( o.compact ? ' tkd-pick--compact' : '' ),
			'aria-haspopup': 'listbox',
			'aria-expanded': 'false',
			'aria-labelledby': o.labelId + ' ' + valueId,
			onclick: () => ( list.isConnected ? close( true ) : open() ),
			onkeydown: ( e ) => {
				if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
					e.preventDefault();
					open();
				}
			},
		} );
		const quiet = () => close( false );
		const outside = ( e ) => ! list.contains( e.target ) && ! button.contains( e.target ) && quiet();

		function show( p ) {
			button.replaceChildren( face( p ), el( 'span', { id: valueId, class: 'tkd-pick__name', text: nameOf( p ) } ), icon( 'down' ) );
		}

		function open() {
			if ( button.hasAttribute( 'aria-disabled' ) ) {
				return;
			}
			if ( closeMenu ) {
				closeMenu();
			}
			list.replaceChildren(
				...choices.map( ( p, i ) => {
					const on = key( p ) === key( current );
					return el(
						'li',
						{ id: list.id + '-' + i, class: 'tkd-pop__opt', role: 'option', 'aria-selected': String( on ), onclick: () => pick( i ), onpointermove: () => move( i, true ) },
						face( p ),
						el( 'span', { class: 'tkd-pop__name', text: nameOf( p ) } ),
						on ? icon( 'check' ) : null
					);
				} )
			);
			root.append( list );
			button.setAttribute( 'aria-expanded', 'true' );
			place();
			move( choices.findIndex( ( p ) => key( p ) === key( current ) ) );
			list.focus( { preventScroll: true } );
			document.addEventListener( 'pointerdown', outside, true );
			window.addEventListener( 'scroll', place, true );
			window.addEventListener( 'resize', place );
			window.addEventListener( 'hashchange', quiet );
			closeMenu = quiet;
		}

		function close( refocus ) {
			document.removeEventListener( 'pointerdown', outside, true );
			window.removeEventListener( 'scroll', place, true );
			window.removeEventListener( 'resize', place );
			window.removeEventListener( 'hashchange', quiet );
			if ( closeMenu === quiet ) {
				closeMenu = null;
			}
			list.remove();
			button.setAttribute( 'aria-expanded', 'false' );
			if ( refocus ) {
				button.focus();
			}
		}

		// Below the button, or above it when there is more room there; never wider or taller than the window.
		function place() {
			if ( ! button.isConnected ) {
				quiet();
				return;
			}
			const gap = 6;
			const b = button.getBoundingClientRect();
			const view = document.documentElement;
			const below = view.clientHeight - b.bottom - 2 * gap;
			const above = b.top - 2 * gap;
			list.style.minWidth = b.width + 'px';
			list.style.maxHeight = '';
			const h = list.offsetHeight;
			const up = h > below && above > below;
			const room = Math.max( 0, up ? above : below );
			list.style.maxHeight = h > room ? room + 'px' : '';
			list.style.top = ( up ? b.top - gap - Math.min( h, room ) : b.bottom + gap ) + 'px';
			list.style.transformOrigin = up ? 'bottom' : 'top';
			const w = list.offsetWidth;
			const x = 'rtl' === getComputedStyle( button ).direction ? b.right - w : b.left;
			list.style.left = Math.max( gap, Math.min( x, view.clientWidth - w - gap ) ) + 'px';
		}

		function move( i, hover ) {
			active = Math.max( 0, Math.min( choices.length - 1, i ) );
			const li = list.children[ active ];
			[ ...list.children ].forEach( ( n ) => n.classList.toggle( 'is-active', n === li ) );
			list.setAttribute( 'aria-activedescendant', li.id );
			if ( hover ) {
				return;
			}
			if ( li.offsetTop < list.scrollTop ) {
				list.scrollTop = li.offsetTop;
			} else if ( li.offsetTop + li.offsetHeight > list.scrollTop + list.clientHeight ) {
				list.scrollTop = li.offsetTop + li.offsetHeight - list.clientHeight;
			}
		}

		function keys( e ) {
			const k = e.key;
			const to = { ArrowDown: active + 1, ArrowUp: active - 1, Home: 0, End: choices.length - 1 }[ k ];
			if ( undefined !== to ) {
				e.preventDefault();
				move( to );
			} else if ( 'Enter' === k || ' ' === k ) {
				e.preventDefault();
				pick( active );
			} else if ( 'Escape' === k ) {
				e.preventDefault();
				e.stopPropagation();
				close( true );
			} else if ( 'Tab' === k ) {
				close( true ); // The Tab itself then moves on from the button.
			} else if ( 1 === k.length && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
				// Type-ahead: the next person whose name starts with the letter typed.
				for ( let n = 1; n <= choices.length; n++ ) {
					const i = ( active + n ) % choices.length;
					if ( nameOf( choices[ i ] ).trim().toLowerCase().startsWith( k.toLowerCase() ) ) {
						move( i );
						break;
					}
				}
			}
		}

		function pick( i ) {
			const before = current;
			const next = choices[ i ];
			close( true );
			if ( key( next ) === key( before ) ) {
				return;
			}
			const idle = ( p ) => {
				current = p;
				show( p );
				button.removeAttribute( 'aria-disabled' );
				button.classList.remove( 'is-busy' );
			};
			current = next;
			show( next );
			button.setAttribute( 'aria-disabled', 'true' );
			button.classList.add( 'is-busy' );
			o.save( key( next ) ).then(
				( res ) => {
					idle( res.assigned_to || null );
					o.done( res );
				},
				( e ) => {
					idle( before );
					button.focus();
					toast( errMsg( e ), 'error' );
				}
			);
		}

		show( current );
		return button;
	}

	/* ---------- Forms ---------- */
	function field( o ) {
		const id = uid();
		const type = o.type || 'text';
		let input;
		if ( 'textarea' === type ) {
			input = el( 'textarea', Object.assign( { id, name: o.name, rows: o.rows || 3, class: 'tkd-input' }, o.attrs ) );
		} else if ( 'select' === type ) {
			input = el( 'select', Object.assign( { id, name: o.name, class: 'tkd-input tkd-select' }, o.attrs ), o.options.map( ( p ) => el( 'option', { value: p[ 0 ], text: p[ 1 ] } ) ) );
		} else {
			input = el( 'input', Object.assign( { id, name: o.name, type, class: 'checkbox' === type ? null : 'tkd-input' }, o.attrs ) );
		}
		input.required = !! o.required;
		if ( undefined !== o.value ) {
			input.value = o.value;
		}
		const help = o.help ? el( 'p', { id: id + 'h', class: 'tkd-help', text: o.help } ) : null;
		const err = el( 'p', { id: id + 'e', class: 'tkd-error', hidden: true } );
		const label =
			'checkbox' === type
				? el( 'label', { for: id, class: 'tkd-check' }, input, el( 'span', { text: o.label } ) )
				: el( 'label', { for: id, class: 'tkd-label', text: o.label } );
		const wrap = el( 'div', { class: 'tkd-field' + ( o.wide ? ' tkd-field--wide' : '' ) }, label, 'checkbox' === type ? null : input, help, err );
		const set = ( msg ) => {
			err.hidden = ! msg;
			err.replaceChildren( ...( msg ? [ icon( 'alert' ), el( 'span', { text: msg } ) ] : [] ) );
			input.setAttribute( 'aria-invalid', msg ? 'true' : 'false' );
			const ids = [ help && help.id, msg && err.id ].filter( Boolean ).join( ' ' );
			if ( ids ) {
				input.setAttribute( 'aria-describedby', ids );
			} else {
				input.removeAttribute( 'aria-describedby' );
			}
			return !! msg;
		};
		set( '' );
		return { wrap, input, set };
	}

	// Error summary: focused after a failed submit, each item links to its field.
	function summary( list, msg ) {
		return el(
			'div',
			{ class: 'tkd-summary', role: 'alert', tabindex: '-1' },
			el( 'p', { class: 'tkd-summary__title' }, icon( 'octagon' ), el( 'span', { text: list.length ? __( 'Fix these to continue:', 'takedown-desk' ) : msg } ) ),
			list.length
				? el(
						'ul',
						{},
						list.map( ( x ) =>
							el( 'li', {}, el( 'a', { href: '#' + x[ 0 ].input.id, text: x[ 1 ], onclick: ( e ) => {
								e.preventDefault();
								x[ 0 ].input.focus();
							} } ) )
						)
				  )
				: null
		);
	}

	function showError( box, list, msg ) {
		list.forEach( ( x ) => x[ 0 ].set( x[ 1 ] ) );
		box.replaceChildren( summary( list, msg ) );
		box.firstChild.focus();
	}

	function radios( name, legend, options, value, onPick ) {
		return el(
			'fieldset',
			{ class: 'tkd-radios' },
			el( 'legend', { class: 'tkd-label', text: legend } ),
			options.map( ( p ) =>
				el( 'label', { class: 'tkd-option' }, el( 'input', { type: 'radio', name, value: p[ 0 ], checked: p[ 0 ] === value, onchange: () => onPick( p[ 0 ] ) } ), el( 'span', { text: p[ 1 ] } ) )
			)
		);
	}

	/* ---------- Dialog: native modal (focus stays inside), Cancel built in, focus returns to the opener ---------- */
	function dialog( o ) {
		const opener = document.activeElement;
		const titleId = uid();
		const d = el( 'dialog', { class: 'tkd-dialog' + ( o.wide ? ' tkd-dialog--wide' : '' ), 'aria-labelledby': titleId, 'aria-describedby': o.descId } );
		const inner = o.onsubmit
			? el( 'form', { class: 'tkd-dialog__form', novalidate: true, onsubmit: ( e ) => {
				e.preventDefault();
				o.onsubmit();
			} } )
			: d;
		add( inner, [
			el(
				'div',
				{ class: 'tkd-dialog__head' },
				el( 'h2', { id: titleId, class: 'tkd-dialog__title', text: o.title } ),
				el( 'button', { type: 'button', class: 'tkd-iconbtn', 'aria-label': __( 'Close', 'takedown-desk' ), onclick: () => d.close() }, icon( 'x' ) )
			),
			el( 'div', { class: 'tkd-dialog__body' }, o.body ),
			el( 'div', { class: 'tkd-dialog__foot' }, btn( o.closeLabel || __( 'Cancel', 'takedown-desk' ), { autofocus: o.cancelFirst, onclick: () => d.close() } ), o.buttons ),
		] );
		if ( inner !== d ) {
			d.append( inner );
		}
		d.addEventListener( 'close', () => {
			d.remove();
			const target = 'returnTo' in d ? d.returnTo : opener;
			if ( target && target.isConnected ) {
				target.focus();
			}
		} );
		root.append( d );
		d.showModal();
		const first = d.querySelector( '[data-autofocus]' );
		if ( first ) {
			first.focus();
		}
		return d;
	}

	/* ---------- Shell: header with registered tabs, one panel, footer ---------- */
	const panel = el( 'div', { class: 'tkd-panel', id: 'tkd-panel', role: 'tabpanel' } );
	let seq = 0; // Bumped on every navigation and load; stale responses are dropped.
	let pending = null; // Selector to focus once the next view has drawn.
	let unsaved = false; // Settings has changes that are not saved yet.

	function focusPending( sel ) {
		const target = ( sel || pending ) && panel.querySelector( sel || pending );
		pending = null;
		if ( target ) {
			target.focus();
		}
	}

	function hero( title, desc, actions, tip ) {
		return el(
			'header',
			{ class: 'tkd-hero' },
			el(
				'div',
				{ class: 'tkd-hero__main' },
				el( 'div', { class: 'tkd-hero__titlerow' }, el( 'h1', { class: 'tkd-hero__title', tabindex: '-1', text: title } ), tip ),
				el( 'p', { class: 'tkd-hero__desc', text: desc } )
			),
			el( 'div', { class: 'tkd-hero__actions' }, actions )
		);
	}

	const card = ( ic, tone, title, id, ...kids ) => el( 'section', { class: 'tkd-card', 'aria-labelledby': id }, cardHead( ic, title, tone, id ), el( 'div', { class: 'tkd-card__body' }, kids ) );
	const skeletonCards = () =>
		el( 'div', { class: 'tkd-skel-detail', 'aria-busy': 'true' }, el( 'span', { class: 'tkd-sr', text: __( 'Loading', 'takedown-desk' ) } ), [ 5, 4 ].map( ( n ) =>
			el( 'div', { class: 'tkd-card tkd-skel-card', 'aria-hidden': 'true' }, [ ...Array( n ) ].map( ( x, i ) => el( 'span', { class: 'tkd-skel', style: 'inline-size:' + ( 88 - ( i % 3 ) * 20 ) + '%' } ) ) )
		) );

	/* ---------- Inbox ---------- */
	// Opens on All, newest received first; each person's own filter and sort are kept in this browser.
	// `latest`: the newest request ID the list has seen (for the "new requests" pill); `fresh`: rows above it get a tint once.
	const inbox = { filter: 'all', search: '', page: 1, counts: null, orderby: 'received', order: 'desc', prefs: false, latest: null, fresh: 0, ui: null };
	const PREFS = 'tkdesk-inbox-' + ( cfg.userId || 0 );
	const savePrefs = () => remember( 'localStorage', PREFS, { filter: inbox.filter, orderby: inbox.orderby, order: inbox.order } );

	// Sortable columns: [ orderby, header, direction of the first click ].
	const sortCols = () => [
		[ 'due', __( 'Clock', 'takedown-desk' ), 'asc' ],
		[ 'id', __( 'Request', 'takedown-desk' ), 'asc' ],
		[ 'status', __( 'Status', 'takedown-desk' ), 'asc' ],
		[ 'assigned', __( 'Assigned to', 'takedown-desk' ), 'asc' ],
		[ 'received', __( 'Received', 'takedown-desk' ), 'desc' ],
	];

	// The same sorts as one select, for narrow screens where rows render as cards.
	const sortOptions = () => [
		[ 'received:desc', __( 'Newest received first', 'takedown-desk' ) ],
		[ 'received:asc', __( 'Oldest received first', 'takedown-desk' ) ],
		[ 'due:asc', __( 'Most urgent first', 'takedown-desk' ) ],
		[ 'due:desc', __( 'Least urgent first', 'takedown-desk' ) ],
		[ 'status:asc', __( 'Status, open first', 'takedown-desk' ) ],
		[ 'status:desc', __( 'Status, closed first', 'takedown-desk' ) ],
		[ 'assigned:asc', __( 'Assigned to, A to Z', 'takedown-desk' ) ],
		[ 'assigned:desc', __( 'Assigned to, Z to A', 'takedown-desk' ) ],
		[ 'id:asc', __( 'Request ID, lowest first', 'takedown-desk' ) ],
		[ 'id:desc', __( 'Request ID, highest first', 'takedown-desk' ) ],
	];

	// No order: a header click (new column takes its first direction, the active one flips).
	function setSort( ui, by, order ) {
		const first = sortCols().find( ( c ) => c[ 0 ] === by )[ 2 ];
		inbox.order = order || ( by !== inbox.orderby ? first : ( 'asc' === inbox.order ? 'desc' : 'asc' ) );
		inbox.orderby = by;
		inbox.page = 1;
		ui.sort.value = by + ':' + inbox.order;
		savePrefs();
		loadInbox( ui, 'sort' );
	}

	function renderInbox() {
		const ui = ( inbox.ui = {} );
		if ( ! inbox.prefs ) {
			inbox.prefs = true;
			const p = remember( 'localStorage', PREFS );
			if ( sortOptions().some( ( o ) => o[ 0 ] === p.orderby + ':' + p.order ) ) {
				Object.assign( inbox, { orderby: p.orderby, order: p.order } );
			}
			inbox.filter = 'string' === typeof p.filter && p.filter ? p.filter : inbox.filter;
		}
		if ( 'copyright' === inbox.filter && ! cfg.dmca ) {
			inbox.filter = 'all'; // Copyright notices were turned off since.
		}
		const placeholder = __( 'Search by request ID or item', 'takedown-desk' );
		const tip = infoTip( __( 'About search', 'takedown-desk' ), __( 'Contact details are encrypted, so names and email addresses cannot be searched.', 'takedown-desk' ) );
		let timer = 0;
		ui.search = el( 'input', {
			type: 'search',
			id: 'tkd-search',
			class: 'tkd-input tkd-search__input',
			placeholder,
			autocomplete: 'off',
			'aria-describedby': tip.lastChild.id,
			value: inbox.search,
			oninput: () => {
				clearTimeout( timer );
				timer = setTimeout( () => {
					inbox.search = ui.search.value.trim();
					inbox.page = 1;
					loadInbox( ui, true );
				}, 350 );
			},
		} );
		ui.filters = el( 'div', { class: 'tkd-filters', role: 'group', 'aria-label': __( 'Filter requests', 'takedown-desk' ) } );
		ui.sort = el(
			'select',
			{
				id: 'tkd-sortby',
				class: 'tkd-input tkd-select',
				value: inbox.orderby + ':' + inbox.order,
				onchange: () => setSort( ui, ...ui.sort.value.split( ':' ) ),
			},
			sortOptions().map( ( p ) => el( 'option', { value: p[ 0 ], text: p[ 1 ] } ) )
		);
		ui.toolbar = el(
			'div',
			{ class: 'tkd-card tkd-toolbar' },
			ui.filters,
			el( 'div', { class: 'tkd-search' }, el( 'label', { for: 'tkd-search', class: 'tkd-sr', text: placeholder } ), icon( 'search' ), ui.search, tip ),
			el( 'div', { class: 'tkd-sortby' }, el( 'label', { for: 'tkd-sortby', class: 'tkd-label', text: __( 'Sort by', 'takedown-desk' ) } ), ui.sort )
		);
		ui.results = el( 'div', { class: 'tkd-results', tabindex: '-1' } );
		ui.pill = el( 'div', { class: 'tkd-newslot', 'aria-live': 'polite' } );
		const slot = el( 'div', { class: 'tkd-status-slot', id: 'tkd-status-slot' } );
		panel.replaceChildren(
			view(
				true,
				hero(
					__( 'Removal requests', 'takedown-desk' ),
					__( 'See what needs action and how long is left.', 'takedown-desk' ),
					btn( __( 'Log a request', 'takedown-desk' ), { kind: 'primary', icon: 'plus', onclick: logDialog } )
				),
				slot,
				ui.toolbar,
				ui.pill,
				ui.results
			)
		);
		drawFilters( ui );
		loadInbox( ui );
		statusCard( slot );
		focusPending();
	}

	/* ---------- Status card (top of the inbox) ---------- */
	const FIX = { create_notice_page: 'create', publish_notice_page: 'publish', add_form: 'add_form', create_copyright_page: 'dmca' };

	function statusCard( slot ) {
		api( 'status' ).then(
			( s ) => {
				if ( ! slot.isConnected ) {
					return;
				}
				const problems = s.problems || [];
				// Only the all-clear card can be dismissed; any problem brings the card back.
				const clear = s.live && ! problems.length;
				if ( clear && s.dismissed ) {
					slot.replaceChildren();
					return;
				}
				const dismiss = ( e ) => {
					const b = e.currentTarget;
					busy( b, true );
					api( 'status/dismiss', 'POST' ).then(
						() => {
							slot.replaceChildren();
							const search = document.getElementById( 'tkd-search' );
							( search && search.offsetParent ? search : panel.querySelector( 'h1' ) ).focus();
						},
						( err ) => {
							busy( b, false );
							b.focus();
							toast( errMsg( err ), 'error' );
						}
					);
				};
				const fix = ( p ) => {
					// The monthly drill: send a test request now, or put the prompt off for 30 days (whole site).
					if ( 'test_request' === p.fix_action ) {
						const later = btn( __( 'Remind me in 30 days', 'takedown-desk' ), {
							onclick: ( e ) => {
								const b = e.currentTarget;
								busy( b, true );
								api( 'status/dismiss', 'POST', { what: 'drill' } ).then(
									() => {
										toast( __( 'We will remind you in 30 days.', 'takedown-desk' ) );
										statusCard( slot );
										const search = document.getElementById( 'tkd-search' );
										( search && search.offsetParent ? search : panel.querySelector( 'h1' ) ).focus();
									},
									( err ) => {
										busy( b, false );
										toast( errMsg( err ), 'error' );
									}
								);
							},
						} );
						return el( 'div', { class: 'tkd-problem__acts' }, cfg.canManage ? btn( p.fix_label, { icon: 'send', onclick: sendTest } ) : null, later );
					}
					if ( p.fix_action && FIX[ p.fix_action ] && cfg.canManage ) {
						return btn( p.fix_label, {
							kind: 'error' === p.severity ? 'primary' : 'secondary',
							onclick: ( e ) => {
								const b = e.currentTarget;
								const dmca = 'dmca' === FIX[ p.fix_action ];
								busy( b, true );
								( dmca ? api( 'dmca-page', 'POST' ) : api( 'setup/notice-page', 'POST', { page_id: ( s.notice_page && s.notice_page.id ) || 0, action: FIX[ p.fix_action ] } ) ).then(
									( res ) => {
										if ( ! dmca ) {
											cfg.noticeUrl = res.url || cfg.noticeUrl;
										}
										toast( dmca ? __( 'Copyright notice page created.', 'takedown-desk' ) : __( 'Notice page updated.', 'takedown-desk' ) );
										statusCard( slot );
									},
									( err ) => {
										busy( b, false );
										toast( errMsg( err ), 'error' );
									}
								);
							},
						} );
					}
					// Another site's page (the Copyright Office directory, WordPress docs) opens in a new tab.
					return p.fix_url ? linkChip( p.fix_label || __( 'Fix this', 'takedown-desk' ), p.fix_url, /^https?:/i.test( p.fix_url ) && 0 !== p.fix_url.indexOf( window.location.origin ) ) : null;
				};
				slot.replaceChildren(
					el(
						'section',
						{ class: 'tkd-card tkd-status ' + ( s.live ? 'is-live' : 'is-down' ), 'aria-labelledby': 'tkd-status-h' },
						el(
							'div',
							{ class: 'tkd-status__head' },
							el( 'span', { class: 'tkd-status__ico' }, icon( s.live ? 'check' : 'alert' ) ),
							el( 'h2', { id: 'tkd-status-h', class: 'tkd-status__title', text: s.live ? __( 'Your request process is live', 'takedown-desk' ) : __( 'Your request process is not live yet', 'takedown-desk' ) } ),
							s.live && cfg.noticeUrl ? linkChip( __( 'View your notice page', 'takedown-desk' ), cfg.noticeUrl, true ) : null,
							clear ? el( 'button', { type: 'button', class: 'tkd-iconbtn tkd-status__close', 'aria-label': __( 'Dismiss', 'takedown-desk' ), onclick: dismiss }, icon( 'x' ) ) : null
						),
						problems.length
							? el(
									'ul',
									{ class: 'tkd-problems' },
									problems.map( ( p ) =>
										el(
											'li',
											{ class: 'tkd-problem tkd-problem--' + ( 'error' === p.severity ? 'error' : 'warning' ) },
											el( 'span', { class: 'tkd-problem__ico' }, icon( 'error' === p.severity ? 'octagon' : 'alert' ) ),
											el( 'p', { class: 'tkd-problem__msg' }, el( 'span', { class: 'tkd-sr', text: 'error' === p.severity ? __( 'Problem:', 'takedown-desk' ) + ' ' : __( 'Warning:', 'takedown-desk' ) + ' ' } ), p.message ),
											fix( p )
										)
									)
							  )
							: null
					)
				);
			},
			() => {} // The status card is a helper; the inbox works without it.
		);
	}

	function drawFilters( ui ) {
		const c = inbox.counts;
		if ( ! c ) {
			ui.filters.replaceChildren( ...[ 1, 2, 3, 4 ].map( () => el( 'span', { class: 'tkd-skel tkd-skel--chip', 'aria-hidden': 'true' } ) ) );
			return;
		}
		const defs = hooks.applyFilters(
			'tkdesk.inbox.filters',
			[
				{ id: 'all', label: __( 'All', 'takedown-desk' ) },
				/* translators: %d: number of requests. */
				{ id: 'open', label: sprintf( __( 'Open (%d)', 'takedown-desk' ), c.open || 0 ) },
				/* translators: %d: number of requests. */
				{ id: 'overdue', label: sprintf( __( 'Overdue (%d)', 'takedown-desk' ), c.overdue || 0 ), alert: c.overdue > 0 },
				/* translators: %d: number of requests. */
				{ id: 'needs_info', label: sprintf( __( 'Waiting for details (%d)', 'takedown-desk' ), c.needs_info || 0 ) },
				{ id: 'removed', label: __( 'Removed', 'takedown-desk' ) },
				{ id: 'declined', label: __( 'Not removed', 'takedown-desk' ) },
				{ id: 'spam', label: __( 'Spam', 'takedown-desk' ) },
				/* translators: %d: number of copyright notices. */
				...( cfg.dmca ? [ { id: 'copyright', label: sprintf( __( 'Copyright (%d)', 'takedown-desk' ), c.copyright || 0 ) } ] : [] ),
				/* translators: %d: number of requests. */
				...( c.review ? [ { id: 'review', label: sprintf( __( 'Review requested (%d)', 'takedown-desk' ), c.review ), alert: true } ] : [] ),
			],
			c
		);
		// Update in place so the pressed chip keeps keyboard focus.
		if ( ui.filters.querySelectorAll( 'button' ).length !== defs.length ) {
			ui.filters.replaceChildren( ...defs.map( () => el( 'button', { type: 'button', class: 'tkd-filter', onclick: ( e ) => setFilter( ui, e.currentTarget.dataset.id ) } ) ) );
		}
		defs.forEach( ( f, i ) => {
			const b = ui.filters.children[ i ];
			b.dataset.id = f.id;
			b.textContent = f.label;
			b.classList.toggle( 'has-alert', !! f.alert );
			b.setAttribute( 'aria-pressed', String( f.id === inbox.filter ) );
		} );
	}

	function setFilter( ui, id, focusChip ) {
		inbox.filter = id;
		inbox.page = 1;
		savePrefs();
		drawFilters( ui );
		loadInbox( ui, true );
		if ( focusChip ) {
			ui.filters.querySelector( '[aria-pressed="true"]' ).focus();
		}
	}

	function loadInbox( ui, announce ) {
		const my = ++seq;
		const copy = 'copyright' === inbox.filter; // Every copyright notice that is not spam.
		const q = new URLSearchParams( { status: copy ? 'all' : inbox.filter, page: inbox.page, per_page: 20, orderby: inbox.orderby, order: inbox.order } );
		if ( copy ) {
			q.set( 'type', 'dmca' );
		}
		if ( inbox.search ) {
			q.set( 'search', inbox.search );
		}
		clocks = [];
		ui.results.setAttribute( 'aria-busy', 'true' );
		ui.results.replaceChildren( skeletonRows() );
		api( 'requests?' + q ).then(
			( res ) => {
				if ( my !== seq ) {
					return;
				}
				const items = res.items || [];
				const total = res.total || 0;
				const c = ( inbox.counts = res.counts || {} );
				inbox.mods = res.moderators || inbox.mods || [];
				inbox.latest = res.latest || 0;
				newPill( ui, 0 );
				syncCounts( c );
				const never = ! items.length && ! inbox.search && ! [ 'open', 'removed', 'declined', 'spam' ].some( ( k ) => c[ k ] );
				ui.results.removeAttribute( 'aria-busy' );
				ui.toolbar.hidden = never;
				drawFilters( ui );
				sync( items.find( isOpen ) );
				if ( never ) {
					ui.results.replaceChildren( firstRun() );
				} else if ( items.length ) {
					ui.results.replaceChildren(
						el(
							'div',
							{ class: 'tkd-card tkd-tablecard is-enter' },
							table( items, ui ),
							pager( inbox.page, total, 20, ( p ) => {
								inbox.page = p;
								loadInbox( ui, 'page' );
							} )
						)
					);
					inbox.fresh = 0; // The tint marks new rows once.
				} else {
					ui.results.replaceChildren( noMatch( ui ) );
				}
				if ( announce && 'sort' !== announce ) {
					/* translators: %d: number of requests. */
					speak( sprintf( _n( '%d request found.', '%d requests found.', total, 'takedown-desk' ), total ) );
				}
				if ( 'page' === announce ) {
					ui.results.focus();
				}
				// A header click redrew the table under the focus: put it back on that header.
				const sorted = ui.results.querySelector( '.tkd-sort.is-active' );
				if ( 'sort' === announce && sorted && sorted.offsetParent && ( ! document.activeElement || document.body === document.activeElement ) ) {
					sorted.focus();
				}
			},
			() => {
				if ( my === seq ) {
					ui.results.removeAttribute( 'aria-busy' );
					ui.toolbar.hidden = ! inbox.counts; // No counts yet means no filters worth showing.
					ui.results.replaceChildren( errorCard( __( 'We could not load requests. Check your connection and try again.', 'takedown-desk' ), () => loadInbox( ui ) ) );
				}
			}
		);
	}

	// Requests that arrived after the list loaded are offered, never slipped in under the pointer.
	function newPill( ui, n ) {
		if ( String( n ) === ui.pill.dataset.n ) {
			return; // Unchanged: no repeat announcement.
		}
		ui.pill.dataset.n = String( n );
		ui.pill.replaceChildren(
			n
				? btn(
						/* translators: %d: number of new requests. */
						sprintf( _n( '%d new request. Show it', '%d new requests. Show them', n, 'takedown-desk' ), n ),
						{
							kind: 'primary',
							icon: 'refresh',
							onclick: () => {
								inbox.fresh = inbox.latest;
								inbox.page = 1;
								inbox.search = ui.search.value = '';
								inbox.filter = [ 'all', 'open' ].includes( inbox.filter ) ? inbox.filter : 'all';
								drawFilters( ui );
								loadInbox( ui, 'page' );
							},
						}
				  )
				: ''
		);
	}

	// Every minute: fresh chip counts and admin-bar count, and the pill for new requests while the inbox shows.
	setInterval( () => {
		if ( document.hidden || ! root ) {
			return;
		}
		const since = null === inbox.latest ? '' : '&since=' + inbox.latest;
		api( 'requests?status=open&per_page=1' + since ).then( ( res ) => {
			const ui = inbox.ui;
			syncCounts( res.counts || {} );
			if ( ui && ui.results.isConnected ) {
				inbox.counts = res.counts || inbox.counts;
				drawFilters( ui );
				newPill( ui, since ? res.newer || 0 : 0 );
			}
		}, () => {} );
	}, 60000 );

	function skeletonRows( label ) {
		const bar = ( w ) => el( 'span', { class: 'tkd-skel', style: 'inline-size:' + w } );
		return el(
			'div',
			{ class: 'tkd-card tkd-tablecard' },
			el( 'span', { class: 'tkd-sr', text: label || __( 'Loading requests', 'takedown-desk' ) } ),
			el(
				'div',
				{ class: 'tkd-skel-list', 'aria-hidden': 'true' },
				[ 0, 1, 2, 3, 4 ].map( ( i ) =>
					el(
						'div',
						{ class: 'tkd-skel-row' },
						el( 'span', { class: 'tkd-skel tkd-skel--ring' } ),
						el( 'span', { class: 'tkd-skel-stack' }, bar( i % 2 ? '58%' : '72%' ), bar( i % 2 ? '40%' : '48%' ) ),
						bar( '88px' ),
						bar( '104px' ),
						bar( '76px' )
					)
				)
			)
		);
	}

	function table( items, ui ) {
		const cols = sortCols();
		const heads = cols.map( ( c ) => c[ 1 ] );
		const up = 'asc' === inbox.order;
		// Sortable headers: a button in each th, aria-sort on the active one only.
		const th = ( [ by, h ] ) => {
			const on = by === inbox.orderby;
			return el(
				'th',
				{ scope: 'col', 'aria-sort': on ? ( up ? 'ascending' : 'descending' ) : null },
				el( 'button', { type: 'button', class: 'tkd-sort' + ( on ? ' is-active' : '' ), onclick: () => setSort( ui, by ) }, el( 'span', { text: h } ), icon( on ? ( up ? 'up' : 'down' ) : 'sort' ) )
			);
		};
		return el(
			'table',
			{ class: 'tkd-table' },
			el( 'caption', { class: 'tkd-sr', text: __( 'Removal requests', 'takedown-desk' ) } ),
			el( 'thead', {}, el( 'tr', {}, cols.map( th ) ) ),
			el( 'tbody', {}, items.map( ( r, i ) => row( r, heads, i ) ) )
		);
	}

	function row( r, heads, i ) {
		const href = '#request/' + r.id;
		const o = r.object;
		const chip = el( 'span', { class: 'tkd-row__status' }, statusChip( r ) );
		return el(
			'tr',
			{
				// Unopened requests read bold, like unread mail; requests that just arrived get a tint once.
				class: 'tkd-row' + ( 'new' === r.status ? ' is-new' : '' ) + ( inbox.fresh && r.id > inbox.fresh ? ' is-fresh' : '' ),
				style: '--i:' + Math.min( i, 10 ),
				// The ID link is the keyboard path; the whole row is a larger pointer target.
				onclick: ( e ) => {
					if ( ! e.target.closest( 'a, button, select' ) && ! String( window.getSelection() ) ) {
						window.location.hash = href;
					}
				},
			},
			el( 'td', { 'data-label': heads[ 0 ] }, clock( r ) ),
			el(
				'td',
				{ 'data-label': heads[ 1 ] },
				el(
					'div',
					{ class: 'tkd-req' },
					el(
						'span',
						{ class: 'tkd-req__top' },
						el( 'a', { class: 'tkd-id', href, text: r.public_id } ),
						cfg.dmca && isDmca( r ) ? copyChip() : null,
						r.is_test ? testChip() : null,
						r.minor ? el( 'span', { class: 'tkd-chip tkd-chip--red', text: __( 'Under 18', 'takedown-desk' ) } ) : null,
						r.review_requested ? reviewChip() : null
					),
					el( 'span', { class: 'tkd-req__item', text: o ? o.label : __( 'Item not matched', 'takedown-desk' ) } ),
					o && o.url ? el( 'span', { class: 'tkd-req__where', text: where( o.url ) } ) : null,
					signalChips( r )
				)
			),
			el( 'td', { 'data-label': heads[ 2 ] }, chip ),
			el( 'td', { 'data-label': heads[ 3 ] }, isOpen( r ) ? assignInline( r ) : who( r.assigned_to ) ),
			el( 'td', { 'data-label': heads[ 4 ] }, el( 'span', { class: 'tkd-muted', text: fmtDate( r.received_at ) } ) )
		);
	}

	// Assign straight from the table (open requests only); the row ignores clicks on buttons, so it does not open.
	function assignInline( r ) {
		/* translators: %s: request ID. */
		const label = el( 'span', { id: uid(), class: 'tkd-sr', text: sprintf( __( 'Assign %s', 'takedown-desk' ), r.public_id ) } );
		return [
			label,
			assignMenu( {
				labelId: label.id,
				people: inbox.mods || [],
				current: r.assigned_to,
				compact: true,
				save: ( id ) => api( 'requests/' + r.id + '/assign', 'POST', { user_id: id } ),
				done: ( res ) => {
					r.assigned_to = res.assigned_to || null;
					if ( res.assigned_to ) {
						/* translators: 1: request ID, 2: user display name. */
						toast( sprintf( __( '%1$s assigned to %2$s.', 'takedown-desk' ), r.public_id, res.assigned_to.name ) );
					} else {
						/* translators: %s: request ID. */
						toast( sprintf( __( '%s unassigned.', 'takedown-desk' ), r.public_id ) );
					}
				},
			} ),
		];
	}

	function noMatch( ui ) {
		// One way out per state, never back to an empty view: clear the search, widen to All, or (All is empty) show spam.
		let action = null;
		if ( inbox.search ) {
			action = btn( __( 'Clear search', 'takedown-desk' ), {
				onclick: () => {
					inbox.search = '';
					ui.search.value = '';
					setFilter( ui, inbox.filter, true );
				},
			} );
		} else if ( 'all' !== inbox.filter ) {
			action = btn( __( 'Show all requests', 'takedown-desk' ), { onclick: () => setFilter( ui, 'all', true ) } );
		} else if ( inbox.counts && inbox.counts.spam ) {
			action = btn( __( 'Show spam', 'takedown-desk' ), { onclick: () => setFilter( ui, 'spam', true ) } );
		}
		return el(
			'div',
			{ class: 'tkd-card tkd-empty' },
			art( 'inbox' ),
			el( 'p', {
				class: 'tkd-empty__title',
				/* translators: %s: what the person searched for. */
				text: inbox.search ? sprintf( __( 'No requests match "%s".', 'takedown-desk' ), inbox.search ) : __( 'Nothing here. No requests match this filter.', 'takedown-desk' ),
			} ),
			inbox.search ? el( 'p', { class: 'tkd-empty__text', text: __( 'Search covers request IDs and item names. Names and email addresses are encrypted, so they cannot be searched.', 'takedown-desk' ) } ) : null,
			action
		);
	}

	function firstRun() {
		return el(
			'section',
			{ class: 'tkd-card tkd-empty tkd-empty--first', 'aria-labelledby': 'tkd-first-h' },
			art( 'shield' ),
			el( 'h2', { id: 'tkd-first-h', class: 'tkd-empty__title', text: __( 'No requests yet', 'takedown-desk' ) } ),
			el( 'p', { class: 'tkd-empty__text', text: __( 'When someone sends a removal request it appears here with a 48-hour clock. Try the process once so you know what to expect.', 'takedown-desk' ) } ),
			el(
				'div',
				{ class: 'tkd-actions' },
				cfg.canManage ? btn( __( 'Send a test request', 'takedown-desk' ), { kind: 'primary', icon: 'send', onclick: sendTest } ) : null,
				cfg.noticeUrl ? linkChip( __( 'View your notice page', 'takedown-desk' ), cfg.noticeUrl, true ) : null
			)
		);
	}

	function sendTest( e ) {
		const b = e.currentTarget;
		busy( b, true );
		api( 'test-request', 'POST' ).then(
			( res ) => {
				toast( __( 'Test request sent.', 'takedown-desk' ) );
				window.location.hash = '#request/' + res.request_id;
			},
			( err ) => {
				busy( b, false );
				toast( errMsg( err ), 'error' );
			}
		);
	}

	/* ---------- Log a request (manual entry) ---------- */
	function localInput( ts ) {
		const d = new Date( ts * 1000 );
		d.setMinutes( d.getMinutes() - d.getTimezoneOffset() );
		return d.toISOString().slice( 0, 16 );
	}

	// Who files: the site itself (admin) or someone who asked by email, phone or mail (requester).
	const FILERS = {
		admin: {
			hint: __( 'The request is filed in your name and the 48-hour deadline starts now. No requester emails are sent.', 'takedown-desk' ),
			statement: [ __( 'Reason for removal', 'takedown-desk' ), __( 'Saved to the record. For example: "Intimate image posted without the consent of the person shown."', 'takedown-desk' ) ],
			goodFaith: __( 'I believe in good faith that this image was published without consent, and the information in this request is accurate.', 'takedown-desk' ),
			errors: {
				statement: __( 'Write the reason for removal in 10 to 2,000 characters.', 'takedown-desk' ),
				good_faith: __( 'Check this box to confirm the statement.', 'takedown-desk' ),
			},
		},
		requester: {
			hint: __( 'Enter the details the requester gave you. They get a confirmation email now and the outcome when the request is closed.', 'takedown-desk' ),
			statement: [ __( 'Why does the requester want it removed?', 'takedown-desk' ), __( 'Use their words where you can. For example: "This photo of me was posted without my consent."', 'takedown-desk' ) ],
			goodFaith: __( 'The requester confirmed in good faith that this image was published without consent and that the information in this request is accurate.', 'takedown-desk' ),
			errors: {
				statement: __( 'Write why the requester wants it removed in 10 to 2,000 characters.', 'takedown-desk' ),
				good_faith: __( 'Check this box to record that the requester confirmed this.', 'takedown-desk' ),
				email: __( 'Enter a valid email address for the requester.', 'takedown-desk' ),
				relationship: __( 'Choose who the requester is asking for.', 'takedown-desk' ),
				signature: __( 'Enter the name the requester signed with, in 2 to 120 characters.', 'takedown-desk' ),
			},
		},
	};
	const REQUESTER_ONLY = [ 'received_at', 'channel', 'email', 'phone', 'relationship', 'signature' ];
	const DMCA_ONLY = [ 'work', 'work_url', 'address', 'accuracy' ];

	function logDialog() {
		const t0 = Math.floor( now() );
		const F = {};
		let filer = '';
		let kind = ''; // Requester path with copyright notices on: 'ncii' or 'dmca', asked before the fields show.
		let type = 'ncii';
		let changed = false;
		// What changes with the kind of request: arrival help, where the content is, who the requester is.
		const KINDS = {
			ncii: {
				received: __( 'The 48-hour deadline counts from this time.', 'takedown-desk' ),
				url: __( 'Where is the image?', 'takedown-desk' ),
				urlHelp: __( 'Paste the link to the page that shows it. You can add more links in the next box.', 'takedown-desk' ),
				rel: [
					__( 'Who is the requester asking for?', 'takedown-desk' ),
					[
						[ 'self', __( 'Themselves', 'takedown-desk' ) ],
						[ 'authorized', __( 'Someone they are authorized to act for', 'takedown-desk' ) ],
					],
				],
			},
			dmca: {
				received: __( 'The target time counts from this time.', 'takedown-desk' ),
				url: __( 'Where is the material on this site?', 'takedown-desk' ),
				urlHelp: __( 'Paste the link to the page that shows it. You can add more links in the details box.', 'takedown-desk' ),
				rel: [
					__( 'Who is sending the notice?', 'takedown-desk' ),
					[
						[ 'owner', __( 'The copyright owner', 'takedown-desk' ) ],
						[ 'agent', __( 'Someone authorized to act for the copyright owner', 'takedown-desk' ) ],
					],
				],
			},
		};
		const f = ( o ) => {
			F[ o.name ] = field( o );
			return F[ o.name ].wrap;
		};
		const save = btn( __( 'Save request', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'check', disabled: true } );
		const box = el( 'div' );
		// Save stays off until the fields show and one changes, and again after a failed save until the next edit.
		const ready = () => {
			save.disabled = ! filer || ! changed || grid.hidden;
		};
		const touch = () => {
			changed = true;
			ready();
		};
		const hint = el( 'p', { class: 'tkd-alert__msg' } );
		const grid = el(
			'div',
			{ class: 'tkd-grid2', hidden: true, oninput: touch, onchange: touch },
			el( 'div', { class: 'tkd-alert tkd-alert--neutral tkd-field--wide' }, el( 'span', { class: 'tkd-alert__ico' }, icon( 'info' ) ), hint ),
			f( {
				name: 'received_at',
				type: 'datetime-local',
				label: __( 'When did it arrive?', 'takedown-desk' ),
				help: KINDS.ncii.received,
				value: localInput( t0 ),
				required: true,
				attrs: { max: localInput( t0 ) },
			} ),
			f( {
				name: 'channel',
				type: 'select',
				label: __( 'How did it arrive?', 'takedown-desk' ),
				options: [
					[ 'email', __( 'Email', 'takedown-desk' ) ],
					[ 'phone', __( 'Phone', 'takedown-desk' ) ],
					[ 'mail', __( 'Mail', 'takedown-desk' ) ],
					[ 'other', __( 'Other', 'takedown-desk' ) ],
				],
			} ),
			f( {
				name: 'url',
				type: 'url',
				wide: true,
				required: true,
				label: KINDS.ncii.url,
				help: KINDS.ncii.urlHelp,
				attrs: { autocomplete: 'off' },
			} ),
			f( {
				name: 'work',
				type: 'textarea',
				wide: true,
				required: true,
				label: __( 'Which copyrighted work is it about?', 'takedown-desk' ),
				help: __( 'For example: the title of the photo, article or video, and who made it.', 'takedown-desk' ),
			} ),
			f( { name: 'work_url', type: 'url', wide: true, label: __( 'Where the original is (optional)', 'takedown-desk' ), help: __( 'A link to the original work, on any site.', 'takedown-desk' ), attrs: { autocomplete: 'off' } } ),
			f( {
				name: 'details',
				type: 'textarea',
				wide: true,
				label: __( 'Details that help find it (optional)', 'takedown-desk' ),
				help: __( 'For example: the third photo in the post, or the profile picture.', 'takedown-desk' ),
			} ),
			f( { name: 'statement', type: 'textarea', wide: true, required: true, label: FILERS.requester.statement[ 0 ], help: FILERS.requester.statement[ 1 ] } ),
			f( { name: 'email', type: 'email', required: true, label: __( "Requester's email", 'takedown-desk' ), help: __( 'Any questions and the outcome go to this address.', 'takedown-desk' ), attrs: { autocomplete: 'off' } } ),
			f( { name: 'phone', type: 'tel', label: __( "Requester's phone (optional)", 'takedown-desk' ), help: __( 'Only if they gave one.', 'takedown-desk' ), attrs: { autocomplete: 'off' } } ),
			f( { name: 'address', type: 'textarea', rows: 2, wide: true, label: __( "Requester's postal address (optional)", 'takedown-desk' ), help: __( 'Only if they gave one.', 'takedown-desk' ), attrs: { maxlength: 300 } } ),
			f( { name: 'relationship', type: 'select', label: KINDS.ncii.rel[ 0 ], options: KINDS.ncii.rel[ 1 ] } ),
			f( { name: 'signature', required: true, label: __( 'Name the requester signed with', 'takedown-desk' ), help: __( 'Their full name as they gave it. It counts as their signature.', 'takedown-desk' ), attrs: { autocomplete: 'off' } } ),
			f( { name: 'good_faith', type: 'checkbox', wide: true, required: true, label: FILERS.requester.goodFaith } ),
			f( {
				name: 'accuracy',
				type: 'checkbox',
				wide: true,
				required: true,
				label: __( 'The requester confirmed that the information in the notice is accurate and, under penalty of perjury, that they are the copyright owner or authorized to act for the owner.', 'takedown-desk' ),
			} )
		);
		// The filer and, on the requester path with copyright notices on, the kind of request decide the fields.
		const apply = () => {
			const p = FILERS[ filer ];
			const admin = 'admin' === filer;
			const asks = ! admin && !! cfg.dmca;
			type = asks ? kind : 'ncii';
			const dm = 'dmca' === type;
			const k = KINDS[ type ] || KINDS.ncii;
			kinds.hidden = ! asks;
			REQUESTER_ONLY.forEach( ( n ) => {
				F[ n ].wrap.hidden = admin;
			} );
			DMCA_ONLY.forEach( ( n ) => {
				F[ n ].wrap.hidden = ! dm;
			} );
			F.statement.wrap.hidden = dm;
			F.statement.wrap.querySelector( '.tkd-label' ).textContent = p.statement[ 0 ];
			F.statement.wrap.querySelector( '.tkd-help' ).textContent = p.statement[ 1 ];
			F.received_at.wrap.querySelector( '.tkd-help' ).textContent = k.received;
			F.url.wrap.querySelector( '.tkd-label' ).textContent = k.url;
			F.url.wrap.querySelector( '.tkd-help' ).textContent = k.urlHelp;
			F.relationship.wrap.querySelector( '.tkd-label' ).textContent = k.rel[ 0 ];
			F.relationship.input.replaceChildren( ...k.rel[ 1 ].map( ( o ) => el( 'option', { value: o[ 0 ], text: o[ 1 ] } ) ) );
			F.good_faith.wrap.querySelector( '.tkd-check span' ).textContent = dm
				? __( 'The requester confirmed in good faith that the use of the material is not authorized by the copyright owner, its agent or the law.', 'takedown-desk' )
				: p.goodFaith;
			// A different person or statement now, so the boxes start empty.
			F.good_faith.input.checked = false;
			F.accuracy.input.checked = false;
			hint.textContent = p.hint;
			Object.keys( F ).forEach( ( n ) => F[ n ].set( '' ) );
			box.replaceChildren();
			grid.hidden = ! type;
			ready();
		};
		const required = ( set ) => {
			set.querySelectorAll( 'input' ).forEach( ( input ) => {
				input.required = true;
			} );
			return set;
		};
		const who = required(
			radios(
				'tkd-filer',
				__( 'Who is filing this request?', 'takedown-desk' ),
				[
					[ 'admin', __( 'I am filing it for the site', 'takedown-desk' ) ],
					[ 'requester', __( 'I am logging it for someone who contacted us', 'takedown-desk' ) ],
				],
				'',
				( v ) => {
					filer = v;
					apply();
				}
			)
		);
		who.querySelector( 'input' ).setAttribute( 'data-autofocus', '' );
		const kinds = required(
			radios(
				'tkd-kind',
				__( 'What kind of request is it?', 'takedown-desk' ),
				[
					[ 'ncii', __( 'Intimate image request', 'takedown-desk' ) ],
					[ 'dmca', __( 'Copyright notice', 'takedown-desk' ) ],
				],
				'',
				( v ) => {
					kind = v;
					apply();
				}
			)
		);
		kinds.hidden = true;
		// Field errors next to each field and in the summary; focus moves to the first one.
		const fail = ( list, msg ) => {
			changed = false;
			ready();
			showError( box, list, msg );
			if ( list.length ) {
				list[ 0 ][ 0 ].input.focus();
			}
		};
		const d = dialog( {
			title: __( 'Log a removal request', 'takedown-desk' ),
			wide: true,
			body: [ el( 'p', { class: 'tkd-lead', text: __( 'Record a request that reached you outside the online form, or file one for the site yourself.', 'takedown-desk' ) } ), who, kinds, box, grid ],
			buttons: [ save ],
			onsubmit() {
				if ( ! filer || grid.hidden ) {
					return;
				}
				const admin = 'admin' === filer;
				const dm = 'dmca' === type;
				Object.keys( F ).forEach( ( k ) => F[ k ].set( '' ) );
				const data = { filer, good_faith: F.good_faith.input.checked };
				if ( dm ) {
					Object.assign( data, { type, accuracy: F.accuracy.input.checked } );
				}
				if ( ! admin ) {
					const raw = F.received_at.input.value;
					const at = Math.floor( new Date( raw ).getTime() / 1000 );
					if ( ! raw || isNaN( at ) ) {
						fail( [ [ F.received_at, __( 'Enter the date and time it arrived.', 'takedown-desk' ) ] ] );
						return;
					}
					if ( at > now() + 60 ) {
						fail( [ [ F.received_at, __( 'The arrival time cannot be in the future.', 'takedown-desk' ) ] ] );
						return;
					}
					data.received_at = at;
				}
				let keys = [ 'channel', 'url', 'details', 'statement', 'email', 'phone', 'relationship', 'signature' ];
				if ( admin ) {
					keys = [ 'url', 'details', 'statement' ];
				} else if ( dm ) {
					keys = [ 'channel', 'url', 'work', 'work_url', 'details', 'email', 'phone', 'address', 'relationship', 'signature' ];
				}
				keys.forEach( ( k ) => {
					data[ k ] = F[ k ].input.value.trim();
				} );
				busy( save, true );
				api( 'requests', 'POST', data ).then(
					( r ) => {
						d.returnTo = null; // The new request's heading takes focus.
						d.close();
						/* translators: %s: request ID. */
						toast( sprintf( __( 'Request %s saved.', 'takedown-desk' ), r.public_id ) );
						window.location.hash = '#request/' + r.id;
						syncCounts();
					},
					( e ) => {
						busy( save, false );
						const fields = ( e.data && e.data.fields ) || {};
						const own = Object.assign(
							{ url: __( 'Enter the full link to the page that shows the image, starting with https://.', 'takedown-desk' ) },
							FILERS[ filer ].errors,
							dm
								? {
										url: __( 'Enter the full link to the page on this site that shows the material, starting with https://.', 'takedown-desk' ),
										work: __( 'Describe the copyrighted work in 10 to 2,000 characters.', 'takedown-desk' ),
										work_url: __( 'Enter the full link to the original, starting with https:// or http://.', 'takedown-desk' ),
										address: __( 'Keep the address to 300 characters or fewer.', 'takedown-desk' ),
										relationship: __( 'Choose who is sending the notice.', 'takedown-desk' ),
										accuracy: FILERS.requester.errors.good_faith,
								  }
								: {}
						);
						const list = Object.keys( fields )
							.filter( ( k ) => F[ k ] )
							.map( ( k ) => [ F[ k ], own[ k ] || fields[ k ] ] );
						if ( list.length ) {
							fail( list );
						} else {
							showError( box, [], errMsg( e ) );
						}
					}
				);
			},
		} );
	}

	/* ---------- Request detail ---------- */
	const det = { id: 0, active: false, r: null, copies: null, contact: null, results: null, draft: null, opened: new Set() };
	const base = () => 'requests/' + det.id;
	const reload = ( sel ) => det.active && loadDetail( det.id, sel, true );
	// Filed by the site itself (relationship `site`): there is no requester to contact, ask or email.
	const bySite = ( r ) => 'site' === ( r && r.relationship );
	const NO_REQUESTER = __( 'Filed by the site team, so there is no requester to ask or email.', 'takedown-desk' );
	// Item roles and actions, exactly as stored (Pro adds the near_copy role and its badge through tkdesk.copies.badges).
	const ROLES = {
		reported: [ __( 'Reported item', 'takedown-desk' ), 'blue' ],
		exact_copy: [ __( 'Exact copy', 'takedown-desk' ), 'orange' ],
		reference: [ __( 'Also appears in', 'takedown-desk' ), 'neutral' ],
	};
	const ACTIONS = {
		offline: __( 'Taken offline', 'takedown-desk' ),
		image_removed: __( 'Images removed', 'takedown-desk' ),
		deleted: __( 'Deleted permanently', 'takedown-desk' ),
		restored: __( 'Restored', 'takedown-desk' ),
		hidden: __( 'Hidden while reviewing', 'takedown-desk' ),
		skipped: __( 'Skipped', 'takedown-desk' ),
		failed: __( 'Failed', 'takedown-desk' ),
	};
	const acted = ( it ) => [ 'offline', 'image_removed', 'deleted' ].includes( it.action );
	const noPause = ( r ) => ( isDmca( r ) ? __( 'Asking for details does not pause the target clock.', 'takedown-desk' ) : __( 'Asking for details does not pause the deadline.', 'takedown-desk' ) );
	const chosen = () => ( det.r.items || [] ).filter( ( it ) => det.draft.sel.has( it.id ) && ! acted( it ) );

	function openDetail( id ) {
		if ( det.id !== id ) {
			Object.assign( det, { id, r: null, copies: null, draft: null } );
		}
		Object.assign( det, { active: true, contact: null, results: null } );
		loadDetail( id );
	}

	function loadDetail( id, sel, quiet, findAfter ) {
		const my = ++seq;
		const get = () => api( 'requests/' + id );
		if ( ! quiet ) {
			clocks = [];
			panel.replaceChildren( view( true, backChip(), skeletonDetail() ) );
		}
		get()
			.then( ( r ) => {
				if ( 'new' !== r.status || det.opened.has( id ) ) {
					return r;
				}
				det.opened.add( id ); // POST /open once: new => in review, logged as "opened".
				return api( 'requests/' + id + '/open', 'POST' ).then( get, () => r );
			} )
			.then(
				( r ) => {
					if ( my !== seq ) {
						return;
					}
					setDetail( r );
					const find = ( ! quiet || findAfter ) && r.object && isOpen( r );
					if ( find ) {
						det.copies = { loading: true };
					}
					drawDetail( sel, quiet );
					if ( find ) {
						findCopies();
					}
				},
				( e ) => {
					if ( my !== seq ) {
						return;
					}
					clocks = [];
					panel.replaceChildren(
						view(
							true,
							backChip(),
							404 === ( e && e.data && e.data.status )
								? el(
										'div',
										{ class: 'tkd-card tkd-empty' },
										art( 'search' ),
										el( 'h1', { class: 'tkd-empty__title', tabindex: '-1', text: __( 'This request does not exist or was deleted.', 'takedown-desk' ) } ),
										linkChip( __( 'Back to requests', 'takedown-desk' ), '#inbox', false, 'back' )
								  )
								: errorCard( __( 'We could not load this request. Check your connection and try again.', 'takedown-desk' ), () => loadDetail( id ) )
						)
					);
					focusPending( 'h1' );
				}
			);
	}

	function setDetail( r ) {
		if ( ! det.draft ) {
			// Scope "images" starts with every image checked, so Remove never starts at 0.
			const posts = ( r.images || [] ).length && r.object && 'post' === r.object.type;
			const imgs = new Set( posts ? r.images.map( ( im ) => im.attachment_id ) : [] );
			det.draft = { note: '', mode: 'offline', scope: posts ? 'images' : 'item', sel: new Set(), imgs, seen: new Set() };
		}
		det.r = r;
		sync( r );
		preselect( r.items || [] );
	}

	// The reported item and exact copies start checked; "Also appears in" stays a choice.
	function preselect( items ) {
		const d = det.draft;
		items.forEach( ( it ) => {
			if ( ! d.seen.has( it.id ) ) {
				d.seen.add( it.id );
				if ( ( 'reported' === it.role || 'exact_copy' === it.role ) && ! acted( it ) ) {
					d.sel.add( it.id );
				}
			}
		} );
	}

	const backChip = () => el( 'div', { class: 'tkd-backbar' }, linkChip( __( 'Back to requests', 'takedown-desk' ), '#inbox', false, 'back' ) );

	function skeletonDetail() {
		const card = ( n ) =>
			el( 'div', { class: 'tkd-card tkd-skel-card' }, [ ...Array( n ) ].map( ( x, i ) => el( 'span', { class: 'tkd-skel', style: 'inline-size:' + ( 90 - ( i % 3 ) * 18 ) + '%' } ) ) );
		return el(
			'div',
			{ class: 'tkd-skel-detail' },
			el( 'span', { class: 'tkd-sr', text: __( 'Loading request', 'takedown-desk' ) } ),
			el( 'div', { class: 'tkd-skel-hero', 'aria-hidden': 'true' } ),
			el( 'div', { class: 'tkd-detail', 'aria-hidden': 'true' }, el( 'div', { class: 'tkd-col' }, card( 7 ) ), el( 'div', { class: 'tkd-col' }, card( 4 ), card( 5 ) ) )
		);
	}

	function drawDetail( sel, quiet ) {
		const r = det.r;
		const open = isOpen( r );
		clocks = [];
		panel.classList.toggle( 'tkd-revealed', revealed( r ) );
		panel.replaceChildren(
			view(
				! quiet,
				backChip(),
				detailHero( r ),
				outcome( r ),
				el(
					'div',
					{ class: 'tkd-detail' },
					el( 'div', { class: 'tkd-col' }, r.minor && ! isDmca( r ) ? minorPanel( r ) : null, requestCard( r ), open && ! r.object ? matchCard() : null ),
					el(
						'div',
						{ class: 'tkd-col' },
						r.object || ( r.items || [] ).length ? copiesCard() : null,
						open ? decisionCard() : null,
						hooks.applyFilters( 'tkdesk.request.panels', [], r )
					)
				),
				recordCard( r ),
				open ? decideBar( r ) : null
			)
		);
		focusPending( sel );
	}

	// Narrow screens stack the columns, so the Decision card sits far down: this sticky bar keeps the clock in view and jumps there.
	function decideBar( r ) {
		return el(
			'div',
			{ class: 'tkd-decidebar' },
			clock( r, false, true ),
			btn( __( 'Decide', 'takedown-desk' ), {
				kind: 'primary',
				icon: 'down',
				onclick: () => {
					const h = panel.querySelector( '#tkd-decision-h' ) || panel.querySelector( '#tkd-decision' );
					if ( h ) {
						h.scrollIntoView( { block: 'start' } );
						h.focus( { preventScroll: true } );
					}
				},
			} )
		);
	}

	function detailHero( r ) {
		const open = isOpen( r );
		const chips = el(
			'div',
			{ class: 'tkd-hero__chips' },
			statusChip( r ),
			r.is_test ? testChip() : null,
			r.signals && r.signals.auto_hidden ? autoChip() : null,
			r.review_requested ? reviewChip() : null,
			! open && r.clock && r.clock.late ? el( 'span', { class: 'tkd-chip tkd-chip--red', text: isDmca( r ) ? __( 'Closed after the target', 'takedown-desk' ) : __( 'Closed after the deadline', 'takedown-desk' ) } ) : null
		);
		return el(
			'header',
			{ class: 'tkd-hero tkd-hero--detail' },
			el(
				'div',
				{ class: 'tkd-hero__main' },
				el( 'p', { class: 'tkd-hero__eyebrow', text: isDmca( r ) ? __( 'Copyright notice', 'takedown-desk' ) : __( 'Removal request', 'takedown-desk' ) } ),
				el( 'h1', { class: 'tkd-hero__title tkd-mono', tabindex: '-1', text: r.public_id } ),
				chips,
				el( 'p', { class: 'tkd-hero__desc', text: r.object ? r.object.label : __( 'Item not matched', 'takedown-desk' ) } )
			),
			el(
				'div',
				{ class: 'tkd-hero__side' },
				'spam' === r.status
					? null
					: el(
							'div',
							{ class: 'tkd-hero__clock' },
							clock( r, true ),
							open && r.clock && r.clock.deadline_text ? el( 'p', { class: 'tkd-hero__deadline' }, icon( 'clock' ), el( 'span', { text: r.clock.deadline_text } ) ) : null
					  ),
				assignField( r )
			)
		);
	}

	function assignField( r ) {
		const label = el( 'label', { id: uid(), for: 'tkd-assign', class: 'tkd-label', text: __( 'Assigned to', 'takedown-desk' ) } );
		return el(
			'div',
			{ class: 'tkd-assign' },
			label,
			assignMenu( {
				id: 'tkd-assign',
				labelId: label.id,
				people: r.moderators || [],
				current: r.assigned_to,
				save: ( id ) => api( base() + '/assign', 'POST', { user_id: id } ),
				done: ( res ) => {
					/* translators: %s: user display name. */
					toast( res.assigned_to ? sprintf( __( 'Assigned to %s.', 'takedown-desk' ), res.assigned_to.name ) : __( 'Request unassigned.', 'takedown-desk' ) );
					reload( '#tkd-assign' );
				},
			} )
		);
	}

	// Red line when the outcome email did not go out (plan 3.3 rule 3). It repeats the record's own line, so a
	// turned-off email reads as turned off rather than as a failure.
	function notNotified( r ) {
		const causes = {
			purged: __( 'Requester not emailed: The contact details were deleted.', 'takedown-desk' ),
			key_changed: __( 'Requester not emailed: The contact details cannot be read after a key change.', 'takedown-desk' ),
		};
		const logged = ( r.log || [] ).filter( ( l ) => 'requester_not_notified' === l.event ).pop();
		const text = causes[ r.text_error ] || ( logged && logged.text ) || __( 'Requester not emailed: The email could not be sent.', 'takedown-desk' );
		return el( 'p', { class: 'tkd-banner__alert' }, icon( 'alert' ), el( 'span', { text } ) );
	}

	/* Banner, per-item results and the CDN card after a decision. */
	function outcome( r ) {
		const out = [];
		const reopen = btn( __( 'Reopen request', 'takedown-desk' ), { icon: 'undo', onclick: ( e ) => act( e.currentTarget, 'reopen', __( 'Request reopened. The deadline was not reset.', 'takedown-desk' ), 'h1' ) } );
		const closedText = ( r.clock && r.clock.closed_text ) || '';
		const site = bySite( r );
		const emailed = r.requester_notified || site ? ' ' + ( site ? __( 'Filed by the site team, so there is no requester to email.', 'takedown-desk' ) : __( 'Requester emailed.', 'takedown-desk' ) ) : '';
		const alert = r.requester_notified || site ? null : notNotified( r );
		if ( 'removed' === r.status ) {
			const n = Math.max( 1, ( r.items || [] ).filter( acted ).length );
			// The duration comes from the server's closed_text so the banner and the clock always agree.
			const span = closedText.match( /\d.*\d\S*(?:\s+[^\s\d]+)?/ );
			const msg = span
				? /* translators: 1: number of items, 2: duration such as "17 h 42 min". */ sprintf( _n( 'Removed %1$d item %2$s after the request.', 'Removed %1$d items %2$s after the request.', n, 'takedown-desk' ), n, span[ 0 ] )
				: closedText + '.';
			out.push( banner( 'success', 'check', msg + emailed, reopen, alert ) );
		} else if ( 'declined' === r.status ) {
			/* translators: 1: sentence such as "Closed 3 h 5 min after the request", 2: decline reason. */
			const msg = sprintf( __( '%1$s. Reason: %2$s.', 'takedown-desk' ), closedText || __( 'Closed', 'takedown-desk' ), String( r.decline_reason_label || '' ).replace( /\.$/, '' ) );
			out.push( banner( 'neutral', 'ban', msg + emailed, reopen, alert ) );
		} else if ( 'spam' === r.status ) {
			out.push( banner( 'neutral', 'flag', __( 'Marked as spam. No email was sent.', 'takedown-desk' ), reopen ) );
		}
		if ( det.results ) {
			out.push( resultsCard( r ) );
		}
		if ( r.reach && ( r.items || [] ).some( ( it ) => acted( it ) || 'hidden' === it.action ) ) {
			out.push( reachCard( r ) );
		}
		if ( r.cdn && r.cdn.show && ( r.cdn.urls || [] ).length ) {
			out.push( cdnCard( r.cdn ) );
		}
		return out;
	}

	// Removal proof: the latest check of the links of removed and hidden items (POST /check runs it again).
	function reachCard( r ) {
		const x = r.reach;
		const live = x.live || [];
		const unknown = x.unknown || [];
		const again = btn( __( 'Check again', 'takedown-desk' ), { icon: 'refresh', onclick: ( e ) => checkAgain( e.currentTarget ) } );
		const when = [
			/* translators: %s: date and time. */
			sprintf( __( 'Checked %s.', 'takedown-desk' ), fmtDate( x.at ) ),
			/* translators: %s: date and time. */
			x.recheck_at ? sprintf( __( 'Next automatic check: %s.', 'takedown-desk' ), fmtDate( x.recheck_at ) ) : '',
		].join( ' ' );
		// Cloudflare purge (Settings, Cloudflare): its latest result; a failed one is retried by Check again.
		const cf = x.cf_purge;
		const cfFail =
			cf && ! cf.ok
				? /* translators: %s: error message. */ sprintf( __( 'Cloudflare did not purge the removed links (%s). Check the Cloudflare settings, then select Check again.', 'takedown-desk' ), String( cf.error || '' ).replace( /\.$/, '' ) )
				: '';
		if ( ! live.length ) {
			const warn = unknown.length || cfFail;
			return el(
				'div',
				{ class: 'tkd-alert tkd-reach ' + ( warn ? 'tkd-alert--amber' : 'tkd-alert--ok' ), id: 'tkd-reach', tabindex: '-1' },
				el( 'span', { class: 'tkd-alert__ico' }, icon( warn ? 'alert' : 'shield' ) ),
				el(
					'div',
					{ class: 'tkd-alert__msg' },
					el( 'p', {
						text: x.gone
							? /* translators: %d: number of links. */ sprintf( _n( 'Verified unreachable: %d link no longer answers.', 'Verified unreachable: %d links no longer answer.', x.gone, 'takedown-desk' ), x.gone )
							: __( 'We could not check whether the removed links still answer.', 'takedown-desk' ),
					} ),
					unknown.length
						? /* translators: %d: number of links. */ el( 'p', { text: sprintf( _n( 'We could not check %d link. Try again in a few minutes.', 'We could not check %d links. Try again in a few minutes.', unknown.length, 'takedown-desk' ), unknown.length ) } )
						: null,
					cf ? el( 'p', { text: cfFail || __( 'Cloudflare purged its cached copies.', 'takedown-desk' ) } ) : null,
					el( 'p', { class: 'tkd-reach__when', text: when } )
				),
				again
			);
		}
		const jet = live.filter( ( u ) => /^https:\/\/i[0-3]\.wp\.com\//.test( u ) );
		const area = el( 'textarea', { class: 'tkd-input tkd-mono tkd-cdn__urls', readonly: true, wrap: 'off', rows: Math.min( 6, live.length ) + 1, 'aria-label': __( 'Links that still answer', 'takedown-desk' ), value: live.join( '\n' ) } );
		const steps = [];
		if ( jet.length ) {
			const text = [
				__( 'Hello Jetpack support,', 'takedown-desk' ),
				isDmca( r )
					? /* translators: %s: site address. */ sprintf( __( 'We removed these images from %s after a copyright notice, but the Jetpack image CDN still serves copies. Please purge them from the CDN cache:', 'takedown-desk' ), cfg.siteUrl || '' )
					: /* translators: %s: site address. */ sprintf( __( 'We removed these images from %s after a removal request under the Take It Down Act, but the Jetpack image CDN still serves copies. Please purge them from the CDN cache:', 'takedown-desk' ), cfg.siteUrl || '' ),
				jet.join( '\n' ),
				__( 'Thank you.', 'takedown-desk' ),
			].join( '\n\n' );
			const msg = el( 'textarea', { class: 'tkd-input', readonly: true, rows: 6, 'aria-label': __( 'Message to Jetpack support', 'takedown-desk' ), value: text } );
			steps.push(
				el(
					'li',
					{},
					el( 'p', { text: __( 'The Jetpack image CDN still serves a copy, and only Jetpack support can purge it. Send them this message:', 'takedown-desk' ) } ),
					msg,
					el( 'div', { class: 'tkd-actions' }, btn( __( 'Copy message', 'takedown-desk' ), { icon: 'copy', onclick: () => copyText( msg ) } ), linkChip( __( 'Contact Jetpack support', 'takedown-desk' ), 'https://jetpack.com/contact-support/', true ) )
				)
			);
		}
		if ( cfFail ) {
			steps.push( el( 'li', { text: cfFail } ) );
		}
		if ( x.cloudflare ) {
			steps.push(
				el(
					'li',
					{},
					el( 'p', { text: __( 'Your site uses Cloudflare. In the Cloudflare dashboard, open Caching, then Configuration, choose Custom Purge, paste the links above and purge them.', 'takedown-desk' ) } ),
					cf || ! cfg.canManage ? null : el( 'div', { class: 'tkd-actions' }, linkChip( __( 'Purge Cloudflare automatically', 'takedown-desk' ), '#settings' ) )
				)
			);
		}
		if ( live.length > jet.length ) {
			const plugin = r.cdn && r.cdn.plugin;
			/* translators: %s: name of the caching plugin. */
			steps.push( el( 'li', { text: plugin ? sprintf( __( 'Clear the cache of %s and of your host, so neither serves a saved copy.', 'takedown-desk' ), plugin ) : __( 'If a caching plugin or your host keeps a saved copy, clear that cache.', 'takedown-desk' ) } ) );
			steps.push( el( 'li', { text: __( 'If someone put the item back on the site, take it offline again.', 'takedown-desk' ) } ) );
		}
		steps.push( el( 'li', { text: __( 'Then select Check again.', 'takedown-desk' ) } ) );
		return el(
			'section',
			{ class: 'tkd-card tkd-card--alert', id: 'tkd-reach', 'aria-labelledby': 'tkd-reach-h' },
			/* translators: %d: number of links. */
			cardHead( 'alert', sprintf( _n( 'Still reachable: %d link', 'Still reachable: %d links', live.length, 'takedown-desk' ), live.length ), 'red', 'tkd-reach-h' ),
			el(
				'div',
				{ class: 'tkd-card__body' },
				el( 'p', { class: 'tkd-strong', text: __( 'These links still answer, so people can still see the content.', 'takedown-desk' ) } ),
				area,
				el( 'div', { class: 'tkd-actions' }, btn( __( 'Copy links', 'takedown-desk' ), { icon: 'copy', onclick: () => copyText( area ) } ) ),
				el( 'p', { class: 'tkd-label', text: __( 'Next steps', 'takedown-desk' ) } ),
				el( 'ol', { class: 'tkd-reach__steps' }, steps ),
				el( 'div', { class: 'tkd-actions' }, again, el( 'p', { class: 'tkd-reach__when', text: when } ) )
			)
		);
	}

	function checkAgain( button ) {
		busy( button, true );
		speak( __( 'Checking the links. Please wait.', 'takedown-desk' ) );
		api( base() + '/check', 'POST' ).then(
			() => {
				toast( __( 'Links checked.', 'takedown-desk' ) );
				reload( '#tkd-reach' );
			},
			( e ) => {
				busy( button, false );
				toast( errMsg( e ), 'error' );
			}
		);
	}

	function banner( tone, ic, msg, action, alert ) {
		return el(
			'div',
			{ class: 'tkd-banner tkd-banner--' + tone, tabindex: '-1' },
			el( 'span', { class: 'tkd-banner__ico' }, icon( ic ) ),
			el( 'div', { class: 'tkd-banner__msg' }, el( 'p', { text: msg } ), alert ),
			action
		);
	}

	function resultsCard( r ) {
		const byId = {};
		( r.items || [] ).forEach( ( it ) => {
			byId[ it.id ] = it;
		} );
		return el(
			'section',
			{ class: 'tkd-card', 'aria-labelledby': 'tkd-results-h' },
			cardHead( 'list', __( 'Removal results', 'takedown-desk' ), 'blue', 'tkd-results-h' ),
			el(
				'ul',
				{ class: 'tkd-outcomes' },
				det.results.map( ( x, i ) => {
					const it = byId[ x.item_id ] || {};
					return el(
						'li',
						{ class: 'tkd-outcome ' + ( x.ok ? 'is-ok' : 'is-fail' ), style: '--i:' + i },
						el( 'span', { class: 'tkd-outcome__ico' }, icon( x.ok ? 'check' : 'x' ) ),
						el( 'span', { class: 'tkd-outcome__label', text: it.label || '#' + x.item_id } ),
						el( 'span', { class: 'tkd-outcome__msg', text: x.ok ? ACTIONS[ it.action ] || x.message || __( 'Done', 'takedown-desk' ) : x.message } )
					);
				} )
			)
		);
	}

	function cdnCard( cdn ) {
		const area = el( 'textarea', {
			class: 'tkd-input tkd-mono tkd-cdn__urls',
			readonly: true,
			rows: Math.min( 6, cdn.urls.length ),
			'aria-label': __( 'File links to purge', 'takedown-desk' ),
			value: cdn.urls.join( '\n' ),
		} );
		return el(
			'section',
			{ class: 'tkd-card tkd-card--neutral', 'aria-labelledby': 'tkd-cdn-h' },
			cardHead( 'cloud', __( 'Check your CDN', 'takedown-desk' ), 'neutral', 'tkd-cdn-h' ),
			el(
				'div',
				{ class: 'tkd-card__body' },
				el( 'p', { text: __( 'If you use a CDN, purge these file links there too.', 'takedown-desk' ) } ),
				/* translators: %s: name of the detected caching plugin or host. */
				cdn.plugin ? el( 'p', { class: 'tkd-help', text: sprintf( __( 'Detected: %s', 'takedown-desk' ), cdn.plugin ) } ) : null,
				area,
				el( 'div', { class: 'tkd-actions' }, btn( __( 'Copy links', 'takedown-desk' ), { icon: 'copy', onclick: () => copyText( area ) } ) )
			)
		);
	}

	// "Where the original is": a link only for http and https; anything else stays plain text.
	function workLink( url ) {
		if ( ! url ) {
			return muted( __( 'None given', 'takedown-desk' ) );
		}
		if ( ! /^https?:\/\//i.test( url ) ) {
			return el( 'span', { class: 'tkd-mono tkd-break', text: url } );
		}
		return el( 'a', { class: 'tkd-inline tkd-mono tkd-break', href: url, target: '_blank', rel: 'noopener noreferrer' }, url, el( 'span', { class: 'tkd-sr', text: ' ' + __( '(opens in a new tab)', 'takedown-desk' ) } ) );
	}

	function requestCard( r ) {
		const o = r.object;
		const t = r.text;
		const c = det.contact;
		const dm = isDmca( r );
		const rows = [];
		rows.push( [
			__( 'Reported item', 'takedown-desk' ),
			o
				? [
						el( 'span', { class: 'tkd-strong', text: o.label } ),
						safePreview( r ),
						o.url && ! dm ? el( 'p', { class: 'tkd-contentnote' }, icon( 'alert' ), el( 'span', { text: __( 'This item may show intimate content.', 'takedown-desk' ) } ) ) : null,
						el(
							'span',
							{ class: 'tkd-chips' },
							o.url ? linkChip( __( 'Open item', 'takedown-desk' ), o.url, true ) : null,
							o.edit_url ? linkChip( __( 'Edit in WordPress', 'takedown-desk' ), o.edit_url, false, 'edit' ) : null
						),
				  ]
				: muted( __( 'Not matched yet', 'takedown-desk' ) ),
		] );
		if ( o ) {
			rows.push( [
				__( 'Uploaded by', 'takedown-desk' ),
				o.author ? [ el( 'span', { text: o.author.name } ), o.author.url ? linkChip( __( 'View profile', 'takedown-desk' ), o.author.url ) : null ] : __( 'Guest', 'takedown-desk' ),
			] );
		}
		if ( t ) {
			rows.push( [ __( 'Link as submitted', 'takedown-desk' ), el( 'span', { class: 'tkd-mono tkd-break', text: t.url } ) ] );
			if ( dm ) {
				rows.push( [ __( 'Copyrighted work', 'takedown-desk' ), t.work ? para( t.work ) : muted( __( 'None given', 'takedown-desk' ) ) ] );
				rows.push( [ __( 'Where the original is', 'takedown-desk' ), workLink( t.work_url ) ] );
			}
			rows.push( [ __( 'Details', 'takedown-desk' ), t.details ? para( t.details ) : muted( __( 'None given', 'takedown-desk' ) ) ] );
			if ( ! dm ) {
				rows.push( [ __( 'Statement', 'takedown-desk' ), para( t.statement ) ] );
			}
			if ( ( t.info_thread || [] ).length ) {
				rows.push( [ __( 'Questions and replies', 'takedown-desk' ), thread( t.info_thread ) ] );
			}
		}
		const site = bySite( r );
		const asking = {
			self: __( 'Myself', 'takedown-desk' ),
			authorized: __( 'Authorized representative', 'takedown-desk' ),
			guardian: __( 'Parent or guardian', 'takedown-desk' ),
			site: __( 'The site (filed by the site team)', 'takedown-desk' ),
		};
		// The site signs with the filer's name, which the record already shows.
		const filed = site && ( r.log || [] ).find( ( x ) => 'received' === x.event );
		if ( dm ) {
			// The two statements the sender had to confirm to send the notice (17 U.S.C. 512(c)(3)(A)(v) and (vi)).
			const said = [
				__( 'I believe in good faith that the use of the material is not authorized by the copyright owner, its agent or the law.', 'takedown-desk' ),
				__( 'The information in this notice is accurate, and under penalty of perjury, I am the copyright owner or am authorized to act for the owner.', 'takedown-desk' ),
			];
			rows.push( [ __( 'Relationship to the work', 'takedown-desk' ), r.relationship_label || muted( __( 'Not available', 'takedown-desk' ) ) ] );
			rows.push( [ __( 'Confirmed statements', 'takedown-desk' ), el( 'ul', { class: 'tkd-confirms' }, said.map( ( s ) => el( 'li', {}, icon( 'check' ), el( 'span', { text: s } ) ) ) ) ] );
		} else {
			rows.push( [ __( 'Asking for', 'takedown-desk' ), asking[ r.relationship ] || asking.self ] );
			rows.push( [
				__( 'Under 18 in the image', 'takedown-desk' ),
				r.minor
					? el( 'span', { class: 'tkd-chip tkd-chip--red', text: __( 'Yes', 'takedown-desk' ) } )
					: [ muted( __( 'Not marked', 'takedown-desk' ) ), btn( __( 'Mark as under 18', 'takedown-desk' ), { icon: 'flag', onclick: () => minorDialog( r, true ) } ) ],
			] );
		}
		rows.push( [ __( 'Signed', 'takedown-desk' ), [ ( c && c.signature ) || ( filed && filed.actor ), fmtDate( r.signed_at ) ].filter( Boolean ).join( ', ' ) || muted( __( 'Not available', 'takedown-desk' ) ) ] );
		rows.push( [ __( 'Contact', 'takedown-desk' ), site ? muted( NO_REQUESTER ) : contactBlock( r ) ] );
		if ( r.signals && r.signals.can_watch ) {
			rows.push( [ __( 'Watch list', 'takedown-desk' ), watchBlock( r ) ] );
		}

		let notice = null;
		if ( 'purged' === r.text_error ) {
			/* translators: %s: date. */
			notice = el( 'div', { class: 'tkd-alert tkd-alert--neutral' }, el( 'span', { class: 'tkd-alert__ico' }, icon( 'info' ) ), el( 'p', { class: 'tkd-alert__msg', text: sprintf( __( 'Contact details and request text were deleted on %s under your retention setting.', 'takedown-desk' ), fmtDate( r.purged_at ) ) } ) );
		} else if ( 'key_changed' === r.text_error ) {
			notice = el( 'div', { class: 'tkd-alert tkd-alert--error', role: 'alert' }, el( 'span', { class: 'tkd-alert__ico' }, icon( 'octagon' ) ), el( 'p', { class: 'tkd-alert__msg', text: __( "Contact details cannot be read because this site's encryption key changed. See Settings > Data.", 'takedown-desk' ) } ) );
		}
		return el(
			'section',
			{ class: 'tkd-card', 'aria-labelledby': 'tkd-request-h' },
			cardHead( 'file', __( 'Request', 'takedown-desk' ), 'blue', 'tkd-request-h' ),
			el( 'div', { class: 'tkd-card__body' }, notice, signalsNote( r ), el( 'dl', { class: 'tkd-dl' }, rows.map( ( x ) => el( 'div', { class: 'tkd-dl__row' }, el( 'dt', { text: x[ 0 ] } ), el( 'dd', {}, x[ 1 ] ) ) ) ) )
		);
	}

	// Advisory abuse signals (signals.php): a prompt to look closer, never a decision.
	function signalsNote( r ) {
		const list = ( r.signals && r.signals.list ) || [];
		if ( ! list.length ) {
			return null;
		}
		return el(
			'div',
			{ class: 'tkd-alert tkd-alert--amber tkd-signals' },
			el( 'span', { class: 'tkd-alert__ico' }, icon( 'flag' ) ),
			el(
				'div',
				{ class: 'tkd-alert__msg' },
				el( 'p', { text: __( 'Signals', 'takedown-desk' ) } ),
				el( 'ul', { class: 'tkd-bullets' }, list.map( ( x ) => el( 'li', { text: x.text } ) ) ),
				el( 'p', { text: __( 'Signals are advice only and never decide a request. A person whose images were shared widely may report many items, so judge each request on its own.', 'takedown-desk' ) } )
			)
		);
	}

	// Watch list, keyed on a one-way code made from the requester's email: the address itself is never stored there.
	function watchBlock( r ) {
		const on = !! r.signals.watched;
		return el(
			'div',
			{ class: 'tkd-watch', id: 'tkd-watch', tabindex: '-1' },
			on ? el( 'span', { class: 'tkd-chip tkd-chip--amber' }, icon( 'flag' ), __( 'On your watch list', 'takedown-desk' ) ) : muted( __( 'Not on your watch list', 'takedown-desk' ) ),
			btn( on ? __( 'Remove from watch list', 'takedown-desk' ) : __( 'Add to watch list', 'takedown-desk' ), {
				icon: on ? 'x' : 'flag',
				onclick: ( e ) => {
					const b = e.currentTarget;
					busy( b, true );
					api( base() + '/watch', 'POST', { watch: ! on } ).then(
						() => {
							toast( on ? __( 'Requester removed from the watch list.', 'takedown-desk' ) : __( 'Requester added to the watch list.', 'takedown-desk' ) );
							reload( '#tkd-watch' );
						},
						( err ) => {
							busy( b, false );
							toast( errMsg( err ), 'error' );
						}
					);
				},
			} ),
			el( 'p', { class: 'tkd-help', text: __( 'Every request from this requester shows "On your watch list". The list keeps a one-way code, never the email address.', 'takedown-desk' ) } )
		);
	}

	/* ---------- Safe previews (blurred and gray until "Show image", never on hover) and the minors path ---------- */
	// Per request for this browser session: true shown, false hidden; unset follows "Always blur previews".
	const PEEK = 'tkdesk-previews';
	const peek = ( () => {
		try {
			return JSON.parse( window.sessionStorage.getItem( PEEK ) ) || {};
		} catch ( e ) {
			return {};
		}
	} )();
	const peekLogged = new Set();
	const revealed = ( r ) => ! r.minor && ( r.id in peek ? peek[ r.id ] : ! r.blur_previews );

	function reveal( r, on ) {
		if ( null === on ) {
			delete peek[ r.id ];
		} else {
			peek[ r.id ] = on;
		}
		try {
			window.sessionStorage.setItem( PEEK, JSON.stringify( peek ) );
		} catch ( e ) {}
		panel.classList.toggle( 'tkd-revealed', revealed( r ) );
		if ( on && ! peekLogged.has( r.id ) ) {
			peekLogged.add( r.id ); // Logged once per page visit: "Image preview shown".
			api( 'requests/' + r.id + '/preview', 'POST' ).catch( () => peekLogged.delete( r.id ) );
		}
	}

	function safePreview( r ) {
		const list = r.previews || [];
		if ( r.minor ) {
			return el( 'p', { class: 'tkd-contentnote' }, icon( 'shield' ), el( 'span', { text: __( 'Previews are off because the person in the image was under 18.', 'takedown-desk' ) } ) );
		}
		if ( ! list.length ) {
			return null;
		}
		const n = list.length;
		const show = btn( _n( 'Show image', 'Show images', n, 'takedown-desk' ), { icon: 'eye', onclick: () => {
			reveal( r, true );
			hide.focus();
		} } );
		const hide = btn( _n( 'Hide image', 'Hide images', n, 'takedown-desk' ), { kind: 'ghost', icon: 'x', onclick: () => {
			reveal( r, false );
			show.focus();
		} } );
		show.classList.add( 'tkd-safe__show' );
		hide.classList.add( 'tkd-safe__hide' );
		const always = el( 'input', {
			type: 'checkbox',
			checked: r.blur_previews,
			onchange: ( e ) => {
				const box = e.target;
				box.disabled = true;
				wp.apiFetch( { path: '/wp/v2/users/me', method: 'POST', data: { meta: { _tkdesk_blur_previews: box.checked } } } ).then(
					() => {
						box.disabled = false;
						r.blur_previews = box.checked;
						reveal( r, null );
						toast( box.checked ? __( 'Previews will start blurred.', 'takedown-desk' ) : __( 'Previews will start visible.', 'takedown-desk' ) );
					},
					( err ) => {
						box.disabled = false;
						box.checked = ! box.checked;
						toast( errMsg( err ), 'error' );
					}
				);
			},
		} );
		return el(
			'figure',
			{ class: 'tkd-safe' },
			el(
				'div',
				{ class: 'tkd-safe__frame' + ( n > 1 ? ' is-grid' : '' ) },
				list.map( ( p ) => el( 'img', { class: 'tkd-safe__img', src: safe( p.url ), alt: p.label || '', loading: 'lazy' } ) ),
				el( 'div', { class: 'tkd-safe__veil' }, show )
			),
			el( 'figcaption', { class: 'tkd-safe__bar' }, hide, el( 'label', { class: 'tkd-safe__pref' }, always, el( 'span', { text: __( 'Always blur previews', 'takedown-desk' ) } ) ) )
		);
	}

	function minorDialog( r, on ) {
		const box = el( 'div' );
		const go = btn( on ? __( 'Mark as under 18', 'takedown-desk' ) : __( 'Clear the flag', 'takedown-desk' ), { kind: on ? 'primary' : 'danger', type: 'submit', icon: on ? 'flag' : 'x' } );
		const effects = [
			__( 'Items can only be taken offline, never deleted.', 'takedown-desk' ),
			__( 'Removed files stay out of public reach and are never deleted automatically.', 'takedown-desk' ),
			__( 'Image previews are turned off.', 'takedown-desk' ),
			__( 'You get the steps to report to the NCMEC CyberTipline.', 'takedown-desk' ),
		];
		const d = dialog( {
			title: on ? __( 'Mark this request as involving a person under 18?', 'takedown-desk' ) : __( 'Clear the under-18 flag?', 'takedown-desk' ),
			cancelFirst: ! on,
			body: on
				? [ box, el( 'p', { class: 'tkd-lead', text: __( 'Use this when the request says, or you have reason to believe, that the person in the image was under 18. While the flag is on:', 'takedown-desk' ) } ), el( 'ul', { class: 'tkd-bullets' }, effects.map( ( x ) => el( 'li', { text: x } ) ) ) ]
				: [ box, el( 'p', { class: 'tkd-lead', text: __( 'Clear it only if the request was marked by mistake. Permanent deletion, restore and the normal purge of removed files apply again.', 'takedown-desk' ) } ) ],
			buttons: [ go ],
			onsubmit() {
				busy( go, true );
				api( base() + '/minor', 'POST', { minor: on } ).then(
					() => {
						d.returnTo = null;
						d.close();
						toast( on ? __( 'Marked as involving a person under 18.', 'takedown-desk' ) : __( 'Under-18 flag cleared.', 'takedown-desk' ) );
						reload( on ? '#tkd-minor-h' : '#tkd-request-h' );
					},
					( e ) => {
						busy( go, false );
						showError( box, [], errMsg( e ) );
					}
				);
			},
		} );
	}

	// What to do when the person was under 18 (18 U.S.C. 2258A), with the report number and date for the record.
	function minorPanel( r ) {
		const t = r.cybertip || {};
		const num = field( { name: 'cybertip_report', label: __( 'CyberTipline report number', 'takedown-desk' ), value: t.report || '', attrs: { autocomplete: 'off', maxlength: '64' } } );
		const day = field( { name: 'cybertip_on', type: 'date', label: __( 'Reported on', 'takedown-desk' ), value: t.reported_on || t.today, attrs: { max: t.today } } );
		const box = el( 'div' );
		const save = btn( t.report ? __( 'Update report details', 'takedown-desk' ) : __( 'Save report details', 'takedown-desk' ), { kind: 'primary', icon: 'check', disabled: true } );
		const dirty = () => {
			save.disabled = num.input.value.trim() === ( t.report || '' ) && day.input.value === ( t.reported_on || t.today );
		};
		[ 'input', 'change' ].forEach( ( ev ) => [ num.input, day.input ].forEach( ( i ) => i.addEventListener( ev, dirty ) ) );
		save.addEventListener( 'click', () => {
			if ( num.set( num.input.value.trim() ? '' : __( 'Enter the report number from NCMEC.', 'takedown-desk' ) ) ) {
				num.input.focus();
				return;
			}
			busy( save, true );
			api( base() + '/minor', 'POST', { report: num.input.value.trim(), reported_on: day.input.value } ).then(
				() => {
					toast( __( 'Report details saved to the record.', 'takedown-desk' ) );
					reload( '#tkd-minor-h' );
				},
				( e ) => {
					busy( save, false );
					const f = ( e && e.data && e.data.fields ) || {};
					showError( box, [ [ num, f.report ], [ day, f.reported_on ] ].filter( ( x ) => x[ 1 ] ), errMsg( e ) );
				}
			);
		} );
		const steps = [
			[ __( 'If your site is not registered yet, register it with NCMEC as an electronic service provider.', 'takedown-desk' ), 'https://esp.ncmec.org/', __( 'Register at esp.ncmec.org', 'takedown-desk' ) ],
			[ __( 'File a report with the link to the item and what you know about it.', 'takedown-desk' ), 'https://report.cybertip.org/', __( 'Report at report.cybertip.org', 'takedown-desk' ) ],
			[ __( 'Take the item offline. Do not delete it, download it or share it.', 'takedown-desk' ) ],
			[ __( 'Enter the report number below.', 'takedown-desk' ) ],
		];
		return el(
			'section',
			{ class: 'tkd-card tkd-card--minor', 'aria-labelledby': 'tkd-minor-h' },
			cardHead( 'shield', __( 'Report to the NCMEC CyberTipline', 'takedown-desk' ), 'red', 'tkd-minor-h', el( 'span', { class: 'tkd-chip tkd-chip--red', text: __( 'Under 18', 'takedown-desk' ) } ) ),
			el(
				'div',
				{ class: 'tkd-card__body' },
				el( 'p', { text: __( 'This request involves a person who was under 18 in the image. U.S. law (18 U.S.C. 2258A) asks online services that learn of apparent child sexual abuse material to report it to the NCMEC CyberTipline as soon as they reasonably can, and to keep it for 1 year.', 'takedown-desk' ) } ),
				el( 'p', { class: 'tkd-help', text: __( 'While this flag is on, Takedown Desk only takes items offline, keeps the removed files out of public reach, never deletes them automatically and shows no image previews.', 'takedown-desk' ) } ),
				el(
					'ol',
					{ class: 'tkd-minor__steps' },
					steps.map( ( x ) => el( 'li', {}, el( 'span', { text: x[ 0 ] } ), x[ 1 ] ? linkChip( x[ 2 ], x[ 1 ], true ) : null ) )
				),
				t.report
					? el(
							'p',
							{ class: 'tkd-note' },
							icon( 'done' ),
							/* translators: 1: date, 2: report number, 3: date. */
							el( 'span', { text: sprintf( __( 'Reported on %1$s, report number %2$s. The files are kept until at least %3$s.', 'takedown-desk' ), t.reported_on_text, t.report, t.hold_until_text ) } )
					  )
					: null,
				box,
				el( 'div', { class: 'tkd-minor__form' }, num.wrap, day.wrap ),
				el(
					'div',
					{ class: 'tkd-actions' },
					save,
					btn( __( 'Clear the flag', 'takedown-desk' ), { kind: 'ghost', icon: 'x', disabled: ! t.can_clear, onclick: () => minorDialog( r, false ) } )
				),
				/* translators: %s: date. */
				t.can_clear ? null : el( 'p', { class: 'tkd-help', text: sprintf( __( 'You can clear the flag from %s, 1 year after the report.', 'takedown-desk' ), t.hold_until_text ) } ),
				el( 'p', { class: 'tkd-help', text: __( 'This is not legal advice.', 'takedown-desk' ) } )
			)
		);
	}

	function thread( list ) {
		return el(
			'ol',
			{ class: 'tkd-thread' },
			list.map( ( m ) => {
				const them = 'requester' === m.from;
				return el(
					'li',
					{ class: 'tkd-thread__msg' + ( them ? ' is-them' : '' ) },
					el( 'p', { class: 'tkd-thread__meta', text: ( them ? __( 'Requester', 'takedown-desk' ) : __( 'Moderator', 'takedown-desk' ) ) + ' · ' + fmtDate( m.at ) } ),
					para( m.text )
				);
			} )
		);
	}

	function contactBlock( r ) {
		if ( r.text_error ) {
			return muted( __( 'Not available', 'takedown-desk' ) );
		}
		const c = det.contact;
		if ( ! c ) {
			return btn( __( 'Show contact details', 'takedown-desk' ), {
				icon: 'eye',
				onclick: ( e ) => {
					const b = e.currentTarget;
					busy( b, true );
					api( base() + '/contact' ).then(
						( res ) => {
							det.contact = res;
							reload( '#tkd-contact' ); // Reload so the record shows the "contact viewed" entry.
						},
						( err ) => {
							busy( b, false );
							toast( errMsg( err ), 'error' );
						}
					);
				},
			} );
		}
		return el(
			'div',
			{ class: 'tkd-contact', id: 'tkd-contact', tabindex: '-1' },
			c.email ? el( 'a', { class: 'tkd-inline', href: 'mailto:' + c.email, text: c.email } ) : null,
			c.phone ? el( 'a', { class: 'tkd-inline', href: 'tel:' + String( c.phone ).replace( /[^0-9+]/g, '' ), text: c.phone } ) : null,
			c.address ? para( c.address ) : null
		);
	}

	function matchCard() {
		const link = field( { name: 'match', label: __( "Paste the item's link or ID", 'takedown-desk' ), attrs: { autocomplete: 'off' } } );
		const type = field( {
			name: 'match_type',
			type: 'select',
			label: __( 'Item type', 'takedown-desk' ),
			help: __( 'Used when you paste an ID.', 'takedown-desk' ),
			options: [
				[ 'post', __( 'Post or page', 'takedown-desk' ) ],
				[ 'attachment', __( 'Media file', 'takedown-desk' ) ],
				[ 'comment', __( 'Comment', 'takedown-desk' ) ],
			],
		} );
		const go = btn( __( 'Match item', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'link', disabled: true } );
		link.input.addEventListener( 'input', () => {
			go.disabled = ! link.input.value.trim();
		} );
		const form = el(
			'form',
			{
				class: 'tkd-form',
				novalidate: true,
				onsubmit: ( e ) => {
					e.preventDefault();
					const v = link.input.value.trim();
					const data = /^\d+$/.test( v ) ? { object_type: type.input.value, object_id: parseInt( v, 10 ) } : { url: v };
					busy( go, true );
					api( base() + '/match', 'POST', data ).then(
						() => {
							toast( __( 'Item matched.', 'takedown-desk' ) );
							loadDetail( det.id, '#tkd-copies-h', true, true );
						},
						( err ) => {
							busy( go, false );
							link.set( 'tkdesk_item_not_found' === err.code ? __( 'No item found at that link.', 'takedown-desk' ) : errMsg( err ) );
							link.input.focus();
						}
					);
				},
			},
			el( 'div', { class: 'tkd-grid2' }, link.wrap, type.wrap ),
			el( 'div', { class: 'tkd-actions' }, go )
		);
		return el(
			'section',
			{ class: 'tkd-card tkd-card--warn', 'aria-labelledby': 'tkd-match-h' },
			cardHead( 'link', __( 'Item not matched', 'takedown-desk' ), 'orange', 'tkd-match-h' ),
			el( 'div', { class: 'tkd-card__body' }, el( 'p', { text: __( 'We could not match the link to an item automatically.', 'takedown-desk' ) } ), form )
		);
	}

	/* Copies: the finder runs once per visit for open requests with a matched item. */
	function findCopies() {
		const id = det.id;
		if ( ! det.r || ! det.r.object || ! isOpen( det.r ) ) {
			return;
		}
		det.copies = { loading: true };
		redrawCopies();
		api( base() + '/copies', 'POST' ).then(
			( res ) => {
				if ( id !== det.id ) {
					return;
				}
				det.r.items = res.items || [];
				det.r.index = res.index || det.r.index;
				preselect( det.r.items );
				det.copies = { done: true };
				redrawCopies();
				updateCount();
			},
			() => {
				if ( id === det.id ) {
					det.copies = { error: true };
					redrawCopies();
				}
			}
		);
	}

	function redrawCopies() {
		const old = det.active && panel.querySelector( '#tkd-copies' );
		if ( old ) {
			const had = old.contains( document.activeElement );
			const fresh = copiesCard();
			old.replaceWith( fresh );
			if ( had ) {
				fresh.querySelector( 'h2' ).focus();
			}
		}
	}

	function copiesCard() {
		const r = det.r;
		const items = r.items || [];
		const st = det.copies || {};
		const idx = r.index;
		const body = [];
		if ( st.loading ) {
			body.push( el( 'p', { class: 'tkd-loading', role: 'status' }, el( 'span', { class: 'tkd-spinner', 'aria-hidden': 'true' } ), __( 'Looking for copies...', 'takedown-desk' ) ) );
		}
		if ( st.error ) {
			body.push(
				el(
					'div',
					{ class: 'tkd-alert tkd-alert--error', role: 'alert' },
					el( 'span', { class: 'tkd-alert__ico' }, icon( 'octagon' ) ),
					el( 'p', { class: 'tkd-alert__msg', text: __( 'The copy search failed. You can still remove the reported item.', 'takedown-desk' ) } ),
					btn( __( 'Search again', 'takedown-desk' ), { icon: 'refresh', onclick: findCopies } )
				)
			);
		}
		if ( idx && idx.total && idx.done < idx.total ) {
			const pct = Math.floor( ( idx.done / idx.total ) * 100 );
			body.push(
				el(
					'div',
					{ class: 'tkd-index' },
					/* translators: %d: percentage of media files indexed. */
					el( 'p', { id: 'tkd-index-note', class: 'tkd-help', text: sprintf( __( 'Media index %d%% complete. Results may be partial.', 'takedown-desk' ), pct ) } ),
					el( 'progress', { max: '100', value: pct, 'aria-labelledby': 'tkd-index-note' } )
				)
			);
		}
		if ( items.length ) {
			body.push( el( 'ul', { class: 'tkd-copies' }, items.map( copyRow ) ) );
		}
		if ( st.done && ! items.some( ( it ) => 'reported' !== it.role ) ) {
			/* translators: %d: number of files checked. */
			body.push( el( 'p', { class: 'tkd-note' }, icon( 'done' ), el( 'span', { text: sprintf( __( 'No copies found. We checked %d files.', 'takedown-desk' ), ( idx && idx.done ) || 0 ) } ) ) );
		}
		return el(
			'section',
			{ class: 'tkd-card', id: 'tkd-copies', 'aria-labelledby': 'tkd-copies-h', 'aria-busy': st.loading ? 'true' : null },
			cardHead( 'layers', __( 'Copies on this site', 'takedown-desk' ), 'green', 'tkd-copies-h' ),
			el( 'div', { class: 'tkd-card__body' }, body )
		);
	}

	function copyRow( it ) {
		const done = acted( it );
		const pickable = isOpen( det.r ) && ! done;
		const sel = det.draft.sel;
		const role = ROLES[ it.role ];
		const badges = hooks.applyFilters( 'tkdesk.copies.badges', role ? [ { text: role[ 0 ], tone: role[ 1 ] } ] : [], it );
		const status = ACTIONS[ it.action ];
		const failed = 'failed' === it.action;
		let tone = 'hidden' === it.action ? 'amber' : 'neutral';
		if ( done || failed ) {
			tone = done ? 'green' : 'red';
		}
		const main = [
			el( 'span', { class: 'tkd-copy__label', text: it.label } ),
			el(
				'span',
				{ class: 'tkd-copy__badges' },
				badges.map( ( b ) => el( 'span', { class: 'tkd-chip tkd-chip--' + ( b.tone || 'neutral' ), text: b.text } ) ),
				status ? el( 'span', { class: 'tkd-chip tkd-chip--' + tone }, icon( done ? 'check' : ( failed && 'x' ) || ( 'hidden' === it.action && 'shield' ) || 'undo' ), status ) : null
			),
			/* translators: %s: date. */
			'offline' === it.action && it.purge_after ? el( 'span', { class: 'tkd-help', text: sprintf( __( 'Can be restored until %s', 'takedown-desk' ), fmtDate( it.purge_after ) ) } ) : null,
		];
		return el(
			'li',
			{ class: 'tkd-copy' + ( done ? ' is-done' : '' ) },
			pickable
				? el(
						'label',
						{ class: 'tkd-copy__main' },
						el( 'input', {
							type: 'checkbox',
							checked: sel.has( it.id ),
							onchange: ( e ) => {
								if ( e.target.checked ) {
									sel.add( it.id );
								} else {
									sel.delete( it.id );
								}
								updateCount();
							},
						} ),
						el( 'span', { class: 'tkd-copy__text' }, main )
				  )
				: el( 'div', { class: 'tkd-copy__main' }, el( 'span', { class: 'tkd-copy__text' }, main ) ),
			el(
				'span',
				{ class: 'tkd-copy__tools' },
				it.url
					? /* translators: %s: item name. */ el( 'a', { class: 'tkd-iconbtn', href: safe( it.url ), target: '_blank', rel: 'noopener noreferrer', 'aria-label': sprintf( __( 'Open %s (opens in a new tab)', 'takedown-desk' ), it.label ) }, icon( 'ext' ) )
					: null,
				cfg.canManage && it.can_restore ? btn( __( 'Restore item', 'takedown-desk' ), { icon: 'undo', onclick: () => restoreDialog( it ) } ) : null
			)
		);
	}

	/* Decision: what to remove, how, the note, and the actions. */
	function decisionCard() {
		const r = det.r;
		const d = det.draft;
		const imgs = r.images || [];
		const parts = [];
		// Hide while reviewing: the reported item goes offline now, the decision comes later.
		const rep = ( r.items || [] ).find( ( it ) => 'reported' === it.role && ! acted( it ) );
		if ( rep ) {
			const hidden = 'hidden' === rep.action;
			let showAgain = null;
			if ( hidden && ! r.minor ) {
				showAgain = btn( __( 'Show it again', 'takedown-desk' ), { icon: 'eye', onclick: ( e ) => act( e.currentTarget, 'unhide', __( 'The item is back on the site.', 'takedown-desk' ), '#tkd-decision-h' ) } );
			}
			let lead = __( 'Take the item offline now and decide later. The request stays open, and you can show the item again with one click.', 'takedown-desk' );
			if ( hidden ) {
				lead = r.minor ? __( 'Visitors cannot see it. It stays offline because the person in the image was under 18.', 'takedown-desk' ) : __( 'Visitors cannot see it. The request stays open until you decide.', 'takedown-desk' );
			}
			parts.push(
				el(
					'div',
					{ class: 'tkd-alert tkd-hide' + ( hidden ? ' tkd-alert--ok' : '' ) },
					el( 'span', { class: 'tkd-alert__ico' }, icon( hidden ? 'shield' : 'eye' ) ),
					el(
						'div',
						{ class: 'tkd-alert__msg' },
						el( 'p', { class: 'tkd-strong', text: hidden ? __( 'Hidden while you review', 'takedown-desk' ) : __( 'Hide it while you review?', 'takedown-desk' ) } ),
						el( 'p', { text: lead } )
					),
					hidden
						? showAgain
						: btn( __( 'Hide while reviewing', 'takedown-desk' ), { icon: 'shield', onclick: ( e ) => act( e.currentTarget, 'hide', __( 'Item hidden. The request stays open.', 'takedown-desk' ), '#tkd-decision-h' ) } )
				)
			);
		}
		if ( 'needs_info' === r.status ) {
			parts.push(
				el(
					'div',
					{ class: 'tkd-alert tkd-alert--amber' },
					el( 'span', { class: 'tkd-alert__ico' }, icon( 'hourglass' ) ),
					el(
						'div',
						{ class: 'tkd-alert__msg' },
						el( 'p', { class: 'tkd-strong', text: __( 'Waiting for the requester to reply.', 'takedown-desk' ) } ),
						el( 'p', { text: noPause( r ) } )
					),
					btn( __( 'Continue without waiting', 'takedown-desk' ), { icon: 'arrow', onclick: ( e ) => act( e.currentTarget, 'continue', __( 'The request is back in review.', 'takedown-desk' ), '#tkd-decision-h', 'resume' ) } )
				)
			);
		}
		if ( imgs.length ) {
			const box = el(
				'fieldset',
				{ class: 'tkd-images', hidden: 'images' !== d.scope },
				el( 'legend', { class: 'tkd-sr', text: __( 'Images in this item', 'takedown-desk' ) } ),
				imgs.map( ( im ) =>
					el(
						'label',
						{ class: 'tkd-image' },
						el( 'input', {
							type: 'checkbox',
							checked: d.imgs.has( im.attachment_id ),
							onchange: ( e ) => {
								if ( e.target.checked ) {
									d.imgs.add( im.attachment_id );
								} else {
									d.imgs.delete( im.attachment_id );
								}
								updateCount();
							},
						} ),
						im.thumb_url ? el( 'img', { class: 'tkd-image__thumb', src: safe( im.thumb_url ), alt: '', width: '48', height: '48', loading: 'lazy' } ) : el( 'span', { class: 'tkd-image__thumb' }, icon( 'file' ) ),
						el( 'span', { class: 'tkd-image__label', text: im.label } )
					)
				)
			);
			parts.push(
				radios(
					'tkd-scope',
					__( 'What to remove', 'takedown-desk' ),
					[
						[ 'item', __( 'The whole item', 'takedown-desk' ) ],
						[ 'images', __( 'Only the selected images', 'takedown-desk' ) ],
					],
					d.scope,
					( v ) => {
						d.scope = v;
						box.hidden = 'images' !== v;
						updateCount();
					}
				),
				box
			);
		}
		if ( r.minor ) {
			d.mode = 'offline';
		}
		parts.push(
			radios(
				'tkd-mode',
				__( 'How', 'takedown-desk' ),
				r.minor
					? [ [ 'offline', __( 'Take offline (the files are kept)', 'takedown-desk' ) ] ]
					: [
							/* translators: %d: number of days. */
							[ 'offline', sprintf( __( 'Take offline (can be restored for %d days)', 'takedown-desk' ), r.restore_days || 30 ) ],
							[ 'delete', __( 'Delete permanently now', 'takedown-desk' ) ],
					  ],
				d.mode,
				( v ) => {
					d.mode = v;
				}
			),
			r.minor ? el( 'p', { class: 'tkd-help', text: __( 'Permanent deletion is off because the person in the image was under 18.', 'takedown-desk' ) } ) : null
		);
		const late = r.due_at && r.due_at < now();
		let lateHelp = late ? __( 'This request is past its deadline. Add the reason for the delay to your note.', 'takedown-desk' ) : null;
		if ( late && isDmca( r ) ) {
			lateHelp = __( 'This notice is past its target. Add the reason for the delay to your note.', 'takedown-desk' );
		}
		const note = field( {
			name: 'decision_note',
			type: 'textarea',
			label: __( 'Decision note (saved to the record)', 'takedown-desk' ),
			help: lateHelp,
			value: d.note,
			required: true,
		} );
		note.input.addEventListener( 'input', () => {
			d.note = note.input.value;
			if ( d.note.trim().length >= 10 ) {
				note.set( '' );
			}
		} );
		const actions = hooks.applyFilters(
			'tkdesk.request.actions',
			[
				[ 'new', 'in_review' ].includes( r.status )
					? { id: 'spam', label: __( 'Mark as spam', 'takedown-desk' ), icon: 'flag', run: () => act( null, 'spam', __( 'Marked as spam. No email was sent.', 'takedown-desk' ), '.tkd-banner', 'reopen' ) }
					: null,
				{ id: 'note', label: __( 'Add a note', 'takedown-desk' ), icon: 'note', run: noteDialog },
			].filter( Boolean ),
			r
		);
		const none = ! count();
		const remove = btn( removeLabel(), { id: 'tkd-remove', kind: 'danger', icon: 'trash', disabled: none, onclick: () => startRemove( note ) } );
		remove.setAttribute( 'aria-describedby', 'tkd-remove-help' );
		return el(
			'section',
			{ class: 'tkd-card tkd-card--decision', id: 'tkd-decision', 'aria-labelledby': 'tkd-decision-h' },
			cardHead( 'scale', __( 'Decision', 'takedown-desk' ), 'blue', 'tkd-decision-h' ),
			el(
				'div',
				{ class: 'tkd-card__body tkd-form' },
				parts,
				note.wrap,
				bySite( r ) ? el( 'p', { class: 'tkd-help', text: NO_REQUESTER } ) : null,
				el(
					'div',
					{ class: 'tkd-decision__actions' },
					remove,
					[ 'new', 'in_review' ].includes( r.status ) && ! bySite( r ) ? btn( __( 'Ask requester for details', 'takedown-desk' ), { icon: 'ask', onclick: askDialog } ) : null,
					btn( __( 'Do not remove', 'takedown-desk' ), { icon: 'ban', onclick: declineDialog } ),
					moreMenu( r, actions )
				),
				el( 'p', {
					id: 'tkd-remove-help',
					class: 'tkd-help',
					hidden: ! none,
					text: imgs.length ? __( 'Choose at least one image above, or switch to The whole item.', 'takedown-desk' ) : __( 'Choose at least one item under Copies on this site.', 'takedown-desk' ),
				} )
			)
		);
	}

	// Only the selected images: the server needs at least one image, so none checked counts as 0.
	const imageScope = () => 'images' === det.draft.scope && ( det.r.images || [] ).length;

	function count() {
		const items = chosen();
		if ( ! imageScope() ) {
			return items.length;
		}
		return det.draft.imgs.size ? det.draft.imgs.size + items.filter( ( it ) => 'reported' !== it.role ).length : 0;
	}

	function removeLabel() {
		const n = count();
		/* translators: %d: number of items. */
		return n ? sprintf( _n( 'Remove %d item', 'Remove %d items', n, 'takedown-desk' ), n ) : __( 'Select what to remove', 'takedown-desk' );
	}

	function updateCount() {
		const b = panel.querySelector( '#tkd-remove' );
		const help = panel.querySelector( '#tkd-remove-help' );
		if ( b ) {
			b.querySelector( '.tkd-btn__label' ).textContent = removeLabel();
			b.disabled = ! count();
		}
		if ( help ) {
			help.hidden = !! count();
		}
	}

	// Overflow menu as a disclosure: Escape and focus leaving close it.
	function moreMenu( r, actions ) {
		const id = uid();
		const list = el( 'ul', { class: 'tkd-menu', id, hidden: true } );
		const toggle = el(
			'button',
			{ type: 'button', class: 'tkd-btn tkd-btn--ghost tkd-btn--icon', 'aria-label': __( 'More actions', 'takedown-desk' ), 'aria-expanded': 'false', 'aria-controls': id, onclick: () => set( list.hidden ) },
			icon( 'more' )
		);
		function set( on ) {
			list.hidden = ! on;
			toggle.setAttribute( 'aria-expanded', String( on ) );
			if ( on && list.firstChild ) {
				list.firstChild.firstChild.focus();
			}
		}
		add( list, [
			actions.map( ( a ) =>
				el(
					'li',
					{},
					el(
						'button',
						{
							type: 'button',
							class: 'tkd-menu__item',
							onclick: () => {
								set( false );
								toggle.focus();
								a.run( r );
							},
						},
						icon( a.icon || 'arrow' ),
						el( 'span', { text: a.label } )
					)
				)
			),
		] );
		const wrap = el(
			'div',
			{
				class: 'tkd-more',
				onkeydown: ( e ) => {
					if ( 'Escape' === e.key && ! list.hidden ) {
						e.preventDefault();
						set( false );
						toggle.focus();
					}
				},
				onfocusout: ( e ) => {
					if ( ! wrap.contains( e.relatedTarget ) ) {
						set( false );
					}
				},
			},
			toggle,
			list
		);
		return wrap;
	}

	// One-click state changes: reopen, continue, spam, hide, unhide. With undo (the path that reverses it),
	// the toast offers Undo for 8 seconds.
	function act( button, path, done, focusSel, undo ) {
		const id = det.id;
		const url = base() + '/';
		const after = () => {
			if ( det.id === id ) {
				reload( focusSel );
			}
			syncCounts();
		};
		const undone = {
			reopen: __( 'Undone. The request is open again.', 'takedown-desk' ),
			resume: __( 'Undone. Waiting for details again.', 'takedown-desk' ),
		};
		busy( button, true );
		api( url + path, 'POST' ).then(
			( res ) => {
				const failed = ( ( res && res.results ) || [] ).filter( ( x ) => ! x.ok );
				failed.forEach( ( x ) => toast( x.message, 'error' ) );
				if ( ! failed.length ) {
					toast(
						done,
						null,
						undo &&
							( () =>
								api( url + undo, 'POST' ).then(
									() => {
										toast( undone[ undo ] );
										after();
									},
									( e ) => toast( errMsg( e ), 'error' )
								) )
					);
				}
				after();
			},
			( e ) => {
				busy( button, false );
				toast( errMsg( e ), 'error' );
			}
		);
	}

	function startRemove( note ) {
		const d = det.draft;
		const n = count();
		if ( d.note.trim().length < 10 ) {
			note.set( __( 'Add a short note about your decision.', 'takedown-desk' ) );
			note.input.focus();
			return;
		}
		if ( ! n ) {
			return;
		}
		const del = 'delete' === d.mode;
		const imgs = imageScope();
		const names = ( imgs ? ( det.r.images || [] ).filter( ( im ) => d.imgs.has( im.attachment_id ) ).map( ( im ) => im.label ) : [] ).concat(
			chosen()
				.filter( ( it ) => ! imgs || 'reported' !== it.role )
				.map( ( it ) => it.label )
		);
		/* translators: %s: date. */
		const until = sprintf( _n( 'It disappears from the site now and can be restored until %s.', 'They disappear from the site now and can be restored until %s.', n, 'takedown-desk' ), fmtDate( now() + ( det.r.restore_days || 30 ) * DAY ) );
		const what = det.r.minor ? __( 'It disappears from the site now. The files are kept.', 'takedown-desk' ) : until;
		const lines = [ del ? _n( 'It is deleted permanently. This cannot be undone.', 'They are deleted permanently. This cannot be undone.', n, 'takedown-desk' ) : what ];
		if ( ! bySite( det.r ) ) {
			lines.push( __( 'The requester gets the outcome email.', 'takedown-desk' ) );
		}
		const descId = uid();
		const dlg = dialog( {
			/* translators: %d: number of items. */
			title: sprintf( _n( 'Remove %d item?', 'Remove %d items?', n, 'takedown-desk' ), n ),
			descId,
			cancelFirst: true,
			body: el(
				'div',
				{ id: descId, class: 'tkd-confirm' },
				el( 'p', {}, el( 'strong', { text: del ? __( 'Delete permanently:', 'takedown-desk' ) : __( 'Take offline:', 'takedown-desk' ) } ), ' ', el( 'span', { text: names.join( ', ' ) + '.' } ) ),
				el( 'p', { class: del ? 'tkd-danger-text' : null }, del ? icon( 'alert' ) : null, el( 'span', { text: lines.join( ' ' ) } ) )
			),
			buttons: [
				btn( __( 'Remove', 'takedown-desk' ), {
					kind: 'danger',
					icon: 'trash',
					onclick: () => {
						dlg.close();
						doRemove();
					},
				} ),
			],
		} );
	}

	function doRemove() {
		const d = det.draft;
		const button = panel.querySelector( '#tkd-remove' );
		busy( button, true );
		speak( __( 'Removing items. Please wait.', 'takedown-desk' ) );
		api( base() + '/remove', 'POST', { items: chosen().map( ( it ) => it.id ), scope: d.scope, images: imageScope() ? [ ...d.imgs ] : [], mode: d.mode, note: d.note.trim() } ).then(
			( res ) => {
				const labels = {};
				( det.r.items || [] ).forEach( ( it ) => {
					labels[ it.id ] = it.label;
				} );
				const results = res.results || [];
				det.results = results;
				results
					.filter( ( x ) => ! x.ok )
					.forEach( ( x ) =>
						toast(
							/* translators: 1: item name, 2: reason. */
							sprintf( __( 'Could not remove %1$s: %2$s. Nothing else was changed.', 'takedown-desk' ), labels[ x.item_id ] || '#' + x.item_id, String( x.message || '' ).replace( /\.$/, '' ) ),
							'error'
						)
					);
				/* translators: 1: number of items removed, 2: number of items tried. */
				speak( sprintf( __( '%1$d of %2$d items removed.', 'takedown-desk' ), results.filter( ( x ) => x.ok ).length, results.length ) );
				const closed = res.request && 'removed' === res.request.status;
				if ( closed ) {
					d.note = '';
				}
				reload( closed ? '.tkd-banner' : '#tkd-results-h' );
				syncCounts();
			},
			( e ) => {
				busy( button, false );
				toast( errMsg( e ), 'error' );
			}
		);
	}

	function askDialog() {
		const q = field( {
			name: 'question',
			type: 'textarea',
			rows: 4,
			label: __( 'What do you need to know?', 'takedown-desk' ),
			help: noPause( det.r ),
			value: __( 'Could you tell us which image on the page you mean, or add a direct link?', 'takedown-desk' ),
			attrs: { 'data-autofocus': true },
		} );
		q.input.addEventListener( 'input', () => q.input.value.trim().length >= 10 && q.set( '' ) );
		const box = el( 'div' );
		const send = btn( __( 'Send question', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'send' } );
		const d = dialog( {
			title: __( 'Ask requester for details', 'takedown-desk' ),
			body: [ box, q.wrap ],
			buttons: [ send ],
			onsubmit() {
				const message = q.input.value.trim();
				if ( q.set( message.length < 10 ? __( 'Write a short question for the requester.', 'takedown-desk' ) : '' ) ) {
					q.input.focus();
					return;
				}
				busy( send, true );
				api( base() + '/ask', 'POST', { message } ).then(
					() => {
						d.returnTo = null;
						d.close();
						toast( __( 'Question sent to the requester.', 'takedown-desk' ) );
						reload( '#tkd-decision-h' );
					},
					( e ) => {
						busy( send, false );
						showError( box, [], errMsg( e ) );
					}
				);
			},
		} );
	}

	function declineDialog() {
		const r = det.r;
		let host = cfg.siteUrl || '';
		try {
			host = new URL( host ).host;
		} catch ( e ) {
			// Keep the raw value.
		}
		// Plain-words option, then the sentence the requester reads (plan 3.9).
		const R = {
			not_found: [ __( 'We could not locate the item', 'takedown-desk' ), __( 'We could not find the image from the information in the request.', 'takedown-desk' ) ],
			/* translators: %s: site name. */
			not_on_site: [ __( 'The item is not hosted on this site', 'takedown-desk' ), sprintf( __( 'The link is not hosted on %s, so we cannot remove it. Contact the site that hosts it.', 'takedown-desk' ), host ) ],
			incomplete: [ __( 'Required information is still missing after we asked', 'takedown-desk' ), __( 'The request was missing information we need and we did not hear back.', 'takedown-desk' ) ],
			not_intimate: [ __( 'The item does not show what the request describes', 'takedown-desk' ), __( 'The item at that link does not show what the request describes.', 'takedown-desk' ) ],
			/* translators: %s: placeholder for the other request ID. */
			duplicate: [ __( 'Already handled in another request', 'takedown-desk' ), sprintf( __( 'We already handled this item under request %s.', 'takedown-desk' ), __( '[request ID]', 'takedown-desk' ) ) ],
			// The requester reads a neutral sentence, never an accusation.
			bad_faith: [ __( 'Not made in good faith', 'takedown-desk' ), __( 'We could not confirm the request with the information we have.', 'takedown-desk' ) ],
			restored_after_review: [ __( 'Put back after a further review', 'takedown-desk' ), __( 'After a further review we put the item back. Reply if you have more information.', 'takedown-desk' ) ],
		};
		// The reasons for this request's type come with the request; older servers send none, so the list above stands in.
		const list = Array.isArray( r.decline_reasons ) && r.decline_reasons.length ? r.decline_reasons : Object.keys( R ).map( ( key ) => ( { key, label: R[ key ][ 0 ] } ) );
		const reason = field( {
			name: 'reason',
			type: 'select',
			label: __( 'Reason', 'takedown-desk' ),
			options: [ [ '', __( 'Choose a reason', 'takedown-desk' ) ] ].concat( list.map( ( x ) => [ x.key, x.label ] ) ),
			attrs: { 'data-autofocus': true },
		} );
		const note = field( { name: 'decline_note', type: 'textarea', required: true, label: __( 'Decision note (saved to the record)', 'takedown-desk' ), value: det.draft.note } );
		const msg = field( { name: 'message', type: 'textarea', label: __( 'Message to the requester (optional)', 'takedown-desk' ), help: __( 'Added to the email after the reason.', 'takedown-desk' ) } );
		const preview = el( 'div', { class: 'tkd-email' } );
		const draw = () => {
			const k = reason.input.value;
			const picked = list.find( ( x ) => x.key === k );
			let said = picked ? picked.label : __( '(Choose a reason)', 'takedown-desk' );
			if ( R[ k ] ) {
				said = R[ k ][ 1 ];
			}
			preview.replaceChildren(
				el(
					'p',
					{ class: 'tkd-email__subject' },
					el( 'span', { class: 'tkd-email__key', text: __( 'Subject', 'takedown-desk' ) } ),
					/* translators: %s: request ID. */
					el( 'span', { text: sprintf( __( 'Your removal request %s: Not removed', 'takedown-desk' ), r.public_id ) } )
				),
				para(
					[
						__( 'We reviewed your request and did not remove the item.', 'takedown-desk' ) + ' ' + /* translators: %s: reason sentence. */ sprintf( __( 'Reason: %s', 'takedown-desk' ), said ),
						msg.input.value.trim(),
						__( 'If you think this is a mistake, or you have more details, reply here:', 'takedown-desk' ) + ' ' + __( '[Private link]', 'takedown-desk' ),
					]
						.filter( Boolean )
						.join( '\n\n' )
				)
			);
		};
		draw();
		reason.input.addEventListener( 'change', () => {
			draw();
			if ( reason.input.value ) {
				reason.set( '' );
			}
		} );
		msg.input.addEventListener( 'input', draw );
		note.input.addEventListener( 'input', () => {
			det.draft.note = note.input.value;
			if ( note.input.value.trim().length >= 10 ) {
				note.set( '' );
			}
		} );
		// A hidden item: put it back (default) or keep it offline. Under 18 in the image it always stays offline.
		let restoreHidden = true;
		const hidden = ( r.items || [] ).some( ( it ) => 'hidden' === it.action );
		let ask = null;
		if ( hidden && r.minor ) {
			ask = el( 'p', { class: 'tkd-help', text: __( 'The item stays offline because the person in the image was under 18.', 'takedown-desk' ) } );
		} else if ( hidden ) {
			ask = radios(
				'tkd-restore-hidden',
				__( 'Restore the hidden item?', 'takedown-desk' ),
				[
					[ 'yes', __( 'Yes, put it back on the site', 'takedown-desk' ) ],
					[ 'no', __( 'No, keep it offline', 'takedown-desk' ) ],
				],
				'yes',
				( v ) => {
					restoreHidden = 'yes' === v;
				}
			);
		}
		const box = el( 'div' );
		const site = bySite( r ); // No requester, so no message and no email preview.
		const go = site
			? btn( __( 'Close request', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'ban' } )
			: btn( __( 'Close request and email requester', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'send' } );
		const d = dialog( {
			title: __( 'Do not remove', 'takedown-desk' ),
			wide: true,
			body: [
				box,
				reason.wrap,
				ask,
				note.wrap,
				site ? el( 'p', { class: 'tkd-help', text: NO_REQUESTER } ) : msg.wrap,
				// A copyright notice gets its own "Not removed" email (Settings, Email templates), so this preview is for removal requests only.
				site || isDmca( r ) ? null : el( 'div', { class: 'tkd-field' }, el( 'p', { class: 'tkd-label', text: __( 'Email preview', 'takedown-desk' ) } ), preview ),
			],
			buttons: [ go ],
			onsubmit() {
				const n = note.input.value.trim();
				const bad = [
					reason.set( reason.input.value ? '' : __( 'Choose a reason.', 'takedown-desk' ) ) && reason,
					note.set( n.length >= 10 ? '' : __( 'Add a short note about your decision.', 'takedown-desk' ) ) && note,
				].filter( Boolean );
				if ( bad.length ) {
					bad[ 0 ].input.focus();
					return;
				}
				busy( go, true );
				api( base() + '/decline', 'POST', { reason: reason.input.value, note: n, message: msg.input.value.trim(), restore_hidden: restoreHidden } ).then(
					() => {
						det.draft.note = '';
						d.returnTo = null;
						d.close();
						toast( site ? __( 'Request closed.', 'takedown-desk' ) : __( 'Request closed. The requester was emailed.', 'takedown-desk' ) );
						reload( '.tkd-banner' );
						syncCounts();
					},
					( e ) => {
						busy( go, false );
						showError( box, [], errMsg( e ) );
					}
				);
			},
		} );
	}

	function noteDialog() {
		const n = field( { name: 'record_note', type: 'textarea', rows: 4, required: true, label: __( 'Note (saved to the record)', 'takedown-desk' ), attrs: { 'data-autofocus': true } } );
		const box = el( 'div' );
		const save = btn( __( 'Add note', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'note', disabled: true } );
		n.input.addEventListener( 'input', () => {
			save.disabled = ! n.input.value.trim();
		} );
		const d = dialog( {
			title: __( 'Add a note', 'takedown-desk' ),
			body: [ box, n.wrap ],
			buttons: [ save ],
			onsubmit() {
				busy( save, true );
				api( base() + '/note', 'POST', { note: n.input.value.trim() } ).then(
					() => {
						d.returnTo = null;
						d.close();
						toast( __( 'Note added to the record.', 'takedown-desk' ) );
						reload( '#tkd-record-h' );
					},
					( e ) => {
						busy( save, false );
						showError( box, [], errMsg( e ) );
					}
				);
			},
		} );
	}

	function restoreDialog( it ) {
		const n = field( { name: 'restore_note', type: 'textarea', rows: 3, required: true, label: __( 'Why are you restoring it? (saved to the record)', 'takedown-desk' ), attrs: { 'data-autofocus': true } } );
		n.input.addEventListener( 'input', () => n.input.value.trim().length >= 10 && n.set( '' ) );
		const box = el( 'div' );
		const go = btn( __( 'Restore item', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'undo' } );
		const d = dialog( {
			/* translators: %s: item name. */
			title: sprintf( __( 'Restore %s?', 'takedown-desk' ), it.label ),
			body: [
				el( 'p', {
					class: 'tkd-lead',
					text: bySite( det.r )
						? __( 'The item goes back where it was. If no removed item remains, the request closes as not removed.', 'takedown-desk' )
						: __( 'The item goes back where it was. If no removed item remains, the request closes as not removed and the requester is emailed.', 'takedown-desk' ),
				} ),
				box,
				n.wrap,
			],
			buttons: [ go ],
			onsubmit() {
				const note = n.input.value.trim();
				if ( n.set( note.length < 10 ? __( 'Add a short note about your decision.', 'takedown-desk' ) : '' ) ) {
					n.input.focus();
					return;
				}
				busy( go, true );
				api( base() + '/restore', 'POST', { items: [ it.id ], note } ).then(
					( res ) => {
						const failed = ( res.results || [] ).filter( ( x ) => ! x.ok );
						d.returnTo = null;
						d.close();
						failed.forEach( ( x ) =>
							/* translators: 1: item name, 2: reason. */
							toast( sprintf( __( 'Could not restore %1$s: %2$s', 'takedown-desk' ), it.label, x.message || '' ), 'error' )
						);
						if ( ! failed.length ) {
							toast( __( 'Item restored.', 'takedown-desk' ) );
						}
						reload( '#tkd-copies-h' );
						syncCounts();
					},
					( e ) => {
						busy( go, false );
						showError( box, [], errMsg( e ) );
					}
				);
			},
		} );
	}

	// Identical neighbors (same request, event, text and person) show as one entry with a count, "Copy search ×13".
	// Display only: the record and its export keep every entry.
	function collapse( rows ) {
		return rows.reduce( ( out, x ) => {
			const last = out[ out.length - 1 ];
			if ( last && [ 'request_id', 'event', 'text', 'actor' ].every( ( k ) => last[ k ] === x[ k ] ) ) {
				last.times.push( x.time );
			} else {
				out.push( Object.assign( {}, x, { times: [ x.time ] } ) );
			}
			return out;
		}, [] );
	}

	const when = ( x, withYear ) =>
		x.times.length > 1
			? /* translators: 1: time of the first entry, 2: time of the last entry. */ sprintf( __( '%1$s to %2$s', 'takedown-desk' ), fmtDate( Math.min( ...x.times ), withYear ), fmtDate( Math.max( ...x.times ), withYear ) )
			: fmtDate( x.time, withYear );

	const repeats = ( x ) =>
		x.times.length > 1
			? el(
					'span',
					{ class: 'tkd-repeat' },
					el( 'span', { 'aria-hidden': 'true', text: '×' + x.times.length } ),
					/* translators: %d: number of identical entries shown as one. */
					el( 'span', { class: 'tkd-sr', text: sprintf( _n( '(%d entry)', '(%d entries)', x.times.length, 'takedown-desk' ), x.times.length ) } )
			  )
			: null;

	function recordCard( r ) {
		const log = collapse( r.log || [] );
		const exp = cfg.exportUrl ? cfg.exportUrl + ( cfg.exportUrl.includes( '?' ) ? '&' : '?' ) + 'request=' + r.id : '';
		const tone = ( ev ) => {
			if ( /remov|offline|delet|image|verified/.test( ev ) ) {
				return 'green';
			}
			if ( /overdue|fail|key|still_reach/.test( ev ) ) {
				return 'red';
			}
			return /remind|info|ask|spam|declin|hidden|unchecked/.test( ev ) ? 'amber' : 'blue';
		};
		return el(
			'section',
			{ class: 'tkd-card', id: 'tkd-record', 'aria-labelledby': 'tkd-record-h' },
			cardHead( 'list', __( 'Record', 'takedown-desk' ), 'cream', 'tkd-record-h', exp ? linkChip( __( 'Export this request (CSV)', 'takedown-desk' ), exp ) : null ),
			el(
				'div',
				{ class: 'tkd-card__body' },
				log.length
					? el(
							'ol',
							{ class: 'tkd-log' },
							log.map( ( x ) =>
								el(
									'li',
									{ class: 'tkd-log__row tkd-tone--' + tone( x.event || '' ) },
									el( 'span', { class: 'tkd-log__dot', 'aria-hidden': 'true' } ),
									el(
										'div',
										{ class: 'tkd-log__main' },
										el( 'p', { class: 'tkd-log__text' }, x.text, repeats( x ) ),
										el( 'p', { class: 'tkd-log__meta', text: [ when( x ), x.actor ].filter( Boolean ).join( ' · ' ) } )
									)
								)
							)
					  )
					: muted( __( 'Nothing recorded yet.', 'takedown-desk' ) )
			)
		);
	}

	/* ---------- Record of actions (#log) ---------- */
	const DAY = 86400;
	const ymd = ( ts ) => {
		const d = new Date( ts * 1000 );
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	};
	const unixDay = ( s, end ) => Math.floor( new Date( s + ( end ? 'T23:59:59' : 'T00:00:00' ) ).getTime() / 1000 ) || 0;
	// view: `key` (Key events, the default) or `all` (Everything); picking one event type shows Everything.
	const logq = { from: '', to: '', request: '', event: '', page: 1, view: 'key' };
	const resetLog = () => Object.assign( logq, { from: ymd( now() - 90 * DAY ), to: ymd( now() ), request: '', event: '', page: 1 } );
	const EVENTS = [
		[ '', __( 'All events', 'takedown-desk' ) ],
		[ 'received', __( 'Request received', 'takedown-desk' ) ],
		[ 'opened', __( 'Opened', 'takedown-desk' ) ],
		[ 'assigned', __( 'Assigned', 'takedown-desk' ) ],
		[ 'matched_item', __( 'Item matched', 'takedown-desk' ) ],
		[ 'copies_found', __( 'Copy search', 'takedown-desk' ) ],
		[ 'contact_viewed', __( 'Contact details viewed', 'takedown-desk' ) ],
		[ 'info_requested', __( 'Asked for details', 'takedown-desk' ) ],
		[ 'info_received', __( 'Requester sent details', 'takedown-desk' ) ],
		[ 'item_offline', __( 'Item taken offline', 'takedown-desk' ) ],
		[ 'item_image_removed', __( 'Images removed', 'takedown-desk' ) ],
		[ 'item_deleted', __( 'Item deleted', 'takedown-desk' ) ],
		[ 'item_failed', __( 'Removal failed', 'takedown-desk' ) ],
		[ 'removed', __( 'Closed as removed', 'takedown-desk' ) ],
		[ 'declined', __( 'Closed as not removed', 'takedown-desk' ) ],
		[ 'restored', __( 'Item restored', 'takedown-desk' ) ],
		[ 'reopened', __( 'Reopened', 'takedown-desk' ) ],
		[ 'marked_spam', __( 'Marked as spam', 'takedown-desk' ) ],
		[ 'note_added', __( 'Note added', 'takedown-desk' ) ],
		[ 'reminder_24', __( '24-hour reminder', 'takedown-desk' ) ],
		[ 'reminder_40', __( '8-hours-left reminder', 'takedown-desk' ) ],
		[ 'overdue', __( 'Overdue', 'takedown-desk' ) ],
		[ 'email_sent', __( 'Email sent', 'takedown-desk' ) ],
		[ 'email_failed', __( 'Email failed', 'takedown-desk' ) ],
		[ 'email_off', __( 'Email turned off', 'takedown-desk' ) ],
		[ 'requester_not_notified', __( 'Requester not emailed', 'takedown-desk' ) ],
		[ 'exported', __( 'Record exported', 'takedown-desk' ) ],
		[ 'settings_changed', __( 'Settings changed', 'takedown-desk' ) ],
	];

	function renderLog() {
		if ( ! logq.from ) {
			resetLog();
		}
		const ui = {};
		ui.fields = {
			from: field( { name: 'log_from', type: 'date', label: __( 'From', 'takedown-desk' ), value: logq.from } ),
			to: field( { name: 'log_to', type: 'date', label: __( 'To', 'takedown-desk' ), value: logq.to } ),
			request: field( { name: 'log_request', label: __( 'Request ID', 'takedown-desk' ), value: logq.request, attrs: { autocomplete: 'off', placeholder: 'TD-2611-0047' } } ),
			event: field( { name: 'log_event', type: 'select', label: __( 'Event type', 'takedown-desk' ), options: EVENTS, value: logq.event } ),
		};
		const apply = () => {
			Object.keys( ui.fields ).forEach( ( k ) => {
				logq[ k ] = ui.fields[ k ].input.value.trim();
			} );
			logq.view = logq.event ? 'all' : logq.view;
			[ ...ui.view.children ].forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b.dataset.view === logq.view ) ) );
			logq.page = 1;
			loadLog( ui, true );
		};
		ui.view = el(
			'div',
			{ class: 'tkd-seg', role: 'group', 'aria-label': __( 'Entries to show', 'takedown-desk' ) },
			[
				[ 'key', __( 'Key events', 'takedown-desk' ) ],
				[ 'all', __( 'Everything', 'takedown-desk' ) ],
			].map( ( v ) =>
				el(
					'button',
					{
						type: 'button',
						class: 'tkd-seg__btn',
						'data-view': v[ 0 ],
						'aria-pressed': String( v[ 0 ] === logq.view ),
						onclick: () => {
							logq.view = v[ 0 ];
							if ( 'key' === v[ 0 ] ) {
								ui.fields.event.input.value = ''; // Key events cover several types, so a single type gives way.
							}
							apply();
						},
					},
					v[ 1 ]
				)
			)
		);
		ui.clearRequest = () => {
			ui.fields.request.input.value = '';
			apply();
			ui.fields.request.input.focus();
		};
		let timer = 0;
		[ 'from', 'to', 'event' ].forEach( ( k ) => ui.fields[ k ].input.addEventListener( 'change', apply ) );
		ui.fields.request.input.addEventListener( 'input', () => {
			clearTimeout( timer );
			timer = setTimeout( apply, 400 );
		} );
		ui.export = el( 'a', { class: 'tkd-btn tkd-btn--primary', href: '#' }, icon( 'download' ), el( 'span', { class: 'tkd-btn__label', text: __( 'Export CSV', 'takedown-desk' ) } ) );
		const verify = el( 'button', { type: 'button', class: 'tkd-linkchip tkd-linkchip--btn', onclick: ( e ) => verifyRecord( e.currentTarget ) }, icon( 'shield' ), el( 'span', { text: __( 'Verify record', 'takedown-desk' ) } ) );
		ui.tiles = el( 'div', { class: 'tkd-tiles' }, [ 0, 1, 2, 3, 4 ].map( () => el( 'div', { class: 'tkd-tile', 'aria-hidden': 'true' }, el( 'span', { class: 'tkd-skel', style: 'inline-size:60%' } ), el( 'span', { class: 'tkd-skel tkd-skel--big' } ) ) ) );
		ui.results = el( 'div', { class: 'tkd-results', tabindex: '-1' } );
		panel.replaceChildren(
			view(
				true,
				hero(
					__( 'Record of actions', 'takedown-desk' ),
					__( 'The dated record of every request and decision, ready for a lawyer or the FTC.', 'takedown-desk' ),
					[ cfg.exportUrl ? ui.export : null, verify ],
					infoTip( __( 'About the record', 'takedown-desk' ), __( 'Entries cannot be edited or deleted from here. Each entry is chained to the one before it so changes can be detected.', 'takedown-desk' ) )
				),
				el( 'div', { class: 'tkd-card tkd-logfilters', role: 'group', 'aria-label': __( 'Filter the record', 'takedown-desk' ) }, Object.keys( ui.fields ).map( ( k ) => ui.fields[ k ].wrap ) ),
				ui.tiles,
				el( 'div', { class: 'tkd-logbar' }, ui.view ),
				ui.results
			)
		);
		loadLog( ui );
		focusPending();
	}

	function exportHref() {
		const u = new URL( cfg.exportUrl || '', window.location.href );
		u.searchParams.set( 'from', unixDay( logq.from ) );
		u.searchParams.set( 'to', unixDay( logq.to, true ) );
		[ 'request', 'event' ].forEach( ( k ) => {
			if ( logq[ k ] ) {
				u.searchParams.set( k, logq[ k ] );
			} else {
				u.searchParams.delete( k );
			}
		} );
		return u.toString();
	}

	function loadLog( ui, announce ) {
		const my = ++seq;
		const q = new URLSearchParams( { from: unixDay( logq.from ), to: unixDay( logq.to, true ), page: logq.page, view: logq.view } );
		[ 'request', 'event' ].forEach( ( k ) => logq[ k ] && q.set( k, logq[ k ] ) );
		ui.export.href = exportHref();
		ui.results.setAttribute( 'aria-busy', 'true' );
		ui.results.replaceChildren( skeletonRows( __( 'Loading the record', 'takedown-desk' ) ) );
		api( 'log?' + q ).then(
			( res ) => {
				if ( my !== seq ) {
					return;
				}
				const rows = res.rows || [];
				const total = res.total || 0;
				ui.results.removeAttribute( 'aria-busy' );
				// Site-wide tiles next to one request's record would mislead: name the request instead.
				ui.tiles.replaceChildren(
					...( logq.request
						? [
								el(
									'div',
									{ class: 'tkd-card tkd-recordfor' },
									el( 'span', { class: 'tkd-chip-ico tkd-chip-ico--cream' }, icon( 'list' ) ),
									/* translators: %s: request ID. */
									el( 'p', { class: 'tkd-strong', text: sprintf( __( 'Showing the record for %s', 'takedown-desk' ), logq.request ) } ),
									el( 'span', { class: 'tkd-muted', 'aria-hidden': 'true', text: '·' } ),
									el( 'button', { type: 'button', class: 'tkd-linkchip tkd-linkchip--btn', 'aria-label': __( 'Clear the Request ID filter', 'takedown-desk' ), onclick: ui.clearRequest }, icon( 'x' ), el( 'span', { text: __( 'Clear', 'takedown-desk' ) } ) )
								),
						  ]
						: tiles( res.summary || {} ) )
				);
				if ( rows.length ) {
					ui.results.replaceChildren(
						el(
							'div',
							{ class: 'tkd-card tkd-tablecard is-enter' },
							logTable( rows ),
							pager( logq.page, total, 50, ( p ) => {
								logq.page = p;
								loadLog( ui, 'page' );
							} )
						)
					);
				} else {
					ui.results.replaceChildren(
						el(
							'div',
							{ class: 'tkd-card tkd-empty' },
							art( 'list' ),
							el( 'p', { class: 'tkd-empty__title', text: __( 'No actions recorded in this range.', 'takedown-desk' ) } ),
							btn( __( 'Show last 90 days', 'takedown-desk' ), {
								onclick: () => {
									resetLog();
									Object.keys( ui.fields ).forEach( ( k ) => {
										ui.fields[ k ].input.value = logq[ k ];
									} );
									loadLog( ui, true );
									ui.fields.from.input.focus();
								},
							} )
						)
					);
				}
				if ( announce ) {
					/* translators: %d: number of entries. */
					speak( sprintf( _n( '%d entry found.', '%d entries found.', total, 'takedown-desk' ), total ) );
				}
				if ( 'page' === announce ) {
					ui.results.focus();
				}
			},
			() => {
				if ( my === seq ) {
					ui.results.removeAttribute( 'aria-busy' );
					ui.results.replaceChildren( errorCard( __( 'We could not load the record. Check your connection and try again.', 'takedown-desk' ), () => loadLog( ui ) ) );
				}
			}
		);
	}

	function tiles( s ) {
		const closed = ( s.removed || 0 ) + ( s.declined || 0 );
		const within = s.within_48h || 0;
		const list = [
			[ 'inbox', 'blue', __( 'Requests received', 'takedown-desk' ), String( s.received || 0 ), __( 'Requests that arrived in this date range. Test requests and spam are not counted.', 'takedown-desk' ) ],
			[ 'check', 'green', __( 'Removed', 'takedown-desk' ), String( s.removed || 0 ), __( 'Requests closed in this range by removing the item.', 'takedown-desk' ) ],
			[ 'ban', 'neutral', __( 'Not removed', 'takedown-desk' ), String( s.declined || 0 ), __( 'Requests closed in this range without removing anything. Each one has a recorded reason.', 'takedown-desk' ) ],
			[ 'clock', 'orange', __( 'Median time to removal', 'takedown-desk' ), s.median_seconds ? duration( s.median_seconds ) : __( 'None yet', 'takedown-desk' ), __( 'The middle value: Half of the removals in this range were faster than this, half were slower.', 'takedown-desk' ) ],
			[
				'shield',
				'cream',
				__( 'Closed within 48 hours', 'takedown-desk' ),
				/* translators: 1: number of requests, 2: percentage. */
				closed ? sprintf( __( '%1$d (%2$d%%)', 'takedown-desk' ), within, Math.round( ( within / closed ) * 100 ) ) : '0',
				__( 'Requests closed before their 48-hour deadline, and their share of all requests closed in this range.', 'takedown-desk' ),
			],
		];
		return list.map( ( t ) =>
			el(
				'div',
				{ class: 'tkd-tile' },
				el(
					'div',
					{ class: 'tkd-tile__top' },
					el( 'span', { class: 'tkd-chip-ico tkd-chip-ico--' + t[ 1 ] }, icon( t[ 0 ] ) ),
					el( 'p', { class: 'tkd-tile__label', text: t[ 2 ] } ),
					/* translators: %s: tile name. */
					infoTip( sprintf( __( 'About %s', 'takedown-desk' ), t[ 2 ] ), t[ 4 ] )
				),
				el( 'p', { class: 'tkd-tile__value', text: t[ 3 ] } )
			)
		);
	}

	function logTable( rows ) {
		const heads = [
			/* translators: %s: timezone abbreviation. */
			sprintf( __( 'Time (%s)', 'takedown-desk' ), cfg.tzAbbr || cfg.tz || 'UTC' ),
			__( 'Request', 'takedown-desk' ),
			__( 'Who', 'takedown-desk' ),
			__( 'What happened', 'takedown-desk' ),
		];
		return el(
			'table',
			{ class: 'tkd-table tkd-logtable' },
			el( 'caption', { class: 'tkd-sr', text: __( 'Record of actions', 'takedown-desk' ) } ),
			el( 'thead', {}, el( 'tr', {}, heads.map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ),
			el(
				'tbody',
				{},
				collapse( rows ).map( ( x ) =>
					el(
						'tr',
						{},
						el( 'td', { 'data-label': heads[ 0 ] }, el( 'span', { class: 'tkd-muted', text: when( x, true ) } ) ),
						el( 'td', { 'data-label': heads[ 1 ] }, x.public_id ? el( 'a', { class: 'tkd-id', href: '#request/' + x.request_id, text: x.public_id } ) : muted( __( 'Site', 'takedown-desk' ) ) ),
						el( 'td', { 'data-label': heads[ 2 ] }, x.actor || '' ),
						el( 'td', { 'data-label': heads[ 3 ] }, x.text, repeats( x ) )
					)
				)
			)
		);
	}

	function verifyRecord( b ) {
		busy( b, true );
		api( 'log/verify', 'POST' ).then(
			( res ) => {
				busy( b, false );
				if ( res.ok ) {
					/* translators: %d: number of entries. */
					toast( sprintf( __( 'Record verified: %d entries, no gaps.', 'takedown-desk' ), res.rows || 0 ) );
				} else {
					/* translators: %d: entry ID. */
					toast( sprintf( __( 'Record check failed at entry %d.', 'takedown-desk' ), res.broken_at || 0 ), 'error' );
				}
			},
			( e ) => {
				busy( b, false );
				toast( errMsg( e ), 'error' );
			}
		);
	}

	/* ---------- Shared by Settings and Setup ---------- */
	const typeLabel = ( p ) => ( { topic: __( 'Forum topics', 'takedown-desk' ), reply: __( 'Forum replies', 'takedown-desk' ) }[ p.name ] || p.label );
	const toList = ( input ) => input.value.split( /[\s,]+/ ).filter( Boolean );

	function testEmail( b, key, done ) {
		busy( b, true );
		api( 'test-email', 'POST', { key } ).then(
			() => {
				busy( b, false );
				toast( done || __( 'Test email sent to you.', 'takedown-desk' ) );
			},
			( e ) => {
				busy( b, false );
				toast( errMsg( e ), 'error' );
			}
		);
	}

	// Settings card: spam protection. The built-in check is the default; with it off, Google reCAPTCHA can take its
	// place. The secret key works like the Cloudflare token: it never comes back from the server.
	function spamCard( data, v, h ) {
		const builtin = h.toggle( 'bot_check', __( 'Built-in bot protection', 'takedown-desk' ), __( 'Stops automated spam with an invisible check that runs on your own site. Nothing is sent to other services, and people filling in the forms do not see it. Recommended.', 'takedown-desk' ) );
		const off = el( 'p', { class: 'tkd-note' }, icon( 'shield' ), el( 'span', { text: __( 'To use Google reCAPTCHA instead, turn off the built-in protection.', 'takedown-desk' ) } ) );
		const useIt = h.toggle( 'recaptcha', __( 'Use Google reCAPTCHA', 'takedown-desk' ), __( 'Checks the request form and the review form with Google reCAPTCHA. Google loads its script on those pages and can see who visits them, and the forms then need JavaScript.', 'takedown-desk' ) );
		const version = h.choice( 'recaptcha_version', __( 'Type of reCAPTCHA', 'takedown-desk' ), [
			[ 'v3', __( 'Version 3: invisible, scores each visit', 'takedown-desk' ) ],
			[ 'v2', __( 'Version 2: "I\'m not a robot" checkbox', 'takedown-desk' ) ],
		] );
		const site = h.fld( 'recaptcha_site_key', { label: __( 'Site key', 'takedown-desk' ), attrs: { maxlength: 100, autocomplete: 'off', spellcheck: 'false' } } );
		let saved = !! ( data.recaptcha && data.recaptcha.secret_saved );
		let removed = false;
		const sec = field( {
			name: 's_recaptcha_secret',
			type: 'password',
			label: __( 'Secret key', 'takedown-desk' ),
			help: __( 'Stored encrypted and never shown again.', 'takedown-desk' ),
			attrs: { autocomplete: 'new-password', spellcheck: 'false' },
		} );
		const drawSecret = () => {
			sec.input.value = '';
			row.hidden = ! saved || removed;
			sec.input.hidden = ! row.hidden;
		};
		const row = el(
			'div',
			{ class: 'tkd-cfsaved' },
			el( 'span', { class: 'tkd-cfsaved__state' }, icon( 'done' ), el( 'span', { text: __( 'Saved', 'takedown-desk' ) } ) ),
			btn( __( 'Remove', 'takedown-desk' ), {
				icon: 'trash',
				onclick: ( e ) => {
					const form = e.currentTarget.form;
					removed = true;
					drawSecret();
					sec.input.focus();
					form.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				},
			} )
		);
		sec.wrap.insertBefore( row, sec.input );
		drawSecret();
		h.reg.recaptcha_secret = {
			get: () => ( removed ? '' : sec.input.value.trim() || null ),
			set: sec.set,
			saved: () => {
				saved = removed ? false : saved || !! sec.input.value.trim();
				removed = false;
				drawSecret();
			},
		};
		const score = h.fld(
			'recaptcha_score',
			{
				type: 'select',
				label: __( 'Lowest score that passes', 'takedown-desk' ),
				help: __( 'Google scores each visit from 0.0 (likely a bot) to 1.0 (likely a person).', 'takedown-desk' ),
				options: [ [ '3', __( '0.3: lenient', 'takedown-desk' ) ], [ '5', __( '0.5: recommended', 'takedown-desk' ) ], [ '7', __( '0.7: strict', 'takedown-desk' ) ] ],
			},
			h.num
		);
		const test = btn( __( 'Test the secret key', 'takedown-desk' ), {
			icon: 'link',
			onclick: ( e ) => {
				const b = e.currentTarget;
				busy( b, true );
				api( 'recaptcha/test', 'POST', { secret: removed ? '' : sec.input.value.trim() } ).then(
					() => {
						busy( b, false );
						toast( __( 'Google accepts this secret key.', 'takedown-desk' ) );
					},
					( err ) => {
						busy( b, false );
						toast( errMsg( err ), 'error' );
					}
				);
			},
		} );
		const box = el(
			'div',
			{ class: 'tkd-stack tkd-stack--panel' },
			useIt,
			version,
			el( 'div', { class: 'tkd-grid2' }, site, sec.wrap ),
			score,
			el( 'p', { class: 'tkd-help', text: __( 'Create a key pair for this site\'s domain in the reCAPTCHA admin console, with the same type you choose here.', 'takedown-desk' ) } ),
			el( 'div', { class: 'tkd-actions' }, test, linkChip( __( 'Google reCAPTCHA admin console', 'takedown-desk' ), 'https://www.google.com/recaptcha/admin', true, 'link' ) )
		);
		// Built-in on: reCAPTCHA waits behind a note. v2 has no score.
		const sync = () => {
			const on = builtin.querySelector( 'input' ).checked;
			off.hidden = ! on;
			box.hidden = on;
			score.hidden = 'v2' === ( version.querySelector( 'input:checked' ) || {} ).value;
		};
		builtin.addEventListener( 'change', sync );
		version.addEventListener( 'change', sync );
		sync();
		return card( 'shield', 'blue', __( 'Spam protection', 'takedown-desk' ), 'tkd-set-spam', builtin, off, box );
	}

	// Settings card: Cloudflare purge. The token never comes back from the server: a saved one shows as Saved with Remove.
	function cloudflareCard( data, fld, reg ) {
		let saved = !! ( data.cloudflare && data.cloudflare.token_saved );
		let removed = false;
		const zone = fld( 'cf_zone_id', {
			label: __( 'Zone ID', 'takedown-desk' ),
			help: __( 'Shown on the Overview page of your site in the Cloudflare dashboard.', 'takedown-desk' ),
			attrs: { maxlength: 32, autocomplete: 'off', spellcheck: 'false' },
		} );
		const tok = field( {
			name: 's_cf_token',
			type: 'password',
			label: __( 'API token', 'takedown-desk' ),
			help: __( 'Create a token with the Cache Purge permission for this zone. It is stored encrypted and never shown again.', 'takedown-desk' ),
			attrs: { autocomplete: 'new-password', spellcheck: 'false' },
		} );
		const row = el(
			'div',
			{ class: 'tkd-cfsaved' },
			el( 'span', { class: 'tkd-cfsaved__state' }, icon( 'done' ), el( 'span', { text: __( 'Saved', 'takedown-desk' ) } ) ),
			btn( __( 'Remove', 'takedown-desk' ), {
				icon: 'trash',
				onclick: ( e ) => {
					const form = e.currentTarget.form;
					removed = true;
					draw();
					tok.input.focus();
					form.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				},
			} )
		);
		const draw = () => {
			tok.input.value = '';
			row.hidden = ! saved || removed;
			tok.input.hidden = ! row.hidden;
		};
		tok.wrap.insertBefore( row, tok.input );
		draw();
		reg.cf_token = {
			get: () => ( removed ? '' : tok.input.value.trim() || null ),
			set: tok.set,
			saved: () => {
				saved = removed ? false : saved || !! tok.input.value.trim();
				removed = false;
				draw();
			},
		};
		const test = btn( __( 'Test connection', 'takedown-desk' ), {
			icon: 'link',
			onclick: ( e ) => {
				const b = e.currentTarget;
				busy( b, true );
				api( 'cloudflare/test', 'POST', { zone_id: zone.querySelector( 'input' ).value.trim(), token: removed ? '' : tok.input.value.trim() } ).then(
					() => {
						busy( b, false );
						toast( __( 'Cloudflare connection works.', 'takedown-desk' ) );
					},
					( err ) => {
						busy( b, false );
						toast( errMsg( err ), 'error' );
					}
				);
			},
		} );
		return card(
			'cloud',
			'orange',
			__( 'Cloudflare', 'takedown-desk' ),
			'tkd-set-cf',
			el( 'p', { class: 'tkd-help', text: __( 'If your site runs behind Cloudflare, removed links are purged from its cache as soon as an item is removed or hidden. Leave these fields empty if you do not use Cloudflare.', 'takedown-desk' ) } ),
			el( 'div', { class: 'tkd-grid2' }, zone, tok.wrap ),
			el( 'div', { class: 'tkd-actions' }, test )
		);
	}

	function keyBlock( key ) {
		if ( key && 'constant' === key.source ) {
			return el( 'p', { class: 'tkd-note' }, icon( 'done' ), el( 'span', { text: __( 'Your encryption key is set with TKDESK_KEY in wp-config.php.', 'takedown-desk' ) } ) );
		}
		if ( ! key || ! key.line ) {
			return null;
		}
		// Hidden until asked for: the line holds this site's real key, and screens get shared and screenshotted.
		const id = uid();
		const input = el( 'input', { id, class: 'tkd-input tkd-mono', type: 'password', readonly: true, autocomplete: 'off', spellcheck: 'false', value: key.line } );
		const reveal = ( on ) => {
			input.type = on ? 'text' : 'password';
			show.querySelector( '.tkd-btn__label' ).textContent = on ? __( 'Hide', 'takedown-desk' ) : __( 'Show', 'takedown-desk' );
		};
		const show = btn( __( 'Show', 'takedown-desk' ), { icon: 'eye', onclick: () => reveal( 'password' === input.type ) } );
		// Browsers will not copy from a hidden field, so the manual fallback shows the line first.
		const copy = btn( __( 'Copy', 'takedown-desk' ), {
			icon: 'copy',
			onclick: () => {
				if ( ! navigator.clipboard ) {
					reveal( true );
				}
				copyText( input );
			},
		} );
		return el(
			'div',
			{ class: 'tkd-alert tkd-keybox tkd-alert--amber' },
			el( 'span', { class: 'tkd-alert__ico' }, icon( 'shield' ) ),
			el(
				'div',
				{ class: 'tkd-alert__msg' },
				el( 'p', { class: 'tkd-strong', text: __( 'Your encryption key is stored in the same database as the data it protects. Add a TKDESK_KEY line to wp-config.php.', 'takedown-desk' ) } ),
				el( 'p', { text: __( 'Recommended: Paste this line into wp-config.php, above the line that says to stop editing. It holds the key in use now, so saved contact details stay readable, and the copy in the database is then deleted. Keep a copy of the line somewhere safe: without it, contact details cannot be read.', 'takedown-desk' ) } ),
				el( 'div', { class: 'tkd-field' }, el( 'label', { for: id, class: 'tkd-label', text: __( 'Line for wp-config.php', 'takedown-desk' ) } ), el( 'div', { class: 'tkd-copybox' }, input, show, copy ) )
			)
		);
	}

	function team( mods ) {
		return [
			el(
				'ul',
				{ class: 'tkd-team' },
				mods.map( ( m ) =>
					el(
						'li',
						{ class: 'tkd-team__row' },
						avatar( m ),
						el( 'span', { class: 'tkd-team__who' }, el( 'span', { class: 'tkd-strong', text: m.name } ), el( 'span', { class: 'tkd-help', text: [ m.role, m.email ].filter( Boolean ).join( ' · ' ) } ) )
					)
				)
			),
			1 === mods.length
				? el(
						'div',
						{ class: 'tkd-alert tkd-alert--amber' },
						el( 'span', { class: 'tkd-alert__ico' }, icon( 'alert' ) ),
						el( 'p', { class: 'tkd-alert__msg', text: __( 'Only one person can act on requests. Add a second moderator so weekends and holidays are covered.', 'takedown-desk' ) } )
				  )
				: null,
		];
	}

	// "I will place it myself": the block by its editor title, how to add it with this theme, and the shortcode.
	const footerChoice = ( name, value, onPick ) => {
		const steps = cfg.isBlockTheme
			? [ __( 'Open your footer in the Site Editor.', 'takedown-desk' ), __( 'Click + and search for Removal request link.', 'takedown-desk' ), __( 'Place it and save.', 'takedown-desk' ) ]
			: [ __( 'Open Appearance > Widgets.', 'takedown-desk' ), __( 'Add the Removal request link block, or a Shortcode widget with [takedown_desk_link].', 'takedown-desk' ), __( 'Save.', 'takedown-desk' ) ];
		const manual = el(
			'div',
			{ class: 'tkd-placeit', hidden: 'manual' !== value },
			el( 'p', { class: 'tkd-help', text: __( 'Add the Removal request link block where people will find it, usually in your footer:', 'takedown-desk' ) } ),
			el(
				'ol',
				{ class: 'tkd-howto' },
				steps.map( ( text ) => el( 'li', { text } ) )
			),
			el( 'div', { class: 'tkd-actions' }, linkChip( cfg.isBlockTheme ? __( 'Open the Site Editor', 'takedown-desk' ) : __( 'Open Widgets', 'takedown-desk' ), cfg.footerEditUrl, true ) ),
			copyBox( __( 'Shortcode', 'takedown-desk' ), '[takedown_desk_link]' )
		);
		return [
			radios(
				name,
				__( 'Footer link', 'takedown-desk' ),
				[
					[ 'auto', __( 'Automatic', 'takedown-desk' ) ],
					[ 'manual', __( 'I will place it myself', 'takedown-desk' ) ],
				],
				value,
				( x ) => {
					manual.hidden = 'manual' !== x;
					onPick( x );
				}
			),
			manual,
		];
	};

	/* ---------- Settings (#settings) ---------- */
	// Key, name, when it is sent and, for requester emails, what turning it off means.
	const EMAILS = [
		[ 'requester_received', __( 'Request received, to the requester', 'takedown-desk' ), __( 'Right after someone sends a request, or you log one for them.', 'takedown-desk' ), __( 'Requesters will not get this email, so they will not have their private link to check the status or add details. They still see the request ID on screen after they send the form.', 'takedown-desk' ) ],
		[ 'requester_minor', __( 'Request received, person under 18, to the requester', 'takedown-desk' ), __( 'Instead of the usual confirmation, when the requester says the person in the image was under 18.', 'takedown-desk' ), __( 'Requesters will not get this email, so they will not have their private link or the links to NCMEC services.', 'takedown-desk' ) ],
		[ 'requester_needs_info', __( 'Question, to the requester', 'takedown-desk' ), __( 'When you ask the requester for details.', 'takedown-desk' ), __( 'Requesters will not get your questions, so they cannot send the details you ask for.', 'takedown-desk' ) ],
		[ 'requester_removed', __( 'Removed, to the requester', 'takedown-desk' ), __( 'When you close a request as removed.', 'takedown-desk' ), __( 'Requesters will not get this email. FTC guidance on the Take It Down Act expects you to tell requesters the outcome.', 'takedown-desk' ) ],
		[ 'requester_declined', __( 'Not removed, to the requester', 'takedown-desk' ), __( 'When you close a request as not removed.', 'takedown-desk' ), __( 'Requesters will not get this email. FTC guidance on the Take It Down Act expects you to tell requesters the outcome and why the item was not removed.', 'takedown-desk' ) ],
		[ 'mod_new', __( 'New request, to moderators', 'takedown-desk' ), __( 'Right after a request arrives.', 'takedown-desk' ) ],
		[ 'mod_24h', __( '24 hours left, to moderators', 'takedown-desk' ), __( 'When 24 hours of the 48-hour deadline are left.', 'takedown-desk' ) ],
		[ 'mod_40h', __( '8 hours left, to moderators', 'takedown-desk' ), __( 'When 8 hours of the 48-hour deadline are left.', 'takedown-desk' ) ],
		[ 'mod_overdue', __( 'Overdue, to moderators', 'takedown-desk' ), __( 'When the 48-hour deadline passes and the request is still open.', 'takedown-desk' ) ],
		[ 'mod_reply', __( 'Requester replied, to moderators', 'takedown-desk' ), __( 'When the requester sends the details you asked for.', 'takedown-desk' ) ],
		[ 'requester_update', __( 'Still open after 6 days, to the requester', 'takedown-desk' ), __( 'Once, when a request is still open 6 days after it arrived.', 'takedown-desk' ), __( 'Requesters will not hear from you while a request stays open. California law asks social media platforms to send an update within 7 days.', 'takedown-desk' ) ],
		[ 'poster_removed', __( 'Content removed, to the person who posted it', 'takedown-desk' ), __( 'After a removal, when "Notify the person who posted the item" is on.', 'takedown-desk' ), __( 'People whose content you remove will not be told why or how to ask for a review. The EU Digital Services Act expects a reason to be given.', 'takedown-desk' ) ],
		[ 'mod_review', __( 'Review requested, to moderators', 'takedown-desk' ), __( 'When the person who posted removed content asks for a review.', 'takedown-desk' ) ],
	];
	// Copyright notice emails: listed under their own heading, shown while copyright notices are on.
	const DMCA_EMAILS = [
		[ 'dmca_received', __( 'Notice received, to the sender', 'takedown-desk' ), __( 'Right after someone sends a copyright notice, or you log one for them.', 'takedown-desk' ), __( 'Senders will not get this email, so they will not have their private link to check the status or add details.', 'takedown-desk' ) ],
		[ 'dmca_needs_info', __( 'Question, to the sender', 'takedown-desk' ), __( 'When you ask the sender of a copyright notice for details.', 'takedown-desk' ), __( 'Senders will not get your questions, so they cannot send the details you ask for.', 'takedown-desk' ) ],
		[ 'dmca_removed', __( 'Removed, to the sender', 'takedown-desk' ), __( 'When you close a copyright notice as removed.', 'takedown-desk' ), __( 'Senders will not hear that the material was removed.', 'takedown-desk' ) ],
		[ 'dmca_declined', __( 'Not removed, to the sender', 'takedown-desk' ), __( 'When you close a copyright notice as not removed.', 'takedown-desk' ), __( 'Senders will not hear that the material stayed up or why.', 'takedown-desk' ) ],
		[ 'dmca_poster_removed', __( 'Content removed after a copyright notice, to the person who posted it', 'takedown-desk' ), __( 'After a removal, when "Notify the person who posted the item" is on.', 'takedown-desk' ), __( 'People whose content you remove will not be told why or how to ask for a review.', 'takedown-desk' ) ],
		[ 'mod_dmca_new', __( 'New copyright notice, to moderators', 'takedown-desk' ), __( 'Right after a copyright notice arrives.', 'takedown-desk' ) ],
		[ 'mod_dmca_reminder', __( 'Target reminder, to moderators', 'takedown-desk' ), __( 'When half of the target time has passed, and again near the end.', 'takedown-desk' ) ],
		[ 'mod_dmca_overdue', __( 'Past target, to moderators', 'takedown-desk' ), __( 'When the target time passes and the notice is still open.', 'takedown-desk' ) ],
	];

	// Fills {fields} the way emails.php does: one pass, unknown fields stay, a line whose fields all came out empty goes.
	const TAG = /\{([a-z0-9_]+)\}/g;
	const usedTags = ( text ) => [ ...new Set( ( String( text ).match( TAG ) || [] ).map( ( t ) => t.slice( 1, -1 ) ) ) ];
	function fillTags( text, vals, drop ) {
		const lines = [];
		String( text )
			.replace( /\r\n?/g, '\n' )
			.split( '\n' )
			.forEach( ( line ) => {
				let found = 0;
				let filled = 0;
				const out = line.replace( TAG, ( m, t ) => {
					if ( ! Object.prototype.hasOwnProperty.call( vals, t ) ) {
						return m;
					}
					found++;
					filled += '' === vals[ t ] ? 0 : 1;
					return vals[ t ];
				} );
				if ( ! drop || ! found || filled ) {
					lines.push( out );
				}
			} );
		return lines.join( '\n' ).replace( /\n{3,}/g, '\n\n' ).trim();
	}

	// One row per email: name, when it is sent, an on/off switch (saved with the page), Preview, Edit, Send me a test.
	function mailRows( data, reg ) {
		const saved = Object.assign( {}, ( data.settings || {} ).emails );
		const editor = data.email_editor || { templates: {}, tags: {} };
		const sw = {};
		reg.emails = {
			get: () => Object.keys( sw ).reduce( ( o, k ) => Object.assign( o, { [ k ]: { active: sw[ k ].checked } } ), {} ),
			set: () => false,
		};
		// Only the copyright templates this server has (an older one has none).
		const dmca = DMCA_EMAILS.filter( ( m ) => editor.templates[ m[ 0 ] ] );
		const rows = ( list ) => el(
			'ul',
			{ class: 'tkd-mails' },
			list.map( ( m ) => {
				const key = m[ 0 ];
				const id = uid();
				const input = el( 'input', { type: 'checkbox', role: 'switch', 'aria-labelledby': id, 'aria-describedby': id + 'd' } );
				input.checked = false !== ( saved[ key ] || {} ).active;
				sw[ key ] = input;
				const state = el( 'span', { class: 'tkd-switchrow__state', 'aria-hidden': 'true' } );
				const custom = el( 'span', { class: 'tkd-chip tkd-chip--blue', text: __( 'Custom text', 'takedown-desk' ) } );
				const warn = m[ 3 ] ? el( 'p', { id: id + 'w', class: 'tkd-mails__warn' }, icon( 'alert' ), el( 'span', { text: m[ 3 ] } ) ) : null;
				const row = el( 'li', { class: 'tkd-mails__row' } );
				const sync = () => {
					const t = saved[ key ] || {};
					state.textContent = input.checked ? __( 'On', 'takedown-desk' ) : __( 'Off', 'takedown-desk' );
					custom.hidden = ! ( t.subject || t.body );
					row.classList.toggle( 'is-off', ! input.checked );
					if ( warn ) {
						warn.hidden = input.checked;
						input.setAttribute( 'aria-describedby', id + 'd' + ( input.checked ? '' : ' ' + warn.id ) );
					}
				};
				input.addEventListener( 'change', () => {
					sync();
					if ( warn && ! input.checked ) {
						speak( m[ 3 ] );
					}
				} );
				sync();
				return add( row, [
					el(
						'div',
						{ class: 'tkd-mails__text' },
						el( 'span', { class: 'tkd-mails__name' }, el( 'span', { id, class: 'tkd-mails__label', text: m[ 1 ] } ), custom ),
						el( 'span', { id: id + 'd', class: 'tkd-mails__when' }, icon( 'clock' ), el( 'span', { text: m[ 2 ] } ) )
					),
					el( 'label', { class: 'tkd-mails__switch' }, state, input ),
					el(
						'span',
						{ class: 'tkd-actions tkd-mails__actions', role: 'group', 'aria-labelledby': id },
						btn( __( 'Preview', 'takedown-desk' ), { kind: 'ghost', icon: 'eye', onclick: () => previewEmail( key, m[ 1 ] ) } ),
						btn( __( 'Edit', 'takedown-desk' ), {
							icon: 'edit',
							onclick: () =>
								editEmail( m, editor, saved[ key ] || {}, ( emails ) => {
									saved[ key ] = emails[ key ] || {};
									sync();
								} ),
						} ),
						btn( __( 'Send me a test', 'takedown-desk' ), { kind: 'ghost', icon: 'send', onclick: ( e ) => testEmail( e.currentTarget, key ) } )
					),
					warn,
				] );
			} )
		);
		return [
			rows( EMAILS ),
			// The Copyright notices card's switch shows and hides this group (dmcaCard).
			dmca.length ? el( 'div', { id: 'tkd-mails-dmca', class: 'tkd-mailgroup', hidden: ! ( data.settings || {} ).dmca }, el( 'h3', { class: 'tkd-subhead', text: __( 'Copyright notice emails', 'takedown-desk' ) } ), rows( dmca ) ) : null,
		];
	}

	// Edit one email: subject and text, the fields it can use (click or Enter adds one at the cursor), a live preview.
	function editEmail( m, editor, cur, done ) {
		const key = m[ 0 ];
		const tpl = editor.templates[ key ];
		const info = editor.tags || {};
		const vals = {};
		tpl.tags.forEach( ( t ) => {
			vals[ t ] = String( ( info[ t ] || [] )[ 1 ] || '' );
		} );
		const start = { subject: cur.subject || tpl.subject, body: cur.body || tpl.body };
		const subject = field( { name: 'mail_subject', label: __( 'Subject', 'takedown-desk' ), help: __( 'One line, up to 200 characters.', 'takedown-desk' ), value: start.subject, attrs: { autocomplete: 'off', 'data-autofocus': true } } );
		const body = field( { name: 'mail_body', type: 'textarea', rows: 12, label: __( 'Text', 'takedown-desk' ), help: __( 'Plain text, up to 5,000 characters. Leave an empty line between paragraphs.', 'takedown-desk' ), value: start.body } );
		const preview = el( 'div', { class: 'tkd-email' } );
		const listId = uid();
		const box = el( 'div' );
		let last = body.input;
		let tried = false;
		const brace = ( list ) => list.map( ( t ) => '{' + t + '}' ).join( ', ' );
		// Same rules as tkdesk_email_errors(); the first field with an error, or null.
		const check = () => {
			const s = subject.input.value;
			const b = body.input.value;
			const bad = ( x, max, empty ) => {
				const unknown = usedTags( x ).filter( ( t ) => ! tpl.tags.includes( t ) );
				if ( ! x.trim() ) {
					return empty;
				}
				if ( Array.from( x.trim() ).length > max ) {
					/* translators: %d: maximum number of characters. */
					return sprintf( __( 'Use %d characters or fewer.', 'takedown-desk' ), max );
				}
				/* translators: %s: list of fields such as {item}. */
				return unknown.length ? sprintf( _n( 'This email cannot fill in %s. Remove it or pick a field from the list.', 'This email cannot fill in %s. Remove them or pick fields from the list.', unknown.length, 'takedown-desk' ), brace( unknown ) ) : '';
			};
			const missing = tpl.required.filter( ( t ) => ! usedTags( s + '\n' + b ).includes( t ) );
			const es = /[\r\n]/.test( s ) ? __( 'Keep the subject on one line.', 'takedown-desk' ) : bad( s, 200, __( 'Write a subject.', 'takedown-desk' ) );
			const eb =
				bad( b, 5000, __( 'Write the text of the email.', 'takedown-desk' ) ) ||
				/* translators: %s: list of fields such as {request_id}. */
				( missing.length ? sprintf( _n( 'This email must include %s. Add it back to the subject or the text.', 'This email must include %s. Add them back to the subject or the text.', missing.length, 'takedown-desk' ), brace( missing ) ) : '' );
			subject.set( es );
			body.set( eb );
			return ( es && subject ) || ( eb && body ) || null;
		};
		const save = btn( __( 'Save', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'check', disabled: true } );
		const reset = btn( __( 'Reset to default', 'takedown-desk' ), {
			icon: 'undo',
			onclick: () => {
				subject.input.value = tpl.subject;
				body.input.value = tpl.body;
				draw();
				subject.input.focus();
				speak( __( 'The default subject and text are back. Save to keep them.', 'takedown-desk' ) );
			},
		} );
		reset.classList.add( 'tkd-dialog__start' );
		const draw = () => {
			const s = subject.input.value;
			const b = body.input.value;
			preview.replaceChildren(
				el( 'p', { class: 'tkd-email__subject' }, el( 'span', { class: 'tkd-email__key', text: __( 'Subject', 'takedown-desk' ) } ), el( 'span', { text: fillTags( s, vals, false ).replace( /[\r\n\t]+/g, ' ' ) } ) ),
				para( fillTags( b, vals, true ) )
			);
			save.disabled = s === start.subject && b === start.body;
			reset.disabled = s === tpl.subject && b === tpl.body;
			if ( tried ) {
				check();
			}
		};
		const insert = ( text ) => {
			last.focus();
			last.setRangeText( text, last.selectionStart, last.selectionEnd, 'end' );
			draw();
			/* translators: %s: a field such as {request_id}. */
			speak( sprintf( __( 'Added %s.', 'takedown-desk' ), text ) );
		};
		const tags = el(
			'ul',
			{ class: 'tkd-tags', 'aria-labelledby': listId },
			tpl.tags.map( ( t ) => {
				const did = uid();
				return el(
					'li',
					{ class: 'tkd-tags__row' },
					/* translators: %s: a field such as {request_id}. */
					el( 'button', { type: 'button', class: 'tkd-tag', 'aria-label': sprintf( __( 'Insert %s', 'takedown-desk' ), '{' + t + '}' ), 'aria-describedby': did, onclick: () => insert( '{' + t + '}' ) }, '{' + t + '}' ),
					el( 'span', { id: did, class: 'tkd-tags__desc' }, ( info[ t ] || [] )[ 0 ] || '', tpl.required.includes( t ) ? el( 'span', { class: 'tkd-tags__req', text: __( 'Required', 'takedown-desk' ) } ) : null )
				);
			} )
		);
		[ subject.input, body.input ].forEach( ( i ) => {
			i.addEventListener( 'focus', () => {
				last = i;
			} );
			i.addEventListener( 'input', draw );
		} );
		draw();
		const d = dialog( {
			/* translators: %s: email name, such as "Request received, to the requester". */
			title: sprintf( __( 'Edit email: %s', 'takedown-desk' ), m[ 1 ] ),
			wide: true,
			body: [
				box,
				el(
					'div',
					{ class: 'tkd-tpl' },
					el( 'div', { class: 'tkd-tpl__edit' }, subject.wrap, body.wrap ),
					el(
						'div',
						{ class: 'tkd-tpl__side' },
						el( 'p', { id: listId, class: 'tkd-label', text: __( 'Fields you can add', 'takedown-desk' ) } ),
						el( 'p', { class: 'tkd-help', text: __( 'Select a field to add it where your cursor is. A line is left out when all its fields are empty.', 'takedown-desk' ) } ),
						tags
					)
				),
				el( 'div', { class: 'tkd-field' }, el( 'p', { class: 'tkd-label', text: __( 'Preview with sample details', 'takedown-desk' ) } ), preview ),
			],
			buttons: [ reset, save ],
			onsubmit() {
				tried = true;
				const bad = check();
				if ( bad ) {
					bad.input.focus();
					return;
				}
				busy( save, true );
				api( 'settings', 'POST', { settings: { emails: { [ key ]: { subject: subject.input.value.trim(), body: body.input.value.trim() } } } } ).then(
					( res ) => {
						d.close();
						done( ( res.settings && res.settings.emails ) || {} );
						toast( __( 'Email saved.', 'takedown-desk' ) );
					},
					( e ) => {
						busy( save, false );
						const f = ( e.data && e.data.fields ) || {};
						const list = [
							[ subject, f[ 'emails.' + key + '.subject' ] ],
							[ body, f[ 'emails.' + key + '.body' ] ],
						].filter( ( x ) => x[ 1 ] );
						if ( ! list.length ) {
							showError( box, [], errMsg( e ) );
							return;
						}
						list.forEach( ( x ) => x[ 0 ].set( x[ 1 ] ) );
						list[ 0 ][ 0 ].input.focus();
					}
				);
			},
		} );
	}

	/* ---------- Person who posted it: poster notice and review requests, and the EU switch (poster.php) ---------- */
	const reviewChip = () => el( 'span', { class: 'tkd-chip tkd-chip--amber', text: __( 'Review requested', 'takedown-desk' ) } );

	// The statement as a message from the person who posted it. Their text only ever goes into textContent.
	function reviewMsg( x ) {
		return el(
			'ol',
			{ class: 'tkd-thread' },
			el(
				'li',
				{ class: 'tkd-thread__msg is-them' },
				el( 'p', { class: 'tkd-thread__meta', text: __( 'Person who posted it', 'takedown-desk' ) + ' · ' + fmtDate( x.at ) } ),
				para( x.statement ),
				/* translators: %s: name typed as a signature. */
				el( 'p', { class: 'tkd-thread__meta', text: sprintf( __( 'Signed: %s', 'takedown-desk' ), x.signature ) } )
			)
		);
	}

	function keepDialog() {
		const n = field( { name: 'review_note', type: 'textarea', rows: 3, required: true, label: __( 'Why does it stay offline? (saved to the record)', 'takedown-desk' ), attrs: { 'data-autofocus': true } } );
		n.input.addEventListener( 'input', () => n.input.value.trim().length >= 10 && n.set( '' ) );
		const box = el( 'div' );
		const go = btn( __( 'Keep it offline', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'shield' } );
		const d = dialog( {
			title: __( 'Keep it offline?', 'takedown-desk' ),
			body: [ el( 'p', { class: 'tkd-lead', text: __( 'The content stays removed and the review closes. Nobody is emailed.', 'takedown-desk' ) } ), box, n.wrap ],
			buttons: [ go ],
			onsubmit() {
				const note = n.input.value.trim();
				if ( n.set( note.length < 10 ? __( 'Add a short note about your decision.', 'takedown-desk' ) : '' ) ) {
					n.input.focus();
					return;
				}
				busy( go, true );
				api( base() + '/review', 'POST', { note } ).then(
					() => {
						d.returnTo = null;
						d.close();
						toast( __( 'Review closed. The content stays offline.', 'takedown-desk' ) );
						reload( '#tkd-poster-h' );
					},
					( e ) => {
						busy( go, false );
						showError( box, [], errMsg( e ) );
					}
				);
			},
		} );
	}

	// Detail panel: what the person who posted it was told, a waiting review with its two ways out, and past reviews.
	function posterCard( r ) {
		const p = r.poster;
		const removed = ( r.items || [] ).filter( acted );
		if ( ! p || ( ! p.notices.length && ! p.reviews.length && ! removed.length ) ) {
			return null;
		}
		const parts = [];
		const last = p.reviews[ p.reviews.length - 1 ];
		if ( p.pending && last ) {
			const back = removed.filter( ( it ) => it.can_restore );
			let help = __( 'Only an administrator can put it back.', 'takedown-desk' );
			if ( cfg.canManage ) {
				help = back.length ? __( 'Putting it back closes the request as not removed and emails the requester.', 'takedown-desk' ) : __( 'It cannot be put back: it was deleted, or its restore window has ended.', 'takedown-desk' );
			}
			parts.push(
				el(
					'div',
					{ class: 'tkd-alert tkd-alert--amber' },
					el( 'span', { class: 'tkd-alert__ico' }, icon( 'flag' ) ),
					el(
						'div',
						{ class: 'tkd-alert__msg' },
						el( 'p', { class: 'tkd-strong', text: __( 'Review requested', 'takedown-desk' ) } ),
						el( 'p', { text: __( 'The person who posted it thinks the removal was a mistake. It stays offline until you decide. The deadline and the record of the removal do not change.', 'takedown-desk' ) } )
					)
				),
				reviewMsg( last ),
				el(
					'div',
					{ class: 'tkd-actions' },
					btn( __( 'Keep it offline', 'takedown-desk' ), { kind: 'primary', icon: 'shield', onclick: keepDialog } ),
					cfg.canManage
						? back.map( ( it ) => {
								const one = __( 'Put it back', 'takedown-desk' );
								/* translators: %s: item name. */
								const named = sprintf( __( 'Put back %s', 'takedown-desk' ), it.label );
								return btn( 1 === back.length ? one : named, { icon: 'undo', onclick: () => restoreDialog( it ) } );
						  } )
						: null
				),
				el( 'p', { class: 'tkd-help', text: help } )
			);
		}
		const rows = p.notices.map( ( n ) =>
			el(
				'li',
				{ class: 'tkd-poster__row' + ( n.sent ? '' : ' is-off' ) },
				icon( n.sent ? 'send' : 'alert' ),
				el(
					'span',
					null,
					el( 'span', {
						text: n.sent
							? /* translators: %s: date and time. */ sprintf( __( 'Emailed the reason and a review link on %s.', 'takedown-desk' ), fmtDate( n.at ) )
							: /* translators: 1: date and time, 2: reason. */ sprintf( __( 'Not emailed on %1$s: %2$s', 'takedown-desk' ), fmtDate( n.at ), n.cause ),
					} ),
					n.items.length ? el( 'span', { class: 'tkd-poster__items', text: n.items.join( ', ' ) } ) : null
				)
			)
		);
		if ( ! rows.length ) {
			rows.push(
				el(
					'li',
					{ class: 'tkd-poster__row is-off' },
					icon( 'info' ),
					el( 'span', { text: p.on ? __( 'No notice was sent for this removal.', 'takedown-desk' ) : __( 'Notices to the person who posted it are off.', 'takedown-desk' ) } )
				)
			);
		}
		parts.push( el( 'ul', { class: 'tkd-poster' }, rows ) );
		if ( ! p.on && ! p.notices.length && cfg.canManage ) {
			parts.push( el( 'div', { class: 'tkd-actions' }, linkChip( __( 'Turn on in Settings', 'takedown-desk' ), '#settings', false, 'gear' ) ) );
		}
		const past = p.reviews.filter( ( x ) => 'pending' !== x.outcome );
		if ( past.length ) {
			parts.push(
				el( 'h3', { class: 'tkd-subhead', text: __( 'Earlier reviews', 'takedown-desk' ) } ),
				past.map( ( x ) =>
					el(
						'div',
						{ class: 'tkd-poster__past' },
						reviewMsg( x ),
						el( 'p', {
							class: 'tkd-help',
							text:
								'kept' === x.outcome
									? /* translators: 1: moderator name, 2: date and time, 3: decision note. */ sprintf( __( 'Kept offline by %1$s on %2$s. Note: %3$s', 'takedown-desk' ), x.decided_by, fmtDate( x.decided_at ), x.note )
									: /* translators: 1: moderator name, 2: date and time. */ sprintf( __( 'Put back by %1$s on %2$s.', 'takedown-desk' ), x.decided_by, fmtDate( x.decided_at ) ),
						} )
					)
				)
			);
		}
		return card( 'users', p.pending ? 'orange' : 'neutral', __( 'Person who posted it', 'takedown-desk' ), 'tkd-poster-h', parts );
	}
	hooks.addFilter( 'tkdesk.request.panels', 'takedown-desk/poster', ( panels, r ) => panels.concat( posterCard( r ) || [] ) );

	// Settings card: the EU switch turns the poster notice on and keeps it on while it is on.
	function lawsCard( toggle ) {
		const eu = toggle(
			'eu_visitors',
			__( 'This site has visitors in the EU', 'takedown-desk' ),
			__( 'Outcome emails then say how to contest the decision, as the EU Digital Services Act asks, and the person who posted removed content is always told why.', 'takedown-desk' )
		);
		const pn = toggle(
			'poster_notice',
			__( 'Notify the person who posted the item', 'takedown-desk' ),
			__( 'After a removal, emails them the reason and a link to ask for a review. It never says who sent the request. Not sent when the person in the image was under 18 or when your team filed the request. Always on while the EU switch is on.', 'takedown-desk' )
		);
		const euIn = eu.querySelector( 'input' );
		const pnIn = pn.querySelector( 'input' );
		const link = () => {
			if ( euIn.checked && ! pnIn.checked ) {
				pnIn.checked = true;
				pnIn.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			pnIn.disabled = euIn.checked;
		};
		euIn.addEventListener( 'change', link );
		link();
		return card(
			'send',
			'blue',
			__( 'Notices and other laws', 'takedown-desk' ),
			'tkd-set-laws',
			eu,
			pn,
			el( 'p', { class: 'tkd-note' }, icon( 'clock' ), el( 'span', { text: __( 'A request still open 6 days after it arrived gets one status update to the requester, as California law asks for social media platforms. You can turn it off under Email templates.', 'takedown-desk' ) } ) )
		);
	}

	// Settings card: copyright (DMCA) notices. Off by default; its switch also shows the copyright emails under Email templates.
	function dmcaCard( data, v, h ) {
		const on = h.toggle(
			'dmca',
			__( 'Accept copyright notices', 'takedown-desk' ),
			__( 'Adds a copyright notice form, a Copyright filter under Requests and the copyright notice emails. Notices follow the target time below, not the 48-hour deadline.', 'takedown-desk' )
		);
		on.querySelector( 'input' ).addEventListener( 'change', ( e ) => {
			const mails = document.getElementById( 'tkd-mails-dmca' );
			if ( mails ) {
				mails.hidden = ! e.target.checked;
			}
		} );
		const days = h.fld(
			'dmca_days',
			{
				type: 'select',
				label: __( 'Target time', 'takedown-desk' ),
				help: __( 'How long your team aims to take to act on a notice, counted from when it arrives. Saturdays and Sundays do not count. The DMCA asks you to act expeditiously and sets no number of days.', 'takedown-desk' ),
				value: String( v.dmca_days || 2 ),
				/* translators: %d: number of business days. */
				options: [ ...Array( 10 ) ].map( ( x, i ) => [ String( i + 1 ), sprintf( _n( '%d business day', '%d business days', i + 1, 'takedown-desk' ), i + 1 ) ] ),
			},
			h.num
		);
		const agent = h.fld( 'dmca_agent_date', {
			type: 'date',
			label: __( 'Designated agent registered or renewed on', 'takedown-desk' ),
			help: __( 'Register a designated agent with the US Copyright Office so people know where to send notices. A registration expires after 3 years unless you renew it. The status card reminds you 60 days before.', 'takedown-desk' ),
		} );
		const date = agent.querySelector( 'input' );
		const renew = el( 'p', { class: 'tkd-note' } );
		// Renew by: 3 years after the date in the field, updated as it changes.
		const due = () => {
			const m = /^(\d{4})-(\d\d)-(\d\d)$/.exec( date.value );
			renew.hidden = ! m;
			if ( m ) {
				const when = new Date( Date.UTC( +m[ 1 ] + 3, m[ 2 ] - 1, +m[ 3 ] ) ).toLocaleDateString( document.documentElement.lang || undefined, { timeZone: 'UTC', year: 'numeric', month: 'long', day: 'numeric' } );
				/* translators: %s: date. */
				renew.replaceChildren( icon( 'clock' ), el( 'span', { text: sprintf( __( 'Renew by %s.', 'takedown-desk' ), when ) } ) );
			}
		};
		date.addEventListener( 'input', due );
		due();
		// The page that holds the copyright form: its state, then Create the page or Edit page.
		const pageBox = el( 'div', { class: 'tkd-field', id: 'tkd-dmca-page', tabindex: '-1' } );
		const drawPage = ( p ) => {
			const live = 'publish' === p.status;
			const make = btn( __( 'Create the page', 'takedown-desk' ), {
				icon: 'plus',
				onclick: () => {
					busy( make, true );
					api( 'dmca-page', 'POST' ).then(
						( res ) => {
							drawPage( { id: res.page_id, url: res.url, edit_url: res.edit_url, status: res.status || ( res.url ? 'publish' : 'draft' ) } );
							toast( __( 'Copyright notice page created.', 'takedown-desk' ) );
							( pageBox.querySelector( 'a' ) || pageBox ).focus();
						},
						( err ) => {
							busy( make, false );
							toast( errMsg( err ), 'error' );
						}
					);
				},
			} );
			// Native replaceChildren() prints null as text, so empty slots are filtered out.
			pageBox.replaceChildren( ...[
				el( 'p', { class: 'tkd-label', text: __( 'Copyright notice page', 'takedown-desk' ) } ),
				p.id
					? el(
							'div',
							{ class: 'tkd-chips' },
							el( 'span', { class: 'tkd-chip tkd-chip--' + ( live ? 'green' : 'amber' ) }, el( 'span', { class: 'tkd-chip__dot', 'aria-hidden': 'true' } ), live ? __( 'Published', 'takedown-desk' ) : __( 'Not published', 'takedown-desk' ) ),
							live && p.url ? linkChip( __( 'View page', 'takedown-desk' ), p.url, true ) : null,
							p.edit_url ? linkChip( __( 'Edit page', 'takedown-desk' ), p.edit_url, true, 'edit' ) : null
					  )
					: el( 'div', { class: 'tkd-row2' }, muted( __( 'No page holds the copyright notice form yet.', 'takedown-desk' ) ), make ),
				p.id && false === p.has_form ? el( 'p', { class: 'tkd-help', text: __( 'This page has no copyright notice form. Add the Copyright notice form block or the shortcode below.', 'takedown-desk' ) } ) : null,
				copyBox( __( 'Shortcode for the form', 'takedown-desk' ), '[takedown_desk_copyright_form]' ),
			].filter( Boolean ) );
		};
		drawPage( ( data.dmca && data.dmca.page ) || { id: 0 } );
		return card(
			'copyright',
			'orange',
			__( 'Copyright notices (DMCA)', 'takedown-desk' ),
			'tkd-set-dmca',
			on,
			days,
			h.toggle( 'dmca_footer_link', __( 'Show a Copyright notices link in the footer', 'takedown-desk' ), __( 'Adds a second link next to the removal request link once the copyright notice page is published.', 'takedown-desk' ) ),
			agent,
			renew,
			el( 'div', { class: 'tkd-actions' }, linkChip( __( 'US Copyright Office agent directory', 'takedown-desk' ), 'https://www.copyright.gov/dmca-directory/', true ) ),
			pageBox
		);
	}

	function renderSettings() {
		const my = ++seq;
		const skel = skeletonCards();
		unsaved = false;
		panel.replaceChildren( view( true, hero( __( 'Settings', 'takedown-desk' ), __( 'How your removal process works, who handles it and what people are told.', 'takedown-desk' ) ), skel ) );
		focusPending();
		Promise.all( [ api( 'settings' ), api( 'status' ).then( ( s ) => s, () => ( {} ) ) ] ).then(
			( res ) => {
				if ( my === seq ) {
					skel.replaceWith( drawSettings( res[ 0 ], res[ 1 ] ) );
				}
			},
			() => {
				if ( my === seq ) {
					skel.replaceWith( errorCard( __( 'We could not load settings. Check your connection and try again.', 'takedown-desk' ), renderSettings ) );
				}
			}
		);
	}

	function previewEmail( key, label ) {
		api( 'email-preview?key=' + encodeURIComponent( key ) ).then(
			( p ) =>
				dialog( {
					title: label,
					wide: true,
					closeLabel: __( 'Close', 'takedown-desk' ),
					body: el(
						'div',
						{ class: 'tkd-email' },
						el( 'p', { class: 'tkd-email__subject' }, el( 'span', { class: 'tkd-email__key', text: __( 'Subject', 'takedown-desk' ) } ), el( 'span', { text: p.subject } ) ),
						para( p.body )
					),
					buttons: [],
				} ),
			( e ) => toast( errMsg( e ), 'error' )
		);
	}

	function deleteTestData( n ) {
		const go = btn( __( 'Delete test data', 'takedown-desk' ), {
			kind: 'danger',
			icon: 'trash',
			onclick: () => {
				busy( go, true );
				api( 'test-requests', 'DELETE' ).then(
					( res ) => {
						d.returnTo = null;
						d.close();
						/* translators: %d: number of test requests. */
						toast( sprintf( _n( 'Deleted %d test request.', 'Deleted %d test requests.', res.deleted || 0, 'takedown-desk' ), res.deleted || 0 ) );
						pending = 'h1';
						renderSettings();
					},
					( e ) => {
						busy( go, false );
						toast( errMsg( e ), 'error' );
					}
				);
			},
		} );
		const d = dialog( {
			title: __( 'Delete test data?', 'takedown-desk' ),
			cancelFirst: true,
			/* translators: %d: number of test requests. */
			body: el( 'p', { text: sprintf( _n( '%d test request, its items and the private test post will be deleted.', '%d test requests, their items and the private test posts will be deleted.', n, 'takedown-desk' ), n ) } ),
			buttons: [ go ],
		} );
	}

	// One block in "Blocks and shortcodes": its editor title, what it is for, how to add it, and its shortcode.
	function blockRow( title, code, desc ) {
		/* translators: %s: block title, for example Removal request link. */
		const input = el( 'input', { class: 'tkd-input tkd-mono', readonly: true, value: code, 'aria-label': sprintf( __( 'Shortcode for %s', 'takedown-desk' ), title ) } );
		const copy = btn( __( 'Copy', 'takedown-desk' ), { icon: 'copy', onclick: () => copyText( input ) } );
		/* translators: %s: block title, for example Removal request link. */
		copy.setAttribute( 'aria-label', sprintf( __( 'Copy the %s shortcode', 'takedown-desk' ), title ) );
		return el(
			'li',
			{ class: 'tkd-blockrow' },
			el( 'span', { class: 'tkd-strong', text: title } ),
			/* translators: %s: block title, for example Removal request link. */
			el( 'span', { class: 'tkd-help', text: desc + ' ' + sprintf( __( 'In the editor, click + and search for %s.', 'takedown-desk' ), title ) } ),
			el( 'div', { class: 'tkd-copybox' }, input, copy )
		);
	}

	// "Policy text": a section for terms of service or community rules, to copy or to start a draft page with.
	function policyText( text ) {
		const id = uid();
		const area = el( 'textarea', { id, class: 'tkd-input tkd-policy', readonly: true, rows: 14, value: text } );
		const make = btn( __( 'Create a draft page', 'takedown-desk' ), {
			icon: 'plus',
			onclick: () => {
				busy( make, true );
				api( 'policy-page', 'POST' ).then(
					( res ) => {
						make.replaceWith( linkChip( __( 'Open the draft page', 'takedown-desk' ), res.edit_url, true ) );
						toast( __( 'Draft page created. It is not published.', 'takedown-desk' ) );
					},
					( err ) => {
						busy( make, false );
						toast( errMsg( err ), 'error' );
					}
				);
			},
		} );
		return [
			el( 'p', { class: 'tkd-help', text: __( 'A short section for your terms of service or community rules: no intimate images shared without consent, no deepfakes of real people, how to report and what happens next. It links to your notice page.', 'takedown-desk' ) } ),
			el( 'div', { class: 'tkd-field' }, el( 'label', { for: id, class: 'tkd-label', text: __( 'Suggested text', 'takedown-desk' ) } ), area ),
			cfg.noticeUrl ? null : el( 'p', { class: 'tkd-help', text: __( 'Your notice page is not published yet, so the text shows a placeholder where its link goes.', 'takedown-desk' ) } ),
			el( 'p', { class: 'tkd-note' }, icon( 'info' ), el( 'span', { text: __( 'This is not legal advice. Read the text and adapt it to your site before you use it.', 'takedown-desk' ) } ) ),
			el( 'div', { class: 'tkd-actions' }, btn( __( 'Copy text', 'takedown-desk' ), { icon: 'copy', onclick: () => copyText( area ) } ), make ),
		];
	}

	function drawSettings( data, status ) {
		const v = data.settings || {};
		const reg = {}; // Setting key => { get, set (shows a red field error) }.
		const save = btn( __( 'Save changes', 'takedown-desk' ), { kind: 'primary', type: 'submit', icon: 'check', disabled: true } );
		const put = ( key, f, get ) => {
			reg[ key ] = { get: get ? () => get( f.input ) : () => f.input.value, set: f.set };
			return f.wrap;
		};
		const fld = ( key, o, get ) => put( key, field( Object.assign( { name: 's_' + key, value: undefined === v[ key ] ? '' : String( v[ key ] ) }, o ) ), get );
		const num = ( input ) => parseInt( input.value, 10 ) || 0;
		const check = ( key, label, more ) => {
			const f = field( Object.assign( { name: 's_' + key, type: 'checkbox', label }, more ) );
			f.input.checked = !! v[ key ];
			return put( key, f, ( input ) => input.checked );
		};
		// A setting that is simply on or off: title and description in one row, switch and its state on the right.
		const toggle = ( key, title, desc ) => {
			const id = uid();
			const input = el( 'input', { id, type: 'checkbox', role: 'switch', name: 's_' + key, 'aria-describedby': id + 'd' } );
			input.checked = !! v[ key ];
			const state = el( 'span', { class: 'tkd-switchrow__state', 'aria-hidden': 'true' } );
			const sync = () => {
				state.textContent = input.checked ? __( 'On', 'takedown-desk' ) : __( 'Off', 'takedown-desk' );
			};
			input.addEventListener( 'change', sync );
			sync();
			const row = el(
				'label',
				{ for: id, class: 'tkd-switchrow' },
				el( 'span', { class: 'tkd-switchrow__text' }, el( 'span', { class: 'tkd-switchrow__title', text: title } ), el( 'span', { id: id + 'd', class: 'tkd-switchrow__desc', text: desc } ) ),
				el( 'span', { class: 'tkd-switchrow__ctl' }, state, input )
			);
			return put( key, { wrap: el( 'div', { class: 'tkd-field' }, row ), input, set: () => false }, ( i ) => i.checked );
		};
		const group = ( key, legend, options, help ) => {
			const cur = v[ key ] || [];
			const err = el( 'p', { class: 'tkd-error', hidden: true } );
			const boxes = options.map( ( o ) => el( 'input', { type: 'checkbox', value: o[ 0 ], checked: cur.includes( o[ 0 ] ) } ) );
			reg[ key ] = {
				get: () => boxes.filter( ( b ) => b.checked ).map( ( b ) => b.value ),
				set: ( msg ) => {
					err.hidden = ! msg;
					err.replaceChildren( ...( msg ? [ icon( 'alert' ), el( 'span', { text: msg } ) ] : [] ) );
					return !! msg;
				},
			};
			return el(
				'fieldset',
				{ class: 'tkd-field' },
				el( 'legend', { class: 'tkd-label', text: legend } ),
				help ? el( 'p', { class: 'tkd-help', text: help } ) : null,
				el( 'div', { class: 'tkd-checkgrid' }, options.map( ( o, i ) => el( 'label', { class: 'tkd-option' }, boxes[ i ], el( 'span', { text: o[ 1 ] } ) ) ) ),
				err
			);
		};
		const choice = ( key, legend, options ) => {
			let cur = v[ key ];
			reg[ key ] = { get: () => cur, set: () => false };
			return radios( 's_' + key, legend, options, cur, ( x ) => {
				cur = x;
			} );
		};
		const types = ( data.post_types || [] ).map( ( p ) => [ p.name, typeLabel( p ) ] );

		// 1. Notice and links.
		const pageField = fld( 'notice_page_id', { type: 'select', label: __( 'Notice page', 'takedown-desk' ), options: [ [ '0', __( 'No page selected', 'takedown-desk' ) ] ].concat( ( data.pages || [] ).map( ( p ) => [ String( p.id ), p.title ] ) ) }, num );
		const create = btn( __( 'Create the notice page', 'takedown-desk' ), {
			icon: 'plus',
			onclick: ( e ) => {
				const b = e.currentTarget;
				busy( b, true );
				api( 'setup/notice-page', 'POST', { action: 'create' } ).then(
					( res ) => {
						busy( b, false );
						const sel = pageField.querySelector( 'select' );
						if ( ! [ ...sel.options ].some( ( o ) => o.value === String( res.page_id ) ) ) {
							sel.append( el( 'option', { value: String( res.page_id ), text: __( 'Request removal of intimate images', 'takedown-desk' ) } ) );
						}
						sel.value = String( res.page_id );
						cfg.noticeUrl = res.url || cfg.noticeUrl;
						toast( __( 'Notice page created.', 'takedown-desk' ) );
					},
					( err ) => {
						busy( b, false );
						toast( errMsg( err ), 'error' );
					}
				);
			},
		} );
		let footer = v.footer_link || 'auto';
		reg.footer_link = { get: () => footer, set: () => false };

		const cards = [
			card(
				'link',
				'blue',
				__( 'Notice and links', 'takedown-desk' ),
				'tkd-set-1',
				el( 'div', { class: 'tkd-row2' }, pageField, create ),
				footerChoice( 's_footer_link', footer, ( x ) => {
					footer = x;
				} ),
				fld( 'footer_link_text', { label: __( 'Footer link text', 'takedown-desk' ), attrs: { maxlength: 80 } } ),
				group( 'link_post_types', __( 'Content types that show "Request removal"', 'takedown-desk' ), types ),
				check( 'link_comments', __( 'Show the link under comments', 'takedown-desk' ) ),
				check( 'link_attachments', __( 'Show the link on media pages', 'takedown-desk' ) )
			),
			card(
				'layers',
				'neutral',
				__( 'Blocks and shortcodes', 'takedown-desk' ),
				'tkd-set-blocks',
				el(
					'ul',
					{ class: 'tkd-blocklist' },
					blockRow( __( 'Removal request link', 'takedown-desk' ), '[takedown_desk_link]', __( 'Links to your notice page. Only needed when the automatic footer link is off.', 'takedown-desk' ) ),
					blockRow( __( 'Removal request form', 'takedown-desk' ), '[takedown_desk_form]', __( 'Already on your notice page. Use it only if you build your own page.', 'takedown-desk' ) ),
					blockRow( __( 'Takedown Desk summary', 'takedown-desk' ), '[takedown_desk_summary]', __( 'Optional. Shows your public numbers on the notice page or a transparency page.', 'takedown-desk' ) )
				)
			),
			card(
				'file',
				'green',
				__( 'Request form', 'takedown-desk' ),
				'tkd-set-2',
				fld( 'form_intro', { type: 'textarea', label: __( 'Intro text', 'takedown-desk' ), attrs: { maxlength: 300 } } ),
				fld( 'fallback_email', { type: 'email', label: __( 'Fallback contact email', 'takedown-desk' ), help: __( 'Shown in error messages on the form.', 'takedown-desk' ) } ),
				toggle( 'quick_exit', __( 'Leave this page button', 'takedown-desk' ), __( 'Shows a small button on the request form and status pages that leaves at once for a weather search, for people who may be watched. Pressing Esc twice does the same.', 'takedown-desk' ) ),
				fld( 'allowed_hosts', { type: 'textarea', label: __( 'Allowed site addresses', 'takedown-desk' ), help: __( 'Extra hosts such as a CDN or mapped domain, one per line.', 'takedown-desk' ), value: ( v.allowed_hosts || [] ).join( '\n' ) }, toList )
			),
			spamCard( data, v, { fld, toggle, choice, reg, num } ),
			card(
				'clock',
				'orange',
				__( 'Deadline and reminders', 'takedown-desk' ),
				'tkd-set-3',
				el( 'p', { class: 'tkd-note' }, icon( 'clock' ), el( 'span', { text: __( 'Deadline: 48 hours from receipt (set by the Act)', 'takedown-desk' ) } ) ),
				fld( 'extra_emails', { type: 'textarea', label: __( 'Extra reminder recipients', 'takedown-desk' ), help: __( 'Email addresses that also get reminders, one per line. Up to 10.', 'takedown-desk' ), value: ( v.extra_emails || [] ).join( '\n' ) }, toList ),
				el( 'div', { class: 'tkd-actions' }, btn( __( 'Send a test reminder', 'takedown-desk' ), { icon: 'send', onclick: ( e ) => testEmail( e.currentTarget, 'mod_24h', __( 'Test reminder sent to you.', 'takedown-desk' ) ) } ) )
			),
			card(
				'trash',
				'cream',
				__( 'Removal', 'takedown-desk' ),
				'tkd-set-4',
				choice( 'removal_default', __( 'Default method', 'takedown-desk' ), [
					[ 'offline', __( 'Take offline', 'takedown-desk' ) ],
					[ 'delete', __( 'Delete permanently', 'takedown-desk' ) ],
				] ),
				fld(
					'purge_removed_days',
					{
						type: 'select',
						label: __( 'Delete items that were taken offline after', 'takedown-desk' ),
						/* translators: %d: number of days. */
						options: [ 7, 30, 90 ].map( ( n ) => [ String( n ), sprintf( _n( '%d day', '%d days', n, 'takedown-desk' ), n ) ] ),
					},
					num
				),
				group(
					'removable_types',
					__( 'Content types moderators may remove', 'takedown-desk' ),
					types.concat( [
						[ 'attachment', __( 'Media files', 'takedown-desk' ) ],
						[ 'comment', __( 'Comments', 'takedown-desk' ) ],
					] ),
					__( 'Leave all unchecked to allow the linked types plus media files and comments.', 'takedown-desk' )
				),
				toggle(
					'auto_hide',
					__( 'Hide reported items as soon as a request arrives', 'takedown-desk' ),
					/* translators: %d: number of requests per person per day. */
					sprintf( __( "Use with care: anyone can send a request, so this could be misused to hide content that breaks no rule. Items stay hidden only until you decide. Content by an administrator is never hidden, and nothing is hidden after a person's first %d requests in a day.", 'takedown-desk' ), undefined === data.hide_limit ? 3 : data.hide_limit )
				),
				check( 'cdn_note', __( 'Show a CDN reminder after removal', 'takedown-desk' ) ),
				toggle(
					'jetpack_check',
					__( 'Check Jetpack image CDN copies', 'takedown-desk' ),
					__( 'Only for sites that use the Jetpack image CDN (Site Accelerator). The removal check also asks i0.wp.com to i3.wp.com, run by Automattic, whether they still serve each removed image. Each check sends the image link to that service.', 'takedown-desk' )
				),
				toggle(
					'block_reuploads',
					__( 'Block uploads of removed images', 'takedown-desk' ),
					__( 'Refuses a new upload when it is an exact copy of a file removed after a request. The person uploading only sees that the file cannot be uploaded.', 'takedown-desk' )
				)
			),
			cloudflareCard( data, fld, reg ),
			lawsCard( toggle ),
			dmcaCard( data, v, { fld, toggle, num } ),
			card(
				'shield',
				'neutral',
				__( 'Data', 'takedown-desk' ),
				'tkd-set-5',
				fld(
					'retention_days',
					{
						type: 'select',
						label: __( 'Delete requester contact details and request text', 'takedown-desk' ),
						help: __( 'Requests marked as involving a person under 18 are kept for at least 1 year.', 'takedown-desk' ),
						/* translators: %d: number of days. */
						options: [ 30, 90, 180, 365, 730 ].map( ( n ) => [ String( n ), sprintf( _n( '%d day after a request is closed', '%d days after a request is closed', n, 'takedown-desk' ), n ) ] ),
					},
					num
				),
				fld(
					'log_retention_years',
					{
						type: 'select',
						label: __( 'Keep the record of actions', 'takedown-desk' ),
						options: [ [ '0', __( 'Forever', 'takedown-desk' ) ] ].concat( [ 2, 5, 7 ].map( ( n ) => [ String( n ), /* translators: %d: number of years. */ sprintf( _n( '%d year', '%d years', n, 'takedown-desk' ), n ) ] ) ),
					},
					num
				),
				keyBlock( data.key ),
				check( 'delete_on_uninstall', __( 'Delete all Takedown Desk data when the plugin is deleted', 'takedown-desk' ), { help: __( 'Requests marked as involving a person under 18 are kept for at least 1 year.', 'takedown-desk' ) } ),
				status.test_requests > 0
					? el( 'div', { class: 'tkd-actions' }, btn( __( 'Delete test data', 'takedown-desk' ), { icon: 'trash', onclick: () => deleteTestData( status.test_requests ) } ) )
					: null
			),
			card(
				'users',
				'blue',
				__( 'Team', 'takedown-desk' ),
				'tkd-set-6',
				el( 'p', { class: 'tkd-help', text: __( 'These people can act on requests.', 'takedown-desk' ) } ),
				team( data.moderators || [] ),
				el( 'div', { class: 'tkd-actions' }, linkChip( __( 'Manage users', 'takedown-desk' ), cfg.usersUrl ) )
			),
			card(
				'send',
				'green',
				__( 'Email templates', 'takedown-desk' ),
				'tkd-set-7',
				el(
					'div',
					{ class: 'tkd-grid2' },
					fld( 'from_name', { label: __( 'From name', 'takedown-desk' ), help: __( 'Leave empty to use the site name.', 'takedown-desk' ) } ),
					fld( 'reply_to', { type: 'email', label: __( 'Reply-to address', 'takedown-desk' ), help: __( 'Leave empty to use the admin email address.', 'takedown-desk' ) } )
				),
				fld( 'email_footer', { type: 'textarea', label: __( 'Footer line', 'takedown-desk' ), attrs: { maxlength: 300 } } ),
				mailRows( data, reg )
			),
			card( 'note', 'cream', __( 'Policy text', 'takedown-desk' ), 'tkd-set-policy', policyText( data.policy_text || '' ) ),
		];

		// Save stays disabled until something changes; while it is enabled, leaving asks first (see route()).
		const note = el( 'p', { class: 'tkd-savebar__text' } );
		const discard = btn( __( 'Discard changes', 'takedown-desk' ), {
			onclick: () => {
				unsaved = false;
				pending = 'h1';
				renderSettings();
			},
		} );
		const clean = ( on ) => {
			unsaved = ! on;
			save.disabled = on;
			discard.hidden = on;
			note.textContent = on ? __( 'No unsaved changes.', 'takedown-desk' ) : __( 'Changes take effect when you save.', 'takedown-desk' );
		};
		const dirty = () => ! unsaved && clean( false );
		clean( true );
		const nav = el(
			'nav',
			{ class: 'tkd-secnav', 'aria-label': __( 'Settings sections', 'takedown-desk' ) },
			cards.map( ( c ) => {
				const h = c.querySelector( 'h2' );
				// In-page jump without touching the hash, which the tab router owns.
				return el(
					'a',
					{
						class: 'tkd-secnav__link',
						href: '#' + h.id,
						onclick: ( e ) => {
							e.preventDefault();
							h.scrollIntoView( { block: 'start' } );
							h.focus( { preventScroll: true } );
						},
					},
					h.textContent
				);
			} )
		);
		const form = el(
			'form',
			{
				class: 'tkd-settings',
				novalidate: true,
				oninput: dirty,
				onchange: dirty,
				onsubmit: ( e ) => {
					e.preventDefault();
					const values = {};
					Object.keys( reg ).forEach( ( k ) => {
						reg[ k ].set( '' );
						values[ k ] = reg[ k ].get();
					} );
					busy( save, true );
					api( 'settings', 'POST', { settings: values } ).then(
						() => {
							busy( save, false );
							clean( true );
							Object.keys( reg ).forEach( ( k ) => reg[ k ].saved && reg[ k ].saved() );
							cfg.dmca = !! values.dmca; // The inbox chip and the dialog choice follow without a page reload.
							toast( __( 'Settings saved.', 'takedown-desk' ) );
						},
						( err ) => {
							busy( save, false );
							const fields = ( err.data && err.data.fields ) || {};
							Object.keys( fields ).forEach( ( k ) => reg[ k ] && reg[ k ].set( fields[ k ] ) );
							/* translators: %s: reason. */
							toast( sprintf( __( 'Settings were not saved. %s', 'takedown-desk' ), errMsg( err ) ), 'error' );
							const first = form.querySelector( '[aria-invalid="true"]' );
							if ( first ) {
								first.focus();
							}
						}
					);
				},
			},
			nav,
			el( 'div', { class: 'tkd-settings__cards' }, cards ),
			el( 'div', { class: 'tkd-savebar' }, note, el( 'div', { class: 'tkd-actions' }, discard, save ) )
		);
		return form;
	}

	/* ---------- Setup (#setup) ---------- */
	const setup = { step: 1, ugc: '', main: '', footer: 'auto', types: null, comments: false, media: true, testId: 0, data: null, status: null, poll: null };
	// Progress outlives a trip to "Add a moderator" (same tab) and a reload, for this browser tab only.
	const SETUP_KEY = 'tkdesk-setup-' + ( cfg.userId || 0 );
	const KEEP = [ 'step', 'ugc', 'main', 'footer', 'types', 'comments', 'media', 'testId' ];
	const saveSetup = ( clear ) => remember( 'sessionStorage', SETUP_KEY, clear ? {} : KEEP.reduce( ( o, k ) => Object.assign( o, { [ k ]: setup[ k ] } ), {} ) );
	const VERDICTS = {
		covered: [ 'green', __( 'Likely covered', 'takedown-desk' ), __( 'Sites built around photos, videos or posts that members share are the platforms the Act is written for. Plan on having a removal process.', 'takedown-desk' ) ],
		grey: [ 'amber', __( 'Grey area', 'takedown-desk' ), __( 'Members can post, but it is not the main thing your site offers. The Act may still apply. A simple removal process costs little and protects people.', 'takedown-desk' ) ],
		excluded: [ 'neutral', __( 'Likely excluded', 'takedown-desk' ), __( 'The Act leaves out sites whose main content comes from the site itself, where comments or other member features are a small extra.', 'takedown-desk' ) ],
		not_covered: [ 'neutral', __( 'Likely not covered', 'takedown-desk' ), __( "If members cannot post anything, the Act's removal duty most likely does not apply to your site.", 'takedown-desk' ) ],
	};
	const verdict = () => {
		if ( 'no' === setup.ugc ) {
			return 'not_covered';
		}
		return 'yes' === setup.ugc ? { yes: 'covered', part: 'grey', no: 'excluded' }[ setup.main ] || '' : '';
	};
	const answers = () => ( { ugc: setup.ugc, main: setup.main, verdict: verdict() } );

	function setupHero() {
		return hero(
			__( 'Set up Takedown Desk', 'takedown-desk' ),
			__( 'From install to a working removal process in about three minutes.', 'takedown-desk' ),
			btn( __( 'Skip setup', 'takedown-desk' ), { icon: 'next', end: true, onclick: ( e ) => finishSetup( e.currentTarget, { answers: answers() } ) } )
		);
	}

	function renderSetup() {
		const my = ++seq;
		const skel = skeletonCards();
		panel.replaceChildren( view( true, setupHero(), skel ) );
		focusPending();
		Promise.all( [ api( 'settings' ), api( 'status' ) ] ).then(
			( res ) => {
				if ( my !== seq ) {
					return;
				}
				setup.data = res[ 0 ];
				setup.status = res[ 1 ];
				const s = setup.data.settings || {};
				if ( null === setup.types ) {
					const saved = remember( 'sessionStorage', SETUP_KEY );
					KEEP.forEach( ( k ) => {
						if ( undefined !== saved[ k ] ) {
							setup[ k ] = saved[ k ];
						}
					} );
				}
				if ( null === setup.types ) {
					// Detected member-content types start checked.
					Object.assign( setup, {
						footer: s.footer_link || 'auto',
						types: ( s.link_post_types || [] ).length ? s.link_post_types : ( setup.data.post_types || [] ).filter( ( p ) => p.ugc ).map( ( p ) => p.name ),
						comments: !! s.link_comments,
						media: false !== s.link_attachments,
					} );
				}
				skel.replaceWith( ...setupBody() );
			},
			() => {
				if ( my === seq ) {
					skel.replaceWith( errorCard( __( 'We could not load setup. Check your connection and try again.', 'takedown-desk' ), renderSetup ) );
				}
			}
		);
	}

	const setupSteps = () => [ step1, step2, step3, step4, stepContact, step5 ];
	const setupBody = () => [ stepper(), ( setupSteps()[ setup.step - 1 ] || step1 )() ];

	function goStep( n ) {
		setup.step = n;
		saveSetup();
		const v = panel.querySelector( '.tkd-view' );
		while ( v.children.length > 1 ) {
			v.lastChild.remove();
		}
		add( v, setupBody() );
		panel.querySelector( '#tkd-step-h' ).focus();
	}

	function stepper() {
		const names = [ __( 'Does the Act apply?', 'takedown-desk' ), __( 'Notice page', 'takedown-desk' ), __( 'Where links appear', 'takedown-desk' ), __( 'Who handles requests', 'takedown-desk' ), __( 'Contact email', 'takedown-desk' ), __( 'Try it once', 'takedown-desk' ) ];
		return el(
			'ol',
			{ class: 'tkd-steps', 'aria-label': __( 'Setup steps', 'takedown-desk' ) },
			names.map( ( n, i ) => {
				const done = i + 1 < setup.step;
				return el(
					'li',
					{ class: 'tkd-step' + ( done ? ' is-done' : '' ), 'aria-current': i + 1 === setup.step ? 'step' : null },
					el( 'span', { class: 'tkd-step__num', 'aria-hidden': 'true' }, done ? icon( 'check' ) : String( i + 1 ) ),
					el( 'span', { class: 'tkd-step__label', text: n } ),
					done ? el( 'span', { class: 'tkd-sr', text: ' ' + __( '(done)', 'takedown-desk' ) } ) : null
				);
			} )
		);
	}

	function stepCard( title, kids, actions ) {
		return el(
			'section',
			{ class: 'tkd-card tkd-stepcard', 'aria-labelledby': 'tkd-step-h' },
			el(
				'div',
				{ class: 'tkd-card__body' },
				/* translators: 1: step number, 2: number of steps. */
				el( 'p', { class: 'tkd-eyebrow', text: sprintf( __( 'Step %1$d of %2$d', 'takedown-desk' ), setup.step, setupSteps().length ) } ),
				el( 'h2', { id: 'tkd-step-h', class: 'tkd-stepcard__title', tabindex: '-1', text: title } ),
				kids,
				el(
					'div',
					{ class: 'tkd-stepnav' },
					setup.step > 1 ? btn( __( 'Back', 'takedown-desk' ), { icon: 'prev', onclick: () => goStep( setup.step - 1 ) } ) : null,
					el( 'span', { class: 'tkd-actions tkd-stepnav__next' }, actions )
				)
			)
		);
	}

	// Continue, saving this step's settings first when it has any. `get` returns false to stay (it showed an error).
	function contBtn( get, fields ) {
		return btn( __( 'Continue', 'takedown-desk' ), {
			kind: 'primary',
			icon: 'next',
			end: true,
			onclick: ( e ) => {
				const b = e.currentTarget;
				const settings = get && get();
				if ( false === settings ) {
					return;
				}
				if ( ! settings ) {
					goStep( setup.step + 1 );
					return;
				}
				busy( b, true );
				api( 'settings', 'POST', { settings } ).then(
					( res ) => {
						Object.assign( setup.data, res );
						goStep( setup.step + 1 );
					},
					( err ) => {
						busy( b, false );
						const bad = Object.keys( ( err.data && err.data.fields ) || {} ).filter( ( k ) => fields && fields[ k ] && fields[ k ].set( err.data.fields[ k ] ) );
						if ( bad.length ) {
							fields[ bad[ 0 ] ].input.focus();
						} else {
							toast( errMsg( err ), 'error' );
						}
					}
				);
			},
		} );
	}

	function finishSetup( b, body, linksOff ) {
		busy( b, true );
		api( 'setup-done', 'POST', body ).then(
			() => {
				cfg.setupDone = true;
				saveSetup( true );
				tabs = tabs.filter( ( t ) => 'setup' !== t.id );
				const tab = document.getElementById( 'tkd-tab-setup' );
				if ( tab ) {
					tab.remove();
				}
				if ( ! linksOff ) {
					toast( __( 'Setup finished.', 'takedown-desk' ) );
					window.location.hash = '#inbox';
					return;
				}
				selectTab( 'inbox' );
				panel.replaceChildren(
					view(
						true,
						hero( __( 'Setup finished', 'takedown-desk' ), __( 'Takedown Desk is set up with the removal links turned off.', 'takedown-desk' ) ),
						el(
							'div',
							{ class: 'tkd-card tkd-empty' },
							art( 'shield' ),
							el( 'p', { class: 'tkd-empty__text', text: __( 'If members start posting on your site later, turn the links on in Settings > Notice and links. The record of actions and "Log a request" work either way.', 'takedown-desk' ) } ),
							el( 'div', { class: 'tkd-actions' }, linkChip( __( 'Go to requests', 'takedown-desk' ), '#inbox' ), linkChip( __( 'Open settings', 'takedown-desk' ), '#settings', false, 'gear' ) )
						)
					)
				);
				panel.querySelector( 'h1' ).focus();
			},
			( e ) => {
				busy( b, false );
				toast( errMsg( e ), 'error' );
			}
		);
	}

	function step1() {
		const out = el( 'div', { class: 'tkd-verdict-slot', 'aria-live': 'polite' } );
		const acts = el( 'span', { class: 'tkd-actions' } );
		const draw = () => {
			const k = verdict();
			const vd = VERDICTS[ k ];
			out.replaceChildren(
				...( vd
					? [
							el(
								'div',
								{ class: 'tkd-verdict tkd-verdict--' + vd[ 0 ] },
								el( 'p', { class: 'tkd-verdict__title' }, icon( 'covered' === k ? 'shield' : 'info' ), el( 'span', { text: vd[ 1 ] } ) ),
								el( 'p', { text: vd[ 2 ] } ),
								el( 'p', { class: 'tkd-verdict__note', text: __( 'This is a first read, not legal advice.', 'takedown-desk' ) } )
							),
					  ]
					: [] )
			);
			acts.replaceChildren(
				...( 'excluded' === k || 'not_covered' === k ? [ btn( __( 'I do not need this', 'takedown-desk' ), { onclick: ( e ) => finishSetup( e.currentTarget, { answers: answers(), links_off: true }, true ) } ) ] : [] ),
				contBtn()
			);
		};
		const q2 = el(
			'div',
			{ hidden: 'yes' !== setup.ugc },
			radios(
				'tkd-q2',
				__( 'Is that the main thing your site offers?', 'takedown-desk' ),
				[
					[ 'yes', __( 'Yes, it is the main thing', 'takedown-desk' ) ],
					[ 'part', __( 'It is one important part', 'takedown-desk' ) ],
					[ 'no', __( 'No, it is a small extra, such as comments', 'takedown-desk' ) ],
				],
				setup.main,
				( x ) => {
					setup.main = x;
					draw();
				}
			)
		);
		const q1 = radios(
			'tkd-q1',
			__( 'Can members post their own photos, videos or posts?', 'takedown-desk' ),
			[
				[ 'yes', __( 'Yes', 'takedown-desk' ) ],
				[ 'no', __( 'No', 'takedown-desk' ) ],
			],
			setup.ugc,
			( x ) => {
				setup.ugc = x;
				q2.hidden = 'yes' !== x;
				draw();
			}
		);
		draw();
		return stepCard( __( 'Does the Act apply to your site?', 'takedown-desk' ), [ el( 'div', { class: 'tkd-split' }, el( 'div', { class: 'tkd-form' }, q1, q2 ), out ) ], acts );
	}

	function step2() {
		const np = setup.status && setup.status.notice_page && setup.status.notice_page.id ? setup.status.notice_page : null;
		const call = ( b, body, msg ) => {
			busy( b, true );
			api( 'setup/notice-page', 'POST', body ).then(
				( res ) => {
					setup.status.notice_page = { id: res.page_id, url: res.url, status: 'publish', has_form: true };
					cfg.noticeUrl = res.url || cfg.noticeUrl;
					toast( msg );
					goStep( 2 );
				},
				( e ) => {
					busy( b, false );
					toast( errMsg( e ), 'error' );
				}
			);
		};
		let page;
		if ( np ) {
			page = el( 'p', { class: 'tkd-note' }, icon( 'done' ), el( 'span', { text: __( 'Your notice page is ready.', 'takedown-desk' ) } ), np.url ? linkChip( __( 'View your notice page', 'takedown-desk' ), np.url, true ) : null );
		} else {
			const pick = field( {
				name: 'setup_page',
				type: 'select',
				label: __( 'Use an existing page', 'takedown-desk' ),
				options: [ [ '', __( 'Choose a page', 'takedown-desk' ) ] ].concat( ( setup.data.pages || [] ).map( ( p ) => [ String( p.id ), p.title ] ) ),
			} );
			const use = btn( __( 'Use this page', 'takedown-desk' ), { disabled: true, onclick: ( e ) => call( e.currentTarget, { page_id: parseInt( pick.input.value, 10 ), action: 'add_form' }, __( 'Notice page set.', 'takedown-desk' ) ) } );
			pick.input.addEventListener( 'change', () => {
				use.disabled = ! pick.input.value;
			} );
			page = el(
				'div',
				{ class: 'tkd-form' },
				el( 'div', { class: 'tkd-actions' }, btn( __( 'Create the notice page', 'takedown-desk' ), { kind: 'primary', icon: 'plus', onclick: ( e ) => call( e.currentTarget, { action: 'create' }, __( 'Notice page created.', 'takedown-desk' ) ) } ) ),
				el( 'div', { class: 'tkd-row2' }, pick.wrap, use )
			);
		}
		const preview = el(
			'figure',
			{ class: 'tkd-notice' },
			el( 'figcaption', { class: 'tkd-label', text: __( 'Preview of the default notice', 'takedown-desk' ) } ),
			el(
				'div',
				{ class: 'tkd-notice__page' },
				el( 'p', { class: 'tkd-notice__title', text: __( 'Request removal of intimate images', 'takedown-desk' ) } ),
				// The same paragraphs the notice page prints (tkdesk_notice_paragraphs()), so the preview never drifts.
				( cfg.noticeText || [] ).map( ( t ) => el( 'p', { class: 'tkd-notice__p', text: t } ) ),
				el( 'div', { class: 'tkd-notice__form', 'aria-hidden': 'true' }, el( 'span' ), el( 'span' ), el( 'span', { class: 'is-tall' } ), el( 'span', { class: 'tkd-notice__btn' } ) )
			)
		);
		return stepCard(
			__( 'Create your notice page', 'takedown-desk' ),
			[
				el( 'p', { class: 'tkd-lead', text: __( 'People who need an image removed start here. The page explains the process and holds the request form.', 'takedown-desk' ) } ),
				el( 'div', { class: 'tkd-split' }, el( 'div', { class: 'tkd-form' }, page, footerChoice( 'tkd-setup-footer', setup.footer, ( x ) => {
					setup.footer = x;
				} ) ), preview ),
			],
			contBtn( () => ( { footer_link: setup.footer } ) )
		);
	}

	function step3() {
		const types = setup.data.post_types || [];
		const linkText = ( setup.data.settings || {} ).footer_link_text || __( 'Request removal of intimate images', 'takedown-desk' );
		const mock = el( 'div', { class: 'tkd-mock', 'aria-hidden': 'true' } );
		const where = el( 'p', { class: 'tkd-help', 'aria-live': 'polite' } );
		const draw = () => {
			const link = () => el( 'span', { class: 'tkd-mock__link', text: __( 'Request removal', 'takedown-desk' ) } );
			mock.replaceChildren(
				el( 'div', { class: 'tkd-mock__bar' }, el( 'span' ), el( 'span' ), el( 'span' ) ),
				el( 'div', { class: 'tkd-mock__post' }, el( 'span', { class: 'tkd-mock__title' } ), el( 'span', { class: 'tkd-mock__img' }, icon( 'image' ) ), el( 'span', { class: 'tkd-mock__line' } ), el( 'span', { class: 'tkd-mock__line is-short' } ), setup.types.length ? link() : null ),
				setup.comments ? el( 'div', { class: 'tkd-mock__comment' }, el( 'span', { class: 'tkd-mock__avatar' } ), el( 'span', { class: 'tkd-mock__line' } ), link() ) : null,
				'auto' === setup.footer ? el( 'div', { class: 'tkd-mock__footer', text: linkText } ) : null
			);
			const on = types
				.filter( ( p ) => setup.types.includes( p.name ) )
				.map( typeLabel )
				.concat( setup.comments ? [ __( 'Comments', 'takedown-desk' ) ] : [], setup.media ? [ __( 'Media pages', 'takedown-desk' ) ] : [] );
			/* translators: %s: list of places. */
			where.textContent = on.length ? sprintf( __( 'The "Request removal" link appears on: %s.', 'takedown-desk' ), on.join( ', ' ) ) : __( 'The "Request removal" link does not appear on any content yet.', 'takedown-desk' );
		};
		const box = ( label, checked, onChange ) =>
			el(
				'label',
				{ class: 'tkd-option' },
				el( 'input', {
					type: 'checkbox',
					checked,
					onchange: ( e ) => {
						onChange( e.target.checked );
						draw();
					},
				} ),
				el( 'span', { text: label } )
			);
		draw();
		return stepCard(
			__( 'Choose where the "Request removal" link appears', 'takedown-desk' ),
			[
				el( 'p', { class: 'tkd-lead', text: __( 'Types where members post are checked for you. The link takes people to your notice page with the item already filled in.', 'takedown-desk' ) } ),
				el(
					'div',
					{ class: 'tkd-split' },
					el(
						'div',
						{ class: 'tkd-form' },
						el(
							'fieldset',
							{ class: 'tkd-field' },
							el( 'legend', { class: 'tkd-label', text: __( 'Content types', 'takedown-desk' ) } ),
							el(
								'div',
								{ class: 'tkd-checkgrid' },
								types.map( ( p ) =>
									/* translators: %s: content type name. */
									box( p.ugc ? sprintf( __( '%s (detected)', 'takedown-desk' ), typeLabel( p ) ) : typeLabel( p ), setup.types.includes( p.name ), ( on ) => {
										setup.types = on ? setup.types.concat( p.name ) : setup.types.filter( ( x ) => x !== p.name );
									} )
								)
							)
						),
						el(
							'fieldset',
							{ class: 'tkd-field' },
							el( 'legend', { class: 'tkd-label', text: __( 'Also show it on', 'takedown-desk' ) } ),
							el(
								'div',
								{ class: 'tkd-checkgrid' },
								box( __( 'Comments', 'takedown-desk' ), setup.comments, ( on ) => {
									setup.comments = on;
								} ),
								box( __( 'Media pages', 'takedown-desk' ), setup.media, ( on ) => {
									setup.media = on;
								} )
							)
						)
					),
					el( 'figure', { class: 'tkd-preview' }, el( 'figcaption', { class: 'tkd-label', text: __( 'Live preview', 'takedown-desk' ) } ), mock, where )
				),
			],
			contBtn( () => ( { link_post_types: setup.types, link_comments: setup.comments, link_attachments: setup.media } ) )
		);
	}

	function step4() {
		const d = setup.data;
		const emails = field( {
			name: 'setup_emails',
			type: 'textarea',
			label: __( 'Extra reminder emails', 'takedown-desk' ),
			help: __( 'Email addresses that also get reminders, one per line. Up to 10.', 'takedown-desk' ),
			value: ( ( d.settings || {} ).extra_emails || [] ).join( '\n' ),
		} );
		return stepCard(
			__( 'Who handles requests?', 'takedown-desk' ),
			[
				team( d.moderators || [] ),
				el( 'div', { class: 'tkd-actions' }, linkChip( __( 'Add a moderator', 'takedown-desk' ), String( cfg.usersUrl || '' ).replace( /users\.php.*$/, 'user-new.php' ), false, 'plus' ) ),
				emails.wrap,
				el( 'div', { class: 'tkd-actions' }, btn( __( 'Send a test reminder', 'takedown-desk' ), { icon: 'send', onclick: ( e ) => testEmail( e.currentTarget, 'mod_24h', __( 'Test reminder sent to you.', 'takedown-desk' ) ) } ) ),
				keyBlock( d.key ),
			],
			contBtn( () => ( { extra_emails: toList( emails.input ) } ), { extra_emails: emails } )
		);
	}

	// Required: the public form's error messages point people here when the form itself fails them.
	function stepContact() {
		const d = setup.data;
		const f = field( {
			name: 'setup_fallback',
			type: 'email',
			label: __( 'Fallback contact email', 'takedown-desk' ),
			help: __( 'Shown in error messages on the request form. Use an inbox that someone checks every day.', 'takedown-desk' ),
			value: ( d.settings || {} ).fallback_email || d.admin_email || '',
			required: true,
			attrs: { autocomplete: 'email' },
		} );
		// The error clears as soon as the address looks right.
		f.input.addEventListener( 'input', () => {
			if ( f.input.value.trim() && f.input.checkValidity() ) {
				f.set( '' );
			}
		} );
		return stepCard(
			__( 'Where can people reach you?', 'takedown-desk' ),
			[ el( 'p', { class: 'tkd-lead', text: __( 'If someone cannot send the request form, its messages give them this address, so they still have a way to reach you.', 'takedown-desk' ) } ), f.wrap ],
			contBtn( () => {
				const value = f.input.value.trim();
				if ( ! value || ! f.input.checkValidity() ) {
					f.set( value ? __( 'Enter a valid email address.', 'takedown-desk' ) : __( 'Enter an email address.', 'takedown-desk' ) );
					f.input.focus();
					return false;
				}
				return { fallback_email: value };
			}, { fallback_email: f } )
		);
	}

	function step5() {
		const box = el( 'div', { class: 'tkd-trybox', 'aria-live': 'polite' } );
		const skip = btn( __( 'Finish without a test', 'takedown-desk' ), { kind: 'ghost', onclick: ( e ) => finishSetup( e.currentTarget, { answers: answers() } ) } );
		const me = ( setup.poll = {} ); // Only the newest step 5 keeps polling.
		const my = seq;
		const draw = ( ready ) => {
			skip.hidden = !! ready;
			if ( ready ) {
				box.replaceChildren(
					el(
						'div',
						{ class: 'tkd-verdict tkd-verdict--green' },
						el( 'p', { class: 'tkd-verdict__title' }, icon( 'check' ), el( 'span', { text: __( 'You are ready.', 'takedown-desk' ) } ) ),
						el( 'p', { text: __( 'Your test request went from start to finish. Delete the test data any time in Settings > Data.', 'takedown-desk' ) } ),
						el( 'div', { class: 'tkd-actions' }, btn( __( 'Finish setup', 'takedown-desk' ), { kind: 'primary', icon: 'check', onclick: ( e ) => finishSetup( e.currentTarget, { answers: answers() } ) } ) )
					)
				);
			} else if ( setup.testId ) {
				box.replaceChildren(
					el(
						'div',
						{ class: 'tkd-alert tkd-alert--neutral' },
						el( 'span', { class: 'tkd-alert__ico' }, el( 'span', { class: 'tkd-spinner', 'aria-hidden': 'true' } ) ),
						el(
							'div',
							{ class: 'tkd-alert__msg' },
							el( 'p', { class: 'tkd-strong', text: __( 'Waiting for you to remove the test item.', 'takedown-desk' ) } ),
							el( 'p', { text: __( 'Open the test request, remove the item, then come back here. This page checks on its own.', 'takedown-desk' ) } )
						),
						linkChip( __( 'Open the test request', 'takedown-desk' ), '#request/' + setup.testId )
					)
				);
			} else {
				box.replaceChildren(
					btn( __( 'Send a test request', 'takedown-desk' ), {
						kind: 'primary',
						icon: 'send',
						onclick: ( e ) => {
							const b = e.currentTarget;
							busy( b, true );
							api( 'test-request', 'POST' ).then(
								( res ) => {
									setup.testId = res.request_id;
									saveSetup();
									toast( __( 'Test request sent.', 'takedown-desk' ) );
									draw();
									poll();
								},
								( err ) => {
									busy( b, false );
									toast( errMsg( err ), 'error' );
								}
							);
						},
					} )
				);
			}
		};
		const alive = () => my === seq && setup.poll === me && setupSteps().length === setup.step;
		const poll = () => {
			if ( ! setup.testId || ! alive() ) {
				return;
			}
			api( 'requests/' + setup.testId ).then(
				( r ) => {
					if ( ! alive() ) {
						return;
					}
					if ( isOpen( r ) ) {
						setTimeout( poll, 5000 );
					} else {
						draw( true );
					}
				},
				() => alive() && setTimeout( poll, 5000 )
			);
		};
		draw();
		poll();
		return stepCard(
			__( 'Try it once', 'takedown-desk' ),
			[ el( 'p', { class: 'tkd-lead', text: __( 'Send yourself a test request to see the whole process: The clock starts, the emails arrive (only to you), and you remove the test item. Test requests stay out of the record exports and counts.', 'takedown-desk' ) } ), box ],
			skip
		);
	}

	/* ---------- Tabs (ARIA tabs pattern) + hash router ---------- */
	let tabs = []; // Registered in start() through the tkdesk.tabs filter; only registered tabs render.

	const tablist = el( 'div', {
		class: 'tkd-tabs',
		role: 'tablist',
		'aria-label': __( 'Takedown Desk sections', 'takedown-desk' ),
		onkeydown: ( e ) => {
			const list = [ ...tablist.children ];
			const i = list.indexOf( document.activeElement );
			const step = 'rtl' === document.documentElement.dir ? -1 : 1;
			const to = { ArrowRight: i + step, ArrowLeft: i - step, Home: 0, End: list.length - 1 }[ e.key ];
			if ( i < 0 || undefined === to ) {
				return;
			}
			e.preventDefault();
			const target = list[ ( to + list.length ) % list.length ];
			target.focus();
			window.location.hash = '#' + target.dataset.tab;
		},
	} );
	let firstRoute = true;

	function selectTab( id ) {
		[ ...tablist.children ].forEach( ( b ) => {
			const on = b.dataset.tab === id;
			b.setAttribute( 'aria-selected', String( on ) );
			b.tabIndex = on ? 0 : -1;
		} );
		panel.setAttribute( 'aria-labelledby', 'tkd-tab-' + id );
	}

	function route() {
		// Unsaved Settings: ask before leaving; staying puts the address back without another hashchange.
		if ( unsaved && '#settings' !== window.location.hash ) {
			if ( ! window.confirm( __( 'Leave without saving? Your changes to Settings will be lost.', 'takedown-desk' ) ) ) {
				window.history.replaceState( null, '', '#settings' );
				selectTab( 'settings' );
				if ( tablist.contains( document.activeElement ) ) {
					document.getElementById( 'tkd-tab-settings' ).focus();
				}
				return;
			}
			unsaved = false;
		}
		const parts = window.location.hash.replace( /^#\/?/, '' ).split( '/' );
		const request = 'request' === parts[ 0 ] && /^\d+$/.test( parts[ 1 ] || '' );
		const tab = tabs.find( ( t ) => t.id === ( request ? 'inbox' : parts[ 0 ] ) ) || tabs.find( ( t ) => 'inbox' === t.id ) || tabs[ 0 ];
		selectTab( tab.id );
		// Focus follows the new view's heading, except on first load and when a tab was used (focus stays on the tab).
		pending = firstRoute || tablist.contains( document.activeElement ) ? null : 'h1';
		firstRoute = false;
		seq++;
		clocks = [];
		det.active = false;
		if ( request ) {
			openDetail( parseInt( parts[ 1 ], 10 ) );
		} else {
			tab.render( panel, parts.slice( 1 ) );
		}
	}

	function start() {
		root = document.getElementById( 'tkdesk-app' );
		if ( ! root ) {
			return;
		}
		// Setup leads until it is done; Settings and Setup need the settings capability.
		const builtIn = [
			cfg.canManage && ! cfg.setupDone ? { id: 'setup', label: __( 'Setup', 'takedown-desk' ), icon: 'done', render: renderSetup } : null,
			{ id: 'inbox', label: __( 'Requests', 'takedown-desk' ), icon: 'inbox', render: renderInbox },
			{ id: 'log', label: __( 'Record', 'takedown-desk' ), icon: 'list', render: renderLog },
			cfg.canManage ? { id: 'settings', label: __( 'Settings', 'takedown-desk' ), icon: 'gear', render: renderSettings } : null,
		].filter( Boolean );
		tabs = hooks.applyFilters( 'tkdesk.tabs', builtIn ).filter( ( t ) => t && t.id && 'function' === typeof t.render );
		if ( cfg.canManage && ! cfg.setupDone && ! window.location.hash ) {
			window.history.replaceState( null, '', '#setup' );
		}
		tabs.forEach( ( t ) =>
			tablist.append(
				el(
					'button',
					{
						type: 'button',
						role: 'tab',
						id: 'tkd-tab-' + t.id,
						class: 'tkd-tab',
						'aria-controls': 'tkd-panel',
						'aria-selected': 'false',
						tabindex: '-1',
						'data-tab': t.id,
						onclick: () => {
							window.location.hash = '#' + t.id;
						},
					},
					icon( t.icon || 'file' ),
					el( 'span', { text: t.label } )
				)
			)
		);
		root.replaceChildren(
			el(
				'header',
				{ class: 'tkd-header' },
				el( 'div', { class: 'tkd-brand' }, el( 'span', { class: 'tkd-brand__mark' }, brandMark() ), el( 'span', { class: 'tkd-brand__name', text: __( 'Takedown Desk', 'takedown-desk' ) } ) ),
				tablist
			),
			el( 'div', { class: 'tkd-content' }, el( 'div', { class: 'tkd-wrap' }, panel ) ),
			el( 'footer', { class: 'tkd-footer' }, el( 'p', { text: __( 'Takedown Desk is a tool, not legal advice. Talk to a lawyer about your duties under the Act.', 'takedown-desk' ) } ) ),
			toasts
		);
		window.addEventListener( 'hashchange', route );
		window.addEventListener( 'beforeunload', ( e ) => {
			if ( unsaved ) {
				e.preventDefault();
				e.returnValue = ''; // Older browsers need it set to show their own "Leave site?" prompt.
			}
		} );
		route();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window.wp, window.tkdeskAdmin || {} );
