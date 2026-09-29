/* 1.27.2 round 10 (verify/rx18.js): insulin names in any spelling and
   the units-per-ml forms; the IU/ML single-syringe exemption closed;
   «X MG/Y MG» strengths kept whole; «no strength» confirmed except on
   injectables. Synthetic strings only. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const one = (drug, dose) => HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + P;
const first = (PD, drug, dose) => PD.parsePrescription(one(drug, dose || '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 10 ημέρες')).items[0];
const E1 = '1 ΕΝΕΣΗ x 1 φορά την ημέρα x 30 ημέρες';
const PFS1 = '1 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ x 1 φορά την ημέρα x 30 ημέρες';
const J = (v) => JSON.parse(JSON.stringify(v));
const settle = (PD, it) => PD.rxItemProblem(Object.assign({}, it, { dailyTime: 'morning', customWeekday: 1, confirmed: { name: true, qty: true, freq: true, days: true } }));

const BRANDS = ['Lantus', 'Toujeo', 'Abasaglar', 'Basaglar', 'Semglee', 'Rezvoglar', 'Tresiba', 'Levemir', 'NovoRapid', 'Fiasp', 'Humalog',
	'Lyumjev', 'Liprolog', 'Admelog', 'Apidra', 'Insuman', 'Humulin', 'Huminsulin', 'Actrapid', 'Actraphane', 'Protaphane', 'Mixtard',
	'NovoMix', 'Humalog Mix 25', 'Ryzodeg', 'Xultophy', 'Suliqua', 'Soliqua', 'Abasria', 'Kirsty', 'Truvelog', 'Awiqli'];

test('M1: every insulin brand, INN stem and Greek spelling is insulin (form, print, labels)', () => {
	const { PD } = load();
	const names = BRANDS.concat(BRANDS.map((b) => b.toUpperCase()), ['ΛΑΝΤΟΥΣ', 'λαντους', 'Λάντους', 'ΝΟΒΟΡΑΠΙΝΤ', 'ΧΟΥΜΑΛΟΓΚ', 'ΤΟΥΤΖΕΟ', 'ΑΜΠΑΣΑΓΚΛΑΡ',
		'ΤΡΕΣΙΜΠΑ', 'ΛΕΒΕΜΙΡ', 'ΑΠΙΝΤΡΑ', 'ΑΚΤΡΑΠΙΝΤ', 'ΜΙΞΤΑΡΝΤ', 'insulin glargine', 'INSULIN ASPART', 'glargine 100', 'insulin lispro', 'degludec',
		'detemir', 'glulisine', 'isophane insulin', 'human insulin', 'ΙΝΣΟΥΛΙΝΗ', 'ινσουλίνη γλαργίνη', 'ΑΝΘΡΩΠΙΝΗ ΙΝΣΟΥΛΙΝΗ', 'ΓΛΑΡΓΙΝΗ', 'ΙΝΣΟΥΛΙΝΗ ΑΣΠΑΡΤ',
		'LΑΝΤUS', 'ΝΟVΟRΑΡΙD']);
	for (const n of names) {
		assert.strictEqual(PD.isInsulinName(n), true, n);
		const item = { name: n, doseAmount: 1, doseUnit: 'injection', freq: '24h', dailyTime: 'morning', customMode: 'days', days: '7' };
		assert.strictEqual(PD.planItemInvalid(item), 'unit', n);
	}
	for (const n of ['DEPON', 'MAGNESIUM ASPARTATE', 'ASPARTAM', 'LANTANON', 'HUMIRA', 'NOVOSEVEN', 'SINTROM', 'XANAX', 'CONCOR', 'TETAGAM P INJ.SO.PFS 250 IU/ML',
		'CLEXANE INJ.SOL 4000 IU (40MG)/0,4ML', 'D3 DROPS 10000IU/ML', 'HUMAN ALBUMIN', 'ΑΝΘΡΩΠΙΝΗ ΛΕΥΚΩΜΑΤΙΝΗ']) {
		assert.strictEqual(PD.isInsulinName(n), false, n);
	}
});

test('M1: the form refuses «1 ένεση» for a typed Greek or brand-only insulin name', () => {
	const env = load();
	const { PD } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	for (const name of ['ΛΑΝΤΟΥΣ', 'λαντους', 'Liprolog', 'Actraphane', 'Rezvoglar', 'Abasria', 'Basaglar', 'Soliqua']) {
		PD.setFieldValue('pd-drug', name);
		PD.setFieldValue('pd-dose-amount', '1');
		PD.setFieldValue('pd-dose-unit', 'injection');
		PD.setFieldValue('pd-days', '7');
		PD.s.currentFreq = '24h';
		PD.s.currentDailyTime = 'morning';
		PD.addOrUpdateItem();
		assert.strictEqual(PD.s.items.length, 0, name);
	}
});

test('M1: units per ml as «U/1ML», «(100U+33MCG)/ML», «Μ.Ο./ML», «units/ml»; brands without any', () => {
	const { PD } = load();
	for (const drug of ['FOOGLAR KWIKPEN INJ.SOL 100U/1ML BTx5 PENS', 'FOOQUA INJ.SOL (100U+33MCG)/ML BTx3 PENS', 'FOOGLAR KWIKPEN INJ.SOL 100 Μ.Ο./ML BTx5 PENS',
		'FOOGLAR KWIKPEN INJ.SOL 100 units/ml BTx5 PENS', 'LIPROLOG KWIKPEN INJ.SOL 3ML BTx5 PENS', 'ACTRAPHANE 30 PENFILL INJ.SUSP 100 IU/ml BTx5 CARTRIDGES',
		'SOLIQUA INJ.SOL (100U+33MCG)/ML BTx3 PENS', 'HUMINSULIN BASAL S.SUSP 100IU/ML BTx1 VIALx10ML']) {
		const it = first(PD, drug, E1);
		assert.notStrictEqual(it.doseUnit, 'injection', drug);
		assert.ok(it.warnings.includes('insulinUnits'), drug + ' ' + it.warnings);
		assert.strictEqual(settle(PD, it), 'warnings', drug);
	}
	assert.ok(PD.UNITS_PER_ML.test('100U/1ML') && PD.UNITS_PER_ML.test('(100U+33MCG)/ML') && PD.UNITS_PER_ML.test('100 M.O./ML'));
	assert.ok(!PD.UNITS_PER_ML.test('100MG/ML') && !PD.UNITS_PER_ML.test('4000 IU (40MG)/0,4ML'));
});

test('M1: the single-syringe IU/ML exemption: known non-insulin silent, unknown confirmed, insulin never', () => {
	const { PD } = load();
	const known = first(PD, 'TETAGAM P INJ.SO.PFS 250 IU/ML BTx1 PF.SYR x1ML', PFS1);
	assert.strictEqual(known.doseUnit, 'injection');
	assert.deepStrictEqual(J(known.warnings), ['time']);
	const unknown = first(PD, 'FOOBULIN INJ.SO.PFS 250 IU/ML BTx1 PF.SYR x1ML', PFS1);
	assert.strictEqual(unknown.doseUnit, 'injection');
	assert.ok(unknown.warnings.includes('iuSyringe'));
	assert.deepStrictEqual(J(PD.rxPending(Object.assign({}, unknown, { dailyTime: 'morning' }))), [{ code: 'iuSyringe', field: 'qty', kind: 'confirm' }]);
	assert.strictEqual(settle(PD, unknown), '');
	/* An insulin by name, a U/ML strength (not IU), an insulin marker: never. */
	for (const drug of ['ABASRIA INJ.SO.PFS 100 IU/ML BTx1 PF.SYR x3ML', 'ΛΑΝΤΟΥΣ INJ.SO.PFS 100 IU/ML BTx1 PF.SYR x3ML',
		'FOOBULIN INJ.SO.PFS 250 U/ML BTx1 PF.SYR x1ML', 'FOOBULIN SOLOSTAR INJ.SO.PFS 250 IU/ML BTx1 PF.SYR x1ML']) {
		const it = first(PD, drug, PFS1);
		assert.notStrictEqual(it.doseUnit, 'injection', drug);
		assert.ok(it.warnings.includes('insulinUnits'), drug + ' ' + it.warnings);
	}
});

test('M2: «X MG/Y MG» and «X MG/Y G» are kept whole; a dropped part after «/» is confirmed', () => {
	const { PD } = load();
	for (const [drug, name] of [['EXFORGE F.C.TAB 5MG/160MG BTx28', 'EXFORGE F.C.TAB 5MG/160MG'], ['FOOFER PS.OR.SOL 300MG/15G VIAL BT x 10 VIALS', 'FOOFER PS.OR.SOL 300MG/15G'],
		['FOO TAB 5MG/10MG/20MG BTx30', 'FOO TAB 5MG/10MG/20MG'], ['FOO SYR 250MG/5ML FLX100ML', 'FOO SYR 250MG/5ML'], ['FOO TAB 5/160 MG BTx28', 'FOO TAB 5/160MG']]) {
		const it = first(PD, drug);
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('strength'), drug);
	}
	for (const drug of ['FOO TAB 5MG/160 BTx28', 'FOO TAB 10MG/2,5 BTx28']) {
		const it = first(PD, drug);
		assert.ok(it.warnings.includes('strength') || it.warnings.includes('name'), drug + ' ' + it.warnings);
	}
	/* The pen dose «1MG/0,74 (1 δόση)» is not a dropped part. */
	assert.ok(!first(PD, 'FOOPIC INJ.SOL 1MG/0,74 (1 δόση) 1,34MG/ML 1 πρ. συσκ. τύπου πέναςX1, 5ML+4 βελόνες', E1).warnings.includes('strength'));
});

test('low: no strength is confirmed on an oral form, not on an injectable / vaccine', () => {
	const { PD } = load();
	for (const drug of ['SINTROM TAB BTx20', 'FOO CAPS BTx30', 'FOO F.C.TAB BTx28']) {
		assert.ok(first(PD, drug).warnings.includes('strength'), drug);
	}
	for (const drug of ['FOOCINE INJ.SUSP BTx1 PF.SYR', 'FOORIX PD.SU.IN.S BTx1VIAL+1PF,SYR,x', 'FOOQUAD PS.INJ.SUS BTx 1 VIAL+1 PF.SYR. x 0,5 ML', 'FOO INJ.SU.PFS BTx1 PF.SYR']) {
		assert.ok(!first(PD, drug, '1 ΕΝΕΣΗ x εφάπαξ x 1 ημέρες').warnings.includes('strength'), drug);
	}
});
