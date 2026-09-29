/* 1.28.2: patient-safety fixes from the 1.28.1 review.
   - the same medicine added twice through the form needs a second press;
   - the same once-a-week medicine in two plan entries (each weekly on its
     own) asks again before printing — methotrexate and the others;
   - a prescription dose in tablets for a box of capsules (or the reverse)
     is never planned silently;
   - mg doses take three decimals (0,125 mg) and are never shown rounded. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, isoAhead, closeAll } = require('./harness');

test.after(closeAll);

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

function fill(PD, name, amount, days, unit) {
	PD.setFieldValue('pd-drug', name);
	PD.setFieldValue('pd-dose-amount', amount);
	PD.setFieldValue('pd-days', days);
	if (unit) {
		PD.setFieldValue('pd-dose-unit', unit);
	}
}

function weekly(name, weekday) {
	return {
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: 'custom', dailyTime: 'morning',
		customMode: 'weekday', customIntervalDays: '', customWeekday: weekday, customMonthDay: null,
		days: '28', notes: ''
	};
}

/* ---- duplicate medicine through the form ------------------------------ */

test('form: the same medicine a second time is added only on a second press', () => {
	const { PD, w } = app();
	const msg = () => w.document.getElementById('pd-message').textContent;
	fill(PD, 'Augmentin 1g', '1', '7');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);

	/* Same name, other case and accents/spaces: still the same medicine. */
	fill(PD, '  AUGMENTIN   1G ', '1', '7');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'first press: warning only');
	assert.match(msg(), /υπάρχει ήδη στο πλάνο/);

	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 2, 'second press adds it');
	assert.strictEqual(PD.s.duplicateAck, '', 'the confirmation does not carry over');
});

test('form: another strength is another medicine; editing an entry is not a duplicate of itself', () => {
	const { PD } = app();
	fill(PD, 'SINTROM 1MG', '1', '5');
	PD.addOrUpdateItem();
	fill(PD, 'SINTROM 4MG', '1', '5');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 2);

	PD.editItem(0);
	PD.setFieldValue('pd-days', '6');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 2);
	assert.strictEqual(String(PD.s.items[0].days), '6', 'saved without a duplicate question');
});

test('«Νέος ασθενής» forgets a duplicate confirmation', () => {
	const { PD } = app();
	PD.s.duplicateAck = 'X';
	PD.resetPlanState();
	assert.strictEqual(PD.s.duplicateAck, '');
});

/* ---- once-a-week medicine in more than one entry ----------------------- */

test('methotrexate on Monday and on Thursday: printing asks again', () => {
	const { PD, w } = app();
	PD.s.items = [weekly('METHOTREXATE 2.5MG', 1), weekly('METHOTREXATE 2.5MG', 4)];
	/* Each entry is weekly on its own. */
	assert.strictEqual(PD.methotrexateTooOften(PD.s.items[0]), false);
	assert.strictEqual(PD.methotrexateTooOften(PD.s.items[1]), false);

	assert.strictEqual(PD.methotrexateNeedsConfirm(PD.s.items), true, 'first press stops');
	assert.match(w.document.getElementById('pd-message').textContent, /μεθοτρεξάτη σε περισσότερες από μία εγγραφές/);
	assert.strictEqual(PD.methotrexateNeedsConfirm(PD.s.items), false, 'second press with the same plan prints');

	/* Moving one entry to another day is a new question. */
	PD.s.items[1].customWeekday = 5;
	assert.strictEqual(PD.methotrexateNeedsConfirm(PD.s.items), true);
});

test('two methotrexate brands count as one medicine', () => {
	const { PD } = app();
	const rep = PD.weeklyRepeatedItems([weekly('METOJECT 15MG PEN', 1), weekly('Μεθοτρεξάτη 2,5mg', 3)]);
	assert.strictEqual(rep.mtx.length, 2);
});

test('one weekly methotrexate entry prints without a question', () => {
	const { PD } = app();
	assert.strictEqual(PD.methotrexateNeedsConfirm([weekly('METHOTREXATE 2.5MG', 1), weekly('DEPON 500MG', 1)]), false);
});

test('OZEMPIC in two entries (different strengths) asks again', () => {
	const { PD, w } = app();
	const items = [weekly('OZEMPIC 0.25MG INJ', 1), weekly('OZEMPIC 1MG INJ', 4)];
	assert.strictEqual(PD.weeklyRepeatedItems(items).weekly.length, 2);
	assert.strictEqual(PD.methotrexateNeedsConfirm(items), true);
	assert.match(w.document.getElementById('pd-message').textContent, /σε περισσότερες από μία εγγραφές/);
});

test('the print check itself stops on the repeated entries', () => {
	const { PD } = app();
	PD.s.items = [weekly('METHOTREXATE 2.5MG', 1), weekly('METHOTREXATE 2.5MG', 4)];
	assert.strictEqual(PD.validatePlanForPrint(), false);
	assert.strictEqual(PD.validatePlanForPrint(), true);
});

/* ---- tablets / capsules in a pasted prescription ----------------------- */

const HEAD = 'ΣΤΟΙΧΕΙΑ ΙΑΤΡΟΥ ΣΤΟΙΧΕΙΑ ΑΣΘΕΝΗ\nΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΔΟΚΙΜΑΣΤΙΚΟΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\n';

test('prescription: «1 ΔΙΣΚΙΑ» for a box of capsules is not planned silently', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const it = PD.parsePrescription(HEAD + 'AMOXIL CAPS 500MG BTx12\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 7 ημέρες\n').items[0];
	assert.ok(it.warnings.includes('unitForm'), JSON.stringify(it.warnings));
	assert.notStrictEqual(it.doseUnit, 'tablet');
});

test('prescription: «1 ΚΑΨΟΥΛΑ» for a box of tablets is not planned silently', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const it = PD.parsePrescription(HEAD + 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΚΑΨΟΥΛΑ x 2 φορές την ημέρα x 7 ημέρες\n').items[0];
	assert.ok(it.warnings.includes('unitForm'), JSON.stringify(it.warnings));
});

test('prescription: matching forms still read as before', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const caps = PD.parsePrescription(HEAD + 'AMOXIL CAPS 500MG BTx12\nΔΟΣΟΛΟΓΙΑ : 1 ΚΑΨΟΥΛΑ x 3 φορές την ημέρα x 7 ημέρες\n').items[0];
	assert.strictEqual(caps.doseUnit, 'capsule');
	assert.ok(!caps.warnings.includes('unitForm'));
	const tab = PD.parsePrescription(HEAD + 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n').items[0];
	assert.strictEqual(tab.doseUnit, 'tablet');
	assert.ok(!tab.warnings.includes('unitForm'));
});

/* ---- three decimals for mg --------------------------------------------- */

test('mg takes three decimals; the other units keep two', () => {
	const { PD } = load();
	const v = (raw, unit) => JSON.parse(JSON.stringify(PD.checkDoseAmount(raw, unit)));
	assert.deepStrictEqual(v('0,125', 'mg'), { value: 0.125, error: '' });
	assert.deepStrictEqual(v('0.025', 'mg'), { value: 0.025, error: '' });
	assert.strictEqual(v('0,1255', 'mg').error, 'decimals');
	assert.strictEqual(v('0,125', 'tablet').error, 'decimals');
	assert.strictEqual(v('0,125', 'ml').error, 'decimals');
	/* «1.000» / «1,125» stay ambiguous (thousands) in every unit. */
	assert.strictEqual(v('1,125', 'mg').error, 'thousands');
	assert.strictEqual(v('1.000', 'mg').error, 'thousands');
	assert.match(PD.doseAmountMessage('decimals', '0,1255', 'X', 'mg'), /3 δεκαδικά/);
	assert.match(PD.doseAmountMessage('decimals', '0,125', 'X', 'tablet'), /2 δεκαδικά/);
});

test('0,125 mg is shown as 0,125 (never rounded to 0,13); two-decimal values print as before', () => {
	const { PD } = load();
	assert.strictEqual(PD.formatDecimal(0.125), '0,125');
	assert.strictEqual(PD.formatDecimal(0.25), '0,25');
	assert.strictEqual(PD.formatDecimal(1.5), '1,5');
	assert.strictEqual(PD.formatDecimal(2), '2');
	assert.strictEqual(PD.formatDecimal(1.005), '1,005');
	assert.strictEqual(PD.formatDecimal(10), '10');
});

test('form → plan → sheet: DIGOXIN 0,125 mg', () => {
	const { PD } = app();
	fill(PD, 'DIGOXIN', '0,125', '7', 'mg');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	assert.strictEqual(PD.s.items[0].doseAmount, 0.125);
	const html = PD.buildPrintHtml();
	assert.ok(html.includes('0,125'), 'the sheet shows 0,125');
	assert.ok(!/0,13\b/.test(html), 'never 0,13');
	/* Totals: 7 days × 2 a day (the form's default) = 14 × 0,125 = 1,75;
	   once a day for 7 days = 0,875 (0,88 before 1.28.2). */
	PD.beginDayPass();
	assert.strictEqual(PD.doseTotals(PD.s.items[0]).units, 1.75);
	const once = Object.assign({}, PD.s.items[0], { freq: '24h', dailyTime: 'morning' });
	assert.strictEqual(PD.doseTotals(once).units, 0.875);
});

/* ---- patient calendar page -------------------------------------------- */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const envLib = require('./lib/env.js');
const PUBLIC = path.join(envLib.pluginDir(), 'public');

function patientPage(hash) {
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8')
		.replace(/<script src="calendar\.js[^"]*"><\/script>/, '')
		.replace(/<link[^>]*calendar\.css[^>]*>/, '');
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://pharmacy.test/calendar.html' + (hash ? '#' + hash : '') });
	dom.window.eval(fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8'));
	return dom;
}

function qrPlan(items) {
	const e = load({ config: { calendarUrl: 'https://pharmacy.test/calendar.html' } });
	e.PD.s.startDate = isoAhead(1);
	e.PD.s.items = items;
	e.PD.s.header = { name: 'Φαρμακείο' };
	e.PD.beginDayPass();
	const link = e.PD.calendarLink();
	return link.slice(link.indexOf('#') + 1);
}

const tab = (name, freq, days) => ({ name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: String(days), notes: '' });
const uids = (ics) => ics.replace(/\r\n /g, '').split('\r\n').filter((l) => l.startsWith('UID:'));

test('calendar: the same QR added twice gives the same UIDs (the phone updates, no double reminders)', () => {
	const frag = qrPlan([tab('ΑΝΤΙΒΙΟΤΙΚΟ', '12h', 7), tab('Β', '24h', 10)]);
	const a = patientPage(frag);
	const b = patientPage(frag);
	const Ca = a.window.PlanDoseCalendar;
	const Cb = b.window.PlanDoseCalendar;
	const icsA = Ca.buildIcs(Ca.decode('#' + frag), Ca.DEFAULT_TIME, false, new Date('2026-09-27T10:00:00Z'));
	/* Other times, neutral titles, another moment: still the same events. */
	const icsB = Cb.buildIcs(Cb.decode('#' + frag), Object.assign({}, Cb.DEFAULT_TIME, { m: '07:30' }), true, new Date('2026-09-28T18:00:00Z'));
	assert.ok(uids(icsA).length >= 2);
	assert.deepStrictEqual(uids(icsA), uids(icsB));
	assert.strictEqual(new Set(uids(icsA)).size, uids(icsA).length, 'unique within the file');
	a.window.close();
	b.window.close();
});

test('calendar: another plan gives other UIDs', () => {
	const f1 = qrPlan([tab('Α', '12h', 7)]);
	const f2 = qrPlan([tab('Α', '12h', 8)]);
	const C = patientPage(f1).window.PlanDoseCalendar;
	const u1 = uids(C.buildIcs(C.decode('#' + f1), C.DEFAULT_TIME, false));
	const u2 = uids(C.buildIcs(C.decode('#' + f2), C.DEFAULT_TIME, false));
	assert.notDeepStrictEqual(u1, u2);
});

test('calendar: a bidi override or zero-width mark in a link is refused; the sheet never puts one in', () => {
	const e = load({ config: { calendarUrl: 'https://pharmacy.test/calendar.html' } });
	const PD = e.PD;
	const C = patientPage('').window.PlanDoseCalendar;
	const plan = (name) => '#' + PD.calEncode({ v: 1, l: 'el', s: '2026-10-01', n: 3, f: '', m: [[name, '1', '']], t: [[0, 0, [7]]] });
	assert.ok(C.decode(plan('DEPON')));
	/* Hand-built bytes with U+202E inside the name (0x7F + 3 bytes). */
	const good = [...Buffer.from(plan('DEPON').slice(1).replace(/-/g, '+').replace(/_/g, '/'), 'base64')];
	const at = good.indexOf(0x44); /* 'D' */
	const bad = good.slice(0, at - 1).concat([good[at - 1] + 4, 0x7f, 0x00, 0x20, 0x2e]).concat(good.slice(at));
	const b64 = '#' + Buffer.from(bad).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	assert.strictEqual(C.decode(b64), null, 'U+202E refused');
	/* The same bytes with an ordinary character (é) are read: the
	   refusal is about the character, not the layout. */
	const ok = bad.slice(); ok[at + 2] = 0x00; ok[at + 3] = 0xe9;
	const okD = C.decode('#' + Buffer.from(ok).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''));
	assert.ok(okD && okD.meds[0][0] === 'éDEPON', JSON.stringify(okD && okD.meds));
	/* The encoder drops them, so a name pasted with invisible marks still
	   gives a QR the page reads. */
	const d = C.decode(plan('DE​PON‮ 1'));
	assert.ok(d, 'readable');
	assert.strictEqual(d.meds[0][0], 'DEPON 1');
});

/* ---- patient name: debounced preview, flushed before any action -------- */

test('typing the patient name updates the preview after a pause, and at once before a step change', async () => {
	const { PD, w } = app();
	const d = w.document;
	PD.loadHeader = function () {};
	PD.s.items = [{ name: 'DEPON', doseAmount: 1, doseUnit: 'tablet', freq: '12h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: '5', notes: '' }];
	PD.renderPreview();
	const el = d.getElementById('pd-patient');
	const area = () => d.getElementById('pd-preview-area').innerHTML;
	el.value = 'ΓΙΑΝΝΗΣ';
	el.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.ok(!area().includes('ΓΙΑΝΝΗΣ'), 'not on every key');
	await new Promise((r) => setTimeout(r, PD.PATIENT_RENDER_DELAY_MS + 50));
	assert.ok(area().includes('ΓΙΑΝΝΗΣ'), 'after the pause');

	el.value = 'ΜΑΡΙΑ';
	el.dispatchEvent(new w.Event('input', { bubbles: true }));
	PD.nextStep();
	assert.ok(area().includes('ΜΑΡΙΑ'), 'a step change flushes at once');
	/* Printing reads the field itself: the sheet has the current name. */
	assert.ok(PD.buildPrintHtml().includes('ΜΑΡΙΑ'));
});

/* ---- trigger: aria-expanded -------------------------------------------- */

test('the trigger reports the dialog open / closed (aria-expanded)', () => {
	const { PD, w } = app();
	const trigger = w.document.getElementById('plandose-trigger');
	PD.refreshNonce = function () { return new w.Promise(function () {}); };
	PD.startNonceRefresh = function () {};
	PD.loadHeader = function () {};
	PD.openModal();
	assert.strictEqual(trigger.getAttribute('aria-expanded'), 'true');
	PD.closeModal();
	assert.strictEqual(trigger.getAttribute('aria-expanded'), 'false');
});

/* ---- «Βρέθηκε 1 φάρμακο» ------------------------------------------------ */

test('one medicine found: singular wording', () => {
	const { PD, w } = app();
	const drop = w.document.getElementById('pd-rx-drop');
	drop.value = HEAD + 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
	drop.dispatchEvent(new w.Event('input', { bubbles: true }));
	const found = w.document.querySelector('.pd-rx-found');
	assert.ok(found, 'review shown');
	assert.match(found.textContent, /Βρέθηκε 1 φάρμακο/);
});

/* ---- review follow-ups -------------------------------------------------- */

test('mg: a third decimal only below 1 — «1 1/8» mg (1,125) is refused, as in 1.28.1', () => {
	const { PD } = load();
	assert.strictEqual(PD.checkDoseAmount('1 1/8', 'mg').error, 'decimals');
	assert.strictEqual(PD.checkDoseAmount('9/8', 'mg').error, 'decimals');
	assert.ok(PD.checkDoseAmount(1.125, 'mg').error, 'a stored number is refused too');
	assert.strictEqual(PD.checkDoseAmount('1/8', 'mg').value, 0.125);
	/* A saved 0,125 mg reads back and edits back unchanged. */
	assert.strictEqual(PD.checkDoseAmount(0.125, 'mg').value, 0.125);
	assert.strictEqual(PD.formatDose(0.125), '0,125');
	assert.strictEqual(PD.checkDoseAmount(PD.formatDose(0.125), 'mg').value, 0.125);
});

test('methotrexate too often AND another weekly medicine repeated: both named in one message', () => {
	const { PD, w } = app();
	const daily = { name: 'METHOTREXATE 2.5MG', doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: '7', notes: '' };
	PD.methotrexateNeedsConfirm([daily, weekly('OZEMPIC 0.25MG INJ', 1), weekly('OZEMPIC 1MG INJ', 4)]);
	const text = w.document.getElementById('pd-message').textContent;
	assert.match(text, /μεθοτρεξάτη συχνότερα/);
	assert.match(text, /OZEMPIC 0\.25MG INJ/);
});

test('calendar: every event carries a SEQUENCE that grows with each import', () => {
	const frag = qrPlan([tab('Α', '12h', 3)]);
	const C = patientPage(frag).window.PlanDoseCalendar;
	const plan = C.decode('#' + frag);
	const seq = (d) => C.buildIcs(plan, C.DEFAULT_TIME, false, d).replace(/\r\n /g, '').split('\r\n').filter((l) => l.startsWith('SEQUENCE:')).map((l) => +l.slice(9));
	const a = seq(new Date('2026-09-27T10:00:00Z'));
	const b = seq(new Date('2026-09-28T10:00:00Z'));
	assert.ok(a.length > 0 && a.every((n) => n > 0));
	assert.ok(b[0] > a[0]);
});
