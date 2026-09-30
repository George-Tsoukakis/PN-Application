/**
 * PlanDose — state.js
 * Part of the modular frontend JavaScript files located in assets/js/.
 * All modules share one namespace object (window.__PlandoseNS, aliased as PD).
 * Mutable state lives on PD.s.*; helpers, config, DOM refs and constants live on PD.*
 */
(function () {
	'use strict';

	/* Grab the required DOM anchors first. If any are missing (or the
	   localized PlandoseConfig is absent) the whole tool is inert, so we
	   never even create the namespace and every other module bails out. */
	var overlay = document.getElementById('plandose-overlay');
	var trigger = document.getElementById('plandose-trigger');
	var closeBtn = document.getElementById('plandose-close');
	var app = document.getElementById('plandose-app');

	if (!window.PlandoseConfig || !overlay || !trigger || !closeBtn || !app) {
		return;
	}

	/* If the namespace already exists, this file has been injected a second
	   time (e.g. by an optimization plugin or a theme that double-enqueues).
	   Re-running would replace PD with a fresh object and wipe all runtime
	   state, so bail out and keep the first, already-wired instance. */
	if (window.__PlandoseNS) {
		return;
	}

	/* The shared namespace. Every other file reads window.__PlandoseNS.
	   PD.s holds all mutable runtime state; everything else hangs off PD. */
	var PD = { s: {} };
	window.__PlandoseNS = PD;

	PD.overlay = overlay;
	PD.trigger = trigger;
	PD.closeBtn = closeBtn;
	PD.app = app;

	PD.config = window.PlandoseConfig;
	PD.IS_PRO = !!PD.config.isPro;

	/*
	 * Two client-side dictionaries (Greek + English) shipped together so
	 * the in-app language toggle is instant — no reload, no round-trip.
	 * PD.t always points at the active one; PD.setLang() swaps it and the
	 * whole tool + printout follow, because every module reads live from
	 * PD.t / PD.txt().
	 */
	PD.LANG_KEY = 'plandose_lang';

	/* The dictionaries arrive as their own cacheable script, which sets
	   window.PlandoseI18n.el / .en before this file runs. PlandoseConfig
	   may still carry i18n / i18nEn inline (the pharmacy's printed texts,
	   or whole dictionaries on an older page); inline wins. If the script
	   failed to load, every PD.txt() falls back to its Greek default. */
	function dict(value) {
		return (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
	}
	var shipped = dict(window.PlandoseI18n);
	var el = Object.assign({}, dict(shipped.el), dict(PD.config.i18n));
	PD.dicts = {
		el: el,
		en: Object.assign({}, el, dict(shipped.en), dict(PD.config.i18nEn))
	};

	PD.readStoredLang = function readStoredLang() {
		try {
			var lang = window.localStorage.getItem(PD.LANG_KEY);
			return (lang === 'el' || lang === 'en') ? lang : null;
		} catch (e) {
			return null;
		}
	};

	PD.storeLang = function storeLang(lang) {
		if (lang !== 'el' && lang !== 'en') {
			return;
		}

		try {
			window.localStorage.setItem(PD.LANG_KEY, lang);
		} catch (e) {
			/* Storage blocked (private mode / disabled) — non-fatal. */
		}
	};

	/*
	 * Language switching is a Pro-only feature. A non-Pro pharmacist is
	 * always pinned to Greek, even if an 'en' preference lingers in
	 * localStorage from a previous Pro period (config.isPro is the
	 * server's word and wins).
	 */
	PD.lang = (PD.IS_PRO && PD.readStoredLang() === 'en') ? 'en' : 'el';
	PD.t = PD.dicts[PD.lang] || PD.dicts.el;

	PD.MAX_DAYS = Math.max(
		1,
		parseInt(PD.config.maxDays, 10) || 60
	);

	PD.modalHeadTitleEl = document.querySelector('#plandose-modal-head h2');
	PD.modalHeadSubEl = document.querySelector('#plandose-modal-head p');
	PD.defaultHeadTitle = PD.modalHeadTitleEl
		? PD.modalHeadTitleEl.textContent
		: '';
	PD.defaultHeadSub = PD.modalHeadSubEl
		? PD.modalHeadSubEl.textContent
		: '';

	PD.unitOptions = [
		{
			val: 'tablet',
			key: 'unitTablet'
		},
		{
			val: 'capsule',
			key: 'unitCapsule'
		},
		{
			val: 'drops',
			key: 'unitDrops'
		},
		{
			val: 'ampoule',
			key: 'unitAmpoule'
		},
		{
			val: 'sachet',
			key: 'unitSachet'
		},
		{
			val: 'inhale',
			key: 'unitInhale'
		},
		/* Creams, ointments, gels. */
		{
			val: 'application',
			key: 'unitApplication'
		},
		/* Nasal / oral sprays. */
		{
			val: 'spray',
			key: 'unitSpray'
		},
		/* Rectal / vaginal suppositories and vaginal tablets. */
		{
			val: 'suppository',
			key: 'unitSuppository'
		},
		/* Transdermal patches. */
		{
			val: 'patch',
			key: 'unitPatch'
		},
		/* Pre-filled syringes and pens (not ampoules). */
		{
			val: 'injection',
			key: 'unitInjection'
		},
		/* International units — insulin and the like. */
		{
			val: 'iu',
			key: 'unitIu'
		},
		{
			val: 'ml',
			key: 'unitMl'
		},
		{
			val: 'mg',
			key: 'unitMg'
		}
	];

	/**
	 * Fill %s / %d / %1$s / %2$d style placeholders in a translated
	 * string. Translators may reorder numbered placeholders, so both
	 * plain and numbered forms are supported and any argument that is
	 * missing leaves its placeholder untouched rather than printing
	 * "undefined".
	 */
	PD.format = function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(template == null ? '' : template).replace(/%(\d+\$)?[sd]/g, function (match, position) {
			var index = position ? parseInt(position, 10) - 1 : next++;
			return typeof args[index] === 'undefined' ? match : String(args[index]);
		});
	};

	/**
	 * The largest dose quantity accepted for ONE intake, per unit.
	 *
	 * A sanity ceiling against typos ("15" meant as "1,5", "50" meant as
	 * "5,0"), not a clinical limit — so it is set per unit: 500 is a normal
	 * dose in mg and an impossible one in tablets. Values to be reviewed by
	 * a pharmacist; change them here, in one place.
	 */
	PD.DOSE_MAX_BY_UNIT = {
		tablet: 20,
		capsule: 20,
		drops: 200,
		ampoule: 10,
		sachet: 10,
		inhale: 20,
		application: 10,
		spray: 10,
		suppository: 3,
		patch: 4,
		injection: 3,
		iu: 200,
		ml: 250,
		mg: 5000
	};

	/** Fallback ceiling for a unit missing from the table above. */
	PD.DOSE_MAX = 100;

	/**
	 * Above these a quantity per intake is possible but unusual,
	 * so it is added only after the pharmacist confirms it (form: a second
	 * press; prescription review: «Επιβεβαιώνω»). Plausibility, not a limit.
	 */
	PD.DOSE_UNUSUAL_BY_UNIT = {
		tablet: 4,
		capsule: 4,
		drops: 40,
		ampoule: 2,
		sachet: 2,
		inhale: 4,
		application: 3,
		spray: 4,
		suppository: 2,
		patch: 2,
		injection: 2,
		iu: 60,
		ml: 30,
		mg: 1000
	};

	/** True when `value` (a number) is above the unit's plausibility threshold. */
	PD.doseUnusual = function doseUnusual(value, unit) {
		var n = Number(value);
		if (!isFinite(n) || !Object.prototype.hasOwnProperty.call(PD.DOSE_UNUSUAL_BY_UNIT, unit)) {
			return false;
		}
		return n > PD.DOSE_UNUSUAL_BY_UNIT[unit];
	};

	/**
	 * Pseudo-unit for DISPLAYING a stored dose: the ceiling is an entry-time
	 * check, and a quantity already on the plan must never turn back into
	 * raw text (or drop out of the totals) because of it.
	 */
	PD.DOSE_NO_LIMIT = '__display__';

	/**
	 * The ceiling for a unit (see PD.DOSE_MAX_BY_UNIT).
	 *
	 * @param {string} unit Unit value (tablet, mg …); optional.
	 * @return {number}
	 */
	PD.doseMax = function doseMax(unit) {
		if (PD.DOSE_NO_LIMIT === unit) {
			return Infinity;
		}
		return Object.prototype.hasOwnProperty.call(PD.DOSE_MAX_BY_UNIT, unit)
			? PD.DOSE_MAX_BY_UNIT[unit]
			: PD.DOSE_MAX;
	};

	/** Unicode vulgar fractions accepted in the dose field. */
	PD.DOSE_FRACTION_CHARS = {
		'½': 0.5,
		'¼': 0.25,
		'¾': 0.75
	};

	/**
	 * Decimal places a dose quantity may have in this unit —
	 * three for mg (0,125 mg), two for every other unit. Display-only
	 * parsing (PD.DOSE_NO_LIMIT, see formatDose()) takes three, so a saved
	 * 0,125 mg prints as «0,125», not as its raw «0.125».
	 */
	PD.doseDecimals = function doseDecimals(unit) {
		return ('mg' === unit || PD.DOSE_NO_LIMIT === unit) ? 3 : 2;
	};

	/**
	 * Check a dose quantity typed by the pharmacist and say
	 * exactly what is wrong with it.
	 *
	 * Accepts whole numbers ("1"), decimals with comma or dot and at most
	 * two decimals ("1,5", "1.5", "0,25"), the fractions ½ ¼ ¾ alone or
	 * after a whole number ("1½", "1 ½"), and written fractions ("1/2",
	 * "3/4", "1 1/2").
	 *
	 * "1.000" meant as ONE and printed as "1.000" reads as a thousand to
	 * a Greek reader. A number with exactly three
	 * digits after a single separator is therefore refused as ambiguous
	 * (could be 1 or 1000) instead of guessed at.
	 *
	 * @param {*} raw Field value, or an already canonical number.
	 * @param {string} [unit] Dose unit, for the per-unit ceiling (PD.doseMax()).
	 * @return {{value: number, error: string}} error is '' when valid,
	 *         otherwise 'empty', 'format', 'thousands', 'mixed',
	 *         'decimals', 'zero' or 'max'; value is NaN whenever error is set.
	 */
	PD.checkDoseAmount = function checkDoseAmount(raw, unit) {
		var fail = function (error) {
			return { value: NaN, error: error };
		};
		/* An item saved by 1.21.0+ already holds a number. */
		if (typeof raw === 'number') {
			raw = isFinite(raw) ? String(raw) : '';
		}
		var text = String(raw == null ? '' : raw).replace(/\s+/g, ' ').trim();
		if ('' === text) {
			return fail('empty');
		}
		if (/^[-−]/.test(text)) {
			return fail('zero');
		}
		var value = NaN;
		var m;
		if ((m = /^(\d+)? ?([½¼¾])$/.exec(text))) {
			value = (m[1] ? parseInt(m[1], 10) : 0) + PD.DOSE_FRACTION_CHARS[m[2]];
		} else if ((m = /^(?:(\d+) )?(\d+) ?\/ ?(\d+)$/.exec(text))) {
			var den = parseInt(m[3], 10);
			if (!den) {
				return fail('format');
			}
			/* "11/2" is almost always «1 1/2» typed without the
			   space, but it reads as eleven halves — 5,5 tablets, well
			   under every ceiling, so nothing else would catch it. A
			   fraction with no whole part and a numerator of 10 or more
			   is refused and the message offers the mixed reading. */
			if (!m[1] && parseInt(m[2], 10) >= 10) {
				return fail('mixed');
			}
			/* With a whole part the fraction must be proper: «1 11/2»
			   reads as 6,5 and «1 3/2» as 2,5 — neither is a mixed number
			   anyone writes on purpose. */
			if (m[1] && parseInt(m[2], 10) >= den) {
				return fail('mixed');
			}
			value = (m[1] ? parseInt(m[1], 10) : 0) + parseInt(m[2], 10) / den;
		} else if (/^\d+(?:[.,]\d+)?$/.test(text)) {
			/* "1.000" / "1,000": thousands or one? Refuse to guess. A leading
			   0 ("0,125") cannot be a thousands group — that is just too
			   many decimals. */
			if (/^[1-9]\d{0,2}[.,]\d{3}$/.test(text)) {
				return fail('thousands');
			}
			/* mg doses may have three decimals (digoxin 0,125 mg,
			   levothyroxine 0,025 mg); every other unit keeps two. */
			if ((PD.doseDecimals(unit) > 2 ? /[.,]\d{4,}$/ : /[.,]\d{3,}$/).test(text)) {
				return fail('decimals');
			}
			value = Number(text.replace(',', '.'));
		} else {
			return fail('format');
		}
		if (!isFinite(value)) {
			return fail('format');
		}
		/* A fraction like 1/3 or 1/8 cannot be printed with two decimals
		   without rounding the dose, so it is refused, not rounded. */
		/* The third decimal only below 1 (0,125 mg). «1,125» is
		   ambiguous as text (thousands) and refused above, so a fraction
		   reaching 1.125 («1 1/8») must be refused too: saved, it would be
		   shown as «1.125 mg» and could not be read back. */
		var places = PD.doseDecimals(unit);
		if (places > 2 && value >= 1) {
			places = 2;
		}
		var scale = Math.pow(10, places);
		if (Math.abs(Math.round(value * scale) - value * scale) > 1e-9) {
			return fail('decimals');
		}
		value = Math.round(value * scale) / scale;
		if (value <= 0) {
			return fail('zero');
		}
		if (value > PD.doseMax(unit)) {
			return fail('max');
		}
		return { value: value, error: '' };
	};

	/**
	 * Parse a dose quantity to its canonical number, or NaN when it is not
	 * usable (see checkDoseAmount() for what is accepted and why).
	 */
	PD.parseDoseAmount = function parseDoseAmount(raw, unit) {
		return PD.checkDoseAmount(raw, unit).value;
	};

	/**
	 * The ONE number formatter for everything on screen and on
	 * paper (dose quantities and the totals line alike), so the sheet can
	 * never show "1,5" in one place and "1.5" in another.
	 *
	 * At most two decimals, no trailing zeros, no thousands grouping (a
	 * Greek "1.080" reads as 1,08 to half the readers), and the decimal
	 * sign of the active language: comma in Greek, dot in English.
	 *
	 * @param {*} value Number (or numeric string).
	 * @return {string} '' when the value is not a finite number.
	 */
	PD.formatDecimal = function formatDecimal(value) {
		var n = Number(value);
		if (!isFinite(n)) {
			return '';
		}
		/* toFixed() alone rounds 1.005 down (binary 1.00499…); nudging
		   by a relative epsilon rounds it the way a person would. */
		/* Three places, so a 0,125 mg dose is never shown as 0,13; every
		   two-decimal value prints exactly as with two places. */
		var rounded = Math.round((n + (n >= 0 ? 1 : -1) * Math.abs(n) * Number.EPSILON) * 1000) / 1000;
		var text = rounded.toFixed(3).replace(/\.?0+$/, '');
		if ('-0' === text) {
			text = '0';
		}
		return 'en' === PD.lang ? text : text.replace('.', ',');
	};

	/**
	 * A stored dose quantity for display. Items saved by versions before
	 * 1.21.0 hold the raw text; anything that does not parse is shown as
	 * it was typed rather than hidden.
	 */
	PD.formatDose = function formatDose(amount) {
		var value = PD.parseDoseAmount(amount, PD.DOSE_NO_LIMIT);
		if (isNaN(value)) {
			return String(amount == null ? '' : amount).trim();
		}
		return PD.formatDecimal(value);
	};

	/**
	 * Longest text accepted in each free-text field (enforced by
	 * maxlength in the markup). Long enough for any real name or note,
	 * short enough that one field cannot push the printed table off the
	 * page.
	 */
	PD.MAXLEN = {
		patient: 100,
		drug: 150,
		notes: 500
	};

	/*
	 * The four fixed frequencies, mapped to their dictionary keys.
	 *
	 * The labels count doses ("3 φορές την ημέρα"), not hours ("Κάθε
	 * 24/12/8/6 ώρες"): the plan renders semantic dayparts (Πρωί /
	 * Μεσημέρι / Απόγευμα / Βράδυ), and those are not spaced 8 hours
	 * apart. The dose COUNT is 24h→1, 12h→2, 8h→3, 6h→4 boxes per day, so
	 * "3 φορές την ημέρα" says exactly what the table below it shows. The
	 * internal values ('24h', '8h', …) keep their hour-based names so
	 * nothing else in the codebase has to change.
	 */
	PD.freqLabelKey = {
		'24h': 'times1',
		'12h': 'times2',
		'8h': 'times3',
		'6h': 'times4'
	};

	PD.AJAX_TIMEOUT_MS = 15000;
	PD.NONCE_REFRESH_MS = 10 * 60 * 1000;

	/* The shared mutable state. Reassigning any of these across the
	   other modules must go through PD.s.<name> so the change is shared.
	   Not everything is declared here: some fields are created on first
	   use by the module that owns them and are deliberately NOT touched
	   by resetPlanState() — bootstrapBound (app.js); focusTrapBound,
	   inertChanged, modalInertRec, lastFocusedBeforeModal,
	   lastFocusedBeforeConfirm (modal.js); headerEmpty, headerError
	   (api.js); billing, printLocked, printedIdleBound (print.js);
	   labelSizeFallback (pro-labels.js). A few more (methotrexateAck,
	   planToday, planDayZero, labelCustomOpen, labelCustomError) are set
	   by resetPlanState() and their modules. Search for "PD.s.<name> ="
	   before assuming a field is listed below. */
	PD.s = {
		items: [],
		currentStep: 1,
		currentFreq: '12h',
		currentCustomMode: 'days',
		currentDailyTime: 'morning',
		/* null = no weekday chosen yet (never a silent Monday). */
		currentWeekday: null,
		/* Day of the month (1–31) for customMode 'monthday';
		   null = not chosen, and «Προσθήκη» refuses. */
		currentMonthDay: null,
		/* The prescription text of the medicine loaded into the
		   form by «Συμπλήρωση στη φόρμα», shown read-only above the form.
		   Memory only; never printed or stored. */
		rxSourceText: '',
		/* The unusual quantity the pharmacist already confirmed
		   (see PD.doseUnusual()), as name|amount|unit. */
		doseQtyAck: '',
		/* The medicine name already in the plan that the
		   pharmacist confirmed adding again (see addOrUpdateItem()). */
		duplicateAck: '',
		/* First day of the plan as "YYYY-MM-DD" from the date
		   input; '' means today (read from the clock at each render). */
		startDate: '',
		/* Daypart of the first dose on the start day ('morning',
		   'noon', 'afternoon', 'evening'). Earlier dayparts of that day
		   carry over to one extra day at the end — see
		   skippedFirstDaySlots() in preview.js. */
		firstSlot: 'morning',
		/* Timer that clears a printed plan left idle on screen. */
		printedIdleTimer: null,
		/* Idle clearing of a plan that was never printed. */
		draftIdleTimer: null,
		/* The on-screen warning a minute before either idle clearing. */
		printedWarnTimer: null,
		draftWarnTimer: null,
		editingIndex: null,
		header: null,
		printStatus: null,
		isCheckingPrint: false,
		/* True for the whole of handlePrint()'s preparation (limit
		   check AND header lookup), so the Print button stays busy until
		   the flow either proceeds to print or aborts — see handlePrint(). */
		isPreparingPrint: false,
		isPrinting: false,
		printCreditLocked: false,
		lastPrintToken: null,
		pendingPrintToken: null,
		/* The patient name confirmedPrintHtml was printed with. */
		confirmedPatient: null,
		/* One id per register_print request, reused only when that
		   same request is retried after a lost answer (see api.js). */
		pendingPrintRequest: null,
		pendingPrintRequestToken: null,
		/* The server's print_count at the moment pendingPrintToken was
		   minted. Kept with the token, not re-read on every attempt: a retry
		   after a lost response must be compared with the count from BEFORE
		   the first attempt, or a genuine duplicate looks unrecorded
		   (see consumePrintCreditOnce() in api.js). */
		pendingPrintCountBefore: null,
		/* True while pendingPrintToken may already be charged on
		   the server but has produced no sheet: the register_print answer
		   was lost (timeout, connection), or it was recorded but the print
		   window did not open. Such a token is KEPT when the plan is edited
		   or the next patient starts, so the next print reuses it: the
		   server either records it now (one charge) or answers it as
		   already_recorded (no second charge for the lost one). Cleared
		   once a sheet actually opens, or the server says it was not
		   recorded. See keepsUnusedPrintToken(). */
		printTokenNoSheet: false,
		/* A sheet has opened at least once for pendingPrintToken.
		   Such a token is never carried over — its charge already bought a
		   sheet, so reusing it for an edited plan or another patient would
		   be a free print. Reset whenever a new token is minted. */
		printTokenSheetOpened: false,
		/* The exact sheet (buildPrintHtml()) whose print the server
		   confirmed. A reprint is free only while the sheet is still
		   identical — a language switch or midnight (dates move) makes it
		   a new, charged print even though no field was edited. */
		confirmedPrintHtml: null,
		/* True while the Pro label print window is being prepared/printed,
		   so the plan print cannot start a second print flow on top. */
		isPrintingLabels: false,
		/* The Pro label print window, tracked like
		   activePrintWindow so closing the popup or «Νέος ασθενής» can
		   close it — it holds the patient's name and medicines. */
		activeLabelWindow: null,
		labelFlowId: 0,
		/* The labels whose cut notes were already pointed out (PD.labelNotesNeedAck()). */
		labelNotesAck: '',
		/* The «Επικόλληση συνταγής» panel (rx-import.js). Holds the
		   medicines read from a pasted prescription until they are added;
		   the pasted text itself is never kept. */
		rx: null,
		rxNeedsFreq: false,
		rxHint: '',
		labelNotesAckAt: 0,
		/* The pending nonce refresh (a Promise), so requests that
		   need the nonce can wait for the fresh one — see whenNonceFresh(). */
		nonceRefreshPromise: null,
		/* The last header lookup was refused as an expired session. */
		headerSessionExpired: false,
		activePrintWindow: null,
		printWindowCounter: 0,
		headerLoading: false,
		headerCallbacks: [],
		nonceRefreshInterval: null,
		langHintTimer: null,
		/* Pro: the drugs of the plan that was JUST printed, kept in memory
		   only so "Εκτύπωση Ετικετών" still works after the form has been
		   cleared for the next patient. Never sent anywhere; dropped as soon
		   as the plan content changes or the popup is reopened. */
		lastPrintedItems: null,
		lastPrintedPatient: '',
		/* The start date, first dose and day pass of the plan that
		   was just printed, so its labels say «Έναρξη» / «1η δόση» exactly
		   as the A4 sheet did after «Νέος ασθενής» cleared the form. */
		lastPrintedPlan: null,
		/* True only inside PD.withPrintedPlan() (pro-labels.js). */
		dayPassFrozen: false,
		/* Forgets lastPrintedItems after PRINTED_IDLE_MS. */
		lastPrintedTimer: null
	};

	/* True only when the Pro label module (assets/js/pro-labels.js)
	   has loaded. The server enqueues that file for Pro accounts only, so a
	   Free account does not receive the label code at all — flipping isPro
	   in the browser console does not turn labels on. (Not an absolute
	   barrier: the file is public and client-side, see readme.txt.) */
	PD.PRO_LABELS = false;

	/**
	 * Invalidate a pending, not-yet-confirmed print token because the
	 * plan's content just changed. Without this, a retry after an
	 * ambiguous network failure (see the docs on pendingPrintToken above)
	 * would reuse the SAME token even though the pharmacist edited the
	 * plan in between — if the server had in fact already recorded the
	 * original attempt, the edited plan's print would then be silently
	 * treated as a duplicate_ignored and never actually counted. Called
	 * from every place items[] itself changes (add/update/delete a drug,
	 * or a full plan reset) — not from editItem(), which only loads a
	 * drug into the form and doesn't change items[] until the edit is
	 * saved via addOrUpdateItem().
	 */
	PD.invalidatePendingPrintToken = function invalidatePendingPrintToken() {
		/* A token that may be charged without a sheet is carried
		   over to the edited (or next) plan instead of being thrown away —
		   see printTokenNoSheet. Only the token travels; nothing about the
		   plan or the patient does. */
		if (!PD.keepsUnusedPrintToken()) {
			PD.s.pendingPrintToken = null;
			PD.s.pendingPrintCountBefore = null;
		} else if (typeof PD.clearPrintedIdleTimer === 'function') {
			/* The carried token still equals lastPrintToken, so the idle
			   timer armed for the unprinted sheet would take the EDITED
			   plan for "printed and left on screen" and clear it. */
			PD.clearPrintedIdleTimer();
		}
		PD.s.confirmedPrintHtml = null;
		PD.s.confirmedPatient = null;
		/* Same trigger, same meaning: the plan on screen is no longer the
		   one that was printed, so its labels must not be offered. */
		PD.s.lastPrintedItems = null;
		PD.s.lastPrintedPatient = '';
		PD.s.lastPrintedPlan = null;
		PD.s.labelNotesAck = '';
		/* And its expiry timer (see armLastPrintedTimer()). */
		if (PD.s.lastPrintedTimer) {
			clearTimeout(PD.s.lastPrintedTimer);
			PD.s.lastPrintedTimer = null;
		}
	};

	/** True while any print or label flow is running. */
	PD.isPrintBusy = function isPrintBusy() {
		return !!(PD.s.isCheckingPrint || PD.s.isPreparingPrint || PD.s.isPrinting || PD.s.printCreditLocked || PD.s.isPrintingLabels);
	};

	/** See printTokenNoSheet. */
	PD.keepsUnusedPrintToken = function keepsUnusedPrintToken() {
		return !!PD.s.printTokenNoSheet && !!PD.s.pendingPrintToken;
	};

	/**
	 * The drugs the label printer should print (Pro): the plan being
	 * edited if there is one, otherwise the plan that was just printed.
	 */
	PD.labelItems = function labelItems() {
		if (PD.s.items.length) {
			return PD.s.items;
		}
		return PD.s.lastPrintedItems || [];
	};

	/** The patient name that belongs with PD.labelItems(). */
	PD.labelPatient = function labelPatient() {
		if (PD.s.items.length) {
			return PD.getPatientName ? PD.getPatientName() : '';
		}
		return PD.s.lastPrintedPatient || '';
	};

	/**
	 * Clear all state associated with the current patient and plan.
	 *
	 * The shared drug list is stored in PD.s.items so every module sees the
	 * same state. Resetting it prevents a newly opened plan from containing
	 * medicines entered for the previous patient.
	 *
	 * Header, print status and configuration remain cached because they are
	 * still valid for the same logged-in pharmacist.
	 */
	PD.resetPlanState = function resetPlanState() {
		PD.s.items = [];
		PD.s.editingIndex = null;
		PD.s.currentFreq = '12h';
		PD.s.currentCustomMode = 'days';
		PD.s.currentDailyTime = 'morning';
		PD.s.currentWeekday = null;
		PD.s.currentMonthDay = null;
		PD.s.rxSourceText = '';
		PD.s.doseQtyAck = '';
		PD.s.duplicateAck = '';
		PD.s.currentStep = 1;
		PD.s.startDate = '';
		PD.s.firstSlot = 'morning';
		/* Forget the previous plan's day reading too. It holds the
		   previous patient's start date, so after «Νέος ασθενής» (or
		   reopening the popup) with a start of «Αύριο», startsLater()
		   would still say true and the «Πρώτη δόση» field would be built
		   hidden although the new plan starts today. The next startsLater() /
		   dayAt() takes a fresh reading. */
		PD.s.planDayZero = null;
		PD.s.planToday = null;
		/* Nothing of a pasted prescription survives the plan. */
		PD.s.rx = null;
		PD.s.rxNeedsFreq = false;
		PD.s.rxHint = '';
		/* A methotrexate confirmation belongs to one plan. */
		PD.s.methotrexateAck = '';
		/* An «Άλλο μέγεθος» left open without applying closes. */
		PD.s.labelCustomOpen = false;
		PD.s.labelCustomError = false;
		PD.invalidatePendingPrintToken();
	};

	PD.txt = function txt(key, fallback) {
		return Object.prototype.hasOwnProperty.call(PD.t, key)
			? PD.t[key]
			: (fallback || '');
	};

	/*
	 * BCP-47 locale used for date formatting (weekday + day/month) so the
	 * printed week tables follow the active language. 'en-GB' (not 'en-US')
	 * on purpose: it keeps the day/month order (20/07) while giving English
	 * weekday names, matching the Greek layout.
	 */
	PD.dateLocale = function dateLocale() {
		return (PD.lang === 'en') ? 'en-GB' : 'el-GR';
	};

	/*
	 * Switch the active dictionary and re-render the open tool. Pro-only:
	 * bails out for non-Pro so the locked toggle can never actually change
	 * the language (the button is still shown to them as a teaser). The
	 * server also gates the English strings' usefulness independently —
	 * this is a UI guard, not the security boundary.
	 */
	PD.setLang = function setLang(lang) {
		if (!PD.IS_PRO) {
			return;
		}

		/* Not while a print is being checked, recorded or printed.
		   rerenderForLang() would rebuild the form under the running print,
		   the sheet would come out in the old language, and the plan would
		   then count as changed — so the next reprint would be charged. */
		if (PD.isPrintBusy()) {
			return;
		}

		lang = (lang === 'en') ? 'en' : 'el';

		if (lang === PD.lang) {
			return;
		}

		PD.lang = lang;
		PD.t = PD.dicts[lang] || PD.dicts.el;
		PD.storeLang(lang);

		if (typeof PD.rerenderForLang === 'function') {
			PD.rerenderForLang();
		}
	};

	/* Escapes quotes too, so the result is safe inside an
	   attribute value as well as in text. escapeAttr() is kept as the
	   name to use for attributes. */
	PD.escapeHtml = function escapeHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	};

	PD.escapeAttr = function escapeAttr(str) {
		return PD.escapeHtml(str);
	};

	/**
	 * Remove the error marking (aria-invalid, and the link to the
	 * message) from every field. Run by setMessage(), so a field is only
	 * ever marked while ITS message is the one on screen.
	 */
	PD.clearFieldErrors = function clearFieldErrors() {
		document.querySelectorAll('[data-pd-error]').forEach(function (el) {
			el.removeAttribute('aria-invalid');
			el.removeAttribute('data-pd-error');
			var ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) {
				return id && 'pd-message' !== id;
			});
			if (ids.length) {
				el.setAttribute('aria-describedby', ids.join(' '));
			} else {
				el.removeAttribute('aria-describedby');
			}
		});
	};

	/**
	 * Show an error that belongs to one field. The message goes to
	 * the shared #pd-message (role="alert"), the field is marked
	 * aria-invalid and points at the message with aria-describedby, and
	 * focus moves to it, so a screen reader user hears what is wrong and
	 * lands where it can be fixed.
	 *
	 * @param {Element|string} field       The field or its id.
	 * @param {string}         text        Message.
	 * @param {boolean}        [notInvalid] True when the field is not
	 *        wrong itself (e.g. a medicine typed but not added): it is
	 *        focused and described, but not marked aria-invalid.
	 * @param {boolean}        [noFocus] True to leave focus where it is
	 *        (a message re-shown after a rebuild, not a user action).
	 */
	PD.flagFieldError = function flagFieldError(field, text, notInvalid, noFocus) {
		PD.setMessage(text, 'error');
		var el = typeof field === 'string' ? document.getElementById(field) : field;
		if (!el) {
			return;
		}
		el.setAttribute('data-pd-error', '1');
		if (!notInvalid) {
			el.setAttribute('aria-invalid', 'true');
		}
		var ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
		if (ids.indexOf('pd-message') === -1) {
			ids.push('pd-message');
		}
		el.setAttribute('aria-describedby', ids.join(' '));
		if (noFocus) {
			return;
		}
		try {
			el.focus();
		} catch (e) {
			/* ignore */
		}
	};

	PD.setMessage = function setMessage(text, type) {
		var el = document.getElementById('pd-message');

		PD.clearFieldErrors();

		if (!el) {
			return;
		}

		/* Errors are announced at once (role="alert"); every
		   other message stays polite. The role is set before the text so
		   the change is announced with the new role. */
		var isError = 'error' === type && !!text;
		el.setAttribute('role', isError ? 'alert' : 'status');
		el.setAttribute('aria-live', isError ? 'assertive' : 'polite');
		el.textContent = text || '';
		el.className = type
			? 'pd-message pd-message-' + type
			: 'pd-message';
	};

	PD.clearMessage = function clearMessage() {
		PD.setMessage('');
	};

	PD.setBusy = function setBusy(button, busy, label) {
		if (!button) {
			return;
		}

		/* The markup is kept, not only the text, so the label
		   button's aria-hidden icon survives a print. It is the plugin's
		   own markup, restored as it was. */
		if (busy) {
			button.dataset.originalText =
				button.dataset.originalText || button.innerHTML;

			button.textContent =
				label ||
				PD.txt(
					'pleaseWait',
					'Παρακαλώ περιμένετε...'
				);

			button.disabled = true;
			button.classList.add('is-loading');
			return;
		}

		if (button.dataset.originalText) {
			button.innerHTML = button.dataset.originalText;
		}

		delete button.dataset.originalText;

		button.disabled = false;
		button.classList.remove('is-loading');
	};
})();