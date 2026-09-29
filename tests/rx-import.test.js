/* 1.25.0: prescription import. Every real (anonymised) prescription in
   rx-samples/ against the reviewed expected.json, plus the safety cases.
   Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

const DIR = path.join(__dirname, 'rx-samples');
const EXPECTED = JSON.parse(fs.readFileSync(path.join(DIR, 'expected.json'), 'utf8'));

/* 1.27.0: weekly items are a weekday to choose («weekday?»), monthly ones
   a day of the month to choose («monthday?»). */
function freqCol(it) {
	if (it.freq !== 'custom') {
		return it.freq;
	}
	if (it.customMode === 'weekday') {
		return 'weekday' + (it.customWeekday == null ? '?' : it.customWeekday);
	}
	if (it.customMode === 'monthday') {
		return 'monthday' + (it.customMonthDay == null ? '?' : it.customMonthDay);
	}
	return 'every' + it.customIntervalDays;
}

function row(it) {
	return [it.name, it.doseAmount, it.doseUnit, freqCol(it), +it.days, it.warnings.join(',')];
}

for (const [file, want] of Object.entries(EXPECTED)) {
	test('sample ' + file, () => {
		const { PD } = load();
		const r = PD.parsePrescription(fs.readFileSync(path.join(DIR, file), 'utf8'));
		assert.strictEqual(r.patient, want.patient);
		assert.deepStrictEqual(JSON.parse(JSON.stringify(r.items.map(row))), want.items);
	});
}

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const one = (drug, dose) => HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';

test('methotrexate prescribed daily is flagged', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('METHOTREXATE/EBEWE TAB 2,5MG/TAB BTx50 (Γενόσημο)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.ok(r.items[0].warnings.includes('methotrexateDaily'));
});

test('methotrexate weekly is not flagged', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('NORDIMET INJ.SOL 15MG/0,6ML BTX 1PF.SYR X0,6ML (Γενόσημο)', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 30 ημέρες'));
	assert.strictEqual(r.items[0].freq, 'custom');
	assert.ok(!r.items[0].warnings.includes('methotrexateDaily'));
});

test('weekly phrase that is not «1 φορά την εβδομάδα» is never made daily', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την εβδομάδα x 28 ημέρες'));
	assert.strictEqual(r.items[0].freq, null);
	assert.ok(r.items[0].warnings.includes('freqWeekly'));
});

test('«22 ΕΝΕΣΗ» without insulin markers is not 22 injections', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('SOMEDRUG INJ.SOL 10MG/ML BTx5 AMP', '22 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 5 ημέρες'));
	assert.strictEqual(r.items[0].doseUnit, '');
	assert.ok(r.items[0].warnings.includes('injectionQty'));
});

test('insulin by name, even without «Units/ml», is dosed in units', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('LANTUS SOLOSTAR INJ.SOL 100U/ML BTx5 PENS', '14 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.strictEqual(r.items[0].doseUnit, 'iu');
	assert.strictEqual(r.items[0].doseAmount, 14);
	assert.ok(r.items[0].warnings.includes('iuConfirm'));
});

test('«κάθε 8 ώρες» → 3 times a day; unknown frequency stays empty', () => {
	const { PD } = load();
	let r = PD.parsePrescription(one('AMOXIL CAPS 500MG/CAP BTx12', '1 ΚΑΨΟΥΛΑ x κάθε 8 ώρες x 7 ημέρες'));
	assert.strictEqual(r.items[0].freq, '8h');
	r = PD.parsePrescription(one('AMOXIL CAPS 500MG/CAP BTx12', '1 ΚΑΨΟΥΛΑ x 5 φορές την ημέρα x 7 ημέρες'));
	assert.strictEqual(r.items[0].freq, null);
	assert.ok(r.items[0].warnings.includes('freq'));
});

test('1/3 tablet is not rounded — left for the pharmacist', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('INDERAL F.C.TAB 40MG/TAB ΒΤx30', '1/3 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.strictEqual(r.items[0].doseAmount, '1/3');
	assert.ok(r.items[0].warnings.includes('amount'));
});

test('empty or unrelated text gives no items', () => {
	const { PD } = load();
	assert.strictEqual(PD.parsePrescription('').items.length, 0);
	assert.strictEqual(PD.parsePrescription('γεια σου\nκόσμε').items.length, 0);
});

test('Latin «O» inside the Greek dose phrase («ΕΙΣΠΝOΕΣ») is still read', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one('FOO 5MG BTx1', '2 ΕΙΣΠΝOΕΣ ΣΚΟΝΗ ΔΟΣΕΙΣ x 2 φορές την ημέρα x 10 ημέρες'));
	assert.strictEqual(r.items[0].doseUnit, 'inhale');
});

test('oral solution in ml from the dose phrase; injectable solution stays an injection', () => {
	const { PD } = load();
	let r = PD.parsePrescription(one('FOO 10MG/ML BTx1', '3 ΠΟΣ.ΔΙΑΛΥΜΑ ML x 1 φορά την ημέρα x 2 ημέρες'));
	assert.strictEqual(r.items[0].doseUnit, 'ml');
	r = PD.parsePrescription(one('BAR 4MG/2ML BTx1', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 2 ημέρες'));
	assert.strictEqual(r.items[0].doseUnit, 'injection');
});
