/**
 * QR ReBuilder Pro — front-end του εργαλείου [qr_rebuilder_pro].
 *
 * Σάρωση (keyboard-wedge scanner) και χειροκίνητη εισαγωγή, server-side GS1
 * parsing, εμφάνιση του GS1 DataMatrix που παράγει ο server, λήψη εικόνας,
 * εκτύπωση ετικέτας, αντιγραφή και αποστολή μέσω email.
 *
 * Συνεργάζεται με:
 * - includes/class-qrrp-shortcode.php: HTML του εργαλείου και window.QRRP.
 * - includes/class-qrrp-ajax.php: endpoints parse, rebuild (PNG) και email.
 * - includes/class-qrrp-gs1-parser.php: το authoritative GS1 parsing.
 * - assets/css/qrrp-style.css: εμφάνιση των qrrp-* στοιχείων.
 */
(function () {
	'use strict';

	if ( typeof window.QRRP === 'undefined' ) {
		return;
	}

	var QRRP = window.QRRP;
	var I18N = ( QRRP && QRRP.i18n && typeof QRRP.i18n === 'object' ) ? QRRP.i18n : {};

	/* Localized string with an inline Greek fallback if a key is missing/stale. */
	function t( key, fallback ) {
		return ( typeof I18N[ key ] === 'string' && '' !== I18N[ key ] ) ? I18N[ key ] : fallback;
	}
	var canSendEmail = QRRP.canSendEmail === true || QRRP.canSendEmail === 1 || QRRP.canSendEmail === '1';
	var lastRegeneratedPngBase64 = null;
	var lastValidatedRaw = null;
	var lastValidatedOutput = '';
	var lastValidatedFields = null;
	/* 2.15.2: σήμανση χειροκίνητης αλλαγής του τελευταίου output ('' = από σάρωση). */
	var lastProvenanceNote = '';
	/*
	 * Δύο ανεξάρτητες πηγές warnings, που αποδίδονται μαζί στο ίδιο πλαίσιο:
	 * sourceWarnings από τον parser / την ασάφεια της τρέχουσας πηγής,
	 * printWarnings από τον server για το τελευταίο επιτυχημένο rebuild.
	 */
	var sourceWarnings = [];
	var printWarnings  = [];
	var rebuildGeneration = 0;
	var parseGeneration = 0;
	/*
	 * Το όριο έρχεται από τον server (qrrp_max_raw_bytes()). Το wp_localize_script()
	 * το στέλνει ως string, γι' αυτό το parseInt. Το fallback υπάρχει ώστε μια
	 * χαμένη ή αλλοιωμένη τιμή να μη γίνει 0 και να κόβει κάθε σάρωση.
	 */
	var MAX_RAW_LENGTH = ( function () {
		var n = parseInt( QRRP.maxRawLength, 10 );
		return ( isFinite( n ) && n > 0 ) ? n : 4096;
	}() );
	var MIN_IDLE_SCAN_LENGTH = 8;

	/*
	 * Παύση (ms) μετά τον τελευταίο χαρακτήρα του σαρωτή πριν από την αυτόματη
	 * υποβολή, για σαρωτές χωρίς Enter/Tab suffix. Προαιρετικό QRRP.scannerIdleMs.
	 */
	var scannerIdleMs = ( function () {
		var n = parseInt( QRRP.scannerIdleMs, 10 );

		if ( ! isFinite( n ) ) {
			n = 300;
		}

		return Math.min( 2000, Math.max( 80, n ) );
	}() );

	/*
	 * Πόσο μετά από πάτημα του σαρωτή ένα 'input' event θεωρείται ηχώ του ίδιου
	 * πατήματος από τον browser και όχι επικόλληση.
	 */
	var SCANNER_ECHO_WINDOW_MS = 150;

	/*
	 * Χαρακτήρες που φτάνουν τόσο σύντομα μετά τον τελευταίο μιας σάρωσης που
	 * υποβλήθηκε λόγω παύσης είναι η συνέχειά της, όχι νέα σάρωση.
	 */
	var SCANNER_TAIL_MERGE_MS = 800;
	/* 2.15.3: Tab μέσα σε τόσα ms από τον τελευταίο χαρακτήρα θεωρείται suffix του σαρωτή. */
	var SCANNER_SUFFIX_GUARD_MS = 200;

	var PHYSICAL_KEY_MAP = {
		KeyA: [ 'a', 'A' ], KeyB: [ 'b', 'B' ], KeyC: [ 'c', 'C' ], KeyD: [ 'd', 'D' ],
		KeyE: [ 'e', 'E' ], KeyF: [ 'f', 'F' ], KeyG: [ 'g', 'G' ], KeyH: [ 'h', 'H' ],
		KeyI: [ 'i', 'I' ], KeyJ: [ 'j', 'J' ], KeyK: [ 'k', 'K' ], KeyL: [ 'l', 'L' ],
		KeyM: [ 'm', 'M' ], KeyN: [ 'n', 'N' ], KeyO: [ 'o', 'O' ], KeyP: [ 'p', 'P' ],
		KeyQ: [ 'q', 'Q' ], KeyR: [ 'r', 'R' ], KeyS: [ 's', 'S' ], KeyT: [ 't', 'T' ],
		KeyU: [ 'u', 'U' ], KeyV: [ 'v', 'V' ], KeyW: [ 'w', 'W' ], KeyX: [ 'x', 'X' ],
		KeyY: [ 'y', 'Y' ], KeyZ: [ 'z', 'Z' ],

		Digit0: [ '0', ')' ], Digit1: [ '1', '!' ], Digit2: [ '2', '@' ],
		Digit3: [ '3', '#' ], Digit4: [ '4', '$' ], Digit5: [ '5', '%' ],
		Digit6: [ '6', '^' ], Digit7: [ '7', '&' ], Digit8: [ '8', '*' ],
		Digit9: [ '9', '(' ],

		Numpad0: [ '0', '0' ], Numpad1: [ '1', '1' ], Numpad2: [ '2', '2' ],
		Numpad3: [ '3', '3' ], Numpad4: [ '4', '4' ], Numpad5: [ '5', '5' ],
		Numpad6: [ '6', '6' ], Numpad7: [ '7', '7' ], Numpad8: [ '8', '8' ],
		Numpad9: [ '9', '9' ],

		Minus: [ '-', '_' ], NumpadSubtract: [ '-', '-' ],
		Period: [ '.', '>' ], NumpadDecimal: [ '.', '.' ],
		Slash: [ '/', '?' ], NumpadDivide: [ '/', '/' ], Space: [ ' ', ' ' ],
		Comma: [ ',', '<' ], Semicolon: [ ';', ':' ],
		Quote: [ '\'', '"' ], Equal: [ '=', '+' ], NumpadAdd: [ '+', '+' ],
		NumpadMultiply: [ '*', '*' ],
		BracketLeft: [ '[', '{' ], BracketRight: [ ']', '}' ],
		Backslash: [ '\\', '|' ], Backquote: [ '`', '~' ]
	};

	var SCANNER_ASCII_PATTERN = /^[\x20-\x7E]$/;

	/*
	 * Ελληνική διάταξη πληκτρολογίου.
	 *
	 * Ο σαρωτής στέλνει πατήματα πλήκτρων που το λειτουργικό μεταφράζει με την
	 * ενεργή διάταξη: με ελληνικό πληκτρολόγιο το YN68XRRFDZP φτάνει ως ΥΝ68ΧΡΡΦΔΖΠ.
	 * Συνήθως το event.code δίνει το φυσικό πλήκτρο. Όπου λείπει (κάποια
	 * Bluetooth/εικονικά πληκτρολόγια) και στην επικόλληση, 2.15.7: οι ελληνικοί
	 * χαρακτήρες στέλνονται ΑΥΤΟΥΣΙΟΙ στον server, που κάνει την πλήρη ανάκτηση
	 * (νεκρά πλήκτρα «ά» → «;a», «ΐ» → «Wi», «Σ» = S ή W με επιλογή) και ζητά
	 * επιβεβαίωση. Πριν, η μετατροπή γινόταν εδώ με απώλειες (Σ → S σιωπηλά,
	 * ά → a) και ο server έβλεπε λατινικά, χωρίς λόγο να ζητήσει επιβεβαίωση.
	 */
	var GREEK_LAYOUT_PATTERN = /^[\u0370-\u03FF\u1F00-\u1FFF]$/;

	var GROUP_SEPARATOR = '\x1D';

	function rawToDisplay( raw ) {
		return String( raw || '' ).replace( /\x1D/g, '[GS]' );
	}

	/* Για το clipboard κρατάμε τους πραγματικούς διαχωριστές ASCII 29. */
	function rawToClipboardRaw( raw ) {
		return String( raw || '' );
	}

	function rawFromDisplay( raw ) {
		return String( raw || '' ).replace( /\[GS\]/gi, GROUP_SEPARATOR );
	}

	/*
	 * Καθαρισμός επικολλημένου GS1: η αναδίπλωση στην οθόνη/εκτύπωση βάζει κενά
	 * και αλλαγές γραμμής, που τα GS1 δεδομένα δεν περιέχουν ποτέ. Αφαιρείται και
	 * μια αρχική ετικέτα «GS1:» από την εκτύπωση.
	 */
	function normalizeManualInput( value ) {
		return String( value || '' )
			.replace( /^\s*GS1\s*[:：]?\s*/i, '' )
			.replace( /\s+/g, '' );
	}

	/*
	 * Κεφαλαίο ή πεζό για γράμμα που εντοπίστηκε από το event.code: ΜΟΝΟ το Shift.
	 * Ο σαρωτής γράφει «A» ως Shift+a· με Caps Lock αναμμένο το λειτουργικό το
	 * αντιστρέφει σε «a», οπότε ένα ξεχασμένο Caps Lock θα άλλαζε σιωπηλά SN/LOT.
	 * Γι' αυτό το Caps Lock (και το event.key που το ενσωματώνει) αγνοείται.
	 */
	function letterIsUpper( event ) {
		return !! event.shiftKey;
	}

	/*
	 * inBurst: ο σαρωτής στέλνει ήδη χαρακτήρες. Μόνο τότε γίνεται δεκτό ένα
	 * event.repeat (διπλό γράμμα από σαρωτή)· αλλιώς είναι κρατημένο πλήκτρο.
	 */
	function scannerChar( event, inBurst ) {
		if ( ! event || ( event.repeat && ! inBurst ) ) {
			return null;
		}

		if ( event.key === GROUP_SEPARATOR ) {
			return GROUP_SEPARATOR;
		}

		/* Το keyCode 29 είναι και το NonConvert των ιαπωνικών πληκτρολογίων. */
		if (
			( event.which === 29 || event.keyCode === 29 ) &&
			( ! event.key || event.key === 'Unidentified' )
		) {
			return GROUP_SEPARATOR;
		}

		if (
			event.ctrlKey &&
			! event.altKey &&
			! event.metaKey &&
			( 'BracketRight' === event.code || ']' === event.key )
		) {
			return GROUP_SEPARATOR;
		}

		if ( event.ctrlKey || event.altKey || event.metaKey ) {
			return null;
		}

		var character = null;

		if (
			event.code &&
			Object.prototype.hasOwnProperty.call( PHYSICAL_KEY_MAP, event.code )
		) {
			var pair = PHYSICAL_KEY_MAP[ event.code ];

			if ( /^Key[A-Z]$/.test( event.code ) ) {
				character = letterIsUpper( event ) ? pair[1] : pair[0];
			} else {
				character = event.shiftKey ? pair[1] : pair[0];
			}
		} else if ( event.isComposing ) {
			/* Χωρίς event.code, μια εκδήλωση σε σύνθεση (νεκρό πλήκτρο) δεν είναι αξιόπιστη. */
			return null;
		} else if ( typeof event.key === 'string' && event.key.length === 1 ) {
			/* Εδώ ο χαρακτήρας έχει ήδη περάσει από τη διάταξη· τα ελληνικά τα επαναφέρει ο server. */
			character = event.key;

			/*
			 * Το ';' / ':' ΔΕΝ μετατρέπεται σε q/Q: είναι έγκυροι χαρακτήρες GS1 και
			 * χωρίς event.code δεν ξεχωρίζουν από το πλήκτρο Q της ελληνικής διάταξης.
			 */
		}

		return character && ( SCANNER_ASCII_PATTERN.test( character ) || GREEK_LAYOUT_PATTERN.test( character ) )
			? character
			: null;
	}

	function toDisplayDate( isoDate ) {
		/* 2.15.3: λήξη χωρίς ημέρα (GS1 ΗΗ=00) → μήνας/έτος και εξήγηση. */
		if ( /^\d{4}-\d{2}-00$/.test( isoDate || '' ) ) {
			return isoDate.slice( 5, 7 ) + '/' + isoDate.slice( 0, 4 ) + ' (' +
				t( 'expiryDayZeroShort', 'χωρίς ημέρα – έως το τέλος του μήνα' ) + ')';
		}

		var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( isoDate || '' );

		return match
			? match[3] + '-' + match[2] + '-' + match[1]
			: ( isoDate || '' );
	}

	/* Ημερομηνία που δέχεται ένα <input type="date"> (όχι ΗΗ=00, όχι μήνας 13). */
	function isFullIsoDate( value ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value || '' ) );

		if ( ! match ) {
			return false;
		}

		var month = parseInt( match[2], 10 );
		var day   = parseInt( match[3], 10 );

		return month >= 1 && month <= 12 && day >= 1 &&
			day <= new Date( Date.UTC( parseInt( match[1], 10 ), month, 0 ) ).getUTCDate();
	}

	/*
	 * 2.15.3: 'YYYY-MM-00' → τελευταία μέρα του μήνα ('' για κάθε άλλη τιμή). Το
	 * <input type="date"> δεν δέχεται ΗΗ=00, οπότε δείχνει αυτή την ημέρα, ενώ
	 * στον server φεύγει το 'YYYY-MM-00' όσο ο χρήστης δεν την αλλάζει.
	 */
	function endOfMonthIso( value ) {
		var match = /^(\d{4})-(\d{2})-00$/.exec( String( value || '' ) );

		if ( ! match ) {
			return '';
		}

		var month = parseInt( match[2], 10 );

		if ( month < 1 || month > 12 ) {
			return '';
		}

		var day = new Date( Date.UTC( parseInt( match[1], 10 ), month, 0 ) ).getUTCDate();

		return match[1] + '-' + match[2] + '-' + ( day < 10 ? '0' : '' ) + day;
	}

	function toGs1Expiry( isoDate ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( isoDate || '' ) );

		return match
			? match[1].slice( -2 ) + match[2] + match[3]
			: '';
	}

	/*
	 * Το passthrough_raw (extras AI που επιστρέφει ο server) είναι προαιρετικό:
	 * η απουσία του είναι νόμιμη. Οτιδήποτε άλλο από string είναι σφάλμα και όχι
	 * κάτι που μετατρέπεται σιωπηλά. Το όριο μήκους είναι μόνο άμυνα απέναντι σε
	 * παθολογικές τιμές· το όριο μεγέθους συμβόλου το επιβάλλει ο server.
	 */
	function normalizePassthroughRaw( value ) {
		if ( value === null || value === undefined || '' === value ) {
			return '';
		}

		if ( 'string' !== typeof value ) {
			throw new Error( 'invalid_server_passthrough' );
		}

		if ( value.length > MAX_RAW_LENGTH ) {
			throw new Error( 'invalid_server_passthrough' );
		}

		return value;
	}

	/*
	 * Το κανονικό GS1 RAW όπως το χτίζει ο server, για έλεγχο συνέπειας της
	 * απάντησής του: ο server δεν άλλαξε σιωπηλά PC/SN/LOT/EXP και το RAW δεν
	 * περιέχει τίποτα άλλο. Τα extras (passthrough) έρχονται από την ίδια απάντηση,
	 * άρα εδώ ελέγχεται μόνο η θέση τους, όχι η προέλευσή τους. Η σύγκριση είναι
	 * ακριβής ισότητα.
	 */
	function canonicalRaw( fields, passthroughRaw ) {
		var expiry = toGs1Expiry( fields.EXP );

		if ( ! expiry ) {
			throw new Error( 'invalid_expiry_for_raw' );
		}

		/*
		 * Τα extras μπαίνουν πριν από το τελικό AI 21: το τελευταίο πεδίο μεταβλητού
		 * μήκους δεν χρειάζεται GS. Ο τερματικός GS ενός extra μεταβλητού μήκους
		 * ανήκει ήδη στο fragment του server.
		 */
		return '01' + fields.PC +
			'17' + expiry +
			'10' + fields.LOT + GROUP_SEPARATOR +
			normalizePassthroughRaw( passthroughRaw ) +
			'21' + fields.SN;
	}

	function rawMatchesCanonical( raw, fields, passthroughRaw ) {
		return canonicalRaw( fields, passthroughRaw ) === raw;
	}

	/*
	 * Η ημερομηνία του server είναι runtime state: η σελίδα μπορεί να μείνει
	 * ανοιχτή μετά τα μεσάνυχτα ή να σερβιριστεί από cache, ενώ ο server κρίνει
	 * τη λήξη με το δικό του «σήμερα». Κάθε AJAX απάντηση φέρνει server_today
	 * και συγχρονίζεται εδώ για όλη τη συνεδρία.
	 */
	var serverToday        = '';
	var onServerTodayChange = null;

	function todayISO() {
		return serverToday || String( QRRP.todayISO || '' );
	}

	function syncServerToday( json ) {
		var value = ( json && json.data && json.data.server_today ) || '';

		value = String( value );

		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) || value === serverToday ) {
			return;
		}

		var previous = todayISO();

		serverToday = value;

		/* Η νέα ημερομηνία πρέπει να φανεί αμέσως στο UI (checkbox λήξης, κουμπί). */
		if ( 'function' === typeof onServerTodayChange ) {
			onServerTodayChange( previous );
		}
	}

	/*
	 * Το fetch() δεν έχει δικό του timeout· χωρίς αυτό ένας server που δεν απαντά
	 * αφήνει το κουμπί κλειδωμένο για πάντα. 30″ και όχι λιγότερο, γιατί η αποστολή
	 * email καλεί σύγχρονα το wp_mail() και ένας αργός SMTP κρατά αρκετά δευτερόλεπτα·
	 * ένα σφιχτό όριο θα έκοβε επιτυχημένες αποστολές και θα έφερνε διπλά email.
	 */
	var AJAX_TIMEOUT_MS = 30000;

	/*
	 * 2.15.3: ληγμένο nonce (π.χ. σελίδα από cache/CDN) → ένα φρέσκο nonce από
	 * τον server και μία επανάληψη. Ασφαλές και για το email: ο server απορρίπτει
	 * το nonce πριν κάνει οτιδήποτε. Αν αποτύχει και η ανανέωση, μένει το μήνυμα
	 * για σκληρή ανανέωση.
	 */
	function isStaleNonceResult( json ) {
		return !! ( json && false === json.success && json.data && 'invalid_nonce' === json.data.code );
	}

	function refreshNonce() {
		return ajaxRequestOnce( 'qrrp_refresh_nonce', {} ).then( function ( json ) {
			var fresh = json && json.success && json.data && 'string' === typeof json.data.nonce ? json.data.nonce : '';

			if ( ! /^[a-f0-9]{6,32}$/i.test( fresh ) ) {
				throw new Error( 'ajax_nonce_rejected' );
			}

			QRRP.nonce = fresh;
		} );
	}

	function ajaxRequest( action, data ) {
		return ajaxRequestOnce( action, data ).then(
			function ( json ) {
				if ( ! isStaleNonceResult( json ) ) {
					return json;
				}

				return refreshNonce().then( function () {
					return ajaxRequestOnce( action, data );
				}, function () {
					return json;
				} );
			},
			function ( error ) {
				if ( ! error || 'ajax_nonce_rejected' !== error.message ) {
					throw error;
				}

				return refreshNonce().then( function () {
					return ajaxRequestOnce( action, data );
				}, function () {
					throw error;
				} );
			}
		);
	}

	function ajaxRequestOnce( action, data ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', QRRP.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] == null ? '' : String( data[ key ] ) );
		} );

		var options = {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		};

		/*
		 * Χωρίς AbortController το αίτημα φεύγει χωρίς timeout. Το timeout είναι
		 * ευκολία, όχι προστασία, και δεν πρέπει να σπάει το εργαλείο σε παλιό browser.
		 */
		var timer = null;

		if ( typeof AbortController === 'function' ) {
			var controller = new AbortController();

			options.signal = controller.signal;
			timer          = setTimeout( function () {
				controller.abort();
			}, AJAX_TIMEOUT_MS );
		}

		var clear = function () {
			if ( null !== timer ) {
				clearTimeout( timer );
				timer = null;
			}
		};

		var status = 0;

		return fetch( QRRP.ajaxUrl, options ).then( function ( response ) {
			status = response.status || 0;

			return response.text();
		} ).then( function ( text ) {
			clear();

			var json;
			var bare = String( text ).trim();

			/*
			 * Το admin-ajax.php απαντά σκέτο «-1» όταν απορρίπτει το nonce και
			 * σκέτο «0» όταν η ενέργεια δεν επιτρέπεται στον τρέχοντα χρήστη
			 * (π.χ. έληξε η σύνδεση). Δεν είναι JSON του εργαλείου.
			 */
			if ( '-1' === bare ) {
				throw new Error( 'ajax_nonce_rejected' );
			}

			if ( '0' === bare ) {
				throw new Error( 'ajax_not_allowed' );
			}

			try {
				json = JSON.parse( text );
			} catch ( error ) {
				if ( status && ( status < 200 || status > 299 ) ) {
					throw new Error( 'http_' + status );
				}

				throw new Error( 'invalid_json_response' );
			}

			/* Επιτυχία ή σφάλμα — η ώρα του server συγχρονίζεται και στα δύο. */
			syncServerToday( json );

			return json;
		} ).catch( function ( error ) {
			clear();

			/* Το abort φτάνει ως DOMException 'AbortError'· μεταφράζεται σε δικό μας κωδικό. */
			if ( error && 'AbortError' === error.name ) {
				throw new Error( 'request_timeout' );
			}

			throw error;
		} );
	}

	/*
	 * Σέβεται το prefers-reduced-motion και στις κυλίσεις του JS: η κύλιση γίνεται,
	 * απλώς χωρίς κίνηση. Ελέγχεται κάθε φορά, γιατί η ρύθμιση μπορεί να αλλάξει
	 * όσο η σελίδα είναι ανοιχτή.
	 */
	function scrollBehavior() {
		if (
			typeof window.matchMedia === 'function' &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		) {
			return 'auto';
		}

		return 'smooth';
	}

	/*
	 * Μήνυμα για αποτυχία αιτήματος. Το timeout διαφέρει από την απώλεια δικτύου: ο
	 * server πιθανότατα αργεί, και για email το αίτημα μπορεί να έχει ήδη εκτελεστεί.
	 */
	function networkErrorMessage( error ) {
		var code = error && error.message ? String( error.message ) : '';

		if ( 'request_timeout' === code ) {
			return t(
				'requestTimeout',
				'Ο server δεν απάντησε εγκαίρως. Δοκιμάστε ξανά — αν είχατε ζητήσει αποστολή email, ελέγξτε πρώτα αν έφτασε, γιατί μπορεί να στάλθηκε παρότι διακόπηκε η αναμονή.'
			);
		}

		if ( 'ajax_nonce_rejected' === code ) {
			return staleNonceMessage();
		}

		if ( 'ajax_not_allowed' === code || 'http_401' === code || 'http_403' === code ) {
			return t(
				'accessDenied',
				'Ο server αρνήθηκε το αίτημα — πιθανότατα έληξε η σύνδεσή σας ή άλλαξαν τα δικαιώματά σας. Ανανεώστε τη σελίδα και συνδεθείτε ξανά.'
			);
		}

		if ( /^http_\d+$/.test( code ) ) {
			return t(
				'serverHttpError',
				'Σφάλμα του server (HTTP {status}). Δοκιμάστε ξανά σε λίγο.'
			).replace( '{status}', code.slice( 5 ) );
		}

		return t( 'serverCommFailed', 'Αποτυχία επικοινωνίας με τον server.' );
	}

	/*
	 * Ληγμένο nonce: μια απλή ανανέωση μπορεί να φέρει το ίδιο cached HTML, οπότε
	 * ζητάμε σκληρή ανανέωση. Κρίνεται από τον κωδικό, όχι από το (μεταφρασμένο) κείμενο.
	 */
	function staleNonceMessage() {
		return t(
			'staleNonce',
			'Η σελίδα είναι παλιά και το διακριτικό ασφαλείας της έληξε. Κάντε σκληρή ανανέωση (Ctrl+F5 ή Cmd+Shift+R) και δοκιμάστε ξανά — μια απλή ανανέωση μπορεί να επιστρέψει το ίδιο αποθηκευμένο αντίγραφο.'
		);
	}

	function nonceErrorMessage( json ) {
		var code = json && json.data && json.data.code ? String( json.data.code ) : '';

		return 'invalid_nonce' === code ? staleNonceMessage() : '';
	}

	/*
	 * Escaping για περιεχόμενο κειμένου, όχι για attributes: τα εισαγωγικά δεν
	 * γίνονται escape. Για attributes υπάρχει η escapeAttr().
	 */
	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value == null ? '' : String( value );
		return div.innerHTML;
	}

	/* Escaping για τιμή attribute: escapeHtml() και επιπλέον τα " και '. */
	function escapeAttr( value ) {
		return escapeHtml( value )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/*
	 * Επιτρέπει μόνο data: URI PNG σε base64, όπως το παράγει η currentPngDataUri().
	 * Ο αυστηρός έλεγχος προστατεύει το src ακόμη κι αν αλλάξει η πηγή της τιμής.
	 */
	function safePngDataUri( value ) {
		var src = value == null ? '' : String( value );

		return /^data:image\/png;base64,[A-Za-z0-9+/]*={0,2}$/.test( src ) ? src : '';
	}

	/* Η live ανανέωση των μετρητών χρήσης γίνεται στο assets/js/qrrp-usage.js. */

	/* Element.remove() και Promise#finally λείπουν από παλιούς browsers. */
	function removeNode( node ) {
		if ( node && node.parentNode ) {
			node.parentNode.removeChild( node );
		}
	}

	function promiseFinally( promise, callback ) {
		return promise.then( function ( value ) {
			callback();

			return value;
		}, function ( error ) {
			callback();

			throw error;
		} );
	}

	function boot() {
		var root = document.getElementById( 'qrrp-app' );

		if ( ! root ) {
			return;
		}

		var els = {
			loadingIndicator: document.getElementById( 'qrrp-loading-indicator' ),
			loadingText: document.getElementById( 'qrrp-loading-text' ),
			manualToggle: document.getElementById( 'qrrp-manual-toggle' ),
			manualEntry: document.getElementById( 'qrrp-manual-entry' ),
			manualInput: document.getElementById( 'qrrp-manual-input' ),
			manualSubmit: document.getElementById( 'qrrp-manual-submit' ),
			hwInput: document.getElementById( 'qrrp-hw-input' ),
			resultsPanel: document.getElementById( 'qrrp-results-panel' ),
			outputPanel: document.getElementById( 'qrrp-output-panel' ),
			warnings: document.getElementById( 'qrrp-warnings' ),
			ambiguousConfirm: document.getElementById( 'qrrp-ambiguous-confirm' ),
			ambiguousCheckbox: document.getElementById( 'qrrp-ambiguous-checkbox' ),
			expiryConfirm: document.getElementById( 'qrrp-expiry-confirm' ),
			expiryCheckbox: document.getElementById( 'qrrp-expiry-checkbox' ),
			rawOriginal: document.getElementById( 'qrrp-raw-original' ),
			rawOriginalGroup: document.getElementById( 'qrrp-raw-original-group' ),
			manualEntryNote: document.getElementById( 'qrrp-manual-entry-note' ),
			fieldPc: document.getElementById( 'qrrp-field-pc' ),
			fieldSn: document.getElementById( 'qrrp-field-sn' ),
			fieldLot: document.getElementById( 'qrrp-field-lot' ),
			fieldExp: document.getElementById( 'qrrp-field-exp' ),
			customerName: document.getElementById( 'qrrp-customer-name' ),
			printDate: document.getElementById( 'qrrp-print-date' ),
			regenerate: document.getElementById( 'qrrp-regenerate' ),
			rescan: document.getElementById( 'qrrp-rescan' ),
			qrOutput: document.getElementById( 'qrrp-qr-output' ),
			summaryPc: document.getElementById( 'qrrp-summary-pc' ),
			summarySn: document.getElementById( 'qrrp-summary-sn' ),
			summaryLot: document.getElementById( 'qrrp-summary-lot' ),
			summaryExp: document.getElementById( 'qrrp-summary-exp' ),
			summaryCustomer: document.getElementById( 'qrrp-summary-customer' ),
			summaryPrintdate: document.getElementById( 'qrrp-summary-printdate' ),
			summaryProvenance: document.getElementById( 'qrrp-summary-provenance' ),
			downloadQr: document.getElementById( 'qrrp-download-qr' ),
			printQr: document.getElementById( 'qrrp-print-qr' ),
			copyRaw: document.getElementById( 'qrrp-copy-raw' ),
			emailInput: document.getElementById( 'qrrp-email-input' ),
			sendEmail: document.getElementById( 'qrrp-send-email' ),
			status: document.getElementById( 'qrrp-status' ),
			statusLive: document.getElementById( 'qrrp-status-live' ),
			alertLive: document.getElementById( 'qrrp-alert-live' )
		};

		if ( els.printDate ) {
			els.printDate.value = todayISO();
		}

		var hwIdleTimer = null;
		var hwBuffer = '';

		var lastScannerKeyAt = 0;

		/*
		 * Η τελευταία σάρωση που υποβλήθηκε λόγω παύσης (χωρίς Enter/Tab), για
		 * την περίπτωση που ο σαρωτής συνεχίσει μετά την παύση.
		 */
		var lastIdleSubmittedRaw = '';
		var hwBufferIsMergedTail = false;

		/* Prefill token from an email link, spent on the first successful rebuild. */
		var activeRebuildToken = '';

		/* Το HTML που βρίσκεται αυτή τη στιγμή στο #qrrp-warnings. */
		var renderedWarningsHtml = '';

		/* Ψηφία Alt+numpad (Windows) που μαζεύονται όσο κρατιέται το Alt. */
		var altNumpadDigits = '';
		var lastParseNeedsConfirmation = false;

		/*
		 * Το πρωτότυπο της τρέχουσας πηγής, byte-exact (με τα ASCII 29), για να κρίνει ο
		 * server την ίδια ανάγνωση. Το textarea κρατά μόνο τη μορφή εμφάνισης με [GS].
		 * Υπάρχει για σάρωση και για χειροκίνητη επικόλληση· καθαρίζει στο prefill από
		 * σύνδεσμο email, που φέρνει ήδη αποφασισμένα πεδία χωρίς πρωτότυπο.
		 */
		var lastSourceRaw = '';

		/*
		 * Η tuple από την οποία ξεκίνησε ο χρήστης, όταν τη δήλωσε ρητά στον picker
		 * αμφισβητούμενων τιμών. Στέλνεται ως baseline_* και ο server τη δέχεται μόνο αν
		 * ανήκει στις αποδεκτές αναγνώσεις — είναι υπόδειξη που επαληθεύεται. Ο server
		 * δεν διαλέγει μόνος του baseline σε αμφίβολη ανάγνωση.
		 */
		var declaredBaseline = {};

		/*
		 * Το challenge του τελευταίου 409 provenance: opaque handle δεμένο server-side
		 * στην ακριβή tuple και στη σάρωση. Καθαρίζει σε κάθε αλλαγή πεδίου.
		 */
		var pendingChallenge = '';
		var pendingChallengeCode = '';

		/*
		 * Το challenge στέλνεται μόνο όταν ο χρήστης πατήσει ρητά «Τα διάβασα από τη
		 * συσκευασία», και μόνο για μία υποβολή. Αν στελνόταν αυτόματα, ένα απλό
		 * ξαναπάτημα του «Δημιουργία» θα παρέκαμπτε τη δήλωση.
		 */
		var challengeArmed = false;

		/*
		 * Αμφισβητούμενες τιμές ανά πεδίο, όπως τις στέλνει ο server στο contested_fields
		 * ({ SN: ['ABC', 'ABC123'], … }). Κενό όταν η ανάγνωση είναι μονοσήμαντη.
		 */
		var contestedValues = {};
		var contestedResolved = {};
		/* 2.15.3: η λήξη 'YYYY-MM-00' της σάρωσης όσο το πεδίο δείχνει την τελευταία μέρα της. */
		var expDayZero = '';

		/* Δημιουργία από PC/SN/LOT/EXP χωρίς σάρωση· ακυρώνεται με κάθε νέα ανάλυση. */
		var manualEntryMode = false;
		var CAN_MANUAL_ENTRY = !! QRRP.canManualEntry && '0' !== String( QRRP.canManualEntry );

		function setStatus( message, isError ) {
			if ( ! els.status ) {
				return;
			}

			els.status.textContent = message || '';
			els.status.classList.toggle( 'qrrp-status-error', !! isError );

			announce( message, isError );

			// Το μήνυμα είναι στην κορυφή του εργαλείου· ένα σφάλμα πρέπει να φαίνεται
			// ακόμη κι αν ο χρήστης έχει κυλήσει πιο κάτω.
			if ( message && isError && els.status.scrollIntoView ) {
				els.status.scrollIntoView( { behavior: scrollBehavior(), block: 'nearest' } );
			}
		}

		/*
		 * 2.15.3: ανακοίνωση στις κρυφές live regions. Άδειασμα και γράψιμο στο
		 * επόμενο tick, ώστε να ανακοινώνεται και ίδιο μήνυμα που επαναλαμβάνεται.
		 */
		var announceTimer = null;

		function announce( message, isError ) {
			var target = isError ? els.alertLive : els.statusLive;
			var other  = isError ? els.statusLive : els.alertLive;

			if ( other ) {
				other.textContent = '';
			}

			if ( ! target ) {
				return;
			}

			clearTimeout( announceTimer );
			target.textContent = '';

			if ( ! message ) {
				return;
			}

			announceTimer = setTimeout( function () {
				target.textContent = message;
			}, 50 );
		}

		function showLoader( message ) {
			if ( ! els.loadingIndicator ) {
				return;
			}

			if ( els.loadingText ) {
				els.loadingText.textContent = message || '';
			}

			els.loadingIndicator.hidden = false;
		}

		function hideLoader() {
			if ( els.loadingIndicator ) {
				els.loadingIndicator.hidden = true;
			}
		}

		/*
		 * Η λήξη κρίνεται από την τρέχουσα τιμή του πεδίου, όχι από την ανάλυση: ο
		 * χρήστης μπορεί να την αλλάξει ή να έρθει από σύνδεσμο email. Ο server
		 * εφαρμόζει τον ίδιο κανόνα ανεξάρτητα.
		 */
		function dateIsInPast( isoDate ) {
			var value = endOfMonthIso( isoDate ) || String( isoDate || '' ).trim();
			var today = todayISO();

			if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) || ! /^\d{4}-\d{2}-\d{2}$/.test( today ) ) {
				return false;
			}

			return value < today;
		}

		/*
		 * Η τελική κρίση είναι του server. Αν για οποιονδήποτε λόγο το dateIsInPast()
		 * διαφωνεί, κρατάμε την τιμή EXP που ο server χαρακτήρισε ληγμένη, ώστε να μη
		 * ζητηθεί ποτέ επιβεβαίωση χωρίς ορατό checkbox. Δεμένο στη συγκεκριμένη τιμή:
		 * παύει να ισχύει μόλις αλλάξει η ημερομηνία.
		 */
		var serverExpiredFor = '';

		function expiryIsInPast() {
			var value = els.fieldExp ? String( els.fieldExp.value || '' ).trim() : '';

			return dateIsInPast( value ) || ( '' !== value && value === serverExpiredFor );
		}

		/*
		 * 409 expiry_not_confirmed: εμφανίζεται το checkbox λήξης. Ποτέ αυτόματο retry
		 * με expiry_confirmed=1 — η επιβεβαίωση είναι απόφαση του ανθρώπου.
		 */
		function applyServerExpiryVerdict( json ) {
			var data = ( json && json.data ) || {};

			if ( 'expiry_not_confirmed' !== data.code ) {
				return false;
			}

			serverExpiredFor = els.fieldExp
				? String( els.fieldExp.value || '' ).trim()
				: '';

			updateRegenerateAvailability();

			if ( els.expiryCheckbox && ! els.expiryCheckbox.disabled ) {
				els.expiryCheckbox.focus();
			}

			return true;
		}

		/*
		 * 409 ambiguity_not_confirmed: για διαδρομές που δεν πέρασαν από parse σε αυτή
		 * την καρτέλα (π.χ. σύνδεσμος email). Η απάντηση φέρνει το contested_fields,
		 * οπότε ο picker εμφανίζεται χωρίς δεύτερο αίτημα.
		 */
		function applyServerAmbiguityVerdict( json ) {
			var data = ( json && json.data ) || {};

			if ( 'ambiguity_not_confirmed' !== data.code ) {
				return false;
			}

			lastParseNeedsConfirmation = true;
			contestedValues = normalizeContested( data.contested_fields );
			contestedResolved = {};

			if ( els.ambiguousConfirm ) {
				els.ambiguousConfirm.hidden = false;
			}

			if ( els.ambiguousCheckbox ) {
				els.ambiguousCheckbox.checked = false;
			}

			renderContestedPicker();

			if ( Array.isArray( data.warnings ) && data.warnings.length ) {
				sourceWarnings = data.warnings.slice();
				renderAllWarnings();
			}

			updateRegenerateAvailability();

			if ( els.ambiguousConfirm && els.ambiguousConfirm.scrollIntoView ) {
				els.ambiguousConfirm.scrollIntoView( { behavior: scrollBehavior(), block: 'center' } );
			}

			return true;
		}

		function expiryAcknowledged() {
			return ! expiryIsInPast() ||
				( !! els.expiryCheckbox && els.expiryCheckbox.checked );
		}

		function updateExpiryNotice() {
			var expired = expiryIsInPast();

			if ( els.expiryConfirm ) {
				els.expiryConfirm.hidden = ! expired;
			}

			if ( ! expired && els.expiryCheckbox ) {
				els.expiryCheckbox.checked = false;
			}
		}

		/*
		 * Καταγράφει ως baseline ολόκληρη την tuple της φόρμας, μόνο όταν κάθε
		 * αμφισβητούμενο πεδίο έχει επιλεγεί ρητά. Ο server επαληθεύει ολόκληρη
		 * tuple (τα πεδία που λείπουν θα γίνονταν κενά), οπότε μερικό snapshot δεν θα
		 * ταίριαζε ποτέ. Ένας αδύνατος συνδυασμός απλώς δεν γίνεται δεκτός ως baseline.
		 */
		function captureDeclaredBaseline() {
			var labels = contestedLabels();

			declaredBaseline = {};

			if ( ! labels.length ) {
				return;
			}

			var pending = labels.some( function ( label ) {
				return ! contestedResolved[ label ];
			} );

			if ( pending ) {
				return;
			}

			var snapshot = collectFields();

			if ( ! snapshot ) {
				return;
			}

			[ 'PC', 'SN', 'LOT', 'EXP' ].forEach( function ( label ) {
				if ( snapshot[ label ] ) {
					declaredBaseline[ label ] = snapshot[ label ];
				}
			} );
		}

		function contestedLabels() {
			return Object.keys( contestedValues );
		}

		/*
		 * Η ασάφεια θεωρείται αποδεκτή όταν ο χρήστης έχει διαλέξει τιμή σε κάθε
		 * αμφισβητούμενο πεδίο ή έχει τσεκάρει το checkbox. Η ενεργή επιλογή ανάμεσα
		 * σε εναλλακτικές είναι ισχυρότερη από ένα checkbox· το checkbox όμως μένει,
		 * γιατί ο χρήστης μπορεί να γράψει τιμή που δεν είναι καμία από τις εναλλακτικές.
		 */
		function ambiguityAcknowledged() {
			if ( ! lastParseNeedsConfirmation ) {
				return true;
			}

			var labels = contestedLabels();

			if ( labels.length ) {
				var allChosen = labels.every( function ( label ) {
					return true === contestedResolved[ label ];
				} );

				if ( allChosen ) {
					return true;
				}
			}

			return !! ( els.ambiguousCheckbox && els.ambiguousCheckbox.checked );
		}

		/*
		 * Ο picker χτίζεται με createElement/textContent, ποτέ με innerHTML: οι τιμές
		 * έρχονται από τη συσκευασία και το GS1 επιτρέπει «<», «>» και εισαγωγικά.
		 */
		function contestedPickerHost() {
			if ( ! els.ambiguousConfirm ) {
				return null;
			}

			var host = document.getElementById( 'qrrp-contested' );

			if ( ! host ) {
				host = document.createElement( 'div' );
				host.id = 'qrrp-contested';
				host.className = 'qrrp-contested';
				els.ambiguousConfirm.insertBefore( host, els.ambiguousConfirm.firstChild );
			}

			return host;
		}

		/** Το κουτάκι επιβεβαίωσης, για να μπορεί να κρύβεται όταν υπάρχει picker. */
		function ambiguousCheckboxRow() {
			if ( ! els.ambiguousCheckbox || ! els.ambiguousCheckbox.closest ) {
				return null;
			}

			return els.ambiguousCheckbox.closest( 'label' );
		}

		/** Το πεδίο εισαγωγής που αντιστοιχεί σε μια ετικέτα GS1. */
		function fieldElementFor( label ) {
			if ( 'SN' === label ) { return els.fieldSn; }
			if ( 'LOT' === label ) { return els.fieldLot; }
			if ( 'PC' === label ) { return els.fieldPc; }
			if ( 'EXP' === label ) { return els.fieldExp; }

			return null;
		}

		/*
		 * Κρατάμε μόνο πραγματικές επιλογές: δύο ή περισσότερες διακριτές τιμές, σε
		 * πεδίο που υπάρχει στη φόρμα.
		 */
		function normalizeContested( raw ) {
			var out = {};

			if ( ! raw || 'object' !== typeof raw ) {
				return out;
			}

			[ 'PC', 'SN', 'LOT', 'EXP' ].forEach( function ( label ) {
				var values = raw[ label ];

				if ( ! Array.isArray( values ) || ! fieldElementFor( label ) ) {
					return;
				}

				var unique = [];

				values.forEach( function ( value ) {
					if ( 'string' === typeof value && -1 === unique.indexOf( value ) ) {
						unique.push( value );
					}
				} );

				if ( unique.length > 1 ) {
					out[ label ] = unique;
				}
			} );

			return out;
		}

		function clearContestedPicker() {
			contestedValues = {};
			contestedResolved = {};

			var host = document.getElementById( 'qrrp-contested' );

			if ( host ) {
				host.textContent = '';
				host.hidden = true;
			}

			var row = ambiguousCheckboxRow();

			if ( row ) {
				row.hidden = false;
			}
		}

		/*
		 * Καμία επιλογή δεν είναι προεπιλεγμένη: η αξία του picker είναι ακριβώς ότι
		 * κάποιος πρέπει να διαλέξει κοιτώντας τη συσκευασία.
		 */
		function renderContestedPicker() {
			var host = contestedPickerHost();

			if ( ! host ) {
				return;
			}

			host.textContent = '';

			var labels = contestedLabels();

			if ( ! labels.length ) {
				host.hidden = true;

				var plainRow = ambiguousCheckboxRow();

				if ( plainRow ) {
					plainRow.hidden = false;
				}

				return;
			}

			host.hidden = false;

			labels.forEach( function ( label ) {
				var group = document.createElement( 'fieldset' );
				group.className = 'qrrp-contested-group';

				var legend = document.createElement( 'legend' );
				legend.textContent = t(
					'contestedPrompt',
					'Ποια τιμή δείχνει η συσκευασία;'
				) + ' (' + label + ')';
				group.appendChild( legend );

				contestedValues[ label ].forEach( function ( value, index ) {
					var id = 'qrrp-contested-' + label.toLowerCase() + '-' + index;

					var option = document.createElement( 'label' );
					option.className = 'qrrp-contested-option';
					option.setAttribute( 'for', id );

					var radio = document.createElement( 'input' );
					radio.type = 'radio';
					radio.name = 'qrrp-contested-' + label;
					radio.id = id;
					radio.value = value;

					var text = document.createElement( 'span' );
					text.className = 'qrrp-contested-value';
					text.textContent = value;

					radio.addEventListener( 'change', function () {
						chooseContestedValue( label, value );
					} );

					option.appendChild( radio );
					option.appendChild( text );
					group.appendChild( option );
				} );

				host.appendChild( group );
			} );

			/*
			 * Με picker στην οθόνη το checkbox κρύβεται, εκτός αν κάποιο πεδίο έχει ήδη
			 * τιμή εκτός λίστας (π.χ. όταν ο picker έρχεται από 409 του server).
			 */
			refreshContestedFallback();
		}

		/*
		 * Η ανάθεση ελέγχεται: ένα <input type="date"> απορρίπτει σιωπηλά μορφή που
		 * δεν δέχεται. Το πεδίο σημειώνεται λυμένο μόνο αν κράτησε ακριβώς την τιμή.
		 */
		function chooseContestedValue( label, value ) {
			var field = fieldElementFor( label );

			/* Ξεκινάμε από μη λυμένο, ώστε μια αποτυχημένη νέα επιλογή να ακυρώνει την παλιά. */
			contestedResolved[ label ] = false;

			if ( ! field ) {
				updateRegenerateAvailability();

				return;
			}

			/* 2.15.3: ΗΗ=00 στο πεδίο ως τελευταία μέρα· στον server φεύγει το '-00'. */
			var shown = value;

			if ( 'EXP' === label ) {
				expDayZero = endOfMonthIso( value ) ? value : '';
				shown      = endOfMonthIso( value ) || value;
			}

			if ( field.value !== shown ) {
				field.value = shown;
				invalidateGeneratedQr( false );
			}

			/* Αυστηρή ταυτότητα: το πεδίο ημερομηνίας μπορεί να κρατήσει την προηγούμενη τιμή. */
			if ( field.value !== shown ) {
				setStatus(
					t(
						'contestedValueRejected',
						'Η επιλεγμένη τιμή δεν έγινε δεκτή από το πεδίο. Συμπληρώστε την χειροκίνητα από τη συσκευασία.'
					),
					true
				);

				return;
			}

			contestedResolved[ label ] = true;
			clearPendingChallenge();

			captureDeclaredBaseline();

			updateRegenerateAvailability();
		}

		/*
		 * Κάθε χειροκίνητη αλλαγή αμφισβητούμενου πεδίου ακυρώνει πρώτα την προηγούμενη
		 * απόφαση (radio και checkbox) και μετά αξιολογείται η νέα τιμή. Η πληκτρολόγηση
		 * τιμής που υπάρχει στη λίστα δεν μετράει ως επιλογή: αποδοχή είναι μόνο το
		 * ρητό πάτημα, όχι autofill ή προγραμματιστική αλλαγή.
		 */
		function onContestedFieldEdited( label ) {
			if ( ! contestedValues[ label ] ) {
				return;
			}

			contestedResolved[ label ] = false;

			var chosen = document.querySelector(
				'input[name="qrrp-contested-' + label + '"]:checked'
			);

			if ( chosen ) {
				chosen.checked = false;
			}

			/* Το checkbox αφορά τις τιμές που είδε ο χρήστης· ξετσεκάρεται σε κάθε αλλαγή. */
			if ( els.ambiguousCheckbox ) {
				els.ambiguousCheckbox.checked = false;
			}

			refreshContestedFallback();
		}

		/*
		 * Δείχνει το checkbox όταν κάποιο αμφισβητούμενο πεδίο έχει τιμή εκτός λίστας,
		 * ώστε ο χρήστης που γράφει τη σωστή τιμή από τη συσκευασία να μπορεί να προχωρήσει.
		 */
		function refreshContestedFallback() {
			var row = ambiguousCheckboxRow();

			if ( ! row ) {
				return;
			}

			var offList = contestedLabels().some( function ( label ) {
				var field = fieldElementFor( label );
				var value = field ? String( field.value ) : '';

				return -1 === contestedValues[ label ].indexOf( value );
			} );

			row.hidden = ! offList;
		}

		function updateRegenerateAvailability() {
			updateExpiryNotice();

			if ( ! els.regenerate ) {
				return;
			}

			/*
			 * Όσο εκκρεμεί challenge, η μόνη διαδρομή είναι το κουμπί επιβεβαίωσης· το
			 * «Δημιουργία» θα έφερνε απλώς ξανά την ίδια άρνηση.
			 */
			var blocked = ! ambiguityAcknowledged() || ! expiryAcknowledged() || !! pendingChallenge;

			els.regenerate.disabled = !! blocked;
		}

		onServerTodayChange = function ( previousToday ) {
			/* Η ημερομηνία εκτύπωσης ακολουθεί το «σήμερα» όσο δεν την άλλαξε ο χρήστης. */
			if ( els.printDate && previousToday && els.printDate.value === previousToday ) {
				els.printDate.value = todayISO();
				updateNonGs1Summary();
			}

			updateRegenerateAvailability();
		};

		if ( els.ambiguousCheckbox ) {
			els.ambiguousCheckbox.addEventListener( 'change', updateRegenerateAvailability );
		}

		if ( els.expiryCheckbox ) {
			els.expiryCheckbox.addEventListener( 'change', updateRegenerateAvailability );
		}

		/*
		 * Άλλο ορατό, ενεργό στοιχείο ελέγχου έχει την εστίαση — τότε δεν την
		 * παίρνουμε πίσω αυτόματα.
		 */
		function focusIsOnOtherControl() {
			var active = document.activeElement;

			if (
				! active ||
				active === els.hwInput ||
				active === document.body ||
				active === document.documentElement ||
				active.disabled
			) {
				return false;
			}

			return ! ( active.closest && active.closest( '[hidden]' ) );
		}

		function focusHwInput( force ) {
			if ( ! els.hwInput || document.activeElement === els.hwInput ) {
				return;
			}

			if ( force || ! focusIsOnOtherControl() ) {
				els.hwInput.focus();
			}
		}

		function setHwBuffer( value ) {
			hwBuffer = value == null ? '' : String( value );
			if ( hwBuffer.length > MAX_RAW_LENGTH ) {
				hwBuffer = hwBuffer.slice( 0, MAX_RAW_LENGTH );
			}

			if ( els.hwInput ) {
				els.hwInput.value = rawToDisplay( hwBuffer );
			}
		}

		function scheduleHwSubmit() {
			clearTimeout( hwIdleTimer );
			hwIdleTimer = setTimeout( function () {
				if ( hwBuffer.trim().length >= MIN_IDLE_SCAN_LENGTH ) {
					submitHwScan( true );
				}
			}, scannerIdleMs );
		}

		/* viaIdle: υποβολή λόγω παύσης, χωρίς τερματικό Enter/Tab του σαρωτή. */
		function submitHwScan( viaIdle ) {
			clearTimeout( hwIdleTimer );
			hwIdleTimer = null;

			var raw = hwBuffer.trim();
			var mergedTail = hwBufferIsMergedTail;

			setHwBuffer( '' );
			hwBufferIsMergedTail = false;
			lastIdleSubmittedRaw = viaIdle ? raw : '';

			if ( raw ) {
				handleScannedRaw( raw, { mergedTail: mergedTail } );
			}
		}

		function clearHwScanState() {
			clearTimeout( hwIdleTimer );
			hwIdleTimer = null;
			setHwBuffer( '' );
			hwBufferIsMergedTail = false;
			lastIdleSubmittedRaw = '';
		}

		/*
		 * Κοινή είσοδος χαρακτήρων σαρωτή (keydown και Alt+numpad).
		 *
		 * Ένας σαρωτής χωρίς suffix που κάνει παύση μέσα στη σάρωση ενεργοποιεί
		 * την υποβολή λόγω αδράνειας με κομμένα δεδομένα. Αν η συνέχεια φτάσει
		 * λίγο μετά, ενώνεται με το ήδη υποβληθέν τμήμα και αναλύεται ξανά
		 * ολόκληρη, αντί να σταλεί η ουρά ως νέα (αποτυχημένη) σάρωση.
		 */
		function acceptScannerChars( chars ) {
			var now = Date.now();

			if (
				'' === hwBuffer &&
				'' !== lastIdleSubmittedRaw &&
				now - lastScannerKeyAt < SCANNER_TAIL_MERGE_MS
			) {
				setHwBuffer( lastIdleSubmittedRaw );
				hwBufferIsMergedTail = true;
			}

			lastIdleSubmittedRaw = '';

			if ( hwBuffer.length < MAX_RAW_LENGTH ) {
				setHwBuffer( hwBuffer + chars );
			}

			lastScannerKeyAt = now;
			scheduleHwSubmit();
		}

		var INTERACTIVE_SELECTOR = 'a[href], button, input, select, textarea, label, summary, [contenteditable="true"], [tabindex]';

		if ( els.hwInput ) {
			focusHwInput();

			els.hwInput.addEventListener( 'keydown', function ( event ) {
				/*
				 * Το Tab ολοκληρώνει σάρωση μόνο με περιεχόμενο στο buffer (σαρωτής με Tab
				 * suffix). Με άδειο buffer μετακινεί κανονικά την εστίαση (WCAG 2.1.2).
				 */
				if ( event.key === 'Tab' && hwBuffer.trim() === '' ) {
					/*
					 * 2.15.3: Tab ms μετά τη σάρωση είναι suffix του σαρωτή (Enter+Tab),
					 * όχι άνθρωπος· αλλιώς η εστίαση φεύγει και οι επόμενες σαρώσεις χάνονται.
					 */
					if ( Date.now() - lastScannerKeyAt < SCANNER_SUFFIX_GUARD_MS ) {
						event.preventDefault();
					}

					return;
				}

				if ( event.key === 'Enter' || event.key === 'Tab' ) {
					event.preventDefault();
					submitHwScan( false );
					return;
				}

				if ( event.key === 'Escape' ) {
					event.preventDefault();
					clearHwScanState();
					return;
				}

				if ( event.key === 'Backspace' ) {
					event.preventDefault();
					setHwBuffer( hwBuffer.slice( 0, -1 ) );
					scheduleHwSubmit();
					return;
				}

				/*
				 * Alt+numpad: πολλοί σαρωτές στέλνουν χαρακτήρες ελέγχου με τη
				 * μέθοδο των Windows (Alt+0029 = Group Separator). Ο χαρακτήρας
				 * δεν φτάνει στον browser, μόνο τα πατήματα του numpad, οπότε
				 * μαζεύουμε τα ψηφία και τα μετατρέπουμε όταν αφεθεί το Alt.
				 */
				if ( event.altKey && ! event.ctrlKey && ! event.metaKey &&
					/^Numpad[0-9]$/.test( event.code || '' ) ) {
					event.preventDefault();
					altNumpadDigits += ( event.code || '' ).slice( -1 );
					return;
				}

				var character = scannerChar(
					event,
					Date.now() - lastScannerKeyAt < SCANNER_ECHO_WINDOW_MS
				);

				if ( character === null ) {
					return;
				}

				event.preventDefault();
				acceptScannerChars( character );
			} );

			/*
			 * Το Alt αφέθηκε: τα ψηφία είναι ένας κωδικός χαρακτήρα. Ακούμε στο window,
			 * γιατί το keyup μπορεί να φτάσει αφού μετακινηθεί η εστίαση. Γίνονται δεκτοί
			 * οι ίδιοι χαρακτήρες με το keydown (εκτυπώσιμο ASCII) και το GS (29)·
			 * CR/LF/Tab τερματίζουν τη σάρωση.
			 */
			window.addEventListener( 'keyup', function ( event ) {
				if ( event.key !== 'Alt' ) {
					return;
				}

				var digits = altNumpadDigits;
				altNumpadDigits = '';

				if ( ! digits || document.activeElement !== els.hwInput ) {
					return;
				}

				var codePoint = parseInt( digits, 10 );

				if ( 13 === codePoint || 10 === codePoint || 9 === codePoint ) {
					if ( '' !== hwBuffer.trim() ) {
						submitHwScan( false );
					}

					return;
				}

				if ( ! isFinite( codePoint ) || codePoint < 1 || codePoint > 126 ) {
					return;
				}

				var character = String.fromCharCode( codePoint );

				if ( 29 !== codePoint && ! SCANNER_ASCII_PATTERN.test( character ) ) {
					return;
				}

				acceptScannerChars( character );
			} );

			els.hwInput.addEventListener( 'input', function () {
				/*
				 * Το 'input' έρχεται σε επικόλληση (οι χαρακτήρες του σαρωτή περνούν από το
				 * keydown με preventDefault()). Αν όμως μόλις ήρθε χαρακτήρας σαρωτή, είναι ηχώ
				 * του browser (IME, κάποια Bluetooth πληκτρολόγια) και θα διπλασίαζε τον
				 * χαρακτήρα· τότε το buffer του keydown υπερισχύει.
				 */
				if ( Date.now() - lastScannerKeyAt < SCANNER_ECHO_WINDOW_MS ) {
					setHwBuffer( hwBuffer );
					return;
				}

				var captured = rawFromDisplay( normalizeManualInput( els.hwInput.value ) );

				if ( captured === hwBuffer ) {
					return;
				}

				setHwBuffer( captured );
				scheduleHwSubmit();
			} );

			/*
			 * Κλικ σε «νεκρή» περιοχή του εργαλείου πριν από την ανάλυση ξαναδίνει
			 * την εστίαση στο πεδίο του σαρωτή. Κλικ σε στοιχεία ελέγχου ή έξω
			 * από το εργαλείο δεν την αγγίζουν.
			 */
			root.addEventListener( 'click', function ( event ) {
				if (
					! els.resultsPanel ||
					! els.outputPanel ||
					! els.resultsPanel.hidden ||
					! els.outputPanel.hidden
				) {
					return;
				}

				var target = event.target;

				if ( target && target.closest && target.closest( INTERACTIVE_SELECTOR ) ) {
					return;
				}

				setTimeout( focusHwInput, 0 );
			} );
		}

		if ( els.manualToggle && els.manualEntry ) {
			els.manualToggle.addEventListener( 'click', function () {
				/*
				 * Χωρίς φορτωμένη σάρωση ανοίγουν τα πεδία PC/SN/LOT/EXP για δημιουργία από
				 * το μηδέν. Ελέγχεται πριν από το toggle, αλλιώς μετά από «Νέα σάρωση» το κλικ
				 * θα έκλεινε το πεδίο επικόλλησης αντί να ανοίξει τα πεδία.
				 */
				if ( CAN_MANUAL_ENTRY && els.resultsPanel && els.resultsPanel.hidden ) {
					startManualEntry();
					return;
				}

				var open = els.manualEntry.hidden;

				els.manualEntry.hidden = ! open;
				els.manualToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

				if ( open && els.manualInput ) {
					els.manualInput.focus();
				}
			} );
		}

		if ( els.manualSubmit && els.manualInput ) {
			els.manualSubmit.addEventListener( 'click', function () {
				var raw = normalizeManualInput( els.manualInput.value );

				if ( raw ) {
					handleScannedRaw( raw );
				}
			} );
		}

		/*
		 * Χειροκίνητη δημιουργία: ανοίγουν κενά πεδία. Χωρίς σάρωση δεν υπάρχει
		 * source_raw, οπότε ο server ζητά ρητή δήλωση (challenge) πριν δημιουργήσει.
		 */
		function startManualEntry() {
			expDayZero = '';
			resetToolCompletely();

			manualEntryMode = true;

			if ( els.manualEntry ) { els.manualEntry.hidden = false; }
			if ( els.manualToggle ) { els.manualToggle.setAttribute( 'aria-expanded', 'true' ); }
			if ( els.manualEntryNote ) { els.manualEntryNote.hidden = false; }
			if ( els.rawOriginalGroup ) { els.rawOriginalGroup.hidden = true; }

			lastParseNeedsConfirmation = false;
			updateRegenerateAvailability();

			els.resultsPanel.hidden = false;

			if ( els.fieldPc ) {
				els.fieldPc.focus();
			}
		}

		function stopManualEntry() {
			manualEntryMode = false;

			if ( els.manualEntryNote ) { els.manualEntryNote.hidden = true; }
			if ( els.rawOriginalGroup ) { els.rawOriginalGroup.hidden = false; }
		}

		/*
		 * «Νέα σάρωση»: αδειάζει ρητά κάθε τιμή, όχι μόνο κρύβει τα panels — το όνομα
		 * πελάτη, για παράδειγμα, τυπώνεται στην ετικέτα. Η ημερομηνία εκτύπωσης
		 * επανέρχεται στη σημερινή.
		 */
		function resetToolCompletely() {
			expDayZero = '';
			parseGeneration++;
			rebuildGeneration++;
			clearHwScanState();

			/* Μια ανάλυση σε εξέλιξη ακυρώνεται· το κουμπί της δεν μένει κλειδωμένο. */
			if ( els.manualSubmit ) { els.manualSubmit.disabled = false; }

			if ( els.manualInput ) { els.manualInput.value = ''; }
			if ( els.rawOriginal ) { els.rawOriginal.value = ''; }

			[ els.fieldPc, els.fieldSn, els.fieldLot, els.fieldExp ].forEach(
				function ( field ) {
					if ( field ) {
						field.value = '';
					}
				}
			);

			// Πεδία εκτός GS1 — καταλήγουν στην ετικέτα και στο email.
			if ( els.customerName ) { els.customerName.value = ''; }
			if ( els.emailInput ) { els.emailInput.value = ''; }
			if ( els.printDate ) { els.printDate.value = todayISO(); }

			if ( els.ambiguousConfirm ) { els.ambiguousConfirm.hidden = true; }
			if ( els.ambiguousCheckbox ) { els.ambiguousCheckbox.checked = false; }
			if ( els.expiryConfirm ) { els.expiryConfirm.hidden = true; }
			if ( els.expiryCheckbox ) { els.expiryCheckbox.checked = false; }

			sourceWarnings = [];
			printWarnings  = [];
			renderAllWarnings();
			if ( els.qrOutput ) { els.qrOutput.innerHTML = ''; }

			lastParseNeedsConfirmation = false;

			lastSourceRaw = '';
			declaredBaseline = {};
			clearPendingChallenge();
			clearContestedPicker();

			clearValidatedOutput();

			/* Το token του συνδέσμου email δεν αφορά τη νέα σάρωση (λήγει μόνο του). */
			activeRebuildToken = '';

			stopManualEntry();

			updateNonGs1Summary();
			setOutputActionsEnabled( false );
			hideLoader();
			updateRegenerateAvailability();

			if ( els.resultsPanel ) { els.resultsPanel.hidden = true; }
			if ( els.outputPanel ) { els.outputPanel.hidden = true; }

			setStatus( '' );
		}

		if ( els.rescan ) {
			els.rescan.addEventListener( 'click', function () {
				resetToolCompletely();
				focusHwInput( true );
			} );
		}

		if ( els.regenerate ) {
			els.regenerate.addEventListener( 'click', onRegenerate );
		}

		if ( els.downloadQr ) {
			els.downloadQr.addEventListener( 'click', onDownload );
		}

		if ( els.printQr ) {
			els.printQr.addEventListener( 'click', onPrint );
		}

		if ( els.copyRaw ) {
			els.copyRaw.addEventListener( 'click', onCopyRaw );
		}

		if ( els.sendEmail && canSendEmail ) {
			els.sendEmail.addEventListener( 'click', onSendEmail );
		} else if ( els.sendEmail ) {
			els.sendEmail.disabled = true;
		}

		/* Η κατάσταση του τελευταίου επικυρωμένου output μηδενίζεται πάντα μαζί. */
		function clearValidatedOutput() {
			lastRegeneratedPngBase64 = null;
			lastValidatedRaw = null;
			lastValidatedOutput = '';
			lastValidatedFields = null;
			setProvenanceNote( '' );
		}

		/*
		 * 2.15.2: κείμενο σήμανσης από τα metadata provenance του server. Μόνο για
		 * εμφάνιση: ο server δεν διαβάζει ποτέ provenance από τον browser.
		 */
		function provenanceNoteFrom( data ) {
			var kind = data && typeof data.provenance === 'string' ? data.provenance : '';

			if ( 'user_declared' === kind ) {
				return t( 'userDeclaredNote', 'Δηλωμένο από τον χρήστη: τα στοιχεία δόθηκαν από επισκέπτη και η προέλευσή τους δεν επαληθεύεται.' );
			}

			if ( 'scan_unverified' === kind ) {
				return t( 'scanUnverifiedNote', 'Μη επαληθευμένη ανάγνωση: οι τιμές επιβεβαιώθηκαν από τον χρήστη.' );
			}

			if ( 'manual_reconstruction' !== kind ) {
				return '';
			}

			var changed = ( ! data.changed_fields_unknown && Array.isArray( data.changed_fields ) )
				? [ 'PC', 'SN', 'LOT', 'EXP' ].filter( function ( label ) {
					return data.changed_fields.indexOf( label ) !== -1;
				} )
				: [];

			if ( ! changed.length ) {
				return t( 'manualEntryNote', 'Χειροκίνητη καταχώριση: οι τιμές δηλώθηκαν από τον χρήστη, όχι από σάρωση.' );
			}

			return t( 'manualChangeFields', 'Χειροκίνητη αλλαγή: {fields} (δηλώθηκε από τον χρήστη, όχι από σάρωση).' )
				.replace( '{fields}', changed.join( ', ' ) );
		}

		function setProvenanceNote( note ) {
			lastProvenanceNote = note || '';

			if ( els.summaryProvenance ) {
				els.summaryProvenance.textContent = lastProvenanceNote;
				els.summaryProvenance.hidden = '' === lastProvenanceNote;
			}
		}

		function setOutputActionsEnabled( enabled ) {
			[ els.downloadQr, els.printQr, els.copyRaw ].forEach(
				function ( button ) {
					if ( button ) {
						button.disabled = ! enabled;
					}
				}
			);

			if ( els.sendEmail ) {
				els.sendEmail.disabled = ! enabled || ! canSendEmail ||
					! lastRegeneratedPngBase64 || ! lastValidatedRaw ||
					! lastValidatedFields || ! lastValidatedOutput;
			}
		}

		function invalidateGeneratedQr( notify ) {
			rebuildGeneration++;

			var hadOutput = !! lastValidatedRaw;

			clearValidatedOutput();

			/* Το print warning ανήκει στο output που μόλις ακυρώθηκε. */
			printWarnings = [];
			renderAllWarnings();

			setOutputActionsEnabled( false );

			if ( els.outputPanel ) {
				els.outputPanel.hidden = true;
			}

			hideLoader();
			updateRegenerateAvailability();

			if ( notify && hadOutput ) {
				setStatus(
					t( 'fieldsChanged', 'Αλλάξατε τα στοιχεία — πατήστε «Αναδημιουργία» για να ενημερωθεί το GS1 DataMatrix.' ),
					true
				);
			}
		}

		/*
		 * Κάθε listener ξέρει ποιο πεδίο άλλαξε, ώστε να ακυρώνεται μόνο η επιλογή
		 * εκείνου του πεδίου.
		 */
		[
			[ 'PC', els.fieldPc ],
			[ 'SN', els.fieldSn ],
			[ 'LOT', els.fieldLot ],
			[ 'EXP', els.fieldExp ]
		].forEach(
			function ( pair ) {
				var label = pair[ 0 ];
				var field = pair[ 1 ];

				if ( field ) {
					field.addEventListener( 'input', function () {
						invalidateGeneratedQr( true );
						onContestedFieldEdited( label );

						/* Το challenge είναι δεμένο στην ακριβή tuple· μετά από αλλαγή δεν ισχύει. */
						clearPendingChallenge();
						updateRegenerateAvailability();
					} );
				}
			}
		);

		if ( els.fieldExp ) {
			els.fieldExp.addEventListener( 'change', updateRegenerateAvailability );
		}

		setOutputActionsEnabled( false );

		/*
		 * Όνομα πελάτη και ημερομηνία εκτύπωσης δεν κωδικοποιούνται στο σύμβολο, οπότε
		 * η αλλαγή τους δεν ακυρώνει το DataMatrix· ενημερώνει μόνο τη σύνοψη, ώστε η
		 * οθόνη να συμφωνεί με την εκτύπωση, την εικόνα και το email.
		 */
		function updateNonGs1Summary() {
			if ( els.summaryCustomer && els.customerName ) {
				els.summaryCustomer.textContent = els.customerName.value.trim() || '—';
			}

			if ( els.summaryPrintdate && els.printDate ) {
				els.summaryPrintdate.textContent = els.printDate.value
					? toDisplayDate( els.printDate.value )
					: '—';
			}
		}

		if ( els.customerName ) {
			els.customerName.addEventListener( 'input', updateNonGs1Summary );
		}

		if ( els.printDate ) {
			els.printDate.addEventListener( 'input', updateNonGs1Summary );
			els.printDate.addEventListener( 'change', updateNonGs1Summary );
		}

		/*
		 * Το qrrp_token είναι bearer secret: μόλις ο server το έλυσε (QRRP.prefill),
		 * φεύγει από τη γραμμή διευθύνσεων, ώστε να μη μένει σε ιστορικό,
		 * σελιδοδείκτες ή αντιγραμμένους συνδέσμους. Το token μένει στη μνήμη
		 * (activeRebuildToken) για το rebuild. Αποτυχία εδώ δεν σταματά τίποτα.
		 */
		function scrubTokenFromUrl() {
			try {
				if ( ! window.history || typeof window.history.replaceState !== 'function' || typeof window.URL !== 'function' ) {
					return;
				}

				var url = new window.URL( window.location.href );

				if ( ! url.searchParams.has( 'qrrp_token' ) ) {
					return;
				}

				url.searchParams.delete( 'qrrp_token' );
				window.history.replaceState( window.history.state, '', url.pathname + url.search + url.hash );
			} catch ( e ) {
				/* Παλιός browser ή sandbox: το token απλώς μένει στο URL, όπως πριν. */
			}
		}

		function prefillFromUrl() {
			var pc = '', sn = '', lot = '', exp = '';

			/*
			 * Τα πεδία έρχονται μόνο από τον server, λυμένα από αδιαφανές token
			 * (QRRP.prefill), ώστε κανένα ταυτοποιητικό στοιχείο να μη βρίσκεται στο URL.
			 */
			var pre = QRRP.prefill;

			if ( pre && typeof pre === 'object' ) {
				pc = ( pre.pc || '' ).trim();
				sn = ( pre.sn || '' ).trim();
				lot = ( pre.lot || '' ).trim();
				exp = ( pre.exp || '' ).trim();

				/*
				 * Το token ξοδεύεται στο rebuild, όχι στο άνοιγμα της σελίδας: mail gateways
				 * και link scanners ανοίγουν συνδέσμους αυτόματα.
				 */
				if ( typeof pre.token === 'string' && /^[a-f0-9]{32}$/.test( pre.token ) ) {
					activeRebuildToken = pre.token;
				}
			}

			scrubTokenFromUrl();

			if ( ! pc && ! sn && ! lot && ! exp ) {
				return;
			}

			if ( els.fieldPc ) { els.fieldPc.value = pc; }
			if ( els.fieldSn ) { els.fieldSn.value = sn; }
			if ( els.fieldLot ) { els.fieldLot.value = lot; }
			if ( els.fieldExp && endOfMonthIso( exp ) ) {
				expDayZero = exp;
				els.fieldExp.value = endOfMonthIso( exp );
			} else if ( els.fieldExp && isFullIsoDate( exp ) ) {
				els.fieldExp.value = exp;
			}

			if ( els.rawOriginal ) {
				els.rawOriginal.value = '';
			}

			sourceWarnings = [];
			printWarnings  = [];
			renderAllWarnings();
			lastParseNeedsConfirmation = false;

			/* Σύνδεσμος email: δεν υπήρξε σάρωση, άρα δεν υπάρχει πρωτότυπο. */
			lastSourceRaw = '';
			declaredBaseline = {};
			clearPendingChallenge();
			clearContestedPicker();

			if ( els.ambiguousConfirm ) { els.ambiguousConfirm.hidden = true; }
			if ( els.ambiguousCheckbox ) { els.ambiguousCheckbox.checked = false; }
			updateRegenerateAvailability();

			if ( els.resultsPanel ) { els.resultsPanel.hidden = false; }
			if ( els.outputPanel ) { els.outputPanel.hidden = true; }
			setOutputActionsEnabled( false );

			setStatus( t( 'prefilledFromLink', 'Τα στοιχεία φορτώθηκαν από τον σύνδεσμο. Πατήστε «Δημιουργία νέου GS1 DataMatrix».' ) );

			if ( els.resultsPanel && els.resultsPanel.scrollIntoView ) {
				els.resultsPanel.scrollIntoView( { behavior: scrollBehavior(), block: 'start' } );
			}
		}

		prefillFromUrl();

		function handleScannedRaw( raw, options ) {
			var mergedTail = !! ( options && options.mergedTail );

			raw = rawFromDisplay( raw ).trim();

			if ( ! raw || raw.length > MAX_RAW_LENGTH ) {
				setStatus( t( 'invalidGs1Data', 'Μη έγκυρα δεδομένα GS1.' ), true );
				return;
			}

			var thisParse = ++parseGeneration;

			/*
			 * 2.15.3: πριν φύγει το αίτημα, η προηγούμενη συσκευασία φεύγει από την
			 * οθόνη. Αν η νέα ανάλυση αποτύχει ή αργήσει, δεν μένει ενεργό κουμπί
			 * εκτύπωσης με τον κωδικό της προηγούμενης: σωστό state, λάθος ετικέτα.
			 */
			invalidateGeneratedQr( false );

			if ( els.resultsPanel ) {
				els.resultsPanel.hidden = true;
			}

			setStatus( '' );
			showLoader( t( 'analyzingGs1', 'Ανάλυση GS1…' ) );

			if ( els.manualSubmit ) {
				els.manualSubmit.disabled = true;
			}

			var request = ajaxRequest( 'qrrp_parse', { raw: raw } )
				.then( function ( json ) {
					if ( thisParse !== parseGeneration ) {
						return;
					}

					if ( ! json || ! json.success || ! json.data ) {
						setStatus(
							nonceErrorMessage( json ) ||
							( json && json.data && json.data.message ) ||
								t( 'parseFailed', 'Η ανάλυση των GS1 δεδομένων απέτυχε.' ),
							true
						);
						return;
					}

					applyParsedResult( raw, json.data, mergedTail );
				} )
				.catch( function ( error ) {
					if ( thisParse === parseGeneration ) {
						setStatus( networkErrorMessage( error ), true );
					}
				} );

			promiseFinally( request, function () {
				if ( thisParse !== parseGeneration ) {
					return;
				}

				hideLoader();

				if ( els.manualSubmit ) {
					els.manualSubmit.disabled = false;
				}

				/* 2.15.3: η επόμενη σάρωση πρέπει να πέσει στο πεδίο του σαρωτή, όχι σε άλλο πεδίο. */
				focusHwInput( true );
			} );
		}

		function applyParsedResult( raw, parsed, mergedTail ) {
			var fields = parsed.fields || {};
			var warnings = Array.isArray( parsed.warnings ) ? parsed.warnings.slice() : [];

			lastSourceRaw = raw;

			/* Υπάρχει πλέον σάρωση — τέλος η εισαγωγή από το μηδέν. */
			stopManualEntry();

			/* Νέα σάρωση: baseline και challenge της προηγούμενης δεν ισχύουν. */
			declaredBaseline = {};
			clearPendingChallenge();

			clearContestedPicker();
			contestedValues = normalizeContested( parsed.contested_fields );

			els.rawOriginal.value = rawToDisplay( raw );
			els.fieldPc.value = fields.PC || '';
			els.fieldSn.value = fields.SN || '';
			els.fieldLot.value = fields.LOT || '';

			/*
			 * Το <input type="date"> απορρίπτει σιωπηλά ό,τι δεν είναι πλήρης
			 * ημερομηνία (π.χ. ΗΗ=00 στο GS1). Τότε το πεδίο μένει κενό και ο
			 * χρήστης ενημερώνεται να το συμπληρώσει από τη συσκευασία.
			 */
			var exp = String( fields.EXP || '' );

			/* 2.15.3: ΗΗ=00 διατηρείται· το πεδίο δείχνει την τελευταία μέρα του μήνα. */
			expDayZero = '';

			if ( endOfMonthIso( exp ) ) {
				expDayZero = exp;
				exp        = endOfMonthIso( exp );

				warnings.push( t(
					'expiryDayZero',
					'Η λήξη δεν έχει ημέρα (ΗΗ=00): ισχύει έως το τέλος του μήνα και διατηρείται έτσι στον νέο κωδικό.'
				) );
			}

			els.fieldExp.value = isFullIsoDate( exp ) ? exp : '';

			if ( '' === els.fieldExp.value || els.fieldExp.value !== exp ) {
				els.fieldExp.value = '';

				warnings.push( t(
					'expiryNotReadable',
					'Η ημερομηνία λήξης δεν μπόρεσε να συμπληρωθεί αυτόματα. Συμπληρώστε την από τη συσκευασία.'
				) );
			}

			if ( mergedTail ) {
				warnings.push( t(
					'scanMergedAfterPause',
					'Ο σαρωτής έκανε παύση στη μέση της σάρωσης και τα δύο τμήματα ενώθηκαν. Ελέγξτε SN και LOT στη συσκευασία.'
				) );
			}

			sourceWarnings = warnings;
			printWarnings  = [];
			renderAllWarnings();

			/*
			 * Το requires_confirmation υπερισχύει: αν ο server ζητά ανθρώπινο έλεγχο, τον
			 * παίρνει, ακόμη κι αν δηλώνει και inference_auto_accepted.
			 */
			if ( parsed.requires_confirmation === true ) {
				lastParseNeedsConfirmation = true;
			} else if ( parsed.inference_auto_accepted === true ) {
				lastParseNeedsConfirmation = false;
			} else if ( typeof parsed.requires_confirmation === 'boolean' ) {
				lastParseNeedsConfirmation = parsed.requires_confirmation;
			} else {
				var confidence = String( parsed.confidence || 'low' ).toLowerCase();

				lastParseNeedsConfirmation =
					!! parsed.ambiguous ||
					'high' !== confidence ||
					!! parsed.search_truncated;
			}

			if ( els.ambiguousConfirm ) {
				els.ambiguousConfirm.hidden = ! lastParseNeedsConfirmation;
			}

			if ( els.ambiguousCheckbox ) {
				els.ambiguousCheckbox.checked = false;
			}

			/* Ο picker εμφανίζεται μόνο όταν χρειάζεται επιβεβαίωση. */
			if ( lastParseNeedsConfirmation ) {
				renderContestedPicker();
			} else {
				clearContestedPicker();
			}

			updateRegenerateAvailability();

			els.resultsPanel.hidden = false;
			els.outputPanel.hidden = true;
			clearValidatedOutput();
			setOutputActionsEnabled( false );

			/*
			 * 2.15.7: η επιτυχής ανάλυση ανακοινώνεται (live region), γιατί η εστίαση
			 * επιστρέφει στο πεδίο του σαρωτή και ένας χρήστης screen reader αλλιώς
			 * δεν μαθαίνει ότι εμφανίστηκαν αποτελέσματα ή ότι ζητείται επιβεβαίωση.
			 */
			setStatus( lastParseNeedsConfirmation
				? t( 'parseDoneConfirm', 'Η ανάλυση ολοκληρώθηκε, αλλά χρειάζεται επιβεβαίωση: ελέγξτε τα πεδία και τις προειδοποιήσεις πριν δημιουργήσετε τον κωδικό.' )
				: t( 'parseDone', 'Η ανάλυση ολοκληρώθηκε. Ελέγξτε τα πεδία PC, SN, LOT και EXP με τη συσκευασία.' ) );

			els.resultsPanel.scrollIntoView( {
				behavior: scrollBehavior(),
				block: 'start'
			} );
		}

		/*
		 * Το #qrrp-warnings είναι role="alert": κάθε αλλαγή περιεχομένου
		 * ανακοινώνεται. Καλείται και σε κάθε πληκτρολόγηση πεδίου, οπότε το DOM
		 * αγγίζεται μόνο όταν αλλάζει πράγματι το περιεχόμενο.
		 */
		function renderWarnings( warnings ) {
			if ( ! els.warnings ) {
				return;
			}

			var html = ( warnings && warnings.length )
				? '<strong>' + escapeHtml( t( 'warningsLabel', 'Προειδοποιήσεις:' ) ) + '</strong><ul>' +
					warnings.map( function ( warning ) {
						return '<li>' + escapeHtml( warning ) + '</li>';
					} ).join( '' ) +
					'</ul>'
				: '';

			if ( html === renderedWarningsHtml ) {
				return;
			}

			renderedWarningsHtml = html;
			els.warnings.innerHTML = html;
			els.warnings.hidden = '' === html;
		}

		function renderAllWarnings() {
			renderWarnings( sourceWarnings.concat( printWarnings ) );
		}

		/*
		 * Το print warning είναι server-derived: η JavaScript δεν υπολογίζει
		 * X-dimension, αποδίδει μόνο την κρίση και τους αριθμούς του server.
		 */
		function printWarningsFromGeometry( geometry ) {
			if (
				! geometry ||
				typeof geometry !== 'object' ||
				'x_dimension_below_gs1_minimum' !== geometry.print_warning
			) {
				return [];
			}

			var message = t(
				'xDimensionBelowGs1Minimum',
				'Το GS1 DataMatrix δημιουργήθηκε, αλλά στο επιλεγμένο πλάτος εκτύπωσης το X-dimension είναι κάτω από το ελάχιστο GS1.'
			);

			var minimumX     = geometry.minimum_x_dimension_mm;
			var minimumWidth = geometry.minimum_print_width_mm;

			if (
				typeof minimumX === 'number' &&
				isFinite( minimumX ) &&
				minimumX > 0 &&
				typeof minimumWidth === 'number' &&
				isFinite( minimumWidth ) &&
				minimumWidth > 0
			) {
				message += ' ' + t(
					'xDimensionMinimumAdvice',
					'Ελάχιστο X-dimension: {minimum_x} mm. Για αυτό το σύμβολο χρησιμοποιήστε πλάτος τουλάχιστον {minimum_width} mm.'
				)
					.replace( '{minimum_x}', String( minimumX ) )
					.replace( '{minimum_width}', String( minimumWidth ) );
			}

			return [ message ];
		}

		function sameGs1Fields( a, b ) {
			return !! a && !! b && [ 'PC', 'SN', 'LOT', 'EXP' ].every( function ( key ) {
				return a[ key ] === b[ key ];
			} );
		}

		function collectFields() {
			return {
				PC: els.fieldPc.value.trim(),
				SN: els.fieldSn.value.trim(),
				LOT: els.fieldLot.value.trim(),
				EXP: ( expDayZero && els.fieldExp.value === endOfMonthIso( expDayZero ) )
					? expDayZero
					: els.fieldExp.value.trim()
			};
		}

		function validateFields( fields ) {
			var missing = [];

			[ 'PC', 'SN', 'LOT', 'EXP' ].forEach( function ( key ) {
				if ( ! fields[ key ] ) {
					missing.push( key );
				}
			} );

			if ( missing.length ) {
				setStatus(
					t( 'missingFields', 'Λείπουν υποχρεωτικά πεδία.' ) + ' (' + missing.join( ', ' ) + ')',
					true
				);
				return false;
			}

			if ( ! /^\d{4}-\d{2}-\d{2}$/.test( fields.EXP ) ) {
				setStatus( t( 'invalidExpiry', 'Μη έγκυρη ημερομηνία λήξης.' ), true );
				return false;
			}

			return true;
		}

		/* Κάθε μεταβολή ακυρώνει το challenge, που είναι δεμένο στην ακριβή tuple. */
		function clearPendingChallenge() {
			pendingChallenge     = '';
			pendingChallengeCode = '';
			challengeArmed       = false;

			var host = provenanceHost();

			if ( host ) {
				host.hidden      = true;
				host.textContent = '';
			}
		}

		/*
		 * Ο κόμβος δεν υπάρχει στο template του shortcode και δημιουργείται δίπλα στο
		 * κουμπί δημιουργίας. Χωρίς σημείο αγκύρωσης επιστρέφει null και ο καλών
		 * δείχνει μόνο το μήνυμα.
		 */
		function provenanceHost() {
			var host = document.getElementById( 'qrrp-provenance-confirm' );

			if ( host ) {
				return host;
			}

			var anchor = els.regenerate;

			if ( ! anchor || ! anchor.parentNode ) {
				return null;
			}

			host           = document.createElement( 'div' );
			host.id        = 'qrrp-provenance-confirm';
			host.className = 'qrrp-provenance-confirm';
			host.hidden    = true;

			anchor.parentNode.insertBefore( host, anchor.nextSibling );

			return host;
		}

		/*
		 * Διαδρομή ανάκτησης μετά από 409 provenance: οι τιμές δεν προκύπτουν από τη
		 * σάρωση (π.χ. διόρθωση από το τυπωμένο HRI) και ο χρήστης δηλώνει ρητά ότι τις
		 * διάβασε από τη συσκευασία. Το μήνυμα λέει ποια πεδία αποκλίνουν. Επιστρέφει
		 * το κείμενο της οδηγίας ('' αν δεν υπάρχει σημείο αγκύρωσης).
		 */
		function renderProvenanceChallenge( data ) {
			var host = provenanceHost();

			if ( ! host ) {
				return '';
			}

			host.textContent = '';
			host.hidden      = false;

			var note = document.createElement( 'p' );
			note.className = 'qrrp-provenance-note';

			var changed = ( data && Array.isArray( data.changed_fields ) ) ? data.changed_fields : [];

			if ( 'manual_entry_confirmation_required' === pendingChallengeCode ) {
				note.textContent = t(
					'manualEntryConfirm',
					'Δημιουργία χωρίς σάρωση: τα στοιχεία δεν προέρχονται από σάρωση. Επιβεβαιώστε ότι τα PC, SN, LOT και EXP τα διαβάσατε από την ίδια τη συσκευασία.'
				);
			} else if ( 'scan_unverified_required' === pendingChallengeCode ) {
				note.textContent = t(
					'scanUnverified',
					'Η σάρωση ήταν πολύ σύνθετη για να επαληθευτεί αυτόματα. Ελέγξτε τα στοιχεία στη συσκευασία.'
				);
			} else if ( changed.length ) {
				note.textContent = t(
					'manualOverrideFields',
					'Τα παρακάτω δεν προκύπτουν από τη σάρωση:'
				) + ' ' + changed.join( ', ' ) + '. ' + t(
					'manualOverrideCheck',
					'Επιβεβαιώστε τα από το τυπωμένο HRI της συσκευασίας.'
				);
			} else {
				note.textContent = t(
					'manualOverrideGeneric',
					'Οι τιμές που ζητάτε δεν προκύπτουν από τη σάρωση. Επιβεβαιώστε τις από το τυπωμένο HRI της συσκευασίας.'
				);
			}

			host.appendChild( note );

			var button = document.createElement( 'button' );
			button.type      = 'button';
			button.className = 'qrrp-btn qrrp-btn-primary qrrp-provenance-confirm-button';
			button.textContent = t(
				'confirmFromPackage',
				'Τα διάβασα από τη συσκευασία — συνέχεια'
			);

			button.addEventListener( 'click', function () {
				var fields = collectFields();

				if ( ! validateFields( fields ) ) {
					return;
				}

				/*
				 * Το πλαίσιο δεν κρύβεται εδώ: σε αποτυχία δικτύου πρέπει να μείνει διαθέσιμο
				 * (το κουμπί ξαναενεργοποιείται στο τέλος του αιτήματος). Το disabled αποτρέπει
				 * και διπλή υποβολή του ίδιου single-use challenge.
				 */
				button.disabled = true;

				/* Η ρητή ανθρώπινη πράξη που οπλίζει το challenge. */
				challengeArmed = true;

				doRegenerate( fields );
			} );

			host.appendChild( button );

			return note.textContent;
		}

		/*
		 * Κρατά το challenge και εμφανίζει τη διαδρομή επιβεβαίωσης. Επιστρέφει το
		 * κείμενο της οδηγίας, ή '' αν η απάντηση δεν φέρει challenge.
		 */
		function applyProvenanceVerdict( json ) {
			var data = ( json && json.data ) ? json.data : null;

			if ( ! data || ! data.challenge || typeof data.challenge !== 'string' ) {
				return '';
			}

			pendingChallenge     = data.challenge;
			pendingChallengeCode = data.code || '';

			return renderProvenanceChallenge( data );
		}

		function onRegenerate() {
			if ( ! ambiguityAcknowledged() ) {
				setStatus(
					contestedLabels().length
						? t( 'chooseContested', 'Διαλέξτε πρώτα ποια τιμή δείχνει η συσκευασία.' )
						: t( 'confirmLowConfidence', 'Επιβεβαιώστε πρώτα ότι ελέγξατε τα πεδία χαμηλής βεβαιότητας.' ),
					true
				);
				return;
			}

			if ( ! expiryAcknowledged() ) {
				setStatus( t( 'confirmExpired', 'Το προϊόν έχει λήξει. Επιβεβαιώστε πρώτα ότι θέλετε να συνεχίσετε.' ), true );
				return;
			}

			var fields = collectFields();

			if ( ! validateFields( fields ) ) {
				return;
			}

			doRegenerate( fields );
		}

		function doRegenerate( fields ) {
			var thisGeneration = ++rebuildGeneration;

			els.regenerate.disabled = true;
			setStatus( '' );
			showLoader( t( 'validatingAndBuilding', 'Έλεγχος δεδομένων και δημιουργία GS1 DataMatrix…' ) );

			var payload = {
				pc: fields.PC,
				sn: fields.SN,
				lot: fields.LOT,
				exp: fields.EXP
			};

			/*
			 * Το πρωτότυπο της σάρωσης (source_raw — όχι το raw του email endpoint, που
			 * είναι το ανακατασκευασμένο element string), ώστε ο server να επιβάλει τον
			 * κανόνα της ασάφειας. Λείπει μόνο στο prefill από σύνδεσμο email.
			 */
			if ( lastSourceRaw ) {
				payload.source_raw = lastSourceRaw;
			} else if ( manualEntryMode && ! activeRebuildToken ) {
				/* Δεν είναι απόδειξη — ο server ζητά ρητή δήλωση. */
				payload.entry_mode = 'manual';
			}

			/*
			 * Η απάντηση του ανθρώπου, όχι άδεια: ο server εφαρμόζει τον έλεγχο ανεξάρτητα.
			 * Στέλνεται μόνο με πραγματική αποδοχή (επιλογή σε κάθε πεδίο ή checkbox).
			 */
			if ( lastParseNeedsConfirmation && ambiguityAcknowledged() ) {
				payload.ambiguity_confirmed = '1';
			}

			if ( activeRebuildToken ) {
				payload.rebuild_token = activeRebuildToken;
			}

			/* Ίδιος κανόνας για τη λήξη· ο server τον εφαρμόζει ανεξάρτητα. */
			if ( expiryIsInPast() && els.expiryCheckbox && els.expiryCheckbox.checked ) {
				payload.expiry_confirmed = '1';
			}

			/*
			 * Το challenge της προηγούμενης άρνησης· ο server το επαληθεύει απέναντι στην
			 * ακριβή tuple και εκδίδει νέο αν κάτι άλλαξε.
			 */
			if ( pendingChallenge && challengeArmed ) {
				payload.provenance_challenge = pendingChallenge;
			}

			/* Ο οπλισμός ισχύει για μία υποβολή. */
			challengeArmed = false;

			/* Υπόδειξη, όχι ισχυρισμός: ο server δέχεται το baseline μόνο αν επαληθεύεται. */
			[ 'PC', 'SN', 'LOT', 'EXP' ].forEach( function ( label ) {
				if ( declaredBaseline[ label ] ) {
					payload[ 'baseline_' + label.toLowerCase() ] = declaredBaseline[ label ];
				}
			} );

			var request = ajaxRequest( 'qrrp_rebuild', payload )
				.then( function ( json ) {
					if ( thisGeneration !== rebuildGeneration ) {
						return;
					}

					if ( ! json || ! json.success || ! json.data || ! json.data.raw || ! json.data.png ) {
						var message = nonceErrorMessage( json ) ||
							( json && json.data && json.data.message ) ||
							t( 'gs1CheckFailed', 'Τα δεδομένα δεν πέρασαν τον έλεγχο GS1.' );

						applyServerExpiryVerdict( json );
						applyServerAmbiguityVerdict( json );

						/* Ένα 409 με challenge φέρνει και τη διαδρομή επιβεβαίωσης. */
						var challengeNote = applyProvenanceVerdict( json );

						/*
						 * Το πλαίσιο επιβεβαίωσης δεν είναι live region, οπότε η οδηγία ανακοινώνεται
						 * από το #qrrp-status. Η χειροκίνητη δημιουργία δεν είναι σφάλμα.
						 */
						if ( challengeNote && 'manual_entry_confirmation_required' === pendingChallengeCode ) {
							setStatus( challengeNote );
							return;
						}

						setStatus( challengeNote ? message + ' ' + challengeNote : message, true );
						return;
					}

					/* Ο server απέσυρε το token· δεν ξαναστέλνεται. */
					activeRebuildToken = '';

					/* Το challenge καταναλώθηκε server-side. */
					clearPendingChallenge();

					/* Χωρίς passthrough_raw στην απάντηση, η normalizePassthroughRaw() δίνει ''. */
					renderQrFromServer( json.data.raw, json.data.png, fields, json.data.passthrough_raw );

					if ( lastValidatedRaw ) {
						setProvenanceNote( provenanceNoteFrom( json.data ) );
					}

					/* Το geometry warning αφορά μόνο output που πράγματι αποδόθηκε. */
					printWarnings = lastValidatedRaw
						? printWarningsFromGeometry( json.data.geometry )
						: [];

					renderAllWarnings();

					/* Κρατάμε το proof του server μόνο για output που αποδόθηκε επιτυχώς. */
					if (
						lastValidatedRaw &&
						typeof json.data.validated_output === 'string' &&
						'' !== json.data.validated_output
					) {
						lastValidatedOutput = json.data.validated_output;
						setOutputActionsEnabled( true );
					}
				} )
				.catch( function ( error ) {
					if ( thisGeneration !== rebuildGeneration ) {
						return;
					}

					setStatus( networkErrorMessage( error ), true );
				} );

			promiseFinally( request, function () {
				if ( thisGeneration !== rebuildGeneration ) {
					return;
				}

				hideLoader();

				/*
				 * Σε αποτυχία δικτύου το challenge επιζεί και το κουμπί του, που
				 * απενεργοποιήθηκε με το κλικ, πρέπει να ξαναενεργοποιηθεί.
				 */
				if ( pendingChallenge ) {
					var challengeHost = document.getElementById( 'qrrp-provenance-confirm' );
					var challengeButton = challengeHost
						? challengeHost.querySelector( '.qrrp-provenance-confirm-button' )
						: null;

					if ( challengeButton ) {
						challengeButton.disabled = false;
					}
				}

				updateRegenerateAvailability();
			} );
		}

		function renderQrFromServer( raw, pngDataUri, fields, passthroughRaw ) {
			try {
				/* Κακοσχηματισμένο passthrough ρίχνει exception: το output ακυρώνεται. */
				passthroughRaw = normalizePassthroughRaw( passthroughRaw );

				if ( ! rawMatchesCanonical( raw, fields, passthroughRaw ) ) {
					throw new Error( 'server_raw_field_mismatch' );
				}

				if (
					typeof pngDataUri !== 'string' ||
					pngDataUri.indexOf( 'data:image/png;base64,' ) !== 0
				) {
					throw new Error( 'invalid_server_png' );
				}

				/* Η εικόνα έρχεται αυτούσια από τον server· ο browser μόνο την εμφανίζει. */
				var img = document.createElement( 'img' );
				img.alt = 'GS1 DataMatrix';
				img.decoding = 'async';
				img.src = pngDataUri;

				els.qrOutput.innerHTML = '';
				els.qrOutput.appendChild( img );

				lastRegeneratedPngBase64 = pngDataUri.replace(
					/^data:image\/png;base64,/,
					''
				);
				lastValidatedRaw = raw;
				lastValidatedFields = {
					PC: fields.PC,
					SN: fields.SN,
					LOT: fields.LOT,
					EXP: fields.EXP
				};

				els.summaryPc.textContent = fields.PC;
				els.summarySn.textContent = fields.SN;
				els.summaryLot.textContent = fields.LOT;
				els.summaryExp.textContent = toDisplayDate( fields.EXP );
				updateNonGs1Summary();

				setOutputActionsEnabled( true );

				els.outputPanel.hidden = false;
				els.outputPanel.scrollIntoView( {
					behavior: scrollBehavior(),
					block: 'start'
				} );

				setStatus( t( 'datamatrixCreated', 'Το νέο GS1 DataMatrix δημιουργήθηκε με επιτυχία.' ) );
			} catch ( error ) {
				clearValidatedOutput();
				setOutputActionsEnabled( false );
				setStatus( t( 'datamatrixFailed', 'Η δημιουργία του GS1 DataMatrix απέτυχε.' ), true );
			}
		}

		function loadImageFromSrc( src ) {
			return new Promise( function ( resolve, reject ) {
				if ( ! src ) {
					reject( new Error( 'missing_image_src' ) );
					return;
				}

				var image = new Image();

				image.onload = function () {
					resolve( image );
				};

				image.onerror = function () {
					reject( new Error( 'image_load_failed' ) );
				};

				image.src = src;
			} );
		}

		function currentPngDataUri() {
			return lastRegeneratedPngBase64
				? 'data:image/png;base64,' + lastRegeneratedPngBase64
				: '';
		}

		function wrapMonoText( ctx, text, font, maxWidth ) {
			ctx.font = font;

			var value = String( text || '' );
			var lines = [];
			var current = '';

			for ( var i = 0; i < value.length; i++ ) {
				var candidate = current + value[ i ];

				if ( current && ctx.measureText( candidate ).width > maxWidth ) {
					lines.push( current );
					current = value[ i ];
				} else {
					current = candidate;
				}
			}

			if ( current ) {
				lines.push( current );
			}

			return lines.length ? lines : [ '' ];
		}

		/* Αναδίπλωση ανά λέξη για κείμενο αναλογικής γραμματοσειράς στον καμβά. */
		function wrapText( ctx, text, font, maxWidth ) {
			ctx.font = font;

			var words = String( text || '' ).split( /\s+/ );
			var lines = [];
			var current = '';

			for ( var i = 0; i < words.length; i++ ) {
				var word = words[ i ];

				if ( ! word ) {
					continue;
				}

				var candidate = current ? current + ' ' + word : word;

				if ( current && ctx.measureText( candidate ).width > maxWidth ) {
					lines.push( current );
					current = word;
				} else {
					current = candidate;
				}
			}

			if ( current ) {
				lines.push( current );
			}

			return lines.length ? lines : [ '' ];
		}

		function renderCardCanvas( qrImage, rows ) {
			var padding = 28;
			var gap = 28;
			var qrSize = Math.max( qrImage.width || 220, 220 );
			var lineHeight = 26;
			var rawLineHeight = 18;
			var rawMaxWidth = 340;
			var fontStack = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif';
			var monoStack = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
			var labelFont = '600 15px ' + fontStack;
			var textFont = '15px ' + fontStack;
			var rawFont = '12px ' + monoStack;
			var noteFont = '12px ' + fontStack;
			var noteLineHeight = 17;
			var noteMaxWidth = 340;

			var measureCanvas = document.createElement( 'canvas' );
			var measureCtx = measureCanvas.getContext( '2d' );
			var maxTextWidth = 220;
			var renderLines = [];

			rows.forEach( function ( row ) {
				if ( 'GS1' === row[0] ) {
					measureCtx.font = labelFont;
					maxTextWidth = Math.max( maxTextWidth, measureCtx.measureText( 'GS1:' ).width );

					renderLines.push( { label: 'GS1:', height: lineHeight } );

					wrapMonoText( measureCtx, row[1], rawFont, rawMaxWidth ).forEach( function ( line ) {
						maxTextWidth = Math.max( maxTextWidth, measureCtx.measureText( line ).width );
						renderLines.push( { raw: line, height: rawLineHeight } );
					} );
					return;
				}

				if ( 'NOTE' === row[0] ) {
					wrapText( measureCtx, row[1], noteFont, noteMaxWidth ).forEach( function ( line ) {
						maxTextWidth = Math.max( maxTextWidth, measureCtx.measureText( line ).width );
						renderLines.push( { note: line, height: noteLineHeight } );
					} );
					return;
				}

				measureCtx.font = labelFont;
				var labelWidth = measureCtx.measureText( row[0] + ': ' ).width;
				measureCtx.font = textFont;
				var valueWidth = measureCtx.measureText( row[1] || '—' ).width;

				maxTextWidth = Math.max( maxTextWidth, labelWidth + valueWidth );
				renderLines.push( { label: row[0] + ':', value: row[1] || '—', height: lineHeight } );
			} );

			var textBlockHeight = renderLines.reduce( function ( sum, line ) {
				return sum + line.height;
			}, 0 );

			var canvasWidth = padding + qrSize + gap + maxTextWidth + padding;
			var canvasHeight = padding * 2 + Math.max( qrSize, textBlockHeight );

			var canvas = document.createElement( 'canvas' );
			canvas.width = canvasWidth;
			canvas.height = canvasHeight;

			var ctx = canvas.getContext( '2d' );

			if ( ! ctx ) {
				throw new Error( 'canvas_unavailable' );
			}

			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, canvasWidth, canvasHeight );

			var qrY = ( canvasHeight - qrSize ) / 2;
			/* Το DataMatrix σχεδιάζεται με ευκρινή όρια modules, χωρίς εξομάλυνση. */
			ctx.imageSmoothingEnabled = false;
			ctx.drawImage( qrImage, padding, qrY, qrSize, qrSize );
			ctx.imageSmoothingEnabled = true;

			var textX = padding + qrSize + gap;
			var textTop = ( canvasHeight - textBlockHeight ) / 2;

			renderLines.forEach( function ( line ) {
				var baseline = textTop + line.height * 0.72;

				if ( line.raw ) {
					ctx.font = rawFont;
					ctx.fillStyle = '#3c434a';
					ctx.fillText( line.raw, textX, baseline );
					textTop += line.height;
					return;
				}

				if ( line.note ) {
					ctx.font = noteFont;
					ctx.fillStyle = '#646970';
					ctx.fillText( line.note, textX, baseline );
					textTop += line.height;
					return;
				}

				ctx.font = labelFont;
				ctx.fillStyle = '#1d2327';
				ctx.fillText( line.label, textX, baseline );

				if ( line.value ) {
					var labelWidth = ctx.measureText( line.label + ' ' ).width;

					ctx.font = textFont;
					ctx.fillStyle = '#1d2327';
					ctx.fillText( line.value, textX + labelWidth, baseline );
				}

				textTop += line.height;
			} );

			return canvas;
		}

		var REBUILD_NOTE_FALLBACK = 'Αντιγράψτε το και επικολλήστε το στη «Χειροκίνητη εισαγωγή» του QR ReBuilder Pro για να αναδημιουργηθεί ο κωδικός. Το [GS] είναι ο διαχωριστής των πεδίων.';

		function onDownload() {
			var pngSrc = currentPngDataUri();

			if ( ! pngSrc || ! lastValidatedRaw || ! lastValidatedFields ) {
				setStatus( t( 'createFirst', 'Δημιουργήστε πρώτα το νέο GS1 DataMatrix.' ), true );
				return;
			}

			var fields = lastValidatedFields;
			var customer = els.customerName.value.trim() || '—';
			var printDate = els.printDate.value
				? toDisplayDate( els.printDate.value )
				: '—';
			var rawData = rawToDisplay( lastValidatedRaw );

			var rows = [
				[ 'PC', fields.PC || '—' ],
				[ 'SN', fields.SN || '—' ],
				[ 'LOT', fields.LOT || '—' ],
				[ 'EXP', fields.EXP ? toDisplayDate( fields.EXP ) : '—' ],
				[ t( 'customerLabel', 'Πελάτης' ), customer ],
				[ t( 'printDateLabel', 'Ημ/νία εκτύπωσης' ), printDate ],
				[ 'GS1', rawData || '—' ],
				[ 'NOTE', t( 'rebuildNote', REBUILD_NOTE_FALLBACK ) ]
			];

			/* 2.15.2: η σήμανση χειροκίνητης αλλαγής και στην εικόνα, πριν από τη γραμμή GS1. */
			if ( lastProvenanceNote ) {
				rows.splice( rows.length - 2, 0, [ 'NOTE', lastProvenanceNote ] );
			}

			loadImageFromSrc( pngSrc )
				.then( function ( image ) {
					var canvas = renderCardCanvas( image, rows );
					var link = document.createElement( 'a' );

					/*
					 * Χωρίς Serial Number στο όνομα αρχείου: το SN ταυτοποιεί μια συγκεκριμένη
					 * συσκευασία και τα ονόματα αρχείων καταλήγουν σε λίστες λήψεων και backups.
					 */
					var stamp = ( els.printDate.value || todayISO() )
						.replace( /[^0-9]/g, '' )
						.slice( 0, 8 );

					link.href = canvas.toDataURL( 'image/png' );
					link.download = 'gs1-datamatrix' + ( stamp ? '-' + stamp : '' ) + '.png';

					document.body.appendChild( link );
					link.click();
					removeNode( link );
				} )
				.catch( function () {
					setStatus( t( 'imageSaveFailed', 'Η αποθήκευση της εικόνας απέτυχε.' ), true );
				} );
		}

		/*
		 * Φυσικό μέγεθος του κωδικού στην εκτύπωση: { mm, floor }. Το mm είναι NaN
		 * όταν δεν ήρθε καμία ρύθμιση από τον server· το floor είναι NaN όταν
		 * δεν ήρθε πάτωμα σαρωσιμότητας.
		 */
		function resolvePrintBarcodeSize() {
			/*
			 * Το εύρος έρχεται από τον server (qrrp_print_barcode_mm_range()). Το clamp
			 * εφαρμόζεται μόνο όταν ήρθε όριο· η JavaScript δεν εφευρίσκει δικό της.
			 */
			var barcodeMm    = parseInt( QRRP.printBarcodeMm, 10 );
			var barcodeMmMin = parseInt( QRRP.printBarcodeMmMin, 10 );
			var barcodeMmMax = parseInt( QRRP.printBarcodeMmMax, 10 );

			if ( isNaN( barcodeMm ) ) {
				barcodeMm = parseInt( QRRP.printBarcodeMmDefault, 10 );
			}

			if ( ! isNaN( barcodeMmMin ) && barcodeMm < barcodeMmMin ) {
				barcodeMm = barcodeMmMin;
			}

			if ( ! isNaN( barcodeMmMax ) && barcodeMm > barcodeMmMax ) {
				barcodeMm = barcodeMmMax;
			}

			/*
			 * Πάτωμα σαρωσιμότητας (printBarcodeMmFloor): κάτω από αυτό ο κωδικός δεν
			 * συρρικνώνεται για να χωρέσει. Διαφορετική έννοια από το ελάχιστο του εύρους.
			 * Αν δεν ήρθε από τον server, δεν γίνεται καμία προσαρμογή μεγέθους.
			 */
			var barcodeMmFloor = parseInt( QRRP.printBarcodeMmFloor, 10 );

			if ( ! isNaN( barcodeMmFloor ) && barcodeMmFloor > barcodeMm ) {
				barcodeMmFloor = barcodeMm;
			}

			return { mm: barcodeMm, floor: barcodeMmFloor };
		}

		function printPageSize() {
			/* Σε φαρδιά-κοντή ετικέτα το landscape βάζει κωδικό και στοιχεία δίπλα-δίπλα. */
			var orientation = String( QRRP.printOrientation || 'landscape' );
			var pageSize = 'landscape';
			if ( 'portrait' === orientation ) {
				pageSize = 'portrait';
			} else if ( 'auto' === orientation ) {
				pageSize = 'auto';
			}

			return pageSize;
		}

		function buildPrintCss( barcodeMm, barcodeMmFloor, pageSize ) {
			/* Κενό όταν λείπει το πάτωμα: ο κωδικός κρατά ακριβώς το μέγεθος της ρύθμισης. */
			var fitFloorCss = isNaN( barcodeMmFloor )
				? ''
				: 'max-width:100%;max-height:100%;min-width:' + barcodeMmFloor + 'mm;';

			return (
				/*
				 * margin:0 στη σελίδα και το κενό ως padding στην ετικέτα, ώστε το 100%
				 * παρακάτω να είναι ολόκληρη η ετικέτα.
				 */
				'@page{size:' + pageSize + ';margin:0;}' +
				'*{box-sizing:border-box;}' +
				'html,body{margin:0;padding:0;}' +
				'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#000;}' +
				/*
				 * Μία ετικέτα = μία σελίδα: height:100% και overflow:hidden δένουν την ετικέτα
				 * στη σελίδα (το page-break-inside μόνο του αγνοείται όταν δεν χωράει). Χωρίς
				 * justify-content:center και με margin:0, μια υπέρβαση πέφτει ορατά δεξιά αντί
				 * να χαθεί η αρχή του περιεχομένου σε αρνητικές συντεταγμένες.
				 */
				'.qrrp-print-label{display:flex;flex-wrap:wrap;align-items:center;gap:3mm;padding:2mm;text-align:left;page-break-inside:avoid;break-inside:avoid;width:100%;height:100%;overflow:hidden;margin:0;}' +
				/*
				 * Ο κωδικός στο φυσικό μέγεθος της ρύθμισης. Με πάτωμα από τον server
				 * (fitFloorCss) συρρικνώνεται για να χωρέσει, αλλά όχι κάτω από το πάτωμα·
				 * πιο κάτω ξεχειλίζει ορατά αντί να γίνει μη αναγνώσιμος. Το DataMatrix είναι
				 * τετράγωνο, οπότε σε χαμηλή ετικέτα μετρά και το max-height.
				 */
				'.qrrp-print-qr{flex:0 0 auto;width:' + barcodeMm + 'mm;height:auto;' + fitFloorCss + 'image-rendering:pixelated;image-rendering:crisp-edges;}' +
				/* min-width:0 ώστε το κείμενο να αναδιπλώνεται αντί να σπρώχνει τον κωδικό. */
				'.qrrp-print-info{flex:1 1 45mm;min-width:0;max-width:100%;}' +
				'.qrrp-print-customer{font-size:12pt;font-weight:700;margin:0 0 1.5mm;word-break:break-word;}' +
				/*
				 * Η γραμμή GS1 είναι monospace, άρα 22ch = 22 χαρακτήρες ανά σειρά: ταβάνι σε
				 * φαρδιά ετικέτα (η ουρά δεν φτάνει στο δεξί άκρο), 100% σε στενή.
				 */
				'.qrrp-print-gs1{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:9pt;font-weight:700;word-break:break-all;line-height:1.35;margin:0 0 1mm;max-width:min(22ch,100%);}' +
				'.qrrp-print-note{font-size:7pt;color:#333;line-height:1.3;margin:0 0 1.5mm;word-break:break-word;}' +
				'.qrrp-print-provenance{font-size:7pt;font-weight:700;color:#000;line-height:1.3;margin:0 0 1.5mm;padding:0.5mm 1mm;border:0.3mm solid #000;word-break:break-word;}' +
				'.qrrp-print-meta{font-size:8pt;color:#000;line-height:1.5;}' +
				/* white-space:normal: ένα μακρύ SN δεν γίνεται άσπαστο κουτί. */
				'.qrrp-print-meta-item{display:inline-block;margin:0 2mm 0.5mm 0;white-space:normal;}' +
				/*
				 * Στενή ετικέτα: στοίβαγμα αντί για συμπίεση. Το κατώφλι είναι το μέγεθος του
				 * κωδικού συν το flex-basis (45mm) του .qrrp-print-info.
				 */
				'@media (max-width:' + ( barcodeMm + 45 ) + 'mm){.qrrp-print-label{flex-direction:column;align-items:flex-start;justify-content:flex-start;}.qrrp-print-info{width:100%;}}'
			);
		}

		function buildPrintLabelHtml( pngSrc ) {
			var customer = els.customerName.value.trim();
			var printDate = els.printDate.value
				? toDisplayDate( els.printDate.value )
				: '—';
			var rawData = rawToDisplay( lastValidatedRaw );

			var metaRows = [
				[ 'PC', lastValidatedFields.PC ],
				[ 'SN', lastValidatedFields.SN ],
				[ 'LOT', lastValidatedFields.LOT ],
				[ 'EXP', toDisplayDate( lastValidatedFields.EXP ) ],
				[ t( 'printDateShortLabel', 'Ημ/νία' ), printDate ]
			];

			var metaHtml = metaRows.map( function ( row ) {
				return '<span class="qrrp-print-meta-item"><strong>' +
					escapeHtml( row[0] ) + ':</strong> ' + escapeHtml( row[1] ) +
					'</span>';
			} ).join( '' );

			/* Προαιρετικά μπλοκ, ώστε μια μικρή ετικέτα να μην ξεχειλίζει. */
			var noteBlock = String( QRRP.printShowNote ) !== '0'
				? '<div class="qrrp-print-note">' + escapeHtml( t( 'rebuildNote', REBUILD_NOTE_FALLBACK ) ) + '</div>'
				: '';
			/* 2.15.2: η σήμανση χειροκίνητης αλλαγής τυπώνεται πάντα, ανεξάρτητα από τις ρυθμίσεις. */
			var provenanceBlock = lastProvenanceNote
				? '<div class="qrrp-print-provenance">' + escapeHtml( lastProvenanceNote ) + '</div>'
				: '';
			var metaBlock = String( QRRP.printShowMeta ) !== '0'
				? '<div class="qrrp-print-meta">' + metaHtml + '</div>'
				: '';

			return '<div class="qrrp-print-label">' +
				'<img class="qrrp-print-qr" alt="GS1 DataMatrix" src="' + escapeAttr( safePngDataUri( pngSrc ) ) + '">' +
				'<div class="qrrp-print-info">' +
				'<div class="qrrp-print-customer">' + escapeHtml( customer || '—' ) + '</div>' +
				'<div class="qrrp-print-gs1">GS1: ' + escapeHtml( rawData ) + '</div>' +
				provenanceBlock +
				noteBlock +
				metaBlock +
				'</div>' +
				'</div>';
		}

		function onPrint() {
			var pngSrc = currentPngDataUri();

			if ( ! pngSrc || ! lastValidatedRaw || ! lastValidatedFields ) {
				setStatus( t( 'createFirst', 'Δημιουργήστε πρώτα το νέο GS1 DataMatrix.' ), true );
				return;
			}

			var barcode = resolvePrintBarcodeSize();
			var barcodeMm = barcode.mm;

			/*
			 * Χωρίς καμία ρύθμιση μεγέθους από τον server η εκτύπωση ακυρώνεται: το φυσικό
			 * μέγεθος επηρεάζει τη σαρωσιμότητα και δεν το αποφασίζει ο browser.
			 */
			if ( isNaN( barcodeMm ) ) {
				setStatus( t( 'printSettingsMissing', 'Οι ρυθμίσεις εκτύπωσης δεν φορτώθηκαν, οπότε η εκτύπωση ακυρώθηκε — μια ετικέτα με μέγεθος κωδικού που δεν επιλέξατε δεν είναι αξιόπιστη. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.' ), true );
				return;
			}

			var printWindow = window.open( '', '_blank' );

			if ( ! printWindow ) {
				setStatus( t( 'printBlocked', 'Το παράθυρο εκτύπωσης αποκλείστηκε από τον browser.' ), true );
				return;
			}

			var lang = document.documentElement.getAttribute( 'lang' ) || 'el';

			printWindow.opener = null;
			printWindow.document.write(
				'<!doctype html><html lang="' + escapeAttr( lang ) + '"><head>' +
				'<meta charset="utf-8"><title>QR ReBuilder Pro</title><style>' +
				buildPrintCss( barcodeMm, barcode.floor, printPageSize() ) +
				'</style></head><body>' +
				buildPrintLabelHtml( pngSrc ) +
				'</body></html>'
			);

			printWindow.document.close();
			printWindow.focus();

			printWhenBarcodeReady( printWindow );
		}

		function printWhenBarcodeReady( printWindow ) {
			/*
			 * Το print() περιμένει να φορτωθεί η εικόνα, και αν ο κωδικός δεν είναι ορατός
			 * μέσα στο χρονικό όριο η εκτύπωση ακυρώνεται: μια ετικέτα χωρίς DataMatrix
			 * μοιάζει έγκυρη και είναι χειρότερη από καμία.
			 */
			var settled = false;
			var printImage = printWindow.document.querySelector( '.qrrp-print-qr' );

			/* Το complete ισχύει και σε αποτυχία· μόνο το naturalWidth δείχνει επιτυχία. */
			function barcodeIsUsable() {
				return !! printImage && printImage.complete && printImage.naturalWidth > 0;
			}

			function runPrint() {
				if ( settled ) {
					return;
				}

				settled = true;
				printWindow.print();
			}

			/* Fail-closed: κλείνει το παράθυρο και ενημερώνει τον χρήστη. */
			function abortPrint() {
				if ( settled ) {
					return;
				}

				settled = true;

				try {
					printWindow.close();
				} catch ( error ) {
					/* Ο browser μπορεί να αρνηθεί το close· το μήνυμα αρκεί. */
				}

				setStatus(
					t( 'printImageFailed', 'Η εικόνα του κωδικού δεν φορτώθηκε, οπότε η εκτύπωση ακυρώθηκε — μια ετικέτα χωρίς DataMatrix δεν έχει καμία αξία. Δοκιμάστε ξανά.' ),
					true
				);
			}

			if ( barcodeIsUsable() ) {
				runPrint();
				return;
			}

			if ( ! printImage ) {
				abortPrint();
				return;
			}

			printImage.addEventListener( 'load', function () {
				if ( barcodeIsUsable() ) {
					runPrint();
					return;
				}

				abortPrint();
			} );

			printImage.addEventListener( 'error', abortPrint );

			/* Αν ως τότε η εικόνα δεν είναι έγκυρη, δεν τυπώνουμε. */
			printWindow.setTimeout( function () {
				if ( barcodeIsUsable() ) {
					runPrint();
					return;
				}

				abortPrint();
			}, 5000 );
		}

		function onCopyRaw() {
			var raw = rawToClipboardRaw( lastValidatedRaw );

			if ( ! raw ) {
				setStatus( t( 'createFirst', 'Δημιουργήστε πρώτα το νέο GS1 DataMatrix.' ), true );
				return;
			}

			copyToClipboard(
				raw,
				t( 'gs1Copied', 'Τα GS1 δεδομένα αντιγράφηκαν. Επικολλήστε στο πρόγραμμα με Ctrl+V.' )
			);
		}

		function copyToClipboard( value, successMessage ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( value )
					.then( function () {
						setStatus( successMessage );
					} )
					.catch( function () {
						copyFallback( value, successMessage );
					} );
				return;
			}

			copyFallback( value, successMessage );
		}

		function copyFallback( value, successMessage ) {
			var textarea = document.createElement( 'textarea' );

			textarea.value = value;
			textarea.setAttribute( 'readonly', '' );
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';

			document.body.appendChild( textarea );
			textarea.select();

			try {
				/*
				 * Το execCommand επιστρέφει false σε άρνηση χωρίς exception· χωρίς έλεγχο θα
				 * αναφέραμε επιτυχία και θα επικολλούνταν παλιό περιεχόμενο clipboard.
				 */
				if ( document.execCommand( 'copy' ) ) {
					setStatus( successMessage );
				} else {
					setStatus( t( 'copyFailed', 'Η αντιγραφή απέτυχε.' ), true );
				}
			} catch ( error ) {
				setStatus( t( 'copyFailed', 'Η αντιγραφή απέτυχε.' ), true );
			}

			removeNode( textarea );
		}

		function onSendEmail() {
			if ( ! canSendEmail || ! els.emailInput || ! els.sendEmail ) {
				setStatus( t( 'noEmailPermission', 'Δεν έχετε δικαίωμα αποστολής email από αυτό το εργαλείο.' ), true );
				return;
			}

			var email = els.emailInput.value.trim();

			if ( ! email || ! els.emailInput.checkValidity() ) {
				setStatus( t( 'enterValidEmail', 'Εισάγετε μία έγκυρη διεύθυνση email.' ), true );
				return;
			}

			if (
				! lastRegeneratedPngBase64 ||
				! lastValidatedRaw ||
				! lastValidatedFields ||
				! lastValidatedOutput
			) {
				setStatus( t( 'createFirst', 'Δημιουργήστε πρώτα το νέο GS1 DataMatrix.' ), true );
				return;
			}

			/*
			 * Το email στέλνει τα επικυρωμένα πεδία. Αν η φόρμα διαφέρει (π.χ. αλλαγή τιμής
			 * χωρίς input event), θα στελνόταν κάτι άλλο από αυτό που βλέπει ο χρήστης.
			 */
			if ( ! sameGs1Fields( collectFields(), lastValidatedFields ) ) {
				invalidateGeneratedQr( false );
				setStatus( t( 'validatedDataChanged', 'Τα επικυρωμένα GS1 δεδομένα άλλαξαν. Δημιουργήστε ξανά το GS1 DataMatrix.' ), true );
				return;
			}

			var fields = lastValidatedFields;
			var emailGeneration = rebuildGeneration;

			var emailPayload = {
				email: email,
				raw: lastValidatedRaw,
				pc: fields.PC,
				sn: fields.SN,
				lot: fields.LOT,
				exp: fields.EXP,
				customer_name: els.customerName.value.trim(),
				print_date: els.printDate.value
					? toDisplayDate( els.printDate.value )
					: '',
				/*
				 * Ολόκληρο το URL της σελίδας (με το query string, χωρίς hash), ώστε ο
				 * σύνδεσμος του email να επιστρέφει εδώ. Ο server το ελέγχει ως same-site.
				 */
				page_url: ( window.location.origin || '' ) +
					( window.location.pathname || '' ) +
					( window.location.search || '' )
			};

			emailPayload.validated_output = lastValidatedOutput;

			/*
			 * Κριτήριο είναι το επικυρωμένο EXP: το rebuild του απαίτησε ήδη επιβεβαίωση,
			 * ενώ το checkbox μηδενίζεται σε κάθε αλλαγή ημερομηνίας.
			 */
			if ( dateIsInPast( fields.EXP ) || ( '' !== fields.EXP && fields.EXP === serverExpiredFor ) ) {
				emailPayload.expiry_confirmed = '1';
			}

			els.sendEmail.disabled = true;
			setStatus( '' );
			showLoader( t( 'sendingEmail', 'Αποστολή email…' ) );

			var request = ajaxRequest( 'qrrp_send_email', emailPayload )
				.then( function ( json ) {
					/*
					 * 2.15.7: μετά από «Νέα σάρωση» ή νέο κωδικό η απάντηση ανήκει σε
					 * άλλη οθόνη· δεν γράφει status (π.χ. «στάλθηκε» σε άδειο εργαλείο).
					 */
					if ( emailGeneration !== rebuildGeneration ) {
						return;
					}

					if ( json && json.success ) {
						setStatus( t( 'emailSent', 'Το email στάλθηκε με επιτυχία.' ) );
						return;
					}

					applyServerExpiryVerdict( json );

					var emailCode = (
						json &&
						json.data &&
						json.data.code
					)
						? String( json.data.code )
						: '';

					if ( 'validated_output_invalid' === emailCode ) {
						lastValidatedOutput = '';
						setOutputActionsEnabled( false );

						setStatus(
							( json && json.data && json.data.message ) ||
								t(
								'outputStale',
								'Η επαλήθευση του ήδη δημιουργημένου κωδικού έληξε ή δεν αντιστοιχεί στα τρέχοντα στοιχεία. Δημιουργήστε ξανά τον κωδικό.'
							),
							true
						);

						return;
					}

					setStatus(
						nonceErrorMessage( json ) ||
							( json && json.data && json.data.message ) ||
							t( 'emailFailed', 'Η αποστολή email απέτυχε.' ),
						true
					);
				} )
				/*
				 * Σε timeout το email μπορεί να έχει ήδη σταλεί· η networkErrorMessage()
				 * προειδοποιεί να ελεγχθεί πριν από νέα αποστολή.
				 */
				.catch( function ( error ) {
					if ( emailGeneration === rebuildGeneration ) {
						setStatus( networkErrorMessage( error ), true );
					}
				} );

			promiseFinally( request, function () {
				/* 2.15.7: ο loader ανήκει πλέον σε νεότερη ενέργεια (ή τον έκλεισε το reset). */
				if ( emailGeneration !== rebuildGeneration ) {
					return;
				}

				hideLoader();

				if (
					canSendEmail &&
					emailGeneration === rebuildGeneration &&
					lastRegeneratedPngBase64 &&
					lastValidatedRaw &&
					lastValidatedFields &&
					lastValidatedOutput
				) {
					els.sendEmail.disabled = false;
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();