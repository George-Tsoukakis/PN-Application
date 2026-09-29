/* 1.26.1: patient-safety fixes from the 1.25.0 review. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const one = (drug, dose) => HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + PRICE;
const J = (v) => JSON.parse(JSON.stringify(v));

test.after(closeAll);

test('two strengths of the same medicine keep their strength and are not duplicates', () => {
	const { PD } = load();
	const r = PD.parsePrescription(
		one('SINTROM TAB 1MG/TAB BTx20', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
		one('SINTROM TAB 4MG/TAB BTx20', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.deepStrictEqual(J(r.items.map((i) => i.name)), ['SINTROM TAB 1MG', 'SINTROM TAB 4MG']);
});

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

function paste(env, text) {
	const drop = env.w.document.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new env.w.Event('input', { bubbles: true }));
}

test('review screen: both strengths selected; same medicine in another pack is a duplicate', () => {
	const env = app();
	const d1 = one('SINTROM TAB 1MG/TAB BTx20', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες');
	const d4 = one('SINTROM TAB 4MG/TAB BTx20', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες');
	const b30 = one('BESPAR TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες');
	const b60 = one('BESPAR TAB 10MG/TAB BTx60', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες');
	paste(env, d1 + d4 + b30 + b60);
	const rows = env.PD.s.rx.rows;
	assert.deepStrictEqual(J(rows.map((r) => r.include)), [true, true, true, false]);
	assert.ok(rows[3].item.warnings.includes('duplicate'));
});

test('weekly for a duration that is not whole weeks is sent through the form', () => {
	const { PD } = load();
	const r30 = PD.parsePrescription(one('TRULICITY INJ.SOL 1,5MG/0,5ML BTx4', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 30 ημέρες'));
	assert.ok(r30.items[0].warnings.includes('intervalCount'));
	assert.strictEqual(PD.rxItemProblem(r30.items[0]), 'warnings');
	const r28 = PD.parsePrescription(one('TRULICITY INJ.SOL 1,5MG/0,5ML BTx4', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 28 ημέρες'));
	assert.ok(!r28.items[0].warnings.includes('intervalCount'));
});

test('«1 φορά τον μήνα» is flagged: 30 days is not a calendar month', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('PROLIA INJ.SOL 60MG/ML BTx1', '1 ΕΝΕΣΗ x 1 φορά τον μήνα x 60 ημέρες'));
	assert.ok(r.items[0].warnings.includes('monthly'));
});

test('a dose line continued on the next line is flagged, the next medicine is not', () => {
	const { PD } = load();
	const cont = HEAD + 'MEDROL TAB 16MG/TAB BTx14\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 7 ημέρες\nκαι μετά 1/2 x 1 φορά την ημέρα x 7 ημέρες\n' + PRICE;
	const r = PD.parsePrescription(cont);
	assert.ok(r.items[0].warnings.includes('extra'));
	assert.match(r.items[0].source, /και μετά/);
	/* Compressed copy: the next drug's line follows the dose line directly. */
	const tight = HEAD + 'DELIPOST F.C.TAB 10MG/TAB BTx28\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες 25% 1 7,34\n' +
		'CARVEDILEN F.C.TAB 12,5MG/TAB BTX30(3 BLIST X10)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες 25% 1 6,90\n';
	const t = PD.parsePrescription(tight);
	assert.ok(!t.items[0].warnings.includes('extra'));
	for (const line of ['σε περίπτωση πόνου', 'πρωί - βράδυ μετά το φαγητό', 'μία ώρα πριν το φαγητό', '1/2 x 1 φορά x 14']) {
		const r2 = PD.parsePrescription(HEAD + 'MEDROL TAB 16MG/TAB BTx14\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 7 ημέρες\n' + line + '\n' + PRICE);
		assert.ok(r2.items[0].warnings.includes('extra'), line);
	}
	/* A continuation line with no price row after it is not the next name. */
	const r3 = PD.parsePrescription(HEAD + 'MEDROL TAB 16MG/TAB BTx14\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 7 ημέρες 25% 1 1,00\nεπί πόνου\nBESPAR TAB 10MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες 25% 1 1,00\n');
	assert.strictEqual(r3.items[1].name, 'BESPAR TAB 10MG');
});

test('labels run the same last check: methotrexate needs a second press', () => {
	const { PD } = load();
	const mtx = { name: 'METHOTREXATE TAB 2,5MG', doseAmount: 1, doseUnit: 'tablet', freq: '24h', customIntervalDays: '' };
	assert.strictEqual(PD.methotrexateNeedsConfirm([mtx]), true);
	assert.strictEqual(PD.methotrexateNeedsConfirm([mtx]), false);
	assert.strictEqual(PD.methotrexateNeedsConfirm([Object.assign({}, mtx, { name: 'ZINADOL' })]), false);
});

function planEnv(items) {
	const env = load({ config: { i18n: { invalidItemForPrint: 'BAD %s', methotrexatePrintConfirm: 'MTX' } } });
	env.PD.s.startDate = isoAhead(0);
	env.PD.s.items = items;
	env.PD.getStartDateRaw = () => isoAhead(0);
	return env;
}

const good = { name: 'A', doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: 1, days: '7', notes: '' };

test('the last check before printing re-validates every medicine', () => {
	const bad = [
		Object.assign({}, good, { doseAmount: NaN }),
		Object.assign({}, good, { doseUnit: '' }),
		Object.assign({}, good, { days: '0' }),
		Object.assign({}, good, { days: '7.5' }),
		Object.assign({}, good, { freq: null })
	];
	bad.forEach((item) => {
		const { PD } = planEnv([item]);
		assert.strictEqual(PD.planItemInvalid(item) !== '', true, JSON.stringify(item));
	});
	const { PD } = planEnv([good]);
	assert.strictEqual(PD.planItemInvalid(good), '');
});

test('methotrexate more often than weekly needs a second press, per plan', () => {
	const mtx = Object.assign({}, good, { name: 'METHOTREXATE TAB 2,5MG' });
	const { PD } = planEnv([mtx]);
	assert.strictEqual(PD.validatePlanForPrint(), false);
	assert.strictEqual(PD.validatePlanForPrint(), true);
	/* A different schedule is a new question. */
	PD.s.items = [Object.assign({}, mtx, { freq: '12h' })];
	assert.strictEqual(PD.validatePlanForPrint(), false);
	assert.strictEqual(PD.validatePlanForPrint(), true);
	/* A new patient starts with no confirmation. */
	PD.resetPlanState();
	PD.s.startDate = isoAhead(0);
	PD.s.items = [Object.assign({}, mtx, { freq: '12h' })];
	assert.strictEqual(PD.validatePlanForPrint(), false);
});

test('setBusy restores the button markup, not only its text', () => {
	const { PD, w } = load();
	const btn = w.document.createElement('button');
	btn.innerHTML = '<span aria-hidden="true">🏷️</span> Ετικέτες';
	PD.setBusy(btn, true);
	PD.setBusy(btn, false);
	assert.match(btn.innerHTML, /aria-hidden="true"/);
});
