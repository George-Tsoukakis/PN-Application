/**
 * QR ReBuilder Pro — live μετρητές χρήσης.
 *
 * Αυτόνομο: δεν διαβάζει window.QRRP, δεν στέλνει nonce ή cookies. Διαβάζει
 * ΜΟΝΟ το δημόσιο usage.json (data-qrrp-usage-src) και ενημερώνει τους
 * αριθμούς. Φορτώνεται όπου υπάρχουν μετρητές — και στην κλειδωμένη θέση,
 * όπου το qrrp-app.js δεν φορτώνεται.
 *
 * - Ανανέωση με το άνοιγμα της σελίδας (οι αριθμοί της page cache μπορεί να
 *   είναι ώρες παλιοί), κάθε 60″ όσο η καρτέλα είναι ορατή, και αμέσως όταν
 *   η καρτέλα ξαναγίνει ορατή.
 * - Σε διαδοχικές αποτυχίες το διάστημα διπλασιάζεται, έως 15′.
 * - Το ?t= αλλάζει ανά λεπτό, ώστε CDN/browser να μη σερβίρουν παλιό αρχείο
 *   χωρίς να γίνεται νέο αρχείο σε κάθε αίτημα.
 * - Κάθε σφάλμα αγνοείται σιωπηλά: μένει ο αριθμός που τύπωσε ο server.
 */
( function () {
	'use strict';

	var INTERVAL_MS     = 60000;
	var MAX_INTERVAL_MS = 15 * 60000;

	function usableCount( value ) {
		return 'number' === typeof value && isFinite( value ) && value >= 0 && Math.floor( value ) === value;
	}

	function formatCount( value ) {
		var lang = document.documentElement.getAttribute( 'lang' ) || undefined;

		try {
			return value.toLocaleString( lang );
		} catch ( ignored ) {
			return String( value );
		}
	}

	function apply( usage, data ) {
		var total = usage.querySelector( '.qrrp-usage-value-total' );
		var month = usage.querySelector( '.qrrp-usage-value-month' );
		var block = usage.querySelector( '.qrrp-usage-item-month' );

		if ( total && usableCount( data.total ) ) {
			total.textContent = formatCount( data.total );
		}

		if ( month && usableCount( data.month ) ) {
			month.textContent = formatCount( data.month );

			if ( block ) {
				block.hidden = 0 === data.month;
			}
		}
	}

	/* Ένα αίτημα ανά src· ενημερώνει όλα τα μπλοκ του. Επιλύεται σε true/false. */
	function refresh( src, blocks ) {
		var bucket = Math.floor( Date.now() / 60000 );
		var url    = src + ( -1 === src.indexOf( '?' ) ? '?' : '&' ) + 't=' + bucket;

		return fetch( url, { credentials: 'omit' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data || 'object' !== typeof data ) {
					return false;
				}

				blocks.forEach( function ( usage ) {
					apply( usage, data );
				} );

				return true;
			} )
			[ 'catch' ]( function () {
				return false;
			} );
	}

	function start() {
		if ( 'function' !== typeof window.fetch ) {
			return;
		}

		var nodes   = document.querySelectorAll( '.qrrp-usage[data-qrrp-usage-src]' );
		var sources = {};
		var keys    = [];

		for ( var i = 0; i < nodes.length; i++ ) {
			var src = nodes[ i ].getAttribute( 'data-qrrp-usage-src' ) || '';

			/* Απόλυτο http(s) ή protocol-relative URL. */
			if ( ! /^(https?:)?\/\/[^/]/.test( src ) ) {
				continue;
			}

			if ( ! sources[ src ] ) {
				sources[ src ] = [];
				keys.push( src );
			}

			sources[ src ].push( nodes[ i ] );
		}

		if ( ! keys.length ) {
			return;
		}

		var failures = 0;
		var timer    = null;
		var inFlight = false;
		var lastRun  = 0;

		function schedule() {
			var delay = Math.min( INTERVAL_MS * Math.pow( 2, failures ), MAX_INTERVAL_MS );

			window.clearTimeout( timer );
			timer = window.setTimeout( tick, delay );
		}

		function run() {
			if ( inFlight ) {
				return;
			}

			inFlight = true;
			lastRun  = Date.now();

			Promise.all( keys.map( function ( src ) {
				return refresh( src, sources[ src ] );
			} ) ).then( function ( results ) {
				inFlight = false;
				failures = -1 === results.indexOf( false ) ? 0 : Math.min( failures + 1, 8 );
				schedule();
			} );
		}

		function tick() {
			if ( 'hidden' === document.visibilityState ) {
				schedule();
				return;
			}

			run();
		}

		document.addEventListener( 'visibilitychange', function () {
			/* Όχι σε κάθε γρήγορη εναλλαγή καρτέλας: το πολύ μία φορά ανά 10″. */
			if ( 'hidden' !== document.visibilityState && Date.now() - lastRun > 10000 ) {
				window.clearTimeout( timer );
				run();
			}
		} );

		run();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
