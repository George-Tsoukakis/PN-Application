/**
 * PlanDose — app.js
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

	PD.stepTitle = function stepTitle(step) {
		if (step === 1) {
			return PD.txt('stepPatient', 'Στοιχεία & Φάρμακα');
		}
		return PD.txt('stepPreview', 'Προεπισκόπηση');
	};

	PD.canGoNext = function canGoNext() {
		if (PD.s.currentStep === 1 && PD.s.items.length === 0) {
			return false;
		}
		return true;
	};

	PD.updateStepButtons = function updateStepButtons() {
		var prevBtn = document.getElementById('pd-prev-step');
		var nextBtn = document.getElementById('pd-next-step');
		var printBtn = document.getElementById('pd-print');
		if (prevBtn) {
			prevBtn.disabled = PD.s.currentStep === 1;
		}
		if (nextBtn) {
			nextBtn.disabled = PD.s.currentStep === 2 || !PD.canGoNext();
		}
		var reprintFree = typeof PD.isReprintFree === 'function' && PD.isReprintFree() && PD.s.items.length > 0;
		/* isPreparingPrint keeps everything print-related disabled
		   for the whole of handlePrint()'s preparation. */
		var busy = PD.s.isPrinting || PD.s.isCheckingPrint || PD.s.isPreparingPrint || PD.s.printCreditLocked || PD.s.isPrintingLabels;
		if (printBtn) {
			printBtn.disabled = PD.s.items.length === 0 || busy;
			/* An already recorded plan re-prints free of charge.
			   Left alone while busy: setBusy() owns the text then. */
			if (!printBtn.classList.contains('is-loading')) {
				printBtn.textContent = reprintFree
					? PD.txt('reprint', 'Εκτύπωση ξανά')
					: PD.txt('print', 'Εκτύπωση Πλάνου');
			}
		}
		var newPatientBtn = document.getElementById('pd-new-patient');
		if (newPatientBtn) {
			newPatientBtn.hidden = !reprintFree;
			newPatientBtn.disabled = busy;
		}
		var labelsBtn = document.getElementById('pd-print-labels');
		if (labelsBtn && !labelsBtn.classList.contains('is-loading')) {
			labelsBtn.disabled = PD.labelItems().length === 0 || busy;
		}
	};

	PD.updateStepsUi = function updateStepsUi() {
		document.querySelectorAll('[data-pd-step]').forEach(function (el) {
			var step = parseInt(el.dataset.pdStep, 10);
			el.hidden = step !== PD.s.currentStep;
		});
		document.querySelectorAll('.plandose-step-dot').forEach(function (el) {
			var step = parseInt(el.dataset.step, 10);
			el.classList.toggle('active', step === PD.s.currentStep);
			el.classList.toggle('done', step < PD.s.currentStep);
			el.setAttribute('aria-current', step === PD.s.currentStep ? 'step' : 'false');
		});
		PD.updateStepButtons();
		if (PD.s.currentStep === 2) {
			PD.renderMedicationList();
			PD.renderPreview();
		}
	};

	PD.goToStep = function goToStep(step) {
		/* Remembered so focus can be rescued below. */
		var before = document.activeElement;
		PD.s.currentStep = Math.max(1, Math.min(2, parseInt(step, 10) || 1));
		if (PD.s.currentStep === 2) {
			PD.loadHeader(function () {
				PD.renderPreview();
			});
		}
		PD.updateStepsUi();
		PD.clearMessage();
		/* «Προεπισκόπηση»/«Πίσω» disable themselves (and «Νέος
		   ασθενής» is destroyed by buildApp()), which drops focus to
		   <body> and leaves the step change unannounced. The new step's dot
		   carries aria-current="step", so landing there reads out
		   "2 Προεπισκόπηση, τρέχον βήμα". Only when focus was lost. */
		if (typeof PD.focusWasLost === 'function' && PD.focusWasLost(before)) {
			var dot = document.querySelector('.plandose-step-dot[data-step="' + PD.s.currentStep + '"]');
			if (dot) {
				dot.focus();
			}
		}
	};

	/* Debounced preview after typing the patient name. */
	var patientRenderTimer = null;
	PD.PATIENT_RENDER_DELAY_MS = 250;

	PD.schedulePatientRender = function schedulePatientRender() {
		if (patientRenderTimer) {
			clearTimeout(patientRenderTimer);
		}
		patientRenderTimer = setTimeout(PD.flushPatientInput, PD.PATIENT_RENDER_DELAY_MS);
	};

	/** Run a pending patient-name render now (no-op when none is due). */
	PD.flushPatientInput = function flushPatientInput() {
		if (!patientRenderTimer) {
			return;
		}
		clearTimeout(patientRenderTimer);
		patientRenderTimer = null;
		PD.updateStepButtons();
		PD.renderPreview();
	};

	PD.nextStep = function nextStep() {
		PD.flushPatientInput();
		/* Patient safety: the preview must not show — and invite
		   printing — a plan without the medicine still sitting in the form. */
		if (PD.s.currentStep === 1 && PD.blockIfUnsavedDrug()) {
			return;
		}
		if (PD.s.currentStep === 1 && PD.s.items.length === 0) {
			PD.setMessage(PD.txt('addAtLeastOne', 'Προσθέστε τουλάχιστον ένα φάρμακο.'), 'error');
			return;
		}
		PD.goToStep(PD.s.currentStep + 1);
	};

	PD.prevStep = function prevStep() {
		PD.flushPatientInput();
		PD.goToStep(PD.s.currentStep - 1);
	};

	/*
	 * Markup builders for the tool UI.
	 *
	 * The form is split into the sections a person
	 * actually thinks in — steps, patient, medicine, frequency, duration,
	 * notes, list, preview, actions.
	 *
	 * These functions
	 * only concatenate markup and never write state (startDateField() reads
	 * PD.s.startDate, so the chosen date survives a rebuild).
	 */

	/** Βήματα 1–2 στην κορυφή του παραθύρου. */
	function stepsNav() {
		return '<div class="plandose-steps" role="group" aria-label="' + PD.escapeAttr(PD.txt('stepsAria', 'Βήματα PlanDose')) + '">' +
			'<button type="button" class="plandose-step-dot active" data-step="1"><span>1</span><small>' + PD.escapeHtml(PD.stepTitle(1)) + '</small></button>' +
			'<button type="button" class="plandose-step-dot" data-step="2"><span>2</span><small>' + PD.escapeHtml(PD.stepTitle(2)) + '</small></button>' +
			'</div>';
	}

	/** Προαιρετικό όνομα ασθενή. */
	function patientField() {
		return '<div class="plandose-field plandose-field-patient">' +
			'<label for="pd-patient">' + PD.escapeHtml(PD.txt('patient', 'Ασθενής')) + '</label>' +
			'<input id="pd-patient" type="text" autocomplete="off" maxlength="' + PD.MAXLEN.patient + '" placeholder="' + PD.escapeAttr(PD.txt('patientPlaceholder', 'π.χ. Ιωάννης Παπαδόπουλος')) + '" />' +
			'<small>' + PD.escapeHtml(PD.txt('patientHelp', 'Προαιρετικό πεδίο. Θα εμφανιστεί στην εκτύπωση.')) + '</small>' +
			'</div>';
	}

	/**
	 * Ημερομηνία έναρξης του πλάνου. Προεπιλογή «Σήμερα», με τη
	 * σημερινή ημερομηνία ορατή στο πεδίο. Εσωτερικά το «σήμερα» φυλάσσεται
	 * ως '' ώστε να ακολουθεί το ρολόι (ένα παράθυρο ανοιχτό μετά τα
	 * μεσάνυχτα ξεκινά την καινούργια μέρα, όχι μια μέρα που πέρασε).
	 */
	function startDateField() {
		var chip = function (ahead, key, fallback) {
			var active = PD.startChipActive(ahead);
			return '<button type="button" class="pd-start-chip' + (active ? ' active' : '') + '" data-start="' + ahead + '" aria-pressed="' + (active ? 'true' : 'false') + '">' +
				PD.escapeHtml(PD.txt(key, fallback)) + '</button>';
		};
		return '<div class="plandose-field plandose-field-start">' +
			'<label for="pd-start-date">' + PD.escapeHtml(PD.txt('startDate', 'Ημερομηνία έναρξης')) + '</label>' +
			'<div class="pd-start-row">' +
			'<input id="pd-start-date" class="pd-start-input" type="date" min="' + PD.escapeAttr(PD.todayIso()) + '" max="' + PD.escapeAttr(PD.isoDateAhead(PD.MAX_START_AHEAD_DAYS)) + '" value="' + PD.escapeAttr(PD.startDateDisplayValue()) + '" aria-describedby="pd-start-help" />' +
			'<div class="pd-start-chips" role="group" aria-label="' + PD.escapeAttr(PD.txt('startDate', 'Ημερομηνία έναρξης')) + '">' +
			chip(0, 'startToday', 'Σήμερα') +
			chip(1, 'startTomorrow', 'Αύριο') +
			'</div>' +
			'</div>' +
			'<small id="pd-start-help">' + PD.escapeHtml(PD.txt('startDateHelp', 'Από ποια ημέρα ξεκινά το πλάνο.')) + '</small>' +
			'</div>';
	}

	/**
	 * What the date field shows: today's date for the default ("today",
	 * stored as ''), the chosen date otherwise, and nothing for an
	 * incomplete entry (the error message explains it).
	 */
	PD.startDateDisplayValue = function startDateDisplayValue() {
		if ('' === PD.s.startDate) {
			return PD.todayIso();
		}
		return null === PD.parseStartDate(PD.s.startDate) ? '' : PD.s.startDate;
	};

	/** Whether the «Σήμερα» (0) / «Αύριο» (1) chip matches the start date. */
	PD.startChipActive = function startChipActive(ahead) {
		if (0 === ahead) {
			return '' === PD.s.startDate;
		}
		return PD.s.startDate === PD.isoDateAhead(ahead);
	};

	/** Highlight the chip that matches the current start date, if any. */
	PD.updateStartChips = function updateStartChips() {
		document.querySelectorAll('.pd-start-chip').forEach(function (btn) {
			var active = PD.startChipActive(parseInt(btn.dataset.start, 10) || 0);
			btn.classList.toggle('active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	/** Μήνυμα λάθους για ένα πρόβλημα του PD.startDateProblem(). */
	PD.startDateMessage = function startDateMessage(problem) {
		if ('past' === problem) {
			return PD.txt('startDateInPast', 'Η ημερομηνία έναρξης έχει περάσει. Επιλέξτε σήμερα ή μεταγενέστερη ημέρα.');
		}
		if ('tooFar' === problem) {
			return PD.format(PD.txt('startDateTooFar', 'Η ημερομηνία έναρξης δεν μπορεί να απέχει πάνω από %d ημέρες από σήμερα.'), PD.MAX_START_AHEAD_DAYS);
		}
		return PD.txt('invalidStartDate', 'Η ημερομηνία έναρξης δεν είναι έγκυρη.');
	};

	/**
	 * Apply a new start date: it changes every date on the printout, so
	 * it invalidates a pending print token exactly like editing a drug.
	 */
	PD.setStartDate = function setStartDate(value) {
		var v = String(value == null ? '' : value).trim();
		/* Today's date, however it was chosen, is stored as '' so that it
		   keeps meaning "today" — see startDateField(). */
		if (v === PD.todayIso()) {
			v = '';
		}
		if (v === PD.s.startDate) {
			PD.updateStartChips();
			return;
		}
		PD.s.startDate = v;
		PD.updateStartChips();
		PD.updateFirstSlotVisibility();
		PD.invalidatePendingPrintToken();
		var problem = PD.startDateProblem(v);
		if (problem) {
			PD.setMessage(PD.startDateMessage(problem), 'error');
		} else {
			PD.clearMessage();
		}
		PD.updateDoseTotal();
		/* The drug cards show the first/last dose, which depend on it. */
		PD.renderMedicationList();
		PD.renderPreview();
		PD.updateStepButtons();
	};

	/**
	 * Σε ποιο μέρος της ημέρας έναρξης παίρνει ο ασθενής την πρώτη
	 * δόση. Οι δόσεις που πέφτουν νωρίτερα εκείνη την ημέρα δεν χάνονται:
	 * μεταφέρονται σε μία επιπλέον ημέρα στο τέλος, ώστε το σύνολο δόσεων
	 * να μένει ίδιο (βλ. skippedFirstDaySlots() στο preview.js).
	 */
	function firstSlotField() {
		var labels = PD.dailyTimeLabels();
		var current = PD.normalizeFirstSlot(PD.s.firstSlot);
		var chips = PD.SLOT_ORDER.map(function (key) {
			var active = key === current;
			return '<button type="button" class="plandose-chip pd-first-slot-chip' + (active ? ' active' : '') + '" data-first-slot="' + key + '" aria-pressed="' + (active ? 'true' : 'false') + '">' +
				PD.escapeHtml(labels[key]) + '</button>';
		}).join('');
		/* Shown only while the plan starts today (see effectiveFirstSlot()). */
		return '<div class="plandose-field plandose-field-first-slot"' + (PD.startsLater() ? ' hidden' : '') + '>' +
			'<label id="pd-first-slot-label">' + PD.escapeHtml(PD.txt('firstSlotLabel', 'Πρώτη δόση την ημέρα έναρξης')) + '</label>' +
			'<div class="plandose-chips pd-first-slot-chips" role="group" aria-labelledby="pd-first-slot-label" aria-describedby="pd-first-slot-help">' + chips + '</div>' +
			'<small id="pd-first-slot-help">' + PD.escapeHtml(PD.txt('firstSlotHelp', 'Οι δόσεις που έχουν ήδη περάσει μεταφέρονται στο τέλος — το σύνολο δόσεων δεν αλλάζει.')) + '</small>' +
			'</div>';
	}

	/** Show the first-dose field only while the plan starts today. */
	PD.updateFirstSlotVisibility = function updateFirstSlotVisibility() {
		var field = document.querySelector('.plandose-field-first-slot');
		if (field) {
			PD.beginDayPass();
			field.hidden = PD.startsLater();
		}
	};

	/** Highlight the chosen first-dose daypart. */
	PD.updateFirstSlotChips = function updateFirstSlotChips() {
		var current = PD.normalizeFirstSlot(PD.s.firstSlot);
		document.querySelectorAll('.pd-first-slot-chip').forEach(function (chip) {
			var active = chip.dataset.firstSlot === current;
			chip.classList.toggle('active', active);
			chip.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	/**
	 * Apply a new first-dose daypart. It changes the printed table, so it
	 * invalidates a pending print token exactly like editing a drug.
	 */
	PD.setFirstSlot = function setFirstSlot(value) {
		var slot = PD.normalizeFirstSlot(value);
		if (slot === PD.s.firstSlot) {
			PD.updateFirstSlotChips();
			return;
		}
		PD.s.firstSlot = slot;
		PD.updateFirstSlotChips();
		PD.invalidatePendingPrintToken();
		PD.updateDoseTotal();
		PD.renderMedicationList();
		PD.renderPreview();
		PD.updateStepButtons();
	};

	/**
	 * Όνομα φαρμάκου, ποσότητα και είδος δόσης. Και τα τρία στην
	 * ίδια γραμμή (το όνομα παίρνει τον υπόλοιπο χώρο)· σε στενή οθόνη το
	 * όνομα πάει σε δική του γραμμή και ποσότητα/είδος μοιράζονται την επόμενη.
	 */
	function drugFields() {
		return '<div class="pd-drug-row">' +
			'<div class="plandose-field plandose-field-drug">' +
			'<label for="pd-drug">' + PD.escapeHtml(PD.txt('drug', 'Όνομα φαρμάκου')) + '</label>' +
			'<input id="pd-drug" type="text" autocomplete="off" maxlength="' + PD.MAXLEN.drug + '" placeholder="' + PD.escapeAttr(PD.txt('drugPlaceholder', 'π.χ. Depon 500mg')) + '" />' +
			'</div>' +
			'<div class="plandose-field plandose-field-amount">' +
			'<label for="pd-dose-amount">' + PD.escapeHtml(PD.txt('doseAmount', 'Ποσότητα')) + '</label>' +
			/* Short text field; accepted forms in PD.checkDoseAmount(). */
			'<input id="pd-dose-amount" type="text" inputmode="decimal" maxlength="12" autocomplete="off" value="' + PD.escapeAttr(PD.DEFAULT_DOSE_AMOUNT) + '" placeholder="1" />' +
			'</div>' +
			'<div class="plandose-field plandose-field-unit">' +
			'<label for="pd-dose-unit">' + PD.escapeHtml(PD.txt('doseUnit', 'Είδος')) + '</label>' +
			'<select id="pd-dose-unit">' + PD.unitOptionsHtml() + '</select>' +
			'</div>' +
			'</div>';
	}

	/** Συχνότητα: τα 4 σταθερά chips, η ώρα λήψης για το 24h, και τα δύο εξειδικευμένα sub-modes. */
	function frequencyFields() {
		return '<div class="plandose-field">' +
			'<label id="pd-freq-label">' + PD.escapeHtml(PD.txt('frequency', 'Συχνότητα')) + '</label>' +
			'<div class="plandose-chips" id="pd-chips" role="group" aria-labelledby="pd-freq-label">' +
			'<button type="button" class="plandose-chip" data-val="24h">' + PD.escapeHtml(PD.txt('times1', 'Μία φορά την ημέρα')) + '</button>' +
			'<button type="button" class="plandose-chip active" data-val="12h">' + PD.escapeHtml(PD.txt('times2', '2 φορές την ημέρα')) + '</button>' +
			'<button type="button" class="plandose-chip" data-val="8h">' + PD.escapeHtml(PD.txt('times3', '3 φορές την ημέρα')) + '</button>' +
			'<button type="button" class="plandose-chip" data-val="6h">' + PD.escapeHtml(PD.txt('times4', '4 φορές την ημέρα')) + '</button>' +
			'<button type="button" class="plandose-chip plandose-chip-custom" data-val="custom">' + PD.escapeHtml(PD.txt('freqCustom', 'Εξειδικευμένη συχνότητα')) + '</button>' +
			'</div>' +
			'</div>' +
			'<div class="plandose-field" id="pd-daily-time-wrap" hidden>' +
			'<label id="pd-daily-time-label">' + PD.escapeHtml(PD.txt('dailyTimeLabel', 'Ώρα λήψης')) + '</label>' +
			'<div class="plandose-chips pd-daily-time-chips" id="pd-daily-time-chips" role="group" aria-labelledby="pd-daily-time-label">' +
			'<button type="button" class="plandose-chip pd-daily-time-chip active" data-time="morning">' + PD.escapeHtml(PD.txt('morning', 'Πρωί')) + '</button>' +
			'<button type="button" class="plandose-chip pd-daily-time-chip" data-time="noon">' + PD.escapeHtml(PD.txt('noon', 'Μεσημέρι')) + '</button>' +
			'<button type="button" class="plandose-chip pd-daily-time-chip" data-time="afternoon">' + PD.escapeHtml(PD.txt('afternoon', 'Απόγευμα')) + '</button>' +
			'<button type="button" class="plandose-chip pd-daily-time-chip" data-time="evening">' + PD.escapeHtml(PD.txt('evening', 'Βράδυ')) + '</button>' +
			'</div>' +
			'</div>' +
			'<div class="plandose-field" id="pd-custom-mode-wrap" hidden>' +
			'<label id="pd-custom-mode-label">' + PD.escapeHtml(PD.txt('customModeLabel', 'Τρόπος εξειδικευμένης συχνότητας')) + '</label>' +
			'<div class="plandose-chips pd-custom-mode-chips" id="pd-custom-mode-chips" role="group" aria-labelledby="pd-custom-mode-label">' +
			'<button type="button" class="plandose-chip pd-custom-mode-chip active" data-mode="days">' + PD.escapeHtml(PD.txt('customModeDays', 'Ανά ημέρες')) + '</button>' +
			'<button type="button" class="plandose-chip pd-custom-mode-chip" data-mode="weekday">' + PD.escapeHtml(PD.txt('customModeWeekday', 'Ανά ημέρα εβδομάδας')) + '</button>' +
			/* A real calendar month. */
			'<button type="button" class="plandose-chip pd-custom-mode-chip" data-mode="monthday">' + PD.escapeHtml(PD.txt('customModeMonthDay', 'Ημέρα του μήνα')) + '</button>' +
			'</div>' +
			'</div>' +
			'<div class="plandose-field" id="pd-custom-weekday-wrap" hidden>' +
			'<label id="pd-weekday-label">' + PD.escapeHtml(PD.txt('weekdayLabel', 'Ποια ημέρα;')) + '</label>' +
			'<div class="plandose-chips pd-weekday-chips" id="pd-weekday-chips" role="group" aria-labelledby="pd-weekday-label">' + PD.weekdayChipsHtml() + '</div>' +
			'</div>' +
			monthDayField();
	}

	/**
	 * «Ημέρα του μήνα» — the dose falls on that calendar day each
	 * month, or on the month's last day when the month is shorter. Empty
	 * until the pharmacist types a day.
	 */
	function monthDayField() {
		var md = PD.validMonthDay(PD.s.currentMonthDay);
		return '<div class="plandose-field" id="pd-custom-monthday-wrap" hidden>' +
			'<label for="pd-custom-monthday">' + PD.escapeHtml(PD.txt('monthDayLabel', 'Ποια ημέρα του μήνα; (1–31)')) + '</label>' +
			'<input id="pd-custom-monthday" type="number" min="1" max="31" step="1" inputmode="numeric" autocomplete="off" value="' + (null === md ? '' : md) + '" placeholder="' + PD.escapeAttr(PD.txt('monthDayPlaceholder', 'π.χ. 15')) + '" aria-describedby="pd-monthday-help pd-monthday-last" />' +
			'<small id="pd-monthday-help">' + PD.escapeHtml(PD.txt('monthDayHelp', 'Η δόση πέφτει αυτή την ημέρα κάθε μήνα.')) + '</small>' +
			'<small id="pd-monthday-last" class="pd-monthday-last" role="note" hidden></small>' +
			'</div>';
	}

	/** Κάθε πόσες ημέρες (μόνο στο «Ανά ημέρες»), σε δική του γραμμή. */
	function intervalField() {
		return '<div class="plandose-field" id="pd-custom-days-wrap" hidden>' +
			'<label for="pd-custom-interval-days">' + PD.escapeHtml(PD.txt('customIntervalLabel', 'Κάθε πόσες ημέρες;')) + '</label>' +
			'<input id="pd-custom-interval-days" type="number" min="1" max="90" inputmode="numeric" placeholder="' + PD.escapeAttr(PD.txt('customIntervalPlaceholder', 'π.χ. 7')) + '" />' +
			'<div class="pd-interval-presets">' +
			'<button type="button" class="pd-interval-preset" data-days="7">' + PD.escapeHtml(PD.txt('presetWeekly', 'Εβδομάδα (7)')) + '</button>' +
			'<button type="button" class="pd-interval-preset" data-days="14">' + PD.escapeHtml(PD.txt('presetBiweekly', '2 εβδ. (14)')) + '</button>' +
			'<button type="button" class="pd-interval-preset" data-days="15">' + PD.escapeHtml(PD.txt('preset15', '15 ημ.')) + '</button>' +
			/* 30 days is not «a month» (see «Ημέρα του μήνα»). */
			'<button type="button" class="pd-interval-preset" data-days="30">' + PD.escapeHtml(PD.txt('preset30', '30 ημ.')) + '</button>' +
			'</div>' +
			'</div>';
	}

	/**
	 * Διάρκεια και Σημειώσεις στην ίδια γραμμή (στενή η διάρκεια,
	 * οι σημειώσεις παίρνουν τον υπόλοιπο χώρο· σε κινητό σπάει σε δύο
	 * γραμμές), μετά η ζωντανή γραμμή συνόλου δόσεων και το κουμπί
	 * προσθήκης.
	 */
	function durationNotesAndAdd() {
		return '<div class="pd-duration-notes-row">' +
			'<div class="plandose-field plandose-field-duration">' +
			'<label for="pd-days">' + PD.escapeHtml(PD.txt('duration', 'Διάρκεια (ημέρες)')) + '</label>' +
			'<input id="pd-days" type="number" min="1" max="' + PD.MAX_DAYS + '" inputmode="numeric" />' +
			'</div>' +
			'<div class="plandose-field plandose-field-notes">' +
			'<label for="pd-notes">' + PD.escapeHtml(PD.txt('notes', 'Σημειώσεις')) + '</label>' +
			'<textarea id="pd-notes" rows="1" maxlength="' + PD.MAXLEN.notes + '"' + (PD.PRO_LABELS ? ' aria-describedby="pd-notes-label-count"' : '') + ' placeholder="' + PD.escapeAttr(PD.txt('notesPlaceholder', 'π.χ. Να λαμβάνεται μετά το φαγητό')) + '"></textarea>' +
			/* Pro: the label prints at most LABEL_NOTES_MAX characters of
			   the notes; the A4 plan always prints them in full. */
			(PD.PRO_LABELS ? '<small id="pd-notes-label-count" class="plandose-notes-count"></small>' : '') +
			'</div>' +
			'</div>' +
			'<p id="pd-dose-total" class="pd-dose-total" aria-live="polite" hidden></p>' +
			/* «Καθαρισμός» next to «Προσθήκη» — the way out of a
			   typed-but-unwanted medicine (or an edit), since Preview and
			   Print refuse to leave one behind. */
			'<div class="pd-add-row">' +
			'<button type="button" id="pd-add" class="plandose-btn plandose-btn-secondary">' + PD.escapeHtml(PD.txt('add', 'Προσθήκη Φαρμάκου')) + '</button>' +
			'<button type="button" id="pd-clear-form" class="plandose-btn plandose-btn-light pd-clear-form" aria-label="' + PD.escapeAttr(PD.txt('clearFormAria', 'Καθαρισμός φόρμας φαρμάκου')) + '">' + PD.escapeHtml(PD.txt('clearForm', 'Καθαρισμός')) + '</button>' +
			'</div>';
	}

	/** Η λίστα των φαρμάκων που έχουν ήδη προστεθεί. */
	function addedDrugsList() {
		return '<div class="plandose-added-wrap">' +
			'<h3 class="plandose-section-title">' + PD.escapeHtml(PD.txt('addedDrugs', 'Φάρμακα στο πλάνο')) + '</h3>' +
			'<div id="pd-rows"></div>' +
			'</div>';
	}

	/** Βήμα 2: έλεγχος πλάνου και προεπισκόπηση εκτύπωσης. */
	function previewStep() {
		return '<section class="plandose-step-panel" data-pd-step="2" hidden>' +
			'<div class="plandose-review">' +
			'<h3 class="plandose-section-title">' + PD.escapeHtml(PD.txt('scheduleReviewTitle', 'Έλεγχος πλάνου')) + '</h3>' +
			'<p>' + PD.escapeHtml(PD.txt('scheduleReviewText', 'Ελέγξτε τα φάρμακα πριν την εκτύπωση.')) + '</p>' +
			'<div id="pd-rows-review"></div>' +
			'</div>' +
			'<div class="plandose-preview-head">' +
			'<h3 class="plandose-section-title">' + PD.escapeHtml(PD.txt('previewTitle', 'Προεπισκόπηση Εκτύπωσης')) + '</h3>' +
			'<p>' + PD.escapeHtml(PD.txt('previewText', 'Αυτό είναι το πλάνο που θα εκτυπωθεί.')) + '</p>' +
			'</div>' +
			'<div id="pd-preview-area" class="plandose-preview-area"></div>' +
			'</section>';
	}

	/**
	 * Η υποδοχή του μετρητή εκτυπώσεων. Παραμένει κενή όταν ο διαχειριστής
	 * έχει απενεργοποιήσει την εμφάνισή του στις Ρυθμίσεις.
	 */
	function printCounterSlot() {
		if (!PD.config.showPrintCounter) {
			return '';
		}
		return '<p id="pd-print-counter" class="plandose-print-counter" aria-live="polite"></p>';
	}

	/** Μετρητής εκτυπώσεων, μήνυμα κατάστασης και τα κουμπιά ενεργειών. */
	function actionBar() {
		return '<p id="pd-message" class="pd-message" role="status" aria-live="polite"></p>' +
			'<div class="plandose-actionbar">' +
			printCounterSlot() +
			'<p id="pd-print-hint" class="plandose-print-hint">' + PD.escapeHtml(PD.txt('printCreditHint', 'Η εκτύπωση μετράει μόλις ανοίξει το παράθυρο εκτύπωσης, ακόμη κι αν πατήσετε Ακύρωση.')) + '</p>' +
			'<div class="plandose-actionbar-buttons">' +
			'<button type="button" id="pd-prev-step" class="plandose-btn plandose-btn-light">' + PD.escapeHtml(PD.txt('back', 'Πίσω')) + '</button>' +
			'<button type="button" id="pd-next-step" class="plandose-btn plandose-btn-secondary">' + PD.escapeHtml(PD.txt('preview', 'Προεπισκόπηση')) + '</button>' +
			'<button type="button" id="pd-print" class="plandose-btn plandose-btn-primary">' + PD.escapeHtml(PD.txt('print', 'Εκτύπωση Πλάνου')) + '</button>' +
			/* Shown only after a print, while the plan is unchanged. */
			'<button type="button" id="pd-new-patient" class="plandose-btn plandose-btn-secondary plandose-btn-new-patient" hidden>' + PD.escapeHtml(PD.txt('newPatient', 'Νέος ασθενής')) + '</button>' +
			/* Pro only: labels go to the label printer on their own. */
			(PD.PRO_LABELS ? '<button type="button" id="pd-print-labels" class="plandose-btn plandose-btn-secondary plandose-btn-labels"><span aria-hidden="true">🏷️ </span>' + PD.escapeHtml(PD.txt('printLabels', 'Εκτύπωση Ετικετών')) + '</button>' : '') +
			'</div>' +
			'</div>';
	}

	/** Η ενότητα «Φάρμακα»: όλα τα πεδία ενός φαρμάκου. */
	function medicineSection() {
		return '<section class="plandose-section">' +
			'<h3 class="plandose-section-title">' + PD.escapeHtml(PD.txt('drugSection', 'Φάρμακα')) + '</h3>' +
			/* Filled by PD.updateRxSource() (textContent only). */
			'<div id="pd-rx-source" class="pd-rx-source" role="note" hidden>' +
			'<strong>' + PD.escapeHtml(PD.txt('rxFromPrescription', 'Από τη συνταγή:')) + '</strong>' +
			'<span class="pd-rx-source-text"></span>' +
			'</div>' +
			drugFields() +
			frequencyFields() +
			intervalField() +
			durationNotesAndAdd() +
			'</section>';
	}

	/** Βήμα 1: στοιχεία ασθενή και φάρμακα. */
	function planStep() {
		return '<section class="plandose-step-panel" data-pd-step="1">' +
			/* «Επικόλληση συνταγής» (assets/js/rx-import.js). */
			(typeof PD.rxImportHtml === 'function' ? PD.rxImportHtml() : '') +
			/* Patient and start date share one row; the row wraps
			   to two lines when it is too narrow (phones). */
			'<div class="pd-patient-start-row">' +
			patientField() +
			startDateField() +
			'</div>' +
			firstSlotField() +
			medicineSection() +
			addedDrugsList() +
			'</section>';
	}

	PD.buildApp = function buildApp() {
		PD.app.classList.remove('plandose-guest-app');
		PD.app.innerHTML = '<div class="plandose-v2">' +
			stepsNav() +
			planStep() +
			previewStep() +
			actionBar() +
			'</div>';
		PD.wireAppEvents();
		if (typeof PD.wireRxImport === 'function') {
			PD.wireRxImport();
		}
		/* The chip markup carries only the "active" class; these set
		   aria-pressed on every chip from PD.s right away, so a screen
		   reader hears which frequency is chosen before anything is
		   clicked (and the chips always match the state after a rebuild). */
		PD.updateFreqChips();
		PD.updateDailyTimeChips();
		PD.updateCustomModeChips();
		PD.updateWeekdayChips();
		PD.updateMonthDayField();
		PD.updateCustomFieldsVisibility();
		PD.updateRxSource();
		PD.renderMedicationList();
		PD.renderPreview();
		PD.renderPrintCounter();
		PD.updateStepsUi();
		PD.updateDoseTotal();
		PD.updateNotesCount();
		/* #pd-message is rebuilt empty; the billing line under it
		   (print.js) must survive a rebuild such as a language switch. */
		if (PD.renderBillingStatus) {
			PD.renderBillingStatus();
		}
	};

	/**
	 * Live "how much will the patient need" line under the duration
	 * field.
	 *
	 * It reads the form as it stands right now (not the saved items), so
	 * the pharmacist sees the box count update while choosing frequency
	 * and duration, before pressing Προσθήκη. The drug name is
	 * deliberately not required — the arithmetic does not depend on it.
	 */
	/**
	 * «Σύνολο: N δόσεις • M Δισκία» («1 δόση» in the singular).
	 * Shared by the form and the prescription review.
	 */
	PD.doseTotalText = function doseTotalText(totals, unit) {
		var doses = PD.formatNumber(totals.doses);
		var one = 1 === totals.doses;
		if (null === totals.units) {
			return one
				? PD.format(PD.txt('doseTotalOneDoseOnly', 'Σύνολο: %1$s δόση'), doses)
				: PD.format(PD.txt('doseTotalDosesOnly', 'Σύνολο: %1$s δόσεις'), doses);
		}
		return PD.format(
			one ? PD.txt('doseTotalLineOne', 'Σύνολο: %1$s δόση • %2$s %3$s') : PD.txt('doseTotalLine', 'Σύνολο: %1$s δόσεις • %2$s %3$s'),
			doses,
			PD.formatNumber(totals.units),
			PD.unitLabel(unit)
		);
	};

	PD.updateDoseTotal = function updateDoseTotal() {
		var el = document.getElementById('pd-dose-total');
		if (!el) {
			return;
		}
		/* A fresh day pass, so the total follows the current start date
		   and the current calendar day rather than the last render's. */
		PD.beginDayPass();
		var totals = PD.doseTotals(PD.collectDrugForm());
		if (!totals || !totals.doses) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		var text = PD.doseTotalText(totals, PD.currentDoseUnit());
		var form = PD.collectDrugForm();
		/* Weekly, monthly and every-N-days: the actual dates. */
		var dates = PD.doseDatesText(form);
		if (dates) {
			text += ' — ' + dates;
		}
		/* Say so when doses carry over to the extra day. */
		var course = PD.courseLine(form);
		if (course) {
			text += ' — ' + course;
		}
		el.textContent = text;
		el.hidden = false;
	};

	PD.wireAppEvents = function wireAppEvents() {
		document.querySelectorAll('.plandose-step-dot').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var requestedStep = parseInt(btn.dataset.step, 10);
				/* Same rule as «Προεπισκόπηση» (see nextStep()). */
				if (requestedStep > 1 && PD.s.currentStep === 1 && PD.blockIfUnsavedDrug()) {
					return;
				}
				if (requestedStep > 1 && PD.s.items.length === 0) {
					PD.setMessage(PD.txt('addAtLeastOne', 'Προσθέστε τουλάχιστον ένα φάρμακο.'), 'error');
					return;
				}
				PD.goToStep(requestedStep);
			});
		});
		document.querySelectorAll('.plandose-chip[data-val]').forEach(function (chip) {
			chip.addEventListener('click', function () {
				PD.s.currentFreq = chip.dataset.val || '12h';
				PD.s.rxNeedsFreq = false;
				PD.updateFreqChips();
				PD.updateCustomFieldsVisibility();
				PD.updateDoseTotal();
			});
		});
		document.querySelectorAll('.pd-custom-mode-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				PD.s.currentCustomMode = chip.dataset.mode || 'days';
				PD.updateCustomModeChips();
				PD.updateCustomFieldsVisibility();
				PD.updateDoseTotal();
			});
		});
		document.querySelectorAll('.pd-daily-time-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				PD.s.currentDailyTime = PD.normalizeDailyTime(chip.dataset.time);
				PD.updateDailyTimeChips();
				PD.updateDoseTotal();
			});
		});
		document.querySelectorAll('.pd-weekday-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				PD.s.currentWeekday = PD.validWeekday(chip.dataset.weekday);
				PD.updateWeekdayChips();
				PD.updateDoseTotal();
			});
		});
		var monthDayEl = document.getElementById('pd-custom-monthday');
		if (monthDayEl) {
			['input', 'change'].forEach(function (type) {
				monthDayEl.addEventListener(type, function () {
					/* Anything but a whole 1–31 leaves the day unchosen. */
					PD.s.currentMonthDay = PD.validMonthDay(monthDayEl.value);
					PD.updateMonthDayHelp();
					PD.updateDoseTotal();
				});
			});
		}
		document.querySelectorAll('.pd-interval-preset').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById('pd-custom-interval-days');
				var days = parseInt(btn.dataset.days, 10);
				if (!input || !days) {
					return;
				}
				input.value = days;
				input.focus();
				PD.updateDoseTotal();
			});
		});
		var startEl = document.getElementById('pd-start-date');
		if (startEl) {
			['input', 'change'].forEach(function (type) {
				startEl.addEventListener(type, function () {
					/* A half-typed or half-deleted date (e.g. «ηη/09/2026»)
					   reports value '' — which would silently mean "today".
					   badInput tells the two apart, so an incomplete date is
					   an error that blocks adding and printing, not today. */
					if (startEl.validity && startEl.validity.badInput) {
						PD.setStartDate(PD.START_DATE_INCOMPLETE);
						return;
					}
					PD.setStartDate(startEl.value);
				});
			});
			/* min/max and the shown "today" were computed when the form was
			   built; refresh them so a popup left open past midnight shows
			   and offers the right days. */
			startEl.addEventListener('focus', function () {
				startEl.min = PD.todayIso();
				startEl.max = PD.isoDateAhead(PD.MAX_START_AHEAD_DAYS);
				if ('' === PD.s.startDate && !(startEl.validity && startEl.validity.badInput)) {
					startEl.value = PD.todayIso();
				}
			});
			/* A field emptied completely means "today": show today's date
			   again once the pharmacist leaves it. */
			startEl.addEventListener('blur', function () {
				if ('' === PD.s.startDate && '' === startEl.value && !(startEl.validity && startEl.validity.badInput)) {
					startEl.value = PD.todayIso();
				}
			});
		}
		document.querySelectorAll('.pd-start-chip').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById('pd-start-date');
				var ahead = parseInt(btn.dataset.start, 10) || 0;
				var value = PD.isoDateAhead(ahead);
				if (input) {
					input.value = value;
				}
				PD.setStartDate(value);
			});
		});
		document.querySelectorAll('.pd-first-slot-chip').forEach(function (btn) {
			btn.addEventListener('click', function () {
				PD.setFirstSlot(btn.dataset.firstSlot);
			});
		});
		var addBtn = document.getElementById('pd-add');
		var drugEl = document.getElementById('pd-drug');
		var daysEl = document.getElementById('pd-days');
		var patientEl = document.getElementById('pd-patient');
		var prevBtn = document.getElementById('pd-prev-step');
		var nextBtn = document.getElementById('pd-next-step');
		var printBtn = document.getElementById('pd-print');
		if (addBtn) {
			addBtn.addEventListener('click', PD.addOrUpdateItem);
		}
		var clearBtn = document.getElementById('pd-clear-form');
		if (clearBtn) {
			clearBtn.addEventListener('click', PD.clearDrugForm);
		}
		['pd-dose-amount', 'pd-dose-unit', 'pd-days', 'pd-custom-interval-days'].forEach(function (id) {
			var el = document.getElementById(id);
			if (!el) {
				return;
			}
			el.addEventListener('input', PD.updateDoseTotal);
			el.addEventListener('change', PD.updateDoseTotal);
		});
		[drugEl, daysEl].forEach(function (el) {
			if (!el) {
				return;
			}
			el.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					PD.addOrUpdateItem();
				}
			});
		});
		if (patientEl) {
			patientEl.addEventListener('input', function () {
				// The patient name is printed content (see getPatientName()
				// in the print HTML), so changing it must invalidate any
				// pending print token — reusing a stale token after editing
				// the patient would let the server treat this as a
				// duplicate of the OLD print and silently not count the
				// new one. See invalidatePendingPrintToken()'s own docs.
				//
				// Except while the plan on screen is a printed one.
				// Its token then stays; handlePrint() compares the whole
				// sheet (name included) with what was paid for and only
				// reuses the token when they match, so typing a letter and
				// deleting it again does not turn «Εκτύπωση ξανά» into a
				// charged print.
				var printedOnScreen = !!PD.s.pendingPrintToken &&
					PD.s.pendingPrintToken === PD.s.lastPrintToken &&
					null !== PD.s.confirmedPrintHtml;
				if (!printedOnScreen) {
					PD.invalidatePendingPrintToken();
				}
				/* The preview and the buttons (which rebuild the
				   whole sheet) follow the typing after a short pause
				   instead of on every key — on a 60-day plan with ten
				   medicines each rebuild takes a noticeable fraction of a
				   second. Anything that prints or changes step flushes
				   first (PD.flushPatientInput()). */
				PD.schedulePatientRender();
			});
			patientEl.addEventListener('change', function () {
				PD.flushPatientInput();
			});
		}
		if (prevBtn) {
			prevBtn.addEventListener('click', PD.prevStep);
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', PD.nextStep);
		}
		if (printBtn) {
			printBtn.addEventListener('click', PD.handlePrint);
		}
		var newPatientBtn = document.getElementById('pd-new-patient');
		if (newPatientBtn) {
			newPatientBtn.addEventListener('click', function () {
				PD.resetForNextPatient('manual');
			});
		}
		var labelsBtn = document.getElementById('pd-print-labels');
		if (labelsBtn && typeof PD.handlePrintLabels === 'function') {
			labelsBtn.addEventListener('click', PD.handlePrintLabels);
		}
		var notesEl = document.getElementById('pd-notes');
		if (notesEl) {
			notesEl.addEventListener('input', PD.updateNotesCount);
		}
	};

	/*
	 * Re-render the open tool in the newly-selected language. Called by
	 * PD.setLang() (state.js) after the active dictionary is swapped.
	 *
	 * buildApp() rebuilds the entire form from PD.t and re-wires all its
	 * events, so added drugs (PD.s.items) and the current step survive
	 * automatically. What it does NOT preserve is the in-progress,
	 * not-yet-"Added" input — so we snapshot those transient fields and
	 * restore them, and re-apply the active frequency/mode/daily-time
	 * chips from PD.s (buildApp resets them to the markup defaults).
	 */
	PD.rerenderForLang = function rerenderForLang() {
		if (PD.overlay.hidden) {
			return;
		}
		/* Belt-and-braces: a visitor who is not an approved
		   pharmacist is served plandose-guest.js instead of the tool
		   modules, so this file never even runs for them and the guest view
		   is rendered server-side by render_modal(). If the enqueue rules
		   ever change, bail out quietly rather than rebuilding the tool
		   form for someone who is not allowed to use it. */
		if (!PD.config.isAllowed) {
			return;
		}

		var fieldIds = [
			'pd-patient',
			'pd-drug',
			'pd-dose-amount',
			'pd-dose-unit',
			'pd-notes',
			'pd-days',
			'pd-custom-interval-days',
			'pd-custom-monthday'
		];
		var snapshot = {};
		/* A type=number field holding text it cannot parse (e.g. «επτά»
		   in «Ημέρες») reports value '' and only validity.badInput says
		   something is there. Copying '' into the rebuilt field would turn
		   "unreadable input" into "empty" on a language switch, and the
		   raw text cannot be written back into a number field. So such a
		   field keeps its OLD element (raw text and badInput intact),
		   re-labelled with the new markup's attributes. Its listeners
		   still work: they call PD.* or read the element itself. */
		var keepNodes = {};
		fieldIds.forEach(function (id) {
			var el = document.getElementById(id);
			if (el) {
				snapshot[id] = el.value;
				if (el.type === 'number' && el.validity && el.validity.badInput) {
					keepNodes[id] = el;
				}
			}
		});

		PD.buildApp();

		fieldIds.forEach(function (id) {
			var el = document.getElementById(id);
			if (!el) {
				return;
			}
			var old = keepNodes[id];
			if (old && el.parentNode) {
				Array.prototype.slice.call(old.attributes).forEach(function (attr) {
					if (!el.hasAttribute(attr.name) && 'value' !== attr.name) {
						old.removeAttribute(attr.name);
					}
				});
				Array.prototype.slice.call(el.attributes).forEach(function (attr) {
					if ('value' !== attr.name) {
						old.setAttribute(attr.name, attr.value);
					}
				});
				el.parentNode.replaceChild(old, el);
				return;
			}
			if (typeof snapshot[id] !== 'undefined') {
				el.value = snapshot[id];
			}
		});

		PD.updateNotesCount();
		/* The totals line is built from the fields, which were
		   empty when buildApp() computed it; recompute from the restored
		   values (and in the new language's number format). */
		PD.updateDoseTotal();

		if (PD.s.editingIndex !== null) {
			var addBtn = document.getElementById('pd-add');
			if (addBtn) {
				addBtn.textContent = PD.txt('saveDrug', 'Αποθήκευση Φαρμάκου');
			}
		}

		PD.updateFreqChips();
		PD.updateCustomModeChips();
		PD.updateDailyTimeChips();
		PD.updateWeekdayChips();
		PD.updateMonthDayHelp();
		PD.updateCustomFieldsVisibility();

		PD.applyHeaderLang();
		PD.renderPreview();

		/* buildApp() rebuilds #pd-message empty, which silently
		   drops a start-date error still in force — show it again. */
		var problem = PD.startDateProblem(PD.s.startDate);
		if (problem) {
			PD.flagFieldError('pd-start-date', PD.startDateMessage(problem), false, true);
		}
	};

	/*
	 * Non-Pro users can see and click the locked toggle; instead of
	 * switching language we briefly reveal its "available in Pro" hint.
	 */
	PD.showLangProHint = function showLangProHint(btn) {
		if (!btn) {
			return;
		}
		btn.classList.add('show-hint');
		window.clearTimeout(PD.s.langHintTimer);
		PD.s.langHintTimer = window.setTimeout(function () {
			btn.classList.remove('show-hint');
			PD.s.langHintTimer = null;
		}, 1800);
	};

	/* ---- bootstrap: runs last, after every module has registered ---- */

	if (PD.config.buttonColor) {
		/* backgroundColor (not the background shorthand) so a non-color value
		   is ignored by the browser rather than allowing a gradient/url();
		   defense-in-depth on top of the server-side sanitize_hex_color(). */
		PD.trigger.style.backgroundColor = PD.config.buttonColor;
	}

	if (PD.config.buttonPosition === 'bottom_left') {
		PD.trigger.style.left = 'max(24px, env(safe-area-inset-left))';
		PD.trigger.style.right = 'auto';
	}

	/* A page that injects the bundle twice runs this block twice,
	   and two Escape handlers would open the close prompt and remove it again
	   at once. state.js keeps the first PD, so a flag on it makes the
	   bindings one-time. */
	if (!PD.s.bootstrapBound) {
		PD.s.bootstrapBound = true;

		PD.trigger.addEventListener('click', PD.openModal);

		PD.closeBtn.addEventListener('click', PD.requestCloseModal);

		var langToggle = document.getElementById('plandose-lang-toggle');
		if (langToggle) {
			langToggle.addEventListener('click', function () {
				if (!PD.IS_PRO) {
					PD.showLangProHint(langToggle);
					return;
				}
				PD.setLang(PD.lang === 'el' ? 'en' : 'el');
			});
		}

		document.addEventListener('keydown', function (e) {
			/* 'Esc' is the legacy key name still reported by older Edge/IE.
			   assets/js/plandose-guest.js has always accepted both; the tool
			   bundle accepted only 'Escape', so on those browsers a guest could
			   close the popup with the keyboard and a pharmacist could not. */
			if ((e.key === 'Escape' || e.key === 'Esc') && !PD.overlay.hidden) {
				if (document.getElementById('plandose-confirm-close')) {
					PD.removeConfirmClose();
					return;
				}
				PD.requestCloseModal();
			}
		});
	}
})();