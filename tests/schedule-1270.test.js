/* 1.27.0: real monthly recurrence («Ημέρα του μήνα»), an explicit weekday,
   the interval labels and the dose dates shown before «Προσθήκη»
   (review items 11–14). Greek time zone, so the DST change at the end of
   March and October is crossed for real. */
'use strict';
process.env.TZ = 'Europe/Athens';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const base = { name: 'M', doseAmount: 1, doseUnit: 'tablet', freq: 'custom', dailyTime: 'morning', customMode: 'monthday', customIntervalDays: '', customWeekday: null, customMonthDay: 31, days: '60', notes: '' };
const monthly = (over) => Object.assign({}, base, over);

/* Day zero pinned to a date (as the label print does for a printed plan). */
function pinned(y, m, d) {
	const env = load();
	env.PD.s.dayPassFrozen = true;
	env.PD.s.planDayZero = new Date(y, m, d).getTime();
	env.PD.s.planToday = env.PD.s.planDayZero;
	return env;
}

function doseDates(PD, item) {
	return Array.from(PD.doseDayOffsets(item), (o) => {
		const d = PD.dayAt(o);
		return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
	});
}

test('monthDoseDay: the chosen day, or the last day of a shorter month', () => {
	const { PD } = load();
	assert.strictEqual(PD.monthDoseDay(new Date(2027, 1, 10), 31), 28);
	assert.strictEqual(PD.monthDoseDay(new Date(2028, 1, 10), 31), 29, 'leap year');
	assert.strictEqual(PD.monthDoseDay(new Date(2027, 1, 10), 29), 28);
	assert.strictEqual(PD.monthDoseDay(new Date(2027, 3, 10), 31), 30);
	assert.strictEqual(PD.monthDoseDay(new Date(2027, 0, 10), 31), 31);
	assert.strictEqual(PD.monthDoseDay(new Date(2027, 3, 10), 15), 15);
});

test('monthly on the 31st: 31 Jan, 28 Feb, 31 Mar (across the DST change), 30 Apr', () => {
	const { PD } = pinned(2027, 0, 15);
	const it = monthly({ customMonthDay: 31, days: '110' });
	assert.deepStrictEqual(doseDates(PD, it), ['2027-1-31', '2027-2-28', '2027-3-31', '2027-4-30']);
	assert.strictEqual(PD.doseTotals(it).doses, 4);
	assert.strictEqual(PD.doseTotals(it).units, 4);
});

test('monthly on the 15th across the October DST change; a leap February for the 30th', () => {
	let env = pinned(2027, 9, 1);
	assert.deepStrictEqual(doseDates(env.PD, monthly({ customMonthDay: 15, days: '62' })), ['2027-10-15', '2027-11-15']);
	closeAll();
	env = pinned(2028, 0, 20);
	assert.deepStrictEqual(doseDates(env.PD, monthly({ customMonthDay: 30, days: '45' })), ['2028-1-30', '2028-2-29']);
});

test('monthly: the start day itself counts; no day chosen means no dose at all', () => {
	const { PD } = pinned(2027, 4, 5);
	assert.deepStrictEqual(doseDates(PD, monthly({ customMonthDay: 5, days: '30' })), ['2027-5-5']);
	assert.strictEqual(PD.doseTotals(monthly({ customMonthDay: null })).doses, 0);
	assert.strictEqual(PD.doseTotals(monthly({ customMonthDay: 'abc' })).doses, 0);
	assert.strictEqual(PD.doseTotals(monthly({ customMonthDay: 5, days: '0' })), null);
});

test('monthly label states the last-day rule from the 29th on', () => {
	const { PD } = load();
	assert.strictEqual(PD.getFreqLabel(monthly({ customMonthDay: 5 })), 'Μία φορά τον μήνα, στις 5');
	assert.strictEqual(PD.getFreqLabel(monthly({ customMonthDay: 28 })), 'Μία φορά τον μήνα, στις 28');
	assert.strictEqual(PD.getFreqLabel(monthly({ customMonthDay: 29 })), 'Μία φορά τον μήνα, στις 29 (ή την τελευταία ημέρα του μήνα)');
	assert.strictEqual(PD.getFreqLabel(monthly({ customMonthDay: 31 })), 'Μία φορά τον μήνα, στις 31 (ή την τελευταία ημέρα του μήνα)');
	assert.match(PD.getFreqLabel(monthly({ customMonthDay: null })), /επιλέξτε ημέρα/);
});

test('the printed sheet carries the monthly label and one dose card per dose', () => {
	const env = load();
	const { PD } = env;
	PD.s.startDate = isoAhead(0);
	PD.s.items = [monthly({ customMonthDay: 31, days: '62' })];
	PD.beginDayPass();
	const html = PD.buildPrintHtml();
	assert.ok(html.includes('Μία φορά τον μήνα, στις 31 (ή την τελευταία ημέρα του μήνα)'));
	const doses = PD.doseTotals(PD.s.items[0]).doses;
	assert.ok(doses >= 2 && doses <= 3);
	assert.strictEqual((html.match(/class="pd-day-dose"/g) || []).length, doses);
});

/* (11) */
test('interval labels: «Κάθε ημέρα», and 30 days is «Κάθε 30 ημέρες», not a month', () => {
	const { PD } = load();
	assert.strictEqual(PD.intervalDaysLabel(1), 'Κάθε ημέρα');
	assert.strictEqual(PD.intervalDaysLabel(2), 'Κάθε 2 ημέρες');
	assert.strictEqual(PD.intervalDaysLabel(7), 'Μία φορά την εβδομάδα');
	assert.strictEqual(PD.intervalDaysLabel(30), 'Κάθε 30 ημέρες');
	const every30 = { freq: 'custom', customMode: 'days', customIntervalDays: '30' };
	assert.ok(!/μήνα/.test(PD.getFreqLabel(every30)));
});

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

test('the 30-day preset is no longer called «Μήνας»', () => {
	const { w } = app();
	const btn = w.document.querySelector('.pd-interval-preset[data-days="30"]');
	assert.strictEqual(btn.textContent, '30 ημ.');
	assert.ok(!w.document.body.textContent.includes('Μήνας (30)'));
});

/* (12) form */
test('form: «Ημέρα του μήνα» — refused until a day is chosen, rule shown from 29, then added', () => {
	const { PD, w } = app();
	const d = w.document;
	PD.setFieldValue('pd-drug', 'PROLIA');
	PD.setFieldValue('pd-days', '60');
	d.querySelector('.plandose-chip[data-val="custom"]').click();
	const chip = d.querySelector('.pd-custom-mode-chip[data-mode="monthday"]');
	assert.ok(chip, 'third mode chip');
	assert.match(chip.textContent, /Ημέρα του μήνα/);
	chip.click();
	assert.strictEqual(chip.getAttribute('aria-pressed'), 'true');
	assert.strictEqual(d.getElementById('pd-custom-monthday-wrap').hidden, false);
	assert.strictEqual(d.getElementById('pd-custom-days-wrap').hidden, true);
	assert.strictEqual(PD.s.currentMonthDay, null);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0, 'no day → not added');
	assert.match(d.getElementById('pd-message').textContent, /ημέρα του μήνα/);
	assert.strictEqual(d.getElementById('pd-custom-monthday').getAttribute('aria-invalid'), 'true');
	const input = d.getElementById('pd-custom-monthday');
	for (const bad of ['0', '32', '4.5']) {
		input.value = bad;
		input.dispatchEvent(new w.Event('input', { bubbles: true }));
		assert.strictEqual(PD.s.currentMonthDay, null, bad);
	}
	input.value = '15';
	input.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.strictEqual(PD.s.currentMonthDay, 15);
	assert.strictEqual(d.getElementById('pd-monthday-last').hidden, true);
	input.value = '31';
	input.dispatchEvent(new w.Event('input', { bubbles: true }));
	const rule = d.getElementById('pd-monthday-last');
	assert.strictEqual(rule.hidden, false);
	assert.match(rule.textContent, /τελευταία ημέρα/);
	assert.match(input.getAttribute('aria-describedby'), /pd-monthday-last/);
	/* The live total lists the dates. */
	const total = d.getElementById('pd-dose-total').textContent;
	assert.match(total, /Σύνολο: \d δόσ/);
	assert.match(total, /\d\d\/\d\d/);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	const it = PD.s.items[0];
	assert.strictEqual(it.customMode, 'monthday');
	assert.strictEqual(it.customMonthDay, 31);
	assert.strictEqual(PD.planItemInvalid(it), '');
	/* The form is reset: no day carried to the next medicine. */
	assert.strictEqual(PD.s.currentMonthDay, null);
	assert.strictEqual(d.getElementById('pd-custom-monthday').value, '');
	/* Editing brings the day back, and an unchanged save is unchanged. */
	PD.editItem(0);
	assert.strictEqual(PD.s.currentCustomMode, 'monthday');
	assert.strictEqual(d.getElementById('pd-custom-monthday').value, '31');
	assert.ok(PD.drugFormMatchesItem(it));
	d.getElementById('pd-custom-monthday').value = '30';
	d.getElementById('pd-custom-monthday').dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.ok(!PD.drugFormMatchesItem(it));
});

test('form: a typed day of the month counts as unsaved input', () => {
	const { PD, w } = app();
	const input = w.document.getElementById('pd-custom-monthday');
	input.value = '12';
	assert.strictEqual(PD.hasUnsavedDrugForm(), true);
});

test('form: the day of the month survives a language switch (Pro)', () => {
	const { PD, w } = app();
	PD.config.isAllowed = true;
	PD.overlay.hidden = false;
	const d = w.document;
	d.querySelector('.plandose-chip[data-val="custom"]').click();
	d.querySelector('.pd-custom-mode-chip[data-mode="monthday"]').click();
	const input = d.getElementById('pd-custom-monthday');
	input.value = '29';
	input.dispatchEvent(new w.Event('input', { bubbles: true }));
	PD.setLang('en');
	assert.strictEqual(d.getElementById('pd-custom-monthday').value, '29');
	assert.strictEqual(d.getElementById('pd-custom-monthday-wrap').hidden, false);
	assert.strictEqual(d.getElementById('pd-monthday-last').hidden, false);
});

/* (13) */
test('form: switching to «Ανά ημέρα εβδομάδας» preselects no day; add refuses until one is chosen', () => {
	const { PD, w } = app();
	const d = w.document;
	PD.setFieldValue('pd-drug', 'FOSAMAX');
	PD.setFieldValue('pd-days', '28');
	d.querySelector('.plandose-chip[data-val="custom"]').click();
	d.querySelector('.pd-custom-mode-chip[data-mode="weekday"]').click();
	assert.strictEqual(d.querySelectorAll('.pd-weekday-chip.active').length, 0);
	assert.strictEqual(d.querySelectorAll('.pd-weekday-chip[aria-pressed="true"]').length, 0);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0);
	assert.match(d.getElementById('pd-message').textContent, /ημέρα της εβδομάδας/);
	d.querySelector('.pd-weekday-chip[data-weekday="2"]').click();
	/* (14) the dates are shown before adding: 4 Tuesdays. */
	const total = d.getElementById('pd-dose-total').textContent;
	assert.match(total, /^Σύνολο: 4 δόσεις/);
	assert.strictEqual((total.match(/Τρί \d\d\/\d\d/g) || []).length, 4);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	assert.strictEqual(PD.s.items[0].customWeekday, 2);
	/* A saved item keeps its day when edited. */
	PD.editItem(0);
	assert.strictEqual(d.querySelector('.pd-weekday-chip.active').dataset.weekday, '2');
	PD.resetDrugForm();
	assert.strictEqual(PD.s.currentWeekday, null);
	assert.strictEqual(d.querySelectorAll('.pd-weekday-chip.active').length, 0);
});

/* (14) */
test('dose dates: more than six → the first three … the last', () => {
	const { PD } = pinned(2027, 5, 1);
	const it = { name: 'X', doseAmount: 1, doseUnit: 'tablet', freq: 'custom', customMode: 'days', customIntervalDays: '2', days: '20' };
	const text = PD.doseDatesText(it);
	const parts = text.split(' … ');
	assert.strictEqual(parts.length, 2, text);
	assert.strictEqual(parts[0].split(', ').length, 3);
	assert.strictEqual(parts[1], PD.shortDay(18));
	assert.strictEqual(PD.doseDatesText({ freq: '12h', days: '5' }), '');
	const four = PD.doseDatesText({ freq: 'custom', customMode: 'days', customIntervalDays: '7', days: '28' });
	assert.strictEqual(four.split(', ').length, 4);
});

test('form total says «1 δόση» in the singular', () => {
	const { PD, w } = app();
	const d = w.document;
	PD.setFieldValue('pd-days', '7');
	d.querySelector('.plandose-chip[data-val="custom"]').click();
	PD.setFieldValue('pd-custom-interval-days', '30');
	PD.updateDoseTotal();
	assert.match(d.getElementById('pd-dose-total').textContent, /^Σύνολο: 1 δόση /);
});
