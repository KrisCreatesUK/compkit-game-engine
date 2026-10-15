/**
 * instant-reveal-admin-preview.js
 *
 * CSS-var live preview, tab switching, game interaction, and reset for the
 * Instant Reveal global settings page and the WooCommerce product panel.
 */
jQuery( function ( $ ) {

	/* ── Lighten a hex colour (mirrors PHP lighten_hex) ── */
	function lightenHex( hex, amount ) {
		if ( ! hex || hex.length < 7 ) return hex;
		var r  = parseInt( hex.slice( 1, 3 ), 16 );
		var gv = parseInt( hex.slice( 3, 5 ), 16 );
		var b  = parseInt( hex.slice( 5, 7 ), 16 );
		r  = Math.min( 255, Math.round( r  + ( 255 - r  ) * amount ) );
		gv = Math.min( 255, Math.round( gv + ( 255 - gv ) * amount ) );
		b  = Math.min( 255, Math.round( b  + ( 255 - b  ) * amount ) );
		return '#' + r.toString( 16 ).padStart( 2, '0' )
		           + gv.toString( 16 ).padStart( 2, '0' )
		           + b.toString( 16 ).padStart( 2, '0' );
	}

	/* ── Read a field value by name, with optional fallback ── */
	function gv( fieldName, fallback ) {
		var el = document.querySelector( '[name="' + fieldName + '"]' );
		return ( el && el.value !== undefined && el.value !== '' ) ? el.value : ( fallback || '' );
	}
	function gvRadio( fieldName, fallback ) {
		var el = document.querySelector( '[name="' + fieldName + '"]:checked' );
		return el ? el.value : ( fallback || '' );
	}

	/* ── Push CSS vars onto a container (and cascade to mini style-card icons) ── */
	function pushVars( container, primary, dark, tableHeader, tableAccent ) {
		var body = lightenHex( primary, 0.22 );
		[
			[ '--instant-reveal-primary',      primary     ],
			[ '--instant-reveal-body',         body        ],
			[ '--instant-reveal-dark',         dark        ],
			[ '--instant-reveal-table-header', tableHeader ],
			[ '--instant-reveal-table-accent', tableAccent ],
			[ '--eiwr-primary',      primary     ],
			[ '--eiwr-body',         body        ],
			[ '--eiwr-dark',         dark        ],
			[ '--eiwr-table-header', tableHeader ],
			[ '--eiwr-table-accent', tableAccent ],
		].forEach( function ( pair ) {
			container.style.setProperty( pair[0], pair[1] );
		} );
		/* Update mini style-card icons with theme colours */
		document.querySelectorAll( '.ir-style-cards' ).forEach( function ( el ) {
			el.style.setProperty( '--eiwr-primary', primary );
			el.style.setProperty( '--eiwr-body',    body    );
			el.style.setProperty( '--eiwr-dark',    dark    );
		} );
	}

	/* ════════════════════════════════════════════════════════════
	   PREVIEW GAME INTERACTION
	   ════════════════════════════════════════════════════════════ */

	function spawnConfetti( parent ) {
		var colors = [ '#A3EA41', '#38d4c0', '#ffd700', '#ff6b6b', '#4ecdc4', '#fff' ];
		for ( var i = 0; i < 22; i++ ) {
			( function ( idx ) {
				setTimeout( function () {
					var el = document.createElement( 'div' );
					var sz = 6 + Math.random() * 8;
					el.className  = 'eiwr-confetti';
					el.style.cssText = [
						'left:'               + ( Math.random() * 100 ) + '%',
						'width:'              + sz + 'px',
						'height:'             + sz + 'px',
						'background:'         + colors[ Math.floor( Math.random() * colors.length ) ],
						'border-radius:'      + ( Math.random() > 0.5 ? '50%' : '2px' ),
						'animation-duration:' + ( 0.7 + Math.random() * 0.8 ) + 's',
						'animation-delay:0s'
					].join( ';' );
					parent.appendChild( el );
					setTimeout( function () {
						if ( el.parentNode ) el.parentNode.removeChild( el );
					}, 1600 );
				}, idx * 45 );
			} )( i );
		}
	}

	function revealPreviewResult( wrap, loseText ) {
		if ( wrap._eiwrOpened ) return;
		wrap._eiwrOpened = true;
		setTimeout( function () {
			var revealCont  = wrap.querySelector( '.gift-reveal-container' );
			var instruction = wrap.querySelector( '.eiwr-instruction' );
			var message     = wrap.querySelector( '.instant-win-message' );
			if ( revealCont )  revealCont.classList.add( 'hidden' );
			if ( instruction ) instruction.classList.add( 'hidden' );
			if ( message ) {
				var hasWin = message.querySelectorAll( 'tbody tr' ).length > 0;
				if ( hasWin ) {
					message.classList.remove( 'hidden' );
					spawnConfetti( wrap );
				} else {
					var p = document.createElement( 'p' );
					p.className   = 'eiwr-lose-msg';
					p.textContent = loseText;
					wrap.appendChild( p );
				}
			}
		}, 650 );
	}

	function resetPreviewGame( container ) {
		if ( ! container ) return;
		var wrap = container.querySelector( '.enhanced-instant-win-reveal' );
		if ( ! wrap ) return;

		/* Clear animation classes */
		var giftbox = wrap.querySelector( '.eiwr-giftbox' );
		if ( giftbox ) {
			giftbox.classList.remove( 'eiwr-fade-out' );
			var lid = giftbox.querySelector( '.eiwr-gift-lid' );
			if ( lid ) lid.classList.remove( 'eiwr-open' );
		}
		var envelope = wrap.querySelector( '.eiwr-envelope' );
		if ( envelope ) envelope.classList.remove( 'eiwr-opening' );

		var inner = wrap.querySelector( '.eiwr-flipcard-inner' );
		if ( inner ) inner.classList.remove( 'eiwr-flipped' );

		/* Restore reveal area and instruction */
		var revealCont  = wrap.querySelector( '.gift-reveal-container' );
		var instruction = wrap.querySelector( '.eiwr-instruction' );
		var message     = wrap.querySelector( '.instant-win-message' );
		if ( revealCont )  revealCont.classList.remove( 'hidden' );
		if ( instruction ) instruction.classList.remove( 'hidden' );
		if ( message )     message.classList.add( 'hidden' );

		/* Remove dynamically added lose message and confetti */
		wrap.querySelectorAll( '.eiwr-lose-msg, .eiwr-confetti' ).forEach( function ( el ) { el.remove(); } );

		wrap._eiwrOpened = false;
	}

	function showRevealStyle( container, style ) {
		if ( ! container ) return;
		var wrap = container.querySelector( '.enhanced-instant-win-reveal' );
		if ( ! wrap ) return;
		wrap.querySelectorAll( '[data-reveal]' ).forEach( function ( el ) {
			el.style.display = ( el.getAttribute( 'data-reveal' ) === style ) ? '' : 'none';
		} );
	}

	function switchPreviewStyle( container, style ) {
		resetPreviewGame( container );
		showRevealStyle( container, style );
	}

	function initPreviewGame( container ) {
		if ( ! container ) return;
		var wrap = container.querySelector( '.enhanced-instant-win-reveal' );
		if ( ! wrap || wrap._eiwrInit ) return;
		wrap._eiwrInit = true;

		var saved = window.irpvSaved || {};

		function getLoseText() {
			var el = document.getElementById( 'ir_lose_text' )
			      || document.querySelector( '[name="_ir_lose_text"]' );
			return ( el && el.value ) ? el.value : ( saved.loseText || 'Sorry, better luck next time' );
		}

		/* Gift box */
		var giftbox = wrap.querySelector( '.eiwr-giftbox' );
		if ( giftbox ) {
			giftbox.addEventListener( 'click', function () {
				if ( wrap._eiwrOpened ) return;
				var lid = giftbox.querySelector( '.eiwr-gift-lid' );
				if ( lid ) lid.classList.add( 'eiwr-open' );
				setTimeout( function () { giftbox.classList.add( 'eiwr-fade-out' ); }, 350 );
				revealPreviewResult( wrap, getLoseText() );
			} );
		}

		/* Envelope */
		var envelope = wrap.querySelector( '.eiwr-envelope' );
		if ( envelope ) {
			envelope.addEventListener( 'click', function () {
				if ( wrap._eiwrOpened ) return;
				envelope.classList.add( 'eiwr-opening' );
				revealPreviewResult( wrap, getLoseText() );
			} );
		}

		/* Flip card */
		var flipcard = wrap.querySelector( '.eiwr-flipcard' );
		if ( flipcard ) {
			var fcInner = flipcard.querySelector( '.eiwr-flipcard-inner' );
			flipcard.addEventListener( 'click', function () {
				if ( wrap._eiwrOpened ) return;
				if ( fcInner ) fcInner.classList.add( 'eiwr-flipped' );
				revealPreviewResult( wrap, getLoseText() );
			} );
		}
	}

	/* Expose for inline scripts (product panel) */
	window.irpvInitGame    = initPreviewGame;
	window.irpvResetGame   = resetPreviewGame;
	window.irpvShowStyle   = showRevealStyle;
	window.irpvSwitchStyle = switchPreviewStyle;

	/* ════════════════════════════════════════════════════════════
	   GLOBAL SETTINGS PAGE PREVIEW
	   ════════════════════════════════════════════════════════════ */
	var globalContainer = document.getElementById( 'ir-prev-container' );
	initPreviewGame( globalContainer );

	var currentGlobalStyle = gvRadio( 'ir_style', 'giftbox' );

	function updateGlobal() {
		if ( ! globalContainer ) return;

		var primary     = gv( 'ir_primary',         '#A3EA41' );
		var dark        = gv( 'ir_dark',            '#6aa52b' );
		var tableHeader = gv( 'ir_table_header_bg', '#2aa8f8' );
		var tableAccent = gv( 'ir_table_accent',    '#0d5fa9' );
		pushVars( globalContainer, primary, dark, tableHeader, tableAccent );

		/* Hex badge sync */
		document.querySelectorAll( '.ck-color-input' ).forEach( function ( inp ) {
			var field = inp.closest( '.ck-color-field' );
			if ( ! field ) return;
			var hex = field.querySelector( '.ck-color-hex' );
			if ( hex ) hex.textContent = inp.value;
		} );

		/* Text elements */
		var el;
		el = globalContainer.querySelector( '#irpv-ticket-title' );
		if ( el ) el.textContent = gv( 'ir_ticket_title', 'Your Tickets' );

		globalContainer.querySelectorAll( '.eiwr-instruction' ).forEach( function ( e ) {
			e.textContent = gv( 'ir_instruction_text', 'Click to reveal your prize' );
		} );

		el = globalContainer.querySelector( '#irpv-win-heading strong' );
		if ( el ) el.textContent = gv( 'ir_win_heading', 'Congratulations, you have won:' );

		/* Style card active state */
		document.querySelectorAll( '.ir-style-card' ).forEach( function ( card ) {
			var radio = card.querySelector( 'input[type="radio"]' );
			card.classList.toggle( 'ir-style-card--active', !! ( radio && radio.checked ) );
		} );

		/* Always show the correct reveal item; only reset game state when style changes */
		var style = gvRadio( 'ir_style', 'giftbox' );
		showRevealStyle( globalContainer, style );
		if ( style !== currentGlobalStyle ) {
			currentGlobalStyle = style;
			resetPreviewGame( globalContainer );
			showRevealStyle( globalContainer, style );
		}
	}

	/* Bind all form fields */
	$( document ).on( 'input change', '[name^="ir_"]', function () {
		requestAnimationFrame( updateGlobal );
	} );
	$( document ).on( 'click', '.ir-style-card', function () {
		setTimeout( updateGlobal, 10 );
	} );

	/* Colour picker → sync hidden input + update preview immediately */
	$( document ).on( 'input change', '.ck-color-input', function () {
		var f = $( this ).closest( '.ck-color-field' );
		f.find( '.ck-color-value' ).val( this.value );
		f.find( '.ck-color-hex' ).text( this.value );
		requestAnimationFrame( updateGlobal );
	} );

	/* Desktop preview toggle */
	$( document ).on( 'mousedown touchstart', '#ir-preview-desktop-btn', function ( e ) {
		e.preventDefault();
		var $btn   = $( this );
		var $panel = $( '#ir-preview-panel' );
		if ( $panel.hasClass( 'ck-preview-desktop-mode' ) ) {
			$panel.removeClass( 'ck-preview-desktop-mode' );
			$btn.removeClass( 'ck-preview-desktop-btn--active' ).text( 'View Desktop' );
		} else {
			$panel.addClass( 'ck-preview-desktop-mode' );
			$btn.addClass( 'ck-preview-desktop-btn--active' ).text( 'Close ×' );
		}
	} );

	/* ════════════════════════════════════════════════════════════
	   TAB SWITCHER  (.fbp-tab-btn / .fbp-tab-section)
	   ════════════════════════════════════════════════════════════ */
	$( document ).on( 'click', '.fbp-tab-btn', function () {
		var tab      = $( this ).data( 'tab' );
		var nav      = $( this ).closest( '.fbp-tab-nav' );
		var tabgroup = nav.data( 'tabgroup' ) || 'default';

		nav.find( '.fbp-tab-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );

		$( '.fbp-tab-section[data-tabgroup="' + tabgroup + '"]' ).removeClass( 'active' );
		$( '.fbp-tab-section[data-tabgroup="' + tabgroup + '"][data-tab="' + tab + '"]' ).addClass( 'active' );
	} );

	/* Initial render */
	updateGlobal();

} );
