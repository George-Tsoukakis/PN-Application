/* «Υπενθυμίσεις στο κινητό» QR:
   - a plan over the patient page's limits (41 medicines, 401 days) was
     left off the sheet without a word to the pharmacist:
     the preview now says the sheet prints without the QR;
   - the patient page counted a name's length in UTF-16 units while the
     sheet clips it by characters, so a name full of emoji printed a QR the
     phone refused. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { load, closeAll, isoAhead } = require('./harness');
const envLib = require('./lib/env.js');

test.afterEach(closeAll);

const PUBLIC = path.join(envLib.pluginDir(), 'public');
const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.30.0';
const TOO_BIG = /πολύ μεγάλο[^]*χωρίς QR/;

function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}

function qrSetup(items) {
	const e = load({ config: { calendarUrl: CAL_URL } });
	e.PD.s.startDate = isoAhead(0);
	e.PD.s.firstSlot = 'morning';
	e.PD.s.items = items;
	e.PD.s.header = { name: 'Φαρμακείο' };
	e.PD.beginDayPass();
	const area = e.w.document.createElement('div');
	area.id = 'pd-preview-area';
	e.w.document.body.appendChild(area);
	return e;
}

function patientPage(hash) {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8')
		.replace(/<script src="calendar\.js[^"]*"><\/script>/, '')
		.replace(/<link[^>]*calendar\.css[^>]*>/, '');
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://pharmacy.test/calendar.html' + (hash ? '#' + hash : '') });
	dom.window.eval(fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8'));
	return dom;
}

test('QR: 41 medicines → no QR, and the preview says the sheet prints without it', () => {
	const items = [];
	for (let i = 1; i <= 41; i++) {
		items.push(med('M' + i, '24h', 1));
	}
	const { PD, w } = qrSetup(items);
	assert.strictEqual(PD.calendarPayload(), null, 'over the patient page limit of 40');
	assert.strictEqual(PD.calendarLink(), '');
	assert.strictEqual(PD.calLastInfo && PD.calLastInfo.state, 'tooBig');
	assert.match(PD.calendarQrNotice(), TOO_BIG);
	PD.renderPreview();
	const shown = w.document.querySelector('#pd-preview-area .pd-preview-notice');
	assert.ok(shown, 'notice above the preview');
	assert.match(shown.textContent, TOO_BIG);
	assert.ok(!PD.buildPrintHtml().includes('pd-cal-box'), 'the sheet has no QR');
	assert.ok(!PD.buildPrintHtml().includes(shown.textContent), 'the notice is never printed');
});

test('QR: 40 medicines is still within the limit (no false «too big» from the count)', () => {
	const items = [];
	for (let i = 1; i <= 40; i++) {
		items.push(med('M' + i, '24h', 1));
	}
	assert.ok(qrSetup(items).PD.calendarPayload(), 'a payload is built');
});

test('QR: more than 400 days → the same notice', () => {
	const { PD } = qrSetup([med('A', '24h', 401)]);
	assert.strictEqual(PD.calendarLink(), '');
	assert.strictEqual(PD.calLastInfo && PD.calLastInfo.state, 'tooBig');
	assert.match(PD.calendarQrNotice(), TOO_BIG);
});

test('QR: nothing to plan is not «too big» — no notice', () => {
	const { PD } = qrSetup([]);
	PD.calLastInfo = null;
	assert.strictEqual(PD.calendarLink(), '');
	assert.strictEqual(PD.calLastInfo, null);
	assert.strictEqual(PD.calendarQrNotice(), '');
});

test('patient page: a medicine name of 60 emoji, clipped by the sheet, is read by the phone', () => {
	const name = '💊'.repeat(70);
	const { PD } = qrSetup([med(name, '24h', 3)]);
	const link = PD.calendarLink();
	assert.ok(link, 'a QR is made');
	const frag = link.slice(link.indexOf('#') + 1);
	const dom = patientPage(frag);
	const plan = dom.window.PlanDoseCalendar.decode('#' + frag);
	assert.ok(plan, 'decoded (it was refused: 60 emoji are 120 UTF-16 units > 100)');
	assert.strictEqual(Array.from(plan.meds[0][0]).length, 60);
	assert.strictEqual(plan.meds[0][0], '💊'.repeat(59) + '…');
	dom.window.close();
});

test('patient page: the limits are still enforced, by characters', () => {
	const C = patientPage('').window.PlanDoseCalendar;
	const { PD } = qrSetup([]);
	const plan = (name) => '#' + PD.calEncode({ v: 1, l: 'el', s: '2026-10-01', n: 3, f: '', m: [[name, '1', '']], t: [[0, 0, [7]]] });
	assert.ok(C.decode(plan('Α'.repeat(100))), '100 characters: accepted');
	assert.strictEqual(C.decode(plan('Α'.repeat(101))), null, '101 characters: refused');
	assert.ok(C.decode(plan('😀'.repeat(60))), '60 emoji (120 UTF-16 units): accepted');
});

test('patient page: cache-busting follows the changed calendar.js', () => {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8');
	/* The plugin version: bumped with every release that changes the page. */
	const version = /define\( 'PLANDOSE_VERSION', '([^']+)' \)/.exec(fs.readFileSync(path.join(PUBLIC, '..', 'plandose.php'), 'utf8'))[1];
	assert.ok(html.includes('<script src="calendar.js?v=' + version + '"></script>'), 'calendar.js?v=' + version);
	assert.ok(html.includes('<link rel="stylesheet" href="calendar.css?v=' + version + '">'), 'calendar.css?v=' + version);
});
