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
			addHelp: 'Στο iPhone ανοίγει το Ημερολόγιο: πατήστε «Προσθήκη όλων». Στο Android ανοίξτε το αρχείο με την εφαρμογή ημερολογίου σας.',
			added: 'Έτοιμο. Αν δεν άνοιξε μόνο του, ανοίξτε το αρχείο «%s» από τις Λήψεις του κινητού.',
			google: 'Χρησιμοποιώ Google Calendar στο Android',
			googleHelp: 'Ένα κουμπί για κάθε υπενθύμιση. Πατήστε το καθένα και μετά «Αποθήκευση». Με αυτά τα κουμπιά η υπενθύμιση (φάρμακα και δόση) αποθηκεύεται στον λογαριασμό σας στη Google.',
			googleTooMany: 'Το πλάνο έχει πολλές ξεχωριστές υπενθυμίσεις: χρησιμοποιήστε το κουμπί «Προσθήκη στο ημερολόγιο».',
			badTitle: 'Το πλάνο δεν διαβάστηκε',
			bad: 'Ο σύνδεσμος είναι ελλιπής ή δεν διαβάζεται. Σκανάρετε ξανά το QR από το φύλλο του φαρμακείου.',
			until: 'έως',
			days: '%d ημέρες',
			oneDay: '1 ημέρα',
			doses: 'Φάρμακα',
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
			addHelp: 'On iPhone the Calendar opens: tap “Add All”. On Android open the file with your calendar app.',
			added: 'Done. If nothing opened, open the file “%s” from your phone’s Downloads.',
			google: 'I use Google Calendar on Android',
			googleHelp: 'One button per reminder. Tap each one, then “Save”. With these buttons the reminder (medicines and dose) is saved in your Google account.',
			googleTooMany: 'This plan has many separate reminders: use the “Add to calendar” button.',
			badTitle: 'The plan could not be read',
			bad: 'The link is incomplete or cannot be read. Scan the QR on the pharmacy sheet again.',
			until: 'until',
			days: '%d days',
			oneDay: '1 day',
			doses: 'Medicines',
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

	/**
	 * Runs of consecutive days with the same medicines in the same slot:
	 * one calendar event each (a daily repeat for runs longer than a day).
	 */
	function segments(plan) {
		var map = dayMap(plan);
		var out = [];
		SLOTS.forEach(function (slot) {
			var days = map[slot];
			var d = 0;
			while (d < plan.days) {
				if (!days[d].length) { d++; continue; }
				var key = days[d].join(',');
				var start = d;
				while (d < plan.days && days[d].join(',') === key) { d++; }
				out.push({ slot: slot, from: start, count: d - start, meds: days[start].slice() });
			}
		});
		return out;
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

	function medLine(plan, i, T) {
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
		var lines = seg.meds.map(function (i) { return '• ' + medLine(plan, i, T); });
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
		var lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//PlanDose//Calendar 1.28.2//EL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'].concat(VTIMEZONE);
		segments(plan).forEach(function (seg, i) {
			var time = times[seg.slot];
			var day = addDays(plan.start, seg.from);
			var title = eventTitle(plan, seg, neutral, T);
			lines.push(
				'BEGIN:VEVENT',
				'UID:plandose-' + uid + '-' + i + '@plandose.invalid',
				'DTSTAMP:' + dtstamp,
				/* The UID is stable, so a later import must carry a
				   higher SEQUENCE for calendar apps to take the new times. */
				'SEQUENCE:' + Math.floor((now || new Date()).getTime() / 1000),
				'DTSTART;TZID=' + TZ + ':' + stamp(day, time),
				'DURATION:PT15M'
			);
			if (seg.count > 1) {
				lines.push('RRULE:FREQ=DAILY;COUNT=' + seg.count);
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
		var day = addDays(plan.start, seg.from);
		var q = [
			['action', 'TEMPLATE'],
			['text', eventTitle(plan, seg, neutral, T)],
			['dates', stamp(day, time) + '/' + endStamp(day, time)],
			['ctz', TZ],
			['details', eventText(plan, seg, T)]
		];
		if (seg.count > 1) {
			q.push(['recur', 'RRULE:FREQ=DAILY;COUNT=' + seg.count]);
		}
		return 'https://calendar.google.com/calendar/render?' + q.map(function (kv) {
			return encodeURIComponent(kv[0]) + '=' + encodeURIComponent(kv[1]);
		}).join('&');
	}

	/* For the tests (Node). */
	window.PlanDoseCalendar = { decode: decode, segments: segments, dayMap: dayMap, buildIcs: buildIcs, googleLink: googleLink, DEFAULT_TIME: DEFAULT_TIME };

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

	function medWhen(plan, index, T) {
		var slots = [];
		var first = -1;
		var last = -1;
		plan.tracks.forEach(function (t) {
			if (t.med !== index) { return; }
			for (var d = 0; d < plan.days; d++) {
				if (has(t.bits, d)) {
					if (-1 === slots.indexOf(t.slot)) { slots.push(t.slot); }
					if (-1 === first || d < first) { first = d; }
					if (d > last) { last = d; }
				}
			}
		});
		slots.sort(function (a, b) { return SLOTS.indexOf(a) - SLOTS.indexOf(b); });
		var span = first === last
			? shortDate(addDays(plan.start, first))
			: shortDate(addDays(plan.start, first)) + ' – ' + shortDate(addDays(plan.start, last));
		return slots.map(function (s) { return T.slot[s]; }).join(' · ') + ' · ' + span;
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
			timeBox.appendChild(el('label', { 'for': 'pd-t-' + s }, [
				document.createTextNode(T.slot[s]),
				el('input', { type: 'time', id: 'pd-t-' + s, value: DEFAULT_TIME[s] })
			]));
		});
		/* Unticked by default — the medicine names show in the alert
		   (the patient can still tick it for a discreet alert). */
		var neutralBox = el('input', { type: 'checkbox', id: 'pd-neutral' });
		app.appendChild(el('section', { 'class': 'panel stack' }, [
			el('h2', { text: T.times }),
			timeBox,
			el('label', { 'class': 'check', 'for': 'pd-neutral' }, [neutralBox, el('span', { text: T.neutral })])
		]));

		var addBtn = el('button', { type: 'button', 'class': 'btn', text: T.add });
		var done = el('p', { 'class': 'done', role: 'status', text: T.added.replace('%s', T.file) });
		done.hidden = true;
		var googleList = el('div', { 'class': 'gcal-list' });
		var segs = segments(plan);
		app.appendChild(el('section', { 'class': 'stack' }, [
			addBtn,
			done,
			el('p', { 'class': 'muted', text: T.addHelp }),
			el('details', { 'class': 'panel' }, [
				el('summary', { text: T.google }),
				segs.length > MAX_GOOGLE ? el('p', { 'class': 'muted', text: T.googleTooMany }) : googleList,
				segs.length > MAX_GOOGLE ? null : el('p', { 'class': 'muted', text: T.googleHelp })
			])
		]));
		app.appendChild(el('footer', { text: T.disclaimer }));

		function times() {
			var out = {};
			SLOTS.forEach(function (s) {
				var input = document.getElementById('pd-t-' + s);
				out[s] = input && /^\d{2}:\d{2}$/.test(input.value) ? input.value : DEFAULT_TIME[s];
			});
			return out;
		}

		function drawGoogle() {
			if (segs.length > MAX_GOOGLE) { return; }
			var t = times();
			var neutral = neutralBox.checked;
			googleList.textContent = '';
			segs.forEach(function (seg) {
				var from = addDays(plan.start, seg.from);
				var to = addDays(plan.start, seg.from + seg.count - 1);
				var span = seg.count > 1 ? shortDate(from) + ' – ' + shortDate(to) : shortDate(from);
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
			var blob = new Blob([buildIcs(plan, times(), neutralBox.checked)], { type: 'text/calendar;charset=utf-8' });
			var url = URL.createObjectURL(blob);
			var a = el('a', { href: url, download: T.file });
			document.body.appendChild(a);
			a.click();
			a.remove();
			done.hidden = false;
			setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
		});
	}

	if (document.getElementById('app')) {
		render();
		window.addEventListener('hashchange', render);
	}
})();
