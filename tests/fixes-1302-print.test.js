/* 1.30.2: print / QR / patient page fixes from the 1.30.1 review.
   - A long dose was cut to 30 characters in the phone QR without a word:
     the dose now gets the patient page's whole limit (60), and anything
     still shortened (name, dose, notes) is named to the pharmacist above
     the preview.
   - The dose on the A4 day cards could be clipped by the card
     (nowrap inside overflow:hidden): it now wraps.
   - Patient page: PRODID no longer carries a stale version; a cleared
     time shows the default it falls back to; only the status line is a
     live region; the iPhone help covers the download case. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const env = require('./lib/env.js');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const PUBLIC = path.join(env.pluginDir(), 'public');
const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.30.2';
const CLIPPED = /κόβονται με «…»/;

function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}

function qrSetup(items, doseText) {
	const e = load({ config: { calendarUrl: CAL_URL } });
	e.PD.s.startDate = isoAhead(1);
	e.PD.s.firstSlot = 'morning';
	e.PD.s.items = items;
	e.PD.s.header = { name: 'Φαρμακείο' };
	if (doseText) {
		e.PD.doseAmountText = () => doseText;
	}
	e.PD.beginDayPass();
	return e;
}

function patientPage(link) {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8')
		.replace(/<script src="calendar\.js[^"]*"><\/script>/, '')
		.replace(/<link[^>]*calendar\.css[^>]*>/, '');
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: CAL_URL + '#' + link.slice(link.indexOf('#') + 1) });
	dom.window.eval(fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8'));
	return dom;
}

/* ---- 1. dose / name shortened in the QR ---------------------------------- */

test('QR: a 45-character dose goes to the phone in full, with no notice', () => {
	const dose = '1 Δισκίο διαλυμένο σε μισό ποτήρι νερό αμέσως';
	assert.ok(Array.from(dose).length > 30 && Array.from(dose).length <= 60);
	const { PD } = qrSetup([med('ALPHA', '24h', 5)], dose);
	assert.strictEqual(PD.calendarPayload().m[0][1], dose);
	assert.strictEqual(PD.calendarQrNotice(), '');
	const dom = patientPage(PD.calendarLink());
	assert.strictEqual(dom.window.document.querySelector('.med-dose').textContent, dose, 'the patient page accepts it');
});

test('QR: a dose over the patient page limit is shortened AND the pharmacist is told', () => {
	const dose = 'Δ'.repeat(70);
	const { PD } = qrSetup([med('ALPHA', '24h', 5), med('BETA', '24h', 5)], null);
	PD.doseAmountText = (item) => ('ALPHA' === item.name ? dose : '1 Δισκίο');
	const sent = PD.calendarPayload().m[0][1];
	assert.strictEqual(Array.from(sent).length, 60);
	assert.ok(sent.endsWith('…'));
	const notice = PD.calendarQrNotice();
	assert.match(notice, CLIPPED);
	assert.match(notice, /ALPHA/);
	assert.doesNotMatch(notice, /BETA/, 'only the shortened medicine is named');
	assert.ok(!PD.buildPrintHtml().includes('κόβονται'), 'never printed');
	/* And the phone still reads the plan. */
	const dom = patientPage(PD.calendarLink());
	assert.strictEqual(dom.window.document.querySelector('.med-dose').textContent, sent);
});

test('QR: a name over 60 characters is named in the notice too', () => {
	const name = 'ΦΑΡΜΑΚΟ ' + 'Α'.repeat(70);
	const { PD } = qrSetup([med(name, '24h', 3)]);
	assert.ok(PD.calendarPayload().m[0][0].endsWith('…'));
	assert.match(PD.calendarQrNotice(), CLIPPED);
});

test('QR: the notice for a shortened dose is shown in the preview', () => {
	const { PD, w } = qrSetup([med('ALPHA', '24h', 5)], 'Δ'.repeat(70));
	const area = w.document.createElement('div');
	area.id = 'pd-preview-area';
	w.document.body.appendChild(area);
	PD.renderPreview();
	const shown = w.document.querySelector('#pd-preview-area .pd-preview-notice');
	assert.ok(shown, 'notice above the preview');
	assert.match(shown.textContent, CLIPPED);
});

/* ---- 2. the dose on the day cards is never clipped ------------------------ */

test('A4: the dose on a day card may wrap (the card clips overflow)', () => {
	const { PD } = load();
	const css = PD.PRINT_STYLES;
	const rule = /\.pd-day-dose-amount\s*\{([^}]*)\}/.exec(css);
	assert.ok(rule, 'rule found');
	assert.doesNotMatch(rule[1], /white-space:\s*nowrap/);
	assert.match(rule[1], /overflow-wrap:\s*break-word/);
	assert.match(rule[1], /flex:\s*0 1 auto/, 'it may shrink (and wrap) instead of overflowing');
	assert.match(rule[1], /max-width:\s*60%/);
	assert.match(css, /\.pd-day-card \{[^}]*overflow: hidden/, 'the reason: the card clips');
});

/* ---- 3. / 4. / 5. / 6. patient page --------------------------------------- */

function simplePage() {
	const { PD } = qrSetup([med('ALPHA', '24h', 5)]);
	return patientPage(PD.calendarLink());
}

test('patient page: PRODID carries no (stale) version', () => {
	const dom = simplePage();
	const cal = dom.window.PlanDoseCalendar;
	const plan = cal.decode(dom.window.location.hash);
	const ics = cal.buildIcs(plan, cal.DEFAULT_TIME, false, new Date(Date.UTC(2026, 0, 1)));
	assert.match(ics, /\r\nPRODID:-\/\/PlanDose\/\/Calendar\/\/EL\r\n/);
	assert.doesNotMatch(ics, /PRODID:[^\r]*\d+\.\d+\.\d+/);
});

test('patient page: a cleared time shows the default it falls back to', () => {
	const dom = simplePage();
	const d = dom.window.document;
	const input = d.getElementById('pd-t-m');
	assert.ok(input);
	input.value = '07:30';
	input.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.strictEqual(input.value, '07:30');
	input.value = '';
	input.dispatchEvent(new dom.window.FocusEvent('blur'));
	assert.strictEqual(input.value, '08:00', 'the default is back in the field');
	assert.match(d.querySelector('.gcal-list a').textContent, /08:00/, 'and is what the reminders use');
});

test('patient page: only the status line is a live region, filled on «Add»', () => {
	const dom = simplePage();
	const d = dom.window.document;
	assert.strictEqual(d.getElementById('app').getAttribute('aria-live'), null, 'no live region over the whole page');
	const done = d.querySelector('.done');
	assert.strictEqual(done.getAttribute('role'), 'status');
	assert.strictEqual(done.textContent, '');
	assert.ok(!done.hidden, 'present before its text changes');
	dom.window.URL.createObjectURL = () => 'blob:x';
	dom.window.URL.revokeObjectURL = () => {};
	d.querySelector('button.btn').click();
	assert.match(done.textContent, /plano-farmakon\.ics/);
});

test('patient page: the iPhone help covers Calendar opening AND the downloaded file', () => {
	const T = simplePage().window.PlanDoseCalendar.TEXT;
	assert.match(T.el.addHelp, /Ημερολόγιο[^]*Λήψεις[^]*«Αρχεία»/);
	assert.match(T.en.addHelp, /Calendar opens[^]*Downloads[^]*Files/);
	const dom = simplePage();
	const help = Array.from(dom.window.document.querySelectorAll('p.muted')).map((p) => p.textContent).join('\n');
	assert.match(help, /plano-farmakon\.ics/, 'the file is named, no «%s» left');
	assert.doesNotMatch(help, /%s/);
});

/* ---- 8. fitLabel() measures each label content once ------------------------ */

test('labels: fitLabel() is memoised by content, and measures again on any change', () => {
	const { PD, w } = load();
	let measures = 0;
	const host = {
		html: '',
		set innerHTML(v) { this.html = v; },
		get innerHTML() { return this.html; },
		querySelector() {
			measures++;
			const doc = new w.DOMParser().parseFromString(host.html, 'text/html');
			const notes = doc.querySelector('.pd-lbl-notes .pd-lbl-val');
			return { clientHeight: 100, style: {}, offsetHeight: notes ? Array.from(notes.textContent).length : 0 };
		}
	};
	PD.labelMeasureHost = () => host;
	PD.labelSize = () => ({ id: 'custom', w: 100, h: 50 });
	const size = PD.labelSize();
	const item = med('Ciproxin', '12h', 7, { notes: 'Μετά το φαγητό' });
	const a = PD.fitLabel(item, 'Γ. Παπαδόπουλος', size);
	const first = measures;
	assert.ok(first > 0);
	a.opts.mutated = true;
	a.trimmed.push('x');
	const b = PD.fitLabel(item, 'Γ. Παπαδόπουλος', size);
	assert.strictEqual(measures, first, 'same content: no new layout');
	assert.deepStrictEqual(b, PD.fitLabel(item, 'Γ. Παπαδόπουλος', size));
	assert.ok(!b.opts.mutated && -1 === b.trimmed.indexOf('x'), 'callers get a copy');
	PD.fitLabel(Object.assign({}, item, { notes: 'Μετά το φαγητό, ΟΧΙ με γάλα' }), 'Γ. Παπαδόπουλος', size);
	assert.ok(measures > first, 'other notes: measured again');
	const n = measures;
	PD.fitLabel(item, 'Άλλος', size);
	assert.ok(measures > n, 'other patient: measured again');
	const m = measures;
	PD.fitLabel(item, 'Γ. Παπαδόπουλος', { id: 'custom', w: 60, h: 30 });
	assert.ok(measures > m, 'other size: measured again');
});
