/* 1.31.0: the patient page's .ics — one recurring event per rhythm.

   A daily medicine and an every-other-day one in the same part of the day
   changed the set of medicines every day, and the page made one event per
   day: a 400-day plan of two medicines (it fits the QR) gave 1200 events,
   a 781 KB file that a patient can hardly import or delete. Now each
   medicine (or medicines with exactly the same days) has its own RRULE.

   The rule that matters is unchanged: the calendar has EXACTLY the doses
   of the plan. The .ics is expanded here by a small RFC 5545 expander (the
   subset the page writes: DAILY/INTERVAL, WEEKLY/BYDAY, MONTHLY/BYMONTHDAY,
   all with COUNT; never RDATE) and compared, dose by dose, with the plan's
   day bitmaps — for fixed cases and for hundreds of random plans. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const env = require('./lib/env.js');
const { load, closeAll, isoAhead } = require('./harness');

const PUBLIC = path.join(env.pluginDir(), 'public');
const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html';
const SLOTS = ['m', 'n', 'a', 'e', 'x'];
const NOW = new Date('2026-09-27T10:00:00Z');

test.after(closeAll);

function patientPage(hash) {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8')
		.replace(/<script src="calendar\.js[^"]*"><\/script>/, '')
		.replace(/<link[^>]*calendar\.css[^>]*>/, '');
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: CAL_URL + (hash ? '#' + hash : '') });
	dom.window.eval(fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8'));
	return dom;
}

const page = patientPage('');
const C = page.window.PlanDoseCalendar;
/* Arrays from the page's window are of another realm: compare as JSON. */
const plain = (x) => JSON.parse(JSON.stringify(x));
test.after(() => page.window.close());

/* ---- plans ----------------------------------------------------------------- */

/* {start, days, meds: n, tracks: [[med, slot, [day…]]]} → a decoded plan. */
function plan(start, days, medCount, tracks) {
	const width = Math.ceil(days / 8);
	return {
		lang: 'el', start, days, pharmacy: 'Φαρμακείο',
		meds: Array.from({ length: medCount }, (_, i) => ['M' + i, '1 δισκίο', '']),
		tracks: tracks.map(([med, slot, list]) => {
			const bits = new Array(width).fill(0);
			list.forEach((d) => { bits[d >> 3] |= 1 << (d & 7); });
			return { med, slot, bits };
		})
	};
}

const range = (from, to, step) => {
	const out = [];
	for (let d = from; d <= to; d += (step || 1)) { out.push(d); }
	return out;
};

/* Civil dates, in UTC: no DST in the arithmetic of the expected doses. */
const P = (iso) => iso.split('-').map(Number);
const isoOf = (t) => t.toISOString().slice(0, 10);
const addDays = (iso, n) => { const p = P(iso); return isoOf(new Date(Date.UTC(p[0], p[1] - 1, p[2] + n))); };
const weekday = (iso) => { const p = P(iso); return new Date(Date.UTC(p[0], p[1] - 1, p[2])).getUTCDay(); };
const monthLen = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();

/* The plan's doses: sorted «YYYY-MM-DD HH:MM name» (one per med/slot/day). */
function planDoses(p, times) {
	const seen = new Set();
	p.tracks.forEach((t) => {
		for (let d = 0; d < p.days; d++) {
			if (t.bits[d >> 3] & (1 << (d & 7))) {
				seen.add(addDays(p.start, d) + ' ' + times[t.slot] + ' ' + p.meds[t.med][0]);
			}
		}
	});
	return [...seen].sort();
}

/* ---- a small RFC 5545 reader + expander ------------------------------------ */

function unfold(ics) {
	return ics.replace(/\r\n[ \t]/g, '').split('\r\n').filter(Boolean);
}

function events(ics) {
	const out = [];
	let cur = null;
	let depth = 0;
	for (const line of unfold(ics)) {
		if ('BEGIN:VEVENT' === line) { cur = { props: {} }; depth = 0; continue; }
		if ('END:VEVENT' === line) { out.push(cur); cur = null; continue; }
		if (!cur) { continue; }
		if (/^BEGIN:/.test(line)) { depth++; continue; }
		if (/^END:/.test(line)) { depth--; continue; }
		if (depth) { continue; }
		const i = line.indexOf(':');
		const name = line.slice(0, i);
		const key = name.split(';')[0];
		assert.ok(!(key in cur.props), 'one ' + key + ' per event');
		cur.props[key] = { name, value: line.slice(i + 1) };
	}
	return out;
}

const WD = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

/* «YYYYMMDDTHHMMSS» (TZID=Europe/Athens) → {date, time}. */
function local(v) {
	const m = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})00$/.exec(v);
	assert.ok(m, 'local date-time: ' + v);
	return { date: m[1] + '-' + m[2] + '-' + m[3], time: m[4] + ':' + m[5] };
}

/* The dates of one event, in its own (wall-clock) time. */
function expand(ev) {
	assert.strictEqual(ev.props.DTSTART.name, 'DTSTART;TZID=Europe/Athens');
	const start = local(ev.props.DTSTART.value);
	const dates = [start.date];
	if (ev.props.RRULE) {
		const r = {};
		ev.props.RRULE.value.split(';').forEach((kv) => { const [k, v] = kv.split('='); r[k] = v; });
		assert.ok(!('UNTIL' in r), 'COUNT, never UNTIL');
		const count = +r.COUNT;
		assert.ok(count >= 2, 'a rule repeats');
		const interval = +(r.INTERVAL || 1);
		assert.deepStrictEqual(Object.keys(r).filter((k) => !['FREQ', 'COUNT', 'INTERVAL', 'BYDAY', 'BYMONTHDAY'].includes(k)), [], 'only the subset: ' + ev.props.RRULE.value);
		dates.length = 0;
		if ('DAILY' === r.FREQ) {
			assert.ok(!r.BYDAY && !r.BYMONTHDAY);
			for (let k = 0; k < count; k++) { dates.push(addDays(start.date, k * interval)); }
		} else if ('WEEKLY' === r.FREQ) {
			assert.strictEqual(interval, 1, 'weekly rules are written with INTERVAL=1 only');
			assert.ok(!r.BYMONTHDAY);
			const by = r.BYDAY ? r.BYDAY.split(',') : [WD[weekday(start.date)]];
			assert.ok(by.includes(WD[weekday(start.date)]), 'DTSTART is one of the weekdays');
			for (let d = start.date; dates.length < count; d = addDays(d, 1)) {
				if (by.includes(WD[weekday(d)])) { dates.push(d); }
			}
		} else if ('MONTHLY' === r.FREQ) {
			assert.strictEqual(interval, 1);
			const md = +r.BYMONTHDAY;
			assert.ok(md && !r.BYDAY);
			let [y, m] = P(start.date);
			/* RFC 5545: a month without that day is skipped. */
			for (let guard = 0; dates.length < count && guard < 1000; guard++) {
				const len = monthLen(y, m);
				const day = md > 0 ? md : len + md + 1;
				if (day >= 1 && day <= len) {
					const iso = y + '-' + String(m).padStart(2, '0') + '-' + String(day).padStart(2, '0');
					if (iso >= start.date) { dates.push(iso); }
				}
				m++;
				if (m > 12) { m = 1; y++; }
			}
			assert.strictEqual(dates[0], start.date, 'DTSTART is the first instance');
		} else {
			assert.fail('unexpected FREQ ' + r.FREQ);
		}
	}
	/* No RDATE / EXDATE: iPhone's Calendar has no such dates, and
	   parsers disagree about an RDATE event's own DTSTART. */
	assert.ok(!ev.props.RDATE && !ev.props.EXDATE && !ev.props['RECURRENCE-ID'], 'DTSTART + RRULE only');
	assert.strictEqual(new Set(dates).size, dates.length, 'no date twice in one event');
	return dates.map((d) => ({ date: d, time: start.time }));
}

/* The doses of a whole .ics: sorted «YYYY-MM-DD HH:MM name». */
function icsDoses(ics) {
	const out = [];
	for (const ev of events(ics)) {
		const names = ev.props.SUMMARY.value.replace(/^💊 /, '').split('\\, ');
		for (const o of expand(ev)) {
			names.forEach((n) => out.push(o.date + ' ' + o.time + ' ' + n));
		}
	}
	return out.sort();
}

function checkFile(ics) {
	for (const l of ics.split('\r\n')) {
		assert.ok(Buffer.byteLength(l, 'utf8') <= 75, 'folded: ' + l);
	}
	assert.ok(ics.startsWith('BEGIN:VCALENDAR\r\n') && ics.endsWith('END:VCALENDAR\r\n'));
	const evs = events(ics);
	const uids = evs.map((e) => e.props.UID.value);
	assert.strictEqual(new Set(uids).size, uids.length, 'unique UIDs');
	evs.forEach((e) => {
		assert.ok(e.props.SEQUENCE && e.props.DURATION && e.props.DTSTAMP);
		assert.ok(/^plandose-[0-9a-z]+-[mnaex]-[0-9a-z.-]+@plandose\.invalid$/.test(e.props.UID.value), e.props.UID.value);
	});
	return evs;
}

function assertExact(p, times, label) {
	const ics = C.buildIcs(p, times, false, NOW);
	checkFile(ics);
	assert.deepStrictEqual(icsDoses(ics), planDoses(p, times), label);
	return ics;
}

/* ---- the case of the review ------------------------------------------------ */

test('400 days, daily + every other day in three slots: a handful of events, not 1200', () => {
	const p = plan('2026-10-05', 400, 2, [
		[0, 'm', range(0, 399)], [1, 'm', range(0, 399, 2)],
		[0, 'n', range(0, 399)], [1, 'n', range(0, 399, 2)],
		[0, 'e', range(0, 399)], [1, 'e', range(0, 399, 2)]
	]);
	const ics = assertExact(p, C.DEFAULT_TIME, 'exact');
	const evs = events(ics);
	assert.strictEqual(evs.length, 6, 'one per medicine and slot');
	assert.ok(Buffer.byteLength(ics, 'utf8') < 20 * 1024, 'size ' + Buffer.byteLength(ics, 'utf8'));
	const rules = evs.map((e) => e.props.RRULE.value).sort();
	assert.deepStrictEqual(rules, ['FREQ=DAILY;COUNT=400', 'FREQ=DAILY;COUNT=400', 'FREQ=DAILY;COUNT=400',
		'FREQ=DAILY;INTERVAL=2;COUNT=200', 'FREQ=DAILY;INTERVAL=2;COUNT=200', 'FREQ=DAILY;INTERVAL=2;COUNT=200']);
	/* Google: one button per event, with the same rule. */
	const segs = C.segments(p);
	assert.ok(C.googleOk(segs));
	const recur = plain(segs).map((s) => new URL(C.googleLink(p, s, C.DEFAULT_TIME, false)).searchParams.get('recur')).sort();
	assert.deepStrictEqual(recur, rules.map((r) => 'RRULE:' + r));
});

test('the same case from the real sheet (8h + every 2 days, 400 days, through the QR)', () => {
	const I18N = { unitTablet: 'Δισκίο(α)', morning: 'Πρωί', noon: 'Μεσημέρι', afternoon: 'Απόγευμα', evening: 'Βράδυ', anyTimeOfDay: 'Μέσα στην ημέρα' };
	const med = (name, freq, days, extra) => Object.assign({
		name, doseAmount: 1, doseUnit: 'tablet', freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
	const { PD } = load({ config: { i18n: I18N, calendarUrl: CAL_URL } });
	PD.s.startDate = isoAhead(1);
	PD.s.firstSlot = 'morning';
	PD.s.items = [med('ΑΝΤΙΒΙΟΤΙΚΟ', '8h', 400), med('ΣΙΔΗΡΟΣ', 'custom', 400, { customIntervalDays: '2' })];
	PD.s.header = { name: 'Φαρμακείο Δοκιμής' };
	PD.beginDayPass();
	const link = PD.calendarLink();
	assert.ok(link, 'fits the QR');
	const hash = link.slice(link.indexOf('#') + 1);
	const dom = patientPage(hash);
	const P2 = dom.window.PlanDoseCalendar;
	const p = P2.decode('#' + hash);
	const ics = P2.buildIcs(p, P2.DEFAULT_TIME, false, NOW);
	checkFile(ics);
	const n = events(ics).length;
	assert.ok(n <= 5, n + ' events');
	assert.ok(Buffer.byteLength(ics, 'utf8') < 20 * 1024);
	/* The names have no comma: SUMMARY splits cleanly. */
	assert.deepStrictEqual(icsDoses(ics), planDoses(p, P2.DEFAULT_TIME));
	/* Google buttons are drawn, and say the rhythm. */
	const when = [...dom.window.document.querySelectorAll('.gcal-list a .when')].map((a) => a.textContent);
	assert.strictEqual(when.length, n);
	assert.ok(when.some((t) => /κάθε 2 ημέρες/.test(t)), when.join(' | '));
	dom.window.close();
});

test('plans that were fine keep one alarm for all their medicines (runs win ties)', () => {
	/* A for 7 days, B for 14, both mornings: two events, as before —
	   the first names both medicines. */
	const p = plan('2026-10-01', 14, 2, [[0, 'm', range(0, 6)], [1, 'm', range(0, 13)]]);
	const segs = C.segments(p);
	assert.strictEqual(segs.length, 2);
	assert.deepStrictEqual(plain(segs[0].meds), [0, 1]);
	assertExact(p, C.DEFAULT_TIME);
	/* Same days in the same slot: one event for both. */
	const q = plan('2026-10-01', 10, 3, [[0, 'e', range(0, 9, 3)], [2, 'e', range(0, 9, 3)], [1, 'e', range(1, 9, 2)]]);
	const s2 = C.segments(q);
	assert.strictEqual(s2.length, 2);
	assert.deepStrictEqual(plain(s2.map((s) => s.meds)), [[0, 2], [1]]);
	assertExact(q, C.DEFAULT_TIME);
});

/* ---- weekly / monthly ------------------------------------------------------ */

test('weekly: one weekday → FREQ=WEEKLY; two weekdays → BYDAY', () => {
	/* 2026-10-01 is a Thursday. */
	const p = plan('2026-10-01', 90, 2, [[0, 'm', range(0, 89, 7)], [1, 'm', range(0, 89).filter((d) => [1, 4].includes(weekday(addDays('2026-10-01', d))))]]);
	const ics = assertExact(p, C.DEFAULT_TIME);
	const rules = events(ics).map((e) => e.props.RRULE.value).sort();
	assert.deepStrictEqual(rules, ['FREQ=WEEKLY;BYDAY=MO,TH;COUNT=26', 'FREQ=WEEKLY;COUNT=13']);
});

test('monthly: the 15th → BYMONTHDAY; «the 31st» (the last day) → BYMONTHDAY=-1; «the 30th» → BYMONTHDAY=30 only between Februaries', () => {
	const start = '2027-01-01';
	const days = 400;
	const monthly = (want) => range(0, days - 1).filter((d) => {
		const [y, m, dd] = P(addDays(start, d));
		return dd === Math.min(want, monthLen(y, m));
	});
	const p = plan(start, days, 3, [[0, 'm', monthly(15)], [1, 'm', monthly(31)], [2, 'm', monthly(30)]]);
	const ics = assertExact(p, C.DEFAULT_TIME);
	const rules = events(ics).map((e) => e.props.RRULE ? e.props.RRULE.value : '');
	assert.ok(rules.includes('FREQ=MONTHLY;BYMONTHDAY=15;COUNT=13'), rules.join(' | '));
	assert.ok(rules.includes('FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=13'), rules.join(' | '));
	/* «30»: 30/01 and 28/02 (every 29 days, twice), then 30/03 … 30/01:
	   the rule stops before February 2028, which has no 30th. */
	assert.ok(rules.includes('FREQ=MONTHLY;BYMONTHDAY=30;COUNT=11'), rules.join(' | '));
	assert.ok(rules.includes('FREQ=DAILY;INTERVAL=29;COUNT=2'), rules.join(' | '));
	assert.strictEqual(events(ics).length, 4);
	/* A hand-made 31st that jumps to 03/03 after 31/01 is not «monthly». */
	const q = plan('2027-01-31', 40, 1, [[0, 'm', [0, 31]]]);
	assert.ok(!/BYMONTHDAY/.test(assertExact(q, C.DEFAULT_TIME)));
});

/* ---- DST ------------------------------------------------------------------ */

test('DST: a daily dose over the March and October changes stays at the same local time', () => {
	for (const start of ['2026-03-20', '2026-10-18', '2027-03-25', '2027-10-28']) {
		const p = plan(start, 20, 2, [[0, 'm', range(0, 19)], [1, 'e', range(0, 19, 3)], [0, 'x', [0, 9, 10, 19]]]);
		const times = { m: '03:30', n: '14:00', a: '18:00', e: '23:50', x: '00:00' };
		const ics = assertExact(p, times, start);
		const u = unfold(ics);
		assert.ok(u.some((l) => l === 'DTSTART;TZID=Europe/Athens:' + start.replace(/-/g, '') + 'T033000'), start);
		assert.ok(u.slice(u.indexOf('BEGIN:VEVENT')).every((l) => !/^DTSTART[:;](?!TZID=Europe\/Athens:)/.test(l)), 'events always in Athens time');
		assert.ok(u.includes('TZID:Europe/Athens') && u.includes('RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU'));
	}
});

/* ---- irregular days: a few more events, never a lost dose ------------------ */

test('irregular days: a few rules; scattered (hand-made) days never cost more events than runs', () => {
	/* A dose carried over to an extra day: daily 0–9, then day 11. */
	const p = plan('2026-10-01', 12, 1, [[0, 'm', range(0, 9).concat([11])]]);
	const segs = C.segments(p);
	assert.strictEqual(segs.length, 2);
	assertExact(p, C.DEFAULT_TIME);
	/* Scattered days (a hand-made link). */
	const scattered = [0, 1, 5, 6, 7, 13, 20, 22, 23, 30, 41, 42, 50, 57, 61, 79, 80, 88, 99, 100, 150, 151, 199];
	const q = plan('2026-10-01', 200, 2, [[0, 'a', scattered], [1, 'a', range(0, 199)]]);
	const ics = assertExact(q, C.DEFAULT_TIME);
	const evs = events(ics);
	/* The daily medicine: one event. The scattered one: short rules. */
	assert.strictEqual(evs.filter((e) => 'FREQ=DAILY;COUNT=200' === (e.props.RRULE || {}).value).length, 1);
	assert.ok(evs.length < 20, evs.length + ' events (runs of days: 45)');
	assert.strictEqual(C.googleOk(C.segments(q)), evs.length <= 12, 'Google buttons only for a few events');
	/* Every day different in two slots: as runs, never more. */
	const alt = range(0, 59).filter((d) => d % 3 !== 1);
	const r = plan('2026-10-01', 60, 2, [[0, 'm', alt], [1, 'm', range(0, 59).filter((d) => !alt.includes(d))]]);
	assert.ok(C.segments(r).length <= 60);
	assertExact(r, C.DEFAULT_TIME);
});

test('random plans: the .ics has exactly the plan’s doses, and never more events than runs of days', () => {
	let seed = 1310;
	const rnd = () => {
		seed = (seed + 0x6d2b79f5) | 0;
		let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
	};
	const int = (a, b) => a + Math.floor(rnd() * (b - a + 1));
	const pick = (a) => a[int(0, a.length - 1)];
	const starts = ['2026-03-27', '2026-10-23', '2027-03-26', '2027-10-29', '2028-02-27', '2026-12-30', '2027-01-31'];
	const patterns = [
		(n) => range(int(0, 3), n - 1 - int(0, 3)),
		(n) => range(int(0, 5), n - 1, int(2, 10)),
		(n, s) => { const w = [int(0, 6), int(0, 6), int(0, 6)]; return range(0, n - 1).filter((d) => w.includes(weekday(addDays(s, d)))); },
		(n, s) => { const md = int(1, 31); return range(0, n - 1).filter((d) => { const [y, m, dd] = P(addDays(s, d)); return dd === Math.min(md, monthLen(y, m)); }); },
		(n) => range(0, n - 1).filter(() => rnd() < 0.3),
		(n) => { const a = range(1, Math.max(1, n - 2)); return n > 3 ? a.concat([n - 1]) : a; },
		(n) => [int(0, n - 1)]
	];
	let total = 0;
	for (let k = 0; k < 400; k++) {
		const start = k < 60 ? pick(starts) : addDays('2026-01-01', int(0, 900));
		const days = pick([1, 2, 7, 13, 31, 60, 95, 200, 366, 400]);
		const medCount = int(1, 5);
		const tracks = [];
		for (let m = 0; m < medCount; m++) {
			const slots = SLOTS.filter(() => rnd() < 0.45);
			(slots.length ? slots : [pick(SLOTS)]).forEach((slot) => {
				let set = pick(patterns)(days, start);
				if (rnd() < 0.2) { set = [...new Set(set.concat(pick(patterns)(days, start)))].sort((a, b) => a - b); }
				set = set.filter((d) => d >= 0 && d < days);
				if (set.length) { tracks.push([m, slot, set]); }
			});
		}
		if (!tracks.length) { continue; }
		const p = plan(start, days, medCount, tracks);
		const times = {};
		SLOTS.forEach((s) => { times[s] = String(int(0, 23)).padStart(2, '0') + ':' + String(int(0, 59)).padStart(2, '0'); });
		const ics = assertExact(p, times, JSON.stringify({ start, days, tracks }));
		/* Never worse than one event per run of days (the old way). */
		const map = C.dayMap(p);
		let runs = 0;
		SLOTS.forEach((s) => { for (let d = 0; d < days; d++) { if (map[s][d].length && (0 === d || map[s][d].join() !== map[s][d - 1].join())) { runs++; } } });
		assert.ok(events(ics).length <= runs);
		total++;
	}
	assert.ok(total > 350);
});

test('the same plan gives the same UIDs; a second track does not change the first one’s UID', () => {
	const p = plan('2026-10-01', 30, 2, [[0, 'm', range(0, 29)], [1, 'm', range(0, 29, 2)]]);
	const uids = (ics) => events(ics).map((e) => e.props.UID.value).sort();
	const a = uids(C.buildIcs(p, C.DEFAULT_TIME, false, NOW));
	const b = uids(C.buildIcs(p, Object.assign({}, C.DEFAULT_TIME, { m: '07:15' }), true, new Date('2026-09-28T10:00:00Z')));
	assert.deepStrictEqual(a, b);
	assert.ok(a.every((u) => /-m-/.test(u)));
});

/* ---- the patient page's text ----------------------------------------------- */

test('medWhen: each part of the day with its own days', () => {
	const T = C.TEXT.el;
	/* Every morning for 10 days, three evenings. */
	const p = plan('2026-10-25', 10, 1, [[0, 'm', range(0, 9)], [0, 'e', [0, 1, 2]]]);
	assert.strictEqual(C.medWhen(p, 0, T), 'Πρωί · κάθε ημέρα · 25/10 – 03/11; Βράδυ · κάθε ημέρα · 25/10 – 27/10');
	/* The same days: said once, as before. */
	const q = plan('2026-10-25', 10, 1, [[0, 'm', range(0, 9)], [0, 'e', range(0, 9)]]);
	assert.strictEqual(C.medWhen(q, 0, T), 'Πρωί · Βράδυ · κάθε ημέρα · 25/10 – 03/11');
	/* Daily mornings, every-other-day nights. */
	const r = plan('2026-10-01', 10, 1, [[0, 'e', range(0, 9, 2)], [0, 'm', range(0, 9)]]);
	assert.strictEqual(C.medWhen(r, 0, T), 'Πρωί · κάθε ημέρα · 01/10 – 10/10; Βράδυ · κάθε 2 ημέρες · 5 ημέρες με δόση · 01/10 – 09/10');
});
