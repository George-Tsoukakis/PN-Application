/**
 * PlanDose — api.js
 * Part of the modular frontend JavaScript files located in assets/js/.
 * All modules share one namespace object (window.__PlandoseNS, aliased as PD).
 * Mutable state lives on PD.s.*; helpers, config, DOM refs and constants live on PD.*
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;

	/* Namespace missing → the tool is not available on this page
	   (guest/no-permission or required DOM absent). Bail out quietly. */
	if (!PD) {
		return;
	}

	/**
	 * WordPress's check_ajax_referer() replies with a bare "-1" (not a JSON
	 * object) when the security nonce has expired — typically because the
	 * browser tab was left open for many hours. That "-1" still parses as
	 * valid JSON (the number -1), so without this check it would silently
	 * fall through to a generic "something went wrong" message instead of telling
	 * the pharmacist what actually happened and how to fix it.
	 */
	PD.isValidAjaxResponse = function isValidAjaxResponse(json) {
		return json !== null && typeof json === 'object' && !Array.isArray(json);
	};

	/**
	 * POST to admin-ajax.php with a bounded timeout and strict response
	 * parsing. WordPress or an upstream proxy can occasionally return HTML
	 * (fatal-error page, login page, WAF response) instead of JSON; treating
	 * that as a normal response produced confusing follow-up errors.
	 */
	PD.ajaxPost = function ajaxPost(action, extraParams, includeNonce) {
		var body = new URLSearchParams();
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		var timeoutId = null;
		body.append('action', String(action || ''));
		if (includeNonce !== false) {
			body.append('nonce', PD.config.nonce || '');
		}
		if (extraParams) {
			Object.keys(extraParams).forEach(function (key) {
				if (extraParams[key] !== undefined && extraParams[key] !== null) {
					body.append(key, String(extraParams[key]));
				}
			});
		}

		var request = fetch(PD.config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString(),
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			return response.text();
		}).then(function (text) {
			try {
				return JSON.parse(text);
			} catch (e) {
				/* wp_send_json_error() legitimately uses HTTP 4xx/5xx while
					 still returning a useful JSON payload, so we don't gate on
					 response.ok — only genuinely non-JSON bodies (HTML error,
					 login or WAF pages) are rejected here. */
				throw new Error('invalid_json_response');
			}
		});

		/*
		 * Enforce the timeout via Promise.race so it works even on the (now
		 * very rare) browsers that have fetch() but not AbortController: there
		 * the underlying HTTP request can't be cancelled, but the returned
		 * promise still rejects so the UI never hangs waiting forever. When
		 * AbortController is available the request itself is also aborted.
		 */
		var timed = new Promise(function (resolve, reject) {
			timeoutId = setTimeout(function () {
				if (controller) {
					controller.abort();
				}
				reject(new Error('request_timeout'));
			}, PD.AJAX_TIMEOUT_MS);
		});

		return Promise.race([request, timed]).finally(function () {
			if (timeoutId) {
				clearTimeout(timeoutId);
			}
		});
	};

	/** Generate an unpredictable, server-compatible idempotency token. */
	PD.createPrintToken = function createPrintToken() {
		var bytes;
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
			bytes = new Uint8Array(16);
			window.crypto.getRandomValues(bytes);
			return Array.prototype.map.call(bytes, function (byte) {
				return byte.toString(16).padStart(2, '0');
			}).join('');
		}

		/* Compatibility fallback for very old browsers. The timestamp and
			 counter prevent accidental collisions even without Web Crypto. */
		PD.s.printWindowCounter++;
		return String(Date.now()) + '-' + String(PD.s.printWindowCounter) + '-' + String(Math.random()).slice(2);
	};

	/**
	 * Fetch the pharmacy header (name, address, phone, email) once per
	 * session and cache it on PD.s.header.
	 *
	 * Every callback is told whether the data is actually there, as a
	 * boolean argument. A failed request still runs the callbacks — the
	 * preview must keep rendering, with placeholders, rather than freezing
	 * the modal — but the caller can tell the two apart. The print flow
	 * needs that distinction: printing with an empty header produces a plan
	 * whose "Στοιχεία Φαρμακείου" box is a dash, hands the patient a sheet
	 * with no pharmacy phone number on it, and still spends one of the
	 * month's print credits. See handlePrint() in print.js.
	 *
	 * PD.s.header stays null on failure, so the next call simply retries.
	 *
	 * @param {function(boolean)} [callback] Receives true when the header
	 *        is loaded, false when it could not be fetched.
	 */
	/** A header object with at least the pharmacy's name. */
	PD.isUsableHeader = function isUsableHeader(data) {
		return !!data && typeof data === 'object' && !Array.isArray(data) &&
			typeof data.name === 'string' && data.name.trim() !== '';
	};

	PD.loadHeader = function loadHeader(callback) {
		if (PD.s.header) {
			if (callback) {
				callback(true);
			}
			return;
		}
		if (callback) {
			PD.s.headerCallbacks.push(callback);
		}
		if (PD.s.headerLoading) {
			return;
		}
		PD.s.headerLoading = true;
		PD.s.headerSessionExpired = false;
		PD.s.headerError = '';
		PD.s.headerEmpty = false;
		PD.ajaxPost('plandose_get_header').then(function (json) {
			/* A bare "-1"/"0" is an expired nonce, not a network
			   problem — handlePrint() then says so (sessionExpired). */
			if (!PD.isValidAjaxResponse(json)) {
				PD.s.headerSessionExpired = true;
				return;
			}
			if (!json.success) {
				/* A refusal (e.g. rate limit) is not a connection
				   problem — keep the server's own message. */
				PD.s.headerError = (json.data && typeof json.data.message === 'string') ? json.data.message : '';
				return;
			}
			/* An empty or malformed header is NOT loaded — the
			   sheet would print «—» as the pharmacy. */
			if (PD.isUsableHeader(json.data)) {
				PD.s.header = json.data;
			} else {
				PD.s.headerEmpty = true;
			}
		}).catch(function () {
			/* ignore, callbacks below still fire so the flow can continue */
		}).then(function () {
			PD.s.headerLoading = false;
			var loaded = !!PD.s.header;
			var callbacks = PD.s.headerCallbacks;
			PD.s.headerCallbacks = [];
			callbacks.forEach(function (cb) {
				cb(loaded);
			});
		});
	};

	PD.loadPrintStatus = function loadPrintStatus(callback) {
		PD.ajaxPost('plandose_check_print').then(function (json) {
			PD.s.printStatus = json && json.success ? json.data : null;
			PD.renderPrintCounter();
			if (callback) {
				callback(json);
			}
		}).catch(function () {
			if (callback) {
				callback(null);
			}
		});
	};

	PD.checkPrintAllowed = function checkPrintAllowed(callback, onFail) {
		if (PD.s.isCheckingPrint || PD.s.isPrinting) {
			return;
		}
		PD.s.isCheckingPrint = true;
		/* The Print button's busy state is owned by handlePrint()
		   (PD.s.isPreparingPrint) for the whole preparation. Releasing it
		   here would re-enable the button while the header lookup could
		   still be running. */
		/* The check can outlive the flow that asked for it (the header
		   lookup failed first and handlePrint() aborted): the buttons were
		   then left disabled by isCheckingPrint, so refresh them. */
		function checkDone() {
			PD.s.isCheckingPrint = false;
			if (typeof PD.updateStepButtons === 'function') {
				PD.updateStepButtons();
			}
			if (typeof PD.syncPrintLock === 'function') {
				PD.syncPrintLock();
			}
		}
		PD.ajaxPost('plandose_check_print').then(function (json) {
			checkDone();
			if (!PD.isValidAjaxResponse(json)) {
				PD.setMessage(PD.txt('sessionExpired', 'Η σελίδα ήταν ανοιχτή πολλή ώρα και η σύνδεσή σας έληξε. Κάντε ανανέωση της σελίδας (F5) και δοκιμάστε ξανά.'), 'error');
				if (onFail) {
					onFail();
				}
				return;
			}
			if (!json.success) {
				PD.setMessage(json.data && json.data.message ? json.data.message : PD.txt('genericError', 'Κάτι πήγε στραβά.'), 'error');
				if (onFail) {
					onFail();
				}
				return;
			}
			PD.s.printStatus = json.data || null;
			PD.renderPrintCounter();
			if (callback) {
				callback();
			}
		}).catch(function () {
			checkDone();
			PD.setMessage(PD.txt('connectionError', 'Πρόβλημα σύνδεσης. Δοκιμάστε ξανά.'), 'error');
			if (onFail) {
				onFail();
			}
		});
	};

	/**
	 * Registers the print with the server exactly once per token.
	 *
	 * EVERY print goes through the server, including a free
	 * reprint of a plan whose token is already confirmed. The callback
	 * receives (recorded, info) where info.alreadyRecorded is true when the
	 * server answered from its print receipts without charging.
	 *
	 * lastPrintToken is only set once the server has actually confirmed
	 * the print as recorded (a `success` response) — never just because a
	 * request was sent. A network failure or a server-reported failure
	 * (server_error) leaves lastPrintToken untouched, so a legitimate
	 * retry with the SAME token can still go through. This matters
	 * because the server releases its own print lock whenever the
	 * print wasn't actually recorded, specifically so a same-token retry
	 * isn't wrongly treated as a duplicate (see
	 * Plandose_Ajax::release_print_lock() in class-plandose-ajax.php) —
	 * a client that locked itself out of retrying the same token would
	 * defeat that.
	 *
	 * printCreditLocked guards against overlapping calls while a request
	 * is in flight, and is released again as soon as that request settles
	 * (success, server-reported failure, or network failure alike) rather
	 * than only at the start of the next print action, so a failed
	 * attempt can be retried immediately.
	 *
	 * ON `duplicate_ignored`
	 *
	 * The server answers `success` with `duplicate_ignored` whenever it
	 * could not take the print lock for this token. Usually that means
	 * exactly what it says — a double click, or `afterprint` and the
	 * fallback timeout both firing for one print — and the print really
	 * was recorded by the request that won the lock, so reporting success
	 * is right.
	 *
	 * But acquire_print_lock_atomic() also returns "already held" when the
	 * object cache itself misbehaves (see its note about failing toward
	 * treating the request as a duplicate). That path records nothing, and
	 * treating it identically would mean a green message, form wiped,
	 * plan gone, counter unchanged: the pharmacist is told the plan
	 * printed and has no way to know it was never counted.
	 *
	 * The two are told apart by the counter the response carries. A real
	 * duplicate is a duplicate OF something, so the count has moved past
	 * where it stood before this token's FIRST attempt; a lock that was
	 * never really held leaves it exactly there. When the count has not
	 * moved, this reports failure — which keeps the plan on screen and
	 * lets the pharmacist retry with the same token.
	 *
	 * The baseline is PD.s.pendingPrintCountBefore, captured once when the
	 * token was minted (see doPrintSafely() in print.js) — not the count
	 * from the latest check_print, which on a retry after a lost response
	 * already includes the recorded print. With that, the genuine duplicate
	 * would compare equal, be reported as "not recorded", the plan would be
	 * printed again on every retry, and once the server's 60-second lock
	 * expired the same print would be charged a second time.
	 */
	PD.consumePrintCreditOnce = function consumePrintCreditOnce(token, callback) {
		if (PD.s.printCreditLocked) {
			if (callback) {
				callback(false);
			}
			return;
		}
		/* No shortcut for a token already confirmed here (a free reprint,
		   or a retry after the print did not open): answering true without
		   asking the server would let anyone who sets PD.s.lastPrintToken
		   from the browser console print for free. The server answers it
		   from its own print receipts
		   (`already_recorded`), free of charge and regardless of the
		   monthly limit — see Plandose_Ajax::PRINT_RECEIPT_META_KEY. */

		/* The count from before this token's first attempt (see the note
		   above). Falls back to the current status only when no baseline was
		   recorded for this token. */
		var countBefore = (PD.s.pendingPrintToken === token && typeof PD.s.pendingPrintCountBefore === 'number')
			? PD.s.pendingPrintCountBefore
			: ((PD.s.printStatus && typeof PD.s.printStatus.print_count === 'number')
				? PD.s.printStatus.print_count
				: null);

		/* One request id per press of Print. It is reused only by a
		   retry of the SAME press after a lost answer (the id is dropped as
		   soon as the server answers), so the server can tell «the same
		   request sent twice» from «a second reprint» and never counts one
		   press twice. */
		if (!PD.s.pendingPrintRequest || PD.s.pendingPrintRequestToken !== token) {
			PD.s.pendingPrintRequest = PD.createPrintToken();
			PD.s.pendingPrintRequestToken = token;
		}
		var requestId = PD.s.pendingPrintRequest;
		/* Restores the token if an edit dropped it while the
		   request was in flight and the server may have charged it. */
		var countAtSend = PD.s.pendingPrintCountBefore;
		function keepMaybeCharged() {
			/* A token that already produced a sheet paid for that sheet
			   and is never carried to another plan. */
			if (PD.s.printTokenSheetOpened) {
				return;
			}
			if (PD.s.pendingPrintToken && PD.s.pendingPrintToken !== token) {
				return; /* A newer print owns the pending token. */
			}
			if (!PD.s.pendingPrintToken) {
				PD.s.pendingPrintToken = token;
				PD.s.pendingPrintCountBefore = countAtSend;
			}
			PD.s.printTokenNoSheet = true;
		}

		PD.s.printCreditLocked = true;
		PD.ajaxPost('plandose_register_print', {
			token: token,
			request_id: requestId
		}).then(function (json) {
			PD.s.printCreditLocked = false;
			/* Answered: the next press is a new request. */
			PD.s.pendingPrintRequest = null;
			PD.s.pendingPrintRequestToken = null;

			/* check_ajax_referer() answers an expired nonce with a bare "-1"
			   (or "0" when the action is unknown to a logged-out session) —
			   valid JSON, but not an object. That is an expired session, and
			   saying «Κάτι πήγε στραβά» would send the pharmacist nowhere.
			   Nothing was recorded, so the token stays pending.
			   printTokenNoSheet is left as it was — the request never reached
			   the handler, so the token is exactly as charged as before. */
			if (!PD.isValidAjaxResponse(json)) {
				PD.setMessage(PD.txt('sessionExpired', 'Η σελίδα ήταν ανοιχτή πολλή ώρα και η σύνδεσή σας έληξε. Κάντε ανανέωση της σελίδας (F5) και δοκιμάστε ξανά.'), 'error');
				if (callback) {
					callback(false);
				}
				return;
			}

			var data = json.data ? json.data : null;

			/* Even a server-reported failure (limit_reached,
				 server_error) can carry a fresh print status snapshot
				 — see Plandose_Ajax::print_status_payload() — so the
				 on-screen counter doesn't stay stale just because
				 this particular attempt didn't succeed. */
			if (data && typeof data.print_count === 'number') {
				PD.s.printStatus = data;
				PD.renderPrintCounter();
			}
			if (json && json.success) {
				/* The server already charged this token (a retry
				   after a lost answer, or a free reprint). */
				if (data && data.already_recorded) {
					PD.s.lastPrintToken = token;
					if (callback) {
						callback(true, {
							alreadyRecorded: true,
							freeReprintsLeft: typeof data.free_reprints_left === 'number' ? data.free_reprints_left : null,
							requestId: requestId
						});
					}
					return;
				}

				var unrecordedDuplicate = data &&
					data.duplicate_ignored &&
					countBefore !== null &&
					typeof data.print_count === 'number' &&
					data.print_count <= countBefore;

				if (unrecordedDuplicate) {
					PD.s.printTokenNoSheet = false; /* Definitely not charged. */
					PD.setMessage(
						PD.txt('printNotCounted', 'Η εκτύπωση δεν καταγράφηκε. Δοκιμάστε ξανά.'),
						'error'
					);
					if (callback) {
						callback(false);
					}
					return;
				}

				PD.s.lastPrintToken = token;
				if (callback) {
					callback(true, {
						alreadyRecorded: false,
						freeReprintsLeft: data && typeof data.free_reprints_left === 'number' ? data.free_reprints_left : null,
						requestId: requestId
					});
				}
				return;
			}
			/* The server answered with a refusal: THIS request did not
			   charge the token. Only a definite refusal (limit
			   reached, invalid token or request) also says the token was
			   never charged. A server_error does not — an earlier attempt
			   whose answer was lost may well have charged it (the server
			   also answers server_error when it cannot see that attempt's
			   outcome), so «charged, no sheet» is kept and the token is
			   reused, not thrown away with an edit and charged again. */
			if (!data || data.limit_reached || data.invalid_token || data.invalid_request) {
				PD.s.printTokenNoSheet = false;
			}
			/* This press was already replayed as often as the server
			   allows (HTTP 409): nothing was charged or printed now. The
			   request id was dropped above (answered); the TOKEN is kept, so
			   the next press sends a new request id on the same token and
			   the server decides: a free reprint if any are left, otherwise
			   a new charge. */
			if (data && data.replay_limit) {
				PD.setMessage(PD.txt('printReplayLimitKeep', 'Αυτή η προσπάθεια εκτύπωσης έχει ήδη επαναληφθεί όσες φορές επιτρέπεται. Τώρα δεν χρεώθηκε και δεν τυπώθηκε τίποτα. Αν πατήσετε ξανά «Εκτύπωση», θα γίνει δωρεάν επανεκτύπωση αν απομένουν (δείτε τη γραμμή χρέωσης) — αλλιώς θα μετρήσει ως νέα εκτύπωση.'), 'error');
				if (callback) {
					callback(false, { replayLimit: true });
				}
				return;
			}
			if (data && data.message) {
				PD.setMessage(data.message, 'error');
			} else {
				PD.setMessage(PD.txt('genericError', 'Κάτι πήγε στραβά.'), 'error');
			}
			if (callback) {
				callback(false);
			}
		}).catch(function () {
			PD.s.printCreditLocked = false;
			/*
			 * Timeout or lost connection — the server may well have recorded
			 * this print already, so the message must not say «δεν
			 * καταγράφηκε», and the token is kept (printTokenNoSheet) and
			 * reused by the next print, edited or not: otherwise the next
			 * print would be charged again although the first one produced
			 * no sheet.
			 */
			/* Also when an edit dropped the token while the request
			   was in flight — it may be charged, so it must be reused. The
			   next print still builds the sheet from the current plan. */
			keepMaybeCharged();
			var until = Date.now() + (typeof PD.reprintWindowMinutes === 'function' ? PD.reprintWindowMinutes() : 30) * 60000;
			PD.setMessage(PD.format(PD.txt('printNotRecordedUntil', 'Πρόβλημα σύνδεσης. Δεν είναι βέβαιο αν η εκτύπωση χρεώθηκε. Πατήστε ξανά «Εκτύπωση» έως τις %1$s — αν είχε χρεωθεί, δεν θα χρεωθεί δεύτερη φορά (έως %2$d επαναλήψεις), ακόμη κι αν διορθώσετε πρώτα το πλάνο.'), typeof PD.clockTime === 'function' ? PD.clockTime(until) : '', PD.maxFreeReprints()), 'error');
			if (callback) {
				callback(false);
			}
		});
	};

	/* every 10 minutes */

	/**
	 * Silently reissue the AJAX nonce so a long-open popup doesn't run into
	 * the "-1" expired-nonce response later when the pharmacist actually
	 * tries to print. Failures here are not shown to the person — if this
	 * doesn't work for some reason, the normal print flow still has its own
	 * clear "session expired, please refresh" fallback message.
	 *
	 * Returns a Promise that ALWAYS resolves (true when a fresh
	 * nonce arrived), and keeps it on PD.s.nonceRefreshPromise while it is
	 * in flight, so callers can wait for it — see whenNonceFresh().
	 *
	 * @return {Promise<boolean>}
	 */
	PD.refreshNonce = function refreshNonce() {
		var promise = PD.ajaxPost('plandose_refresh_nonce', null, false).then(function (json) {
			if (PD.isValidAjaxResponse(json) && json.success && json.data && json.data.nonce) {
				PD.config.nonce = String(json.data.nonce);
				return true;
			}
			return false;
		}).catch(function () {
			/* ignore — next periodic attempt, or the print flow's own
				 session-expired message, will handle it */
			return false;
		}).then(function (ok) {
			if (PD.s.nonceRefreshPromise === promise) {
				PD.s.nonceRefreshPromise = null;
			}
			return ok;
		});
		PD.s.nonceRefreshPromise = promise;
		return promise;
	};

	/**
	 * Run `callback` once no nonce refresh is in flight. Requests fired in
	 * parallel with refreshNonce() would carry the nonce the refresh is
	 * about to replace — on a page open for many hours they would come
	 * back "-1". If the refresh fails, the callback still runs with the nonce
	 * there is (graceful fallback: the request's own error handling
	 * reports an expired session).
	 */
	PD.whenNonceFresh = function whenNonceFresh(callback) {
		if (PD.s.nonceRefreshPromise) {
			PD.s.nonceRefreshPromise.then(function () {
				callback();
			});
			return;
		}
		callback();
	};

	PD.startNonceRefresh = function startNonceRefresh() {
		PD.stopNonceRefresh();
		PD.s.nonceRefreshInterval = setInterval(PD.refreshNonce, PD.NONCE_REFRESH_MS);
	};

	PD.stopNonceRefresh = function stopNonceRefresh() {
		if (PD.s.nonceRefreshInterval) {
			clearInterval(PD.s.nonceRefreshInterval);
			PD.s.nonceRefreshInterval = null;
		}
	};
})();