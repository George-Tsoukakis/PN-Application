/**
 * PlanDose — preview.js
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
	 * Day zero of the plan: midnight of the chosen start date, or
	 * of the day the sheet is being built when no start date was chosen.
	 *
	 * Every date on the printout is an offset from this — the column
	 * headers, the boxes of an every-N-days schedule, the "Κάθε Τρίτη"
	 * matching, the printed date in the header. If each of those called
	 * `new Date()` for itself, a plan built across midnight could have a
	 * day header resolve to one day while sparseDayHasDose(item, 0)
	 * resolves to the next, so the ticks would land in the wrong columns
	 * on a sheet a patient is supposed to follow for a month.
	 *
	 * beginDayPass() takes the reading once, at the top of
	 * buildPrintHtml(), and everything in that pass works from the same
	 * one. It is deliberately re-taken per pass rather than pinned for the
	 * session: a popup left open overnight should print tomorrow's plan
	 * tomorrow, not yesterday's.
	 */
	PD.beginDayPass = function beginDayPass() {
		/* Labels of the plan just printed are built against that
		   plan's own day pass (PD.withPrintedPlan(), pro-labels.js). */
		if (PD.s.dayPassFrozen && typeof PD.s.planDayZero === 'number') {
			return PD.s.planDayZero;
		}
		var now = new Date();
		var today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
		var start = PD.parseStartDate(PD.s.startDate);
		/* Day zero is the chosen start date when there is one. The
		   real calendar day is kept separately for "printed on" and the
		   label date, which must never move with the start date. */
		PD.s.planToday = today;
		PD.s.planDayZero = (null === start) ? today : start;
		return PD.s.planDayZero;
	};

	/**
	 * How far ahead a plan may start. A limit exists only to catch typos
	 * such as 2062 for 2026; three months covers any real case.
	 */
	PD.MAX_START_AHEAD_DAYS = 90;

	/**
	 * Stored as the start date while the date field holds an incomplete
	 * date. It never parses, so startDateProblem() reports 'invalid' and
	 * the plan cannot be added to or printed until the date is fixed.
	 */
	PD.START_DATE_INCOMPLETE = 'incomplete';

	/**
	 * Parse the value of the start-date input ("YYYY-MM-DD", the format a
	 * date input always reports) into a local-midnight timestamp.
	 *
	 * Built from the parts with new Date(y, m, d), NOT new Date(string):
	 * the string form is parsed as UTC and lands on the previous day west
	 * of Greenwich. Impossible dates (2026-02-30) are rejected rather than
	 * rolled over into the next month.
	 *
	 * @param {string} value Input value.
	 * @return {number|null} Timestamp, or null when empty or invalid.
	 */
	PD.parseStartDate = function parseStartDate(value) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value == null ? '' : value).trim());
		if (!m) {
			return null;
		}
		var y = parseInt(m[1], 10);
		var mo = parseInt(m[2], 10) - 1;
		var d = parseInt(m[3], 10);
		var date = new Date(y, mo, d);
		if (date.getFullYear() !== y || date.getMonth() !== mo || date.getDate() !== d) {
			return null;
		}
		return date.getTime();
	};

	/** "YYYY-MM-DD" of a Date in LOCAL time (toISOString() would be UTC). */
	PD.isoDate = function isoDate(date) {
		var pad = function (n) {
			return (n < 10 ? '0' : '') + n;
		};
		return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
	};

	/** Today's local date as "YYYY-MM-DD", read from the clock now. */
	PD.todayIso = function todayIso() {
		return PD.isoDate(new Date());
	};

	/** "YYYY-MM-DD" of today + `days`, read from the clock now. */
	PD.isoDateAhead = function isoDateAhead(days) {
		var now = new Date();
		return PD.isoDate(new Date(now.getFullYear(), now.getMonth(), now.getDate() + days));
	};

	/**
	 * What is wrong with a start-date value, if anything.
	 *
	 * @param {string} value Input value ('' means "today").
	 * @return {string} '' when usable, otherwise one of 'invalid',
	 *                  'past', 'tooFar'.
	 */
	PD.startDateProblem = function startDateProblem(value) {
		var raw = String(value == null ? '' : value).trim();
		if ('' === raw) {
			return '';
		}
		var start = PD.parseStartDate(raw);
		if (null === start) {
			return 'invalid';
		}
		/* Compared as "YYYY-MM-DD" strings, which sort like dates, so no
		   millisecond arithmetic can be thrown off by a DST change. */
		if (raw < PD.todayIso()) {
			return 'past';
		}
		if (raw > PD.isoDateAhead(PD.MAX_START_AHEAD_DAYS)) {
			return 'tooFar';
		}
		return '';
	};

	/** The real calendar day of the current pass (never the start date). */
	PD.todayDate = function todayDate() {
		if (typeof PD.s.planToday !== 'number') {
			PD.beginDayPass();
		}
		return new Date(PD.s.planToday);
	};

	/** True when the plan starts on a day other than today. */
	PD.startsLater = function startsLater() {
		if (typeof PD.s.planDayZero !== 'number' || typeof PD.s.planToday !== 'number') {
			PD.beginDayPass();
		}
		return PD.s.planDayZero !== PD.s.planToday;
	};

	/**
	 * A fresh Date for `offset` days after day zero. Falls back to taking
	 * a reading now if no pass is open — the live "Σύνολο: N δόσεις" line
	 * under the duration field is computed outside any render.
	 */
	PD.dayAt = function dayAt(offset) {
		var base = (typeof PD.s.planDayZero === 'number')
			? PD.s.planDayZero
			: PD.beginDayPass();
		var d = new Date(base);
		d.setDate(d.getDate() + (parseInt(offset, 10) || 0));
		return d;
	};

	PD.unitLabel = function unitLabel(val) {
		for (var i = 0; i < PD.unitOptions.length; i++) {
			if (PD.unitOptions[i].val === val) {
				return PD.txt(PD.unitOptions[i].key, val);
			}
		}
		return val || '';
	};

	/**
	 * The four selectable "part of day" labels used both for the once-daily
	 * (24h) frequency's chosen time slot, and as the display labels for the
	 * 6h frequency's 4 fixed daily doses (see getSlots()).
	 */
	PD.dailyTimeLabels = function dailyTimeLabels() {
		return {
			morning: PD.txt('morning', 'Πρωί'),
			noon: PD.txt('noon', 'Μεσημέρι'),
			afternoon: PD.txt('afternoon', 'Απόγευμα'),
			evening: PD.txt('evening', 'Βράδυ')
		};
	};

	/**
	 * Normalize an item's stored dailyTime to one of the 4 known keys,
	 * defaulting to 'morning' for legacy items saved before this field
	 * existed (so those 24h drugs stay once daily, shown as "Πρωί").
	 */
	PD.normalizeDailyTime = function normalizeDailyTime(value) {
		var labels = PD.dailyTimeLabels();
		return Object.prototype.hasOwnProperty.call(labels, value) ? value : 'morning';
	};

	PD.doseLabel = function doseLabel(item) {
		if (!item || !item.doseAmount) {
			return '';
		}
		/* Never the raw typed text — "1.000" printed as typed reads
		   as a thousand. Always the canonical number, formatted for the
		   active language ("1,5" / "1.5"). */
		return PD.formatDose(item.doseAmount) + ' ' + PD.unitLabel(item.doseUnit);
	};

	PD.getIntervalDays = function getIntervalDays(item) {
		var raw = String(item && item.customIntervalDays || '').trim();
		var n = parseInt(raw, 10);
		return Math.max(1, Math.min(90, n || 7));
	};

	/* 30 days is printed as «Κάθε 30 ημέρες» — it is not a calendar
	   month (that is customMode 'monthday'). */
	PD.intervalDaysLabel = function intervalDaysLabel(n) {
		if (1 === n) {
			return PD.txt('everyOneDay', 'Κάθε ημέρα');
		}
		if (7 === n) {
			return PD.txt('every7Days', 'Μία φορά την εβδομάδα');
		}
		if (14 === n) {
			return PD.txt('every14Days', 'Κάθε 2 εβδομάδες');
		}
		return PD.txt('everyNDays', 'Κάθε %d ημέρες').replace('%d', n);
	};

	/**
	 * A weekday (getDay() 0..6) that was actually chosen, or null.
	 * Unlike normalizeWeekday() it never turns «not chosen» into Monday.
	 */
	PD.validWeekday = function validWeekday(value) {
		if (null == value || '' === value) {
			return null;
		}
		var raw = String(value).trim();
		if (!/^\d$/.test(raw)) {
			return null;
		}
		var n = parseInt(raw, 10);
		return n <= 6 ? n : null;
	};

	/** A day of the month 1..31, or null when not (validly) chosen. */
	PD.validMonthDay = function validMonthDay(value) {
		if (null == value || '' === value) {
			return null;
		}
		var raw = String(value).trim();
		if (!/^\d{1,2}$/.test(raw)) {
			return null;
		}
		var n = parseInt(raw, 10);
		return (n >= 1 && n <= 31) ? n : null;
	};

	PD.isMonthDayCustom = function isMonthDayCustom(item) {
		return !!item && 'custom' === item.freq && 'monthday' === item.customMode;
	};

	/**
	 * The calendar day of `date`'s month on which a monthly dose
	 * falls: the chosen day, or the month's last day when it is shorter
	 * (31 → 30 April, 29–31 → 28/29 February).
	 */
	PD.monthDoseDay = function monthDoseDay(date, monthDay) {
		var last = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
		return Math.min(monthDay, last);
	};

	/**
	 * Custom \"specific weekday\" mode: the dose is taken only on one fixed
	 * weekday (e.g. every Tuesday), regardless of what day the plan starts.
	 * Weekday is stored as a JavaScript getDay() value (0 = Sunday … 6 =
	 * Saturday); default Monday (1) for legacy/blank items.
	 */
	PD.normalizeWeekday = function normalizeWeekday(value) {
		var n = parseInt(value, 10);
		return (n >= 0 && n <= 6) ? n : 1;
	};

	PD.isWeekdayCustom = function isWeekdayCustom(item) {
		return !!item && 'custom' === item.freq && 'weekday' === item.customMode;
	};

	/**
	 * Both custom day-based modes (\"every N days\" and \"every <weekday>\")
	 * render as a single \"Δόση\" row with a box only on the matching day
	 * columns, so most of the table logic is shared.
	 */
	PD.isSparseDayCustom = function isSparseDayCustom(item) {
		/*
		 * EVERY custom frequency is
		 * day-based ("κάθε N ημέρες" or "κάθε <weekday>"), so this is
		 * simply "is it custom". Written against item.freq rather than as
		 * the union of the two mode checks so that an item carrying an
		 * unrecognised customMode still renders as a single "Δόση" row
		 * (falling back to the every-N-days path in sparseDayHasDose).
		 */
		return !!item && 'custom' === item.freq;
	};

	/**
	 * Localized weekday name, derived from the active print locale so it
	 * always matches the day headers (Greek vs English) with no separate
	 * dictionary to keep in sync. `short` returns the abbreviated form
	 * used on the picker chips.
	 */
	PD.weekdayName = function weekdayName(dow, short) {
		var d = PD.dayAt(0);
		d.setDate(d.getDate() + ((PD.normalizeWeekday(dow) - d.getDay() + 7) % 7));
		var name = d.toLocaleDateString(PD.dateLocale(), {
			weekday: short ? 'short' : 'long'
		});
		return name.charAt(0).toUpperCase() + name.slice(1);
	};

	/**
	 * Whether the drug's dose falls on a given day offset (0 = today).
	 * For \"every N days\" that is every Nth column from the start; for the
	 * weekday mode it is every column whose real calendar date lands on
	 * the chosen weekday. The date comes from PD.dayAt(), like every date
	 * on the sheet, so the boxes always line up with the printed days.
	 */
	PD.sparseDayHasDose = function sparseDayHasDose(item, dayOffset) {
		if (PD.isWeekdayCustom(item)) {
			/* A weekday that was never chosen has no dose at all,
			   so the item cannot be added or printed (0 doses). */
			var dow = PD.validWeekday(item.customWeekday);
			return null !== dow && PD.dayAt(dayOffset).getDay() === dow;
		}
		if (PD.isMonthDayCustom(item)) {
			/* Real calendar dates from dayAt(), like the weekday
			   mode, so the dose lands on the same column the header shows
			   (DST-safe: dayAt() moves by calendar days). */
			var md = PD.validMonthDay(item.customMonthDay);
			if (null === md) {
				return false;
			}
			var date = PD.dayAt(dayOffset);
			return date.getDate() === PD.monthDoseDay(date, md);
		}
		return 0 === dayOffset % PD.getIntervalDays(item);
	};

	PD.getFreqLabel = function getFreqLabel(item) {
		if (!item) {
			return '';
		}
		if ('custom' === item.freq) {
			if (PD.isWeekdayCustom(item)) {
				if (null === PD.validWeekday(item.customWeekday)) {
					return PD.txt('everyWeekdayUnset', 'Μία φορά την εβδομάδα (επιλέξτε ημέρα)');
				}
				return PD.txt('everyWeekday', 'Κάθε %s').replace('%s', PD.weekdayName(item.customWeekday));
			}
			if (PD.isMonthDayCustom(item)) {
				var md = PD.validMonthDay(item.customMonthDay);
				if (null === md) {
					return PD.txt('everyMonthDayUnset', 'Μία φορά τον μήνα (επιλέξτε ημέρα)');
				}
				/* Days a month can lack carry the rule on the sheet itself. */
				return md >= 29
					? PD.txt('everyMonthDayLast', 'Μία φορά τον μήνα, στις %d (ή την τελευταία ημέρα του μήνα)').replace('%d', md)
					: PD.txt('everyMonthDay', 'Μία φορά τον μήνα, στις %d').replace('%d', md);
			}
			return PD.intervalDaysLabel(PD.getIntervalDays(item));
		}
		/*
		 * The four fixed frequencies are labelled by count ("3 φορές την
		 * ημέρα"), not by hours: they do NOT schedule by the clock, they
		 * place doses in named parts of the day (see getSlots()). A label
		 * like "Κάθε 8 ώρες" would promise an interval
		 * the printed table does not produce. The dayparts are
		 * spelled out next to the count so the patient sees exactly when.
		 */
		var hourLabel = PD.txt(PD.freqLabelKey[item.freq], '');
		if (!hourLabel) {
			return '';
		}
		var slots = PD.getSlots(item);
		return slots.length ? hourLabel + ' (' + slots.join(', ') + ')' : hourLabel;
	};

	/**
	 * How many doses this item adds up to over its whole course, and how
	 * many units (tablets, ml, …) that works out to.
	 *
	 * The dosing days are counted with sparseDayHasDose() rather than
	 * derived with arithmetic, so the total always matches exactly what
	 * the printed table shows — including the partial final week of an
	 * every-N-days or weekday schedule.
	 *
	 * A first dose later than «Πρωί» does not change this total.
	 * The dayparts that fall before it on the start day carry over to one
	 * extra day at the end (see skippedFirstDaySlots()), so a course of
	 * "2 φορές την ημέρα × 7 ημέρες" is always 14 doses.
	 *
	 * @return {{doses: number, units: number|null}|null} null when the
	 *         duration is not usable yet.
	 */
	PD.doseTotals = function doseTotals(item) {
		if (!item) {
			return null;
		}
		var days = parseInt(item.days, 10);
		if (!days || days < 1) {
			return null;
		}
		var perDay = 1;
		var dosingDays = 0;
		var d;
		if ('custom' === item.freq) {
			for (d = 0; d < days; d++) {
				if (PD.sparseDayHasDose(item, d)) {
					dosingDays++;
				}
			}
		} else {
			perDay = PD.getSlots(item).length;
			dosingDays = days;
		}
		var doses = dosingDays * perDay;
		var amount = PD.parseDoseAmount(item.doseAmount, PD.DOSE_NO_LIMIT);
		var units = isNaN(amount) ? null : Math.round(doses * amount * 1000) / 1000;
		return {
			doses: doses,
			units: units
		};
	};

	/**
	 * The day offsets on which a day-based (custom) item has a
	 * dose, counted exactly like doseTotals(). [] for fixed frequencies.
	 */
	PD.doseDayOffsets = function doseDayOffsets(item) {
		var out = [];
		var days = parseInt(item && item.days, 10);
		if (!item || 'custom' !== item.freq || !(days >= 1)) {
			return out;
		}
		for (var d = 0; d < days; d++) {
			if (PD.sparseDayHasDose(item, d)) {
				out.push(d);
			}
		}
		return out;
	};

	/**
	 * «Τρί 29/09, Τρί 06/10, …» — the actual dates of a weekly,
	 * monthly or every-N-days item, so the pharmacist sees them before it
	 * is added. More than six: the first three, «…», the last. '' when
	 * the item has no day-based schedule or no dose.
	 */
	PD.doseDatesText = function doseDatesText(item) {
		var offsets = PD.doseDayOffsets(item);
		if (!offsets.length) {
			return '';
		}
		var shown = offsets.length > 6
			? offsets.slice(0, 3).map(PD.shortDay).concat(['…', PD.shortDay(offsets[offsets.length - 1])])
			: offsets.map(PD.shortDay);
		return shown.join(', ').replace(', …,', ' …');
	};

	/**
	 * Localized number for display: Greek uses a decimal comma, and
	 * whole numbers must not pick up a trailing ",0".
	 *
	 * Delegates to PD.formatDecimal(), the same formatter as the
	 * dose quantity, so the totals line and the plan always agree. It
	 * never groups thousands: "1.080" in Greek on a dosage sheet reads
	 * like a decimal.
	 */
	PD.formatNumber = function formatNumber(value) {
		return PD.formatDecimal(value);
	};

	/** The four dayparts, in the order they come in a day. */
	PD.SLOT_ORDER = ['morning', 'noon', 'afternoon', 'evening'];

	/** A first-dose daypart, defaulting to 'morning'. */
	PD.normalizeFirstSlot = function normalizeFirstSlot(value) {
		return PD.SLOT_ORDER.indexOf(value) === -1 ? 'morning' : value;
	};

	/**
	 * The first-dose daypart that actually applies. Only a plan that
	 * starts TODAY can have dayparts that have already passed; a plan that
	 * starts on a later day always starts with the whole day (morning),
	 * whatever was chosen while the start date was still today.
	 */
	PD.effectiveFirstSlot = function effectiveFirstSlot() {
		return PD.startsLater() ? 'morning' : PD.normalizeFirstSlot(PD.s.firstSlot);
	};

	/**
	 * The daypart keys of a fixed frequency, in the order of the day, or
	 * null for a custom (day-based) frequency, which has no dayparts.
	 */
	PD.getSlotKeys = function getSlotKeys(item) {
		if ('24h' === item.freq) {
			return [PD.normalizeDailyTime(item.dailyTime)];
		}
		if ('12h' === item.freq) {
			return ['morning', 'evening'];
		}
		if ('8h' === item.freq) {
			return ['morning', 'noon', 'evening'];
		}
		if ('6h' === item.freq) {
			return PD.SLOT_ORDER.slice();
		}
		return null;
	};

	PD.getSlots = function getSlots(item) {
		var keys = PD.getSlotKeys(item);
		if (keys) {
			var labels = PD.dailyTimeLabels();
			return keys.map(function (key) {
				return labels[key];
			});
		}
		/*
		 * Every remaining case is a custom day-based frequency: one dose
		 * per occurrence, shown as a single "Δόση" row with a box only on
		 * the matching day columns.
		 */
		return [PD.txt('doseRowLabel', 'Δόση')];
	};

	/**
	 * How many of a fixed-frequency drug's doses fall BEFORE the
	 * plan's first dose on day zero (e.g. 1 for "2 φορές την ημέρα" when
	 * the first dose is «Βράδυ»). Those doses are not dropped: they move
	 * to one extra day after the last one, so the course keeps exactly
	 * days × doses-per-day doses. Custom (day-based) frequencies have no
	 * dayparts and are not affected.
	 */
	PD.skippedFirstDaySlots = function skippedFirstDaySlots(item) {
		var keys = PD.getSlotKeys(item);
		if (!keys) {
			return 0;
		}
		var first = PD.SLOT_ORDER.indexOf(PD.effectiveFirstSlot());
		var skipped = 0;
		keys.forEach(function (key) {
			if (PD.SLOT_ORDER.indexOf(key) < first) {
				skipped++;
			}
		});
		return skipped;
	};

	/** Number of day columns the drug's table needs (days, plus one when doses carry over). */
	PD.planColumns = function planColumns(item) {
		var days = parseInt(item.days, 10) || 0;
		return days + (days > 0 && PD.skippedFirstDaySlots(item) > 0 ? 1 : 0);
	};

	/**
	 * Whether a fixed-frequency drug has a dose in row `slotIndex` (index
	 * into getSlotKeys()) of day column `dayOffset`. The skipped slots are
	 * always the first ones of the day, so the carried-over doses on the
	 * extra day are those same first slots.
	 */
	PD.slotHasDose = function slotHasDose(item, dayOffset, slotIndex) {
		var days = parseInt(item.days, 10) || 0;
		var skipped = PD.skippedFirstDaySlots(item);
		if (dayOffset < 0 || dayOffset > days) {
			return false;
		}
		if (dayOffset === days) {
			return slotIndex < skipped;
		}
		if (0 === dayOffset) {
			return slotIndex >= skipped;
		}
		return true;
	};

	/** Short "Τρί 22/09" form of a day offset, for the course line. */
	PD.shortDay = function shortDay(offset) {
		var d = PD.dayAt(offset);
		var wd = d.toLocaleDateString(PD.dateLocale(), {
			weekday: 'short'
		});
		var dm = d.toLocaleDateString(PD.dateLocale(), {
			day: '2-digit',
			month: '2-digit'
		});
		return wd.charAt(0).toUpperCase() + wd.slice(1) + ' ' + dm;
	};

	/**
	 * «Πρώτη δόση: Βράδυ Τρί 22/09 • Τελευταία δόση: Πρωί Τετ 30/09»,
	 * printed under a drug's title only when some of its doses carry over
	 * to the extra day, so the patient is told plainly that the course
	 * ends a day later than the start date + duration suggests.
	 */
	/**
	 * First and last dose of a course whose doses carry over, or null when
	 * nothing carries over. Shared by the A4 course line and the Pro label.
	 *
	 * @return {{firstSlot:string, firstDay:number, lastSlot:string, lastDay:number}|null}
	 */
	PD.courseEnds = function courseEnds(item) {
		var skipped = PD.skippedFirstDaySlots(item);
		var keys = PD.getSlotKeys(item);
		var days = parseInt(item.days, 10) || 0;
		if (!skipped || !keys || days < 1) {
			return null;
		}
		var labels = PD.dailyTimeLabels();
		/* All of the day's doses can fall before the first one (a morning
		   once-a-day drug with the first dose «Βράδυ»): then the course
		   starts with the next day's first slot. */
		var firstOnDayZero = skipped < keys.length;
		return {
			firstSlot: labels[firstOnDayZero ? keys[skipped] : keys[0]],
			firstDay: firstOnDayZero ? 0 : 1,
			lastSlot: labels[keys[skipped - 1]],
			lastDay: days
		};
	};

	PD.courseLine = function courseLine(item) {
		var ends = PD.courseEnds(item);
		if (!ends) {
			return '';
		}
		return PD.format(
			PD.txt('courseLine', 'Πρώτη δόση: %1$s %2$s • Τελευταία δόση: %3$s %4$s'),
			ends.firstSlot,
			PD.shortDay(ends.firstDay),
			ends.lastSlot,
			PD.shortDay(ends.lastDay)
		);
	};

	/**
	 * True when a WHOLE DAY of the drug moves: the plan starts today and
	 * every one of its dayparts falls before the chosen «Πρώτη δόση» (a
	 * once-daily «Πρωί» drug with the first dose «Μεσημέρι»), so its
	 * first dose is tomorrow. The course line says so, but it is easy to
	 * miss; the pharmacist is warned on screen (never on the sheet).
	 *
	 * Built on courseEnds(), i.e. on the very rule that lays out the
	 * table, so the warning cannot disagree with the sheet. That rule
	 * already leaves out everything whose later first date is intended:
	 * a plan starting on a later day (effectiveFirstSlot() is 'morning'),
	 * and weekly, monthly and every-N-days schedules (no dayparts, their
	 * first date comes from sparseDayHasDose()). A drug that only loses
	 * SOME of today's doses (2 φορές, first dose «Βράδυ») still starts
	 * today and is not warned about either.
	 */
	PD.firstDoseShifted = function firstDoseShifted(item) {
		var ends = item ? PD.courseEnds(item) : null;
		return !!ends && ends.firstDay > 0;
	};

	/** The on-screen warning for a drug of firstDoseShifted(), or ''. */
	PD.firstDoseShiftText = function firstDoseShiftText(item) {
		if (!PD.firstDoseShifted(item)) {
			return '';
		}
		var ends = PD.courseEnds(item);
		return PD.format(
			PD.txt('firstDoseShiftWarn', 'Το «%1$s» ξεκινά αύριο (%2$s), γιατί η ώρα της πρώτης δόσης του πέρασε σήμερα. Αν πρέπει να πάρει δόση σήμερα, αλλάξτε την «Πρώτη δόση την ημέρα έναρξης».'),
			item.name || '',
			ends.firstSlot + ' ' + PD.shortDay(ends.firstDay)
		);
	};

	PD.chunk = function chunk(arr, size) {
		var out = [];
		for (var i = 0; i < arr.length; i += size) {
			out.push(arr.slice(i, i + size));
		}
		return out;
	};

	PD.itemSummary = function itemSummary(item) {
		var bits = [];
		if (PD.doseLabel(item)) {
			bits.push(PD.doseLabel(item));
		}
		if (PD.getFreqLabel(item)) {
			bits.push(PD.getFreqLabel(item));
		}
		if (item.days) {
			bits.push(item.days + ' ' + PD.txt('days', 'ημέρες'));
		}
		return bits.join(' • ');
	};

	PD.unitOptionsHtml = function unitOptionsHtml() {
		return PD.unitOptions.map(function (u) {
			return '<option value="' + PD.escapeAttr(u.val) + '">' + PD.escapeHtml(PD.txt(u.key, u.val)) + '</option>';
		}).join('');
	};

	/*
	 * Weekday picker chips, ordered Monday → Sunday (getDay() values
	 * 1..6,0). Labels are the localized short weekday names so the picker
	 * follows the active language automatically. The chip matching the
	 * currently selected weekday is pre-marked active.
	 */
	PD.weekdayChipsHtml = function weekdayChipsHtml() {
		var order = [1, 2, 3, 4, 5, 6, 0];
		/* No chip is pre-marked until a day is chosen. */
		var current = PD.validWeekday(PD.s.currentWeekday);
		return order.map(function (dow) {
			var active = dow === current;
			return '<button type="button" class="plandose-chip pd-weekday-chip' +
				(active ? ' active' : '') + '" data-weekday="' + dow +
				'" aria-pressed="' + (active ? 'true' : 'false') + '">' +
				PD.escapeHtml(PD.weekdayName(dow, true)) + '</button>';
		}).join('');
	};

	/**
	 * The dose amount with the unit in its real singular or
	 * plural form instead of the form's «Δισκίο(α)»: «1 Δισκίο», «2 Δισκία»,
	 * «1 Κάψουλα», «3 Κάψουλες», «1 Tablet», «2 Tablets». Units without a
	 * «(…)» ending (Ml, Mg) are left as they are. Anything the rule does
	 * not recognise falls back to the dictionary wording, never to a guess.
	 *
	 * @param {Object} item Medicine.
	 * @return {string} e.g. «1 Δισκίο», or '' when no amount was entered.
	 */
	PD.doseAmountText = function doseAmountText(item) {
		if (!item || !item.doseAmount) {
			return '';
		}
		/* The canonical number, formatted like the A4 plan. */
		var raw = PD.formatDose(item.doseAmount);
		var unit = PD.unitLabel(item.doseUnit);
		var m = /^(.*?)\s*\(([^()]+)\)$/.exec(unit);
		/* Plurals no suffix rule can build — the accent moves
		   («Επάλειψη» → «Επαλείψεις») or sits on the ending («Ψεκασμός» →
		   «Ψεκασμοί»). */
		/* A plain string is the plural (the singular is the base);
		   an object names both, for «Μονάδες (IU)» whose bracket is not an
		   ending. */
		var irregular = {
			'Επάλειψη': 'Επαλείψεις',
			'Ψεκασμός': 'Ψεκασμοί',
			'Ένεση': 'Ενέσεις',
			'Μονάδες': { one: 'Μονάδα IU', many: 'Μονάδες IU' },
			'Suppository': 'Suppositories',
			'Patch': 'Patches',
			'Units': { one: 'IU', many: 'IU' }
		};
		/* Greek takes the singular up to one: «0,5 Δισκίο», «1 Δισκίο»,
		   «1,5 Δισκία». English keeps it for exactly one («0.5 tablets»). */
		var amount = PD.parseDoseAmount(item.doseAmount, PD.DOSE_NO_LIMIT);
		var single = 1 === amount || ('en' !== PD.lang && amount > 0 && amount < 1);
		if (m && Object.prototype.hasOwnProperty.call(irregular, m[1])) {
			var forms = irregular[m[1]];
			if (typeof forms === 'string') {
				return raw + '\u00a0' + (single ? m[1] : forms);
			}
			/* Non-breaking, as below: «2 Ψεκασμοί» never splits on the label. */
			return raw + '\u00a0' + (single ? forms.one : forms.many);
		}
		if (m) {
			var base = m[1];
			var suf = m[2];
			if (single) {
				unit = base;
			} else if ('s' === suf) {
				unit = base + 's';
			} else if ('α' === suf && /ο$/.test(base)) {
				unit = base.slice(0, -1) + 'α';
			} else if ('ες' === suf && /α$/.test(base)) {
				unit = base.slice(0, -1) + 'ες';
			} else if ('ες' === suf && /ή$/.test(base)) {
				unit = base.slice(0, -1) + 'ές';
			} else if ('οι' === suf && /ος$/.test(base)) {
				unit = base.slice(0, -2) + 'οι';
			}
		}
		return raw + '\u00a0' + unit;
	};

	/*
	 * The A4 sheet is laid out BY DAY. One card per day lists what
	 * to take in each part of the day, so the patient reads today's card
	 * instead of finding today's column in one table per medicine.
	 *
	 * Every dose on the cards comes from the same helpers the per-medicine
	 * tables used (slotHasDose() for the fixed frequencies, including the
	 * doses carried over to the extra day; sparseDayHasDose() for the
	 * day-based ones), and doseTotals() is still what validation checks,
	 * so the sheet and the checks cannot disagree.
	 */

	/** Group key for day-based (custom) frequencies, which have no daypart. */
	PD.ANYTIME_SLOT = 'anytime';

	/** Number of day cards the plan needs: the longest medicine, carried-over day included. */
	PD.planDayCount = function planDayCount() {
		var max = 0;
		PD.s.items.forEach(function (item) {
			var n = PD.isSparseDayCustom(item) ? (parseInt(item.days, 10) || 0) : PD.planColumns(item);
			if (n > max) {
				max = n;
			}
		});
		return max;
	};

	/**
	 * What to take on day `dayOffset`, grouped by part of the day in the
	 * order of the day, day-based medicines last. Empty groups are left out.
	 *
	 * @return {Array<{key:string,label:string,entries:Array<{index:number,item:Object}>}>}
	 */
	PD.dayDoses = function dayDoses(dayOffset) {
		var labels = PD.dailyTimeLabels();
		var order = PD.SLOT_ORDER.concat([PD.ANYTIME_SLOT]);
		var groups = {};
		order.forEach(function (key) {
			groups[key] = [];
		});
		PD.s.items.forEach(function (item, index) {
			if (PD.isSparseDayCustom(item)) {
				var days = parseInt(item.days, 10) || 0;
				if (dayOffset < days && PD.sparseDayHasDose(item, dayOffset)) {
					groups[PD.ANYTIME_SLOT].push({ index: index, item: item });
				}
				return;
			}
			var keys = PD.getSlotKeys(item) || [];
			keys.forEach(function (key, slotIndex) {
				if (PD.slotHasDose(item, dayOffset, slotIndex)) {
					groups[key].push({ index: index, item: item });
				}
			});
		});
		return order.filter(function (key) {
			return groups[key].length > 0;
		}).map(function (key) {
			return {
				key: key,
				label: PD.ANYTIME_SLOT === key ? PD.txt('anyTimeOfDay', 'Μέσα στην ημέρα') : labels[key],
				entries: groups[key]
			};
		});
	};

	/** «Πέμπτη 22-10-2026» for a day card. */
	PD.dayCardTitle = function dayCardTitle(offset) {
		var d = PD.dayAt(offset);
		var wd = d.toLocaleDateString(PD.dateLocale(), { weekday: 'long' });
		var pad = function (n) {
			return (n < 10 ? '0' : '') + n;
		};
		return wd.charAt(0).toUpperCase() + wd.slice(1) + ' ' + pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear();
	};

	/** The list of medicines at the top of the sheet: name on the left, dose, frequency, duration and notes on the right. */
	PD.buildMedicineKey = function buildMedicineKey() {
		var html = '<section class="pd-med-key">';
		html += '<h3>' + PD.escapeHtml(PD.txt('yourMedicines', 'Τα Φάρμακά σας:')) + '</h3>';
		PD.s.items.forEach(function (item) {
			var bits = [];
			var amount = PD.doseAmountText(item);
			if (amount) {
				bits.push(amount);
			}
			if (PD.getFreqLabel(item)) {
				bits.push(PD.getFreqLabel(item));
			}
			var nDays = parseInt(item.days, 10) || 0;
			var duration = 1 === nDays
				? PD.txt('forOneDay', 'Για 1 μέρα')
				: PD.txt('forNDays', 'Για %d μέρες').replace('%d', nDays);
			html += '<div class="pd-med-key-row">';
			html += '<div class="pd-med-key-name">' + PD.escapeHtml(item.name) + '</div>';
			html += '<div class="pd-med-key-dose"><div>' + bits.map(PD.escapeHtml).join(' · ') + ' / ' + PD.escapeHtml(duration) + '</div>';
			var courseLine = PD.courseLine(item);
			if (courseLine) {
				html += '<div class="pd-drug-course">' + PD.escapeHtml(courseLine) + '</div>';
			}
			if (item.notes) {
				html += '<div class="pd-drug-notes">' + PD.escapeHtml(item.notes) + '</div>';
			}
			html += '</div></div>';
		});
		html += '</section>';
		return html;
	};

	/* One dose line: the name (and the pharmacist's notes under it) on the
	   left, the dose in bold and the tick box on the right. */
	PD.buildDayCard = function buildDayCard(offset, groups, part) {
		/* A day too long for one A4 page is a full-width
		   card, or several «(συνέχεια)» cards — see PD.dayCardLayout(). */
		if (!part && typeof PD.dayCardLayout === 'function') {
			var parts = PD.dayCardLayout(groups).parts;
			if (parts.length > 1) {
				return parts.map(function (g, i) {
					return PD.buildDayCard(offset, g, i + 1);
				}).join('');
			}
		}
		var html = '<section class="' + (typeof PD.dayCardClass === 'function' ? PD.dayCardClass(groups, part) : 'pd-day-card') + '">';
		html += '<div class="pd-day-card-head"><strong>' + PD.escapeHtml(PD.dayCardTitle(offset) + (part > 1 ? ' ' + PD.txt('dayCardContinued', '(συνέχεια)') : '')) + '</strong>';
		html += '<span>' + PD.escapeHtml(PD.txt('dayNumber', 'Ημέρα %d').replace('%d', offset + 1)) + '</span></div>';
		html += '<div class="pd-day-card-body">';
		groups.forEach(function (group) {
			html += '<div class="pd-day-slot"><div class="pd-day-slot-label">' + PD.escapeHtml(group.label) + '</div><div class="pd-day-slot-items">';
			group.entries.forEach(function (entry) {
				html += '<div class="pd-day-dose">';
				html += '<span class="pd-day-dose-name">' + PD.escapeHtml(entry.item.name);
				if (entry.item.notes) {
					/* Long notes in full only in the medicine list — see PD.dayCardNotesText(). */
					html += '<span class="pd-day-dose-notes">' + PD.escapeHtml(typeof PD.dayCardNotesText === 'function' ? PD.dayCardNotesText(entry.item.notes) : entry.item.notes) + '</span>';
				}
				html += '</span>';
				html += '<span class="pd-day-dose-amount">' + PD.escapeHtml(PD.doseAmountText(entry.item)) + '</span>';
				html += '<span class="plandose-box"></span>';
				html += '</div>';
			});
			html += '</div></div>';
		});
		html += '</div></section>';
		return html;
	};

	/**
	 * All day cards, two per row. A row is the unit that never splits
	 * across printed pages. Days with no dose at all (between the doses of
	 * a weekly medicine, once the daily ones have ended) get no card.
	 */
	PD.buildDayCards = function buildDayCards() {
		var cards = [];
		var total = PD.planDayCount();
		for (var d = 0; d < total; d++) {
			var groups = PD.dayDoses(d);
			if (groups.length) {
				cards.push(PD.buildDayCard(d, groups));
			}
		}
		return PD.chunk(cards, 2).map(function (row) {
			return '<div class="pd-day-row">' + row.join('') + '</div>';
		}).join('');
	};

	/** The error code buildPrintHtml() throws for an unusable start date. */
	PD.INVALID_START_DATE = 'invalid_start_date';

	/**
	 * @throws {Error} code/message PD.INVALID_START_DATE when the
	 *         start date is invalid, incomplete, past or too far: the sheet
	 *         is never built on «today» instead (beginDayPass() would).
	 */
	PD.buildPrintHtml = function buildPrintHtml() {
		/* A frozen pass (the plan just printed, see pro-labels.js) carries
		   its own, already checked day zero. */
		var problem = PD.s.dayPassFrozen ? '' : PD.startDateProblem(PD.s.startDate);
		if (problem) {
			var err = new Error(PD.INVALID_START_DATE);
			err.code = PD.INVALID_START_DATE;
			err.problem = problem;
			throw err;
		}
		/* One reading of the clock for the whole sheet — see beginDayPass(). */
		PD.beginDayPass();

		var patient = PD.getPatientName();
		var pharmName = PD.s.header ? PD.s.header.name : '';
		var pharmPhone = PD.s.header ? PD.s.header.phone_1 || PD.s.header.mobile_phone || '' : '';
		var pharmEmail = PD.s.header ? PD.s.header.email || '' : '';
		var pharmAddress = PD.s.header ? PD.s.header.address || '' : '';
		var dateOpts = {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric'
		};
		var todayStr = PD.todayDate().toLocaleDateString(PD.dateLocale(), dateOpts);
		/* Only when the plan does not start today; a same-day plan
		   prints no start line. */
		var startHtml = PD.startsLater()
			? '<span class="pd-print-start">' + PD.escapeHtml(PD.txt('planStartsOn', 'Έναρξη πλάνου:') + ' ' + PD.dayAt(0).toLocaleDateString(PD.dateLocale(), dateOpts)) + '</span>'
			: '';
		var introHtml = '';
		if (pharmName) {
			var introTemplate = PD.txt('printIntro', 'Το φαρμακείο %s ετοίμασε αυτό το πλάνο για να σας βοηθήσει να θυμάστε εύκολα τη λήψη των φαρμάκων σας.');
			var phIndex = introTemplate.indexOf('%s');
			if (phIndex === -1) {
				/* Translation without the %s placeholder: render as-is. */
				introHtml = '<p class="pd-print-intro">' + PD.escapeHtml(introTemplate) + '</p>';
			} else {
				var introBefore = introTemplate.slice(0, phIndex);
				var introAfter = introTemplate.slice(phIndex + 2);
				introHtml = '<p class="pd-print-intro">' + PD.escapeHtml(introBefore) + '<strong>' + PD.escapeHtml(pharmName) + '</strong>' + PD.escapeHtml(introAfter) + '</p>';
			}
		}
		var pharmacyBox = '<div class="pd-info-box">' + '<h3>' + PD.escapeHtml(PD.txt('pharmacySection', 'Στοιχεία Φαρμακείου')) + '</h3>' + '<p class="pd-info-name">' + PD.escapeHtml(pharmName || '—') + '</p>' + (pharmAddress ? '<p>' + PD.escapeHtml(pharmAddress) + '</p>' : '') + (pharmPhone ? '<p>' + PD.escapeHtml(pharmPhone) + '</p>' : '') + (pharmEmail ? '<p>' + PD.escapeHtml(pharmEmail) + '</p>' : '') + '</div>';
		var patientBox = '<div class="pd-info-box">' + '<h3>' + PD.escapeHtml(PD.txt('patientSection', 'Στοιχεία Ασθενή')) + '</h3>' + '<p class="pd-info-name">' + PD.escapeHtml(patient || '—') + '</p>' + '</div>';
		/* «Υπενθυμίσεις στο κινητό» — the QR of calendar-qr.js,
		   built from this same day pass. Absent module / feature off → ''. */
		var calendarBox = typeof PD.calendarQrHtml === 'function' ? PD.calendarQrHtml() : '';
		var drugsHtml = PD.s.items.length ? PD.buildMedicineKey() + '<div class="pd-day-cards">' + PD.buildDayCards() + '</div>' : '';
		if (!drugsHtml) {
			drugsHtml = '<div class="plandose-empty">' + PD.escapeHtml(PD.txt('addAtLeastOne', 'Προσθέστε τουλάχιστον ένα φάρμακο.')) + '</div>';
		}
		/* The Pro labels are NOT part of the A4 sheet: they are
		   printed on their own, on the label printer (buildLabelPrintDocument). */
		return '<div class="pd-print-document">' + '<header class="pd-print-header">' + '<div class="pd-print-brand">' + '<span class="pd-print-brand-icon" aria-hidden="true"></span>' + '<div>' + '<strong>' + PD.escapeHtml(PD.txt('printTitle', 'Πλάνο Δοσολογίας')) + '</strong>' + '<span>' + PD.escapeHtml(PD.txt('printedOn', 'Εκτυπώθηκε στις')) + ' ' + PD.escapeHtml(todayStr) + '</span>' + startHtml + '</div>' + '</div>' + '</header>' + (introHtml || '') + '<div class="pd-info-grid' + (calendarBox ? ' pd-info-grid-cal' : '') + '">' + pharmacyBox + patientBox + calendarBox + '</div>' + '<main class="pd-drugs">' + drugsHtml + '</main>' + '<footer class="plandose-disclaimer">' + PD.escapeHtml(PD.txt('disclaimer', 'Το παρόν πλάνο είναι βοηθητικό και δεν αντικαθιστά τις οδηγίες του γιατρού ή του φαρμακοποιού.')) + '</footer>' + '<p class="pd-print-thanks">' + PD.escapeHtml(PD.txt('thanksLine1', 'Ευχαριστούμε που εμπιστευτήκατε το φαρμακείο μας.')) + '<br>' + PD.escapeHtml(PD.txt('thanksLine2', 'Για οποιαδήποτε διευκρίνηση είμαστε πάντα στη διάθεσή σας!')) + '</p>' + '</div>';
	};

	PD.renderPreview = function renderPreview() {
		var previewArea = document.getElementById('pd-preview-area');
		if (previewArea) {
			/* The label preview exists only when the Pro module is loaded
			   (assets/js/pro-labels.js, served to Pro accounts only). */
			/* An unusable start date shows the problem, not a
			   sheet dated today. */
			var html;
			try {
				html = PD.buildPrintHtml();
			} catch (e) {
				if (!e || PD.INVALID_START_DATE !== e.code) {
					throw e;
				}
				var text = typeof PD.startDateMessage === 'function'
					? PD.startDateMessage(e.problem)
					: PD.txt('invalidStartDate', 'Η ημερομηνία έναρξης δεν είναι έγκυρη.');
				previewArea.innerHTML = '<div class="plandose-empty pd-preview-blocked" role="note">' + PD.escapeHtml(text) + '</div>';
				return;
			}
			/* What happened to the phone-reminder QR (notes left
			   out, or no QR at all) — on screen only, never on the sheet. */
			var qrNotice = typeof PD.calendarQrNotice === 'function' && PD.s.items.length ? PD.calendarQrNotice() : '';
			/* Drugs whose first dose moved to tomorrow — also on
			   screen only, from the same day pass as the sheet. */
			var shifts = PD.s.items.map(PD.firstDoseShiftText).filter(Boolean);
			PD.ensurePreviewStyles();
			previewArea.innerHTML = (shifts.length
				? '<div class="pd-preview-shift" role="note"><ul>' + shifts.map(function (text) {
					return '<li>' + PD.escapeHtml(text) + '</li>';
				}).join('') + '</ul></div>'
				: '') +
				(qrNotice ? '<p class="pd-preview-notice" role="note">' + PD.escapeHtml(qrNotice) + '</p>' : '') +
				'<div class="pd-preview-page"><div class="pd-preview-sheet">' + html + '</div></div>' +
				(typeof PD.buildLabelsPage === 'function' ? PD.buildLabelsPage() : '');
			PD.watchPreviewSheet(previewArea);
		}
	};

	/*
	 * The on-screen preview is styled by the print stylesheet itself
	 * (PD.PRINT_STYLES, print-styles.js), not by a look-alike copy in
	 * plandose.css: the pharmacist is told «this is what will be printed»,
	 * and two stylesheets drifted apart (card widths, header colours,
	 * notes). The rules are scoped to the preview's paper so they never
	 * reach the page around it.
	 *
	 * Scoped in the host page rather than rendered in an iframe: the sheet
	 * stays in the modal's DOM (tests, screen readers and find-in-page see
	 * it), scrolls with the preview area and needs no height syncing.
	 */
	PD.PREVIEW_SHEET_SCOPE = '#pd-preview-area .pd-preview-sheet';
	PD.PREVIEW_STYLE_ID = 'pd-preview-print-styles';

	/** Selector list → its top-level selectors (commas inside :is()/:has()/[..]/strings stay). */
	PD.splitSelectorList = function splitSelectorList(text) {
		var out = [];
		var depth = 0;
		var quote = '';
		var start = 0;
		for (var i = 0; i < text.length; i++) {
			var c = text.charAt(i);
			if (quote) {
				if ('\\' === c) {
					i++;
				} else if (c === quote) {
					quote = '';
				}
			} else if ('"' === c || "'" === c) {
				quote = c;
			} else if ('(' === c || '[' === c) {
				depth++;
			} else if (')' === c || ']' === c) {
				depth = Math.max(0, depth - 1);
			} else if (',' === c && 0 === depth) {
				out.push(text.slice(start, i));
				start = i + 1;
			}
		}
		out.push(text.slice(start));
		return out.map(function (s) {
			return s.trim();
		}).filter(Boolean);
	};

	/**
	 * One print selector → the same selector inside `scope`. The print
	 * document's html/body is the preview's paper, so they map to the
	 * scope itself.
	 */
	PD.scopeSelector = function scopeSelector(selector, scope) {
		var rest = selector.trim();
		var root = /^(?:html|body|:root)(?![\w-])\s*/i;
		var hadRoot = false;
		while (root.test(rest)) {
			rest = rest.replace(root, '');
			hadRoot = true;
		}
		if (!rest) {
			return hadRoot ? scope : '';
		}
		return scope + ' ' + rest;
	};

	/**
	 * CSS rules → their text scoped to `scope`. @media / @supports keep
	 * their condition (a print-only rule stays print-only); @page is left
	 * out, it has no meaning on screen; any other at-rule too, as it
	 * cannot be scoped (the print stylesheet has none).
	 */
	PD.scopeCssRules = function scopeCssRules(rules, scope) {
		var out = [];
		for (var i = 0; i < rules.length; i++) {
			var rule = rules[i];
			/* Some CSSOM implementations (jsdom) hand @page over as a
			   style rule whose selector is "@page". */
			if (1 === rule.type && '@' !== String(rule.selectorText || '').trim().charAt(0)) {
				var seen = {};
				var selectors = PD.splitSelectorList(rule.selectorText || '').map(function (s) {
					return PD.scopeSelector(s, scope);
				}).filter(function (s) {
					if (!s || seen[s]) {
						return false;
					}
					seen[s] = true;
					return true;
				});
				if (selectors.length) {
					out.push(selectors.join(', ') + ' { ' + rule.style.cssText + ' }');
				}
			} else if (4 === rule.type || 12 === rule.type) {
				var inner = PD.scopeCssRules(rule.cssRules || [], scope);
				if (inner) {
					out.push((4 === rule.type ? '@media ' + rule.media.mediaText : '@supports ' + rule.conditionText) + ' {\n' + inner + '\n}');
				}
			}
		}
		return out.join('\n');
	};

	/**
	 * A stylesheet's text scoped to `scope`, parsed by the browser's own
	 * CSS parser (a regex would trip over comments, strings and :has()).
	 * The throwaway <style> has media "not all": parsed, never applied.
	 */
	PD.scopeStylesheet = function scopeStylesheet(css, scope) {
		var parent = document.head || document.documentElement;
		if (!css || !parent) {
			return '';
		}
		var probe = document.createElement('style');
		probe.setAttribute('media', 'not all');
		probe.textContent = css;
		parent.appendChild(probe);
		try {
			return probe.sheet && probe.sheet.cssRules ? PD.scopeCssRules(probe.sheet.cssRules, scope) : '';
		} finally {
			parent.removeChild(probe);
		}
	};

	/** Puts the scoped print stylesheet in the page, once. */
	PD.ensurePreviewStyles = function ensurePreviewStyles() {
		if (document.getElementById(PD.PREVIEW_STYLE_ID) || 'string' !== typeof PD.PRINT_STYLES) {
			return;
		}
		var css;
		try {
			css = PD.scopeStylesheet(PD.PRINT_STYLES, PD.PREVIEW_SHEET_SCOPE);
		} catch (e) {
			/* Never break the preview over its styling. */
			return;
		}
		if (!css) {
			return;
		}
		var style = document.createElement('style');
		style.id = PD.PREVIEW_STYLE_ID;
		style.textContent = css;
		(document.head || document.documentElement).appendChild(style);
	};

	/**
	 * The paper is laid out at its printed width (A4 minus the page
	 * margins), so every line wraps where it wraps on paper, then scaled
	 * down to the preview's width. A transform keeps the layout untouched
	 * (zoom would re-wrap text at the smaller font size); the page box is
	 * sized to the scaled sheet so the preview scrolls to its real end.
	 */
	PD.fitPreviewSheet = function fitPreviewSheet(area) {
		var page = area && area.querySelector('.pd-preview-page');
		var sheet = page && page.querySelector('.pd-preview-sheet');
		if (!sheet) {
			return;
		}
		var cs = window.getComputedStyle(area);
		var avail = area.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
		var width = sheet.offsetWidth;
		var height = sheet.offsetHeight;
		/* Hidden (another step) or no layout engine: leave it unscaled. */
		if (!(avail > 0) || !(width > 0)) {
			return;
		}
		var scale = Math.min(1, avail / width);
		var transform = scale < 1 ? 'scale(' + scale + ')' : '';
		if (sheet.style.transform !== transform) {
			sheet.style.transform = transform;
		}
		var w = scale < 1 ? width * scale + 'px' : '';
		var h = scale < 1 ? height * scale + 'px' : '';
		if (page.style.width !== w) {
			page.style.width = w;
		}
		if (page.style.height !== h) {
			page.style.height = h;
		}
	};

	/* Re-fit when the preview area changes width (resize, step shown) or
	   the sheet changes height (fonts arriving). One observer, re-aimed at
	   each new sheet. */
	var previewObserver = null;
	var previewResizeBound = false;
	/* Closing the popup (or starting over) drops the sheet from the page;
	   stop observing it so the detached nodes are not kept alive. */
	PD.unwatchPreviewSheet = function unwatchPreviewSheet() {
		if (previewObserver) {
			previewObserver.disconnect();
			previewObserver = null;
		}
	};
	PD.watchPreviewSheet = function watchPreviewSheet(area) {
		var fit = function () {
			PD.fitPreviewSheet(area);
		};
		fit();
		if (typeof window.ResizeObserver === 'function') {
			if (previewObserver) {
				previewObserver.disconnect();
			}
			/* Next frame: resizing the page box inside the callback would
			   raise the «ResizeObserver loop» error. */
			previewObserver = new window.ResizeObserver(function () {
				if (typeof window.requestAnimationFrame === 'function') {
					window.requestAnimationFrame(fit);
				} else {
					fit();
				}
			});
			previewObserver.observe(area);
			var sheet = area.querySelector('.pd-preview-sheet');
			if (sheet) {
				previewObserver.observe(sheet);
			}
		} else if (!previewResizeBound) {
			previewResizeBound = true;
			window.addEventListener('resize', function () {
				var current = document.getElementById('pd-preview-area');
				if (current) {
					PD.fitPreviewSheet(current);
				}
			});
		}
	};

	PD.renderPrintCounter = function renderPrintCounter() {
		var el = document.getElementById('pd-print-counter');
		if (!el) {
			return;
		}
		/* Counter turned off at runtime, or no valid status yet: never
		   leave a stale number sitting in the DOM. */
		if (!PD.config.showPrintCounter || !PD.s.printStatus) {
			el.textContent = '';
			return;
		}
		if (PD.s.printStatus.unlimited) {
			el.textContent = PD.txt('printsUnlimited', 'Απεριόριστες εκτυπώσεις.');
			return;
		}
		if (typeof PD.s.printStatus.remaining === 'number') {
			el.textContent = PD.txt('printsRemaining', 'Απομένουν %d εκτυπώσεις αυτόν τον μήνα.').replace('%d', PD.s.printStatus.remaining);
			return;
		}
		/* Unexpected/invalid shape — clear rather than show a wrong count. */
		el.textContent = '';
	};
})();