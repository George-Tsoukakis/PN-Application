/**
 * PlanDose — rx-review.js
 *
 * «Επικόλληση συνταγής», the review of the medicines read: what each
 * warning says and whether it is settled in the row or needs the form
 * (PD.rxPending(), PD.rxItemProblem(), PD.rxQuantityCheck()), the rows
 * with their choices and «Επιβεβαιώνω», «Προσθήκη στο πλάνο» and
 * «Συμπλήρωση στη φόρμα». Needs rx-parse.js; rx-import.js shows it.
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;
	var R = PD && PD.rx && PD.rx._;

	/* Nothing of the reader runs if a file before this one is missing. */
	if (!R || !PD.parsePrescription) {
		return;
	}

	/** Warning codes → text for the review screen (dictionary keys rxWarn_*). */
	var WARN_TEXT = {
		name: 'Ελέγξτε το όνομα του φαρμάκου.',
		brandWords: 'Το όνομα έχει περισσότερες από μία λέξεις πριν από τη μορφή (τονίζονται) — επιβεβαιώστε ότι ανήκουν στο όνομα του φαρμάκου (π.χ. «PO», «HS» δεν ανήκουν).',
		strength: 'Η περιεκτικότητα λείπει ή δεν διαβάστηκε ολόκληρη — συγκρίνετε το όνομα με τη συνταγή και επιβεβαιώστε.',
		dose: 'Η δοσολογία δεν διαβάστηκε — συμπληρώστε τη.',
		noDose: 'Βρέθηκε φάρμακο χωρίς γραμμή «ΔΟΣΟΛΟΓΙΑ» — συμπληρώστε τη δοσολογία από τη συνταγή.',
		tooLong: 'Η γραμμή της δοσολογίας είναι υπερβολικά μεγάλη και δεν διαβάστηκε — συμπληρώστε τη.',
		amount: 'Η ποσότητα δεν είναι έγκυρη — συμπληρώστε τη.',
		unit: 'Επιλέξτε «Είδος».',
		iuConfirm: 'Ινσουλίνη: η ποσότητα διαβάστηκε ως μονάδες (IU) — επιβεβαιώστε την.',
		highQty: 'Ασυνήθιστα μεγάλη ποσότητα ανά λήψη — επιβεβαιώστε την με τη συνταγή.',
		injectionQty: 'Η ποσότητα δεν ταιριάζει με ενέσεις — ελέγξτε μονάδες και «Είδος».',
		freq: 'Η συχνότητα δεν διαβάστηκε — συμπληρώστε τη.',
		freqWeekly: 'Εβδομαδιαία δοσολογία που δεν διαβάστηκε — συμπληρώστε τη.',
		onceDays: 'Εφάπαξ, αλλά για περισσότερες ημέρες — ελέγξτε τη διάρκεια.',
		phrase: 'Η δοσολογία έχει λέξεις ή αριθμούς που δεν αναγνωρίστηκαν — ελέγξτε ποσότητα και «Είδος».',
		unitsOrInjection: 'Ισχύς σε μονάδες/ml: ελέγξτε αν η ποσότητα είναι ενέσεις ή μονάδες (IU).',
		weekDay: 'Μία φορά την εβδομάδα: επιλέξτε ημέρα της εβδομάδας.',
		everyHours: 'Η συνταγή λέει «κάθε … ώρες»: στο πλάνο οι δόσεις μπαίνουν Πρωί / Μεσημέρι / Βράδυ, όχι ανά ακριβές ωράριο. Αν πρέπει να απέχουν ακριβώς, γράψτε τις ώρες στις σημειώσεις.',
		startDay: 'Η πρώτη δόση μπαίνει την ημέρα έναρξης του πλάνου — ελέγξτε ότι είναι η σωστή ημέρα.',
		time: 'Η συνταγή δεν ορίζει ώρα — επιλέξτε την.',
		iuSyringe: 'Ισχύς σε μονάδες ανά ml σε μία προγεμισμένη σύριγγα: επιβεβαιώστε ότι η δόση είναι μία ένεση και ότι το φάρμακο δεν είναι ινσουλίνη.',
		insulinUnits: 'Ινσουλίνη: η δοσολογία δίνεται σε ενέσεις, όχι σε μονάδες — συμπληρώστε στη φόρμα τη δόση σε μονάδες (IU).',
		insulinMl: 'Ινσουλίνη: η δόση είναι γραμμένη σε ml ή mg, όχι σε μονάδες — συμπληρώστε στη φόρμα τη δόση σε μονάδες (IU).',
		unitForm: 'Η δοσολογία δεν ταιριάζει με τη μορφή του φαρμάκου (π.χ. ένεση σε δισκία) — ελέγξτε ότι η γραμμή δοσολογίας ανήκει σε αυτό το φάρμακο.',
		shortDuration: 'Η διάρκεια φαίνεται πολύ μικρή για την ποσότητα που χορηγείται — ελέγξτε τη διάρκεια.',
		strengthZero: 'Η περιεκτικότητα είναι γραμμένη χωρίς το αρχικό μηδέν (π.χ. «.25MG») και μπήκε με μηδέν μπροστά (0.25MG) — συγκρίνετε το όνομα με τη συνταγή στη φόρμα.',
		strengthSplit: 'Ο αριθμός της περιεκτικότητας είναι σπασμένος με κενό (π.χ. «0. 25MG», «1 . 5MG») και μπήκε ενωμένος (0.25MG, 1.5MG) — συγκρίνετε το όνομα με τη συνταγή στη φόρμα.',
		strengthSep: 'Ακριβώς πριν από την περιεκτικότητα υπάρχει τελεία, κόμμα ή άλλος αριθμός (π.χ. «1X. 5MG», «0 25MG») — ίσως λείπει μηδέν ή υποδιαστολή. Συγκρίνετε το όνομα με τη συνταγή στη φόρμα.',
		strengthThousands: 'Η περιεκτικότητα έχει τελεία ή κόμμα και τρία ψηφία (π.χ. «1.000MG») — μπορεί να σημαίνει χίλια ή ένα. Συγκρίνετε το όνομα με τη συνταγή στη φόρμα.',
		methotrexateDaily: 'ΠΡΟΣΟΧΗ: η μεθοτρεξάτη χορηγείται συνήθως μία φορά την εβδομάδα. Επιβεβαιώστε τη συχνότητα.',
		weeklyOnly: 'ΠΡΟΣΟΧΗ: αυτό το φάρμακο χορηγείται συνήθως μία φορά την εβδομάδα, εδώ είναι συχνότερα. Επαληθεύστε τη συχνότητα με τον γιατρό που το συνταγογράφησε.',
		dispensedQty: 'Η ποσότητα που χορηγήθηκε δεν ταιριάζει με το πλάνο (πολύ περισσότερη ή πολύ λιγότερη) — ελέγξτε ποσότητα, συχνότητα και διάρκεια με τη συνταγή.',
		extra: 'Η δοσολογία έχει κι άλλο κείμενο που δεν διαβάστηκε — ελέγξτε τη συνταγή.',
		asNeeded: 'Η συνταγή λέει «όταν χρειάζεται» (SOS / σε περίπτωση / εάν) — δεν είναι σταθερό πρόγραμμα. Γράψτε την οδηγία στις σημειώσεις.',
		intervalCount: 'Οι ημέρες δεν είναι ακέραιος αριθμός εβδομάδων/διαστημάτων: ελέγξτε το πλήθος των δόσεων (π.χ. 30 ημέρες εβδομαδιαία = 4 ή 5 δόσεις) με όσα χορηγήθηκαν.',
		monthly: '«1 φορά τον μήνα»: επιλέξτε ημέρα του μήνα. Αν ένας μήνας δεν έχει αυτή την ημέρα, η δόση πέφτει την τελευταία ημέρα του μήνα.',
		sameDrugOtherDose: 'Το ίδιο φάρμακο υπάρχει και με άλλη δοσολογία — επιλέξτε ποια ισχύει.',
		variableDose: 'Η συνταγή έχει σειρά από ποσότητες (π.χ. διαφορετική δόση ανά ημέρα) — δεν είναι σταθερή δόση. Συμπληρώστε το πρόγραμμα στη φόρμα.',
		monthlyCount: 'Μία φορά τον μήνα: το πλήθος των δόσεων εξαρτάται από την ημέρα που θα επιλέξετε — ελέγξτε τις ημερομηνίες με όσα χορηγήθηκαν.',
		duplicate: 'Υπάρχει ήδη πιο πάνω — δεν επιλέχθηκε.',
		inPlan: 'Υπάρχει ήδη στο πλάνο — δεν επιλέχθηκε.'
	};

	/* Codes whose meaning changed read a new dictionary key, so an
	   older translation can never show the old meaning («μπήκε ως κάθε 30
	   ημέρες»). «startDay» is what «weekDay» used to say. */
	var WARN_KEY = {
		weekDay: 'rxWarn_pickWeekday',
		startDay: 'rxWarn_weekDay',
		monthly: 'rxWarn_pickMonthDay',
		iuConfirm: 'rxWarn_iuConfirmHere',
		intervalCount: 'rxWarn_intervalCountCheck',
		/* Also a strength read only in part, or none at all. */
		strength: 'rxWarn_strengthCheck'
	};

	PD.prescriptionWarningText = function prescriptionWarningText(code) {
		return PD.txt(WARN_KEY[code] || 'rxWarn_' + code, WARN_TEXT[code] || code);
	};

	/* Notes only: the row is simply not preselected. */
	var SOFT = { duplicate: true, inPlan: true };

	/* Settled in the review row by a CHOICE (the field it fills). */
	var PICK = { time: 'freq', weekDay: 'freq', monthly: 'freq' };

	/* Settled in the review row by «Επιβεβαιώνω» on that field. */
	var CONFIRM = { brandWords: 'name', strength: 'name', iuConfirm: 'qty', iuSyringe: 'qty', highQty: 'qty', sameDrugOtherDose: 'qty', startDay: 'freq', everyHours: 'freq', intervalCount: 'days', monthlyCount: 'days', dispensedQty: 'days', shortDuration: 'days' };

	/* Every other warning sends the medicine through the normal form
	   («Συμπλήρωση στη φόρμα»). */
	function hardWarnings(item) {
		return item.warnings.filter(function (w) {
			return !SOFT[w] && !PICK[w] && !CONFIRM[w];
		});
	}

	function pickDone(item, code) {
		if ('time' === code) {
			return '24h' !== item.freq || Object.prototype.hasOwnProperty.call(PD.dailyTimeLabels(), item.dailyTime);
		}
		if ('weekDay' === code) {
			return 'weekday' !== item.customMode || null !== PD.validWeekday(item.customWeekday);
		}
		if ('monthly' === code) {
			return 'monthday' !== item.customMode || null !== PD.validMonthDay(item.customMonthDay);
		}
		return true;
	}

	/**
	 * What the pharmacist still has to choose or confirm in the
	 * review row before the medicine can be added.
	 *
	 * @return {Array<{code:string, field:string, kind:string}>}
	 */
	PD.rxPending = function rxPending(item) {
		var out = [];
		(item.warnings || []).forEach(function (w) {
			if (PICK[w] && !pickDone(item, w)) {
				out.push({ code: w, field: PICK[w], kind: 'pick' });
			} else if (CONFIRM[w] && !(item.confirmed && item.confirmed[CONFIRM[w]])) {
				out.push({ code: w, field: CONFIRM[w], kind: 'confirm' });
			}
		});
		return out;
	};

	/**
	 * Why an imported item cannot go into the plan as it is, or ''. The
	 * same limits the form enforces (dose ceiling per unit, 1–MAX_DAYS
	 * days, interval 1–90, at least one dose in the period). 'warnings'
	 * also while a choice or confirmation is pending (PD.rxPending()).
	 */
	PD.rxItemProblem = function rxItemProblem(item) {
		if (hardWarnings(item).length || PD.rxPending(item).length) {
			return 'warnings';
		}
		/* Checked again here, whatever the parse said. */
		if (PD.methotrexateTooOften(item) || (PD.weeklyOnlyTooOften && PD.weeklyOnlyTooOften(item))) {
			return 'warnings';
		}
		if (!String(item.name || '').trim()) {
			return 'name';
		}
		var known = PD.unitOptions.some(function (u) {
			return u.val === item.doseUnit;
		});
		if (!known) {
			return 'unit';
		}
		if (PD.checkDoseAmount(item.doseAmount, item.doseUnit).error) {
			return 'amount';
		}
		if (['24h', '12h', '8h', '6h', 'custom'].indexOf(item.freq) === -1) {
			return 'freq';
		}
		var invalid = PD.planItemInvalid ? PD.planItemInvalid(plainItem(item)) : '';
		if (invalid) {
			return 'days' === invalid ? 'days' : 'freq';
		}
		PD.beginDayPass();
		var totals = PD.doseTotals(item);
		if (!totals || totals.doses < 1) {
			return 'days';
		}
		return '';
	};

	/**
	 * What was dispensed (pack × boxes of the price row) against
	 * what the plan needs (quantity × doses), or null when either is not
	 * known — a pack that cannot be counted in the plan's «Είδος», or a
	 * weekday / day of the month still to choose.
	 *
	 * Warns (`warn`) only for a reliable pack (tablets, capsules,
	 * suppositories, one syringe or ampoule) and only when the plan needs
	 * no more than all boxes but one ((boxes − 1) × pack ≥ needed) or more
	 * than three times what was dispensed. Dispensing less than the plan
	 * needs is normal (a repeat, a partial prescription): never a warning
	 * down to 1/3. On 345 real rows, neither rule fired on a correct one.
	 *
	 * @return {?{dispensed:number, needed:number, reliable:boolean, warn:boolean}}
	 */
	PD.rxQuantityCheck = function rxQuantityCheck(item) {
		var p = item && item.pack;
		var boxes = item ? parseInt(item.boxes, 10) : NaN;
		if (!p || !(boxes >= 1) || !item.freq) {
			return null;
		}
		var unit = item.doseUnit;
		var size = 0;
		var reliable = false;
		if (p.count && /^(tablet|capsule|suppository)$/.test(unit)) {
			size = p.count;
			reliable = true;
		} else if (p.single && 'injection' === unit) {
			size = 1;
			reliable = true;
		} else if (p.doses && /^(inhale|spray)$/.test(unit)) {
			size = p.doses;
		} else if (p.containers && /^(injection|ampoule|sachet)$/.test(unit)) {
			size = p.containers;
		} else if (p.ml && 'ml' === unit) {
			size = p.ml;
		}
		if (!(size > 0) || !pickDone(item, 'weekDay') || !pickDone(item, 'monthly')) {
			return null;
		}
		PD.beginDayPass();
		var totals = PD.doseTotals(item);
		if (!totals || !(totals.units > 0)) {
			return null;
		}
		var dispensed = Math.round(size * boxes * 100) / 100;
		var needed = totals.units;
		return {
			dispensed: dispensed,
			needed: needed,
			reliable: reliable,
			warn: reliable && ((boxes - 1) * size >= needed || dispensed / needed < 1 / 3),
			/* 15 times what the plan needs, for 3 days or less
			   («BTx60 … x 1 ημέρες»): is the duration right? */
			short: reliable && dispensed / needed >= 15 && parseInt(item.days, 10) <= 3
		};
	};

	/* The «dispensedQty» confirmation follows the check (the doses depend
	   on the weekday or day of the month chosen in the row). */
	function syncQuantityWarning(item) {
		var check = PD.rxQuantityCheck(item);
		var sync = function (code, on) {
			var at = item.warnings.indexOf(code);
			if (on && at === -1) {
				item.warnings.push(code);
			} else if (!on && at !== -1) {
				item.warnings.splice(at, 1);
			}
		};
		sync('dispensedQty', !!(check && check.warn));
		/* A soft confirmation on «Διάρκεια». */
		sync('shortDuration', !!(check && check.short && !check.warn));
		return check;
	}

	/* ---------------------------------------------------------------- */
	/* Review screen                                                      */
	/* ---------------------------------------------------------------- */

	/* PD.s.rx: null (closed), or { stage: 'paste' } / { stage: 'review',
	   patient, usePatient, patientNote, patientCheck, patientOk, countNote,
	   countAck, rows: [{ item, include }] }. Memory only; wiped with the
	   plan (resetPlanState) and when the popup closes. */

	function t(key, fallback) {
		return PD.txt(key, fallback);
	}

	function timeChips(i, item) {
		var labels = PD.dailyTimeLabels();
		var labelId = 'pd-rx-time-label-' + i;
		return '<div class="pd-rx-times" role="group" aria-labelledby="' + labelId + '">' +
			'<span id="' + labelId + '">' + PD.escapeHtml(t('rxTimeLabel', 'Ώρα λήψης')) + ':</span>' +
			PD.SLOT_ORDER.map(function (slot) {
				var on = item.dailyTime === slot;
				return '<button type="button" id="pd-rx-t-' + i + '-' + slot + '" class="plandose-chip pd-rx-time' + (on ? ' active' : '') + '" data-rx-row="' + i + '" data-rx-time="' + slot + '" aria-pressed="' + (on ? 'true' : 'false') + '">' +
					PD.escapeHtml(labels[slot]) + '</button>';
			}).join('') +
			/* A time set by «Ίδια ώρα για όλα» says so; the row
			   can still be changed on its own. */
			(item.bulkTime && item.dailyTime === item.bulkTime ? ' <span class="pd-rx-bulk-mark">' + PD.escapeHtml(t('rxBulkMark', '(για όλα)')) + '</span>' : '') +
			'</div>';
	}

	/* The weekday of a weekly medicine, chosen in the row. */
	function weekdayChips(i, item) {
		var labelId = 'pd-rx-wd-label-' + i;
		var current = PD.validWeekday(item.customWeekday);
		return '<div class="pd-rx-times pd-rx-weekdays" role="group" aria-labelledby="' + labelId + '">' +
			'<span id="' + labelId + '">' + PD.escapeHtml(t('rxWeekdayLabel', 'Ημέρα της εβδομάδας')) + ':</span>' +
			[1, 2, 3, 4, 5, 6, 0].map(function (dow) {
				var on = dow === current;
				return '<button type="button" id="pd-rx-wd-' + i + '-' + dow + '" class="plandose-chip pd-rx-time' + (on ? ' active' : '') + '" data-rx-row="' + i + '" data-rx-weekday="' + dow + '" aria-pressed="' + (on ? 'true' : 'false') + '">' +
					PD.escapeHtml(PD.weekdayName(dow, true)) + '</button>';
			}).join('') +
			'</div>';
	}

	/* The day of the month of a monthly medicine, typed in the row. */
	function monthDayInput(i, item) {
		var id = 'pd-rx-md-' + i;
		var md = PD.validMonthDay(item.customMonthDay);
		var note = null !== md && md >= 29
			? '<small id="' + id + '-note" class="pd-rx-md-note">' + PD.escapeHtml(PD.format(t('monthDayLastRule', 'Όταν ένας μήνας δεν έχει %1$d ημέρες (π.χ. Φεβρουάριος, Απρίλιος), η δόση πέφτει την τελευταία ημέρα εκείνου του μήνα.'), md)) + '</small>'
			: '';
		return '<div class="pd-rx-times pd-rx-monthday">' +
			'<label for="' + id + '">' + PD.escapeHtml(t('rxMonthDayLabel', 'Ημέρα του μήνα (1–31)')) + ':</label>' +
			'<input type="number" id="' + id + '" class="pd-rx-md-input" min="1" max="31" step="1" inputmode="numeric" autocomplete="off" data-rx-monthday="' + i + '" value="' + (null === md ? '' : md) + '"' + (note ? ' aria-describedby="' + id + '-note"' : '') + ' />' +
			note +
			'</div>';
	}

	function confirmBox(i, field, item, describedBy) {
		var id = 'pd-rx-cf-' + i + '-' + field;
		var on = !!(item.confirmed && item.confirmed[field]);
		return '<label class="pd-rx-confirm' + (on ? ' is-done' : '') + '"><input type="checkbox" id="' + id + '" data-rx-confirm="' + i + '" data-rx-field="' + field + '"' + (on ? ' checked' : '') +
			(describedBy.length ? ' aria-describedby="' + describedBy.join(' ') + '"' : '') + ' /> ' +
			PD.escapeHtml(t('rxConfirm', 'Επιβεβαιώνω')) + '</label>';
	}

	var FIELD_ORDER = ['name', 'qty', 'freq', 'days'];

	/* Which field each warning is about (for the «?» marking and the
	   confirmation's description). */
	var WARN_FIELD = {
		name: 'name', strength: 'name', strengthZero: 'name', strengthSplit: 'name', strengthSep: 'name', strengthThousands: 'name', brandWords: 'name',
		dose: 'qty', noDose: 'qty', tooLong: 'qty', amount: 'qty', unit: 'qty', iuConfirm: 'qty', highQty: 'qty',
		injectionQty: 'qty', insulinUnits: 'qty', insulinMl: 'qty', unitForm: 'qty', iuSyringe: 'qty', shortDuration: 'days', phrase: 'qty', unitsOrInjection: 'qty', sameDrugOtherDose: 'qty', variableDose: 'qty',
		freq: 'freq', freqWeekly: 'freq', weekDay: 'freq', startDay: 'freq', everyHours: 'freq', time: 'freq', monthly: 'freq',
		methotrexateDaily: 'freq', weeklyOnly: 'freq', extra: 'freq', asNeeded: 'freq', dispensedQty: 'days',
		onceDays: 'days', intervalCount: 'days', monthlyCount: 'days'
	};

	/**
	 * The auto-filled fields of one row, each next to the choice
	 * or «Επιβεβαιώνω» it still needs. Pickers and confirmations only when
	 * the row can be settled here (no warning that needs the form).
	 */
	function fieldsHtml(i, item, hard) {
		var labels = {
			name: t('rxFieldName', 'Φάρμακο'),
			qty: t('rxFieldQty', 'Ποσότητα'),
			freq: t('rxFieldFreq', 'Συχνότητα'),
			days: t('rxFieldDays', 'Διάρκεια')
		};
		var values = {
			name: item.name || '?',
			qty: (String(item.doseAmount) !== '' ? PD.formatDose(item.doseAmount) : '?') + ' ' + (item.doseUnit ? PD.unitLabel(item.doseUnit) : '?'),
			/* Not «(Πρωί)» before a time is chosen. */
			freq: !item.freq ? '?' : ('24h' === item.freq && !pickDone(item, 'time')
				? t('everyDayTimeUnset', 'Μία φορά την ημέρα (επιλέξτε ώρα)')
				: PD.getFreqLabel(item)),
			days: item.days ? PD.format(t('rxDays', '%s ημέρες'), item.days) : '?'
		};
		var pending = PD.rxPending(item);
		/* The leading word(s) of a multi-word brand stand apart,
		   so the pharmacist sees what «Επιβεβαιώνω» is about. */
		var valueHtml = function (field) {
			var lead = item.brandLead || 0;
			if ('name' !== field || !lead || item.warnings.indexOf('brandWords') === -1) {
				return PD.escapeHtml(values[field]);
			}
			var words = String(item.name).split(' ');
			return '<mark class="pd-rx-brand-lead">' + PD.escapeHtml(words.slice(0, lead).join(' ')) + '</mark> ' +
				PD.escapeHtml(words.slice(lead).join(' '));
		};
		return '<dl class="pd-rx-fields">' + FIELD_ORDER.map(function (field) {
			var fieldWarns = item.warnings.filter(function (w) {
				return WARN_FIELD[w] === field && !SOFT[w];
			});
			var doubt = fieldWarns.length > 0;
			var extra = '';
			if (!hard) {
				if ('freq' === field) {
					if ('24h' === item.freq) {
						extra += timeChips(i, item);
					}
					if ('weekday' === item.customMode && 'custom' === item.freq) {
						extra += weekdayChips(i, item);
					}
					if ('monthday' === item.customMode && 'custom' === item.freq) {
						extra += monthDayInput(i, item);
					}
				}
				var needsConfirm = item.warnings.some(function (w) {
					return CONFIRM[w] === field;
				});
				/* The duration is confirmed once the dates can be seen, i.e.
				   after the weekday / day of the month is chosen. */
				var pickOpen = pending.some(function (p) {
					return 'pick' === p.kind;
				});
				if (needsConfirm && !('days' === field && pickOpen)) {
					var ids = item.warnings.filter(function (w) {
						return CONFIRM[w] === field;
					}).map(function (w) {
						return 'pd-rx-w-' + i + '-' + w;
					});
					extra += confirmBox(i, field, item, ids);
				}
				if ('days' === field) {
					extra += totalsHtml(item) + quantityHtml(item);
				}
			}
			var open = pending.some(function (p) {
				return p.field === field;
			});
			return '<div class="pd-rx-field' + (doubt ? ' is-doubt' : '') + (open ? ' is-open' : '') + '">' +
				'<dt>' + PD.escapeHtml(labels[field]) + '</dt>' +
				'<dd><span class="pd-rx-value">' + valueHtml(field) + '</span>' + extra + '</dd></div>';
		}).join('') + '</dl>';
	}

	/* «Σύνολο: 4 δόσεις — Τρί 29/09, …» before anything is added. */
	function totalsHtml(item) {
		if (!item.freq || !item.days || PD.rxPending(item).some(function (p) {
			return 'pick' === p.kind;
		})) {
			return '';
		}
		PD.beginDayPass();
		var totals = PD.doseTotals(item);
		if (!totals || !totals.doses) {
			return '';
		}
		var text = PD.doseTotalText ? PD.doseTotalText(totals, item.doseUnit) : '';
		var dates = PD.doseDatesText(item);
		if (dates) {
			text += (text ? ' — ' : '') + dates;
		}
		return text ? '<div class="pd-rx-total">' + PD.escapeHtml(text) + '</div>' : '';
	}

	/* «Χορηγούνται 30 · το πλάνο χρειάζεται 28». */
	function quantityHtml(item) {
		var check = PD.rxQuantityCheck(item);
		if (!check) {
			return '';
		}
		return '<div class="pd-rx-qty' + (check.warn ? ' is-warn' : '') + '">' +
			PD.escapeHtml(PD.format(t('rxDispensed', 'Χορηγούνται %1$s · το πλάνο χρειάζεται %2$s'), PD.formatDose(check.dispensed), PD.formatDose(check.needed))) +
			'</div>';
	}

	/* The rows «Ίδια ώρα για όλα» applies to — once a day (not
	   «εφάπαξ»), settled in the row itself, no time chosen yet. */
	function bulkTimeRows(rx) {
		return rx.rows.filter(function (row) {
			var item = row.item;
			return !row.loaded && '24h' === item.freq && !item.once && !pickDone(item, 'time') &&
				!hardWarnings(item).length && !PD.methotrexateTooOften(item) && !(PD.weeklyOnlyTooOften && PD.weeklyOnlyTooOften(item));
		});
	}

	function bulkTimeHtml(rx) {
		var rows = bulkTimeRows(rx);
		var status = '<p id="pd-rx-bulk-status" class="pd-rx-bulk-status" role="status" tabindex="-1">' + PD.escapeHtml(rx.bulkStatus || '') + '</p>';
		if (rows.length < 2) {
			return rx.bulkStatus ? status : '';
		}
		var labels = PD.dailyTimeLabels();
		return '<div class="pd-rx-bulk" role="group" aria-labelledby="pd-rx-bulk-label">' +
			'<span id="pd-rx-bulk-label">' + PD.escapeHtml(PD.format(t('rxBulkTime', 'Ίδια ώρα για όλα τα 1×ημέρα (%d):'), rows.length)) + '</span>' +
			PD.SLOT_ORDER.map(function (slot) {
				return '<button type="button" id="pd-rx-bulk-' + slot + '" class="plandose-chip pd-rx-time" data-rx-bulk="' + slot + '">' +
					PD.escapeHtml(labels[slot]) + '</button>';
			}).join('') +
			'</div>' + status;
	}

	function origHtml(i, item) {
		return '<div class="pd-rx-orig"><span class="pd-rx-orig-label" id="pd-rx-orig-' + i + '">' + PD.escapeHtml(t('rxOriginal', 'Στη συνταγή')) + '</span>' +
			'<div class="pd-rx-orig-text" role="note" aria-labelledby="pd-rx-orig-' + i + '">' + PD.escapeHtml(item.origText || item.source || '—') + '</div></div>';
	}

	/** Ticked rows that still need a choice or confirmation, and the checks of the whole paste. */
	function blockers(rx) {
		var out = [];
		if (rx.patientCheck && !rx.patientOk) {
			out.push('patient');
		}
		if (rx.countNote && !rx.countAck) {
			out.push('count');
		}
		rx.rows.forEach(function (row) {
			if (!row.loaded && row.include && !hardWarnings(row.item).length && PD.rxPending(row.item).length) {
				out.push('pending');
			}
		});
		return out;
	}

	/* The review stage of the panel (rx-import.js wraps it): one row per
	   medicine read, the checks of the whole paste, «Προσθήκη». */
	function reviewBody(rx) {
		var addable = 0;
		rx.rows.forEach(function (row) {
			if (!row.loaded) {
				syncQuantityWarning(row.item);
			}
		});
		var rows = rx.rows.map(function (row, i) {
			var item = row.item;
			if (row.loaded) {
				return '<li class="pd-rx-row is-loaded"><span class="pd-rx-name">' + PD.escapeHtml(item.name) + '</span>' +
					'<div class="pd-rx-sum">' + PD.escapeHtml(t('rxLoaded', 'Φορτώθηκε στη φόρμα — ολοκληρώστε το εκεί.')) + '</div>' +
					'<div class="pd-rx-src">' + PD.escapeHtml(PD.format(t('rxSource', 'Συνταγή: %s'), item.source || '—')) + '</div></li>';
			}
			var hard = hardWarnings(item).length > 0 || PD.methotrexateTooOften(item) || (PD.weeklyOnlyTooOften && PD.weeklyOnlyTooOften(item));
			var problem = PD.rxItemProblem(item);
			if (!problem && row.include) {
				addable++;
			}
			var warns = item.warnings.filter(function (w) {
				/* A choice already made needs no note. */
				return !PICK[w] || !pickDone(item, w);
			}).map(function (w) {
				var cls = ('methotrexateDaily' === w || 'weeklyOnly' === w || 'asNeeded' === w) ? 'is-danger' : 'is-warn';
				return '<li id="pd-rx-w-' + i + '-' + w + '" class="' + cls + '">' + PD.escapeHtml(PD.prescriptionWarningText(w)) + '</li>';
			}).join('');
			if (problem && !hard && !PD.rxPending(item).length) {
				warns += '<li class="is-warn">' + PD.escapeHtml(t('rxInvalid', 'Δεν μπορεί να μπει όπως είναι — συμπληρώστε το στη φόρμα.')) + '</li>';
			}
			var toForm = hard || (problem && !PD.rxPending(item).length);
			return '<li class="pd-rx-row' + (toForm ? ' needs-form' : '') + (!toForm && problem ? ' needs-check' : '') + '">' +
				'<label class="pd-rx-check">' +
				'<input type="checkbox" id="pd-rx-inc-' + i + '" data-rx-include="' + i + '"' + (row.include && !toForm ? ' checked' : '') + (toForm ? ' disabled' : '') + ' />' +
				'<span class="pd-rx-name">' + PD.escapeHtml(item.name || '?') + '</span></label>' +
				'<div class="pd-rx-cols">' + origHtml(i, item) + fieldsHtml(i, item, toForm) + '</div>' +
				(warns ? '<ul class="pd-rx-warns">' + warns + '</ul>' : '') +
				(problem ? '<button type="button" class="plandose-btn plandose-btn-light pd-rx-toform" data-rx-toform="' + i + '">' + PD.escapeHtml(t('rxToForm', 'Συμπλήρωση στη φόρμα')) + '</button>' : '') +
				'</li>';
		}).join('');
		var blocked = blockers(rx);
		var help = blocked.length
			? '<p id="pd-rx-add-help" class="pd-rx-note is-warn">' + PD.escapeHtml(t('rxAddBlocked', 'Για να ενεργοποιηθεί η «Προσθήκη», επιλέξτε ή επιβεβαιώστε τα σημειωμένα πεδία των επιλεγμένων φαρμάκων.')) + '</p>'
			: '';
		return '<p class="pd-rx-found">' + PD.escapeHtml(1 === rx.rows.length
			/* The singular, not «Βρέθηκαν 1 φάρμακα». */
			? t('rxFoundOne', 'Βρέθηκε 1 φάρμακο. Ελέγξτε το πριν μπει στο πλάνο.')
			: PD.format(t('rxFound', 'Βρέθηκαν %d φάρμακα. Ελέγξτε τα πριν μπουν στο πλάνο.'), rx.rows.length)) + '</p>' +
			(rx.countNote ? '<div class="pd-rx-block" role="alert"><p id="pd-rx-count-note">' + PD.escapeHtml(rx.countNote) + '</p>' +
				'<label class="pd-rx-check"><input type="checkbox" id="pd-rx-count-ack" aria-describedby="pd-rx-count-note"' + (rx.countAck ? ' checked' : '') + ' /> ' +
				PD.escapeHtml(t('rxCountAck', 'Έλεγξα τη συνταγή και ξέρω ποια φάρμακα λείπουν από τη λίστα.')) + '</label></div>' : '') +
			(rx.patient ? '<label class="pd-rx-check pd-rx-patient"><input type="checkbox" id="pd-rx-use-patient"' + (rx.usePatient ? ' checked' : '') + ' /> ' +
				PD.escapeHtml(PD.format(t('rxPatient', 'Όνομα ασθενή: %s'), rx.patient)) + '</label>' : '') +
			(rx.patientNote ? '<p class="pd-rx-note is-warn" role="note" id="pd-rx-patient-note">' + PD.escapeHtml(rx.patientNote) + '</p>' : '') +
			(rx.patientCheck ? '<div class="pd-rx-block"><label class="pd-rx-check"><input type="checkbox" id="pd-rx-patient-ok" aria-describedby="pd-rx-patient-note"' + (rx.patientOk ? ' checked' : '') + ' /> ' +
				PD.escapeHtml(t('rxPatientConfirm', 'Επιβεβαιώνω ότι τα φάρμακα που επιλέγω είναι του ασθενή αυτού του πλάνου.')) + '</label></div>' : '') +
			bulkTimeHtml(rx) +
			'<ul class="pd-rx-list">' + rows + '</ul>' +
			help +
			'<div class="pd-rx-actions">' +
			'<button type="button" id="pd-rx-add" class="plandose-btn plandose-btn-primary"' + (addable && !blocked.length ? '' : ' disabled') + (help ? ' aria-describedby="pd-rx-add-help"' : '') + '>' +
			PD.escapeHtml(PD.format(t('rxAdd', 'Προσθήκη στο πλάνο (%d)'), addable)) + '</button>' +
			'<button type="button" id="pd-rx-cancel" class="plandose-btn plandose-btn-light">' + PD.escapeHtml(t('rxCancel', 'Άκυρο')) + '</button>' +
			'</div>';
	}

	function rerender(focusId) {
		var host = document.getElementById('pd-rx');
		/* A change event fired while the node is being replaced. */
		if (!host || !host.parentNode) {
			return;
		}
		host.outerHTML = PD.rxImportHtml();
		PD.wireRxImport();
		var el = focusId ? document.getElementById(focusId) : null;
		if (el) {
			el.focus();
		}
	}

	function plainItem(item) {
		var mode = 'custom' === item.freq && ('weekday' === item.customMode || 'monthday' === item.customMode) ? item.customMode : 'days';
		return {
			name: String(item.name).trim().slice(0, PD.MAXLEN.drug),
			doseAmount: PD.checkDoseAmount(item.doseAmount, item.doseUnit).value,
			doseUnit: item.doseUnit,
			freq: item.freq,
			/* A once-daily time is the one chosen; for the other
			   frequencies the field is unused and holds the form's default. */
			dailyTime: '24h' === item.freq ? item.dailyTime : PD.normalizeDailyTime(item.dailyTime),
			customMode: mode,
			customIntervalDays: 'custom' === item.freq && 'days' === mode ? String(item.customIntervalDays) : '',
			customWeekday: PD.validWeekday(item.customWeekday),
			customMonthDay: PD.validMonthDay(item.customMonthDay),
			days: String(parseInt(item.days, 10)),
			notes: ''
		};
	}

	/**
	 * The patient's name goes into «Ασθενής» as soon as the
	 * pharmacist goes on with the prescription — «Προσθήκη» OR «Συμπλήρωση
	 * στη φόρμα», so a prescription whose only medicine needs the form
	 * (a vaccine for «ΠΕΡΟΥΛΙΟΣ ΑΡΡΕΝ-1») keeps the name.
	 * Guards: only when ticked (never over a name already
	 * in the plan — usePatient starts off then), never while a «different
	 * patients» check is open, and only once per paste.
	 */
	function applyPatient(rx) {
		if (!rx || !rx.usePatient || !rx.patient || (rx.patientCheck && !rx.patientOk)) {
			return;
		}
		var patientEl = document.getElementById('pd-patient');
		if (patientEl && !patientEl.value.trim()) {
			patientEl.value = rx.patient.slice(0, PD.MAXLEN.patient);
			PD.invalidatePendingPrintToken();
			PD.renderPreview();
			PD.updateStepButtons();
		}
		rx.usePatient = false;
	}

	function addSelected() {
		var rx = PD.s.rx;
		if (!rx || 'review' !== rx.stage) {
			return;
		}
		rx.rows.forEach(function (row) {
			if (!row.loaded) {
				syncQuantityWarning(row.item);
			}
		});
		/* The button is disabled then; checked again here all the same. */
		if (blockers(rx).length) {
			PD.setMessage(t('rxAddBlocked', 'Για να ενεργοποιηθεί η «Προσθήκη», επιλέξτε ή επιβεβαιώστε τα σημειωμένα πεδία των επιλεγμένων φαρμάκων.'), 'error');
			return;
		}
		applyPatient(rx);
		var added = 0;
		var left = [];
		rx.rows.forEach(function (row) {
			if (row.loaded) {
				return;
			}
			var problem = PD.rxItemProblem(row.item);
			if (row.include && !problem) {
				PD.s.items.push(plainItem(row.item));
				added++;
			} else if (problem) {
				left.push(row);
			}
		});
		if (!added) {
			return;
		}
		PD.invalidatePendingPrintToken();
		PD.renderMedicationList();
		PD.renderPreview();
		PD.updateStepButtons();
		var msg = PD.format(t('rxAdded', 'Προστέθηκαν %d φάρμακα από τη συνταγή. Ελέγξτε το πλάνο πριν την εκτύπωση.'), added);
		if (left.length) {
			/* What was acknowledged for this paste stays acknowledged. */
			PD.s.rx = {
				stage: 'review',
				patient: '',
				usePatient: false,
				patientNote: rx.patientNote,
				patientCheck: rx.patientCheck,
				patientOk: rx.patientOk,
				countNote: rx.countNote,
				countAck: rx.countAck,
				rows: left
			};
			msg += ' ' + PD.format(t('rxLeft', 'Μένουν %d για συμπλήρωση στη φόρμα.'), left.length);
			rerender();
		} else {
			PD.s.rx = null;
			rerender('pd-rx-drop');
		}
		PD.setMessage(msg, 'success');
	}

	/* Load one medicine into the normal form, where every field is checked
	   again by «Προσθήκη Φαρμάκου». */
	function toForm(index) {
		var rx = PD.s.rx;
		var row = rx && rx.rows[index];
		if (!row) {
			return;
		}
		if (PD.s.editingIndex !== null) {
			var editing = PD.s.items[PD.s.editingIndex];
			if (editing && !PD.drugFormMatchesItem(editing) && PD.blockIfUnsavedDrug()) {
				return;
			}
			PD.resetDrugForm();
		} else if (PD.blockIfUnsavedDrug()) {
			return;
		}
		applyPatient(rx);
		var item = row.item;
		/* What the prescription did not give is left for the pharmacist to
		   CHOOSE: no frequency chip is lit, «Είδος» is empty, no time of
		   day, weekday or day of the month is preset, and «Προσθήκη
		   Φαρμάκου» refuses until each is set. */
		PD.s.currentFreq = item.freq || '12h';
		PD.s.rxNeedsFreq = !item.freq;
		PD.s.currentCustomMode = PD.customModeOf ? PD.customModeOf(item) : ('weekday' === item.customMode ? 'weekday' : 'days');
		PD.s.currentDailyTime = Object.prototype.hasOwnProperty.call(PD.dailyTimeLabels(), item.dailyTime)
			? item.dailyTime
			: ('24h' === item.freq ? null : 'morning');
		PD.s.currentWeekday = PD.validWeekday(item.customWeekday);
		PD.s.currentMonthDay = PD.validMonthDay(item.customMonthDay);
		/* Shown read-only above the form until this medicine is added or
		   the form is cleared (PD.updateRxSource()). */
		PD.s.rxSourceText = item.origText || item.source || '';
		PD.setFieldValue('pd-drug', item.name || '');
		PD.setFieldValue('pd-dose-amount', typeof item.doseAmount === 'number' ? PD.formatDose(item.doseAmount) : String(item.doseAmount || ''));
		PD.setFieldValue('pd-dose-unit', item.doseUnit || '');
		PD.setFieldValue('pd-days', item.days || '');
		PD.setFieldValue('pd-notes', '');
		PD.setFieldValue('pd-custom-interval-days', item.customIntervalDays || '');
		PD.updateFreqChips();
		PD.updateCustomModeChips();
		PD.updateDailyTimeChips();
		PD.updateWeekdayChips();
		if (PD.updateMonthDayField) {
			PD.updateMonthDayField();
		}
		PD.updateCustomFieldsVisibility();
		if (PD.updateRxSource) {
			PD.updateRxSource();
		}
		if (PD.updateDoseTotal) {
			PD.updateDoseTotal();
		}
		var notes = item.warnings.map(PD.prescriptionWarningText);
		notes.unshift(PD.format(t('rxSource', 'Συνταγή: %s'), item.source || '—'));
		/* The row stays, marked, so the pharmacist still sees which
		   medicines of the prescription are done. */
		row.loaded = true;
		row.include = false;
		var open = rx.rows.some(function (r) {
			return !r.loaded;
		});
		if (!open) {
			PD.s.rx = null;
		}
		rerender();
		PD.goToStep(1);
		PD.setMessage(notes.join(' — '), 'error');
		var drugEl = document.getElementById('pd-drug');
		if (drugEl) {
			drugEl.focus();
		}
	}

	/* The controls of the review stage (PD.wireRxImport() calls it after
	   every render). */
	function wireReview(host) {
		var add = document.getElementById('pd-rx-add');
		if (add) {
			add.addEventListener('click', addSelected);
		}
		var usePatient = document.getElementById('pd-rx-use-patient');
		if (usePatient) {
			usePatient.addEventListener('change', function () {
				PD.s.rx.usePatient = usePatient.checked;
			});
		}
		var flag = function (id, key) {
			var box = document.getElementById(id);
			if (box) {
				box.addEventListener('change', function () {
					PD.s.rx[key] = box.checked;
					rerender(id);
				});
			}
		};
		flag('pd-rx-patient-ok', 'patientOk');
		flag('pd-rx-count-ack', 'countAck');
		var rowOf = function (el, attr) {
			return PD.s.rx.rows[parseInt(el.getAttribute(attr), 10)];
		};
		host.querySelectorAll('[data-rx-include]').forEach(function (box) {
			box.addEventListener('change', function () {
				rowOf(box, 'data-rx-include').include = box.checked;
				rerender(box.id);
			});
		});
		host.querySelectorAll('[data-rx-confirm]').forEach(function (box) {
			box.addEventListener('change', function () {
				var item = rowOf(box, 'data-rx-confirm').item;
				item.confirmed = item.confirmed || {};
				item.confirmed[box.getAttribute('data-rx-field')] = box.checked;
				rerender(box.id);
			});
		});
		host.querySelectorAll('[data-rx-time]').forEach(function (chip) {
			chip.addEventListener('click', function () {
				var tItem = rowOf(chip, 'data-rx-row').item;
				tItem.dailyTime = chip.getAttribute('data-rx-time');
				if (tItem.bulkTime && tItem.bulkTime !== tItem.dailyTime) {
					tItem.bulkTime = null; /* changed on its own */
				}
				rerender(chip.id);
			});
		});
		/* «Ίδια ώρα για όλα τα 1×ημέρα»: only the rows without a
		   time get it; a time already chosen is never overwritten, and
		   nothing but the time is settled. */
		host.querySelectorAll('[data-rx-bulk]').forEach(function (chip) {
			chip.addEventListener('click', function () {
				var slot = chip.getAttribute('data-rx-bulk');
				var labels = PD.dailyTimeLabels();
				if (!Object.prototype.hasOwnProperty.call(labels, slot)) {
					return;
				}
				var rows = bulkTimeRows(PD.s.rx);
				rows.forEach(function (row) {
					row.item.dailyTime = slot;
					row.item.bulkTime = slot;
				});
				PD.s.rx.bulkStatus = PD.format(t('rxBulkDone', '«%1$s»: ορίστηκε σε %2$d φάρμακα.'), labels[slot], rows.length);
				rerender('pd-rx-bulk-status');
			});
		});
		host.querySelectorAll('[data-rx-weekday]').forEach(function (chip) {
			chip.addEventListener('click', function () {
				var wItem = rowOf(chip, 'data-rx-row').item;
				wItem.customWeekday = PD.validWeekday(chip.getAttribute('data-rx-weekday'));
				/* Other dates, other count: confirmed again. */
				if (wItem.confirmed) {
					wItem.confirmed.days = false;
				}
				rerender(chip.id);
			});
		});
		host.querySelectorAll('[data-rx-monthday]').forEach(function (input) {
			input.addEventListener('change', function () {
				var mItem = rowOf(input, 'data-rx-monthday').item;
				mItem.customMonthDay = PD.validMonthDay(input.value);
				if (mItem.confirmed) {
					mItem.confirmed.days = false;
				}
				rerender(input.id);
			});
		});
		host.querySelectorAll('[data-rx-toform]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				toForm(parseInt(btn.getAttribute('data-rx-toform'), 10));
			});
		});
	}

	R.reviewBody = reviewBody;
	R.wireReview = wireReview;
	R.rerender = rerender;
})();
