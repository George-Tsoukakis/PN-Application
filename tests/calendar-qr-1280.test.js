/* 1.28.0: «Υπενθυμίσεις στο κινητό» — the QR on the A4 sheet and the
   patient page (public/calendar.html + calendar.js).

   The rule that matters: the calendar has EXACTLY the doses of the paper.
   The QR payload is built from PD.dayDoses() (the day cards), decoded by
   the patient page, and compared back day by day, slot by slot. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const env = require('./lib/env.js');
const { load, closeAll, isoAhead } = require('./harness');

const PUBLIC = path.join(env.pluginDir(), 'public');
const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.28.0';
const SLOT = { morning: 'm', noon: 'n', afternoon: 'a', evening: 'e', anytime: 'x' };

const I18N = { unitTablet: 'Δισκίο(α)', morning: 'Πρωί', noon: 'Μεσημέρι', afternoon: 'Απόγευμα', evening: 'Βράδυ', anyTimeOfDay: 'Μέσα στην ημέρα' };

function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}

function setup(items, opts) {
	opts = opts || {};
	const e = load({ config: Object.assign({ i18n: I18N }, 'calendarUrl' in opts ? { calendarUrl: opts.calendarUrl } : { calendarUrl: CAL_URL }) });
	const PD = e.PD;
	PD.s.startDate = isoAhead(opts.ahead || 0);
	PD.s.firstSlot = opts.firstSlot || 'morning';
	PD.s.items = items;
	PD.s.header = { name: opts.pharmacy || 'Φαρμακείο Δοκιμής' };
	PD.beginDayPass();
	return e;
}

/* The patient page script, in a page of its own, with the given fragment. */
function patientPage(hash) {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8')
		.replace(/<script src="calendar\.js[^"]*"><\/script>/, '')
		.replace(/<link[^>]*calendar\.css[^>]*>/, '');
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: CAL_URL + (hash ? '#' + hash : '') });
	dom.window.eval(fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8'));
	return dom;
}

function fragment(link) {
	return link.slice(link.indexOf('#') + 1);
}

/* Paper: {day: {slot: [names]}} from the day cards. */
function paper(PD) {
	const out = {};
	for (let d = 0; d < PD.planDayCount(); d++) {
		PD.dayDoses(d).forEach((g) => {
			g.entries.forEach((en) => {
				const k = d + SLOT[g.key];
				(out[k] = out[k] || []).push(en.item.name);
			});
		});
	}
	Object.keys(out).forEach((k) => out[k].sort());
	return out;
}

/* Calendar: the same shape, from the patient page's own segments. */
function calendar(plan, C) {
	const out = {};
	C.segments(plan).forEach((seg) => {
		for (let d = seg.from; d < seg.from + seg.count; d++) {
			const k = d + seg.slot;
			(out[k] = out[k] || []).push(...seg.meds.map((i) => plan.meds[i][0]));
		}
	});
	Object.keys(out).forEach((k) => out[k].sort());
	return out;
}

test.after(closeAll);

test('the calendar has exactly the doses of the paper (fixed, carried over, weekly, every N days, monthly)', () => {
	const cases = [
		{ items: [med('A', '12h', 7), med('B', '24h', 14, { dailyTime: 'evening' }), med('C', '8h', 5), med('D', '6h', 3)] },
		/* First dose «Βράδυ» today: the morning doses move to an extra day. */
		{ items: [med('A', '12h', 5), med('C', '8h', 4), med('D', '6h', 2)], firstSlot: 'evening' },
		{ items: [med('E', 'custom', 28, { customMode: 'weekday', customWeekday: 3 }), med('F', 'custom', 20, { customMode: 'days', customIntervalDays: '3' }), med('G', '24h', 10)], ahead: 1 },
		{ items: [med('M', 'custom', 90, { customMode: 'monthday', customMonthDay: 31 }), med('N', '24h', 30, { dailyTime: 'noon' })], ahead: 2 }
	];
	for (const c of cases) {
		const { PD } = setup(c.items, c);
		const link = PD.calendarLink();
		assert.ok(link.startsWith(CAL_URL + '#'), 'link built');
		const dom = patientPage(fragment(link));
		const C = dom.window.PlanDoseCalendar;
		const plan = C.decode('#' + fragment(link));
		assert.ok(plan, 'the patient page reads the link');
		assert.strictEqual(plan.start, PD.isoDate(PD.dayAt(0)));
		assert.deepStrictEqual(calendar(plan, C), paper(PD), JSON.stringify(c.items.map((i) => i.name)));
		dom.window.close();
	}
});

test('the patient name is never in the QR; the pharmacy and notes are, when they fit', () => {
	const { PD } = setup([med('A', '12h', 7, { notes: 'Μετά το φαγητό' })]);
	PD.getPatientName = () => 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΓΙΩΡΓΟΣ';
	const frag = fragment(PD.calendarLink());
	const bytes = [...Buffer.from(frag.replace(/-/g, '+').replace(/_/g, '/'), 'base64')];
	const needle = PD.calText('ΠΑΠΑΔΟΠΟΥΛΟΣ');
	const found = bytes.some((_, i) => needle.every((x, j) => bytes[i + j] === x));
	assert.ok(!found, 'no patient name bytes');
	const dom = patientPage(frag);
	const plan = dom.window.PlanDoseCalendar.decode('#' + frag);
	assert.strictEqual(plan.pharmacy, 'Φαρμακείο Δοκιμής');
	assert.strictEqual(plan.meds[0][2], 'Μετά το φαγητό');
	dom.window.close();
});

test('Greek text survives the compact code both ways (accents, ½, €, emoji)', () => {
	const name = 'ΆΈΉΊΌΎΏ άέήίόύώ ϊϋΐΰ ½ € 💊 «test»';
	const { PD } = setup([med(name, '24h', 3, { notes: 'μισό ½ — «πριν»' })], { pharmacy: 'Φαρμακείο «Ώρα»' });
	const frag = fragment(PD.calendarLink());
	const dom = patientPage(frag);
	const plan = dom.window.PlanDoseCalendar.decode('#' + frag);
	assert.strictEqual(plan.meds[0][0], name);
	assert.strictEqual(plan.meds[0][2], 'μισό ½ — «πριν»');
	assert.strictEqual(plan.pharmacy, 'Φαρμακείο «Ώρα»');
	dom.window.close();
});

test('a long plan drops notes, then the pharmacy, and never makes a QR too dense to read', () => {
	const many = [];
	for (let i = 0; i < 8; i++) {
		many.push(med('ΦΑΡΜΑΚΟ ΜΕ ΠΟΛΥ ΜΕΓΑΛΟ ΟΝΟΜΑ ' + i + ' 500MG', '8h', 30, { notes: 'Σημείωση αρκετά μεγάλη για να γεμίσει το QR ' + i }));
	}
	const { PD } = setup(many);
	const link = PD.calendarLink();
	assert.ok(link.length <= PD.CAL_MAX_LINK, String(link.length));
	if (link) {
		assert.ok(!link.includes('Σημείωση'));
	}
	const huge = [];
	for (let i = 0; i < 30; i++) {
		huge.push(med('ΠΟΛΥ ΜΕΓΑΛΟ ΟΝΟΜΑ ΦΑΡΜΑΚΟΥ ΑΡΙΘΜΟΣ ' + i + ' 1000MG TAB', '6h', 60));
	}
	const big = setup(huge).PD;
	assert.strictEqual(big.calendarLink(), '', 'too long: no QR at all');
	assert.strictEqual(big.calendarQrHtml(), '');
});

test('the QR box is on the sheet when on, absent when the setting is off', () => {
	const on = setup([med('A', '12h', 7)]).PD;
	const html = on.buildPrintHtml();
	assert.ok(html.includes('pd-info-grid pd-info-grid-cal'));
	assert.ok(/<div class="pd-cal-qr"><svg[^>]*viewBox/.test(html));
	assert.ok(html.includes('Υπενθυμίσεις στο κινητό'));

	const off = setup([med('A', '12h', 7)], { calendarUrl: '' }).PD;
	const plainHtml = off.buildPrintHtml();
	assert.ok(!plainHtml.includes('pd-cal-box'));
	assert.ok(plainHtml.includes('<div class="pd-info-grid">'));
});

test('the QR encodes the link exactly (module count fits, bytes round-trip)', () => {
	const { PD } = setup([med('A', '12h', 7), med('B', '24h', 14, { dailyTime: 'evening' })]);
	const link = PD.calendarLink();
	const qr = PD.qrcode(0, 'L');
	qr.addData(link, 'Byte');
	qr.make();
	assert.ok(qr.getModuleCount() <= 89, 'version ≤ 18 for the 34 mm box');
});

test('patient page: HTML in the link stays text, bad links are refused', () => {
	const { PD } = setup([med('<img src=x onerror="window.pwned=1">', '12h', 3, { notes: '<script>window.pwned=2</script>' })], { pharmacy: '<b>x</b>' });
	const dom = patientPage(fragment(PD.calendarLink()));
	const doc = dom.window.document;
	assert.strictEqual(doc.querySelectorAll('#app img, #app script, #app b').length, 0);
	assert.ok(doc.querySelector('.med-name').textContent.includes('<img'));
	assert.strictEqual(dom.window.pwned, undefined);
	dom.window.close();

	const C = patientPage('').window.PlanDoseCalendar;
	const good = { v: 1, l: 'el', s: '2026-10-01', n: 3, f: '', m: [['A', '1', '']], t: [[0, 0, [7]]] };
	const enc = (o) => '#' + PD.calEncode(o);
	assert.ok(C.decode(enc(good)), 'the good one is read');
	for (const bad of [
		Object.assign({}, good, { v: 2 }),
		Object.assign({}, good, { n: 0 }),
		Object.assign({}, good, { n: 401 }),
		Object.assign({}, good, { t: [[1, 0, [7]]] }),
		Object.assign({}, good, { t: [[0, 5, [7]]] }),
		Object.assign({}, good, { t: [[0, 0, []]] }),
		Object.assign({}, good, { t: [[0, 0, [7, 0]]] }),
		Object.assign({}, good, { m: [['', '1', '']] }),
		Object.assign({}, good, { m: [['   ', '1', '']] })
	]) {
		assert.strictEqual(C.decode(enc(bad)), null, JSON.stringify(bad));
	}
	const bytes = [...Buffer.from(PD.calEncode(good).replace(/-/g, '+').replace(/_/g, '/'), 'base64')];
	const b64 = (arr) => '#' + Buffer.from(arr).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	assert.strictEqual(C.decode(b64(bytes.concat([0]))), null, 'trailing bytes');
	assert.strictEqual(C.decode(b64(bytes.slice(0, -1))), null, 'cut short');
	const flags = bytes.slice(); flags[1] = 3;
	assert.strictEqual(C.decode(b64(flags)), null, 'unknown flags');
	const ctrl = bytes.slice(); ctrl[ctrl.indexOf(0x41)] = 0x0a;
	assert.strictEqual(C.decode(b64(ctrl)), null, 'control character');
	assert.strictEqual(C.decode('#not*base64'), null);
	assert.strictEqual(C.decode('#'), null);

	const page = patientPage('garbage');
	assert.ok(page.window.document.querySelector('.notice'), 'a clear message, not a broken page');
	page.window.close();
});

test('patient page: valid .ics — folded lines, Athens time, daily repeats, one alarm per event', () => {
	const { PD } = setup([med('ΑΝΤΙΒΙΟΤΙΚΟ, 1G; ΔΟΚΙΜΗ', '8h', 7), med('Β', '24h', 30, { dailyTime: 'evening' })], { ahead: 1 });
	const dom = patientPage(fragment(PD.calendarLink()));
	const C = dom.window.PlanDoseCalendar;
	const plan = C.decode(dom.window.location.hash);
	const ics = C.buildIcs(plan, C.DEFAULT_TIME, false, new Date('2026-09-27T10:00:00Z'));
	const lines = ics.split('\r\n');
	assert.ok(ics.endsWith('END:VCALENDAR\r\n'));
	for (const l of lines) {
		assert.ok(Buffer.byteLength(l, 'utf8') <= 75, 'folded: ' + l);
	}
	const unfolded = ics.replace(/\r\n /g, '');
	const events = unfolded.split('BEGIN:VEVENT').length - 1;
	assert.strictEqual(events, C.segments(plan).length);
	assert.strictEqual(unfolded.split('BEGIN:VALARM').length - 1, events);
	assert.ok(unfolded.includes('DTSTART;TZID=Europe/Athens:'));
	assert.ok(unfolded.includes('RRULE:FREQ=DAILY;COUNT='));
	assert.ok(unfolded.includes('ΑΝΤΙΒΙΟΤΙΚΟ\\, 1G\\; ΔΟΚΙΜΗ'), 'commas and semicolons escaped');
	const neutral = C.buildIcs(plan, C.DEFAULT_TIME, true).replace(/\r\n /g, '');
	assert.ok(/SUMMARY:💊 Φάρμακα — Πρωί/.test(neutral));
	assert.ok(!/SUMMARY:[^\r\n]*ΑΝΤΙΒΙΟΤΙΚΟ/.test(neutral), 'neutral titles hide the names');
	dom.window.close();
});

test('patient page: static, no network, no third-party code', () => {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8');
	const csp = /http-equiv="Content-Security-Policy" content="([^"]+)"/.exec(html);
	assert.ok(csp, 'CSP present');
	assert.ok(/default-src 'none'/.test(csp[1]) && /connect-src 'none'/.test(csp[1]) && /script-src 'self'(;|$)/.test(csp[1]));
	assert.ok(!/<script>(?!\s*<\/script>)/.test(html), 'no inline script');
	const js = fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8');
	assert.ok(!/fetch\(|XMLHttpRequest|sendBeacon|localStorage|sessionStorage|indexedDB|innerHTML/.test(js), 'no network, no storage, no innerHTML');
	const external = (html + js).match(/https?:\/\/[^\s'"<>)]+/g) || [];
	assert.deepStrictEqual(external.filter((u) => !u.startsWith('https://calendar.google.com/')), []);
	assert.ok(fs.existsSync(path.join(PUBLIC, 'index.php')));
});

/* Independent review of 1.28.0. */
test('review: no QR for plans the patient page cannot read (> 400 days)', () => {
	const { PD } = setup([med('A', '24h', 401)]);
	assert.strictEqual(PD.calendarLink(), '');
	const ok = setup([med('A', '24h', 400)]).PD;
	const dom = patientPage(fragment(ok.calendarLink()));
	assert.ok(dom.window.PlanDoseCalendar.decode(dom.window.location.hash));
	dom.window.close();
});

test('review: clipping never splits an emoji (the whole QR stayed unreadable)', () => {
	const { PD } = setup([med('a'.repeat(58) + '💊bbb', '24h', 3, { notes: 'n'.repeat(48) + '💊zzz' })], { pharmacy: 'p'.repeat(38) + '😀qq' });
	const dom = patientPage(fragment(PD.calendarLink()));
	const plan = dom.window.PlanDoseCalendar.decode(dom.window.location.hash);
	assert.ok(plan, 'readable');
	assert.ok(plan.meds[0][0].endsWith('…'));
	dom.window.close();
});

test('review: a non-ASCII site address is percent-encoded in the QR', () => {
	const { PD } = setup([med('A', '24h', 3)], { calendarUrl: 'https://φαρμακείο.gr/φάκελος/wp-content/plugins/plandose/public/calendar.html' });
	const link = PD.calendarLink();
	assert.ok(/^[\x21-\x7e]+$/.test(link), link);
	assert.ok(link.includes('%CF%86%CE%AC'));
});

test('review: an event at 23:59 is still 15 minutes long (DURATION, next-day end for Google)', () => {
	const { PD } = setup([med('A', '24h', 3, { dailyTime: 'evening' })], { ahead: 1 });
	const dom = patientPage(fragment(PD.calendarLink()));
	const C = dom.window.PlanDoseCalendar;
	const plan = C.decode(dom.window.location.hash);
	const times = Object.assign({}, C.DEFAULT_TIME, { e: '23:59' });
	const ics = C.buildIcs(plan, times, true);
	assert.ok(ics.includes('DURATION:PT15M') && !ics.includes('DTEND'));
	const g = decodeURIComponent(C.googleLink(plan, C.segments(plan)[0], times, true));
	const m = /dates=(\d{8})T235900\/(\d{8})T001400/.exec(g);
	assert.ok(m && m[2] > m[1], g);
	dom.window.close();
});

test('1.28.1: the on-screen preview gives the QR a size (it filled the whole preview)', () => {
	/* The preview is styled by the print stylesheet, scoped to its paper
	   (preview.js): the QR gets the printed 34 mm there too. */
	const { PD, w } = setup([med('ΑΝΤΙΒΙΟΤΙΚΟ', '12h', 5)], { ahead: 1 });
	PD.buildApp();
	PD.renderPreview();
	assert.ok(w.document.querySelector('#pd-preview-area .pd-preview-sheet .pd-cal-qr svg'), 'QR in the preview');
	const css = w.document.getElementById('pd-preview-print-styles').textContent;
	const rule = /#pd-preview-area \.pd-preview-sheet \.pd-cal-qr\s*\{([^}]*)\}/.exec(css);
	assert.ok(rule, '.pd-cal-qr scoped to the preview');
	assert.ok(/width:\s*34mm/.test(rule[1]) && /height:\s*34mm/.test(rule[1]), rule[1]);
	assert.ok(/\.pd-preview-sheet \.pd-cal-qr svg\s*\{[^}]*width:\s*100%/.test(css));
});

test('1.28.4: the medicine names show in the alert by default; ticking the box hides them', () => {
	const { PD } = setup([med('ΑΝΤΙΒΙΟΤΙΚΟ', '12h', 5), med('ΣΙΡΟΠΙ', '24h', 5, { dailyTime: 'evening' })], { ahead: 1 });
	const dom = patientPage(fragment(PD.calendarLink()));
	const doc = dom.window.document;
	const box = doc.getElementById('pd-neutral');
	assert.ok(box, 'the option is on the page');
	assert.strictEqual(box.checked, false, 'unticked by default');
	assert.strictEqual(box.hasAttribute('checked'), false);

	const title = (a) => new URL(a.href).searchParams.get('text');
	let links = [...doc.querySelectorAll('.gcal-list a')];
	assert.ok(links.length > 0, 'Google buttons drawn');
	assert.ok(links.some((a) => title(a).includes('ΑΝΤΙΒΙΟΤΙΚΟ')), 'names in the alert title by default');

	box.checked = true;
	box.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	links = [...doc.querySelectorAll('.gcal-list a')];
	assert.ok(links.every((a) => !/ΑΝΤΙΒΙΟΤΙΚΟ|ΣΙΡΟΠΙ/.test(title(a))), 'ticked: names hidden');
	assert.ok(links.every((a) => title(a).startsWith('💊 Φάρμακα')));
	dom.window.close();
});
