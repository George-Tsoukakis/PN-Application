/**
 * PlanDose — pro-labels.js
 *
 * The Pro medicine labels: layout, on-screen preview and printing on the
 * label printer. Kept apart from state.js, preview.js and print.js so that
 * the server can send it to Pro accounts ONLY (see
 * Plandose_Frontend::assets()). A Free account never receives this file,
 * so flipping PlandoseConfig.isPro in the browser console does not turn
 * labels on — the code to run is not on the page. (Not an absolute
 * barrier: someone skilled could load this public file by hand; labels
 * never touch the server. See readme.txt.)
 *
 * Loads after state.js, preview.js and print.js and before app.js, and
 * does nothing unless the server said the account is Pro.
 */
(function () {
	'use strict';

	var PD = window.__PlandoseNS;

	if (!PD || !PD.IS_PRO) {
		return;
	}

	/*
	 * Orientation for the label printer (Pro), from the admin setting. The
	 * server only ever sends one of these three, but the page CSS is built
	 * from it, so anything else falls back to the safe default.
	 */
	PD.LABEL_ORIENTATION = ['landscape', 'portrait', 'auto'].indexOf(String(PD.config.labelOrientation)) !== -1
		? String(PD.config.labelOrientation)
		: 'landscape';

	/*
	 * Label layout: ONE fixed style for every label printer.
	 *
	 * The text box always starts 5 mm from the label's left edge and 6 mm
	 * from the top, and ends at 65 mm (60 mm of text), well before the
	 * split of a Zebra 100x47 split label. Font sizes are fixed and all bold, so
	 * what the preview shows is what every printer prints. There is no
	 * per-computer setting and no per-size variant: the text never goes
	 * past 65 mm on any label. The page size itself still comes from the
	 * printer's driver; only the orientation is forced (admin setting).
	 *
	 * Nothing is ever clipped: in the rare case that a label does not fit,
	 * the rest continues onto the next label rather than being lost.
	 */
	PD.LABEL_BOX = { left: 5, top: 6, width: 60 };

	/*
	 * Notes on the printer-sized (auto) LABEL are cut to this many
	 * characters (one line); a fixed size uses the room it has
	 * (fitLabel()). Any cut needs a second press (labelNotesNeedAck()).
	 * The A4 plan always prints the notes in full.
	 */
	PD.LABEL_NOTES_MAX = 50;

	/* A browser used with 1.16.2–1.16.3 may still hold that version's
	   per-computer label setup, which nothing reads; drop it so nothing
	   stale lingers. */
	try {
		window.localStorage.removeItem('plandose_label_layout');
	} catch (e) {
		/* Storage blocked: nothing to clean. */
	}

	/**
	 * The notes as they will appear on the label: whitespace collapsed to
	 * one line, then cut at LABEL_NOTES_MAX characters with "…" marking
	 * the cut. Counts characters, not UTF-16 units, so Greek and emoji are
	 * counted the way the pharmacist sees them.
	 */
	PD.labelNotes = function labelNotes(notes, max) {
		/* max < LABEL_NOTES_MAX when a small label needs room;
		   max > LABEL_NOTES_MAX (up to Infinity) when a fixed-size
		   label has the room. */
		var limit = max > 0 ? max : PD.LABEL_NOTES_MAX;
		var text = PD.labelNotesFull(notes);
		var chars = Array.from(text);
		if (chars.length <= limit) {
			return text;
		}
		var kept = chars.slice(0, Math.max(1, limit - 1));
		/* Cut at a word boundary when one is reasonably close, so
		   «όχι γάλα» never becomes «όχι γ…». */
		var sp = kept.lastIndexOf(' ');
		if (sp >= Math.floor(kept.length * 0.5)) {
			kept = kept.slice(0, sp);
		}
		return kept.join('').replace(/[\s,;:.·\-–—]+$/, '') + '…';
	};

	/** The notes whitespace-collapsed but never cut — the baseline
	    every "was anything left out?" check compares against. */
	PD.labelNotesFull = function labelNotesFull(notes) {
		return String(notes == null ? '' : notes).replace(/\s+/g, ' ').trim();
	};

	/**
	 * «Μαρία Κ.» → «Μ.Κ.» — the customer's initials, used only when
	 * a small label cannot fit the full name.
	 */
	PD.labelInitials = function labelInitials(name) {
		return String(name || '').trim().split(/\s+/).filter(Boolean).map(function (word) {
			return Array.from(word)[0].toLocaleUpperCase('el') + '.';
		}).join('');
	};

	/** Length of the notes as counted for the label (see labelNotes()). */
	PD.labelNotesLength = function labelNotesLength(notes) {
		return Array.from(String(notes == null ? '' : notes).replace(/\s+/g, ' ').trim()).length;
	};

	/**
	 * Pharmacy name / phone / today's date, shared by the plan and by
	 * both label renderings so they can never disagree.
	 */
	PD.labelHeaderBits = function labelHeaderBits() {
		var h = PD.s.header || {};
		return {
			pharmName: h.name || '',
			pharmPhone: h.phone_1 || h.mobile_phone || '',
			/* The day the label is made, not the plan's start date. */
			todayStr: PD.todayDate().toLocaleDateString(PD.dateLocale(), {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric'
			})
		};
	};

	/**
	 * The text of one medicine label, as plain strings (escaped by the
	 * callers).
	 *
	 * The dose line is the SHORT form, «1 Δισκίο(α) · Πρωί, Βράδυ ·
	 * 60 ημέρες», not the plan's «… / 2 φορές την ημέρα (Πρωί, Βράδυ) /
	 * Διάρκεια: 60 ημέρες». The count of doses is already visible from the
	 * dayparts, so the long form only costs a line — and on the smallest
	 * label (Dymo 28x89) that line decides whether everything fits on one
	 * label. Custom frequencies keep their own wording («Κάθε 2 εβδομάδες»,
	 * «Κάθε Τρίτη»), which has no dayparts to show instead. The words
	 * themselves still come from getSlots() / getFreqLabel(), so the label
	 * never says something the plan does not.
	 *
	 * Notes are cut to LABEL_NOTES_MAX characters (see PD.labelNotes()).
	 */
	PD.labelParts = function labelParts(item, opts) {
		var amount = PD.labelAmount(item);
		var when = 'custom' === item.freq ? PD.getFreqLabel(item) : PD.getSlots(item).join('-');
		var days = parseInt(item.days, 10);
		/* «Για 60 Μέρες» / «Για 1 Μέρα» — non-breaking inside, so it never splits. */
		var duration = days > 0
			? (1 === days
				? PD.txt('labelForOneDay', 'Για 1 Μέρα')
				: PD.txt('labelForDays', 'Για %d Μέρες').replace('%d', days)).replace(/ /g, '\u00a0')
			: '';
		var schedule = [when, duration].filter(Boolean).join(' ');
		return {
			drug: item.name || '',
			amount: amount,
			schedule: schedule,
			/* «Έναρξη: 25/09 · 1η δόση: Βράδυ», or '' (see labelStart()). */
			start: PD.labelStart(item),
			summary: [amount, schedule].filter(Boolean).join(' '),
			notes: (opts && opts.notesMax < 0) ? '' : PD.labelNotes(item.notes, opts && opts.notesMax)
		};
	};

	/**
	 * A label string: the dictionary entry when there is one, otherwise
	 * the built-in wording of the ACTIVE language (so an English label
	 * does not fall back to Greek before the keys reach the dictionary).
	 */
	function labelWord(key, el, en) {
		return PD.txt(key, 'en' === PD.lang ? en : el);
	}

	/**
	 * When the course does NOT simply start today with the
	 * medicine's first daypart, the label says so — otherwise «Πρωί-Βράδυ
	 * Για 7 Μέρες» reads as "start this morning" while the plan sheet
	 * starts on 25/09, or on the evening.
	 *
	 *   «Έναρξη: 25/09»            the plan starts on a later day;
	 *   «1η δόση: Βράδυ»           today's earlier dayparts are skipped;
	 *   «1η δόση: Πρωί 24/09»      all of today's dayparts are skipped.
	 *
	 * Built from the very helpers the A4 plan uses (startsLater(),
	 * dayAt(), courseEnds() → skippedFirstDaySlots()/slotHasDose()), in
	 * the same day pass, so the label and the sheet cannot disagree. A
	 * same-day plan starting with the first daypart gets '' and its label
	 * is unchanged.
	 *
	 * @param {Object} item Medicine.
	 * @return {string} Plain text (escaped by the caller), or ''.
	 */
	PD.labelStart = function labelStart(item) {
		if (!item) {
			return '';
		}
		/* A medicine of the plan that was just printed (the form
		   already cleared by «Νέος ασθενής») is described with THAT plan's
		   start date and first dose, not with the empty new form's. */
		if (PD.s.lastPrintedPlan && !PD.s.dayPassFrozen &&
			PD.s.lastPrintedItems && PD.s.lastPrintedItems.indexOf(item) !== -1) {
			return PD.withPrintedPlan(function () {
				return PD.labelStart(item);
			});
		}
		var dm = function (offset) {
			return PD.dayAt(offset).toLocaleDateString(PD.dateLocale(), {
				day: '2-digit',
				month: '2-digit'
			});
		};
		var bits = [];
		if (PD.startsLater()) {
			bits.push(PD.format(labelWord('labelStartOn', 'Έναρξη: %s', 'Start: %s'), dm(0)));
		}
		/* courseEnds() is null unless some of day one's dayparts come
		   before the first dose (never when the plan starts later). */
		var ends = PD.courseEnds(item);
		if (ends) {
			var first = ends.firstSlot + (ends.firstDay > 0 ? ' ' + dm(ends.firstDay) : '');
			bits.push(PD.format(labelWord('labelFirstDose', '1η δόση: %s', '1st dose: %s'), first));
		}
		return bits.join(' · ').replace(/ /g, '\u00a0').replace(/\u00a0·\u00a0/g, '\u00a0· ');
	};

	/**
	 * Run `fn` with the start date, first dose and day pass of the
	 * plan that was just printed (PD.s.lastPrintedPlan), then put the
	 * current plan's values back — whatever `fn` does, including throw.
	 * Every helper the A4 sheet uses (startsLater(), dayAt(), courseEnds())
	 * reads those four values, so the label is computed exactly as the
	 * sheet was.
	 *
	 * @param {Function} fn Callback.
	 * @return {*} What `fn` returns.
	 */
	PD.withPrintedPlan = function withPrintedPlan(fn) {
		var ctx = PD.s.lastPrintedPlan;
		if (!ctx || PD.s.dayPassFrozen) {
			return fn();
		}
		var saved = {
			startDate: PD.s.startDate,
			firstSlot: PD.s.firstSlot,
			planDayZero: PD.s.planDayZero,
			planToday: PD.s.planToday
		};
		PD.s.startDate = ctx.startDate;
		PD.s.firstSlot = ctx.firstSlot;
		PD.s.planDayZero = ctx.dayZero;
		PD.s.planToday = ctx.today;
		PD.s.dayPassFrozen = true;
		try {
			return fn();
		} finally {
			PD.s.dayPassFrozen = false;
			PD.s.startDate = saved.startDate;
			PD.s.firstSlot = saved.firstSlot;
			PD.s.planDayZero = saved.planDayZero;
			PD.s.planToday = saved.planToday;
		}
	};

	/**
	 * The dose amount for the LABEL, with the unit in its real singular or
	 * plural form («1 Δισκίο», «2 Δισκία»). The rule lives in
	 * PD.doseAmountText() in preview.js, because the A4 day cards use it
	 * too (Free accounts do not load this file).
	 *
	 * @param {Object} item Medicine.
	 * @return {string} e.g. «1 Δισκίο», or '' when no amount was entered.
	 */
	PD.labelAmount = function labelAmount(item) {
		return PD.doseAmountText(item);
	};

	/**
	 * Pharmacy cross for the label header. Solid black on
	 * purpose: a thermal head prints black or nothing, and any grey or
	 * colour turns into dither. Sized by the CSS (.pd-lbl-logo svg).
	 */
	PD.LABEL_LOGO_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
		'<path fill="#000" d="M8.5 1.5h7a1 1 0 0 1 1 1v6h6a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1h-6v6a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1v-6h-6a1 1 0 0 1-1-1v-5a1 1 0 0 1 1-1h6v-6a1 1 0 0 1 1-1z"/>' +
		'</svg>';

	/**
	 * «ΦΑΡΜΑΚΕΙΟ» under the pharmacy name — unless the name already says
	 * it (e.g. «Φαρμακείο Παπαδοπούλου»), so it is never printed twice.
	 *
	 * @param {string} name Pharmacy name.
	 * @return {string} Subtitle or ''.
	 */
	PD.labelPharmacyKind = function labelPharmacyKind(name) {
		var kind = PD.txt('labelPharmacy', 'ΦΑΡΜΑΚΕΙΟ');
		var plain = function (str) {
			return String(str || '').toLocaleLowerCase('el').normalize('NFD').replace(/[̀-ͯ]/g, '');
		};
		return plain(name).indexOf(plain(kind)) !== -1 ? '' : kind;
	};

	/**
	 * The content of ONE label, shared by the on-screen preview and the
	 * label printer so the two can never differ:
	 *
	 *   [cross] <pharmacy>          | Τηλ.: <phone>
	 *          ΦΑΡΜΑΚΕΙΟ           | <date>
	 *   ─────────────────────────────────────────
	 *   Πελάτης:       <name>       (only when a name was entered)
	 *   Φάρμακο:       <medicine>   (largest text on the label)
	 *   Δοσολογία:     1 Δισκίο:
	 *                  Πρωί-Μεσημέρι-Βράδυ Για 60 Μέρες
	 *                  Έναρξη: 25/09 · 1η δόση: Βράδυ  (only when so)
	 *   Σημειώσεις:    <notes>      (only when there are notes; anything cut
	 *                                needs a second press, see labelNotesNeedAck())
	 *
	 * The rows are direct children of .pd-lbl (not wrapped in one block),
	 * so on an overflow only the rows that do not fit move to the next
	 * label — the header never gets stranded alone.
	 *
	 * @param {Object} item    Medicine.
	 * @param {string} patient Patient/customer name, may be ''.
	 * @return {string} HTML (escaped).
	 */
	PD.labelInnerHtml = function labelInnerHtml(item, patient, opts) {
		/* opts (fitted labels only) — noKind drops «ΦΑΡΜΑΚΕΙΟ»,
		   patientInitials shortens the customer, notesMax cuts the notes.
		   The medicine and its dosage are never shortened. */
		opts = opts || {};
		var hb = PD.labelHeaderBits();
		var p = PD.labelParts(item, opts);
		var kind = opts.noKind ? '' : PD.labelPharmacyKind(hb.pharmName);
		if (patient && opts.patientInitials) {
			patient = PD.labelInitials(patient);
		}
		var row = function (cls, label, value) {
			return '<div class="pd-lbl-row ' + cls + '">' +
				'<div class="pd-lbl-key">' + PD.escapeHtml(label) + ':</div>' +
				'<div class="pd-lbl-val">' + PD.escapeHtml(value) + '</div>' +
				'</div>';
		};
		var contact = '';
		if (hb.pharmPhone) {
			contact += '<div>' + PD.escapeHtml(PD.txt('labelPhone', 'Τηλ.') + ': ' + hb.pharmPhone) + '</div>';
		}
		if (hb.todayStr) {
			contact += '<div>' + PD.escapeHtml(hb.todayStr) + '</div>';
		}
		return '<div class="pd-lbl-head">' +
				'<div class="pd-lbl-logo">' + PD.LABEL_LOGO_SVG + '</div>' +
				'<div class="pd-lbl-pharm">' +
					(hb.pharmName ? '<div class="pd-lbl-pharm-name">' + PD.escapeHtml(hb.pharmName) + '</div>' : '') +
					(kind ? '<div class="pd-lbl-pharm-kind">' + PD.escapeHtml(kind) + '</div>' : '') +
				'</div>' +
				(contact ? '<div class="pd-lbl-contact">' + contact + '</div>' : '') +
			'</div>' +
			(patient ? row('pd-lbl-patient', PD.txt('labelCustomer', 'Πελάτης'), patient) : '') +
			row('pd-lbl-drug', PD.txt('labelDrug', 'Φάρμακο'), p.drug) +
			(p.summary || p.start
				? '<div class="pd-lbl-row pd-lbl-dose">' +
					'<div class="pd-lbl-key">' + PD.escapeHtml(PD.txt('labelDosage', 'Δοσολογία')) + ':</div>' +
					'<div class="pd-lbl-val">' +
						(p.amount ? '<div class="pd-lbl-amount">' + PD.escapeHtml(p.amount) + (p.schedule ? ':' : '') + '</div>' : '') +
						(p.schedule ? '<div class="pd-lbl-when">' + PD.escapeHtml(p.schedule) + '</div>' : '') +
						/* Part of the dosage — measured by fitLabel()
						   and never shortened; if it cannot fit, the label is
						   refused like any other that does not fit. */
						(p.start ? '<div class="pd-lbl-start">' + PD.escapeHtml(p.start) + '</div>' : '') +
					'</div>' +
				'</div>'
				: '') +
			(p.notes ? row('pd-lbl-notes', PD.txt('labelNotesKey', 'Σημειώσεις'), p.notes) : '');
	};

	/**
	 * Position and size of the text box on the label: the same for every
	 * printer — 5 mm from the left, 6 mm from the top, ending at 65 mm
	 * (before the split of a Zebra split label). See PD.LABEL_BOX.
	 * box-sizing is border-box, so the left padding is inside the width.
	 *
	 * @return {string} CSS declarations.
	 */
	PD.labelBoxCss = function labelBoxCss() {
		var b = PD.LABEL_BOX;
		return 'box-sizing:border-box;width:' + (b.left + b.width) + 'mm;max-width:100%;padding:' + b.top + 'mm 0 1mm ' + b.left + 'mm;';
	};

	/**
	 * Type and layout of the label: FIXED, one set for every
	 * printer, all bold, sized so that a normal medicine fits on one
	 * Zebra 100x47 inside the 10–65 mm box. Used verbatim by both the printed
	 * document and the on-screen preview (scoped by `prefix`), so the
	 * preview cannot drift from what is printed.
	 *
	 * Thermal-safe: black only, no grey fills (a thermal head turns grey
	 * into dither), rules at least 0.2 mm so they do not break up.
	 *
	 * Nothing is nowrap and nothing is clipped: a line that does not fit
	 * wraps, and a label that does not fit continues onto the next one.
	 *
	 * @param {string} prefix Selector prefix, e.g. '#pd-preview-area '.
	 * @return {string} CSS rules.
	 */
	PD.labelTextCss = function labelTextCss(prefix, scale) {
		var p = prefix || '';
		/* scale < 1 shrinks the type (and the sizes tied to it) for a
		   fitted label. At 1 the rules are byte-identical to the unscaled
		   ones. */
		var k = scale > 0 ? scale : 1;
		var n = function (value) {
			return 1 === k ? String(value) : String(Math.round(value * k * 100) / 100);
		};
		return p + '.pd-lbl{font-family:Arial,Helvetica,sans-serif;color:#000;}' +
			/* Bold = 700 ONLY. On Windows, weight 900 with Arial picks
			   «Arial Black», which is far wider: names wrap and the label
			   spills onto a second one (seen on a real Zebra).
			   Where extra weight is wanted (section titles, dayparts) it
			   comes from a thin text stroke, which thickens the letters
			   without making them any wider. */
			p + '.pd-lbl div{min-width:0;max-width:100%;overflow-wrap:break-word;white-space:normal;text-align:left;font-weight:700;}' +
			/* Header: cross · pharmacy · | · phone/date */
			p + '.pd-lbl-head{display:flex;align-items:center;gap:1.2mm;padding:0 0 0.8mm;border-bottom:0.4mm solid #000;}' +
			p + '.pd-lbl-logo{flex:0 0 ' + n(4.6) + 'mm;width:' + n(4.6) + 'mm;height:' + n(4.6) + 'mm;line-height:0;}' +
			p + '.pd-lbl-logo svg{display:block;width:100%;height:100%;}' +
			p + '.pd-lbl-pharm{flex:1 1 auto;}' +
			p + '.pd-lbl .pd-lbl-pharm-name{font-size:' + n(8) + 'pt;line-height:1.05;}' +
			p + '.pd-lbl .pd-lbl-pharm-kind{font-size:' + n(5) + 'pt;letter-spacing:0.3mm;line-height:1.2;}' +
			p + '.pd-lbl-contact{flex:0 0 auto;border-left:0.35mm solid #000;padding-left:1.2mm;font-size:' + n(6.5) + 'pt;line-height:1.25;}' +
			p + '.pd-lbl .pd-lbl-contact div{text-align:right;}' +
			/* Rows: label column + value, rule between rows */
			p + '.pd-lbl-row{display:grid;grid-template-columns:' + n(16.5) + 'mm 1fr;column-gap:0.6mm;align-items:baseline;padding:0.55mm 0;border-bottom:0.25mm solid #000;}' +
			p + '.pd-lbl-row:last-child{border-bottom:0;}' +
			p + '.pd-lbl .pd-lbl-key{font-size:' + n(6.6) + 'pt;line-height:1.15;-webkit-text-stroke:0.22pt #000;}' +
			p + '.pd-lbl .pd-lbl-val{font-size:' + n(7.5) + 'pt;line-height:1.15;}' +
			p + '.pd-lbl .pd-lbl-drug .pd-lbl-val{font-size:' + n(10) + 'pt;line-height:1.05;}' +
			p + '.pd-lbl .pd-lbl-dose .pd-lbl-val div{font-size:' + n(8) + 'pt;line-height:1.15;}' +
			p + '.pd-lbl .pd-lbl-dose .pd-lbl-val .pd-lbl-when{font-size:' + n(7.4) + 'pt;-webkit-text-stroke:0.2pt #000;}';
	};

	/*
	 * LABEL SIZE, chosen per computer (the label printer is attached
	 * to the computer, and pharmacies use different stock).
	 *
	 * 'auto' is the printer-sized label: fixed type, page size
	 * from the printer driver, and a medicine that does not fit continues
	 * onto a second label. Every other size GUARANTEES one label per
	 * medicine: the label box has the real size and never overflows, and
	 * PD.fitLabel() shrinks the type and, only if needed, shortens the
	 * less critical parts. The medicine and its dosage are never
	 * shortened — if even they do not fit, the label is not printed.
	 *
	 * Sizes are width × height as the label comes out (landscape). The
	 * choice is by SIZE, not by brand: the presets are only shortcuts, and
	 * «Άλλο μέγεθος» takes any width × height within LABEL_CUSTOM_LIMITS.
	 */
	PD.LABEL_SIZES = [
		{ id: 'auto', w: 0, h: 0 },
		{ id: 'dymo-99014', w: 101, h: 54, name: 'DYMO 99014 — 101 × 54 mm' },
		{ id: 'brother-dk11202', w: 100, h: 62, name: 'Brother DK-11202 — 100 × 62 mm' },
		{ id: 'zebra-100x47', w: 100, h: 47, name: 'Zebra — 100 × 47 mm' },
		{ id: 'dymo-99012', w: 89, h: 36, name: 'DYMO 99012 — 89 × 36 mm' },
		{ id: 'brother-dk11201', w: 90, h: 29, name: 'Brother DK-11201 — 90 × 29 mm' },
		{ id: 'dymo-99010', w: 89, h: 28, name: 'DYMO 99010 — 89 × 28 mm' },
		{ id: 'dymo-11354', w: 57, h: 32, name: 'DYMO 11354 — 57 × 32 mm' },
		{ id: 'custom', w: 0, h: 0 }
	];
	PD.LABEL_SIZE_KEY = 'plandose_label_size';

	/*
	 * «Άλλο μέγεθος»: the long side 50–120 mm, the short side 28–80 mm
	 * (28 mm = DYMO 99010, the smallest common stock; tested down to 50 × 28).
	 * Below that, a medicine name and its dosage cannot be printed legibly
	 * on one label; above it, it is not a label any more. The values end
	 * up in the print CSS, so they are whole or half millimetres only.
	 */
	PD.LABEL_CUSTOM_LIMITS = { longMin: 50, longMax: 120, shortMin: 28, shortMax: 80 };

	/**
	 * Parse «Άλλο μέγεθος» (either order: 54 × 101 is read as 101 × 54).
	 *
	 * @return {{w:number,h:number}|null} null when out of range or not a number.
	 */
	PD.parseCustomLabelSize = function parseCustomLabelSize(w, h) {
		var num = function (v) {
			var t = String(v == null ? '' : v).trim().replace(',', '.');
			if (!/^\d{1,3}(\.\d)?$/.test(t)) {
				return NaN;
			}
			return Math.round(parseFloat(t) * 2) / 2;
		};
		var a = num(w);
		var b = num(h);
		if (isNaN(a) || isNaN(b)) {
			return null;
		}
		var L = PD.LABEL_CUSTOM_LIMITS;
		var long = Math.max(a, b);
		var short = Math.min(a, b);
		if (long < L.longMin || long > L.longMax || short < L.shortMin || short > L.shortMax) {
			return null;
		}
		return { w: long, h: short };
	};

	/** Type scales tried, largest first. 0.75 keeps the keys ≥ 4.2 pt. */
	PD.LABEL_FIT_SCALES = [1, 0.93, 0.86, 0.8, 0.75];

	/**
	 * What may be given up, in order, when the type alone is not enough.
	 * Each step keeps the previous ones. Never the medicine or the dose.
	 */
	PD.LABEL_FIT_STEPS = [
		{},
		{ noKind: true },
		{ noKind: true, patientInitials: true },
		{ noKind: true, patientInitials: true, notesMax: 35 },
		{ noKind: true, patientInitials: true, notesMax: 20 },
		/* Last resort on the smallest stock — no notes on the
		   label at all (the A4 plan always has them in full). */
		{ noKind: true, patientInitials: true, notesMax: -1 }
	];

	PD.labelSize = function labelSize() {
		var id = PD.s.labelSizeFallback || 'auto';
		try {
			id = window.localStorage.getItem(PD.LABEL_SIZE_KEY) || id;
		} catch (e) {
			/* Storage blocked: the in-memory choice, or auto. */
		}
		/* «Άλλο μέγεθος» is stored as "custom:101x54". Anything that does
		   not parse falls back to auto — the stored text never reaches
		   the CSS unchecked. */
		var m = /^custom:([0-9.]+)x([0-9.]+)$/.exec(String(id));
		if (m) {
			var c = PD.parseCustomLabelSize(m[1], m[2]);
			if (c) {
				return { id: 'custom', w: c.w, h: c.h, name: c.w + ' × ' + c.h + ' mm' };
			}
			return PD.LABEL_SIZES[0];
		}
		for (var i = 0; i < PD.LABEL_SIZES.length; i++) {
			if ('custom' === PD.LABEL_SIZES[i].id) {
				continue;
			}
			if (PD.LABEL_SIZES[i].id === id) {
				return PD.LABEL_SIZES[i];
			}
		}
		return PD.LABEL_SIZES[0];
	};

	PD.setLabelSize = function setLabelSize(id) {
		try {
			window.localStorage.setItem(PD.LABEL_SIZE_KEY, String(id));
		} catch (e) {
			/* Storage blocked: the choice lasts until the page reloads. */
			PD.s.labelSizeFallback = String(id);
		}
	};

	/**
	 * The label box of a fixed size. Small stock (≤ 40 mm high) gets tight
	 * margins; the text never runs wider than 80 mm. The height is 1 mm
	 * short of the label so rounding in the driver cannot push it onto a
	 * second one, and nothing ever overflows it.
	 */
	PD.labelFixedBoxCss = function labelFixedBoxCss(size) {
		var small = size.h <= 40;
		var t = small ? 1.5 : 3;
		var side = small ? 3 : 4;
		var b = small ? 0.5 : 1;
		var w = Math.min(size.w, side + 80 + side);
		return 'box-sizing:border-box;width:' + w + 'mm;max-width:100%;height:' + (size.h - 1) + 'mm;overflow:hidden;' +
			'padding:' + t + 'mm ' + side + 'mm ' + b + 'mm ' + side + 'mm;';
	};

	/** Off-screen element used to measure labels, isolated from the theme. */
	/*
	 * The measuring happens inside a hidden, same-origin <iframe> whose
	 * document carries ONLY the print window's base CSS — none of the
	 * site theme's rules (`*{line-height}`, `div{letter-spacing}` …) can
	 * reach it, so it lays the label out exactly as the print window will.
	 * (An `all:initial` div on the page still inherits theme rules aimed
	 * at its children, so a label measured as fitting there could clip
	 * its dosage in print.)
	 */
	PD.LABEL_PRINT_BASE_CSS = '*{box-sizing:border-box;}' +
		'html,body{margin:0;padding:0;background:#fff;}' +
		'body{font-family:Arial,Helvetica,sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';

	PD.labelMeasureHost = function labelMeasureHost() {
		var frame = document.getElementById('pd-lbl-measure-frame');
		var doc = frame && frame.contentDocument;
		var host = doc && doc.getElementById('pd-lbl-measure');
		if (host) {
			return host;
		}
		if (!frame) {
			frame = document.createElement('iframe');
			frame.id = 'pd-lbl-measure-frame';
			frame.setAttribute('aria-hidden', 'true');
			frame.setAttribute('tabindex', '-1');
			frame.title = '';
			frame.style.cssText = 'position:absolute;left:-10000px;top:0;width:200mm;height:200mm;border:0;visibility:hidden;pointer-events:none;';
			document.body.appendChild(frame);
		}
		doc = frame.contentDocument;
		doc.open();
		doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + PD.LABEL_PRINT_BASE_CSS + '</style></head><body><div id="pd-lbl-measure"></div></body></html>');
		doc.close();
		return doc.getElementById('pd-lbl-measure');
	};

	/**
	 * The last word, in the print window itself, right before
	 * print(): the medicine index of the first fixed-size label whose
	 * content is taller than its box (it would be clipped), or -1.
	 */
	PD.labelOverflowIn = function labelOverflowIn(doc) {
		var boxes = doc ? doc.querySelectorAll('.pd-fit .pd-lbl') : [];
		for (var i = 0; i < boxes.length; i++) {
			if (boxes[i].scrollHeight > boxes[i].clientHeight + 1) {
				return i;
			}
		}
		return -1;
	};

	/**
	 * Find the largest type and the least shortening with which the label
	 * of `item` fits `size` completely.
	 *
	 * @return {{ok:boolean, scale:number, opts:Object, trimmed:Array<string>}}
	 *         trimmed lists what was shortened: 'patient', 'notes'.
	 */
	PD.fitLabel = function fitLabel(item, patient, size) {
		var host = PD.labelMeasureHost();
		/* One layout pass per label content: the preview, the size
		   check, the notes / initials warnings and the print each ask for
		   the same fit. The key is everything the label shows (its HTML,
		   which carries the language, pharmacy, dates and dose), the full
		   notes, the patient and the box, so any change measures again. */
		var memo = null;
		var key = '';
		if (fitMemo && host && 'object' === typeof host) {
			memo = fitMemo.get(host);
			if (!memo || memo.size > 200) {
				memo = new Map();
				fitMemo.set(host, memo);
			}
			key = JSON.stringify([PD.labelFixedBoxCss(size), patient || '', String(item.notes || ''), PD.labelInnerHtml(item, patient, {})]);
			if (memo.has(key)) {
				return copyFit(memo.get(key));
			}
		}
		var result = measureFit(item, patient, size, host);
		if (memo) {
			memo.set(key, copyFit(result));
		}
		return result;
	};

	/* Per measuring host (a new host measures afresh): content key → fit. */
	var fitMemo = 'function' === typeof WeakMap && 'function' === typeof Map ? new WeakMap() : null;

	function copyFit(fit) {
		var opts = {};
		Object.keys(fit.opts || {}).forEach(function (k) {
			opts[k] = fit.opts[k];
		});
		return { ok: fit.ok, scale: fit.scale, opts: opts, trimmed: fit.trimmed.slice() };
	}

	function measureFit(item, patient, size, host) {
		var box = PD.labelFixedBoxCss(size);
		/* 0.6 mm of slack against rounding between screen and printer. */
		var slack = 0.6 * 96 / 25.4;
		/* Compared with the FULL notes, not with a 50-character
		   baseline, so any note text left off the label is reported. */
		var fullNotes = PD.labelNotesFull(item.notes);
		var fullLen = Array.from(fullNotes).length;
		var s;
		var k;
		function fits(opts, scale) {
			host.innerHTML = '<style>' + PD.labelTextCss('#pd-lbl-measure ', scale) + '</style>' +
				'<div class="pd-lbl" style="' + box + '">' + PD.labelInnerHtml(item, patient, opts) + '</div>';
			var el = host.querySelector('.pd-lbl');
			if (!el) {
				return false;
			}
			/* scrollHeight never drops below the box itself, so compare
			   the content's NATURAL height (box released to auto) with
			   the real label box. */
			var boxPx = el.clientHeight;
			el.style.height = 'auto';
			el.style.overflow = 'visible';
			var natural = el.offsetHeight;
			return boxPx > 0 && natural <= boxPx - slack;
		}
		function withNotes(opts, max) {
			var o = {};
			Object.keys(opts).forEach(function (key) {
				o[key] = opts[key];
			});
			o.notesMax = max;
			return o;
		}
		function done(opts, scale) {
			var trimmed = [];
			if (opts.patientInitials && patient && PD.labelInitials(patient) !== patient) {
				trimmed.push('patient');
			}
			var shown = opts.notesMax < 0 ? '' : PD.labelNotes(item.notes, opts.notesMax);
			if (fullLen && shown !== fullNotes) {
				trimmed.push('notes');
			}
			host.innerHTML = '';
			return { ok: true, scale: scale, opts: opts, trimmed: trimmed };
		}
		/* Pass 1: the notes in FULL, giving up only the type size
		   and «ΦΑΡΜΑΚΕΙΟ» — the notes use whatever room the label has. */
		if (fullLen > PD.LABEL_NOTES_MAX) {
			for (s = 0; s < PD.LABEL_FIT_STEPS.length; s++) {
				if (PD.LABEL_FIT_STEPS[s].patientInitials || PD.LABEL_FIT_STEPS[s].notesMax) {
					continue;
				}
				for (k = 0; k < PD.LABEL_FIT_SCALES.length; k++) {
					var full = withNotes(PD.LABEL_FIT_STEPS[s], fullLen);
					if (fits(full, PD.LABEL_FIT_SCALES[k])) {
						return done(full, PD.LABEL_FIT_SCALES[k]);
					}
				}
			}
		}
		/* Pass 2: the LABEL_FIT_STEPS. Where a step fits with its cut, the
		   notes are then lengthened to the most the label still holds. */
		for (s = 0; s < PD.LABEL_FIT_STEPS.length; s++) {
			var step = PD.LABEL_FIT_STEPS[s];
			var cap = step.notesMax < 0 ? -1 : (step.notesMax || PD.LABEL_NOTES_MAX);
			for (k = 0; k < PD.LABEL_FIT_SCALES.length; k++) {
				var scale = PD.LABEL_FIT_SCALES[k];
				var opts = withNotes(step, cap);
				if (!fits(opts, scale)) {
					continue;
				}
				if (cap > 0 && cap < fullLen) {
					var lo = cap;
					var hi = fullLen;
					while (lo < hi) {
						var mid = Math.ceil((lo + hi) / 2);
						if (fits(withNotes(step, mid), scale)) {
							lo = mid;
						} else {
							hi = mid - 1;
						}
					}
					opts = withNotes(step, lo);
				}
				return done(opts, scale);
			}
		}
		host.innerHTML = '';
		return { ok: false, scale: 0, opts: {}, trimmed: [] };
	}

	/**
	 * The medicine name that cannot fit the chosen size, or ''.
	 *
	 * @param {Array}  items   Medicines.
	 * @param {string} [patient] Defaults to the current plan's patient.
	 */
	PD.labelFitProblem = function labelFitProblem(items, patient) {
		var size = PD.labelSize();
		if ('auto' === size.id) {
			return '';
		}
		if (typeof patient !== 'string') {
			patient = PD.labelPatient();
		}
		for (var i = 0; i < items.length; i++) {
			if (!PD.fitLabel(items[i], patient, size).ok) {
				return items[i].name || '?';
			}
		}
		return '';
	};

	PD.labelTooLongMessage = function labelTooLongMessage(drug) {
		return PD.format(PD.txt('labelTooLong', 'Το «%s» με τη δοσολογία του δεν χωράει σε μία ετικέτα αυτού του μεγέθους. Συντομεύστε το όνομα ή επιλέξτε μεγαλύτερη ετικέτα.'), drug);
	};

	/**
	 * The medicines whose notes a fixed-size label would cut short
	 * or leave out. The notes are where a pharmacist writes warnings
	 * («ΟΧΙ μαζί με γάλα»), so this is not left to the preview alone: the
	 * first «Εκτύπωση Ετικετών» stops and names them, and only a second
	 * press of the same labels prints them that way (see
	 * PD.labelNotesNeedAck()). The medicine and its dosage are never cut
	 * (labelFitProblem() refuses those outright).
	 *
	 * @param {Array}  items   Medicines.
	 * @param {string} patient Customer name.
	 * @return {Array<string>} Medicine names, empty when nothing is cut.
	 */
	PD.labelNotesTrimmed = function labelNotesTrimmed(items, patient) {
		var size = PD.labelSize();
		var names = [];
		items.forEach(function (item) {
			if (!PD.labelNotesLength(item.notes)) {
				return;
			}
			/* The printer-sized (auto) label cuts at
			   LABEL_NOTES_MAX too — that needs the same acknowledgement. */
			var cut = 'auto' === size.id
				? PD.labelNotes(item.notes) !== PD.labelNotesFull(item.notes)
				: (function () {
					var fit = PD.fitLabel(item, patient, size);
					return fit.ok && fit.trimmed.indexOf('notes') !== -1;
				})();
			if (cut) {
				names.push(item.name || '?');
			}
		});
		return names;
	};

	/**
	 * True when a fixed-size label prints the customer as
	 * initials only — it needs the same second press as cut notes.
	 */
	PD.labelPatientTrimmed = function labelPatientTrimmed(items, patient) {
		var size = PD.labelSize();
		if ('auto' === size.id || !patient) {
			return false;
		}
		return items.some(function (item) {
			var fit = PD.fitLabel(item, patient, size);
			return fit.ok && fit.trimmed.indexOf('patient') !== -1;
		});
	};

	/** A second press sooner than this after the warning is a double-click. */
	PD.LABEL_ACK_MIN_MS = 800;

	/**
	 * Short non-cryptographic hash (FNV-1a) — enough to recognise
	 * "the same labels again" without keeping what is on them.
	 *
	 * @param {string} text Input.
	 * @return {string}
	 */
	PD.labelAckHash = function labelAckHash(text) {
		var h = 0x811c9dc5;
		for (var i = 0; i < text.length; i++) {
			h ^= text.charCodeAt(i);
			h = Math.imul(h, 0x01000193) >>> 0;
		}
		return text.length + ':' + h.toString(16);
	};

	/**
	 * Whether the labels about to print cut notes that the
	 * pharmacist has not yet been told about. Remembers the exact labels
	 * (size, patient, medicines and notes) it warned about, so a second
	 * press of the SAME labels prints, while any change warns again.
	 *
	 * @return {string} The warning to show, or '' to go ahead.
	 */
	PD.labelNotesNeedAck = function labelNotesNeedAck(items, patient) {
		var names = PD.labelNotesTrimmed(items, patient);
		var initials = PD.labelPatientTrimmed(items, patient);
		if (!names.length && !initials) {
			return '';
		}
		var size = PD.labelSize();
		/* Only a hash is kept — never the patient's name or notes — and it
		   is cleared with the rest of the plan (invalidatePendingPrintToken()). */
		var signature = PD.labelAckHash(JSON.stringify([size.id, size.w, size.h, patient || '', PD.lang, items.map(function (item) {
			return [item.name || '', item.notes || ''];
		})]));
		var now = Date.now();
		if (PD.s.labelNotesAck === signature) {
			/* A double-click is not a decision: the second press only counts
			   when it comes a moment after the warning was shown. */
			if (now - PD.s.labelNotesAckAt >= PD.LABEL_ACK_MIN_MS) {
				return '';
			}
		} else {
			PD.s.labelNotesAck = signature;
			PD.s.labelNotesAckAt = now;
		}
		var parts = [];
		if (names.length) {
			parts.push(PD.format(
				PD.txt('labelNotesCutConfirm', 'Οι σημειώσεις δεν χωράνε ολόκληρες στην ετικέτα και θα κοπούν ή θα παραλειφθούν: %s. Ελέγξτε την προεπισκόπηση. Πατήστε ξανά «Εκτύπωση Ετικετών» για να τυπωθούν έτσι, ή επιλέξτε μεγαλύτερη ετικέτα.'),
				names.join(', ')
			));
		}
		if (initials) {
			parts.push(PD.txt('labelPatientInitialsConfirm', 'Το όνομα του πελάτη δεν χωράει και θα τυπωθούν μόνο τα αρχικά του. Πατήστε ξανά «Εκτύπωση Ετικετών» για να τυπωθεί έτσι, ή επιλέξτε μεγαλύτερη ετικέτα.'));
		}
		return parts.join(' ');
	};

	/**
	 * The size picker shown above the label preview — presets,
	 * «Άλλο μέγεθος» (width × height), the driver hint and the test label.
	 */
	PD.labelSizePicker = function labelSizePicker() {
		var size = PD.labelSize();
		var current = size.id;
		var options = PD.LABEL_SIZES.map(function (preset) {
			var name = 'auto' === preset.id
				? PD.txt('labelSizeAuto', 'Όπως τώρα (από τον εκτυπωτή)')
				: ('custom' === preset.id ? PD.txt('labelSizeCustom', 'Άλλο μέγεθος…') : preset.name);
			var selected = PD.s.labelCustomOpen ? 'custom' : current;
			return '<option value="' + PD.escapeHtml(preset.id) + '"' + (preset.id === selected ? ' selected' : '') + '>' + PD.escapeHtml(name) + '</option>';
		}).join('');
		var custom = '';
		if ('custom' === current || PD.s.labelCustomOpen) {
			var L = PD.LABEL_CUSTOM_LIMITS;
			var cw = 'custom' === current ? size.w : '';
			var ch = 'custom' === current ? size.h : '';
			custom = '<div class="pd-lbl-custom">' +
				'<label for="pd-label-w">' + PD.escapeHtml(PD.txt('labelWidth', 'Πλάτος (mm)')) + '</label>' +
				'<input type="text" inputmode="decimal" id="pd-label-w" value="' + PD.escapeHtml(String(cw)) + '" size="4" aria-describedby="pd-label-custom-hint"' + (PD.s.labelCustomError ? ' aria-invalid="true"' : '') + ' />' +
				'<span aria-hidden="true">×</span>' +
				'<label for="pd-label-h">' + PD.escapeHtml(PD.txt('labelHeight', 'Ύψος (mm)')) + '</label>' +
				'<input type="text" inputmode="decimal" id="pd-label-h" value="' + PD.escapeHtml(String(ch)) + '" size="4" aria-describedby="pd-label-custom-hint"' + (PD.s.labelCustomError ? ' aria-invalid="true"' : '') + ' />' +
				'<button type="button" id="pd-label-custom-apply" class="plandose-btn plandose-btn-secondary">' + PD.escapeHtml(PD.txt('labelApply', 'Εφαρμογή')) + '</button>' +
				'<p id="pd-label-custom-hint" class="pd-lbl-size-hint' + (PD.s.labelCustomError ? ' is-error" role="alert' : '') + '">' +
					(PD.s.labelCustomError ? PD.escapeHtml(PD.txt('labelCustomInvalid', 'Μη έγκυρο μέγεθος.')) + ' ' : '') +
					PD.escapeHtml(PD.format(PD.txt('labelCustomRange', 'Όπως γράφει το κουτί των ετικετών. Από %1$s × %2$s έως %3$s × %4$s mm.'), L.longMin, L.shortMin, L.longMax, L.shortMax)) +
				'</p>' +
				'</div>';
		}
		var hint;
		if ('auto' === current) {
			hint = PD.txt('labelSizeHint', 'Επιλέξτε το μέγεθος των ετικετών σας: έτσι κάθε φάρμακο χωράει πάντα σε μία ετικέτα.');
		} else {
			hint = PD.format(PD.txt('labelDriverHint', 'Στις ρυθμίσεις του εκτυπωτή επιλέξτε ετικέτα %1$s × %2$s mm. Στο παράθυρο εκτύπωσης: Περιθώρια «Κανένα», Κλίμακα 100%.'), size.w, size.h);
		}
		return '<div class="pd-lbl-size"><label for="pd-label-size">' +
			PD.escapeHtml(PD.txt('labelSizeLabel', 'Μέγεθος ετικέτας (σε αυτόν τον υπολογιστή)')) + '</label>' +
			'<select id="pd-label-size">' + options + '</select>' +
			custom +
			(PD.s.labelCustomOpen ? '' : '<p class="pd-lbl-size-hint">' + PD.escapeHtml(hint) + '</p>') +
			'<button type="button" id="pd-label-test" class="plandose-btn plandose-btn-secondary pd-lbl-test">' + PD.escapeHtml(PD.txt('labelTestPrint', 'Δοκιμαστική ετικέτα')) + '</button>' +
			'</div>';
	};

	function rerenderLabelPicker(focusId) {
		if (typeof PD.renderPreview === 'function') {
			PD.renderPreview();
		}
		var el = document.getElementById(focusId);
		if (el) {
			el.focus();
		}
	}

	function applyCustomLabelSize() {
		var w = document.getElementById('pd-label-w');
		var h = document.getElementById('pd-label-h');
		var c = PD.parseCustomLabelSize(w ? w.value : '', h ? h.value : '');
		PD.s.labelCustomError = !c;
		if (c) {
			PD.s.labelCustomOpen = false;
			PD.setLabelSize('custom:' + c.w + 'x' + c.h);
		}
		rerenderLabelPicker(c ? 'pd-label-size' : 'pd-label-w');
	}

	/* The picker lives inside the preview, which is rebuilt as HTML, so
	   its events are delegated from the document. */
	document.addEventListener('change', function (e) {
		if (!e.target || 'pd-label-size' !== e.target.id) {
			return;
		}
		PD.s.labelCustomError = false;
		if ('custom' === e.target.value) {
			/* Nothing is stored until a valid width × height is applied;
			   the previous size stays in force meanwhile. */
			PD.s.labelCustomOpen = true;
			rerenderLabelPicker('pd-label-size');
			return;
		}
		PD.s.labelCustomOpen = false;
		PD.setLabelSize(e.target.value);
		rerenderLabelPicker('pd-label-size');
	});

	document.addEventListener('click', function (e) {
		var id = e.target && e.target.id;
		if ('pd-label-custom-apply' === id) {
			applyCustomLabelSize();
		} else if ('pd-label-test' === id) {
			PD.printTestLabel();
		}
	});

	document.addEventListener('keydown', function (e) {
		var id = e.target && e.target.id;
		if ('Enter' === e.key && ('pd-label-w' === id || 'pd-label-h' === id)) {
			e.preventDefault();
			applyCustomLabelSize();
		}
	});

	/**
	 * A sample medicine for «Δοκιμαστική ετικέτα» — deliberately
	 * a hard case (long name, four doses a day, full-length notes). No
	 * patient data; nothing is sent anywhere and no print is charged.
	 */
	PD.testLabelItem = function testLabelItem() {
		return {
			name: 'Amoxicillin/Clavulanic acid 875mg/125mg',
			doseAmount: 1,
			doseUnit: 'tablet',
			freq: '6h',
			days: 10,
			notes: PD.txt('labelTestNotes', 'ΔΟΚΙΜΗ — μετά το φαγητό, με ένα ποτήρι νερό, όχι γάλα')
		};
	};

	/**
	 * Pro: on-screen PREVIEW of the labels, shown under the plan preview.
	 * The labels are not part of the A4 printout — they go to the label
	 * printer through their own button — so this block is appended by
	 * renderPreview() only, never by buildPrintHtml().
	 * Returns '' for non-Pro or when there are no drugs.
	 *
	 * Each card is drawn at real size (mm/pt) with the fixed layout and
	 * type sizes, so the preview wraps exactly where the printed label will.
	 */
	PD.buildLabelsPage = function buildLabelsPage() {
		var items = PD.labelItems();
		if (!PD.IS_PRO || !items.length) {
			return '';
		}
		var patient = PD.labelPatient();
		var size = PD.labelSize();
		var head = '<header class="pd-labels-head">' +
			'<h2>' + PD.escapeHtml(PD.txt('labelsTitle', 'Ετικέτες Φαρμάκων')) + '</h2>' +
			'<p>' + PD.escapeHtml(PD.txt('labelsPreviewIntro', 'Εκτυπώνονται χωριστά, στον ετικετογράφο, με το κουμπί «Εκτύπωση Ετικετών».')) + '</p>' +
			PD.labelSizePicker() +
			'</header>';

		if ('auto' === size.id) {
			/* Drawn as a whole Zebra 100x47 label, so the pharmacist sees
			   where the text sits on it. */
			var cards = items.map(function (item) {
				return '<div class="pd-lbl-card" style="width:100mm;min-height:47mm">' +
					'<div class="pd-lbl" style="' + PD.labelBoxCss() + '">' + PD.labelInnerHtml(item, patient) + '</div>' +
					'</div>';
			}).join('');

			return '<section class="pd-labels-page">' +
				'<style>' + PD.labelTextCss('#pd-preview-area ') + '</style>' +
				head +
				'<div class="pd-labels-grid">' + cards + '</div>' +
				'</section>';
		}

		/* A fixed size — each card is the real label, fitted
		   exactly as it will print. */
		var styles = '';
		var fitted = items.map(function (item, i) {
			var fit = PD.fitLabel(item, patient, size);
			var cls = 'pd-pfit-' + i;
			styles += PD.labelTextCss('#pd-preview-area .' + cls + ' ', fit.ok ? fit.scale : 1);
			var note = '';
			if (!fit.ok) {
				note = '<p class="pd-lbl-fit-note is-error" role="alert">' + PD.escapeHtml(PD.labelTooLongMessage(item.name || '')) + '</p>';
			} else if (fit.trimmed.length) {
				var what = fit.trimmed.map(function (t) {
					return 'notes' === t ? PD.txt('labelNotesKey', 'Σημειώσεις') : PD.txt('labelCustomer', 'Πελάτης');
				}).join(', ');
				note = '<p class="pd-lbl-fit-note">' + PD.escapeHtml(PD.format(PD.txt('labelTrimmed', 'Συντομεύτηκαν για να χωρέσουν: %s. Στο πλάνο Α4 τυπώνονται ολόκληρα.'), what)) + '</p>';
			}
			return '<div class="pd-lbl-fitwrap">' +
				'<div class="pd-lbl-card ' + cls + '" style="width:' + size.w + 'mm;height:' + size.h + 'mm">' +
				'<div class="pd-lbl" style="' + PD.labelFixedBoxCss(size) + '">' + PD.labelInnerHtml(item, patient, fit.opts) + '</div>' +
				'</div>' + note + '</div>';
		}).join('');

		return '<section class="pd-labels-page">' +
			'<style>' + styles + '</style>' +
			head +
			'<div class="pd-labels-grid">' + fitted + '</div>' +
			'</section>';
	};

	/**
	 * Pro: the complete document sent to the LABEL PRINTER.
	 *
	 * One medicine = one label = one printed page. The page size is NOT set
	 * here: it comes from the label printer's driver (the loaded roll or
	 * label stock), which is the only thing that knows it. Only the
	 * orientation is forced, from the admin setting, because a driver left
	 * on "portrait" squeezes a wide label into a narrow page box and the
	 * content breaks onto a second label.
	 *
	 * Rules carried over from a label-printing setup already proven on a
	 * Zebra ZD220 (100x47 mm labels) — change them only with a real test
	 * print:
	 *
	 * - @page margin:0, spacing as PADDING on the label. A page margin is
	 *   subtracted from every side of the small page box (and on 0 margin
	 *   Chrome also has no room to print its own header/footer — the page
	 *   title/URL — on the label).
	 * - NOTHING IS EVER CLIPPED. A label that does not fit simply continues
	 *   onto the next one; each medicine still STARTS on a fresh label
	 *   (break-after:page). The label has no fixed height, so a label that
	 *   does fit never produces an extra blank one.
	 * - No centering: top-left flow keeps the drug name and dose at the top
	 *   of the first label, where they belong.
	 * - Layout and type sizes are FIXED (see PD.LABEL_BOX and
	 *   PD.labelTextCss()) — the same on every printer.
	 *
	 * @param {Array} items Drugs to print.
	 * @return {string} Full HTML document.
	 */
	PD.buildLabelPrintDocument = function buildLabelPrintDocument(items, patient) {
		/* Patient passed in by the test label (none); defaults to the
		   plan's patient. */
		if (typeof patient !== 'string') {
			patient = PD.labelPatient();
		}
		var size = PD.labelSize();

		if ('auto' !== size.id) {
			return PD.buildFittedLabelDocument(items, patient, size);
		}

		var css =
			'@page{size:' + PD.LABEL_ORIENTATION + ';margin:0;}' +
			'*{box-sizing:border-box;}' +
			'html,body{margin:0;padding:0;background:#fff;}' +
			'body{font-family:Arial,Helvetica,sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
			'.pd-lbl{display:block;' + PD.labelBoxCss() + 'margin:0;' +
				'break-after:page;page-break-after:always;break-inside:auto;page-break-inside:auto;' +
				/* A continuation label keeps the top padding too. */
				'-webkit-box-decoration-break:clone;box-decoration-break:clone;}' +
			/* Keep each line of text whole across a label boundary. */
			'.pd-lbl div{break-inside:avoid;page-break-inside:avoid;orphans:2;widows:2;}' +
			'.pd-lbl:last-child{break-after:auto;page-break-after:auto;}' +
			/* The medicine row is a table header, which the
			   browser repeats at the top of every label the medicine runs
			   onto (see labelAutoTableHtml()). */
			'.pd-lbl-t{width:100%;border-collapse:collapse;border-spacing:0;}' +
			'.pd-lbl-t thead{display:table-header-group;}' +
			'.pd-lbl-t td{padding:0;vertical-align:top;}' +
			'.pd-lbl-t tr{break-inside:avoid;page-break-inside:avoid;}' +
			'.pd-lbl-t td .pd-lbl-row{border-bottom:0.25mm solid #000;}' +
			'.pd-lbl-t tbody tr:last-child td .pd-lbl-row{border-bottom:0;}' +
			PD.labelTextCss('');

		var labels = items.map(function (item) {
			return '<div class="pd-lbl">' + PD.labelAutoTableHtml(item, patient) + '</div>';
		}).join('');

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title> </title>' +
			'<style>' + css + '</style></head><body>' + labels + '</body></html>';
	};

	/**
	 * The printer-sized (auto) label with its medicine row as a table
	 * header. The pharmacy header and customer come first; the medicine
	 * row is the <thead> of a table holding the
	 * remaining rows, so a label that runs onto a second one repeats
	 * the medicine's name at its top. Same rows, same order as
	 * labelInnerHtml(), which it splits.
	 */
	PD.labelAutoTableHtml = function labelAutoTableHtml(item, patient) {
		var holder = document.createElement('div');
		holder.innerHTML = PD.labelInnerHtml(item, patient);
		var before = '';
		var drug = '';
		var body = '';
		Array.prototype.forEach.call(holder.children, function (el) {
			if (!drug) {
				if (el.classList.contains('pd-lbl-drug')) {
					drug = el.outerHTML;
				} else {
					before += el.outerHTML;
				}
				return;
			}
			body += '<tr><td>' + el.outerHTML + '</td></tr>';
		});
		return before + '<table class="pd-lbl-t" role="presentation"><thead><tr><td>' + drug + '</td></tr></thead>' +
			'<tbody>' + (body || '<tr><td></td></tr>') + '</tbody></table>';
	};

	/**
	 * The label document for a FIXED size. Every medicine is one
	 * wrapper = one page, with its own fitted type scale; the box has the
	 * real label height and overflow:hidden, so nothing can spill onto a
	 * second label. Callers check PD.labelFitProblem() first — a label
	 * that does not fit is never printed.
	 */
	PD.buildFittedLabelDocument = function buildFittedLabelDocument(items, patient, size) {
		/* The exact label size, not just the orientation: each medicine
		   is laid out on a page of exactly this size, so the browser
		   cannot paginate it any other way. The driver must be set to the
		   same stock — see the hint under the size picker. */
		var css =
			'@page{size:' + size.w + 'mm ' + size.h + 'mm;margin:0;}' +
			PD.LABEL_PRINT_BASE_CSS +
			'.pd-fit{break-after:page;page-break-after:always;break-inside:avoid;page-break-inside:avoid;}' +
			'.pd-fit:last-child{break-after:auto;page-break-after:auto;}' +
			'.pd-lbl{display:block;margin:0;' + PD.labelFixedBoxCss(size) + '}';
		var labels = items.map(function (item, i) {
			var fit = PD.fitLabel(item, patient, size);
			css += PD.labelTextCss('.pd-fit-' + i + ' ', fit.ok ? fit.scale : 1);
			return '<div class="pd-fit pd-fit-' + i + '"><div class="pd-lbl">' + PD.labelInnerHtml(item, patient, fit.opts) + '</div></div>';
		}).join('');

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title> </title>' +
			'<style>' + css + '</style></head><body>' + labels + '</body></html>';
	};

	/**
	 * Pro: print one label per medicine on the LABEL PRINTER.
	 *
	 * Independent of the A4 plan:
	 * - does NOT consume a print credit and makes no counting request —
	 *   the labels belong to a plan that is (or will be) printed anyway;
	 * - patient data never leaves the browser, exactly like the plan;
	 * - only the pharmacy header is fetched (cached after the first time).
	 *
	 * The window is opened synchronously inside the click so popup blockers
	 * treat it as user-initiated. There is deliberately NO hidden-iframe
	 * fallback here: printing a frame makes the browser treat it as part of
	 * the host page, and on a small label its header/footer (page title,
	 * URL) would land on the label itself.
	 */
	/**
	 * Close the label print window, if one is open, and release the
	 * label flow. Called by closeModal(), openModal() and
	 * resetForNextPatient(), so that window never keeps the patient's
	 * name and medicines open on screen after the popup is closed.
	 */
	PD.closeLabelWindow = function closeLabelWindow() {
		var win = PD.s.activeLabelWindow;
		PD.s.activeLabelWindow = null;
		/* A new id, so the closed flow's pending callbacks and timers
		   cannot touch the state of a later label print. */
		PD.s.labelFlowId++;
		PD.s.isPrintingLabels = false;
		if (typeof PD.syncPrintLock === 'function') {
			PD.syncPrintLock();
		}
		if (win && !win.closed) {
			try {
				win.close();
			} catch (e) {
				/* ignore */
			}
		}
	};

	/**
	 * The A4 plan's final checks, for the labels: a usable start
	 * date, every medicine valid, and at least one dose in its course.
	 * Labels of the plan just printed (form already cleared) are checked
	 * against THAT plan's start date and day pass. Shows the message.
	 *
	 * @param {Array} items Medicines about to be labelled.
	 * @return {boolean} True when printing must stop.
	 */
	PD.labelPlanProblem = function labelPlanProblem(items) {
		var current = PD.s.items.length > 0;
		if (current && typeof PD.validateStartDate === 'function' && !PD.validateStartDate()) {
			return true;
		}
		var check = function () {
			PD.beginDayPass();
			for (var i = 0; i < items.length; i++) {
				var item = items[i];
				if (PD.planItemInvalid && PD.planItemInvalid(item)) {
					return PD.format(PD.txt('invalidItemForPrint', 'Ελέγξτε το φάρμακο «%s»: η ποσότητα, το «Είδος», η συχνότητα ή η διάρκεια δεν είναι έγκυρα. Πατήστε «Επεξεργασία».'), item.name);
				}
				var totals = PD.doseTotals ? PD.doseTotals(item) : null;
				if (!totals || totals.doses < 1) {
					return PD.txt('noDosesInPeriod', 'Με αυτή τη διάρκεια δεν πέφτει καμία δόση — αυξήστε τη διάρκεια ή αλλάξτε την ημέρα. Φάρμακο: ') + item.name;
				}
			}
			return '';
		};
		var problem = current ? check() : PD.withPrintedPlan(check);
		if (problem) {
			if (current && PD.s.currentStep !== 1 && typeof PD.goToStep === 'function') {
				PD.goToStep(1);
			}
			PD.setMessage(problem, 'error');
			return true;
		}
		return false;
	};

	PD.handlePrintLabels = function handlePrintLabels() {
		if (typeof PD.flushPatientInput === 'function') {
			PD.flushPatientInput();
		}
		if (!PD.IS_PRO || PD.s.isPrinting || PD.s.isCheckingPrint || PD.s.isPreparingPrint || PD.s.isPrintingLabels || PD.s.printCreditLocked) {
			return;
		}
		/* Labels of the plan being edited must not leave out a
		   medicine that is typed but not added (same rule as the plan).
		   Labels of an already printed plan (form cleared, items empty)
		   are not affected by what is typed for the next patient. */
		if ((PD.s.items.length || PD.s.editingIndex !== null) && PD.blockIfUnsavedDrug()) {
			return;
		}
		var items = PD.labelItems().slice();
		if (!items.length) {
			PD.setMessage(PD.txt('addAtLeastOne', 'Προσθέστε τουλάχιστον ένα φάρμακο.'), 'error');
			return;
		}
		/* The same last check as the A4 plan — each medicine valid, the
		   start date and «at least one dose», and methotrexate more often
		   than weekly seen twice. */
		if (PD.labelPlanProblem(items)) {
			return;
		}
		if (PD.methotrexateNeedsConfirm && PD.methotrexateNeedsConfirm(items)) {
			return;
		}
		/* With a fixed label size, a medicine whose name and dose
		   cannot fit one label is refused here — before any window opens —
		   instead of printing half a label. */
		PD.runLabelPrint(items, PD.labelPatient());
	};

	/**
	 * «Δοκιμαστική ετικέτα» — one sample label with the chosen
	 * size, so a pharmacy can check its printer and driver settings. Same
	 * print path as the real labels; no patient data, no charge.
	 */
	PD.printTestLabel = function printTestLabel() {
		if (!PD.IS_PRO || PD.s.isPrinting || PD.s.isCheckingPrint || PD.s.isPreparingPrint || PD.s.isPrintingLabels || PD.s.printCreditLocked) {
			return;
		}
		PD.runLabelPrint([PD.testLabelItem()], PD.txt('labelTestPatient', 'ΔΟΚΙΜΑΣΤΙΚΗ ΕΤΙΚΕΤΑ'), true);
	};

	/**
	 * The label print itself.
	 *
	 * @param {Array}  items   Medicines, one label each.
	 * @param {string} patient Customer name for the labels, may be ''.
	 */
	PD.runLabelPrint = function runLabelPrint(items, patient, isTest) {
		/* The labels carry the start date / first dose. */
		PD.beginDayPass();
		var tooLong = PD.labelFitProblem(items, patient);
		if (tooLong) {
			PD.setMessage(PD.labelTooLongMessage(tooLong), 'error');
			return;
		}
		/* Notes that would be cut need a second press. Checked
		   before any window opens, so the second press is a fresh click
		   and the popup is not blocked. */
		var notesWarning = isTest ? '' : PD.labelNotesNeedAck(items, patient);
		if (notesWarning) {
			PD.setMessage(notesWarning, 'error');
			return;
		}

		/* Only one label window at a time. */
		PD.closeLabelWindow();
		var flowId = PD.s.labelFlowId;

		var win = null;
		try {
			win = window.open('', 'plandoseLabels_' + Date.now());
		} catch (e) {
			win = null;
		}
		if (!win) {
			PD.setMessage(PD.txt('labelsPopupBlocked', 'Ο browser μπλόκαρε το παράθυρο εκτύπωσης ετικετών. Επιτρέψτε τα αναδυόμενα παράθυρα για αυτό το site και δοκιμάστε ξανά.'), 'error');
			return;
		}
		try {
			win.opener = null;
			win.document.open();
			win.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title> </title></head><body style="font-family:sans-serif;color:#777;padding:30px;text-align:center;">' + PD.escapeHtml(PD.txt('pleaseWait', 'Παρακαλώ περιμένετε...')) + '</body></html>');
			win.document.close();
		} catch (e) {
			/* ignore — the real content is written below */
		}

		PD.s.activeLabelWindow = win;
		var btn = document.getElementById('pd-print-labels');
		PD.s.isPrintingLabels = true;
		PD.setBusy(btn, true, PD.txt('printing', 'Εκτύπωση...'));
		PD.updateStepButtons();
		if (typeof PD.syncPrintLock === 'function') {
			PD.syncPrintLock();
		}
		PD.clearMessage();

		var released = false;
		function done() {
			if (released) {
				return;
			}
			released = true;
			/* This flow was closed (popup closed, next patient)
			   — its state is already released and may belong to a newer
			   label print by now. */
			if (flowId !== PD.s.labelFlowId) {
				return;
			}
			PD.s.isPrintingLabels = false;
			PD.setBusy(btn, false);
			PD.updateStepButtons();
			if (typeof PD.syncPrintLock === 'function') {
				PD.syncPrintLock();
			}
		}

		function closeWin() {
			try {
				win.close();
			} catch (e) {
				/* ignore */
			}
			if (PD.s.activeLabelWindow === win) {
				PD.s.activeLabelWindow = null;
			}
		}

		function fail(message) {
			closeWin();
			done();
			PD.setMessage(message, 'error');
		}

		PD.loadHeader(function (loaded) {
			/* The window was closed with the popup meanwhile. */
			if (flowId !== PD.s.labelFlowId) {
				done();
				return;
			}
			/* Same rule as the plan: a label without the pharmacy's name
			   and phone is not worth printing — stop and let them retry. */
			if (!loaded) {
				fail(PD.txt('connectionError', 'Πρόβλημα σύνδεσης. Δοκιμάστε ξανά.'));
				return;
			}
			if (win.closed) {
				done();
				return;
			}
			/* One day pass for the check AND the document, so
			   the start date / first dose measured is the one printed. */
			PD.beginDayPass();
			/* Measured again now that the pharmacy header is in —
			   it takes room on the label too. */
			var tooLongNow = PD.labelFitProblem(items, patient);
			if (tooLongNow) {
				fail(PD.labelTooLongMessage(tooLongNow));
				return;
			}
			/* The pharmacy header can take the room the notes
			   had at the first check. */
			var notesWarningNow = isTest ? '' : PD.labelNotesNeedAck(items, patient);
			if (notesWarningNow) {
				fail(notesWarningNow);
				return;
			}
			try {
				win.document.open();
				win.document.write(PD.buildLabelPrintDocument(items, patient));
				win.document.close();
			} catch (e) {
				fail(PD.txt('genericError', 'Κάτι πήγε στραβά.'));
				return;
			}
			/* Never print a clipped label — checked in the very
			   document that is about to print. */
			var clipped = PD.labelOverflowIn(win.document);
			if (clipped !== -1) {
				fail(PD.labelTooLongMessage((items[clipped] && items[clipped].name) || '?'));
				return;
			}

			/* Same rule as the plan (see printDocumentHtml()): the window is
			   closed only on 'afterprint'. The fallback timer merely releases
			   the buttons, so a non-blocking print dialog is never cut off. */
			function afterPrint() {
				closeWin();
				done();
			}
			try {
				win.addEventListener('afterprint', afterPrint);
			} catch (e) {
				/* ignore */
			}
			setTimeout(function () {
				if (win.closed || flowId !== PD.s.labelFlowId) {
					done();
					return;
				}
				try {
					win.focus();
					win.print();
				} catch (e) {
					fail(PD.txt('genericError', 'Κάτι πήγε στραβά.'));
					return;
				}
				/* Browsers that never fire afterprint: release the UI anyway. */
				setTimeout(done, 6000);
			}, 60);
		});
	};

	PD.PRO_LABELS = true;
})();
