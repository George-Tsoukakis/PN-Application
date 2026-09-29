/**
 * PlanDose — print.js
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

	PD.unlockPrintUi = function unlockPrintUi() {
		var printBtn = document.getElementById('pd-print');
		PD.s.isPrinting = false;
		PD.s.printCreditLocked = false;
		PD.setBusy(printBtn, false);
		PD.syncPrintLock();
	};

	/**
	 * True while the plan on screen is one whose print is already
	 * recorded — pressing Print again re-prints it free of charge. Any edit
	 * to the plan clears the pending token, which ends this state.
	 *
	 * This only decides what the browser OFFERS (reuse the token,
	 * say «χωρίς νέα χρέωση»). Whether the reprint is actually free is
	 * decided by the server from its print receipts — see
	 * consumePrintCreditOnce().
	 */
	PD.isReprintFree = function isReprintFree() {
		if (!PD.s.pendingPrintToken || PD.s.pendingPrintToken !== PD.s.lastPrintToken) {
			return false;
		}
		/* A token carried over to an edited plan (or the next
		   patient) after its print never opened. The plan on screen has not
		   been printed, so it must not look like one («Εκτύπωση ξανά», «Νέος
		   ασθενής»); handlePrint() still reuses the token (carriedOver). */
		if (null === PD.s.confirmedPrintHtml && PD.s.printTokenNoSheet) {
			return false;
		}
		/* Same token but a different sheet (language switched, or the
		   day changed so every date moved): not the print that was paid for. */
		if (null !== PD.s.confirmedPrintHtml) {
			try {
				return PD.buildCheckedPrintHtml() === PD.s.confirmedPrintHtml;
			} catch (e) {
				return false;
			}
		}
		return true;
	};

	/**
	 * How long a printed plan may sit untouched on screen before it is
	 * cleared on its own. Protects the patient's data on a pharmacy PC
	 * shared by several people, since the plan is not wiped the moment
	 * the print dialog closes.
	 */
	PD.PRINTED_IDLE_MS = 10 * 60 * 1000;

	/**
	 * How long before an idle wipe the pharmacist is warned on screen, with
	 * a «Κράτα το» button that restarts the countdown. Only a warning: a
	 * plan nobody comes back to is still cleared at PRINTED_IDLE_MS.
	 */
	PD.IDLE_WARNING_MS = 60 * 1000;

	/* True while activity must restart the idle timers but leave the
	   warning up (focus moving towards «Κράτα το») — see the listeners
	   below. */
	var holdIdleWarning = false;

	/* The element that had focus before «Κράτα το», to give it back when
	   the warning goes away under the keyboard user. */
	var idleWarningReturnFocus = null;

	/* Delay of the warning timer; -1 when the idle time is too short to
	   warn ahead of it. */
	function idleWarningDelay() {
		var delay = PD.PRINTED_IDLE_MS - PD.IDLE_WARNING_MS;
		return PD.IDLE_WARNING_MS > 0 && delay > 0 ? delay : -1;
	}

	/**
	 * Arm the warning of one idle timer. willWipe() is asked when it
	 * fires: the warning shows only when that timer would clear a plan.
	 *
	 * @param {function(): boolean} willWipe
	 * @return {?number} Timer id, or null.
	 */
	function armIdleWarning(willWipe) {
		var delay = idleWarningDelay();
		if (delay < 0) {
			return null;
		}
		/* Created empty ahead of time: a live region that already exists
		   is announced far more reliably than one inserted with its text. */
		idleWarningBox(true);
		return setTimeout(function () {
			if (PD.overlay && !PD.overlay.hidden && willWipe()) {
				PD.showIdleWarning();
			}
		}, delay);
	}

	/** The warning banner at the top of the action bar (created on demand). */
	function idleWarningBox(create) {
		var box = document.getElementById('pd-idle-warning');
		if (box || !create) {
			return box;
		}
		var bar = PD.app ? PD.app.querySelector('.plandose-actionbar') : null;
		if (!bar) {
			return null;
		}
		box = document.createElement('div');
		box.id = 'pd-idle-warning';
		box.className = 'pd-idle-warning';
		/* Assertive, but the text only: the button is not read out as part
		   of the alert, and focus is never moved to it. */
		var text = document.createElement('p');
		text.id = 'pd-idle-warning-text';
		text.className = 'pd-idle-warning-text';
		text.setAttribute('role', 'alert');
		text.setAttribute('aria-live', 'assertive');
		text.setAttribute('aria-atomic', 'true');
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.id = 'pd-idle-keep';
		btn.className = 'plandose-btn plandose-btn-secondary pd-idle-keep';
		btn.hidden = true;
		btn.setAttribute('aria-describedby', text.id);
		btn.textContent = PD.txt('idleWarningKeep', 'Κράτα το');
		btn.addEventListener('focus', function (e) {
			if (e.relatedTarget && !box.contains(e.relatedTarget)) {
				idleWarningReturnFocus = e.relatedTarget;
			}
		});
		btn.addEventListener('click', function () {
			PD.keepPlanOnScreen();
		});
		box.appendChild(text);
		box.appendChild(btn);
		bar.insertBefore(box, bar.firstChild);
		return box;
	}

	PD.isIdleWarningShown = function isIdleWarningShown() {
		var box = idleWarningBox(false);
		return !!box && box.classList.contains('is-active');
	};

	/** «Το πλάνο θα καθαριστεί σε 1 λεπτό…» with its «Κράτα το» button. */
	PD.showIdleWarning = function showIdleWarning() {
		var box = idleWarningBox(true);
		if (!box) {
			return;
		}
		var minutes = Math.max(1, Math.round(PD.IDLE_WARNING_MS / 60000));
		var text = box.querySelector('.pd-idle-warning-text');
		var btn = box.querySelector('.pd-idle-keep');
		btn.textContent = PD.txt('idleWarningKeep', 'Κράτα το');
		/* Emptied first so a repeated warning is announced again. */
		text.textContent = '';
		text.textContent = 1 === minutes
			? PD.txt('idleWarningOne', 'Το πλάνο θα καθαριστεί σε 1 λεπτό λόγω αδράνειας. Πατήστε «Κράτα το» ή συνεχίστε την εργασία σας για να μείνει στην οθόνη.')
			: PD.format(PD.txt('idleWarningMany', 'Το πλάνο θα καθαριστεί σε %d λεπτά λόγω αδράνειας. Πατήστε «Κράτα το» ή συνεχίστε την εργασία σας για να μείνει στην οθόνη.'), minutes);
		btn.hidden = false;
		box.classList.add('is-active');
	};

	PD.hideIdleWarning = function hideIdleWarning() {
		var box = idleWarningBox(false);
		if (!box || !box.classList.contains('is-active')) {
			return;
		}
		var btn = box.querySelector('.pd-idle-keep');
		var hadFocus = !!btn && document.activeElement === btn;
		box.classList.remove('is-active');
		box.querySelector('.pd-idle-warning-text').textContent = '';
		if (btn) {
			btn.hidden = true;
		}
		/* A hidden button drops focus to <body>; hand it back instead. */
		if (hadFocus) {
			var back = idleWarningReturnFocus;
			if (!back || !document.contains(back) || back.disabled || !back.getClientRects().length) {
				back = document.getElementById('pd-print') || PD.closeBtn;
			}
			if (back && typeof back.focus === 'function') {
				back.focus();
			}
		}
		idleWarningReturnFocus = null;
	};

	/** «Κράτα το»: the same as any activity — both countdowns restart. */
	PD.keepPlanOnScreen = function keepPlanOnScreen() {
		if (PD.s.printedIdleTimer) {
			PD.armPrintedIdleTimer();
		}
		PD.armDraftIdleTimer();
		PD.hideIdleWarning();
	};

	/* Token-level, not isReprintFree(): a printed plan that has since
	   crossed midnight is no longer a free reprint but must still be
	   cleared. Only an edit (which clears the token) keeps it. */
	function printedPlanUnchanged() {
		return !!PD.s.pendingPrintToken && PD.s.pendingPrintToken === PD.s.lastPrintToken &&
			/* Renaming the patient does not clear the token (see app.js),
			   so it has to count as an edit here. */
			(PD.s.confirmedPatient === null || !PD.getPatientName || PD.getPatientName() === PD.s.confirmedPatient);
	}

	PD.clearPrintedIdleTimer = function clearPrintedIdleTimer() {
		if (PD.s.printedIdleTimer) {
			clearTimeout(PD.s.printedIdleTimer);
			PD.s.printedIdleTimer = null;
		}
		if (PD.s.printedWarnTimer) {
			clearTimeout(PD.s.printedWarnTimer);
			PD.s.printedWarnTimer = null;
		}
		if (!holdIdleWarning) {
			PD.hideIdleWarning();
		}
	};

	PD.armPrintedIdleTimer = function armPrintedIdleTimer() {
		PD.clearPrintedIdleTimer();
		PD.s.printedIdleTimer = setTimeout(function () {
			PD.s.printedIdleTimer = null;
			if (!PD.overlay || PD.overlay.hidden || !printedPlanUnchanged()) {
				return;
			}
			if (PD.s.isPrinting || PD.s.isPrintingLabels || PD.s.isCheckingPrint || PD.s.isPreparingPrint) {
				PD.armPrintedIdleTimer();
				return;
			}
			PD.resetForNextPatient('idle');
		}, PD.PRINTED_IDLE_MS);
		PD.s.printedWarnTimer = armIdleWarning(function () {
			PD.s.printedWarnTimer = null;
			return !!PD.s.printedIdleTimer && printedPlanUnchanged();
		});
	};

	/**
	 * Privacy (Pro): the previous patient's medicines and name, kept
	 * after «Νέος ασθενής» only so their labels can still be printed, are
	 * forgotten after PRINTED_IDLE_MS, so that on a shared PC «Εκτύπωση
	 * Ετικετών» cannot print the previous patient's labels hours later.
	 * An open label print window is closed at the same moment. Fixed
	 * countdown from «Νέος ασθενής»; editing the new plan or closing the
	 * popup clears them earlier (invalidatePendingPrintToken() /
	 * resetPlanState()).
	 */
	PD.armLastPrintedTimer = function armLastPrintedTimer() {
		if (PD.s.lastPrintedTimer) {
			clearTimeout(PD.s.lastPrintedTimer);
		}
		var kept = PD.s.lastPrintedItems;
		PD.s.lastPrintedTimer = setTimeout(function () {
			PD.s.lastPrintedTimer = null;
			if (PD.s.lastPrintedItems !== kept) {
				return; /* Already replaced or cleared. */
			}
			if (PD.s.isPrintingLabels) {
				PD.armLastPrintedTimer(); /* Never pull data from under a label print. */
				return;
			}
			PD.s.lastPrintedItems = null;
			PD.s.lastPrintedPatient = '';
			PD.s.lastPrintedPlan = null;
			PD.s.labelNotesAck = '';
			/* Nor may they linger in a label print window left
			   open — it holds the same name and medicines. */
			if (typeof PD.closeLabelWindow === 'function') {
				PD.closeLabelWindow();
			}
			if (typeof PD.updateStepButtons === 'function') {
				PD.updateStepButtons();
			}
		}, PD.PRINTED_IDLE_MS);
	};

	/**
	 * Privacy: a plan that was typed but never printed — or was printed
	 * and then changed — must not stay on screen with no time limit while
	 * the popup is open. On a pharmacy PC shared by several people the
	 * patient's name and medicines are cleared after the same idle time as
	 * a printed plan. Any activity inside the popup restarts it.
	 */
	PD.clearDraftIdleTimer = function clearDraftIdleTimer() {
		if (PD.s.draftIdleTimer) {
			clearTimeout(PD.s.draftIdleTimer);
			PD.s.draftIdleTimer = null;
		}
		if (PD.s.draftWarnTimer) {
			clearTimeout(PD.s.draftWarnTimer);
			PD.s.draftWarnTimer = null;
		}
		if (!holdIdleWarning) {
			PD.hideIdleWarning();
		}
	};

	/** Whether anything about a patient is on screen. */
	PD.planHasPatientData = function planHasPatientData() {
		if (PD.s.items && PD.s.items.length) {
			return true;
		}
		if (PD.s.rx && 'review' === PD.s.rx.stage) {
			return true;
		}
		var name = PD.getPatientName ? String(PD.getPatientName() || '') : '';
		if ('' !== name.trim()) {
			return true;
		}
		/* A medicine being typed into the form. */
		return ['pd-drug', 'pd-notes'].some(function (id) {
			var el = document.getElementById(id);
			return !!el && '' !== String(el.value || '').trim();
		});
	};

	PD.armDraftIdleTimer = function armDraftIdleTimer() {
		PD.clearDraftIdleTimer();
		if (!PD.overlay || PD.overlay.hidden) {
			return;
		}
		PD.s.draftIdleTimer = setTimeout(function () {
			PD.s.draftIdleTimer = null;
			if (!PD.overlay || PD.overlay.hidden) {
				return;
			}
			/* A printed plan left untouched is armPrintedIdleTimer()'s. */
			if (PD.s.printedIdleTimer) {
				return;
			}
			if (PD.s.isPrinting || PD.s.isPrintingLabels || PD.s.isCheckingPrint || PD.s.isPreparingPrint) {
				PD.armDraftIdleTimer();
				return;
			}
			if (!PD.planHasPatientData()) {
				return;
			}
			PD.resetForNextPatient('idle');
		}, PD.PRINTED_IDLE_MS);
		/* The same checks as above, a minute early (a printed plan left
		   untouched is warned about by armPrintedIdleTimer()). */
		PD.s.draftWarnTimer = armIdleWarning(function () {
			PD.s.draftWarnTimer = null;
			return !!PD.s.draftIdleTimer && !PD.s.printedIdleTimer && PD.planHasPatientData();
		});
	};

	/* Any activity inside the popup restarts the idle countdown and
	   dismisses the idle warning — except moving focus (Tab, Shift+Tab and
	   the modifier keys alone), which restarts the countdown but leaves the
	   warning up, or a keyboard user could never reach «Κράτα το»; and
	   activity on the warning itself, whose button dismisses it. */
	var FOCUS_KEYS = ['Tab', 'Shift', 'Control', 'Alt', 'AltGraph', 'Meta'];
	if (PD.overlay && !PD.s.printedIdleBound) {
		PD.s.printedIdleBound = true;
		['pointerdown', 'keydown', 'input'].forEach(function (type) {
			PD.overlay.addEventListener(type, function (e) {
				var box = PD.isIdleWarningShown() ? idleWarningBox(false) : null;
				holdIdleWarning = !!box && !!e && (box.contains(e.target) ||
					('keydown' === e.type && FOCUS_KEYS.indexOf(e.key) !== -1));
				try {
					if (PD.s.printedIdleTimer) {
						PD.armPrintedIdleTimer();
					}
					PD.armDraftIdleTimer();
				} finally {
					holdIdleWarning = false;
				}
			}, true);
		});
	}

	/**
	 * After the print dialog the plan STAYS on screen. Browsers
	 * cannot tell "Print" from "Cancel", so the print stays charged — but
	 * a pharmacist who cancelled or picked the wrong printer simply
	 * presses «Εκτύπωση ξανά» (free, same token) instead of typing the
	 * whole plan again. «Νέος ασθενής» clears it.
	 */
	/** Free reprints per recorded plan, as configured on the server. */
	PD.maxFreeReprints = function maxFreeReprints() {
		var n = parseInt(PD.config.maxFreeReprints, 10);
		/* The server's MAX_FREE_REPRINTS is 2. */
		return isNaN(n) || n < 0 ? 2 : n;
	};

	/**
	 * Minutes a recorded print can be re-printed free of charge
	 * (the server's PRINT_RECEIPT_TTL). From PlandoseConfig when the
	 * server sends it, otherwise 30.
	 */
	PD.reprintWindowMinutes = function reprintWindowMinutes() {
		var n = parseInt(PD.config.reprintWindowMinutes, 10);
		return n > 0 ? n : 30;
	};

	/** Local time as HH:MM. */
	PD.clockTime = function clockTime(ms) {
		var d = new Date(ms);
		var pad = function (n) {
			return (n < 10 ? '0' : '') + n;
		};
		return pad(d.getHours()) + ':' + pad(d.getMinutes());
	};

	/**
	 * What the pharmacist was charged, kept for the billing status
	 * line: the token, when it was recorded (local clock) and the free
	 * reprints the server said are left. No patient data.
	 *
	 * @param {string}  token        Print token.
	 * @param {?Object} info         consumePrintCreditOnce() info.
	 */
	PD.recordBilling = function recordBilling(token, info) {
		var now = Date.now();
		var b = PD.s.billing;
		if (info && info.alreadyRecorded) {
			if (!b || b.token !== token) {
				/* Recorded by an earlier attempt whose answer was lost: its
				   exact time is not known here, now is the closest. */
				b = { token: token, at: now, freeLeft: null };
			}
			if (typeof info.freeReprintsLeft === 'number') {
				b.freeLeft = info.freeReprintsLeft;
			}
		} else {
			/* The server's count when it sends one, else its configured maximum. */
			b = { token: token, at: now, freeLeft: (info && typeof info.freeReprintsLeft === 'number') ? info.freeReprintsLeft : PD.maxFreeReprints() };
		}
		PD.s.billing = b;
		return b;
	};

	/** Until when (ms) the recorded print may be re-printed for free. */
	PD.billingUntil = function billingUntil() {
		var b = PD.s.billing;
		return b ? b.at + PD.reprintWindowMinutes() * 60000 : 0;
	};

	/**
	 * The plain billing status line shown after a charge. States
	 * when the print was recorded, how many free reprints are left and
	 * until when — and that the browser cannot know whether the printer
	 * actually printed.
	 *
	 * @return {string} '' when there is nothing to show.
	 */
	PD.billingStatusText = function billingStatusText() {
		var b = PD.s.billing;
		if (!b) {
			return '';
		}
		var parts = [PD.format(PD.txt('billingRecordedAt', 'Η εκτύπωση χρεώθηκε στις %s.'), PD.clockTime(b.at))];
		var until = PD.billingUntil();
		var reusable = b.token === PD.s.pendingPrintToken && Date.now() < until;
		if (reusable && typeof b.freeLeft === 'number' && b.freeLeft > 0) {
			parts.push(PD.format(PD.txt('billingFreeLeft', 'Δωρεάν επανεκτυπώσεις: %1$d, έως τις %2$s.'), b.freeLeft, PD.clockTime(until)));
		} else if (reusable && 0 === b.freeLeft) {
			parts.push(PD.txt('billingNoFreeLeft', 'Δεν απομένουν δωρεάν επανεκτυπώσεις — η επόμενη εκτύπωση χρεώνεται.'));
		} else {
			parts.push(PD.txt('billingNextCharged', 'Η επόμενη εκτύπωση χρεώνεται ως νέα.'));
		}
		parts.push(PD.txt('billingCannotKnow', 'Ο browser δεν μπορεί να γνωρίζει αν ο εκτυπωτής ολοκλήρωσε την εκτύπωση — ελέγξτε το χαρτί.'));
		return parts.join(' ');
	};

	/**
	 * Show the billing status line under the message area. It is
	 * its own element, so later messages do not wipe it.
	 */
	PD.renderBillingStatus = function renderBillingStatus() {
		var el = document.getElementById('pd-billing-status');
		var text = PD.billingStatusText();
		if (!el) {
			var msg = document.getElementById('pd-message');
			if (!msg || !msg.parentNode || !text) {
				return;
			}
			el = document.createElement('p');
			el.id = 'pd-billing-status';
			el.className = 'pd-billing-status';
			el.setAttribute('role', 'status');
			el.setAttribute('aria-live', 'polite');
			msg.parentNode.insertBefore(el, msg.nextSibling);
		}
		el.textContent = text;
		el.hidden = !text;
	};

	/**
	 * The step-1 editing panel (which holds the prescription
	 * import too) is inert and aria-busy while any print or label flow
	 * runs, so the plan cannot change between the final check and the
	 * sheet. Only what this function set is undone.
	 */
	PD.syncPrintLock = function syncPrintLock() {
		var busy = typeof PD.isPrintBusy === 'function' ? PD.isPrintBusy() : !!(PD.s.isPrinting || PD.s.isPreparingPrint || PD.s.isCheckingPrint || PD.s.printCreditLocked);
		var locked = PD.s.printLocked || [];
		PD.s.printLocked = [];
		locked.forEach(function (rec) {
			if (rec.inert) {
				rec.el.inert = false;
				rec.el.removeAttribute('inert');
			}
			if (rec.busy) {
				rec.el.removeAttribute('aria-busy');
			}
		});
		if (!busy) {
			var note = document.getElementById('pd-close-blocked');
			if (note) {
				note.textContent = '';
			}
			return;
		}
		var targets = [];
		document.querySelectorAll('[data-pd-step="1"], #pd-rx').forEach(function (el) {
			/* #pd-rx sits inside step 1; locking step 1 covers it. */
			for (var i = 0; i < targets.length; i++) {
				if (targets[i].contains(el)) {
					return;
				}
			}
			targets.push(el);
		});
		targets.forEach(function (el) {
			var rec = { el: el, inert: false, busy: false };
			if (!el.hasAttribute('inert')) {
				el.setAttribute('inert', '');
				rec.inert = true;
			}
			if (!el.hasAttribute('aria-busy')) {
				el.setAttribute('aria-busy', 'true');
				rec.busy = true;
			}
			PD.s.printLocked.push(rec);
		});
	};

	/**
	 * The sheet, built only from a usable start date. An invalid
	 * or incomplete date must never quietly turn into «today».
	 */
	PD.buildCheckedPrintHtml = function buildCheckedPrintHtml() {
		if (typeof PD.startDateProblem === 'function' && PD.startDateProblem(PD.s.startDate)) {
			throw new Error('invalid_start_date');
		}
		return PD.buildPrintHtml();
	};

	/**
	 * The notes as printed in a DAY CARD. The medicine list at the top of
	 * the sheet always has them in full; a card repeats them in every
	 * slot of every day, so a long note there would make a card taller
	 * than an A4 page. Up to DAY_CARD_NOTES_MAX characters print whole;
	 * longer ones are cut at a word boundary and point to the list.
	 */
	PD.DAY_CARD_NOTES_MAX = 40;

	PD.dayCardNotesText = function dayCardNotesText(notes) {
		var text = String(notes == null ? '' : notes).replace(/\s+/g, ' ').trim();
		var chars = Array.from(text);
		if (chars.length <= PD.DAY_CARD_NOTES_MAX) {
			return text;
		}
		var kept = chars.slice(0, PD.DAY_CARD_NOTES_MAX);
		var sp = kept.lastIndexOf(' ');
		if (sp >= Math.floor(kept.length * 0.5)) {
			kept = kept.slice(0, sp);
		}
		return kept.join('').replace(/[\s,;:.·\-–—]+$/, '') + '… ' +
			PD.txt('dayCardNotesSeeList', '(βλ. λίστα φαρμάκων)');
	};

	/**
	 * How a day card is laid out so that it never runs past one
	 * A4 page (printable height ≈ 1031 CSS px, see @page in PRINT_STYLES):
	 *
	 *   - the normal half-width card when it fits;
	 *   - otherwise a full-width «dense» card (.pd-day-card-dense), one
	 *     line per dose with the notes after the name;
	 *   - otherwise the day is split into several full-width cards, each
	 *     titled «… (συνέχεια)» after the first — a visible, controlled
	 *     split, never one card broken across pages.
	 *
	 * Heights are ESTIMATED from the text length (the sheet is built as a
	 * string, without layout), on the safe side: characters per line are
	 * set below what Arial really fits.
	 *
	 * @param {Array} groups dayDoses() groups ({label, entries[]}).
	 * @return {{dense: boolean, parts: Array<Array>}}
	 */
	PD.DAY_CARD_MAX_PX = 900;

	function charCount(str) {
		return Array.from(String(str == null ? '' : str)).length;
	}

	function doseHeight(entry, dense) {
		var name = charCount(entry.item && entry.item.name);
		var notes = entry.item && entry.item.notes ? charCount(PD.dayCardNotesText(entry.item.notes)) : 0;
		var lines = dense
			? Math.max(1, Math.ceil((name + (notes ? notes + 2 : 0)) / 60))
			: Math.max(1, Math.ceil(name / 24)) + (notes ? Math.ceil(notes / 30) : 0);
		return lines * 13.2 + 7;
	}

	var CARD_FRAME_PX = 46; /* header, body padding, borders */
	var SLOT_PX = 10;       /* slot padding and rule */

	PD.estimateDayCardHeight = function estimateDayCardHeight(groups, dense) {
		var h = CARD_FRAME_PX;
		(groups || []).forEach(function (group) {
			var slot = 0;
			(group.entries || []).forEach(function (entry) {
				slot += doseHeight(entry, dense);
			});
			h += SLOT_PX + Math.max(slot, 16);
		});
		return h;
	};

	PD.dayCardLayout = function dayCardLayout(groups) {
		groups = groups || [];
		if (PD.estimateDayCardHeight(groups, false) <= PD.DAY_CARD_MAX_PX) {
			return { dense: false, parts: [groups] };
		}
		if (PD.estimateDayCardHeight(groups, true) <= PD.DAY_CARD_MAX_PX) {
			return { dense: true, parts: [groups] };
		}
		var parts = [];
		var cur = [];
		var curH = CARD_FRAME_PX;
		groups.forEach(function (group) {
			var sub = null;
			var subH = 0;
			var close = function () {
				if (sub) {
					cur.push(sub);
					curH += SLOT_PX + Math.max(subH, 16);
				}
				sub = null;
				subH = 0;
			};
			(group.entries || []).forEach(function (entry) {
				var eh = doseHeight(entry, true);
				if ((cur.length || sub) && curH + (sub ? SLOT_PX + subH : 0) + (sub ? 0 : SLOT_PX) + eh > PD.DAY_CARD_MAX_PX) {
					close();
					parts.push(cur);
					cur = [];
					curH = CARD_FRAME_PX;
				}
				if (!sub) {
					sub = {};
					Object.keys(group).forEach(function (key) {
						sub[key] = group[key];
					});
					sub.entries = [];
				}
				sub.entries.push(entry);
				subH += eh;
			});
			close();
		});
		if (cur.length) {
			parts.push(cur);
		}
		return { dense: true, parts: parts };
	};

	/** Class of a day card (or of one part of a split day). */
	PD.dayCardClass = function dayCardClass(groups, part) {
		var dense = part > 0 || PD.dayCardLayout(groups).dense;
		return dense ? 'pd-day-card pd-day-card-dense' : 'pd-day-card';
	};

	PD.showPrintedState = function showPrintedState(chargedAgain, info, uncertain) {
		PD.updateStepButtons();
		PD.renderBillingStatus();
		/* Edited while the print was being recorded: the old sheet went
		   to the printer, but what is on screen now is a new plan. */
		if (!PD.isReprintFree()) {
			PD.setMessage(PD.txt('printedPlanChanged', 'Το πλάνο εκτυπώθηκε. Αλλάξατε το πλάνο στο μεταξύ, οπότε η επόμενη εκτύπωση θα μετρήσει ως νέα.'), 'success');
			return;
		}
		var idleMin = Math.round(PD.PRINTED_IDLE_MS / 60000);
		var until = PD.clockTime(PD.billingUntil());
		if (uncertain === true) {
			/* The hidden-frame fallback cannot tell whether print()
			   opened a dialog at all. */
			PD.setMessage(PD.format(PD.txt('printedMaybeNoDialog', 'Η χρέωση καταγράφηκε· αν δεν άνοιξε ο διάλογος εκτύπωσης, πατήστε «Εκτύπωση ξανά» — δωρεάν έως τις %1$s (έως %2$d φορές), όσο το πλάνο μένει στην οθόνη.'), until, PD.maxFreeReprints()), 'success');
		} else if (chargedAgain === true) {
			/* A reprint the server charged as a new print (its free
			   reprints used up, or its receipt older than PRINT_RECEIPT_TTL). */
			PD.setMessage(PD.format(PD.txt('printedReprintChargedWindow', 'Η εκτύπωση χρεώθηκε ως νέα: οι δωρεάν επανεκτυπώσεις αυτού του πλάνου εξαντλήθηκαν ή πέρασαν %d λεπτά από την πρώτη εκτύπωση.'), PD.reprintWindowMinutes()), 'success');
		} else if (info && info.alreadyRecorded && typeof info.freeReprintsLeft === 'number') {
			/* A free reprint — how many are left, as the server
			   counted them. */
			PD.setMessage(PD.format(PD.txt('reprintedFree', 'Τυπώθηκε ξανά χωρίς νέα χρέωση. Δωρεάν επανεκτυπώσεις που απομένουν για αυτό το πλάνο: %d.'), info.freeReprintsLeft), 'success');
		} else {
			PD.setMessage(PD.format(PD.txt('printedKeepPlanWindow', 'Η εκτύπωση καταγράφηκε. Αν δεν τυπώθηκε σωστά (π.χ. πατήσατε Ακύρωση ή διαλέξατε λάθος εκτυπωτή), πατήστε «Εκτύπωση ξανά» — χωρίς νέα χρέωση, έως %1$d φορές, μέχρι τις %2$s. Χωρίς δραστηριότητα για %3$d λεπτά το πλάνο καθαρίζεται αυτόματα. Για τον επόμενο ασθενή πατήστε «Νέος ασθενής».'), PD.maxFreeReprints(), until, idleMin), 'success');
		}
		PD.armPrintedIdleTimer();
		var newBtn = document.getElementById('pd-new-patient');
		if (newBtn && !newBtn.hidden) {
			newBtn.focus();
		}
	};

	/**
	 * Clear the printed patient's plan and rebuild the form at step 1 for
	 * the next patient.
	 *
	 * @param {string} reason 'manual' («Νέος ασθενής») or 'idle' (the
	 *                        printed plan was left untouched too long).
	 */
	PD.resetForNextPatient = function resetForNextPatient(reason) {
		if (!PD.overlay || PD.overlay.hidden) {
			return;
		}
		var idle = 'idle' === reason;
		PD.clearPrintedIdleTimer();
		PD.clearDraftIdleTimer();
		/* Nothing of this patient may linger in a print window or frame. */
		if (PD.s.activePrintWindow) {
			PD.closePrintWindow(PD.s.activePrintWindow);
		}
		/* Nor in the Pro label window. */
		if (typeof PD.closeLabelWindow === 'function') {
			PD.closeLabelWindow();
		}
		PD.clearPrintFrame();
		/* Pro: remember what was just printed so its labels can still be
		   sent to the label printer after the form is cleared. Memory only,
		   and not kept when the plan is cleared for inactivity. */
		var printedItems = (PD.PRO_LABELS && !idle) ? PD.s.items.slice() : null;
		var printedPatient = (PD.PRO_LABELS && !idle && PD.getPatientName) ? PD.getPatientName() : '';
		/* resetPlanState() below clears the start date and the first
		   dose, and the labels of this plan are built from them
		   (labelStart()). Without this, a plan starting tomorrow or with
		   the first dose «Βράδυ» would get labels reading "start this
		   morning" once «Νέος ασθενής» is pressed — contradicting the A4
		   sheet.
		   The day pass is kept too, so the dates on the label are the
		   dates the sheet was printed with. */
		var printedPlan = null;
		if (printedItems && printedItems.length) {
			if (typeof PD.s.planDayZero !== 'number' || typeof PD.s.planToday !== 'number') {
				PD.beginDayPass();
			}
			printedPlan = {
				startDate: PD.s.startDate,
				firstSlot: PD.s.firstSlot,
				dayZero: PD.s.planDayZero,
				today: PD.s.planToday
			};
		}
		PD.resetPlanState();
		if (printedItems && printedItems.length) {
			PD.s.lastPrintedItems = printedItems;
			PD.s.lastPrintedPatient = printedPatient;
			PD.s.lastPrintedPlan = printedPlan;
			PD.armLastPrintedTimer();
		}
		PD.buildApp();
		PD.goToStep(1);
		PD.renderBillingStatus();
		if (idle) {
			PD.setMessage(PD.format(PD.txt('printedIdleCleared', 'Το πλάνο καθαρίστηκε αυτόματα μετά από %d λεπτά χωρίς δραστηριότητα.'), Math.round(PD.PRINTED_IDLE_MS / 60000)), 'success');
			return;
		}
		if (PD.s.lastPrintedItems) {
			PD.setMessage(PD.txt('planReadyForNextPro', 'Το πλάνο εκτυπώθηκε. Μπορείτε ακόμη να τυπώσετε τις ετικέτες του με το «Εκτύπωση Ετικετών» — ή να ξεκινήσετε τον επόμενο ασθενή.'), 'success');
			return;
		}
		PD.setMessage(PD.txt('planReadyForNext', 'Το πλάνο εκτυπώθηκε. Η φόρμα καθαρίστηκε — έτοιμη για τον επόμενο ασθενή.'), 'success');
	};

	/**
	 * Empty the fallback print frame, which otherwise keeps the
	 * last patient's whole plan in its document until the page reloads.
	 * Called when the popup closes; never while a print is running.
	 */
	PD.clearPrintFrame = function clearPrintFrame() {
		var iframe = document.getElementById('plandose-print-frame');
		if (!iframe) {
			return;
		}
		try {
			var doc = iframe.contentWindow && iframe.contentWindow.document;
			if (doc) {
				doc.open();
				doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title> </title></head><body></body></html>');
				doc.close();
			}
		} catch (e) {
			/* Could not reach the document: remove the frame instead. */
			if (iframe.parentNode) {
				iframe.parentNode.removeChild(iframe);
			}
		}
	};

	/**
	 * Fallback: print through a dedicated hidden iframe. Used only if a
	 * separate print window could not be opened (e.g. blocked by a popup
	 * blocker). Chrome/Edge's automatic print header/footer will show the
	 * parent page's own title/URL in this fallback path, since printing an
	 * embedded frame is still considered "printing within" the parent page.
	 */
	PD.printViaHiddenFrame = function printViaHiddenFrame(html, onDone) {
		var iframe = document.getElementById('plandose-print-frame');
		if (!iframe) {
			iframe = document.createElement('iframe');
			iframe.id = 'plandose-print-frame';
			iframe.setAttribute('aria-hidden', 'true');
			iframe.style.position = 'fixed';
			iframe.style.top = '-10000px';
			iframe.style.left = '-10000px';
			iframe.style.width = '0';
			iframe.style.height = '0';
			iframe.style.border = '0';
			document.body.appendChild(iframe);
		}
		var doc = iframe.contentWindow && iframe.contentWindow.document;
		if (!doc) {
			if (onDone) {
				onDone(false);
			}
			return;
		}
		doc.open();
		doc.write('<!DOCTYPE html><html><head><meta charset="utf-8">' + '<title> </title>' + '<style>' + PD.PRINT_STYLES + '</style>' + '</head><body>' + html + '</body></html>');
		doc.close();
		var finished = false;
		var printStarted = false;
		function finish(started, uncertain) {
			if (finished) {
				return;
			}
			finished = true;
			try {
				iframe.contentWindow.removeEventListener('afterprint', afterPrint);
			} catch (e) {
				/* ignore */
			}
			if (onDone) {
				onDone(started === true, uncertain === true && started === true);
			}
		}
		function afterPrint() {
			finish(printStarted);
		}
		try {
			iframe.contentWindow.addEventListener('afterprint', afterPrint);
		} catch (e) {
			/* ignore */
		}
		setTimeout(function () {
			try {
				iframe.contentWindow.focus();
				printStarted = true;
				iframe.contentWindow.print();
			} catch (e) {
				printStarted = false;
				finish(false);
			}
		}, 30);
		/* No 'afterprint' within 3 s. print() may have been
		   suppressed without an error, so the outcome is reported as
		   uncertain rather than as a print (see showPrintedState()). */
		setTimeout(function () {
			finish(printStarted, true);
		}, 3000);
	};

	/**
	 * Open a placeholder print window synchronously (must happen inside the
	 * click handler, before any async AJAX calls, so browsers still treat it
	 * as a user-initiated action and don't block the popup).
	 *
	 * Uses a FRESH window name on every call (via printWindowCounter)
	 * instead of a single fixed name. With one fixed name (such as
	 * 'plandosePrintWindow'), window.open() returns a reference to whatever
	 * window is already there under it — including, if a prior print's
	 * native browser Print Preview dialog is still open/closing (e.g.
	 * 'afterprint' didn't fire for that popup, or the person clicked print
	 * again before the previous dialog fully dismissed), a window that is
	 * still mid print-preview. Writing a new document into that window
	 * while the browser's Print Preview is still tied to the old one
	 * produces the browser's own "δεν ήταν δυνατή η προεπισκόπιση του
	 * αρχείου" error, and leaves that window stuck for every click after
	 * it, since window.open() with the same name keeps returning the same
	 * broken reference. Explicitly
	 * closing any previous window first, then always opening a brand-new
	 * one, means each print attempt gets a clean window with no leftover
	 * state from an earlier one.
	 */
	PD.openPrintPlaceholder = function openPrintPlaceholder() {
		if (PD.s.activePrintWindow) {
			try {
				if (!PD.s.activePrintWindow.closed) {
					PD.s.activePrintWindow.close();
				}
			} catch (e) {
				/* ignore */
			}
			PD.s.activePrintWindow = null;
		}
		PD.s.printWindowCounter++;
		var win = null;
		try {
			win = window.open('', 'plandosePrintWindow_' + PD.s.printWindowCounter);
		} catch (e) {
			win = null;
		}
		if (win) {
			try {
				win.document.open();
				win.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title> </title></head>' + '<body style="font-family:sans-serif;color:#777;padding:60px;text-align:center;">' + PD.escapeHtml(PD.txt('pleaseWait', 'Παρακαλώ περιμένετε...')) + '</body></html>');
				win.document.close();
			} catch (e) {
				/* ignore, we still try to use the window below */
			}
		}
		PD.s.activePrintWindow = win;
		return win;
	};

	PD.closePrintWindow = function closePrintWindow(printWin) {
		if (printWin && !printWin.closed) {
			try {
				printWin.close();
			} catch (e) {
				/* ignore */
			}
		}
		if (PD.s.activePrintWindow === printWin) {
			PD.s.activePrintWindow = null;
		}
	};

	/**
	 * Print through a dedicated, separate top-level window instead of an
	 * embedded iframe. This is what actually stops Chrome/Edge's automatic
	 * print header/footer from showing the parent page's title and URL
	 * (e.g. "My Account" / pharmacyneeds.gr/my-account/): a printed iframe
	 * is still considered part of the parent page for header/footer
	 * purposes, but a genuinely separate window is not.
	 */
	PD.printDocumentHtml = function printDocumentHtml(printWin, html, onDone) {
		if (!printWin || printWin.closed) {
			PD.printViaHiddenFrame(html, onDone);
			return;
		}
		var doc;
		try {
			doc = printWin.document;
			doc.open();
			doc.write('<!DOCTYPE html><html><head><meta charset="utf-8">' + '<title>' + PD.escapeHtml(PD.txt('printTitle', 'Πλάνο Δοσολογίας')) + '</title>' + '<style>' + PD.PRINT_STYLES + '</style>' + '</head><body>' + html + '</body></html>');
			doc.close();
		} catch (e) {
			/* Close the empty placeholder window before falling back so it
			   isn't left open and blank on screen. */
			PD.closePrintWindow(printWin);
			PD.printViaHiddenFrame(html, onDone);
			return;
		}
		/*
		 * Finishing (reporting the outcome) and closing the window are kept
		 * apart. The window is closed ONLY on 'afterprint' or on
		 * a failure. The 6-second fallback below just reports the outcome
		 * and leaves the window alone: where print() does not block (Chrome
		 * on Android, among others) the print dialog may still be open at
		 * that point, and closing its window would cancel the print after
		 * the credit had already been taken. A window whose browser never fires
		 * 'afterprint' is left for the pharmacist to close, and the next print
		 * closes it anyway (see openPrintPlaceholder()).
		 */
		var finished = false;
		var printStarted = false;
		function finish(started) {
			if (finished) {
				return;
			}
			finished = true;
			if (onDone) {
				onDone(started === true);
			}
		}
		function afterPrint() {
			try {
				printWin.removeEventListener('afterprint', afterPrint);
			} catch (e) {
				/* ignore */
			}
			finish(printStarted);
			PD.closePrintWindow(printWin);
		}
		try {
			printWin.addEventListener('afterprint', afterPrint);
		} catch (e) {
			/* ignore */
		}
		setTimeout(function () {
			try {
				printWin.focus();
				printStarted = true;
				printWin.print();
			} catch (e) {
				printStarted = false;
				finish(false);
				PD.closePrintWindow(printWin);
			}
		}, 30);
		setTimeout(function () {
			finish(printStarted);
		}, 6000);
	};

	PD.doPrintSafely = function doPrintSafely(printWin) {
		if (PD.s.isPrinting) {
			PD.closePrintWindow(printWin);
			return;
		}
		if (PD.s.items.length === 0) {
			PD.setMessage(PD.txt('addAtLeastOne', 'Προσθέστε τουλάχιστον ένα φάρμακο.'), 'error');
			PD.goToStep(1);
			PD.closePrintWindow(printWin);
			return;
		}
		/* The pharmacist closed the "please wait" window while the
		   checks were running — that is a cancel. Nothing is recorded.
		   (A null window means a popup blocker stopped it; that case still
		   prints through the hidden-frame fallback.) Say so. */
		if (printWin && printWin.closed) {
			PD.closePrintWindow(printWin);
			PD.updateStepButtons();
			PD.setMessage(PD.txt('printWindowClosedCancelled', 'Η εκτύπωση ακυρώθηκε ή το παράθυρο μπλοκαρίστηκε — δεν καταγράφηκε νέα εκτύπωση. Πατήστε ξανά «Εκτύπωση»· αν το παράθυρο δεν ανοίγει, επιτρέψτε τα αναδυόμενα παράθυρα για αυτό το site.'), 'error');
			return;
		}
		/* The final check again, right before the sheet is built —
		   the checks in handlePrint() ran before up to two network round
		   trips. Nothing has been sent to register_print yet, so stopping
		   here charges nothing (a token that may already be charged stays
		   pending for the next press, see printTokenNoSheet). */
		if ((typeof PD.blockIfUnsavedDrug === 'function' && PD.blockIfUnsavedDrug()) ||
			(typeof PD.validatePlanForPrint === 'function' && !PD.validatePlanForPrint())) {
			PD.closePrintWindow(printWin);
			PD.updateStepButtons();
			return;
		}
		PD.s.isPrinting = true;
		var printBtn = document.getElementById('pd-print');

		/*
		 * Reuse the pending token from a previous, not-yet-confirmed
		 * attempt if there is one, rather than always minting a fresh
		 * one. Without this, a retry after a network failure whose
		 * response was simply lost (the server may well have already
		 * recorded the earlier attempt) would send a brand-new token,
		 * and the server would have no way to tell that apart from a
		 * genuinely new print — see the docs on pendingPrintToken above.
		 */
		var token = PD.s.pendingPrintToken;
		if (!token) {
			token = PD.createPrintToken();
			/* A fresh token starts with no history. */
			PD.s.printTokenNoSheet = false;
			PD.s.printTokenSheetOpened = false;
			/* Baseline for telling a real duplicate from an unrecorded one on
			   a later retry of this same token — see consumePrintCreditOnce(). */
			PD.s.pendingPrintCountBefore = (PD.s.printStatus && typeof PD.s.printStatus.print_count === 'number')
				? PD.s.printStatus.print_count
				: null;
		}
		PD.s.pendingPrintToken = token;
		PD.setBusy(printBtn, true, PD.txt('printing', 'Εκτύπωση...'));
		PD.syncPrintLock();
		PD.clearMessage();

		/* Built BEFORE the credit is taken: if building the sheet ever
		   failed, nothing has been charged yet. */
		var html;
		try {
			html = PD.buildCheckedPrintHtml();
			PD.renderPreview();
		} catch (e) {
			PD.closePrintWindow(printWin);
			PD.unlockPrintUi();
			PD.updateStepButtons();
			if (e && 'invalid_start_date' === e.message && typeof PD.validateStartDate === 'function' && !PD.validateStartDate()) {
				return;
			}
			PD.setMessage(PD.txt('genericError', 'Κάτι πήγε στραβά.'), 'error');
			return;
		}

		/*
		 * THE PRINT IS RECORDED FIRST, THEN PRINTED.
		 *
		 * The plan is written into the print window only once the server
		 * has confirmed the print — no confirmation, no sheet. Still no
		 * patient data goes to the server: register_print only ever
		 * receives the random print token.
		 *
		 * If the print then fails to open after the print was recorded (the
		 * print window vanished, print() threw), the pharmacist is NOT
		 * charged again for the retry: the token stays pending, and the
		 * server answers it from its print receipts.
		 */
		var wasConfirmed = PD.s.lastPrintToken === token;
		var countBefore = PD.s.pendingPrintCountBefore;
		var chargedAgain = false;
		var lastInfo = null;
		var settled = false;
		var safetyTimer = null;
		function settle(printStarted, uncertain) {
			if (settled) {
				return;
			}
			settled = true;
			if (safetyTimer) {
				clearTimeout(safetyTimer);
				safetyTimer = null;
			}
			PD.unlockPrintUi();
			/* A sheet opened → the token has been used; otherwise it
			   is recorded without a sheet and is carried to the next print
			   (see printTokenNoSheet in state.js) — unless an earlier print
			   of this token already produced a sheet (a cancelled reprint
			   must not pay for a different plan). */
			if (printStarted === true) {
				PD.s.printTokenSheetOpened = true;
			}
			PD.s.printTokenNoSheet = printStarted !== true && !PD.s.printTokenSheetOpened;
			keepRequestForRetry(printStarted !== true);
			if (printStarted === true) {
				/* The plan and its (confirmed) token stay, so
				   «Εκτύπωση ξανά» is free until the plan is changed. */
				PD.showPrintedState(chargedAgain, lastInfo, uncertain === true);
				return;
			}
			/* Recorded, but the print did not open: say so plainly, and that
			   pressing Print again costs nothing. The plan and its token stay
			   (and are cleared after the same idle time). */
			PD.updateStepButtons();
			PD.armPrintedIdleTimer();
			PD.renderBillingStatus();
			PD.setMessage(PD.format(PD.txt('printRetryFreeUntil', 'Η εκτύπωση δεν άνοιξε, αλλά η χρέωση καταγράφηκε. Πατήστε ξανά «Εκτύπωση» έως τις %1$s — δεν θα χρεωθεί δεύτερη φορά (έως %2$d επαναλήψεις), ακόμη κι αν διορθώσετε πρώτα το πλάνο ή αυτό καθαριστεί μετά από %3$d λεπτά αδράνειας.'), PD.clockTime(PD.billingUntil()), PD.maxFreeReprints(), Math.round(PD.PRINTED_IDLE_MS / 60000)), 'error');
		}

		var answeredRequest = null;
		/*
		 * A confirmed charge whose sheet never opened is retried
		 * with the SAME request id, so the server answers it as a replay —
		 * free, for its whole receipt window, up to its replay limit —
		 * instead of spending one of the token's free reprints (after the
		 * last of which a new request id would be charged).
		 */
		function keepRequestForRetry(keep) {
			if (keep && answeredRequest && PD.s.pendingPrintToken === token) {
				PD.s.pendingPrintRequest = answeredRequest;
				PD.s.pendingPrintRequestToken = token;
			} else if (PD.s.pendingPrintRequestToken === token) {
				PD.s.pendingPrintRequest = null;
				PD.s.pendingPrintRequestToken = null;
			}
		}

		PD.consumePrintCreditOnce(token, function (recorded, info) {
			answeredRequest = info && info.requestId ? info.requestId : null;
			chargedAgain = !!recorded && wasConfirmed && !(info && info.alreadyRecorded);
			lastInfo = info || null;
			if (!recorded) {
				/* consumePrintCreditOnce() has already shown why (limit reached,
				   connection problem, not recorded). Nothing was printed, the
				   plan stays on screen with its token for a retry. */
				PD.closePrintWindow(printWin);
				PD.unlockPrintUi();
				PD.updateStepButtons();
				PD.renderBillingStatus();
				return;
			}
			PD.recordBilling(token, info);

			/*
			 * The sheet must be the plan on screen NOW. If the plan
			 * changed while the print was being recorded (the token was
			 * dropped by an edit, or the sheet differs), the old sheet is
			 * not printed. The charge is kept on the token, so the next
			 * press prints the current plan without a new charge — unless
			 * this token already produced a sheet (then it paid for that one).
			 */
			var current = null;
			try {
				current = PD.buildCheckedPrintHtml();
			} catch (e) {
				current = null;
			}
			if (PD.s.pendingPrintToken !== token || current !== html) {
				PD.closePrintWindow(printWin);
				PD.s.confirmedPrintHtml = null;
				PD.s.confirmedPatient = null;
				var carry = !PD.s.printTokenSheetOpened;
				if (carry) {
					PD.s.pendingPrintToken = token;
					PD.s.pendingPrintCountBefore = countBefore;
					PD.s.printTokenNoSheet = true;
				} else if (PD.s.pendingPrintToken === token) {
					PD.s.pendingPrintToken = null;
				}
				keepRequestForRetry(carry);
				settled = true;
				PD.unlockPrintUi();
				PD.updateStepButtons();
				PD.renderBillingStatus();
				PD.setMessage(carry
					? PD.txt('printPlanChangedCarried', 'Το πλάνο άλλαξε ενώ καταγραφόταν η εκτύπωση, οπότε δεν τυπώθηκε. Η χρέωση καταγράφηκε: πατήστε ξανά «Εκτύπωση» για το τρέχον πλάνο — χωρίς νέα χρέωση.')
					: PD.txt('printPlanChangedNoSheet', 'Το πλάνο άλλαξε ενώ καταγραφόταν η επανεκτύπωση, οπότε δεν τυπώθηκε. Η επόμενη εκτύπωση θα μετρήσει ως νέα.'), 'error');
				return;
			}

			/* Remember exactly what was paid for — see isReprintFree(). */
			PD.s.confirmedPrintHtml = html;
			/* And for whom — see the patient field in app.js. */
			PD.s.confirmedPatient = PD.getPatientName ? PD.getPatientName() : '';

			/* Closed by the pharmacist while the print was being recorded:
			   respect the cancel and print nothing. The print is recorded, so
			   pressing Print again re-prints this plan free of charge. */
			if (printWin && printWin.closed) {
				PD.closePrintWindow(printWin);
				settle(false);
				return;
			}

			/*
			 * Last-resort net: printDocumentHtml()/printViaHiddenFrame()
			 * report back through their own 6 s / 3 s fallbacks even when
			 * 'afterprint' never fires, but if something unexpected stops
			 * that, the button must not stay disabled for the rest of the
			 * session. The print is already recorded at this point, so
			 * settling as "did not open" only ever offers a free retry.
			 */
			safetyTimer = setTimeout(function () {
				safetyTimer = null;
				settle(false);
			}, 10000);

			try {
				PD.printDocumentHtml(printWin, html, settle);
			} catch (e) {
				PD.closePrintWindow(printWin);
				settle(false);
			}
		});
	};

	/**
	 * Why the pharmacy header could not be used — an expired
	 * session, the server's own refusal (its message), missing pharmacy
	 * details, or a connection problem.
	 */
	PD.headerFailureMessage = function headerFailureMessage() {
		if (PD.s.headerSessionExpired) {
			return PD.txt('sessionExpired', 'Η σελίδα ήταν ανοιχτή πολλή ώρα και η σύνδεσή σας έληξε. Κάντε ανανέωση της σελίδας (F5) και δοκιμάστε ξανά.');
		}
		if (PD.s.headerError) {
			return String(PD.s.headerError);
		}
		if (PD.s.headerEmpty) {
			return PD.txt('headerEmpty', 'Δεν βρέθηκαν τα στοιχεία του φαρμακείου (όνομα) για το φύλλο. Συμπληρώστε τα στο προφίλ του λογαριασμού σας και δοκιμάστε ξανά.');
		}
		return PD.txt('connectionError', 'Πρόβλημα σύνδεσης. Δοκιμάστε ξανά.');
	};

	PD.handlePrint = function handlePrint() {
		if (typeof PD.flushPatientInput === 'function') {
			PD.flushPatientInput();
		}
		if (PD.s.isCheckingPrint || PD.s.isPreparingPrint || PD.s.isPrinting || PD.s.printCreditLocked || PD.s.isPrintingLabels) {
			return;
		}
		/* Patient safety: a medicine typed but not added would be
		   missing from the sheet. Checked first — before any window opens
		   or any request is sent — so nothing is charged. */
		if (PD.blockIfUnsavedDrug()) {
			return;
		}
		if (PD.s.items.length === 0) {
			PD.setMessage(PD.txt('addAtLeastOne', 'Προσθέστε τουλάχιστον ένα φάρμακο.'), 'error');
			PD.goToStep(1);
			return;
		}
		/* Validate the whole plan BEFORE a window opens or a credit
		   can be spent (start date, and a dose on at least one day for
		   every drug on today's clock). */
		if (typeof PD.validatePlanForPrint === 'function' && !PD.validatePlanForPrint()) {
			return;
		}

		/* Open the print window synchronously, right here in the click
			 handler, so popup blockers still treat it as user-initiated even
			 though the actual content is only written in a moment, after the
			 print-permission and header AJAX calls resolve. */
		var printWin = PD.openPrintPlaceholder();
		var printBtn = document.getElementById('pd-print');
		var failed = false;
		/*
		 * The button stays busy (and disabled) for the WHOLE preparation.
		 * Released as soon as the limit check answered, while the header
		 * lookup could still be running, a second click in that gap would
		 * start a second flow on top of the first. isPreparingPrint is cleared only when the flow
		 * either hands over to doPrintSafely() or aborts.
		 */
		PD.s.isPreparingPrint = true;
		PD.setBusy(printBtn, true, PD.txt('checkingPrint', 'Έλεγχος εκτύπωσης...'));
		PD.updateStepButtons();
		/* The plan cannot be edited from here until the flow ends. */
		PD.syncPrintLock();
		/*
		 * A free retry — this exact plan's print is already recorded
		 * (its token was confirmed) but the print did not open — skips the
		 * limit check. That check would refuse the pharmacist who had just
		 * used the LAST print of the month, leaving them charged with no
		 * sheet. register_print answers the confirmed token from the
		 * server's print receipts, so nothing new is charged.
		 */
		var freeRetry = PD.isReprintFree();
		/* A confirmed token whose sheet has changed since (language,
		   date) is spent — the new sheet is a new print with a new token. */
		if (!freeRetry && PD.s.pendingPrintToken && PD.s.pendingPrintToken === PD.s.lastPrintToken) {
			PD.invalidatePendingPrintToken();
		}
		/*
		 * The same for a retry of a token that was sent but never
		 * confirmed (the answer was lost or the request failed). The server
		 * may well have charged it already — possibly with the month's last
		 * free print — and check_print would then refuse the retry as
		 * «όριο». register_print is the authority: it answers an already
		 * charged token as such, and refuses an uncharged one at the limit
		 * with the same message check_print would have shown.
		 */
		var pendingRetry = !freeRetry && !!PD.s.pendingPrintToken && PD.s.pendingPrintToken !== PD.s.lastPrintToken;
		/* A confirmed token that produced no sheet, carried over
		   to an edited plan (printTokenNoSheet): register_print answers it
		   as already recorded, so the limit check must not refuse it. */
		var carriedOver = !freeRetry && PD.keepsUnusedPrintToken();
		var skipLimitCheck = freeRetry || pendingRetry || carriedOver;
		var pending = skipLimitCheck ? 1 : 2;

		function endPreparing() {
			PD.s.isPreparingPrint = false;
			PD.setBusy(printBtn, false);
			PD.syncPrintLock();
		}

		function maybeProceed() {
			pending--;
			if (failed || pending > 0) {
				return;
			}
			/* Released and immediately taken again by doPrintSafely()
			   (same tick), which owns the busy state from here on. */
			endPreparing();
			try {
				PD.renderPreview();
				PD.doPrintSafely(printWin);
			} catch (e) {
				/* Never leave the plan locked or the button busy.
				   doPrintSafely() throws only before register_print is
				   sent, so nothing was charged. */
				PD.closePrintWindow(printWin);
				PD.unlockPrintUi();
				PD.setMessage(PD.txt('genericError', 'Κάτι πήγε στραβά.'), 'error');
			}
			PD.updateStepButtons();
		}

		/* Shared by both failure paths. The `failed` guard matters: whichever
		   of the two calls fails first has already shown its own message and
		   closed the window, and the second must not overwrite it. */
		function abort(message) {
			if (failed) {
				return;
			}
			failed = true;
			PD.closePrintWindow(printWin);
			endPreparing();
			PD.updateStepButtons();
			if (message) {
				PD.setMessage(message, 'error');
			}
		}

		/* Both requests carry the nonce, so they wait for a refresh
		   still in flight (see whenNonceFresh()) instead of racing it with
		   a stale one. The window is already open, so the popup blocker is
		   not involved. */
		PD.whenNonceFresh(function () {
			/* These two run at the same time (instead of one after the other)
				 since they're independent, so printing only waits for the
				 slower of the two network calls, not both combined. */
			if (!skipLimitCheck) {
				PD.checkPrintAllowed(maybeProceed, function () {
					/* checkPrintAllowed() has already set its own message. */
					abort('');
				});
			}

			/*
			 * A failed header lookup must not be waved through: buildPrintHtml()
			 * would render "Στοιχεία Φαρμακείου" as a bare dash, and the print
			 * would go ahead and consume a credit — the pharmacist would pay
			 * for a sheet the patient cannot phone anyone from. Stop instead — PD.s.header stays null, so simply pressing
			 * Εκτύπωση again retries the lookup.
			 */
			PD.loadHeader(function (loaded) {
				if (!loaded) {
					abort(PD.headerFailureMessage());
					return;
				}
				maybeProceed();
			});
		});
	};

})();
