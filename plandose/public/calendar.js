/**
 * PlanDose — patient page of the «Υπενθυμίσεις στο κινητό» QR.
 *
 * Reads the plan from the address after «#» (never sent to the server),
 * checks it strictly, shows it, and builds a calendar file (.ics) on the
 * phone. It makes no network request (the page's CSP forbids them) and
 * stores nothing.
 *
 * The payload is the compact binary format described in
 * assets/js/calendar-qr.js (built there from PD.dayDoses(), the day cards
 * of the printed sheet); decode() below reads exactly that format.
 */
(function () {
	'use strict';

	var TZ = 'Europe/Athens';
	var SLOTS = ['m', 'n', 'a', 'e', 'x'];
	var DEFAULT_TIME = { m: '08:00', n: '14:00', a: '18:00', e: '21:00', x: '10:00' };
	var MAX_GOOGLE = 12;

	var TEXT = {
		el: {
			title: 'Το πλάνο μου',
			eyebrow: 'Πλάνο δοσολογίας',
			heading: 'Υπενθυμίσεις στο κινητό σας',
			startsOn: 'Έναρξη %s.',
			stayHere: 'Το πλάνο δεν στέλνεται στο φαρμακείο ούτε σε άλλον διακομιστή: διαβάζεται μόνο σε αυτό το κινητό. Ο σύνδεσμος μένει στο ιστορικό του browser.',
			from: 'Από: %s',
			meds: 'Τα φάρμακά σας',
			times: 'Τι ώρα θέλετε την υπενθύμιση;',
			neutral: 'Να μη φαίνονται τα ονόματα των φαρμάκων στην ειδοποίηση (φαίνονται μόνο μέσα στο γεγονός)',
			add: 'Προσθήκη στο ημερολόγιο',
			addHelp: 'Στο iPhone ανοίγει είτε το Ημερολόγιο (πατήστε «Προσθήκη όλων») είτε η λήψη του αρχείου: τότε ανοίξτε τις Λήψεις (εφαρμογή «Αρχεία»), πατήστε το αρχείο «%s» και μετά «Προσθήκη όλων». Στο Android ανοίξτε το αρχείο με την εφαρμογή ημερολογίου σας.',
			added: 'Έτοιμο. Αν δεν άνοιξε μόνο του το Ημερολόγιο, ανοίξτε το αρχείο «%s» από τις Λήψεις του κινητού (στο iPhone: εφαρμογή «Αρχεία» → Λήψεις).',
			google: 'Χρησιμοποιώ Google Calendar στο Android',
			googleHelp: 'Ένα κουμπί για κάθε υπενθύμιση. Πατήστε το καθένα και μετά «Αποθήκευση». Με αυτά τα κουμπιά η υπενθύμιση (φάρμακα και δόση) αποθηκεύεται στον λογαριασμό σας στη Google.',
			googleTooMany: 'Το πλάνο έχει πολλές ξεχωριστές υπενθυμίσεις: χρησιμοποιήστε το κουμπί «Προσθήκη στο ημερολόγιο».',
			badTitle: 'Το πλάνο δεν διαβάστηκε',
			bad: 'Ο σύνδεσμος είναι ελλιπής ή δεν διαβάζεται. Σκανάρετε ξανά το QR από το φύλλο του φαρμακείου.',
			until: 'έως',
			days: '%d ημέρες',
			oneDay: '1 ημέρα',
			doses: 'Φάρμακα',
			everyDay: 'κάθε ημέρα',
			weekly: '1 φορά την εβδομάδα',
			everyN: 'κάθε %d ημέρες',
			doseDays: '%d ημέρες με δόση',
			hint8: 'Αν ο γιατρός σας είπε «κάθε 8 ώρες», βάλτε ώρες με ίση απόσταση, π.χ. Πρωί 07:00, Μεσημέρι 15:00, Βράδυ 23:00.',
			hint12: 'Αν ο γιατρός σας είπε «κάθε 12 ώρες», βάλτε ώρες με ίση απόσταση, π.χ. Πρωί 08:00, Βράδυ 20:00.',
			disclaimer: 'Το πλάνο είναι βοήθημα υπενθύμισης και δεν αντικαθιστά τις οδηγίες του γιατρού ή του φαρμακοποιού. Ελέγξτε ότι τα φάρμακα και οι δόσεις είναι ίδια με το φύλλο του φαρμακείου. Αν αλλάξει η δοσολογία, ζητήστε νέο πλάνο και σβήστε τις παλιές υπενθυμίσεις.',
			slot: { m: 'Πρωί', n: 'Μεσημέρι', a: 'Απόγευμα', e: 'Βράδυ', x: 'Μέσα στην ημέρα' },
			file: 'plano-farmakon.ics'
		},
		en: {
			title: 'My plan',
			eyebrow: 'Dosage plan',
			heading: 'Reminders on your phone',
			startsOn: 'Starts %s.',
			stayHere: 'The plan is not sent to the pharmacy or any other server: it is read on this phone only. The link stays in the browser history.',
			from: 'From: %s',
			meds: 'Your medicines',
			times: 'What time should the reminder come?',
			neutral: 'Hide medicine names in the alert (they show only inside the event)',
			add: 'Add to calendar',
			addHelp: 'On iPhone either Calendar opens (tap “Add All”) or the file is downloaded: then open Downloads (Files app), tap the file “%s”, then “Add All”. On Android open the file with your calendar app.',
			added: 'Done. If Calendar did not open by itself, open the file “%s” from your phone’s Downloads (on iPhone: Files app → Downloads).',
			google: 'I use Google Calendar on Android',
			googleHelp: 'One button per reminder. Tap each one, then “Save”. With these buttons the reminder (medicines and dose) is saved in your Google account.',
			googleTooMany: 'This plan has many separate reminders: use the “Add to calendar” button.',
			badTitle: 'The plan could not be read',
			bad: 'The link is incomplete or cannot be read. Scan the QR on the pharmacy sheet again.',
			until: 'until',
			days: '%d days',
			oneDay: '1 day',
			doses: 'Medicines',
			everyDay: 'every day',
			weekly: 'once a week',
			everyN: 'every %d days',
			doseDays: '%d dose days',
			hint8: 'If your doctor said “every 8 hours”, choose evenly spaced times, e.g. Morning 07:00, Midday 15:00, Night 23:00.',
			hint12: 'If your doctor said “every 12 hours”, choose evenly spaced times, e.g. Morning 08:00, Night 20:00.',
			disclaimer: 'This plan is a reminder aid and does not replace the instructions of your doctor or pharmacist. Check that the medicines and doses match the pharmacy sheet. If the dosage changes, ask for a new plan and delete the old reminders.',
			slot: { m: 'Morning', n: 'Midday', a: 'Afternoon', e: 'Night', x: 'During the day' },
			file: 'medicine-plan.ics'
		}
	};

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	/* ---------------------------------------------------------------- */
	/* Decode + validate                                                  */
	/* ---------------------------------------------------------------- */

	function b64urlBytes(s) {
		if (!/^[A-Za-z0-9_-]*$/.test(s)) {
			throw new Error('b64');
		}
		s = s.replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) { s += '='; }
		var bin = atob(s);
		var out = [];
		for (var i = 0; i < bin.length; i++) { out.push(bin.charCodeAt(i)); }
		return out;
	}

	/**
	 * Unicode format / invisible characters (category Cf and the
	 * C1 controls): bidi embeddings, overrides and isolates, zero-width
	 * marks, the word joiner block, the BOM, tag characters.
	 */
	function isFormatChar(c) {
		return (c >= 0x7f && c <= 0x9f) || 0xad === c || 0x61c === c || 0x180e === c ||
			(c >= 0x200b && c <= 0x200f) || (c >= 0x202a && c <= 0x202e) ||
			(c >= 0x2060 && c <= 0x206f) || 0xfeff === c || (c >= 0xfff9 && c <= 0xfffb) ||
			(c >= 0xe0000 && c <= 0xe007f);
	}

	/** «Greek byte» code → text (see assets/js/calendar-qr.js). */
	function readText(bytes, from, len) {
		var out = '';
		var i = from;
		var end = from + len;
		while (i < end) {
			var b = bytes[i++];
			if (b >= 0x20 && b < 0x7f) {
				out += String.fromCharCode(b);
			} else if (b >= 0x80) {
				out += String.fromCharCode(0x370 + (b - 0x80));
			} else if (0x7f === b && i + 3 <= end) {
				var c = (bytes[i] << 16) | (bytes[i + 1] << 8) | bytes[i + 2];
				i += 3;
				/* Invisible format characters (bidi overrides such
				   as U+202E, zero-width marks) are refused too: they could
				   make a dose read differently from what it is. */
				if (c < 0x20 || c > 0x10ffff || (c >= 0xd800 && c <= 0xdfff) || isFormatChar(c)) {
					throw new Error('char');
				}
				out += String.fromCodePoint(c);
			} else {
				throw new Error('char');
			}
		}
		return out;
	}

	function validDate(iso) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
		if (!m) { return false; }
		var d = new Date(+m[1], +m[2] - 1, +m[3]);
		return d.getFullYear() === +m[1] && d.getMonth() === +m[2] - 1 && d.getDate() === +m[3];
	}

	function isoFromDayNumber(n) {
		var d = new Date(Date.UTC(2000, 0, 1) + n * 86400000);
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
	}

	/**
	 * The plan from a «#…» fragment, or null. Every length, index and
	 * character is checked, and the bytes must end exactly where the plan
	 * does: nothing unexpected is ever used.
	 */
	function decode(hash) {
		var raw = String(hash || '').replace(/^#/, '');
		if (!raw || raw.length > 4000) { return null; }
		try {
			var b = b64urlBytes(raw);
			var pos = 0;
			var need = function (k) {
				if (pos + k > b.length) { throw new Error('short'); }
			};
			var u8 = function () { need(1); return b[pos++]; };
			var u16 = function () { need(2); pos += 2; return (b[pos - 2] << 8) | b[pos - 1]; };
			var str = function (max, allowEmpty) {
				var len = u8();
				need(len);
				var s = readText(b, pos, len);
				pos += len;
				/* By code points, as the sheet clips them (clip() in
				   calendar-qr.js): an emoji is one character, not two. */
				if (Array.from(s).length > max || (!allowEmpty && '' === s.trim())) { throw new Error('str'); }
				return s;
			};
			if (1 !== u8()) { return null; }
			var flags = u8();
			if (flags > 1) { return null; }
			var start = isoFromDayNumber(u16());
			var days = u16();
			if (!validDate(start) || days < 1 || days > 400) { return null; }
			var pharmacy = str(80, true);
			var medCount = u8();
			if (medCount < 1 || medCount > 40) { return null; }
			var meds = [];
			for (var i = 0; i < medCount; i++) {
				meds.push([str(100, false), str(60, true), str(100, true)]);
			}
			var trackCount = u8();
			if (trackCount < 1 || trackCount > 200) { return null; }
			var width = Math.ceil(days / 8);
			var tracks = [];
			for (var t = 0; t < trackCount; t++) {
				var med = u8();
				var slot = u8();
				if (med >= medCount || slot >= SLOTS.length) { return null; }
				need(width);
				tracks.push({ med: med, slot: SLOTS[slot], bits: b.slice(pos, pos + width) });
				pos += width;
			}
			if (pos !== b.length) { return null; }
			return { lang: 1 === flags ? 'en' : 'el', start: start, days: days, pharmacy: pharmacy, meds: meds, tracks: tracks };
		} catch (e) {
			return null;
		}
	}

	/* ---------------------------------------------------------------- */
	/* Schedule → events                                                  */
	/* ---------------------------------------------------------------- */

	/*
	 * Only DTSTART + RRULE (COUNT), never RDATE: iPhone's Calendar
	 * (EventKit) has no list of extra dates, and parsers disagree on
	 * whether an RDATE-only event keeps its DTSTART (ical.js drops it) —
	 * a missed dose either way. Days no rule fits become a few more
	 * events; segments() never makes more than one per run of days.
	 */
	var WEEKDAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

	function has(bits, d) {
		return !!(bits[d >> 3] & (1 << (d & 7)));
	}

	/** Per slot, per day: the sorted medicine indexes with a dose. */
	function dayMap(plan) {
		var map = {};
		SLOTS.forEach(function (slot) {
			map[slot] = [];
			for (var d = 0; d < plan.days; d++) { map[slot].push([]); }
		});
		plan.tracks.forEach(function (t) {
			for (var d = 0; d < plan.days; d++) {
				if (has(t.bits, d) && -1 === map[t.slot][d].indexOf(t.med)) {
					map[t.slot][d].push(t.med);
				}
			}
		});
		SLOTS.forEach(function (slot) {
			map[slot].forEach(function (list) { list.sort(function (a, b) { return a - b; }); });
		});
		return map;
	}

	/*
	 * Day d of the plan as a calendar date {y, m (1–12), d, wd (0 = Sunday),
	 * len (days in its month)} — counted in UTC, so a DST change can never
	 * move a dose to another day.
	 */
	function civil(plan, d) {
		var p = plan.start.split('-').map(Number);
		var t = new Date(Date.UTC(p[0], p[1] - 1, p[2] + d));
		return {
			y: t.getUTCFullYear(), m: t.getUTCMonth() + 1, d: t.getUTCDate(), wd: t.getUTCDay(),
			len: new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate()
		};
	}

	/* The plan day of a date (a day 0 = the last day of the month before). */
	function dayOf(plan, y, m, d) {
		var p = plan.start.split('-').map(Number);
		return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(p[0], p[1] - 1, p[2])) / 86400000);
	}

	/*
	 * The longest run of days[i…] that ONE recurrence rule gives exactly,
	 * as {count, rule}. Each candidate walks the dates its rule would give
	 * (RFC 5545, COUNT from DTSTART = days[i]) and stops at the first date
	 * that is not a dose or at the first dose the rule would not give — so
	 * a rule never adds or drops a dose, whatever the rhythm was on the
	 * sheet.
	 */
	function longestRule(plan, days, i) {
		var best = { count: 1, rule: null };
		var take = function (count, rule) {
			if (count > best.count) { best = { count: count, rule: rule }; }
		};
		var n = days.length;
		/* Every day / every N days / once a week: equal gaps. */
		if (i + 1 < n) {
			var gap = days[i + 1] - days[i];
			var j = i + 1;
			while (j + 1 < n && days[j + 1] - days[j] === gap) { j++; }
			take(j - i + 1, 7 === gap ? { freq: 'WEEKLY', count: j - i + 1 } : { freq: 'DAILY', interval: gap, count: j - i + 1 });
		}
		/* Some weekdays every week: the weekdays of the first seven days,
		   then every day on must be a dose exactly when it is one of them. */
		var wds = [];
		for (var k = i; k < n && days[k] < days[i] + 7; k++) {
			wds.push(civil(plan, days[k]).wd);
		}
		if (wds.length > 1 && wds.length < 7) {
			var c = i;
			for (var d = days[i]; c < n; d++) {
				var want = -1 !== wds.indexOf(civil(plan, d).wd);
				if (want !== (days[c] === d)) { break; }
				if (want) { c++; }
			}
			/* Monday first, as the patient reads a week. */
			wds.sort(function (a, b) { return (a + 6) % 7 - (b + 6) % 7; });
			take(c - i, { freq: 'WEEKLY', byDay: wds.map(function (w) { return WEEKDAYS[w]; }), count: c - i });
		}
		/* The same day of every month, or the last day of every month:
		   each next dose must be exactly the next month's date. A 29th,
		   30th or 31st stops before the first month without that day:
		   RFC 5545 skips such a month (and some apps take its last day
		   instead), while the sheet's «31» falls on the 30th or the 28th
		   there. So the rule never reaches a month where they differ. */
		var first = civil(plan, days[i]);
		[first.d, first.d === first.len ? -1 : 0].forEach(function (md) {
			if (!md) { return; }
			var y = first.y;
			var m = first.m;
			var c = i + 1;
			while (c < n) {
				m++;
				if (m > 12) { m = 1; y++; }
				if (md > new Date(Date.UTC(y, m, 0)).getUTCDate()) { break; }
				if (days[c] !== (md > 0 ? dayOf(plan, y, m, md) : dayOf(plan, y, m + 1, 0))) { break; }
				c++;
			}
			take(c - i, { freq: 'MONTHLY', monthDay: md, count: c - i });
		});
		return best;
	}

	/*
	 * The days of a group cut into as few rules as the walk above finds:
	 * usually one, two with a dose carried over to an extra day.
	 */
	function groupEvents(plan, slot, meds, days, limit) {
		var out = [];
		var i = 0;
		/* At the limit the runs win anyway (segments()): no need to go on
		   (a hand-made link of 200 scattered tracks stays quick). */
		while (i < days.length && out.length < limit) {
			var r = longestRule(plan, days, i);
			out.push({ slot: slot, meds: meds, days: days.slice(i, i + r.count), rule: r.count > 1 ? r.rule : null, key: meds.join('.') + '-' + days[i] });
			i += r.count;
		}
		return out;
	}

	/**
	 * The calendar events: [{slot, meds, days (plan day numbers, sorted),
	 * rule, key}]. Every dose of the plan is in exactly one event.
	 *
	 * Per part of the day, the fewer of two ways:
	 * - runs: consecutive days with the same medicines, one daily event
	 *   each — one alarm names every medicine of that time;
	 * - rhythms: each medicine (medicines with exactly the same days
	 *   share one) with its own recurring event. A daily medicine and an
	 *   every-other-day one in the same slot change the set every day: as
	 *   runs, 400 days made 400 events per slot (a 781 KB file for three
	 *   slots, which a patient can hardly delete); as rhythms, two.
	 * Ties go to the runs: one alarm for all, as until now.
	 */
	function segments(plan) {
		var map = dayMap(plan);
		var out = [];
		SLOTS.forEach(function (slot) {
			var byDay = map[slot];
			var runs = [];
			var d = 0;
			while (d < plan.days) {
				if (!byDay[d].length) { d++; continue; }
				var key = byDay[d].join(',');
				var start = d;
				var run = [];
				while (d < plan.days && byDay[d].join(',') === key) { run.push(d); d++; }
				runs.push({
					slot: slot, meds: byDay[start].slice(), days: run,
					rule: run.length > 1 ? { freq: 'DAILY', interval: 1, count: run.length } : null,
					key: 'r' + start
				});
			}
			/* The days of each medicine; medicines with the very same
			   days make one group (one alarm for them). */
			var daysOf = {};
			byDay.forEach(function (list, day) {
				list.forEach(function (m) { (daysOf[m] = daysOf[m] || []).push(day); });
			});
			var groups = {};
			var order = [];
			Object.keys(daysOf).map(Number).sort(function (a, b) { return a - b; }).forEach(function (m) {
				var k = daysOf[m].join(',');
				if (!groups[k]) {
					groups[k] = { meds: [], days: daysOf[m] };
					order.push(k);
				}
				groups[k].meds.push(m);
			});
			/* Stops as soon as the rhythms are not fewer than the runs. */
			var rhythms = [];
			for (var g = 0; g < order.length && rhythms.length < runs.length; g++) {
				rhythms = rhythms.concat(groupEvents(plan, slot, groups[order[g]].meds, groups[order[g]].days, runs.length - rhythms.length));
			}
			var fewer = g === order.length && rhythms.length < runs.length;
			out = out.concat(fewer ? rhythms : runs);
		});
		return out;
	}

	/*
	 * The RRULE value of an event ('' = none). COUNT, never UNTIL: UNTIL
	 * would have to be converted to UTC, and COUNT cannot be off by a day.
	 */
	function rrule(ev) {
		var r = ev.rule;
		if (!r) { return ''; }
		if ('MONTHLY' === r.freq) {
			return 'FREQ=MONTHLY;BYMONTHDAY=' + r.monthDay + ';COUNT=' + r.count;
		}
		if ('WEEKLY' === r.freq) {
			return 'FREQ=WEEKLY' + (r.byDay ? ';BYDAY=' + r.byDay.join(',') : '') + ';COUNT=' + r.count;
		}
		return 'FREQ=DAILY' + (r.interval > 1 ? ';INTERVAL=' + r.interval : '') + ';COUNT=' + r.count;
	}

	/* ---------------------------------------------------------------- */
	/* Dates                                                               */
	/* ---------------------------------------------------------------- */

	function addDays(iso, n) {
		var p = iso.split('-').map(Number);
		var d = new Date(p[0], p[1] - 1, p[2] + n);
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function shortDate(iso) {
		var p = iso.split('-');
		return p[2] + '/' + p[1];
	}

	function stamp(iso, time) {
		return iso.replace(/-/g, '') + 'T' + time.replace(':', '') + '00';
	}

	/* The end of a 15-minute event, on the next day after 23:45. */
	function endStamp(iso, time) {
		var p = time.split(':').map(Number);
		var m = p[0] * 60 + p[1] + 15;
		var day = m >= 24 * 60 ? addDays(iso, 1) : iso;
		m %= 24 * 60;
		return stamp(day, pad(Math.floor(m / 60)) + ':' + pad(m % 60));
	}

	/* ---------------------------------------------------------------- */
	/* Event text                                                          */
	/* ---------------------------------------------------------------- */

	function medLine(plan, i) {
		var m = plan.meds[i];
		return m[0] + (m[1] ? ': ' + m[1] : '') + (m[2] ? ' (' + m[2] + ')' : '');
	}

	function eventTitle(plan, seg, neutral, T) {
		if (neutral) {
			return '💊 ' + T.doses + ('x' === seg.slot ? '' : ' — ' + T.slot[seg.slot]);
		}
		return '💊 ' + seg.meds.map(function (i) { return plan.meds[i][0]; }).join(', ');
	}

	function eventText(plan, seg, T) {
		var lines = seg.meds.map(function (i) { return '• ' + medLine(plan, i); });
		if (plan.pharmacy) {
			lines.push('', T.from.replace('%s', plan.pharmacy));
		}
		lines.push('', T.disclaimer);
		return lines.join('\n');
	}

	/* ---------------------------------------------------------------- */
	/* .ics (RFC 5545)                                                     */
	/* ---------------------------------------------------------------- */

	function icsText(s) {
		return String(s).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
	}

	/* Lines of at most 75 octets, never splitting a character. */
	function fold(line) {
		var out = [];
		var cur = '';
		var bytes = 0;
		Array.from(line).forEach(function (ch) {
			var b = unescape(encodeURIComponent(ch)).length;
			if (bytes + b > (out.length ? 74 : 75)) {
				out.push(cur);
				cur = '';
				bytes = 0;
			}
			cur += ch;
			bytes += b;
		});
		out.push(cur);
		return out.join('\r\n ');
	}

	var VTIMEZONE = [
		'BEGIN:VTIMEZONE', 'TZID:' + TZ,
		'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0300', 'TZNAME:EEST',
		'DTSTART:19700329T030000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
		'BEGIN:STANDARD', 'TZOFFSETFROM:+0300', 'TZOFFSETTO:+0200', 'TZNAME:EET',
		'DTSTART:19701025T040000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
		'END:VTIMEZONE'
	];

	/**
	 * The events' UID comes from the plan itself (start, days,
	 * medicines, doses and schedule — not the chosen times or the neutral
	 * title), so adding the same QR twice UPDATES the reminders in the
	 * calendar app instead of creating a second set (a double dose).
	 * Two FNV-1a 32-bit hashes of the plan's
	 * canonical text with different seeds: stable, no network, no clock.
	 */
	function uidPart(plan) {
		var text = JSON.stringify([plan.start, plan.days, plan.pharmacy, plan.meds, plan.tracks.map(function (t) {
			return [t.med, t.slot, Array.prototype.slice.call(t.bits)];
		})]);
		function fnv(seed) {
			var h = seed >>> 0;
			for (var i = 0; i < text.length; i++) {
				h ^= text.charCodeAt(i);
				h = Math.imul(h, 0x01000193) >>> 0;
			}
			return h;
		}
		return fnv(0x811c9dc5).toString(36) + fnv(0x2f7a1b3c).toString(36);
	}

	function buildIcs(plan, times, neutral, now) {
		var T = TEXT[plan.lang];
		var dtstamp = (now || new Date()).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
		var uid = uidPart(plan);
		var lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//PlanDose//Calendar//EL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'].concat(VTIMEZONE);
		segments(plan).forEach(function (seg) {
			var time = times[seg.slot];
			var day = addDays(plan.start, seg.days[0]);
			var title = eventTitle(plan, seg, neutral, T);
			lines.push(
				'BEGIN:VEVENT',
				/* Per part of the day and medicines (+ first day), not a
				   running number: the same QR added again updates each
				   event in place, whatever order the events come in. */
				'UID:plandose-' + uid + '-' + seg.slot + '-' + seg.key + '@plandose.invalid',
				'DTSTAMP:' + dtstamp,
				/* The UID is stable, so a later import must carry a
				   higher SEQUENCE for calendar apps to take the new times. */
				'SEQUENCE:' + Math.floor((now || new Date()).getTime() / 1000),
				'DTSTART;TZID=' + TZ + ':' + stamp(day, time),
				'DURATION:PT15M'
			);
			if (seg.rule) {
				lines.push('RRULE:' + rrule(seg));
			}
			lines.push(
				'SUMMARY:' + icsText(title),
				'DESCRIPTION:' + icsText(eventText(plan, seg, T)),
				'TRANSP:TRANSPARENT',
				'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' + icsText(title), 'TRIGGER:PT0M', 'END:VALARM',
				'END:VEVENT'
			);
		});
		lines.push('END:VCALENDAR');
		return lines.map(fold).join('\r\n') + '\r\n';
	}

	function googleLink(plan, seg, times, neutral) {
		var T = TEXT[plan.lang];
		var time = times[seg.slot];
		var day = addDays(plan.start, seg.days[0]);
		var q = [
			['action', 'TEMPLATE'],
			['text', eventTitle(plan, seg, neutral, T)],
			['dates', stamp(day, time) + '/' + endStamp(day, time)],
			['ctz', TZ],
			['details', eventText(plan, seg, T)]
		];
		/* Google reads the same RRULE, in the event's own zone (ctz). */
		if (seg.rule) {
			q.push(['recur', 'RRULE:' + rrule(seg)]);
		}
		return 'https://calendar.google.com/calendar/render?' + q.map(function (kv) {
			return encodeURIComponent(kv[0]) + '=' + encodeURIComponent(kv[1]);
		}).join('&');
	}

	/* One Google button per event: offered only for a few events. */
	function googleOk(segs) {
		return segs.length <= MAX_GOOGLE;
	}

	/* For the tests (Node). */
	window.PlanDoseCalendar = { decode: decode, segments: segments, rrule: rrule, dayMap: dayMap, buildIcs: buildIcs, googleLink: googleLink, googleOk: googleOk, DEFAULT_TIME: DEFAULT_TIME, medWhen: medWhen, spacingHints: spacingHints, TEXT: TEXT };

	/* ---------------------------------------------------------------- */
	/* Page (DOM built with textContent: nothing from the link is HTML)    */
	/* ---------------------------------------------------------------- */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if ('text' === k) {
				node.textContent = attrs[k];
			} else {
				node.setAttribute(k, attrs[k]);
			}
		});
		(children || []).forEach(function (c) {
			if (c) { node.appendChild(c); }
		});
		return node;
	}

	/* The dayparts and the days of one medicine, sorted. */
	function medDays(plan, index) {
		var slots = [];
		var days = [];
		plan.tracks.forEach(function (t) {
			if (t.med !== index) { return; }
			for (var d = 0; d < plan.days; d++) {
				if (has(t.bits, d)) {
					if (-1 === slots.indexOf(t.slot)) { slots.push(t.slot); }
					if (-1 === days.indexOf(d)) { days.push(d); }
				}
			}
		});
		slots.sort(function (a, b) { return SLOTS.indexOf(a) - SLOTS.indexOf(b); });
		days.sort(function (a, b) { return a - b; });
		return { slots: slots, days: days };
	}

	/*
	 * When a medicine is taken. The first–last dates alone read as «every
	 * day»: a weekly methotrexate on 01/10, 08/10, 15/10, 22/10 must never
	 * look like a daily one, so the rhythm is always said — every day, once
	 * a week, every N days with the number of dose days, or the dates
	 * themselves when they follow no rhythm.
	 *
	 * Each part of the day is told with its own days: a medicine taken
	 * every morning but only on three evenings read «Πρωί · Βράδυ · κάθε
	 * ημέρα» when the days of all its slots were merged. Slots with the
	 * very same days are still said together («Πρωί · Βράδυ · κάθε ημέρα»).
	 */
	function medWhen(plan, index, T) {
		var bySlot = {};
		plan.tracks.forEach(function (t) {
			if (t.med !== index) { return; }
			for (var d = 0; d < plan.days; d++) {
				if (has(t.bits, d)) {
					bySlot[t.slot] = bySlot[t.slot] || [];
					if (-1 === bySlot[t.slot].indexOf(d)) { bySlot[t.slot].push(d); }
				}
			}
		});
		var groups = [];
		SLOTS.forEach(function (s) {
			if (!bySlot[s]) { return; }
			var days = bySlot[s].sort(function (a, b) { return a - b; });
			var key = days.join(',');
			var same = groups.filter(function (g) { return g.key === key; })[0];
			if (same) {
				same.slots.push(s);
			} else {
				groups.push({ key: key, slots: [s], days: days });
			}
		});
		return groups.map(function (g) {
			return g.slots.map(function (s) { return T.slot[s]; }).concat(rhythmText(plan, g.days, T)).join(' · ');
		}).join('; ');
	}

	/* The rhythm of some days, as the parts of a «·» line. */
	function rhythmText(plan, days, T) {
		if (!days.length) {
			return [];
		}
		var date = function (d) { return shortDate(addDays(plan.start, d)); };
		var first = days[0];
		var last = days[days.length - 1];
		if (1 === days.length) {
			return [date(first)];
		}
		var gap = days[1] - days[0];
		var even = days.every(function (d, k) { return 0 === k || d - days[k - 1] === gap; });
		var span = date(first) + ' – ' + date(last);
		if (even && 1 === gap) {
			return [T.everyDay, span];
		}
		var count = T.doseDays.replace('%d', days.length);
		if (even) {
			return [7 === gap ? T.weekly : T.everyN.replace('%d', gap), count, span];
		}
		var shown = days.length <= 6
			? days.map(date).join(', ')
			: days.slice(0, 3).map(date).join(', ') + ' … ' + date(last);
		return [count + ': ' + shown];
	}

	/*
	 * The plan keeps dayparts, not clock hours: «3 φορές την ημέρα» and
	 * «κάθε 8 ώρες» both come here as Morning / Midday / Night, and the
	 * default times (08:00, 14:00, 21:00) are not evenly spaced. When a
	 * medicine is taken at exactly those dayparts (or Morning / Night), the
	 * patient is told how to space them if the doctor said «every … hours».
	 */
	function spacingHints(plan, T) {
		var out = [];
		plan.meds.forEach(function (m, i) {
			var key = medDays(plan, i).slots.join('');
			var hint = 'mne' === key ? T.hint8 : ('me' === key ? T.hint12 : '');
			if (hint && -1 === out.indexOf(hint)) {
				out.push(hint);
			}
		});
		return out;
	}

	function render() {
		var app = document.getElementById('app');
		if (!app) { return; }
		var plan = decode(location.hash);
		app.textContent = '';
		if (!plan) {
			document.documentElement.lang = /^en/i.test(navigator.language || '') ? 'en' : 'el';
			var B = TEXT[document.documentElement.lang];
			document.title = B.badTitle;
			app.appendChild(el('h1', { text: B.badTitle }));
			app.appendChild(el('p', { 'class': 'notice', text: B.bad }));
			return;
		}
		var T = TEXT[plan.lang];
		document.documentElement.lang = plan.lang;
		document.title = T.title;

		var used = SLOTS.filter(function (s) {
			return plan.tracks.some(function (t) { return t.slot === s; });
		});

		var intro = [T.startsOn.replace('%s', shortDate(plan.start)), T.stayHere].join(' ');
		app.appendChild(el('header', { 'class': 'stack' }, [
			el('span', { 'class': 'eyebrow', text: T.eyebrow }),
			el('h1', { text: T.heading }),
			el('p', { 'class': 'muted', text: intro }),
			plan.pharmacy ? el('p', { 'class': 'muted', text: T.from.replace('%s', plan.pharmacy) }) : null
		]));

		var list = el('ul', { 'class': 'meds' });
		plan.meds.forEach(function (m, i) {
			list.appendChild(el('li', {}, [
				el('span', { 'class': 'med-name', text: m[0] }),
				m[1] ? el('span', { 'class': 'med-dose', text: m[1] }) : null,
				el('span', { 'class': 'med-when', text: medWhen(plan, i, T) }),
				m[2] ? el('span', { 'class': 'med-notes', text: m[2] }) : null
			]));
		});
		app.appendChild(el('section', { 'class': 'panel' }, [el('h2', { text: T.meds }), list]));

		var timeBox = el('div', { 'class': 'times' });
		used.forEach(function (s) {
			var input = el('input', { type: 'time', id: 'pd-t-' + s, value: DEFAULT_TIME[s] });
			/* Leaving a cleared field puts the default back in it. */
			input.addEventListener('blur', function () {
				times(true);
				drawGoogle();
			});
			timeBox.appendChild(el('label', { 'for': 'pd-t-' + s }, [
				document.createTextNode(T.slot[s]),
				input
			]));
		});
		/* Unticked by default — the medicine names show in the alert
		   (the patient can still tick it for a discreet alert). */
		var neutralBox = el('input', { type: 'checkbox', id: 'pd-neutral' });
		app.appendChild(el('section', { 'class': 'panel stack' }, [
			el('h2', { text: T.times }),
			timeBox
		].concat(spacingHints(plan, T).map(function (h) {
			return el('p', { 'class': 'muted hint', text: h });
		})).concat([
			el('label', { 'class': 'check', 'for': 'pd-neutral' }, [neutralBox, el('span', { text: T.neutral })])
		])));

		var addBtn = el('button', { type: 'button', 'class': 'btn', text: T.add });
		/* The page's only live region: present (empty) from the start
		   so that filling it is announced — a re-render of the whole
		   page is not. */
		var done = el('p', { 'class': 'done', role: 'status' });
		var googleList = el('div', { 'class': 'gcal-list' });
		var segs = segments(plan);
		var google = googleOk(segs);
		app.appendChild(el('section', { 'class': 'stack' }, [
			addBtn,
			done,
			el('p', { 'class': 'muted', text: T.addHelp.replace('%s', T.file) }),
			el('details', { 'class': 'panel' }, [
				el('summary', { text: T.google }),
				google ? googleList : el('p', { 'class': 'muted', text: T.googleTooMany }),
				google ? el('p', { 'class': 'muted', text: T.googleHelp }) : null
			])
		]));
		app.appendChild(el('footer', { text: T.disclaimer }));

		/* A cleared (or unreadable) time falls back to the default,
		   which is written back into the field (writeBack: when the
		   patient leaves the field or adds to the calendar — not while
		   typing, when a half-edited time is momentarily empty): the time
		   used is always the time shown. */
		function times(writeBack) {
			var out = {};
			SLOTS.forEach(function (s) {
				var input = document.getElementById('pd-t-' + s);
				var ok = input && /^([01]\d|2[0-3]):[0-5]\d$/.test(input.value);
				out[s] = ok ? input.value : DEFAULT_TIME[s];
				if (writeBack && input && !ok) {
					input.value = DEFAULT_TIME[s];
				}
			});
			return out;
		}

		function drawGoogle() {
			if (!google) { return; }
			var t = times();
			var neutral = neutralBox.checked;
			googleList.textContent = '';
			segs.forEach(function (seg) {
				/* The rhythm is said too: «01/10 – 30/10» alone reads as
				   every day for an every-other-day reminder. */
				var span = rhythmText(plan, seg.days, T).join(' · ');
				googleList.appendChild(el('a', {
					'class': 'btn secondary',
					href: googleLink(plan, seg, t, neutral),
					target: '_blank',
					rel: 'noopener noreferrer'
				}, [el('span', {}, [
					document.createTextNode(T.slot[seg.slot] + ' ' + t[seg.slot]),
					el('span', { 'class': 'when', text: span + ' · ' + seg.meds.map(function (i) { return plan.meds[i][0]; }).join(', ') })
				])]));
			});
		}
		drawGoogle();
		app.onchange = drawGoogle;

		addBtn.addEventListener('click', function () {
			var blob = new Blob([buildIcs(plan, times(true), neutralBox.checked)], { type: 'text/calendar;charset=utf-8' });
			var url = URL.createObjectURL(blob);
			var a = el('a', { href: url, download: T.file });
			document.body.appendChild(a);
			a.click();
			a.remove();
			done.textContent = T.added.replace('%s', T.file);
			setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
		});
	}

	if (document.getElementById('app')) {
		render();
		window.addEventListener('hashchange', render);
	}
})();
