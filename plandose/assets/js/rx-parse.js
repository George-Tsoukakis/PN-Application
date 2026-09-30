/**
 * PlanDose — rx-parse.js
 *
 * «Επικόλληση συνταγής», the pasted prescription → the medicines to
 * review (PD.parsePrescription()): the kind of every line, the drug line
 * each «ΔΟΣΟΛΟΓΙΑ :» line belongs to, quantity, «Είδος», frequency and
 * duration, and the warnings (codes) of each medicine. Needs rx-text.js
 * and rx-lines.js; the review is rx-review.js.
 *
 * Reading is fail-closed. Inside the medicine list (after «Μονάδος Αποζ.»
 * up to the totals) every line is identified: a drug line (the full ΗΔΙΚΑ
 * signature: brand, form, strength, pack — isBrandLine; a drug
 * line with no strength is read with its name confirmed, and a drug line
 * wrapped over several lines is joined), its wrapped status, a
 * «ΔΟΣΟΛΟΓΙΑ :» line, a price row, known boilerplate. Anything else is an
 * UNKNOWN line: never part of a name, never dropped, shown in the review
 * and flagged on the medicine(s) next to it. A drug line with no dose
 * line, a dose line with no drug line, price rows and dose-like text that
 * no medicine was read from are all reported. Outside the list (headers,
 * barcode pages) nothing is read; unknown text there is named in
 * the blocking note at the top, not on any medicine.
 *
 * Safety rules (see parseFrequency / pickUnit):
 * - anything with «εβδομάδα» is NEVER turned into a daily frequency;
 * - an injectable dosed in units (insulin) becomes «Μονάδες (IU)», never
 *   «22 Ενέσεις»;
 * - what cannot be read with certainty is left empty and flagged, never
 *   guessed; a time of day, a weekday or a day of the month the
 *   prescription does not give is chosen by the pharmacist.
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;
	var R = PD && PD.rx && PD.rx._;

	/* Nothing of the reader runs if a file before this one is missing. */
	if (!R || !R.drugName) {
		return;
	}

	var latin = R.latin;
	var plain = R.plain;
	var greekAll = R.greekAll;
	var normalizeInput = R.normalizeInput;
	var MAX_LINE = R.MAX_LINE;
	var MAX_INPUT = R.MAX_INPUT;
	var toLines = R.toLines;
	var isBoundary = R.isBoundary;
	var isBrandLine = R.isBrandLine;
	var brandLead = R.brandLead;
	var drugName = R.drugName;
	var segmentLine = R.segmentLine;
	var mergeDrugWraps = R.mergeDrugWraps;
	var isStatusLine = R.isStatusLine;
	var isDoseLike = R.isDoseLike;
	var isDoseSequence = R.isDoseSequence;
	var knownLineKind = R.knownLineKind;
	var isMoneyOnly = R.isMoneyOnly;
	var moneyCount = R.moneyCount;
	var priceFields = R.priceFields;
	var clearBrandMemo = R.clearBrandMemo;
	var DOSE_LINE = R.DOSE_LINE;
	var PRICE_EXACT = R.PRICE_EXACT;
	var TOTALS_HEADER = R.TOTALS_HEADER;
	var BARCODE_LINE = R.BARCODE_LINE;
	var UNITS_PER_ML = R.UNITS_PER_ML;

	/**
	 * «when needed» wording — SOS, «σε περίπτωση», «εάν», «αν
	 * χρειαστεί», «επί πόνου» … A plan of fixed boxes to tick is not that,
	 * so the medicine goes through the form with the wording in view.
	 */
	function isAsNeeded(text) {
		var l = latin(plain(text));
		var g = greekAll(text);
		return /(^|[^A-Z0-9])(SOS|PRN)(?=[^A-Z0-9]|$)/.test(l) ||
			/* «ΣΟΣ» in Greek letters is SOS. */
			/(^|[^Α-ΩA-Z0-9])(ΕΑΝ|ΑΝ|ΕΦΟΣΟΝ|ΟΤΑΝ|ΣΟΣ)(?=[^Α-ΩA-Z0-9]|$)/.test(g) ||
			/* «ΕΠΙ» is also plain «for» in a duration («ΕΠΙ 7 ΗΜΕΡΕΣ»,
			   «ΕΠΙ ΔΥΟ ΕΒΔΟΜΑΔΕΣ», «ΕΠΙ ΜΑΚΡΟΝ»), which is not «when
			   needed». Any OTHER «ΕΠΙ …» («επί πόνου / ανάγκης / εμέτου»,
			   or «ΕΠΙ» at the very end) still counts — fail closed. */
			/(^|[^Α-ΩA-Z0-9])ΕΠΙ(?=[^Α-ΩA-Z0-9]|$)(?![^Α-ΩA-Z0-9]+(?:\d|ΜΙΑ|ΕΝΑ|ΔΥΟ|ΤΡΕΙΣ|ΤΡΙΑ|ΤΕΣΣΕΡ|ΠΕΝΤΕ|ΕΞΙ|ΕΠΤΑ|ΕΦΤΑ|ΟΚΤΩ|ΟΧΤΩ|ΕΝΝΕΑ|ΕΝΝΙΑ|ΔΕΚΑ|ΕΙΚΟΣΙ|ΤΡΙΑΝΤΑ|ΗΜΕΡ|ΕΒΔΟΜΑΔ|ΜΗΝ|ΜΑΚΡΟΝ))/.test(g) ||
			/ΣΕ ΠΕΡΙΠΤΩΣΗ|ΚΑΤ[^Α-Ω]*ΕΠΙΚΛΗΣ|ΑΝ ΧΡΕΙΑ|ΟΠΟΤΕ|ΟΤΑΝ ΧΡΕΙΑ/.test(g) ||
			/* «επί δύσπνοιας / δυσπνοίας / βήχα / πυρετού / ναυτίας /
			   αϋπνίας / κρίσεως / πόνου / ανάγκης» — named explicitly, so
			   even «ΕΠΙ 7 ΗΜΕΡΕΣ ΕΠΙ ΠΟΝΟΥ» is caught. */
			/ΕΠΙ (ΔΥΣΠΝΟΙ|ΒΗΧΑ|ΠΥΡΕΤΟΥ|ΝΑΥΤΙΑΣ|ΑΥΠΝΙΑΣ|ΚΡΙΣΕΩΣ|ΠΟΝΟΥ|ΑΝΑΓΚΗΣ)/.test(g) ||
			/PRO RE NATA|AS NEEDED|IF NEEDED|WHEN NEEDED|AS REQUIRED/.test(l);
	}

	/* ---------------------------------------------------------------- */
	/* Frequency                                                          */
	/* ---------------------------------------------------------------- */

	var PER_DAY = { 1: '24h', 2: '12h', 3: '8h', 4: '6h' };
	var EVERY_HOURS = { 24: '24h', 12: '12h', 8: '8h', 6: '6h' };

	/**
	 * The WHOLE frequency text must be one of the known ΗΔΙΚΑ phrases.
	 * Anything more («κάθε 6 ώρες επί πόνου», «1 φορά την ημέρα κάθε 2
	 * ημέρες», «… για 3 ημέρες και μετά 2») is not read at all.
	 *
	 * Once a week → a weekday the pharmacist chooses; once a month
	 * → a day of the month the pharmacist chooses (never «every 30 days»).
	 *
	 * @return {{freq:?string, customMode?:string, customIntervalDays?:string,
	 *           once?:boolean, weekly?:boolean, monthly?:boolean, warning?:string}}
	 */
	function parseFrequency(phrase) {
		var p = plain(phrase).replace(/\s+/g, ' ').trim();
		var m;
		if ('ΕΦΑΠΑΞ' === p) {
			return { freq: '24h', once: true };
		}
		if ((m = /^(\d+) ΦΟΡ(?:Α|ΕΣ) ΤΗΝ ΗΜΕΡΑ$/.exec(p))) {
			var n = parseInt(m[1], 10);
			return PER_DAY[n] ? { freq: PER_DAY[n] } : { freq: null, warning: 'freq' };
		}
		if ((m = /^ΚΑΘΕ (\d+) (?:ΩΡΕΣ|ΩΡΟ)$/.exec(p))) {
			var h = parseInt(m[1], 10);
			/* «κάθε 8 / 6 ώρες» is planned as named parts of the day
			   (Πρωί / Μεσημέρι / Βράδυ), not by the clock: one «Επιβεβαιώνω». */
			return EVERY_HOURS[h] ? { freq: EVERY_HOURS[h], everyHours: h < 12 } : { freq: null, warning: 'freq' };
		}
		if (/^(?:1 ΦΟΡΑ ΤΗΝ ΕΒΔΟΜΑΔΑ|ΚΑΘΕ ΕΒΔΟΜΑΔΑ)$/.test(p)) {
			return { freq: 'custom', customMode: 'weekday', customIntervalDays: '', weekly: true };
		}
		/* «κάθε N ημέρες» / «κάθε N εβδομάδες» → every N
		   (7N) days, 2 to 90; the doses are confirmed with the dates. */
		if ((m = /^ΚΑΘΕ (\d+) (ΗΜΕΡΕΣ|ΕΒΔΟΜΑΔΕΣ)$/.exec(p))) {
			var every = parseInt(m[1], 10) * ('ΕΒΔΟΜΑΔΕΣ' === m[2] ? 7 : 1);
			if (every >= 2 && every <= 90) {
				return { freq: 'custom', customMode: 'days', customIntervalDays: String(every), everyN: true };
			}
			return { freq: null, warning: 'ΕΒΔΟΜΑΔΕΣ' === m[2] ? 'freqWeekly' : 'freq' };
		}
		if (/^1 ΦΟΡΑ ΤΟΝ? ΜΗΝΑ$/.test(p)) {
			return { freq: 'custom', customMode: 'monthday', customIntervalDays: '', monthly: true };
		}
		/* Anything weekly that was not read exactly above (e.g. «2 φορές
		   την εβδομάδα») stays empty — never a daily default. */
		return { freq: null, warning: /ΕΒΔΟΜ|ΜΗΝ/.test(p) ? 'freqWeekly' : 'freq' };
	}

	/* ---------------------------------------------------------------- */
	/* Unit («Είδος»)                                                     */
	/* ---------------------------------------------------------------- */

	/* The unit text («ΔΙΣΚΙΑ ΕΠΙΚΑΛ», «ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ», «ΠΟΣ.ΔΙΑΛΥΜΑ
	   ML») is read word by word, and EVERY word must be known: a word that
	   names what is counted (UNIT_WORDS) or one that only describes it
	   (DESCRIPTOR_WORDS). An unknown word, a number or a symbol means the
	   text says something the parser does not understand («ΠΡΩΙ ΚΑΙ 2
	   ΒΡΑΔΥ», «ΚΟΥΤΑΛΑΚΙ») — then nothing is guessed, least of all from the
	   form code of the box. Matched by prefix, on accent-free capitals. */
	var UNIT_WORDS = [
		[/^ΔΙΣΚΙ/, 'tablet'],
		[/^(ΚΑΨΟΥΛ|ΚΑΨΑΚ)/, 'capsule'],
		[/^(ΣΠΡΑΥ|ΨΕΚΑΣΜ)/, 'spray'],
		[/^ΕΙΣΠΝΟ/, 'inhale'],
		[/^(ΕΠΑΛΕΙΨ|ΑΛΟΙΦ|ΚΡΕΜ|ΓΕΛΗ)/, 'application'],
		[/^ΥΠΟΘΕΤ/, 'suppository'],
		[/^ΣΤΑΓΟΝ/, 'drops'],
		[/^ΦΑΚΕΛ/, 'sachet'],
		[/^ΕΜΠΛΑΣΤ/, 'patch'],
		[/^ΑΜΠΟΥΛ/, 'ampoule'],
		[/^ΕΝΕΣ/, 'injection'],
		[/^(ML|CC)$|^ΧΙΛΙΟΣΤΟΛΙΤΡ/, 'ml'],
		[/^MG$|^ΧΙΛΙΟΣΤΟΓΡ/, 'mg'],
		[/^(IU|UI|UNITS?)$|^ΜΟΝΑΔ/, 'iu'],
		/* No «Είδος» for these: never filled. */
		[/^(MCG|UG|MC|G|GR|ΓΡ)$|^(ΜΙΚΡΟΓΡ|ΓΡΑΜ)/, 'refuse']
	];

	var DESCRIPTOR_WORDS = /^(ΕΠΙΚΑΛ|ΕΝΤΕΡΟΔΙΑΛ|ΓΑΣΤΡΟΑΝΘ|ΒΡΑΔΕΙ|ΑΠΟΔ|ΟΦΘ|ΟΤΙΚ|ΚΟΝΙΣ|ΕΛΕΩΔ|ΕΛΑΙΩΔ|ΠΑΡΑΤ|ΤΡΟΠΟΠ|ΕΛΕΓΧ|ΔΙΑΣΠ|ΣΤΟΜΑΤΟΔ|ΑΝΑΒΡ|ΜΑΣΩΜ|ΥΠΟΓΛΩΣΣ|ΔΙΑΛ|ΣΚΟΝ|ΔΟΣΕΙΣ|ΔΟΣΗ|ΡΙΝΙΚ|ΔΕΡΜ|ΟΦΘΑΛΜ|ΩΤΙΚ|ΣΤΟΜΑΤ|ΦΥΣΙΓΓ|ΦΙΑΛ|ΠΡΟΓΕΜΙΣ|ΣΥΡΙΓΓ|ΠΟΣΙΜ|ΕΝΑΙΩΡ|ΓΑΛΑΚΤ|ΤΟΠΙΚ|ΥΠΟΔΟΡ|ΕΝΔΟΜΥ|ΕΝΔΟΦΛ|ΜΑΛΑΚ|ΣΚΛΗΡ|ΣΙΡΟΠ|ΑΕΡΟΛΥΜ|ΔΙΑΔΕΡΜ|ΑΥΤΟΚΟΛΛ|ΕΝΕΣΙΜ)|^(ΣΕ|ΓΙΑ|ΠΟΣ|ΜΕ|ΔΟΣ)$/;

	/* «ΚΟΛΠΙΚΑ ΔΙΣΚΙΑ» / «ΚΟΛΠΙΚΑ ΥΠΟΘΕΤΑ» go in like a suppository; a
	   «ΚΟΛΠΙΚΗ ΚΡΕΜΑ» is still an application. */
	var VAGINAL = /^ΚΟΛΠΙΚ/;


	/**
	 * @return {{unit:?string, warning:string}} warning '' when the unit
	 *         text was read in full.
	 */
	function readUnitText(phrase) {
		var p = plain(phrase).trim();
		/* «ΜG», «μG», «µg» … as the unit: micrograms or milligrams?
		   Never folded to «MG» — refused. */
		if (/(^|[^A-ZΑ-Ω])\u039c\s*[G\u0393]/.test(p)) {
			return { unit: null, warning: 'unit' };
		}
		/* Letters, dots and spaces only («ΠΟΣ.ΔΙΑΛΥΜΑ ML»). */
		if (/[^A-ZΑ-Ω. ]/.test(p)) {
			return { unit: null, warning: 'phrase' };
		}
		var classes = [];
		var vaginal = false;
		var unknown = false;
		p.split(/[. ]+/).filter(Boolean).forEach(function (tok) {
			var l = latin(tok);
			for (var i = 0; i < UNIT_WORDS.length; i++) {
				if (UNIT_WORDS[i][0].test(tok) || UNIT_WORDS[i][0].test(l)) {
					if (classes.indexOf(UNIT_WORDS[i][1]) === -1) {
						classes.push(UNIT_WORDS[i][1]);
					}
					return;
				}
			}
			if (VAGINAL.test(tok)) {
				vaginal = true;
			} else if (!DESCRIPTOR_WORDS.test(tok)) {
				unknown = true;
			}
		});
		if (unknown) {
			return { unit: null, warning: 'phrase' };
		}
		if (classes.indexOf('refuse') !== -1) {
			return { unit: null, warning: 'unit' };
		}
		/* Inhalation capsules are inhalations. */
		if (classes.indexOf('inhale') !== -1 && classes.indexOf('capsule') !== -1 && classes.length === 2) {
			classes = ['inhale'];
		}
		if (vaginal) {
			if (classes.length === 1 && /^(tablet|capsule|suppository)$/.test(classes[0])) {
				classes = ['suppository'];
			} else if (!(classes.length === 1 && 'application' === classes[0])) {
				return { unit: null, warning: 'unit' };
			}
		}
		/* None, or two that disagree («ΣΤΑΓΟΝΕΣ … ML», «5 ML 250 MG»,
		   «ΕΝΕΣ.ΔΙΑΛΥΜΑ ML»): which one is the quantity? Not guessed. */
		if (classes.length !== 1) {
			return { unit: null, warning: 'unit' };
		}
		if ('iu' === classes[0]) {
			return { unit: 'iu', warning: 'iuConfirm' };
		}
		return { unit: classes[0], warning: '' };
	}

	/* Insulins by name, for the print-out without «Units/ml». */
	var INSULINS = /\b(TOUJEO|LANTUS|ABASAGLAR|SEMGLEE|TRESIBA|LEVEMIR|NOVORAPID|FIASP|HUMALOG|LYUMJEV|APIDRA|ADMELOG|HUMULIN|INSUMAN|ACTRAPID|INSULATARD|MIXTARD|NOVOMIX|RYZODEG|XULTOPHY|SULIQUA|KIRSTY|TRURAPI|INSULIN\w*)\b/;

	/**
	 * INSULIN SAFETY RULE: an injectable that is an
	 * insulin by name (brands and INN stems, any letters —
	 * PD.isInsulinName()), or whose strength is in units per ml
	 * (PD.UNITS_PER_ML: «100U/ML», «300 IU/ML», «100U/1ML», «(100U+33MCG)/ML»,
	 * «100 Μ.Ο./ML»), is insulin — whether or not its drug line was
	 * recognised. A dose given as «N ΕΝΕΣΗ» is never planned as
	 * injections: over 3 it can only be units (confirmed); otherwise the
	 * unit is left empty with the hard `insulinUnits` warning and the
	 * form requires units (IU). A dose in «ΜΟΝΑΔΕΣ» / «IU» is units,
	 * confirmed.
	 * The one exception, one injection, only when the name is
	 * NOT an insulin, the strength is in IU/ML with no insulin marker (pen,
	 * cartridge, vial, SoloStar …) and the dose is «1 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ
	 * ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ» of a single syringe / ampoule: silent for a
	 * known non-insulin product (TETAGAM P 250 IU/ML …), and with a soft
	 * «Επιβεβαιώνω» on the quantity (`iuSyringe`) for any other.
	 */
	var INSULIN_MARKER = /PEN|CART|VIAL|SOLOSTAR|KWIK|FLEX|PENFILL|INNOLET/;

	function pickUnit(phrase, formCode, nameLatin, qty, pack) {
		var read = readUnitText(phrase);
		if ('phrase' === read.warning) {
			return { unit: null, warning: 'phrase' };
		}
		var injectable = 'injection' === read.unit || /INJ|IN\.SO|PEN|PFS/.test(formCode);
		var namedInsulin = INSULINS.test(nameLatin) || (PD.isInsulinName && PD.isInsulinName(nameLatin, true));
		var perMl = PD.UNITS_PER_ML ? PD.UNITS_PER_ML.test(nameLatin) : UNITS_PER_ML.test(nameLatin);
		if (injectable && (namedInsulin || perMl)) {
			if ('iu' === read.unit) {
				return { unit: 'iu', warning: 'iuConfirm' };
			}
			/* Insulin in ml or mg («22 ML» of 100 U/ml = 2200 IU) is
			   never re-read as units: the form asks for the dose in IU. */
			if ('ml' === read.unit || 'mg' === read.unit) {
				return { unit: null, warning: 'insulinMl' };
			}
			var p = plain(phrase);
			if (!namedInsulin && pack && pack.single && 1 === qty && /ΠΡΟΓΕΜΙΣ/.test(p) && /ΣΥΡΙΓΓ/.test(p) &&
				/(^|[^A-Z])IU\s*\/\s*1?\s*ML/.test(nameLatin) && !INSULIN_MARKER.test(nameLatin)) {
				return { unit: 'injection', warning: PD.isNonInsulinIu && PD.isNonInsulinIu(nameLatin) ? '' : 'iuSyringe' };
			}
			if (qty > 3) {
				return { unit: 'iu', warning: 'iuConfirm' };
			}
			return { unit: null, warning: 'insulinUnits' };
		}
		if (!read.unit) {
			return { unit: null, warning: read.warning || 'unit' };
		}
		/* «22 ΕΝΕΣΗ» without a unit-dosed strength: not 22 injections. */
		if ('injection' === read.unit && qty > 3) {
			return { unit: null, warning: 'injectionQty' };
		}
		/* ΗΔΙΚΑ writes the dose words from the form of the box, so
		   a dose that does not fit the form («BESPAR TAB» + «1 ΕΝΕΣΗ»,
		   «VOLTAREN SUPP» + «1 ΔΙΣΚΙΑ») is probably the dose line of
		   another medicine: never planned silently. */
		if (formMismatch(read.unit, formCode)) {
			return { unit: null, warning: 'unitForm' };
		}
		return { unit: read.unit, warning: read.warning };
	}

	function formMismatch(unit, formCode) {
		var f = latin(String(formCode || '')).toUpperCase();
		if (!f) {
			return false;
		}
		var parts = f.split(/[.\s]+/);
		function has(re) {
			return parts.some(function (x) {
				return re.test(x);
			});
		}
		var parenteral = has(/^(INJ|IN|SO|PEN|PFS|AMP|VIAL|INF|PD|PWD|LYO|SOL|SUS|SUSP|CART)$/);
		var oralSolid = has(/^(TAB|TABS|CAP|CAPS|DRAG)$/) && !has(/^(INH|VAG|INHAL)$/);
		var supp = has(/^(SUPP|SUP|RECT)$/);
		if (('injection' === unit || 'ampoule' === unit) && (oralSolid || supp) && !parenteral) {
			return true;
		}
		if (('tablet' === unit || 'capsule' === unit) && !oralSolid && (supp || has(/^(INJ|PEN|PFS|CREAM|OINT|GEL|DROPS?|NASPR|SPRAY|EYE)$/))) {
			return true;
		}
		if ('suppository' === unit && oralSolid && !supp) {
			return true;
		}
		/* Tablets for a box of capsules («AMOXIL CAPS» + «1
		   ΔΙΣΚΙΑ»), or capsules for a box of tablets: the same rule — the
		   dose words come from the box, so they belong to another line. */
		var tablets = has(/^(TAB|TABS|DRAG)$/);
		var capsules = has(/^(CAP|CAPS)$/);
		if ('tablet' === unit && capsules && !tablets) {
			return true;
		}
		if ('capsule' === unit && tablets && !capsules) {
			return true;
		}
		/* A liquid, drops or a sachet for tablets / capsules
		   («DEPON F.C.TAB» + «5 ML») is the dose line of another medicine. */
		if (('ml' === unit || 'drops' === unit || 'sachet' === unit) && oralSolid && !parenteral) {
			return true;
		}
		/* A dose in mg of an oral liquid («ZIRTEK SYR 1MG/ML» + «5 MG»,
		   «… PD.ORA.SUS» + «250 MG»): the plan counts what is measured
		   (ml, drops), and mg of a liquid is how many ml only through the
		   strength — never converted, never planned as mg. Not for an
		   injection, an inhaled, nasal, eye or ear liquid. */
		var liquidOral = has(/^(OR|ORA|ORAL|POR|PS|SYR|SIR|SYRUP|ELIX|GTT|DRO|DROP|DROPS|DR|SOL|SUS|SUSP|EMUL|EMULS)$/) &&
			!oralSolid && !supp &&
			!has(/^(INJ|IN|SO|PEN|PFS|PF|AMP|VIAL|INF|CART|INH|INHAL|NE|NEB|NASPR|NAS|SPR|EY|EYE|EA|EAR|OT|OPHT|CUT|TOP|DERM|VAG|RECT|LOT|CREAM|GEL|OINT|SPRAY|GR|EF|TAB|CAP)$/);
		if ('mg' === unit && liquidOral) {
			return true;
		}
		return false;
	}

	function newItem(drug) {
		return {
			name: drug.name,
			strength: drug.strength,
			/* The pack (readPack()); `boxes` comes from the price row. */
			pack: drug.pack || null,
			doseAmount: '',
			doseUnit: '',
			freq: null,
			/* Never preset — the prescription does not say. */
			dailyTime: null,
			customMode: 'days',
			customIntervalDays: '',
			customWeekday: null,
			customMonthDay: null,
			days: '',
			notes: '',
			warnings: [],
			source: '',
			origText: '',
			confirmed: {}
		};
	}

	/**
	 * Parse a pasted prescription.
	 *
	 * @param {string} text Pasted text.
	 * @return {{patient:string, patients:Array<string>, items:Array<Object>,
	 *           expectedCount:number, readCount:number}} Items carry the
	 *         form fields plus `warnings` (codes), `source` (the dose line as
	 *         written) and `origText` (drug, dose and continuation lines),
	 *         for the review screen. expectedCount: medicine blocks by
	 *         their price rows; readCount: medicines read from a dose line.
	 */
	function parsePrescriptionOnce(text) {
		var input = String(text == null ? '' : text);
		/* A real prescription is a few KB; anything this large is
		   refused before any line is read. */
		if (input.length > MAX_INPUT) {
			return { patient: '', patients: [], items: [], expectedCount: 0, readCount: 0, unreadDoseLines: 0, tooLarge: true };
		}
		var raw = normalizeInput(input);
		clearBrandMemo();
		var tooLong = {};
		/* Each line is cut into segments (segmentLine()). */
		var lines = [];
		var glued = [];
		toLines(raw).forEach(function (line) {
			var long = line.length > MAX_LINE;
			segmentLine(long ? line.slice(0, MAX_LINE) : line).forEach(function (seg, k) {
				if (long) {
					tooLong[lines.length] = true;
				}
				glued.push(k > 0);
				lines.push(seg);
			});
		});
		/* Drug lines wrapped over several lines, joined; the
		   prescription text keeps the original line breaks (origOf). */
		var merged = mergeDrugWraps(lines, glued, tooLong);
		lines = merged.lines;
		tooLong = merged.tooLong;
		var origOf = merged.origOf;
		var n = lines.length;
		var isDoseAnchor = function (line) {
			return /^ΔΟΣΟΛΟΓΙΑ\s*:/.test(line);
		};

		/* The dose line can wrap («… x εφάπαξ x 1» / «ημέρες»): up to two
		   more lines, never a boundary or a drug line. */
		function joinDose(i) {
			var dose = lines[i];
			var long = !!tooLong[i];
			var splitNumber = false;
			var k;
			for (k = 1; k <= 2 && !long && !DOSE_LINE.test(dose) && i + k < n && !isBoundary(lines[i + k]) && !isBrandLine(lines[i + k]); k++) {
				/* A number cut in two by the wrap («x 1» / «4 ημέρες»): 14
				   days or 1? Not guessed. */
				if (/[\d½¼¾\/.,]$/.test(dose) && /^[\d½¼¾\/.,]/.test(lines[i + k])) {
					splitNumber = true;
				}
				if (tooLong[i + k] || dose.length + lines[i + k].length > MAX_LINE) {
					long = true;
				}
				dose += ' ' + lines[i + k];
			}
			return { dose: dose, k: k, long: long, splitNumber: splitNumber };
		}

		/* Pass 1 (round 5): every SEGMENT gets a kind. From the first
		   medicine (or the list header) to the end of the paste, a segment
		   is a dose line (and its wrap), a drug line, the status wrapped
		   after a drug line, a price row (and, only right after an
		   incomplete one, its exact missing fields), the totals, a known
		   header / footer / barcode line matched whole — or UNKNOWN. Unknown
		   segments are never a name and never dropped. Before that (the
		   header of the first page) nothing is read. */
		var kind = [];
		var joined = {};
		var listAt = [];
		/* The totals header within the last three segments, with only
		   unknown text between (the totals line still reads as totals; the
		   text in between is flagged on its own). */
		var totalsHeaderBefore = function (i) {
			for (var q = i - 1; q >= 0 && q >= i - 3; q--) {
				if (TOTALS_HEADER.test(lines[q])) {
					return true;
				}
				if ('unknown' !== kind[q]) {
					return false;
				}
			}
			return false;
		};
		var inZone = false;
		var inList = false;
		var lastReal = -1;
		for (var i = 0; i < n; i++) {
			if (kind[i]) {
				listAt[i] = inList;
				continue;
			}
			var line = lines[i];
			var prev = i > 0 ? i - 1 : -1;
			if (isDoseAnchor(line)) {
				kind[i] = 'dose';
				inZone = inList = true;
				joined[i] = joinDose(i);
				for (var w = i + 1; w < i + joined[i].k; w++) {
					kind[w] = 'dosewrap';
				}
				lastReal = i + joined[i].k - 1;
			} else if (PRICE_EXACT.test(line)) {
				kind[i] = 'price';
				lastReal = i;
			} else if (TOTALS_HEADER.test(line)) {
				kind[i] = 'end';
				inList = false;
				lastReal = i;
			} else if (isMoneyOnly(line) && 4 === moneyCount(line) && totalsHeaderBefore(i)) {
				kind[i] = 'end'; /* the totals under «0% 10% 25% Άλλο» */
				lastReal = i;
			} else if (isMoneyOnly(line) && prev >= 0 && 'price' === kind[prev] && priceFields(lines[prev]) < 6 &&
				moneyCount(line) === 6 - priceFields(lines[prev])) {
				kind[i] = 'neutral'; /* the wrapped end of that price row */
			} else if (BARCODE_LINE.test(line)) {
				kind[i] = 'barcode';
				inList = false;
				lastReal = i;
			} else if (knownLineKind(line)) {
				var kk = knownLineKind(line);
				if ('list' === kk) {
					kind[i] = 'header';
					inZone = inList = true;
				} else if ('neutral' === kk) {
					kind[i] = 'neutral';
				} else {
					kind[i] = 'end';
					inList = false;
				}
				lastReal = i;
			} else if (isBrandLine(line)) {
				kind[i] = 'brand';
				inZone = inList = true;
				lastReal = i;
			} else if (!inZone) {
				kind[i] = 'out';
			} else if ('' === line) {
				kind[i] = 'neutral';
			} else if (lastReal >= 0 && 'brand' === kind[lastReal] && lastReal === i - 1 && isStatusLine(line)) {
				kind[i] = 'status';
				lastReal = i;
			} else {
				kind[i] = 'unknown';
				lastReal = i;
			}
			listAt[i] = inList;
		}

		/* The barcode pages repeat the drug lines, wrapped anyhow, each
		   followed by Barcode/PC/SN/Batch. Such a block is ignored only when
		   its text is exactly the start of a drug line of this list;
		   anything else in it stays and is flagged. */
		/* The WHOLE list block of each medicine — its drug
		   line and every line after it up to its «ΔΟΣΟΛΟΓΙΑ». */
		var listTexts = [];
		for (i = 0; i < n; i++) {
			if ('brand' === kind[i]) {
				var nx = i + 1;
				var blockTx = lines[i];
				while (nx < n && nx <= i + 6 && ('status' === kind[nx] || 'unknown' === kind[nx])) {
					blockTx += lines[nx];
					nx++;
				}
				if ('dose' === kind[nx]) {
					listTexts.push(blockTx.replace(/\s+/g, ''));
				}
			}
		}
		for (i = 0; i < n; i++) {
			if ('barcode' !== kind[i] || (i > 0 && 'barcode' === kind[i - 1])) {
				continue;
			}
			var blockIdx = [];
			for (var q = i - 1; q >= 0 && ('brand' === kind[q] || 'status' === kind[q] || 'unknown' === kind[q]); q--) {
				blockIdx.unshift(q);
			}
			if (!blockIdx.length) {
				continue;
			}
			/* The sticker's text is the lines right before its
			   barcode lines; lines above it that are not part of the repeat
			   (another unknown text) stay unknown. And a repeat only counts
			   inside the sticker area: after the totals / page furniture or
			   another sticker's barcode lines — never right after a price
			   row or a dose line of the list (a drug line there is a
			   medicine without a dose line, kept and flagged). */
			for (var from = 0; from < blockIdx.length; from++) {
				var part = blockIdx.slice(from);
				var blockText = part.map(function (x) {
					return lines[x];
				}).join('').replace(/\s+/g, '');
				var repeat = blockText.length >= 10 && listTexts.some(function (tx) {
					return 0 === tx.indexOf(blockText);
				});
				if (!repeat) {
					continue;
				}
				var ctx = part[0] - 1;
				while (ctx >= 0 && ('unknown' === kind[ctx] || 'neutral' === kind[ctx] || '' === lines[ctx])) {
					ctx--;
				}
				if (ctx >= 0 && ('end' === kind[ctx] || 'barcode' === kind[ctx])) {
					part.forEach(function (x) {
						kind[x] = 'barcode';
					});
				}
				break;
			}
		}

		/* Pass 2: medicines, in order. A dose line takes the drug line
		   above it (with only status / unknown / blank lines between); a
		   drug line that no dose line takes (the next thing is a price row,
		   another drug line or the end of the list) is a medicine whose dose
		   line was not found: listed, never dropped. On the barcode pages
		   the drug lines are repeated without a dose, and are ignored. */
		var items = [];
		var itemAt = {};
		var priceRows = 0;
		var readCount = 0;
		var brandCount = 0;
		var pendingDose = false;
		var openBrand = -1;

		var lineIdxOf = function (item) {
			item.lineIdx = item.lineIdx || [];
			return item.lineIdx;
		};

		/* A brand of more than one word («TETAGAM P», «OMNIC
		   TOCAS», but also «PO BESPAR») is confirmed by the pharmacist; the
		   leading word(s) are shown apart in the review. */
		function markBrandLead(item, b) {
			var lead = brandLead(lines[b]);
			if (lead > 0) {
				item.warnings.push('brandWords');
				item.brandLead = lead;
			}
		}

		function orphanFromBrand(b) {
			var nameIdx = [b];
			if (b + 1 < n && 'status' === kind[b + 1]) {
				nameIdx.push(b + 1);
			}
			var orphan = newItem(drugName(nameIdx.map(function (x) {
				return lines[x];
			}).join(' ')));
			orphan.warnings.push('noDose');
			markBrandLead(orphan, b);
			orphan.source = lines[b];
			orphan.brandText = orphan.source;
			orphan.anchor = b;
			lineIdxOf(orphan).push.apply(orphan.lineIdx, nameIdx);
			itemAt[b] = orphan;
			brandCount++;
			items.push(orphan);
		}

		function closeBrand() {
			if (openBrand >= 0) {
				orphanFromBrand(openBrand);
				openBrand = -1;
			}
		}
		/* Two drug lines with no dose line between them — the one
		   the dose line takes is not certain. */
		var doubleBrand = false;

		for (i = 0; i < n; i++) {
			var kd = kind[i];
			if ('brand' === kd) {
				doubleBrand = openBrand >= 0;
				closeBrand();
				openBrand = i;
			} else if ('dose' === kd) {
				pendingDose = true;
				readCount++;
				var brand = openBrand;
				openBrand = -1;
				var item = readDoseBlock(i, brand);
				item.anchor = i;
				if (brand >= 0 && doubleBrand && item.warnings.indexOf('name') === -1) {
					item.warnings.push('name');
				}
				doubleBrand = false;
				if (brand >= 0) {
					itemAt[brand] = item;
					brandCount++;
				}
				for (var c = i; c < i + joined[i].k; c++) {
					itemAt[c] = item;
				}
				items.push(item);
			} else if ('end' === kd || 'header' === kd || 'price' === kd || 'barcode' === kd) {
				closeBrand();
				doubleBrand = false;
			}
			/* One price row per medicine. A price row with no dose
			   line since the previous one, and no drug line either, is still
			   a medicine: listed with the lines above it. */
			if ('price' === kd) {
				priceRows++;
				if (pendingDose) {
					pendingDose = false;
					/* The boxes dispensed («25% 2 …»), for the
					   quantity check of the review (PD.rxQuantityCheck()). */
					var lastItem = items[items.length - 1];
					if (lastItem && undefined === lastItem.boxes) {
						lastItem.boxes = parseInt(lines[i].split(' ')[1], 10);
					}
				} else if ('dose' !== kd && !itemAtAbove(i)) {
					var above = [];
					for (var j = i - 1; j >= 0 && j >= i - 4 && !isBoundary(lines[j]) && 'brand' !== kind[j]; j--) {
						above.unshift(j);
					}
					if (above.length) {
						var orphan = newItem(drugName(''));
						orphan.warnings.push('noDose');
						orphan.warnings.push('name');
						orphan.source = above.map(function (x) {
							return lines[x];
						}).join(' ');
						orphan.anchor = i;
						lineIdxOf(orphan).push.apply(orphan.lineIdx, above);
						/* Unknown lines stay unknown: they are also flagged on
						   the medicine above (pass 3). */
						items.push(orphan);
					}
				}
			}
		}
		closeBrand();

		/* True when the price row at i belongs to a drug line just above it
		   (an orphan made from that drug line). */
		function itemAtAbove(p) {
			for (var q = p - 1; q >= 0 && q >= p - 4; q--) {
				if (itemAt[q]) {
					return true;
				}
				if ('price' === kind[q] || 'dose' === kind[q] || 'end' === kind[q] || 'header' === kind[q]) {
					return false;
				}
			}
			return false;
		}

		/* Pass 3: every unknown line joins a medicine as a hard «extra».
		   Between a drug line and its dose line: that medicine. After a
		   dose line or a price row: the medicine above — and ALSO the next
		   one when the line directly precedes its drug (or dose) line,
		   since which of the two it belongs to cannot be known. */
		var ownerAbove = function (j) {
			for (var q = j - 1; q >= 0; q--) {
				var kq = kind[q];
				if ('unknown' === kq || 'neutral' === kq || 'status' === kq || 'shown' === kq) {
					continue;
				}
				if ('price' === kq) {
					continue;
				}
				return itemAt[q] || null;
			}
			return null;
		};
		var ownerBelow = function (j) {
			for (var q = j + 1; q < n; q++) {
				var kq = kind[q];
				if ('unknown' === kq || 'neutral' === kq || 'shown' === kq) {
					continue;
				}
				return ('brand' === kq || 'dose' === kq) ? (itemAt[q] || null) : null;
			}
			return null;
		};
		var attachLine = function (item, j) {
			var idx = lineIdxOf(item);
			if (idx.indexOf(j) !== -1) {
				return;
			}
			idx.push(j);
			if (item.warnings.indexOf('extra') === -1) {
				item.warnings.push('extra');
			}
			if (isAsNeeded(lines[j]) && item.warnings.indexOf('asNeeded') === -1) {
				item.warnings.push('asNeeded');
			}
			/* «0,25 0,50 0,25 …»: a dose that changes by day. */
			if (isDoseSequence(lines[j]) && item.warnings.indexOf('variableDose') === -1) {
				item.warnings.push('variableDose');
			}
			item.source += ' ' + lines[j];
			item.attachedText = (item.attachedText || '') + ' ' + lines[j];
		};
		/* Text outside the medicine list: named in the blocking note. */
		var unreadText = [];
		for (i = 0; i < n; i++) {
			if ('unknown' !== kind[i]) {
				continue;
			}
			var outside = !listAt[i];
			/* Text outside the medicine list (the page's furniture,
			   a sticker, the totals) is named in the blocking note at the
			   top — it is not any medicine's text, so it does not flag
			   the last medicine. Only text INSIDE a medicine's block flags
			   that medicine. */
			if (outside) {
				unreadText.push(lines[i]);
				continue;
			}
			var up = ownerAbove(i);
			var down = ownerBelow(i);
			/* Also text before the first medicine of a list (glued
			   after «Μονάδος Αποζ. …»): named in the blocking note. */
			if (!up) {
				unreadText.push(lines[i]);
			}
			if (up) {
				attachLine(up, i);
			}
			if (down && down !== up) {
				attachLine(down, i);
			}

		}

		/* The prescription text of each medicine, in the order of the
		   print-out: drug line, status, instructions, dose line(s). */
		items.forEach(function (it) {
			var idx = (it.lineIdx || []).slice().sort(function (a, b) {
				return a - b;
			});
			if (!it.origText) {
				it.origText = idx.map(function (x) {
					return origOf[x] || lines[x];
				}).filter(Boolean).join('\n').replace(/^ΔΟΣΟΛΟΓΙΑ : /m, 'ΔΟΣΟΛΟΓΙΑ: ');
			}
			/* Methotrexate: also a name wrapped onto a line of its own
			   above the drug line («METHOTREX» / «ATE/EBEWE TAB …»). */
			var asWritten = { name: (it.attachedText || '') + ' ' + (it.brandText || it.name), freq: it.freq, customMode: it.customMode, customIntervalDays: it.customIntervalDays };
			/* The second methotrexate check, on purpose (defence in
			   depth): the one in readDoseBlock() sees the drug line only,
			   this one also a name wrapped above it. Methotrexate taken
			   daily instead of weekly can kill, so neither check relies
			   on the other. */
			if (it.freq && PD.methotrexateTooOften && it.warnings.indexOf('methotrexateDaily') === -1 && PD.methotrexateTooOften(asWritten)) {
				it.warnings.push('methotrexateDaily');
			}
			/* The other once-a-week medicines, the same way. */
			if (it.freq && PD.weeklyOnlyTooOften && it.warnings.indexOf('methotrexateDaily') === -1 && it.warnings.indexOf('weeklyOnly') === -1 &&
				PD.weeklyOnlyTooOften(asWritten)) {
				it.warnings.push('weeklyOnly');
			}
			delete it.lineIdx;
			delete it.anchor;
			delete it.attachedText;
			delete it.brandText;
		});

		/* Dose-like text no medicine was read from (a mangled
		   «ΔΟΣΟΛΟΓ1Α», also wrapped over lines): each unknown or outside
		   line is tested together with the next two lines. */
		var unreadDoseLines = 0;
		var unread = function (x) {
			return x < n && ('unknown' === kind[x] || 'out' === kind[x] || 'shown' === kind[x]);
		};
		for (i = 0; i < n; i++) {
			if (!unread(i)) {
				continue;
			}
			/* Only with lines that were not read either. */
			var win = [lines[i]];
			for (var x = i + 1; x <= i + 2 && unread(x); x++) {
				win.push(lines[x]);
			}
			if (isDoseLike(win.join(' '))) {
				unreadDoseLines++;
				i += win.length - 1;
			}
		}

		var patients = PD.prescriptionPatients(lines);
		return {
			patient: patients[0] || '',
			patients: patients,
			items: items,
			expectedCount: Math.max(priceRows, brandCount),
			readCount: readCount,
			unreadDoseLines: unreadDoseLines,
			unreadText: unreadText
		};

		function readDoseBlock(i, brand) {
			var jd = joined[i];
			var dose = jd.dose;
			var long = jd.long;
			var k = jd.k;
			/* A quantity cut by the line break («ΔΟΣΟΛΟΓΙΑ : 1» / «0 ΔΙΣΚΙΑ»
			   — 1? 10?) cannot be trusted. */
			var splitQty = /^ΔΟΣΟΛΟΓΙΑ\s*:\s*[\d\/.,½¼¾ ]*$/.test(lines[i]);
			/* The name is the drug line (and its status wrap) only.
			   No drug line above → no name: the form asks for it. */
			var nameIdx = [];
			if (brand >= 0) {
				nameIdx.push(brand);
				if (brand + 1 < i && 'status' === kind[brand + 1]) {
					nameIdx.push(brand + 1);
				}
			}
			var drug = drugName(nameIdx.map(function (x) {
				return lines[x];
			}).join(' '));
			var item = newItem(drug);
			var warnings = item.warnings;
			item.brandText = drug.rawText;
			var idx = lineIdxOf(item);
			idx.push.apply(idx, nameIdx);
			for (var c = i; c < i + k; c++) {
				idx.push(c);
			}
			if (brand < 0) {
				/* Shown (never used as a name): the lines right above. */
				for (var q = i - 1; q >= 0 && q >= i - 4 && 'out' === kind[q] && !isBoundary(lines[q]); q--) {
					idx.push(q);
				}
			}
			if (!drug.sure || brand < 0) {
				warnings.push('name');
			}
			if (brand >= 0) {
				markBrandLead(item, brand);
			}
			if (drug.strengthZero) {
				warnings.push('strengthZero');
			}
			if (drug.strengthSplit) {
				warnings.push('strengthSplit');
			}
			if (drug.strengthSep) {
				warnings.push('strengthSep');
			}
			if (drug.strengthThousands) {
				warnings.push('strengthThousands');
			}
			if (drug.strengthUnsure) {
				warnings.push('strength');
			}
			/* The regex below is not run on a line this long. */
			if (long) {
				warnings.push('tooLong');
				item.source = dose.replace(/^ΔΟΣΟΛΟΓΙΑ\s*:\s*/, '').slice(0, 200) + '…';
				item.origText = ((brand >= 0 ? lines[brand] + '\n' : '') + dose).slice(0, 400) + '…';
				return item;
			}
			var m = DOSE_LINE.exec(dose);
			if (!m || jd.splitNumber) {
				warnings.push('dose');
				item.source = dose.replace(/^ΔΟΣΟΛΟΓΙΑ\s*:\s*/, '');
				if (isAsNeeded(dose)) {
					warnings.push('asNeeded');
				}
				return item;
			}
			item.source = m[0].replace(/^ΔΟΣΟΛΟΓΙΑ\s*:\s*/, '').replace(/ημ[εέ]ρ(?:ες|ας|α)?$/i, 'ημέρες');
			var qtyRaw = m[1].replace(/\s+/g, ' ');
			/* Fail closed: without the shared checker (state.js) the
			   amount is unread (NaN → the hard «amount» warning below),
			   never parseFloat(), which reads «1/2» as 1 and «1,5» as 1. */
			var qty = typeof PD.checkDoseAmount === 'function' ? PD.checkDoseAmount(qtyRaw, PD.DOSE_NO_LIMIT).value : NaN;
			/* «1 - 2 ΔΙΣΚΙΑ», «1 ή 2», «1 ΕΩΣ 2», «1 ως 2», «1 και 1/2»,
			   «2 x 1», «1 + 1/2», or a number left over at the start of the
			   phrase: a range, a sum, a product or a broken number. The
			   first number alone is not the dose (nor with «ΗΜΙΣΥ» / «ΜΙΣΟ»
			   after it) — the amount stays empty and the text is shown as
			   written, up to the unit words. */
			var QTY_WORD = /^([^A-ZΑ-Ω]|(?:ΕΩΣ|ΩΣ|ΜΕΧΡΙ|Η|ΚΑΙ|Χ|X)(?![A-ZΑ-Ω])|Η?ΜΙΣ[Α-Ω]*(?![A-Z]))/;
			var range = QTY_WORD.test(plain(m[2]));
			if (splitQty || range) {
				qty = NaN;
				var rawWords = [];
				if (range) {
					m[2].split(/\s+/).some(function (w, at) {
						if (at >= 4 || (at > 0 && !QTY_WORD.test(plain(w)))) {
							return true;
						}
						rawWords.push(w);
						return false;
					});
				}
				qtyRaw = (qtyRaw + ' ' + rawWords.join(' ')).trim();
			}
			/* With no drug line recognised, the insulin rule also
			   looks at the unread lines right above the dose line. */
			var nameClue = drug.latinText;
			if (brand < 0) {
				for (var cl = i - 1; cl >= 0 && cl >= i - 4 && !isBoundary(lines[cl]) && 'dose' !== kind[cl]; cl--) {
					nameClue = latin(plain(lines[cl])) + ' ' + nameClue;
				}
			}
			var unit = pickUnit(m[2], drug.form || (/(^|[^A-Z])(INJ|IN\.SO|PEN|PFS)/.test(nameClue) ? 'INJ' : ''), nameClue, qty, drug.pack);
			/* (Confirmed by a pharmacist.) «N ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ»
			   of a single-dose oral solution («OR.SOL.SD») packed in vials or
			   ampoules (SOLUMAG FORTE … BTx20 VIALSx10 ML) is N vials. Only
			   exactly that: the words «ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ» and nothing else (no
			   «5ML»), a whole number, the «.SD» form, a vial / ampoule pack. */
			if ('unit' === unit.warning && /^ΠΟΣ\.ΔΙΑΛ ΔΟΣΕΙΣ$/.test(plain(m[2]).replace(/\s+/g, ' ').trim()) &&
				qty >= 1 && Math.floor(qty) === qty && /(^|\.)SD$/.test(drug.form) &&
				/(^|[^A-Z])(VIALS?|AMPS?)(?:X|(?![A-Z]))|ΦΙΑΛΙΔΙΑ|ΑΜΠΟΥΛΕΣ/.test(latin(plain(drug.rawText)) + ' ' + plain(drug.rawText))) {
				unit = { unit: 'ampoule', warning: '' };
			}
			var freq = parseFrequency(m[3]);
			item.doseAmount = isNaN(qty) ? qtyRaw : qty;
			if (isNaN(qty)) {
				warnings.push('amount');
			}
			/* Words after «x N ημέρες» (an instruction, a second schedule)
			   were not read — the pharmacist must see them. Prices on the
			   same line (digits, %, €) are fine. */
			var tail = dose.slice(dose.indexOf(m[0]) + m[0].length);
			var asNeeded = isAsNeeded(m[2]) || isAsNeeded(m[3]);
			/* Prices are segments of their own, so ANY text left
			   after «x N ημέρες» (a wrapped dose line) was not read. */
			if ('' !== tail.trim()) {
				warnings.push('extra');
				item.source += ' ' + tail.trim();
				asNeeded = asNeeded || isAsNeeded(tail);
				if (isDoseSequence(tail.trim())) {
					warnings.push('variableDose');
				}
			}
			if (asNeeded) {
				warnings.push('asNeeded');
			}
			item.doseUnit = unit.unit || '';
			if (unit.warning) {
				warnings.push(unit.warning);
			}
			/* More than the usual quantity per intake is confirmed. */
			if (!isNaN(qty) && unit.unit && PD.doseUnusual && PD.doseUnusual(qty, unit.unit)) {
				warnings.push('highQty');
			}
			item.freq = freq.freq;
			if (freq.warning) {
				warnings.push(freq.warning);
			}
			if (freq.customMode) {
				item.customMode = freq.customMode;
				item.customIntervalDays = freq.customIntervalDays;
			}
			item.days = String(parseInt(m[4], 10));
			if (freq.once) {
				if ('1' !== item.days) {
					warnings.push('onceDays');
				}
				item.days = '1';
				item.once = true;
			}
			if (freq.everyHours) {
				warnings.push('everyHours');
			}
			if ('24h' === item.freq) {
				/* The prescription never says when in the day: chosen. */
				warnings.push('time');
			}
			if (freq.weekly) {
				/* Nor which day of the week: chosen, never a default. */
				warnings.push('weekDay');
			} else if ('custom' === item.freq && 'days' === item.customMode) {
				/* Every 2 weeks: the first dose is on the start date. */
				warnings.push('startDay');
			}
			if (freq.weekly || 'days' === item.customMode) {
				/* «x 30 ημέρες» once a week is 4 or 5 doses, usually
				   not what was dispensed for «a month». A duration that is
				   not a whole number of intervals is confirmed. */
				var every = freq.weekly ? 7 : parseInt(item.customIntervalDays, 10);
				if ('custom' === item.freq && every > 1 && (freq.everyN || parseInt(item.days, 10) % every !== 0)) {
					warnings.push('intervalCount');
				}
			}
			/* «1 φορά τον μήνα»: the day of the month is chosen, and then the
			   number of doses (2 or 3 in 90 days, depending on the day) is
			   confirmed with the dates in view — always, for every monthly. */
			if (freq.monthly) {
				warnings.push('monthly');
				warnings.push('monthlyCount');
			}
			/* Methotrexate daily: checked here on the drug line, and again
			   once the medicine's whole text is known (parsePrescriptionOnce,
			   defence in depth). */
			if (item.freq && PD.methotrexateTooOften && PD.methotrexateTooOften({ name: drug.rawText, freq: item.freq, customMode: item.customMode, customIntervalDays: item.customIntervalDays })) {
				warnings.push('methotrexateDaily');
			}
			return item;
		}
	}

	/* Nothing of the pasted text stays in memory after the parse.
	   The brand memo holds whole segments of the paste (the patient's name,
	   ΑΜΚΑ lines): it is emptied when the parse ends, whatever happens. */
	PD.parsePrescription = function parsePrescription(text) {
		try {
			return parsePrescriptionOnce(text);
		} finally {
			clearBrandMemo();
		}
	};

	/**
	 * The patient's name: the SECOND «ΕΠΩΝΥΜΟ :» / «ΟΝΟΜΑ :» on the line
	 * (the doctor's column comes first). Returned as «ΕΠΩΝΥΜΟ ΟΝΟΜΑ», the
	 * way the prescription and the pharmacy write it.
	 */
	PD.prescriptionPatients = function prescriptionPatients(lines) {
		var surnames = [];
		var firsts = [];
		lines.forEach(function (line) {
			var m;
			if ((m = /^ΕΠΩΝΥΜΟ\s*:.*?\sΕΠΩΝΥΜΟ\s*:\s*(.+)$/.exec(line))) {
				surnames.push(m[1].trim());
			}
			if ((m = /^ΟΝΟΜΑ\s*:.*?\sΟΝΟΜΑ\s*:\s*(.+)$/.exec(line))) {
				firsts.push(m[1].trim());
			}
		});
		/* One name per prescription; several pasted prescriptions of the
		   same patient give the same name again. */
		var out = [];
		for (var i = 0; i < Math.max(surnames.length, firsts.length); i++) {
			var full = [surnames[i] || '', firsts[i] || ''].filter(Boolean).join(' ');
			if (full && out.indexOf(full) === -1) {
				out.push(full);
			}
		}
		return out;
	};

	PD.prescriptionPatient = function prescriptionPatient(lines) {
		return PD.prescriptionPatients(lines)[0] || '';
	};
})();
