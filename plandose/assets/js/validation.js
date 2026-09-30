/**
 * PlanDose — validation.js
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
	 * Check the plan's start date. Shows the error and focuses
	 * the field when there is a problem.
	 *
	 * @return {boolean} True when the start date is usable.
	 */
	PD.validateStartDate = function validateStartDate() {
		var problem = PD.startDateProblem(PD.s.startDate);
		if (!problem) {
			return true;
		}
		/* goToStep() clears the message, so it runs first. */
		if (PD.s.currentStep !== 1 && typeof PD.goToStep === 'function') {
			PD.goToStep(1);
		}
		/* Marked aria-invalid and linked to the message. */
		PD.flagFieldError('pd-start-date', PD.startDateMessage(problem));
		return false;
	};

	/**
	 * The message for a checkDoseAmount() error, naming what is
	 * wrong instead of one generic "not a number".
	 *
	 * @param {string} error Error code from PD.checkDoseAmount().
	 * @param {string} raw   What was typed (quoted back for 'thousands').
	 * @param {string} name  Medicine name (for 'empty').
	 * @return {string}
	 */
	PD.doseAmountMessage = function doseAmountMessage(error, raw, name, unit) {
		if ('empty' === error) {
			return PD.txt('missingDoseAmount', 'Συμπληρώστε την ποσότητα δόσης για το φάρμακο: ') + name;
		}
		if ('thousands' === error) {
			return PD.format(PD.txt('doseAmountThousands', 'Η ποσότητα «%s» είναι ασαφής: μπορεί να διαβαστεί ως χιλιάδες. Γράψτε π.χ. 1 ή 1,5 — ή 1000 για χίλια.'), String(raw).trim());
		}
		if ('mixed' === error && /^\s*\d+\s+\d+\s*\/\s*\d+\s*$/.test(String(raw))) {
			/* A whole part with an improper fraction («1 11/2»). */
			return PD.format(
				PD.txt('doseAmountMixedWhole', 'Η ποσότητα «%s» δεν είναι έγκυρος μικτός αριθμός: ο αριθμητής του κλάσματος πρέπει να είναι μικρότερος από τον παρονομαστή (π.χ. 1 1/2). Ελέγξτε την τιμή.'),
				String(raw).trim()
			);
		}
		if ('mixed' === error) {
			/* "11/2" → «1 1/2». Whole part = the numerator without
			   its last digit; the last digit stays over the denominator. */
			var mm = /^\s*(\d+)\s*\/\s*(\d+)\s*$/.exec(String(raw));
			var num = mm ? mm[1] : '';
			var den = mm ? parseInt(mm[2], 10) : 0;
			var last = parseInt(num.slice(-1), 10);
			var literal = mm && den ? PD.formatDecimal(Math.round(parseInt(num, 10) / den * 100) / 100) : '';
			/* Only a reading that is itself a proper fraction ("11/2" → «1 1/2»,
			   "13/4" → «1 3/4»); "10/2" or "15/4" get the plain message. */
			if (!mm || !(last > 0 && last < den)) {
				return PD.format(
					PD.txt('doseAmountMixedPlain', 'Η ποσότητα «%1$s» διαβάζεται ως %2$s. Ελέγξτε την τιμή: γράψτε μικτό αριθμό με κενό (π.χ. 1 1/2) ή δεκαδικό (π.χ. 1,5).'),
					String(raw).trim(),
					literal
				);
			}
			var meant = num.slice(0, -1) + ' ' + last + '/' + den;
			return PD.format(
				PD.txt('doseAmountMixed', 'Η ποσότητα «%1$s» διαβάζεται ως %2$s. Αν εννοείτε %3$s, γράψτε το με κενό («%3$s») ή ως δεκαδικό (π.χ. 1,5).'),
				String(raw).trim(),
				literal,
				meant
			);
		}
		if ('decimals' === error && PD.doseDecimals && PD.doseDecimals(unit) > 2) {
			/* mg takes three decimals (0,125 mg). */
			return PD.txt('doseAmountDecimalsMg', 'Η ποσότητα σε mg μπορεί να έχει έως 3 δεκαδικά ψηφία (π.χ. 0,125).');
		}
		if ('decimals' === error) {
			return PD.txt('doseAmountDecimals', 'Η ποσότητα δόσης μπορεί να έχει έως 2 δεκαδικά ψηφία (π.χ. 0,25).');
		}
		if ('zero' === error) {
			return PD.txt('doseAmountZero', 'Η ποσότητα δόσης πρέπει να είναι μεγαλύτερη από το μηδέν.');
		}
		if ('max' === error) {
			return PD.format(PD.txt('doseAmountTooLarge', 'Η ποσότητα δόσης δεν μπορεί να ξεπερνά το %s ανά λήψη. Ελέγξτε την τιμή.'), PD.formatDecimal(PD.doseMax(unit)));
		}
		return PD.txt('invalidDoseAmount', 'Η ποσότητα δόσης δεν είναι έγκυρη. Γράψτε αριθμό όπως 1, 1,5, 0,25, ½ ή 1/2.');
	};

	/**
	 * A methotrexate medicine set more often than once a week —
	 * a daily frequency, or «κάθε N ημέρες» with N under 7. The name list
	 * matches brand and generic spellings (Latin or Greek letters).
	 */
	PD.METHOTREXATE_NAMES = /(METHOTREXAT|METOTREXAT|ΜΕΘΟΤΡΕΞΑΤ|NORDIMET|METOJECT|TREXAN|JYLAMVO|EBETREXAT|EMTHEXAT|ZEXATE|XATMEP|OTREXUP|RASUVO|ΕΜΘΕΞΑΤ|ΜΕΤΟΤΡΕΞΑΤ|METHOX-?F(?![A-Z]))/;
	/* (METHOX-F: a methotrexate pre-filled syringe seen on a real
	   prescription.) */

	/* The common abbreviation, as a word of its own. */
	PD.METHOTREXATE_ABBR = /(^|[^A-Z0-9])MTX(?=[^A-Z0-9]|$)/;

	/* Greek capitals that look like Latin ones («ΝΟRDΙΜΕΤ» typed with
	   Greek letters) are folded to Latin before the Latin names are
	   matched; the Greek spellings are matched on the unfolded name. */
	var GREEK_TO_LATIN = {
		'Α': 'A', 'Β': 'B', 'Ε': 'E', 'Ζ': 'Z', 'Η': 'H', 'Ι': 'I', 'Κ': 'K',
		'Μ': 'M', 'Ν': 'N', 'Ο': 'O', 'Ρ': 'P', 'Τ': 'T', 'Υ': 'Y', 'Χ': 'X'
	};

	PD.isMethotrexateName = function isMethotrexateName(rawName) {
		var name = String(rawName || '').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		var folded = name.replace(/[ΑΒΕΖΗΙΚΜΝΟΡΤΥΧ]/g, function (c) {
			return GREEK_TO_LATIN[c];
		});
		var forms = [name, folded];
		for (var i = 0; i < forms.length; i++) {
			/* «METHOTREX ATE» when the name wrapped on the prescription. */
			if (PD.METHOTREXATE_NAMES.test(forms[i]) ||
				PD.METHOTREXATE_NAMES.test(forms[i].replace(/[\s\-\/.]+/g, '')) ||
				PD.METHOTREXATE_ABBR.test(forms[i])) {
				return true;
			}
		}
		return false;
	};

	PD.methotrexateTooOften = function methotrexateTooOften(item) {
		if (!item) {
			return false;
		}
		if (!PD.isMethotrexateName(item.name)) {
			return false;
		}
		if ('custom' !== item.freq) {
			return true;
		}
		/* A chosen weekday, or once a month: not more often than weekly. */
		if ('weekday' === item.customMode || 'monthday' === item.customMode) {
			return false;
		}
		var n = parseInt(item.customIntervalDays, 10);
		return !(n >= 7);
	};

	/* Insulin by name. INN stems (Latin and
	   Greek) are matched on the name as written / folded; the brands on a
	   phonetic skeleton of the name, so a Greek-letter spelling
	   («ΛΑΝΤΟΥΣ», «λαντους», «ΝΟΒΟΡΑΠΙΝΤ») matches its Latin brand. */
	PD.INSULIN_NAMES = /(^|[^A-Z])(INSULIN[A-Z]*|GLARGIN[A-Z]*|LISPRO|DEGLUDEC|DETEMIR|GLULISIN[A-Z]*|ISOPHAN[A-Z]*|ASPART)(?=[^A-Z]|$)|ΙΝΣΟΥΛΙΝ|ΓΛΑΡΓΙΝ|ΛΙΣΠΡΟ|ΝΤΕΓΚΛΟΥΝΤΕΚ|ΝΤΕΤΕΜΙΡ|ΓΛΟΥΛΙΣΙΝ|ΙΣΟΦΑΝ|ΑΣΠΑΡΤ(?![Α-Ω])/;

	var INSULIN_BRANDS = ['LANTUS', 'TOUJEO', 'ABASAGLAR', 'BASAGLAR', 'SEMGLEE', 'REZVOGLAR', 'TRESIBA', 'LEVEMIR', 'NOVORAPID', 'FIASP',
		'HUMALOG', 'LYUMJEV', 'LIPROLOG', 'ADMELOG', 'APIDRA', 'INSUMAN', 'HUMULIN', 'HUMINSULIN', 'ACTRAPID', 'ACTRAPHANE', 'PROTAPHANE',
		'MIXTARD', 'NOVOMIX', 'RYZODEG', 'XULTOPHY', 'SULIQUA', 'SOLIQUA', 'ABASRIA', 'KIRSTY', 'TRUVELOG', 'AWIQLI', 'INSULATARD', 'TRURAPI'];

	var GREEK_SOUNDS = [[/ΟΥ/g, 'U'], [/ΜΠ/g, 'B'], [/ΝΤ/g, 'D'], [/ΓΚ/g, 'G'], [/ΓΓ/g, 'NG'], [/ΤΖ/g, 'Z'], [/ΤΣ/g, 'TS']];
	var GREEK_LETTERS = { Α: 'A', Β: 'V', Γ: 'G', Δ: 'D', Ε: 'E', Ζ: 'Z', Η: 'I', Θ: 'T', Ι: 'I', Κ: 'K', Λ: 'L', Μ: 'M', Ν: 'N', Ξ: 'KS',
		Ο: 'O', Π: 'P', Ρ: 'R', Σ: 'S', Τ: 'T', Υ: 'I', Φ: 'F', Χ: 'H', Ψ: 'PS', Ω: 'O' };

	/* A spelling-independent skeleton: Greek letters transliterated, and
	   the Latin letters that Greek writes otherwise reduced (D↔ΝΤ, B↔ΜΠ,
	   OU↔ΟΥ, J↔ΤΖ, C/Q↔Κ, Y↔Ι, H↔Χ dropped), double letters once. */
	function skeleton(text) {
		var t = String(text || '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
		GREEK_SOUNDS.forEach(function (r) {
			t = t.replace(r[0], r[1]);
		});
		t = t.replace(/[Α-Ω]/g, function (c) {
			return GREEK_LETTERS[c] || c;
		});
		return t.replace(/OY/g, 'U').replace(/PH/g, 'F').replace(/TH/g, 'T').replace(/OU/g, 'U').replace(/TZ/g, 'Z').replace(/J/g, 'Z')
			.replace(/[CQ]/g, 'K').replace(/Y/g, 'I').replace(/W/g, 'V').replace(/X/g, 'KS').replace(/H/g, '')
			.replace(/D/g, 'NT').replace(/B/g, 'MP').replace(/([A-Z])\1+/g, '$1');
	}

	var BRAND_SKELETONS = new RegExp('(^|[^A-Z])(' + INSULIN_BRANDS.map(skeleton).join('|') + ')(?=[^A-Z]|$)');

	/* Units per ml, as the print-outs write it («100U/ML»,
	   «100 IU/ml», «100U/1ML», «(100U+33MCG)/ML», «100 Μ.Ο./ML»), on the
	   name folded to Latin capitals. */
	PD.UNITS_PER_ML = /(?:^|[^A-Z])(?:UNITS?|IU|U)\s*\/\s*1?\s*ML(?![A-Z])|\d\s*(?:UNITS?|IU|U)\s*\+[^)]*\)\s*\/\s*1?\s*ML(?![A-Z])|(?:^|[^A-Z])M\.\s?O\.\s*\/\s*1?\s*ML(?![A-Z])/;

	/* Products in IU that are not insulin (one injection). */
	PD.NON_INSULIN_IU = /(^|[^A-Z])(TETAGAM|TETABULIN|HEPATECT|RHOPHYLAC|RHESONATIV|CLEXANE|FRAGMIN|INNOHEP|HEPARIN[A-Z]*|ARANESP|EPREX|NEORECORMON|BINOCRIT|RETACRIT|ABSEAMED|GONAL|PUREGON|MENOPUR|OVALEAP|BEMFOLA|VIGANTOL|LECALCIF|DE3|D3|IMMUNOGLOBULIN[A-Z]*)(?=[^A-Z]|$)|ΑΝΟΣΟΣΦΑΙΡΙΝ|ΒΙΤΑΜΙΝΗ D/;

	function foldName(rawName) {
		var name = String(rawName || '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
		return {
			name: name,
			folded: name.replace(/[ΑΒΕΖΗΙΚΜΝΟΡΤΥΧ]/g, function (c) {
				return GREEK_TO_LATIN[c];
			})
		};
	}

	/**
	 * An insulin: a known insulin brand or INN stem (whatever the letters
	 * it is typed in), or (unless `byName`) an injectable whose strength is
	 * in units per ml and that is not a single pre-filled syringe or
	 * ampoule of a known non-insulin (TETAGAM P 250 IU/ML). Its dose is
	 * planned in units (IU) only.
	 */
	PD.isInsulinName = function isInsulinName(rawName, byName) {
		var f = foldName(rawName);
		if (PD.INSULIN_NAMES.test(f.name) || PD.INSULIN_NAMES.test(f.folded) ||
			BRAND_SKELETONS.test(skeleton(f.name)) || BRAND_SKELETONS.test(skeleton(f.folded))) {
			return true;
		}
		if (byName) {
			return false;
		}
		return PD.UNITS_PER_ML.test(f.folded) && !PD.NON_INSULIN_IU.test(f.folded) &&
			/(^|[^A-Z])(INJ|IN\.SO|PEN|CART|SOLOSTAR|FLEXTOUCH|FLEXPEN|KWIKPEN|PENFILL|VIAL)/.test(f.folded) &&
			!/(^|[^A-Z])(PFS|PF\.?SYR|AMP)/.test(f.folded);
	};

	/** A known non-insulin product in IU (TETAGAM, CLEXANE, …). */
	PD.isNonInsulinIu = function isNonInsulinIu(rawName) {
		var f = foldName(rawName);
		return PD.NON_INSULIN_IU.test(f.folded) || PD.NON_INSULIN_IU.test(f.name);
	};

	/* Medicines normally given ONCE A WEEK, the way
	   methotrexate is. Each entry: the names (on the name folded to Latin
	   capitals, and without spaces/dots for a wrapped name), and what else
	   the name must show when the brand also exists in a daily form.
	   Semaglutide as an injection only (RYBELSUS tablets are daily);
	   exenatide only as the weekly BYDUREON (BYETTA is twice a day);
	   alendronate 70 mg and risedronate 35 mg only. */
	var WEEKLY_ONLY = [
		{ re: /OZEMPIC|WEGOVY|ΟΖΕΜΠΙΚ/ },
		{ re: /SEMAGLUTID|ΣΕΜΑΓΛΟΥΤΙΔ/, also: /(^|[^A-Z])(INJ|IN\.SO|PEN|PFS|PF\.?PEN)|ΕΝΕΣ|ΣΥΡΙΓΓ|ΠΕΝΑ/, not: /RYBELSUS|(^|[^A-Z])(TAB|TABS|F\.C\.TAB)(?![A-Z])|ΔΙΣΚΙ/ },
		{ re: /TRULICITY|DULAGLUTID|ΝΤΟΥΛΑΓΛΟΥΤΙΔ|ΔΟΥΛΑΓΛΟΥΤΙΔ|ΤΡΟΥΛΙΣΙΤΙ/ },
		{ re: /MOUNJARO|TIRZEPATID|ΤΙΡΖΕΠΑΤΙΔ|ΜΟΥΝΤΖΑΡΟ/ },
		{ re: /BYDUREON/ },
		{ re: /FOSAVANCE|ΦΟΣΑΒΑΝΣ/ },
		{ re: /FOSAMAX|ALENDRON|ΑΛΕΝΔΡΟΝ|BINOSTO|ΦΟΣΑΜΑΞ/, also: /(^|[^\d.,])70(?!\d|[.,]\d)|ONCE ?WEEKLY/ },
		{ re: /ACTONEL|RISEDRON|ΡΙΣΕΔΡΟΝ|ΑΚΤΟΝΕΛ/, also: /(^|[^\d.,])35(?!\d|[.,]\d)|(^|[^A-Z])OAW(?![A-Z])|ONCE ?A ?WEEK/ }
	];

	/**
	 * Which WEEKLY_ONLY rule the name matches (its index), or -1.
	 * Two plan entries with the same index are the same once-a-week
	 * medicine, whatever strength or spelling each carries.
	 */
	function weeklyOnlyRule(rawName) {
		var name = String(rawName || '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
		var folded = name.replace(/[ΑΒΕΖΗΙΚΜΝΟΡΤΥΧ]/g, function (c) {
			return GREEK_TO_LATIN[c];
		});
		var forms = [name, folded];
		for (var i = 0; i < WEEKLY_ONLY.length; i++) {
			var rule = WEEKLY_ONLY[i];
			for (var k = 0; k < forms.length; k++) {
				var f = forms[k];
				var hit = rule.re.test(f) || rule.re.test(f.replace(/[\s\-\/.]+/g, ''));
				if (hit && (!rule.also || rule.also.test(f) || rule.also.test(name)) && !(rule.not && (rule.not.test(f) || rule.not.test(name)))) {
					return i;
				}
			}
		}
		return -1;
	}

	/** True when the name is one of the once-a-week medicines above. */
	PD.isWeeklyOnlyName = function isWeeklyOnlyName(rawName) {
		return weeklyOnlyRule(rawName) !== -1;
	};

	/**
	 * Patient safety: the key two plan entries share when they
	 * are the same medicine as typed — upper case, no accents, spaces
	 * collapsed. The strength is part of the name, so «SINTROM 1MG» and
	 * «SINTROM 4MG» stay different.
	 */
	PD.medicineKey = function medicineKey(rawName) {
		return String(rawName || '').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			.replace(/\s+/g, ' ').trim();
	};

	/**
	 * Patient safety: once-a-week medicines that appear in MORE
	 * THAN ONE plan entry. Each entry can be weekly on its own («κάθε
	 * Δευτέρα» + «κάθε Πέμπτη») and still add up to twice a week, which
	 * the per-entry check cannot see. Methotrexate entries are grouped
	 * together whatever the brand; the other once-a-week medicines by
	 * their rule (OZEMPIC 0.25 and OZEMPIC 1 are one medicine).
	 *
	 * @return {{mtx: Array, weekly: Array}} The entries in such groups.
	 */
	PD.weeklyRepeatedItems = function weeklyRepeatedItems(items) {
		var groups = {};
		(items || []).forEach(function (item) {
			if (!item) {
				return;
			}
			var g = PD.isMethotrexateName(item.name) ? 'mtx' : weeklyOnlyRule(item.name);
			if (-1 === g) {
				return;
			}
			(groups[g] = groups[g] || []).push(item);
		});
		var out = { mtx: [], weekly: [] };
		Object.keys(groups).forEach(function (g) {
			if (groups[g].length > 1) {
				out['mtx' === g ? 'mtx' : 'weekly'] = out['mtx' === g ? 'mtx' : 'weekly'].concat(groups[g]);
			}
		});
		return out;
	};

	/* The frequency test shared with methotrexate: daily, or every N < 7
	   days. A chosen weekday or a day of the month is not more often. */
	function moreOftenThanWeekly(item) {
		if ('custom' !== item.freq) {
			return true;
		}
		if ('weekday' === item.customMode || 'monthday' === item.customMode) {
			return false;
		}
		var n = parseInt(item.customIntervalDays, 10);
		return !(n >= 7);
	}

	/**
	 * A once-a-week medicine (semaglutide injection, dulaglutide,
	 * tirzepatide, exenatide ER, alendronate 70 mg, risedronate 35 mg)
	 * set more often than once a week. Like methotrexate: a warning in the
	 * form, the prescription import sends it to the form, and printing
	 * needs a second press.
	 */
	PD.weeklyOnlyTooOften = function weeklyOnlyTooOften(item) {
		return !!item && !!item.freq && PD.isWeeklyOnlyName(item.name) && moreOftenThanWeekly(item);
	};

	/**
	 * Last check of the WHOLE plan, run by handlePrint() before
	 * the print window opens and before any credit can be used.
	 *
	 * Each drug was checked when it was added, but the day of the check
	 * and the start date can both have changed since: a "κάθε Σάββατο"
	 * drug added on Friday night for 1 day has no dose on Saturday
	 * morning's sheet, and moving the start date can do the same. The
	 * interval is re-checked too, so a malformed item can never be
	 * printed as "once a week" by getIntervalDays()'s fallback.
	 *
	 * @return {boolean} True when the plan can be printed.
	 */
	PD.validatePlanForPrint = function validatePlanForPrint() {
		if (!PD.validateStartDate()) {
			return false;
		}
		PD.beginDayPass();
		for (var i = 0; i < PD.s.items.length; i++) {
			var item = PD.s.items[i];
			/* Every medicine again, as the form checks it — dose,
			   «Είδος», frequency and duration — whatever path put it in
			   the plan (form, prescription import, an older version). */
			if (PD.planItemInvalid(item)) {
				if (PD.s.currentStep !== 1 && typeof PD.goToStep === 'function') {
					PD.goToStep(1);
				}
				PD.setMessage(PD.format(PD.txt('invalidItemForPrint', 'Ελέγξτε το φάρμακο «%s»: η ποσότητα, το «Είδος», η συχνότητα ή η διάρκεια δεν είναι έγκυρα. Πατήστε «Επεξεργασία».'), item.name), 'error');
				return false;
			}
			/* The «κάθε Ν ημέρες» interval (1–90) is already checked by
			   planItemInvalid() above ('interval'), so only the dose
			   count is left to check here. */
			var totals = PD.doseTotals(item);
			if (!totals || totals.doses < 1) {
				if (PD.s.currentStep !== 1 && typeof PD.goToStep === 'function') {
					PD.goToStep(1);
				}
				PD.setMessage(
					PD.txt('noDosesInPeriod', 'Με αυτή τη διάρκεια δεν πέφτει καμία δόση — αυξήστε τη διάρκεια ή αλλάξτε την ημέρα. Φάρμακο: ') + item.name,
					'error'
				);
				return false;
			}
		}
		if (PD.methotrexateNeedsConfirm(PD.s.items)) {
			return false;
		}
		return true;
	};

	/**
	 * Methotrexate more often than weekly stays the pharmacist's
	 * call (a warning in the form, not a block), but nothing prints
	 * without it being seen once more: the first press shows the
	 * warning, a second press with the same medicines prints. Used by the
	 * A4 plan and the Pro labels.
	 *
	 * @return {boolean} True when printing must stop for now.
	 */
	PD.methotrexateNeedsConfirm = function methotrexateNeedsConfirm(items) {
		/* The once-a-week medicines (PD.weeklyOnlyTooOften) are
		   seen twice the same way — here, so the A4 plan and the Pro
		   labels both get it. */
		var mtx = false;
		/* Also the same once-a-week medicine in two or more plan
		   entries (PD.weeklyRepeatedItems()). */
		var repeated = PD.weeklyRepeatedItems(items);
		var keys = (items || []).filter(function (item) {
			var m = PD.methotrexateTooOften(item);
			mtx = mtx || m;
			return m || PD.weeklyOnlyTooOften(item) ||
				repeated.mtx.indexOf(item) !== -1 || repeated.weekly.indexOf(item) !== -1;
		}).map(function (item) {
			return item.name + '|' + item.freq + '|' + item.customMode + '|' + item.customIntervalDays + '|' +
				item.customWeekday + '|' + item.customMonthDay;
		});
		var key = keys.join('\n');
		if (!keys.length || PD.s.methotrexateAck === key) {
			return false;
		}
		PD.s.methotrexateAck = key;
		var names = function (list) {
			var seen = {};
			return list.filter(function (item) {
				var k = PD.medicineKey(item.name);
				var fresh = !seen[k];
				seen[k] = true;
				return fresh;
			}).map(function (item) {
				return item.name;
			}).join('», «');
		};
		/* Every reason is named: one press acknowledges them all, so none
		   may be hidden behind another. */
		var texts = [];
		if (mtx) {
			texts.push(PD.txt('methotrexatePrintConfirm', 'ΠΡΟΣΟΧΗ: μεθοτρεξάτη συχνότερα από μία φορά την εβδομάδα. Αν η συχνότητα είναι σωστή, πατήστε ξανά «Εκτύπωση».'));
		} else if (repeated.mtx.length) {
			texts.push(PD.format(PD.txt('methotrexateRepeatedPrintConfirm', 'ΠΡΟΣΟΧΗ: μεθοτρεξάτη σε περισσότερες από μία εγγραφές του πλάνου («%s») — μαζί μπορεί να δίνουν δόση συχνότερα από μία φορά την εβδομάδα. Αν είναι σωστό, πατήστε ξανά «Εκτύπωση».'), names(repeated.mtx)));
		}
		if ((items || []).some(PD.weeklyOnlyTooOften)) {
			texts.push(PD.format(PD.txt('weeklyOnlyPrintConfirm', 'ΠΡΟΣΟΧΗ: «%s» συχνότερα από μία φορά την εβδομάδα — συνήθως χορηγείται μία φορά την εβδομάδα. Αν η συχνότητα είναι επιβεβαιωμένη με τον γιατρό, πατήστε ξανά «Εκτύπωση».'), names((items || []).filter(PD.weeklyOnlyTooOften))));
		}
		var weeklyRepeatedOnly = repeated.weekly.filter(function (item) {
			return !PD.weeklyOnlyTooOften(item);
		});
		if (weeklyRepeatedOnly.length) {
			texts.push(PD.format(PD.txt('weeklyRepeatedPrintConfirm', 'ΠΡΟΣΟΧΗ: «%s» σε περισσότερες από μία εγγραφές του πλάνου — συνήθως χορηγείται μία φορά την εβδομάδα. Αν είναι σωστό, πατήστε ξανά «Εκτύπωση».'), names(weeklyRepeatedOnly)));
		}
		PD.setMessage(texts.join(' '), 'error');
		return true;
	};

	/**
	 * Why a plan item could not have been added through the form,
	 * or '' — the same limits validateDrugForm() enforces.
	 */
	PD.planItemInvalid = function planItemInvalid(item) {
		if (!item || !String(item.name || '').trim()) {
			return 'name';
		}
		var unitKnown = PD.unitOptions.some(function (u) {
			return u.val === item.doseUnit;
		});
		if (!unitKnown) {
			return 'unit';
		}
		/* Insulin only in units. */
		if ('iu' !== item.doseUnit && PD.isInsulinName(item.name)) {
			return 'unit';
		}
		if (PD.checkDoseAmount(item.doseAmount, item.doseUnit).error) {
			return 'amount';
		}
		if (['24h', '12h', '8h', '6h', 'custom'].indexOf(item.freq) === -1) {
			return 'freq';
		}
		var daysRaw = String(item.days == null ? '' : item.days).trim();
		var days = Number(daysRaw);
		if (!/^\d+$/.test(daysRaw) || days < 1 || days > PD.MAX_DAYS) {
			return 'days';
		}
		/* The schedule details too, instead of the sheet quietly
		   turning a bad weekday into Monday or a bad time into «Πρωί». */
		if ('24h' === item.freq && !Object.prototype.hasOwnProperty.call(PD.dailyTimeLabels(), item.dailyTime)) {
			return 'time';
		}
		if ('custom' === item.freq) {
			var mode = item.customMode;
			if ('days' === mode) {
				var raw = String(item.customIntervalDays == null ? '' : item.customIntervalDays).trim();
				var n = Number(raw);
				if (!/^\d+$/.test(raw) || n < 1 || n > 90) {
					return 'interval';
				}
			} else if ('weekday' === mode) {
				if (null === PD.validWeekday(item.customWeekday)) {
					return 'weekday';
				}
			} else if ('monthday' === mode) {
				if (null === PD.validMonthDay(item.customMonthDay)) {
					return 'monthday';
				}
			} else {
				return 'mode';
			}
		}
		return '';
	};

	/**
	 * Patient safety: stop Preview / Print / label print while a
	 * medicine sits typed in the form but was never added to the plan.
	 *
	 * Checking only items.length is not enough: a plan with Depon added
	 * and Augmentin typed but not added would print with Depon ONLY — and
	 * the patient would go home without the antibiotic on the sheet.
	 * There is deliberately no "print anyway": the pharmacist either adds
	 * the medicine or clears the form («Καθαρισμός»). Runs before any
	 * print window opens or any request is sent, so nothing is charged.
	 *
	 * @return {boolean} True when the action was blocked.
	 */
	PD.blockIfUnsavedDrug = function blockIfUnsavedDrug() {
		if (typeof PD.hasUnsavedDrugForm !== 'function' || !PD.hasUnsavedDrugForm()) {
			return false;
		}
		/* goToStep() clears the message, so it runs first. */
		if (PD.s.currentStep !== 1 && typeof PD.goToStep === 'function') {
			PD.goToStep(1);
		}
		var text = (PD.s.editingIndex !== null)
			? PD.txt('unsavedDrugEditing', 'Δεν έχετε αποθηκεύσει τις αλλαγές στο φάρμακο που επεξεργάζεστε. Πατήστε «Αποθήκευση Φαρμάκου» ή «Καθαρισμός» πριν συνεχίσετε.')
			: PD.txt('unsavedDrug', 'Έχετε συμπληρώσει φάρμακο που δεν έχει προστεθεί στο πλάνο. Πατήστε «Προσθήκη Φαρμάκου» ή καθαρίστε τη φόρμα πριν συνεχίσετε.');
		/* The name field is not wrong, so it is focused and linked to the
		   message but not marked aria-invalid. */
		PD.flagFieldError('pd-drug', text, true);
		return true;
	};

	PD.validateDrugForm = function validateDrugForm() {
		/* The dose count below depends on the start date, so a bad start
		   date has to be fixed first. */
		if (!PD.validateStartDate()) {
			return false;
		}
		var nameEl = document.getElementById('pd-drug');
		var daysEl = document.getElementById('pd-days');
		var name = PD.currentDrugName();
		if (!name) {
			PD.flagFieldError(nameEl, PD.txt('missingDrugName', 'Συμπληρώστε όνομα φαρμάκου.'));
			return false;
		}
		/* The quantity is what tells the patient how much to take. An
		   empty field must not pass silently and print a plan whose
		   summary says only "2 φορές την ημέρα • 5 ημέρες", with no
		   amount anywhere on the sheet. */
		var doseEl = document.getElementById('pd-dose-amount');
		var doseRaw = PD.currentDoseAmount();
		/* One check with a specific message per problem (see
		   PD.checkDoseAmount()): "1.000" is refused as ambiguous, more
		   than two decimals, zero and values over the unit's ceiling
		   (PD.doseMax()) too. */
		var doseUnit = PD.currentDoseUnit();
		/* A medicine loaded from a prescription whose unit could
		   not be read comes with «Είδος» empty — it must be chosen, never
		   defaulted to «Δισκίο». */
		var unitKnown = PD.unitOptions.some(function (u) {
			return u.val === doseUnit;
		});
		if (!unitKnown) {
			PD.flagFieldError('pd-dose-unit', PD.txt('rxPickUnit', 'Επιλέξτε «Είδος».'));
			return false;
		}
		/* Insulin is planned in units, never as «1 ένεση». */
		if ('iu' !== doseUnit && PD.isInsulinName(name)) {
			PD.flagFieldError('pd-dose-unit', PD.txt('insulinUnitsRequired', 'Ινσουλίνη: η δόση γράφεται σε μονάδες (IU). Επιλέξτε «Μονάδες (IU)» και συμπληρώστε τις μονάδες της συνταγής.'));
			return false;
		}
		/* Same for a frequency the prescription did not give. */
		if (PD.s.rxNeedsFreq) {
			PD.setMessage(PD.txt('rxPickFreq', 'Επιλέξτε συχνότητα λήψης — η συνταγή δεν τη διάβασε με βεβαιότητα.'), 'error');
			return false;
		}
		var dose = PD.checkDoseAmount(doseRaw, doseUnit);
		if (dose.error) {
			PD.flagFieldError(doseEl, PD.doseAmountMessage(dose.error, doseRaw, name, doseUnit));
			if (doseEl && 'empty' !== dose.error) {
				doseEl.select();
			}
			return false;
		}
		/* Duration must be a clean positive integer. Read the raw field so
		   we can reject '', 'abc', '0', '-3' and decimals like '7.5' —
		   parseInt() would silently truncate the last of these to 7. */
		var daysRaw = daysEl ? daysEl.value.trim() : '';
		var days = Number(daysRaw);
		if (!/^\d+$/.test(daysRaw) || !Number.isInteger(days) || days < 1) {
			PD.flagFieldError(daysEl, PD.txt('missingDays', 'Συμπληρώστε διάρκεια για το φάρμακο: ') + name);
			return false;
		}
		if (days > PD.MAX_DAYS) {
			PD.flagFieldError(daysEl, PD.txt('maxDaysExceeded', 'Η διάρκεια ξεπερνά το επιτρεπόμενο όριο.'));
			return false;
		}
		/* A once-daily medicine loaded from a prescription comes
		   with no time chosen — it must be chosen, never defaulted. */
		if ('24h' === PD.s.currentFreq && !Object.prototype.hasOwnProperty.call(PD.dailyTimeLabels(), PD.s.currentDailyTime)) {
			var timeChip = document.querySelector('.pd-daily-time-chip');
			PD.flagFieldError(timeChip, PD.txt('pickDailyTime', 'Επιλέξτε ώρα λήψης (Πρωί, Μεσημέρι, Απόγευμα ή Βράδυ).'), true);
			return false;
		}
		if ('custom' === PD.s.currentFreq) {
			/* Guard against a tampered/unknown mode leaking through with no
			   validation at all. */
			var validCustomModes = ['days', 'weekday', 'monthday'];
			if (validCustomModes.indexOf(PD.s.currentCustomMode) === -1) {
				PD.setMessage(PD.txt('invalidCustomMode', 'Επιλέξτε έγκυρο τρόπο εξειδικευμένης συχνότητας.'), 'error');
				return false;
			}
			if ('days' === PD.s.currentCustomMode) {
				var intervalEl = document.getElementById('pd-custom-interval-days');
				var intervalRaw = PD.currentCustomIntervalDays();
				var intervalVal = Number(intervalRaw);
				if (!/^\d+$/.test(intervalRaw) || !Number.isInteger(intervalVal) || intervalVal < 1 || intervalVal > 90) {
					PD.flagFieldError(intervalEl, PD.txt('missingIntervalDays', 'Συμπληρώστε κάθε πόσες ημέρες γίνεται η λήψη (1–90).'));
					return false;
				}
			}
			/* The weekday / day of the month is an explicit choice. */
			if ('weekday' === PD.s.currentCustomMode && null === PD.validWeekday(PD.s.currentWeekday)) {
				PD.flagFieldError(document.querySelector('.pd-weekday-chip'), PD.txt('pickWeekday', 'Επιλέξτε ημέρα της εβδομάδας.'), true);
				return false;
			}
			if ('monthday' === PD.s.currentCustomMode && null === PD.validMonthDay(PD.s.currentMonthDay)) {
				PD.flagFieldError('pd-custom-monthday', PD.txt('pickMonthDay', 'Επιλέξτε ημέρα του μήνα (1–31).'));
				return false;
			}
		}
		/* An unusually large quantity per intake (PD.doseUnusual())
		   is added only on a second press with the same values. */
		var savedItem = PD.s.editingIndex !== null ? PD.s.items[PD.s.editingIndex] : null;
		/* Only the SAME medicine, amount and unit counts as already
		   confirmed: an edit that renames the medicine (e.g. a 10 mg
		   tablet overwritten with another drug) keeps the large amount but
		   was never confirmed for the new name, so it asks again. */
		var alreadySaved = !!savedItem &&
			Number(savedItem.doseAmount) === dose.value &&
			savedItem.doseUnit === doseUnit &&
			String(savedItem.name == null ? '' : savedItem.name).trim() === name;
		if (PD.doseUnusual(dose.value, doseUnit) && !alreadySaved) {
			var qtyKey = name + '|' + dose.value + '|' + doseUnit;
			if (PD.s.doseQtyAck !== qtyKey) {
				PD.s.doseQtyAck = qtyKey;
				PD.flagFieldError(doseEl, PD.format(
					PD.txt('doseUnusualConfirm', 'Ασυνήθιστα μεγάλη ποσότητα: %1$s %2$s ανά λήψη. Αν είναι σωστή, πατήστε ξανά το ίδιο κουμπί για επιβεβαίωση.'),
					PD.formatDecimal(dose.value),
					PD.unitLabel(doseUnit)
				));
				return false;
			}
		}
		/* A schedule can be valid field by field and still contain no
		   dose at all — e.g. "every Saturday" for 3 days starting on a
		   Tuesday. That would print a sheet with no box to tick, handed to
		   a patient, and charge a print. Counted with the same doseTotals()
		   that builds the printed table, so the two cannot disagree. */
		if (typeof PD.doseTotals === 'function' && typeof PD.collectDrugForm === 'function') {
			if (typeof PD.beginDayPass === 'function') {
				PD.beginDayPass();
			}
			var totals = PD.doseTotals(PD.collectDrugForm());
			if (totals && totals.doses < 1) {
				PD.flagFieldError(daysEl, PD.txt('noDosesInPeriod', 'Με αυτή τη διάρκεια δεν πέφτει καμία δόση — αυξήστε τη διάρκεια ή αλλάξτε την ημέρα. Φάρμακο: ') + name);
				return false;
			}
		}
		return true;
	};
})();
