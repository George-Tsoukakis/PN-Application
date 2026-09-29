/* 1.27.6: «κάθε 8 / 6 ώρες» needs one «Επιβεβαιώνω»; a liquid, drops or a
   sachet dose for tablets / capsules goes to the form (unitForm). Neither
   fires on the real corpus (rx-corpus.test.js keeps the noise budget). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, isoAhead } = require('./harness');

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const parse = (PD, drug, dose) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + P).items[0];

test('every 8 / 6 hours: one confirmation on the frequency, then addable', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [h, freq] of [['8', '8h'], ['6', '6h']]) {
		const it = parse(PD, 'FOOMOX F.C.TAB 500MG BTx16', '1 ΔΙΣΚΙΑ x ΚΑΘΕ ' + h + ' ΩΡΕΣ x 7 ημέρες');
		assert.strictEqual(it.freq, freq);
		assert.ok(it.warnings.includes('everyHours'), it.warnings.join(','));
		const pending = PD.rxPending(it);
		assert.strictEqual(JSON.stringify(pending.map((p) => [p.code, p.field, p.kind])), JSON.stringify([['everyHours', 'freq', 'confirm']]));
		assert.strictEqual(PD.rxItemProblem(it), 'warnings');
		it.confirmed = { freq: true };
		assert.strictEqual(PD.rxItemProblem(it), '', 'one click settles it');
		assert.notStrictEqual(PD.prescriptionWarningText('everyHours'), 'everyHours');
	}
});

test('«N φορές την ημέρα» and every 12 hours stay quiet', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const dose of ['1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 7 ημέρες', '1 ΔΙΣΚΙΑ x 4 φορές την ημέρα x 7 ημέρες', '1 ΔΙΣΚΙΑ x ΚΑΘΕ 12 ΩΡΕΣ x 7 ημέρες']) {
		const it = parse(PD, 'FOOMOX F.C.TAB 500MG BTx16', dose);
		assert.ok(!it.warnings.includes('everyHours'), dose);
		assert.strictEqual(PD.rxItemProblem(it), '', dose + ' ' + it.warnings);
	}
});

test('ml / drops / sachet for tablets or capsules go to the form (unitForm)', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, dose] of [
		['FOOPON F.C.TAB 500MG BTx20', '5 ML x 3 φορές την ημέρα x 5 ημέρες'],
		['FOOPON F.C.TAB 500MG BTx20', '10 ΣΤΑΓΟΝΕΣ x 3 φορές την ημέρα x 5 ημέρες'],
		['FOOPON F.C.TAB 500MG BTx20', '1 ΦΑΚΕΛΙΣΚΟΣ x 3 φορές την ημέρα x 5 ημέρες'],
		['FOOCAP CAPS 20MG BTx28', '5 ML x 1 φορά την ημέρα x 28 ημέρες']
	]) {
		const it = parse(PD, drug, dose);
		assert.ok(it.warnings.includes('unitForm'), drug + ' ' + dose + ' ' + it.warnings);
		assert.strictEqual(it.doseUnit, '', dose);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings');
	}
});

test('matching liquid forms stay quiet', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, dose, unit] of [
		['FOOPON SYR 120MG/5ML BTx1', '5 ML x 3 φορές την ημέρα x 5 ημέρες', 'ml'],
		['FOOPON ORAL.SOL 100MG/ML BTx1', '5 ML x 3 φορές την ημέρα x 5 ημέρες', 'ml']
	]) {
		const it = parse(PD, drug, dose);
		assert.ok(!it.warnings.includes('unitForm'), drug + ' ' + it.warnings);
		assert.strictEqual(it.doseUnit, unit);
	}
});
