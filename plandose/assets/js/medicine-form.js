/**
 * PlanDose — medicine-form.js
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

	PD.getPatientName = function getPatientName() {
		var el = document.getElementById('pd-patient');
		return el ? el.value.trim() : '';
	};

	PD.currentDoseAmount = function currentDoseAmount() {
		var el = document.getElementById('pd-dose-amount');
		return el ? el.value.trim() : '';
	};

	PD.currentDoseUnit = function currentDoseUnit() {
		var el = document.getElementById('pd-dose-unit');
		return el ? el.value : 'tablet';
	};

	PD.currentCustomIntervalDays = function currentCustomIntervalDays() {
		var el = document.getElementById('pd-custom-interval-days');
		return el ? el.value.trim() : '';
	};

	PD.currentDrugName = function currentDrugName() {
		var el = document.getElementById('pd-drug');
		return el ? el.value.trim() : '';
	};

	PD.currentDays = function currentDays() {
		var el = document.getElementById('pd-days');
		return el ? parseInt(el.value, 10) : 0;
	};

	PD.currentNotes = function currentNotes() {
		var el = document.getElementById('pd-notes');
		return el ? el.value.trim() : '';
	};

	/** The dose quantity a fresh form starts with (see resetDrugForm()). */
	PD.DEFAULT_DOSE_AMOUNT = '1';

	/**
	 * True when the medicine form holds something the pharmacist
	 * typed that is NOT in the plan yet — a drug name, a dose other than
	 * the default 1, a duration, notes or an interval — or when a drug is
	 * loaded for editing and not saved. Preview and Print refuse to go on
	 * while it is true (see PD.blockIfUnsavedDrug()), so a typed-but-not-
	 * added medicine can never silently drop off the printed plan.
	 *
	 * The unit and frequency chips are not counted: they always hold some
	 * value, and choosing one without typing anything loses nothing.
	 */
	PD.hasUnsavedDrugForm = function hasUnsavedDrugForm() {
		if (PD.s.editingIndex !== null) {
			return true;
		}
		if (!document.getElementById('pd-drug')) {
			return false;
		}
		if (PD.currentDrugName()) {
			return true;
		}
		var dose = PD.currentDoseAmount();
		if ('' !== dose && dose !== PD.DEFAULT_DOSE_AMOUNT && PD.parseDoseAmount(dose) !== 1) {
			return true;
		}
		var daysEl = document.getElementById('pd-days');
		/* A type=number field reports '' for text it cannot parse (e.g. "επτά");
		   validity.badInput still says something was typed there. */
		if (daysEl && ('' !== daysEl.value.trim() || (daysEl.validity && daysEl.validity.badInput))) {
			return true;
		}
		var monthDayEl = document.getElementById('pd-custom-monthday');
		if (monthDayEl && '' !== monthDayEl.value.trim()) {
			return true;
		}
		return '' !== PD.currentNotes() || '' !== PD.currentCustomIntervalDays();
	};

	/**
	 * «Καθαρισμός» — empty the medicine form without touching the
	 * plan. While editing, this cancels the edit: the drug in the plan
	 * stays exactly as it was saved.
	 */
	PD.clearDrugForm = function clearDrugForm() {
		var wasEditing = PD.s.editingIndex !== null;
		PD.resetDrugForm();
		PD.setMessage(wasEditing
			? PD.txt('editCancelled', 'Η επεξεργασία ακυρώθηκε — το φάρμακο στο πλάνο δεν άλλαξε.')
			: PD.txt('formCleared', 'Η φόρμα φαρμάκου καθαρίστηκε.'), 'success');
		var drugEl = document.getElementById('pd-drug');
		if (drugEl) {
			drugEl.focus();
		}
	};

	PD.setFieldValue = function setFieldValue(id, value) {
		var el = document.getElementById(id);
		if (el) {
			el.value = value == null ? '' : value;
		}
		if ('pd-notes' === id) {
			PD.updateNotesCount();
		}
	};

	/**
	 * Pro: the hint under the notes field. How much of the
	 * notes fits depends on the label size (pro-labels.js uses the free
	 * space on the fixed sizes), so no fixed number is promised; the label
	 * print asks for confirmation when notes are cut. The A4 plan always
	 * prints them in full. Safe to call when the hint is absent (non-Pro).
	 */
	PD.updateNotesCount = function updateNotesCount() {
		var counter = document.getElementById('pd-notes-label-count');
		if (!counter) {
			return;
		}
		counter.textContent = PD.currentNotes()
			? PD.txt('notesLabelHint', 'Στις ετικέτες μπορεί να χωρέσει μέρος των σημειώσεων· θα ζητηθεί επιβεβαίωση αν κοπούν. Στο πλάνο τυπώνονται ολόκληρες.')
			: '';
		counter.classList.remove('is-over');
	};

	PD.resetDrugForm = function resetDrugForm() {
		PD.setFieldValue('pd-drug', '');
		PD.setFieldValue('pd-dose-amount', PD.DEFAULT_DOSE_AMOUNT);
		PD.setFieldValue('pd-dose-unit', 'tablet');
		PD.setFieldValue('pd-days', '');
		PD.setFieldValue('pd-notes', '');
		PD.setFieldValue('pd-custom-interval-days', '');
		PD.s.currentFreq = '12h';
		PD.s.rxNeedsFreq = false;
		PD.s.currentCustomMode = 'days';
		PD.s.currentDailyTime = 'morning';
		PD.s.currentWeekday = null;
		PD.s.currentMonthDay = null;
		PD.s.rxSourceText = '';
		PD.s.doseQtyAck = '';
		PD.s.editingIndex = null;
		PD.updateFreqChips();
		PD.updateCustomModeChips();
		PD.updateDailyTimeChips();
		PD.updateWeekdayChips();
		PD.updateMonthDayField();
		PD.updateCustomFieldsVisibility();
		PD.updateRxSource();
		var addBtn = document.getElementById('pd-add');
		if (addBtn) {
			addBtn.textContent = PD.txt('add', 'Προσθήκη Φαρμάκου');
		}
		if (PD.updateDoseTotal) {
			PD.updateDoseTotal();
		}
	};

	PD.updateFreqChips = function updateFreqChips() {
		document.querySelectorAll('.plandose-chip[data-val]').forEach(function (chip) {
			/* No chip is lit while a prescription's frequency
			   still has to be chosen (PD.s.rxNeedsFreq). */
			if (chip.dataset.val === PD.s.currentFreq && !PD.s.rxNeedsFreq) {
				chip.classList.add('active');
				chip.setAttribute('aria-pressed', 'true');
			} else {
				chip.classList.remove('active');
				chip.setAttribute('aria-pressed', 'false');
			}
		});
	};

	PD.updateCustomModeChips = function updateCustomModeChips() {
		document.querySelectorAll('.pd-custom-mode-chip').forEach(function (chip) {
			var active = chip.dataset.mode === PD.s.currentCustomMode;
			chip.classList.toggle('active', active);
			chip.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	/**
	 * Highlight the chosen weekday chip in the \"by weekday\" custom mode.
	 * data-weekday holds a getDay() value (0..6) so it compares against
	 * PD.s.currentWeekday directly.
	 */
	PD.updateWeekdayChips = function updateWeekdayChips() {
		/* None is lit until a day is chosen. */
		var current = PD.validWeekday(PD.s.currentWeekday);
		document.querySelectorAll('.pd-weekday-chip').forEach(function (chip) {
			var active = parseInt(chip.dataset.weekday, 10) === current;
			chip.classList.toggle('active', active);
			chip.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	/**
	 * Only relevant for the '24h' (once-daily) frequency: which part of the
	 * day the single daily dose is taken (Πρωί/Μεσημέρι/Απόγευμα/Βράδυ),
	 * instead of it always defaulting to "Πρωί".
	 */
	PD.updateDailyTimeChips = function updateDailyTimeChips() {
		document.querySelectorAll('.pd-daily-time-chip').forEach(function (chip) {
			var active = chip.dataset.time === PD.s.currentDailyTime;
			chip.classList.toggle('active', active);
			chip.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	};

	/*
	 * There is no "Ανά ώρες" mode. The four fixed chips
	 * cover 1–4 doses a day, which is everything the printed table can
	 * express — it labels rows with dayparts (Πρωί / Μεσημέρι /
	 * Απόγευμα / Βράδυ), and there are only four of those. Real clock
	 * times would be both a different kind
	 * of instruction and one that drifts day by day whenever the
	 * interval doesn't divide 24 exactly (every 5 hours → 4.8 doses a
	 * day, so the printed times would be wrong from day two onward).
	 */
	PD.updateCustomFieldsVisibility = function updateCustomFieldsVisibility() {
		var modeWrap = document.getElementById('pd-custom-mode-wrap');
		var daysWrap = document.getElementById('pd-custom-days-wrap');
		var weekdayWrap = document.getElementById('pd-custom-weekday-wrap');
		var dailyTimeWrap = document.getElementById('pd-daily-time-wrap');
		var isCustom = PD.s.currentFreq === 'custom';
		var isDaysMode = isCustom && PD.s.currentCustomMode === 'days';
		var isWeekdayMode = isCustom && PD.s.currentCustomMode === 'weekday';
		var monthDayWrap = document.getElementById('pd-custom-monthday-wrap');
		if (monthDayWrap) {
			monthDayWrap.hidden = !(isCustom && PD.s.currentCustomMode === 'monthday');
		}
		if (modeWrap) {
			modeWrap.hidden = !isCustom;
		}
		if (daysWrap) {
			daysWrap.hidden = !isDaysMode;
		}
		if (weekdayWrap) {
			weekdayWrap.hidden = !isWeekdayMode;
		}
		if (dailyTimeWrap) {
			dailyTimeWrap.hidden = PD.s.currentFreq !== '24h';
		}
	};

	/** An item's custom mode, 'days' for anything unknown. */
	PD.customModeOf = function customModeOf(item) {
		var mode = item && item.customMode;
		return ('weekday' === mode || 'monthday' === mode) ? mode : 'days';
	};

	/**
	 * The «Ημέρα του μήνα» field follows PD.s.currentMonthDay, and
	 * the rule for months without that day is spelled out from day 29 on.
	 */
	PD.updateMonthDayField = function updateMonthDayField() {
		var input = document.getElementById('pd-custom-monthday');
		var md = PD.validMonthDay(PD.s.currentMonthDay);
		if (input && PD.validMonthDay(input.value) !== md) {
			input.value = null === md ? '' : String(md);
		}
		PD.updateMonthDayHelp();
	};

	PD.updateMonthDayHelp = function updateMonthDayHelp() {
		var help = document.getElementById('pd-monthday-last');
		if (!help) {
			return;
		}
		var md = PD.validMonthDay(PD.s.currentMonthDay);
		var show = null !== md && md >= 29;
		help.hidden = !show;
		help.textContent = show
			? PD.format(PD.txt('monthDayLastRule', 'Όταν ένας μήνας δεν έχει %1$d ημέρες (π.χ. Φεβρουάριος, Απρίλιος), η δόση πέφτει την τελευταία ημέρα εκείνου του μήνα.'), md)
			: '';
	};

	/**
	 * «Από τη συνταγή: …» above the form while a medicine loaded by
	 * «Συμπλήρωση στη φόρμα» is in it. Read-only, text only; never printed.
	 */
	PD.updateRxSource = function updateRxSource() {
		var box = document.getElementById('pd-rx-source');
		if (!box) {
			return;
		}
		var text = String(PD.s.rxSourceText || '');
		box.hidden = !text;
		var body = box.querySelector('.pd-rx-source-text');
		if (body) {
			body.textContent = text;
		}
	};

	PD.collectDrugForm = function collectDrugForm() {
		/* The dose is kept as its canonical NUMBER (1.5, not the
		   typed "1,5" / "1½" / "3/2"), so the plan, the totals and the
		   label all print it through PD.formatDose(). While the field does
		   not parse yet the raw text is passed on; validateDrugForm()
		   refuses to store it. */
		var doseRaw = PD.currentDoseAmount();
		var dose = PD.parseDoseAmount(doseRaw, PD.currentDoseUnit());
		return {
			name: PD.currentDrugName(),
			doseAmount: isNaN(dose) ? doseRaw : dose,
			doseUnit: PD.currentDoseUnit(),
			freq: PD.s.currentFreq,
			dailyTime: PD.s.currentDailyTime,
			customMode: PD.s.currentCustomMode,
			customIntervalDays: PD.currentCustomIntervalDays(),
			customWeekday: PD.validWeekday(PD.s.currentWeekday),
			customMonthDay: PD.validMonthDay(PD.s.currentMonthDay),
			days: PD.currentDays(),
			notes: PD.currentNotes()
		};
	};

	PD.addOrUpdateItem = function addOrUpdateItem() {
		if (!PD.validateDrugForm()) {
			return;
		}
		var item = PD.collectDrugForm();
		/* «Αποθήκευση» with nothing changed keeps the plan exactly
		   as printed, so its free reprint and labels stay. */
		if (PD.s.editingIndex !== null && typeof PD.s.items[PD.s.editingIndex] !== 'undefined' &&
			PD.drugFormMatchesItem(PD.s.items[PD.s.editingIndex])) {
			PD.setMessage(PD.txt('drugUnchanged', 'Δεν έγινε καμία αλλαγή στο φάρμακο.'), 'success');
			PD.resetDrugForm();
			PD.renderMedicationList();
			PD.renderPreview();
			PD.updateStepButtons();
			var sameEl = document.getElementById('pd-drug');
			if (sameEl) {
				sameEl.focus();
			}
			return;
		}
		/* Patient safety: the same medicine already in the plan
		   (from the prescription or typed before) is added only on a
		   second press — otherwise every day card shows it twice and the
		   patient takes a double dose. The prescription import already
		   leaves such a medicine unselected; this is the form's side. */
		var dupKey = PD.medicineKey(item.name);
		var dupAt = -1;
		PD.s.items.forEach(function (other, idx) {
			if (dupAt === -1 && idx !== PD.s.editingIndex && PD.medicineKey(other.name) === dupKey) {
				dupAt = idx;
			}
		});
		if (dupAt !== -1 && PD.s.duplicateAck !== dupKey) {
			PD.s.duplicateAck = dupKey;
			PD.flagFieldError('pd-drug', PD.format(
				PD.txt('drugDuplicateConfirm', 'Το «%s» υπάρχει ήδη στο πλάνο. Αν πρέπει να μπει δεύτερη φορά (π.χ. άλλη δόση άλλες ώρες), πατήστε ξανά το ίδιο κουμπί — αλλιώς πατήστε «Επεξεργασία» στο φάρμακο της λίστας.'),
				PD.s.items[dupAt].name
			), true);
			return;
		}
		PD.s.duplicateAck = '';
		PD.invalidatePendingPrintToken();
		if (PD.s.editingIndex !== null && typeof PD.s.items[PD.s.editingIndex] !== 'undefined') {
			PD.s.items[PD.s.editingIndex] = item;
			PD.setMessage(PD.txt('drugUpdated', 'Το φάρμακο ενημερώθηκε.'), 'success');
		} else {
			PD.s.items.push(item);
			PD.setMessage(PD.txt('drugAdded', 'Το φάρμακο προστέθηκε στο πλάνο.'), 'success');
		}
		PD.resetDrugForm();
		PD.renderMedicationList();
		PD.renderPreview();
		PD.updateStepButtons();
		/* Patient safety: methotrexate is taken once a WEEK;
		   daily dosing is a well-known fatal error. A warning, not a
		   block — the pharmacist has the final word. */
		if (PD.methotrexateTooOften(item)) {
			PD.setMessage(PD.txt('rxWarn_methotrexateDaily', 'ΠΡΟΣΟΧΗ: η μεθοτρεξάτη χορηγείται συνήθως μία φορά την εβδομάδα. Επιβεβαιώστε τη συχνότητα.'), 'error');
		} else if (PD.weeklyOnlyTooOften && PD.weeklyOnlyTooOften(item)) {
			/* The other once-a-week medicines, the same way. */
			PD.setMessage(PD.txt('rxWarn_weeklyOnly', 'ΠΡΟΣΟΧΗ: αυτό το φάρμακο χορηγείται συνήθως μία φορά την εβδομάδα, εδώ είναι συχνότερα. Επαληθεύστε τη συχνότητα με τον γιατρό που το συνταγογράφησε.'), 'error');
		} else if (PD.weeklyRepeatedItems) {
			/* Each entry weekly, but the same once-a-week medicine
			   in two entries (printing asks again, see validation.js). */
			var rep = PD.weeklyRepeatedItems(PD.s.items);
			if (rep.mtx.indexOf(item) !== -1 || rep.weekly.indexOf(item) !== -1) {
				PD.setMessage(PD.txt('weeklyRepeatedWarn', 'ΠΡΟΣΟΧΗ: αυτό το φάρμακο χορηγείται συνήθως μία φορά την εβδομάδα και υπάρχει ήδη σε άλλη εγγραφή του πλάνου. Επιβεβαιώστε ότι μαζί δεν δίνουν δόση συχνότερα από το σωστό.'), 'error');
			}
		}
		var drugEl = document.getElementById('pd-drug');
		if (drugEl) {
			drugEl.focus();
		}
	};

	/**
	 * True when the medicine form holds exactly what `item` has
	 * saved, i.e. leaving the form now loses nothing.
	 */
	PD.drugFormMatchesItem = function drugFormMatchesItem(item) {
		if (!item) {
			return false;
		}
		function norm(value) {
			if (value == null || (typeof value === 'number' && isNaN(value))) {
				return '';
			}
			return String(value).trim();
		}
		var form = PD.collectDrugForm();
		/* The days field as typed: collectDrugForm() parses it, so "7.5"
		   or "1e1" would otherwise compare equal to a saved 7 / 1. */
		var daysEl = document.getElementById('pd-days');
		if (daysEl) {
			form.days = (daysEl.validity && daysEl.validity.badInput) ? '\u0000' : daysEl.value;
		}
		return norm(form.name) === norm(item.name) &&
			norm(form.doseAmount) === norm(item.doseAmount) &&
			norm(form.doseUnit) === norm(item.doseUnit || 'tablet') &&
			norm(form.freq) === norm(item.freq || '12h') &&
			norm(PD.normalizeDailyTime(form.dailyTime)) === norm(PD.normalizeDailyTime(item.dailyTime)) &&
			norm(form.customMode) === norm(PD.customModeOf(item)) &&
			norm(form.customIntervalDays) === norm(item.customIntervalDays) &&
			norm(PD.validWeekday(form.customWeekday)) === norm(PD.validWeekday(item.customWeekday)) &&
			norm(PD.validMonthDay(form.customMonthDay)) === norm(PD.validMonthDay(item.customMonthDay)) &&
			norm(form.days) === norm(item.days) &&
			norm(form.notes) === norm(item.notes);
	};

	PD.editItem = function editItem(index) {
		if (!Number.isInteger(index) || index < 0 || index >= PD.s.items.length) {
			return;
		}
		var item = PD.s.items[index];
		if (!item) {
			return;
		}
		/*
		 * Patient safety: loading a medicine overwrites the form.
		 * Refuse while the form holds something that would be lost — a
		 * medicine typed but never added, or unsaved changes to the one
		 * being edited — exactly as Preview and Print already do. Switching
		 * from an unchanged edit to another medicine stays allowed.
		 */
		var editing = PD.s.editingIndex !== null ? PD.s.items[PD.s.editingIndex] : null;
		var wouldLose = editing ? !PD.drugFormMatchesItem(editing) : PD.hasUnsavedDrugForm();
		if (wouldLose && PD.blockIfUnsavedDrug()) {
			return;
		}
		PD.s.editingIndex = index;
		PD.s.currentFreq = item.freq || '12h';
		/* A pending «choose a frequency» left by «Συμπλήρωση στη
		   φόρμα» belongs to that medicine, not to the one loaded now. */
		PD.s.rxNeedsFreq = false;
		PD.s.rxSourceText = '';
		PD.s.doseQtyAck = '';
		PD.s.currentCustomMode = PD.customModeOf(item);
		PD.s.currentDailyTime = PD.normalizeDailyTime(item.dailyTime);
		/* A saved item keeps its day; nothing is invented. */
		PD.s.currentWeekday = PD.validWeekday(item.customWeekday);
		PD.s.currentMonthDay = PD.validMonthDay(item.customMonthDay);
		PD.setFieldValue('pd-drug', item.name || '');
		/* Shown in the active language's decimal form ("1,5"). */
		PD.setFieldValue('pd-dose-amount', PD.formatDose(item.doseAmount));
		PD.setFieldValue('pd-dose-unit', item.doseUnit || 'tablet');
		PD.setFieldValue('pd-days', item.days || '');
		PD.setFieldValue('pd-notes', item.notes || '');
		PD.setFieldValue('pd-custom-interval-days', item.customIntervalDays || '');
		PD.updateFreqChips();
		PD.updateCustomModeChips();
		PD.updateDailyTimeChips();
		PD.updateWeekdayChips();
		PD.updateMonthDayField();
		PD.updateCustomFieldsVisibility();
		PD.updateRxSource();
		var addBtn = document.getElementById('pd-add');
		if (addBtn) {
			addBtn.textContent = PD.txt('saveDrug', 'Αποθήκευση Φαρμάκου');
		}
		if (PD.updateDoseTotal) {
			PD.updateDoseTotal();
		}
		PD.goToStep(1);
		PD.clearMessage();
		var drugEl = document.getElementById('pd-drug');
		if (drugEl) {
			drugEl.focus();
		}
	};

	PD.deleteItem = function deleteItem(index) {
		if (!Number.isInteger(index) || index < 0 || index >= PD.s.items.length) {
			return;
		}
		var before = document.activeElement;
		PD.s.items.splice(index, 1);
		PD.invalidatePendingPrintToken();
		if (PD.s.editingIndex === index) {
			PD.resetDrugForm();
		} else if (PD.s.editingIndex !== null && PD.s.editingIndex > index) {
			/* An earlier item was removed, so the one being edited shifted
			   down one slot — keep editingIndex pointing at the same drug. */
			PD.s.editingIndex--;
		}
		PD.renderMedicationList();
		PD.renderPreview();
		PD.updateStepButtons();
		PD.clearMessage();
		/* The list is re-rendered, so the focused × is gone and
		   focus fell to <body>. Land on the card that took its place, else
		   the one above, else «Προσθήκη» once the list is empty. */
		if (typeof PD.focusWasLost === 'function' && PD.focusWasLost(before)) {
			var cards = document.querySelectorAll('#pd-rows .plandose-med-card');
			var card = cards[index] || cards[index - 1];
			var target = card
				? card.querySelector('[data-action="delete"]')
				: document.getElementById('pd-add');
			if (target) {
				target.focus();
			}
		}
	};

	PD.renderMedicationList = function renderMedicationList() {
		/* The cards show the first/last dose, which depend on the
		   current day and start date. */
		if (typeof PD.beginDayPass === 'function') {
			PD.beginDayPass();
		}
		var rows = document.getElementById('pd-rows');
		if (rows) {
			rows.innerHTML = '';
			if (PD.s.items.length === 0) {
				rows.innerHTML = '<div class="plandose-empty">' + '<strong>' + PD.escapeHtml(PD.txt('emptyListTitle', 'Δεν έχουν προστεθεί φάρμακα ακόμα.')) + '</strong>' + '<span>' + PD.escapeHtml(PD.txt('emptyList', 'Προσθέστε τουλάχιστον ένα φάρμακο για να συνεχίσετε.')) + '</span>' + '</div>';
			} else {
				PD.s.items.forEach(function (item, index) {
					var card = document.createElement('div');
					card.className = 'plandose-med-card';
					var deleteLabel = PD.txt('deleteAria', 'Διαγραφή') + ': ' + item.name;
					card.innerHTML = '<div class="plandose-med-card-main">' + '<strong>' + PD.escapeHtml(item.name) + '</strong>' + '<span>' + PD.escapeHtml(PD.itemSummary(item)) + '</span>' + (PD.courseLine(item) ? '<small class="pd-med-course">' + PD.escapeHtml(PD.courseLine(item)) + '</small>' : '') + (item.notes ? '<small>' + PD.escapeHtml(item.notes) + '</small>' : '') + '</div>' + '<div class="plandose-med-card-actions">' + '<button type="button" class="plandose-mini-btn" data-action="edit">' + PD.escapeHtml(PD.txt('edit', 'Επεξεργασία')) + '</button>' + '<button type="button" class="plandose-mini-btn plandose-mini-btn-danger" data-action="delete" aria-label="' + PD.escapeAttr(deleteLabel) + '">×</button>' + '</div>';
					card.querySelector('[data-action="edit"]').addEventListener('click', function () {
						PD.editItem(index);
					});
					card.querySelector('[data-action="delete"]').addEventListener('click', function () {
						PD.deleteItem(index);
					});
					rows.appendChild(card);
				});
			}
		}
		PD.renderReviewList();
	};

	/**
	 * Read-only recap shown at the top of the preview step.
	 * Always rendered straight from the live `items` array so it can
	 * never fall out of sync with the drug list.
	 */
	PD.renderReviewList = function renderReviewList() {
		var review = document.getElementById('pd-rows-review');
		if (!review) {
			return;
		}
		review.innerHTML = '';
		if (PD.s.items.length === 0) {
			review.innerHTML = '<div class="plandose-empty">' + '<strong>' + PD.escapeHtml(PD.txt('emptyListTitle', 'Δεν έχουν προστεθεί φάρμακα ακόμα.')) + '</strong>' + '<span>' + PD.escapeHtml(PD.txt('emptyList', 'Προσθέστε τουλάχιστον ένα φάρμακο για να συνεχίσετε.')) + '</span>' + '</div>';
			return;
		}
		PD.s.items.forEach(function (item) {
			var row = document.createElement('div');
			row.className = 'plandose-list-item';
			row.innerHTML = '<div class="plandose-med-card-main">' + '<strong>' + PD.escapeHtml(item.name) + '</strong>' + '<span>' + PD.escapeHtml(PD.itemSummary(item)) + '</span>' + (PD.courseLine(item) ? '<small class="pd-med-course">' + PD.escapeHtml(PD.courseLine(item)) + '</small>' : '') + '</div>';
			review.appendChild(row);
		});
	};
})();
