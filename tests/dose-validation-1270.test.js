/* 1.27.0: dose quantity, plausibility, methotrexate names and the last
   check of every plan item (review items 1, 8, 9, 10). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const good = { name: 'A', doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: '7', notes: '' };
const withItem = (over) => Object.assign({}, good, over);

/* (1) */
test('a whole part needs a proper fraction: «1 11/2» and «1 3/2» are refused', () => {
	const { PD } = load();
	for (const raw of ['1 11/2', '1 3/2', '2 4/4', '1 2/2', '0 3/2']) {
		const r = PD.checkDoseAmount(raw, 'tablet');
		assert.ok(Number.isNaN(r.value), raw);
		assert.strictEqual(r.error, 'mixed', raw);
		const msg = PD.doseAmountMessage(r.error, raw, 'X', 'tablet');
		assert.match(msg, /αριθμητής/, raw);
		assert.ok(msg.includes('«' + raw + '»'), raw);
	}
	for (const [raw, v] of [['1 1/2', 1.5], ['2 1/4', 2.25], ['1 3/4', 1.75], ['0 1/2', 0.5], ['3/2', 1.5], ['1/2', 0.5]]) {
		assert.deepStrictEqual(JSON.parse(JSON.stringify(PD.checkDoseAmount(raw, 'tablet'))), { value: v, error: '' }, raw);
	}
	/* «11/2» keeps its own message (the mixed reading is offered). */
	assert.match(PD.doseAmountMessage('mixed', '11/2', 'X', 'tablet'), /1 1\/2/);
});

test('the prescription import does not read «1 3/2» as 2,5', () => {
	const { PD } = load();
	const r = PD.parsePrescription('ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 3/2 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n');
	assert.ok(r.items[0].warnings.includes('amount'));
	assert.notStrictEqual(r.items[0].doseAmount, 2.5);
	assert.strictEqual(PD.rxItemProblem(r.items[0]), 'warnings');
});

test('the form refuses «1 11/2» with the clear message', () => {
	const env = load();
	const { PD, w } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	PD.setFieldValue('pd-drug', 'ZINADOL');
	PD.setFieldValue('pd-dose-amount', '1 11/2');
	PD.setFieldValue('pd-days', '5');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0);
	assert.match(w.document.getElementById('pd-message').textContent, /μικτός αριθμός/);
});

/* (8) */
test('plausibility thresholds per unit', () => {
	const { PD } = load();
	assert.strictEqual(PD.doseUnusual(4, 'tablet'), false);
	assert.strictEqual(PD.doseUnusual(5, 'tablet'), true);
	assert.strictEqual(PD.doseUnusual(4.5, 'capsule'), true);
	assert.strictEqual(PD.doseUnusual(30, 'ml'), false);
	assert.strictEqual(PD.doseUnusual(35, 'ml'), true);
	assert.strictEqual(PD.doseUnusual(3, 'suppository'), true);
	assert.strictEqual(PD.doseUnusual(3, 'injection'), true);
	assert.strictEqual(PD.doseUnusual(60, 'iu'), false);
	assert.strictEqual(PD.doseUnusual(80, 'iu'), true);
	assert.strictEqual(PD.doseUnusual(1500, 'mg'), true);
	assert.strictEqual(PD.doseUnusual(NaN, 'tablet'), false);
	assert.strictEqual(PD.doseUnusual(99, 'unknown'), false);
	/* Every unit of the form has a threshold below its ceiling. */
	for (const u of PD.unitOptions) {
		assert.ok(PD.DOSE_UNUSUAL_BY_UNIT[u.val] < PD.doseMax(u.val), u.val);
	}
});

test('form: 6 tablets per intake needs a second press; a change asks again', () => {
	const env = load();
	const { PD, w } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	const d = w.document;
	PD.setFieldValue('pd-drug', 'FOO');
	PD.setFieldValue('pd-dose-amount', '6');
	PD.setFieldValue('pd-days', '3');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0, 'first press: warning only');
	assert.match(d.getElementById('pd-message').textContent, /Ασυνήθιστα μεγάλη ποσότητα/);
	assert.strictEqual(d.getElementById('pd-dose-amount').getAttribute('aria-invalid'), 'true');
	PD.setFieldValue('pd-dose-amount', '7');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0, 'another quantity is a new question');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'second press with the same values adds');
	assert.strictEqual(PD.s.items[0].doseAmount, 7);
	/* The confirmation does not carry over to the next medicine. */
	assert.strictEqual(PD.s.doseQtyAck, '');
	PD.setFieldValue('pd-drug', 'FOO');
	PD.setFieldValue('pd-dose-amount', '7');
	PD.setFieldValue('pd-days', '3');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
});

test('form: 2 tablets are added at once', () => {
	const env = load();
	const { PD } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	PD.setFieldValue('pd-drug', 'FOO');
	PD.setFieldValue('pd-dose-amount', '2');
	PD.setFieldValue('pd-days', '3');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
});

/* (9) */
test('methotrexate: «MTX» and names typed with Greek look-alike letters', () => {
	const { PD } = load();
	const daily = (name) => PD.methotrexateTooOften({ name: name, freq: '24h' });
	assert.strictEqual(daily('MTX 2,5MG TAB'), true);
	assert.strictEqual(daily('mtx'), true);
	assert.strictEqual(daily('ΜΤΧ 2,5MG'), true, 'Greek capitals Μ Τ Χ');
	assert.strictEqual(daily('ΝΟRDΙΜΕΤ 15MG'), true, 'Greek Ν Ο Ι Μ Ε Τ mixed with Latin');
	assert.strictEqual(daily('ΜΕΤΗΟΤRΕΧΑΤΕ'), true, 'METHOTREXATE with Greek look-alikes');
	assert.strictEqual(daily('ΜΕΘΟΤΡΕΞΑΤΗ'), true, 'Greek spelling still matched');
	assert.strictEqual(daily('ΜΕΤΟJΕCΤ'), true);
	assert.strictEqual(daily('MTXA'), false);
	assert.strictEqual(daily('SMTX'), false);
	assert.strictEqual(daily('ZINADOL'), false);
	/* Weekly (weekday) and monthly are not too often. */
	assert.strictEqual(PD.methotrexateTooOften({ name: 'MTX', freq: 'custom', customMode: 'weekday', customWeekday: 2 }), false);
	assert.strictEqual(PD.methotrexateTooOften({ name: 'MTX', freq: 'custom', customMode: 'monthday', customMonthDay: 5 }), false);
	assert.strictEqual(PD.methotrexateTooOften({ name: 'MTX', freq: 'custom', customMode: 'days', customIntervalDays: '3' }), true);
});

test('methotrexate «MTX» daily from a prescription is flagged', () => {
	const { PD } = load();
	const r = PD.parsePrescription('MTX TAB 2,5MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες\n25% 1 1,00 1,00\n');
	assert.ok(r.items[0].warnings.includes('methotrexateDaily'));
	assert.strictEqual(PD.rxItemProblem(r.items[0]), 'warnings');
});

/* (10) */
test('planItemInvalid rejects a bad weekday, time, mode, day of the month or interval', () => {
	const { PD } = load();
	const bad = [
		[withItem({ freq: '24h', dailyTime: 'night' }), 'time'],
		[withItem({ freq: '24h', dailyTime: null }), 'time'],
		[withItem({ freq: 'custom', customMode: 'weekday', customWeekday: 7 }), 'weekday'],
		[withItem({ freq: 'custom', customMode: 'weekday', customWeekday: 'x' }), 'weekday'],
		[withItem({ freq: 'custom', customMode: 'weekday', customWeekday: null }), 'weekday'],
		[withItem({ freq: 'custom', customMode: 'weekday', customWeekday: 1.5 }), 'weekday'],
		[withItem({ freq: 'custom', customMode: 'hours' }), 'mode'],
		[withItem({ freq: 'custom', customMode: undefined }), 'mode'],
		[withItem({ freq: 'custom', customMode: 'monthday', customMonthDay: 0 }), 'monthday'],
		[withItem({ freq: 'custom', customMode: 'monthday', customMonthDay: 32 }), 'monthday'],
		[withItem({ freq: 'custom', customMode: 'monthday', customMonthDay: null }), 'monthday'],
		[withItem({ freq: 'custom', customMode: 'monthday', customMonthDay: '3a' }), 'monthday'],
		[withItem({ freq: 'custom', customMode: 'days', customIntervalDays: '' }), 'interval'],
		[withItem({ freq: 'custom', customMode: 'days', customIntervalDays: '91' }), 'interval']
	];
	for (const [item, why] of bad) {
		assert.strictEqual(PD.planItemInvalid(item), why, JSON.stringify(item));
	}
	const ok = [
		good,
		withItem({ freq: '12h', dailyTime: null }),
		withItem({ freq: 'custom', customMode: 'weekday', customWeekday: 0 }),
		withItem({ freq: 'custom', customMode: 'weekday', customWeekday: '6' }),
		withItem({ freq: 'custom', customMode: 'monthday', customMonthDay: 31 }),
		withItem({ freq: 'custom', customMode: 'days', customIntervalDays: '7' })
	];
	for (const item of ok) {
		assert.strictEqual(PD.planItemInvalid(item), '', JSON.stringify(item));
	}
});

test('the last check before printing refuses a weekday item with no day instead of printing Monday', () => {
	const { PD } = load({ config: { i18n: { invalidItemForPrint: 'BAD %s' } } });
	PD.s.startDate = isoAhead(0);
	PD.s.items = [withItem({ name: 'W', freq: 'custom', customMode: 'weekday', customWeekday: null, days: '14' })];
	assert.strictEqual(PD.validatePlanForPrint(), false);
	PD.s.items[0].customWeekday = 3;
	assert.strictEqual(PD.validatePlanForPrint(), true);
	/* A monthly item has no interval to check. */
	PD.s.items = [withItem({ name: 'M', freq: 'custom', customMode: 'monthday', customMonthDay: 1, days: '60' })];
	assert.strictEqual(PD.validatePlanForPrint(), true);
});

test('preview never turns an unchosen weekday into Monday', () => {
	const { PD } = load();
	const it = withItem({ freq: 'custom', customMode: 'weekday', customWeekday: null, days: '14' });
	for (let d = 0; d < 14; d++) {
		assert.strictEqual(PD.sparseDayHasDose(it, d), false);
	}
	assert.strictEqual(PD.doseTotals(it).doses, 0);
	assert.match(PD.getFreqLabel(it), /επιλέξτε ημέρα/);
});
