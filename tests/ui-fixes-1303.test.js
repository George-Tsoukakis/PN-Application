/* UI fixes (1.30.3):
   - a medicine whose first dose moves a whole day (the plan starts today and
     its only daypart is before «Πρώτη δόση») is warned about on screen —
     above the preview and right after it is added — never on the sheet;
   - the calendar QR uses error correction M whenever it fits the 85-module
     box, and falls back to L (as before) for a long link; the SVG is named
     for screen readers. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');

test.afterEach(closeAll);

/* Monday 5 October 2026, early afternoon: morning and noon have passed. */
const NOW = '2026-10-05T14:00:00';
const SHIFT = /ξεκινά αύριο/;
const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.30.3';

function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}

function plan(items, opts) {
	opts = opts || {};
	const e = load({ now: NOW, config: opts.config || {} });
	e.PD.s.startDate = opts.startDate === undefined ? e.isoAhead(0) : opts.startDate;
	e.PD.s.firstSlot = opts.firstSlot || 'morning';
	e.PD.s.items = items;
	e.PD.s.header = { name: 'Φαρμακείο' };
	const area = e.w.document.createElement('div');
	area.id = 'pd-preview-area';
	e.w.document.body.appendChild(area);
	return e;
}

function shiftBox(w) {
	return w.document.querySelector('#pd-preview-area .pd-preview-shift');
}

test('once daily «Πρωί», first dose «Μεσημέρι» today → warned above the preview, not printed', () => {
	const { PD, w } = plan([med('ASPIRIN', '24h', 7), med('OTHER', '24h', 7, { dailyTime: 'evening' })], { firstSlot: 'noon' });
	PD.renderPreview();
	const box = shiftBox(w);
	assert.ok(box, 'warning above the preview');
	assert.strictEqual(box.getAttribute('role'), 'note');
	const items = box.querySelectorAll('li');
	assert.strictEqual(items.length, 1, 'only the shifted medicine (the evening one starts today)');
	assert.match(items[0].textContent, /«ASPIRIN» ξεκινά αύριο \(Πρωί Τρί 06\/10\)/);
	assert.match(items[0].textContent, /Πρώτη δόση/);
	/* Above the sheet, not inside it. */
	assert.ok(!box.closest('.pd-preview-sheet'));
	const html = PD.buildPrintHtml();
	assert.ok(!html.includes('pd-preview-shift'));
	assert.ok(!SHIFT.test(html), 'never on the patient sheet');
	/* The schedule itself is unchanged: 7 doses, the extra day at the end. */
	assert.strictEqual(PD.doseTotals(PD.s.items[0]).doses, 7);
	assert.strictEqual(PD.planColumns(PD.s.items[0]), 8);
});

test('no warning when only some doses carry over, or with the first dose «Πρωί»', () => {
	const some = plan([med('A', '12h', 7)], { firstSlot: 'evening' });
	some.PD.renderPreview();
	assert.ok(!shiftBox(some.w), '2 φορές, first dose «Βράδυ»: still starts today');
	const morning = plan([med('A', '24h', 7)]);
	morning.PD.renderPreview();
	assert.ok(!shiftBox(morning.w));
});

test('no warning when the plan starts on a later day', () => {
	const e = load({ now: NOW });
	const { PD, w } = plan([med('A', '24h', 7)], { firstSlot: 'evening', startDate: e.isoAhead(1) });
	PD.renderPreview();
	assert.ok(!shiftBox(w));
	assert.strictEqual(PD.firstDoseShifted(PD.s.items[0]), false);
});

test('no warning for weekly / every-N-days schedules (their first date is by design)', () => {
	/* Every Wednesday: the first dose is Τετ 07/10, 2 days after today's
	   start — by design, not because a daypart passed. */
	const weekly = med('FOSAMAX', 'custom', 28, { customMode: 'weekday', customWeekday: 3 });
	const every = med('B', 'custom', 10, { customMode: 'days', customIntervalDays: '3' });
	const { PD, w } = plan([weekly, every], { firstSlot: 'evening' });
	PD.renderPreview();
	assert.ok(!shiftBox(w));
	assert.strictEqual(PD.firstDoseShifted(weekly), false);
	assert.strictEqual(PD.firstDoseShifted(every), false);
});

test('changing «Πρώτη δόση» back removes the warning', () => {
	const { PD, w } = plan([med('A', '24h', 7)], { firstSlot: 'afternoon' });
	PD.renderPreview();
	assert.ok(shiftBox(w));
	PD.s.firstSlot = 'morning';
	PD.renderPreview();
	assert.ok(!shiftBox(w));
});

test('form: adding such a medicine says so at once', () => {
	const env = load({ now: NOW });
	const { PD, w } = env;
	PD.s.startDate = env.isoAhead(0);
	PD.buildApp();
	const d = w.document;
	PD.setFirstSlot('evening');
	PD.setFieldValue('pd-drug', 'LIPITOR');
	PD.setFieldValue('pd-days', '30');
	d.querySelector('.plandose-chip[data-val="24h"]').click();
	d.querySelector('.pd-daily-time-chip[data-time="noon"]').click();
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'added: the warning does not block');
	const msg = d.getElementById('pd-message');
	assert.match(msg.textContent, /«LIPITOR» ξεκινά αύριο \(Μεσημέρι Τρί 06\/10\)/);
	assert.ok(shiftBox(w), 'and listed above the preview');

	/* An «evening» medicine starts today: the usual confirmation. */
	PD.setFieldValue('pd-drug', 'OTHER');
	PD.setFieldValue('pd-days', '30');
	d.querySelector('.plandose-chip[data-val="24h"]').click();
	d.querySelector('.pd-daily-time-chip[data-time="evening"]').click();
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 2);
	assert.doesNotMatch(msg.textContent, SHIFT);
});

test('i18n: the English dictionary words the warning too', () => {
	const e = load({ now: NOW, i18n: 'real' });
	assert.ok(e.PD.txt('firstDoseShiftWarn', '').includes('%1$s'));
	assert.ok(e.PD.txt('calQrAlt', ''));
});

/* ---- QR error correction ---- */

function sameMatrix(a, b) {
	if (a.getModuleCount() !== b.getModuleCount()) {
		return false;
	}
	for (let r = 0; r < a.getModuleCount(); r++) {
		for (let c = 0; c < a.getModuleCount(); c++) {
			if (a.isDark(r, c) !== b.isDark(r, c)) {
				return false;
			}
		}
	}
	return true;
}

function qrAt(PD, link, level) {
	const qr = PD.qrcode(0, level);
	qr.addData(link, 'Byte');
	qr.make();
	return qr;
}

test('QR: a short plan gets level M, the SVG is a named, crisp image', () => {
	const { PD } = plan([med('A', '12h', 7), med('B', '24h', 14, { dailyTime: 'evening' })], { config: { calendarUrl: CAL_URL } });
	PD.beginDayPass();
	const link = PD.calendarLink();
	assert.ok(link && link.length < 500, 'a short link');
	const qr = PD.calendarQrCode(link);
	assert.ok(sameMatrix(qr, qrAt(PD, link, 'M')), 'level M');
	assert.ok(!sameMatrix(qr, qrAt(PD, link, 'L')));
	assert.ok(qr.getModuleCount() <= PD.CAL_QR_MAX_MODULES);
	const html = PD.calendarQrHtml();
	assert.match(html, /<div class="pd-cal-qr"><svg role="img" aria-label="QR για το ημερολόγιο του ασθενή" shape-rendering="crispEdges" [^>]*viewBox/);
});

test('QR: a link too long for M in 85 modules falls back to L and still fits', () => {
	const { PD } = load();
	assert.strictEqual(PD.CAL_QR_MAX_MODULES, 85);
	/* The longest link the sheet prints needs exactly version 17 at L. */
	const longest = 'https://x.test/c#' + 'a'.repeat(PD.CAL_MAX_LINK - 17);
	assert.strictEqual(qrAt(PD, longest, 'L').getModuleCount(), 85);
	const qr = PD.calendarQrCode(longest);
	assert.ok(sameMatrix(qr, qrAt(PD, longest, 'L')), 'level L');
	assert.strictEqual(qr.getModuleCount(), 85);
	/* Around the M limit (504 bytes at version 17). */
	const atM = 'a'.repeat(504);
	assert.ok(sameMatrix(PD.calendarQrCode(atM), qrAt(PD, atM, 'M')));
	const overM = 'a'.repeat(505);
	assert.ok(sameMatrix(PD.calendarQrCode(overM), qrAt(PD, overM, 'L')));
	assert.ok(PD.calendarQrCode(overM).getModuleCount() <= 85);
});

test('QR: a large real plan (notes dropped) still prints a QR within the box', () => {
	const items = [];
	for (let i = 1; i <= 9; i++) {
		items.push(med('MEDICINE NUMBER ' + i, '12h', 30, { notes: 'Μετά το φαγητό με ένα ποτήρι νερό ' + i }));
	}
	const { PD } = plan(items, { config: { calendarUrl: CAL_URL } });
	PD.beginDayPass();
	const link = PD.calendarLink();
	assert.ok(link, 'a QR is still printed');
	assert.ok(link.length > 504 && link.length <= PD.CAL_MAX_LINK, 'too long for M: ' + link.length);
	/* The existing fallback still ran first (notes left out). */
	assert.match(PD.calendarQrNotice(), /χωρίς σημειώσεις/);
	const qr = PD.calendarQrCode(link);
	assert.ok(qr.getModuleCount() <= PD.CAL_QR_MAX_MODULES);
	assert.ok(sameMatrix(qr, qrAt(PD, link, 'L')), 'long link: level L');
	assert.match(PD.calendarQrHtml(), /pd-cal-qr"><svg role="img"/);
});
