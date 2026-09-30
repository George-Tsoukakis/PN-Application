/**
 * PlanDose — rx-import.js
 *
 * Reads the text of a Greek e-prescription (ΗΔΙΚΑ print-out, pasted as
 * text) and turns every prescribed medicine into a PlanDose item for the
 * pharmacist to REVIEW. Runs entirely in the browser: the pasted text holds
 * the patient's ΑΜΚΑ and diagnosis, and nothing of it is sent anywhere.
 * The full pasted text is not kept in the page (the box is emptied at
 * once), but what the parse read from it IS kept in memory while the
 * panel is open: PD.s.rx (each row's drug / dose lines as `origText` and
 * `source`, the unread lines shown for review, the patient name found)
 * until the panel closes (PD.closeRxImport()) or the popup closes / the
 * next patient starts (PD.resetPlanState()); and PD.s.rxSourceText (the
 * lines of the medicine loaded into the form) until the form is cleared
 * or reset. Never printed or stored.
 *
 * This file is the entry point: the paste box, the paste itself (read
 * at once and never left in the page) and the stages of the panel. The
 * reading is rx-parse.js, the review rx-review.js.
 *
 * The reader is five files, loaded in this order (each needs the ones
 * before it): rx-text → rx-lines → rx-parse → rx-review → rx-import.
 * They share their internal helpers on PD.rx._ — not an API, only for
 * the rx modules that come after; the public entry points stay on PD
 * (PD.parsePrescription, PD.rxImportHtml, PD.wireRxImport, …).
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;
	var R = PD && PD.rx && PD.rx._;

	/* Nothing of the reader runs if a file before this one is missing. */
	if (!R || !R.reviewBody) {
		return;
	}

	var plain = R.plain;
	var rerender = R.rerender;

	function t(key, fallback) {
		return PD.txt(key, fallback);
	}

	function isMac() {
		return /Mac|iPhone|iPad/.test(String(navigator.platform || '') + ' ' + String(navigator.userAgent || ''));
	}

	/* «Ctrl+V» → <kbd>Ctrl</kbd>+<kbd>V</kbd>; ⌘ on a Mac. */
	function withKeys(escaped) {
		var mac = isMac();
		return escaped.replace(/Ctrl\+([A-Z])/g, function (all, key) {
			return '<kbd>' + (mac ? '⌘' : 'Ctrl') + '</kbd>+<kbd>' + key + '</kbd>';
		});
	}

	/* Plain-text version (placeholders). */
	function macKeys(text) {
		return isMac() ? text.replace(/Ctrl\+/g, '⌘') : text;
	}

	/**
	 * The closed state is the paste field itself. At rest it is a
	 * dashed box with the instructions; on hover or click it OPENS into a
	 * real text field (class is-open) waiting for Ctrl+V, and the paste is
	 * read at once — no «Ανάγνωση». The pasted text never stays in the
	 * page: the paste is intercepted, and text dropped in is read and
	 * cleared on the input event.
	 */
	function dropZoneHtml() {
		var title = t('rxDropTitle', 'Επικολλήστε εδώ ολόκληρη την ηλεκτρονική συνταγή');
		var help = t('rxDropHelp', 'Αντιγράψτε όλο το κείμενο της συνταγής (Ctrl+A → Ctrl+C), κάντε κλικ εδώ και πατήστε Ctrl+V. Φάρμακα, δοσολογία και ημέρες συμπληρώνονται αυτόματα.');
		var touch = window.matchMedia && window.matchMedia('(hover: none)').matches;
		var placeholder = touch
			? t('rxDropPlaceholderTouch', 'Πατήστε παρατεταμένα εδώ και επιλέξτε «Επικόλληση» — η συνταγή διαβάζεται αμέσως.')
			: macKeys(t('rxDropPlaceholder', 'Πατήστε Ctrl+V για να επικολλήσετε τη συνταγή — διαβάζεται αμέσως.'));
		var hint = PD.s.rxHint
			? '<p class="pd-rx-hint" role="status">' + PD.escapeHtml(PD.s.rxHint) + '</p>'
			: '';
		return '<div id="pd-rx"><div class="pd-rx-drop">' +
			'<span class="pd-rx-idle">' +
			'<svg class="pd-rx-icon" aria-hidden="true" focusable="false" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			'<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></svg>' +
			'<span class="pd-rx-drop-text"><b>' + PD.escapeHtml(title) + '</b>' +
			'<span id="pd-rx-drop-help">' + withKeys(PD.escapeHtml(help)) + '</span></span></span>' +
			'<span class="pd-rx-new" aria-hidden="true">' + PD.escapeHtml(t('rxNew', 'ΝΕΟ')) + '</span>' +
			'<textarea id="pd-rx-drop" rows="4" autocomplete="off" spellcheck="false" aria-label="' + PD.escapeAttr(title) + '" aria-describedby="pd-rx-drop-help" placeholder="' + PD.escapeAttr(placeholder) + '"></textarea>' +
			'</div>' + hint + '</div>';
	}

	PD.rxImportHtml = function rxImportHtml() {
		var rx = PD.s.rx;
		if (!rx) {
			return dropZoneHtml();
		}
		var body;
		if ('paste' === rx.stage) {
			body = '<label for="pd-rx-text">' + PD.escapeHtml(t('rxOpen', 'Επικόλληση συνταγής')) + '</label>' +
				'<textarea id="pd-rx-text" rows="6" autocomplete="off" spellcheck="false" placeholder="' + PD.escapeAttr(t('rxPlaceholder', 'Επικολλήστε εδώ όλο το κείμενο της ηλεκτρονικής συνταγής…')) + '"></textarea>' +
				'<small>' + PD.escapeHtml(t('rxPrivacy', 'Το κείμενο διαβάζεται μόνο σε αυτόν τον browser — δεν στέλνεται και δεν αποθηκεύεται.')) + '</small>' +
				(rx.error ? '<p class="pd-rx-error" role="alert">' + PD.escapeHtml(rx.error) + '</p>' : '') +
				'<div class="pd-rx-actions">' +
				'<button type="button" id="pd-rx-read" class="plandose-btn plandose-btn-secondary">' + PD.escapeHtml(t('rxRead', 'Ανάγνωση')) + '</button>' +
				'<button type="button" id="pd-rx-cancel" class="plandose-btn plandose-btn-light">' + PD.escapeHtml(t('rxCancel', 'Άκυρο')) + '</button>' +
				'</div>';
		} else {
			body = R.reviewBody(rx);
		}
		return '<div id="pd-rx"><div class="pd-rx-panel">' + body + '</div></div>';
	};

	PD.closeRxImport = function closeRxImport() {
		PD.s.rx = null;
		PD.s.rxHint = '';
		rerender('pd-rx-drop');
	};

	function readPasted() {
		var ta = document.getElementById('pd-rx-text');
		var text = ta ? ta.value : '';
		if (ta) {
			ta.value = ''; /* the pasted text holds the ΑΜΚΑ: drop it at once */
		}
		readText(text);
	}

	function readText(text) {
		var result = PD.parsePrescription(text);
		if (result.tooLarge) {
			PD.s.rx = null;
			PD.s.rxHint = t('rxTooLarge', 'Το κείμενο είναι πολύ μεγάλο για μία συνταγή — αντιγράψτε μόνο τη συνταγή και ξαναδοκιμάστε.');
			rerender('pd-rx-drop');
			return;
		}
		if (!result.items.length) {
			/* A small note under the box — the box stays as it is. */
			PD.s.rx = null;
			PD.s.rxHint = t('rxNone', 'Δεν βρέθηκαν φάρμακα σε αυτό το κείμενο — αντιγράψτε ολόκληρη τη συνταγή και ξαναδοκιμάστε.');
			rerender('pd-rx-drop');
			return;
		}
		PD.s.rxHint = '';
		var patientEl = document.getElementById('pd-patient');
		var current = patientEl ? patientEl.value.trim() : '';
		var patientNote = '';
		var usePatient = !!result.patient && !current;
		/* Another patient's medicines must not slip into this
		   plan: nothing is preselected and «Προσθήκη» waits for an
		   explicit confirmation. */
		var patientCheck = false;
		if (result.patients.length > 1) {
			/* Prescriptions of different patients in one paste. */
			usePatient = false;
			patientCheck = true;
			patientNote = PD.format(t('rxManyPatients', 'Το κείμενο έχει συνταγές για διαφορετικούς ασθενείς (%s). Ελέγξτε ότι όλα τα φάρμακα είναι του ίδιου ασθενή.'), result.patients.join(', '));
		} else if (result.patient && current && norm(current) !== norm(result.patient)) {
			patientCheck = true;
			patientNote = PD.format(t('rxOtherPatient', 'Στο πλάνο είναι ήδη ο ασθενής «%1$s» — η συνταγή είναι για «%2$s».'), current, result.patient);
		}
		/* Fewer medicines read than the prescription lists. */
		var countNote = result.expectedCount > result.readCount
			? PD.format(t('rxCountMismatch', 'Η συνταγή φαίνεται να έχει %1$d φάρμακα, διαβάστηκαν %2$d — ελέγξτε.'), result.expectedCount, result.readCount)
			: '';
		/* Dose-like lines that no medicine was read from. */
		/* Text in the paste that no medicine was read from. */
		if (result.unreadText && result.unreadText.length) {
			var shown = result.unreadText.slice(0, 3).map(function (tx) {
				return tx.length > 60 ? tx.slice(0, 60) + '…' : tx;
			}).join('» «') + (result.unreadText.length > 3 ? '» …' : '»');
			countNote = (countNote ? countNote + ' ' : '') +
				PD.format(t('rxUnreadText', 'Υπάρχει κείμενο στη συνταγή που δεν διαβάστηκε: «%s — ελέγξτε τη συνταγή.'), shown);
		}
		if (result.unreadDoseLines > 0) {
			countNote = (countNote ? countNote + ' ' : '') +
				PD.format(t('rxUnreadDoseLines', 'Βρέθηκαν %d γραμμές που μοιάζουν με δοσολογία αλλά δεν διαβάστηκαν — κάποιο φάρμακο μπορεί να λείπει. Ελέγξτε τη συνταγή.'), result.unreadDoseLines);
		}
		var seen = {};
		var inPlan = {};
		PD.s.items.forEach(function (it) {
			inPlan[norm(it.name)] = true;
		});
		/* The same medicine at two different doses (SINTROM 4MG
		   1 δισκίο and 1/2 δισκίο): neither is chosen for the pharmacist. */
		var sources = {};
		result.items.forEach(function (item) {
			var n = norm(item.name);
			sources[n] = sources[n] || {};
			sources[n][norm(item.source)] = true;
		});
		PD.s.rx = {
			stage: 'review',
			patient: result.patient,
			usePatient: usePatient,
			patientNote: patientNote,
			patientCheck: patientCheck,
			patientOk: false,
			countNote: countNote,
			countAck: false,
			rows: result.items.map(function (item) {
				/* The same medicine and dose twice (a prescription pasted
				   twice, the same drug on two prescriptions), or already in
				   the plan: shown, but not selected. The name carries the
				   strength, so SINTROM 1MG and 4MG differ; the pack size
				   («BTx30» / «BTx60») does not make the same medicine a
				   different one. */
				var n = norm(item.name);
				var key = n + '|' + norm(item.source);
				var include = !patientCheck;
				if (seen[key]) {
					item.warnings.push('duplicate');
					include = false;
				} else {
					if (n && Object.keys(sources[n]).length > 1) {
						item.warnings.push('sameDrugOtherDose');
						include = false;
					}
					if (inPlan[n]) {
						item.warnings.push('inPlan');
						include = false;
					}
				}
				seen[key] = true;
				return { item: item, include: include };
			})
		};
		rerender(countNote ? 'pd-rx-count-ack' : (patientCheck ? 'pd-rx-patient-ok' : 'pd-rx-add'));
	}

	function norm(text) {
		return plain(String(text || '')).replace(/\s+/g, ' ').trim();
	}

	/* Hover OPENS the box into the text field, to show where the paste
	   goes; the caret goes there only on a click, a tap or the keyboard
	   (Tab). Moving the pointer never takes the focus (WCAG 3.2.1: the
	   pharmacist may be typing elsewhere, or reading with a screen
	   reader). Leaving a box that only the hover opened closes it again. */
	function wireDropOpen(drop) {
		var box = drop.parentNode;
		var hovering = false;
		var setOpen = function () {
			var open = hovering || document.activeElement === drop;
			box.classList.toggle('is-open', open);
		};
		box.addEventListener('mouseenter', function () {
			hovering = true;
			setOpen();
		});
		box.addEventListener('mouseleave', function () {
			hovering = false;
			setOpen();
		});
		/* A click on the open box around the field (its padding, the «ΝΕΟ»
		   badge) still lands in the field. */
		box.addEventListener('click', function (e) {
			if (e.target !== drop && document.activeElement !== drop) {
				drop.focus();
			}
		});
		drop.addEventListener('focus', setOpen);
		drop.addEventListener('blur', setOpen);
		if (document.activeElement === drop) {
			setOpen();
		}
	}

	PD.wireRxImport = function wireRxImport() {
		var host = document.getElementById('pd-rx');
		if (!host) {
			return;
		}
		var on = function (id, fn) {
			var el = document.getElementById(id);
			if (el) {
				el.addEventListener('click', fn);
			}
		};
		var drop = document.getElementById('pd-rx-drop');
		if (drop) {
			wireDropOpen(drop);
			/* Length at the last input event (see the input handler). */
			var lastLength = drop.value.length;
			drop.addEventListener('paste', function (e) {
				var data = e.clipboardData || window.clipboardData;
				var text = data ? data.getData('text') : '';
				if (!text) {
					return; /* left to the input event below */
				}
				e.preventDefault(); /* never lands in the page */
				readText(text);
			});
			drop.addEventListener('input', function (e) {
				/* Pasted or dropped in (or set by a script): read at once.
				   Ordinary typing is left alone. */
				var type = e && e.inputType ? String(e.inputType) : '';
				var grown = drop.value.length - lastLength;
				lastLength = drop.value.length;
				if (type && type.indexOf('insertFrom') !== 0) {
					return;
				}
				/* No inputType (older engines, a script's synthetic
				   event): one typed character is not a paste — only a
				   chunk of more than one character inserted at once is. */
				if (!type && grown <= 1) {
					return;
				}
				var text = drop.value;
				drop.value = '';
				lastLength = 0;
				if (text.trim()) {
					readText(text);
				}
			});
		}
		on('pd-rx-cancel', PD.closeRxImport);
		on('pd-rx-read', readPasted);
		R.wireReview(host);
	};
})();
