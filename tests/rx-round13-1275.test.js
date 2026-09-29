/* 1.27.5 round 13: the silent errors an independent review reproduced
   with synthetic lines in the shape of the real ones. Each must now be
   flagged (confirm or form), never planned silently:
   - a strength glued to a dot («TAB .5MG» → «5MG»);
   - fractions after the pack («BTx20 1/4 1/2 1/4 1/2», «+1/2», «(1/2)»),
     also on a wrapped line, and a second strength after the box;
   - an instruction taken as the status wrap («1/2ΔΙΣΚΙΟ (Γενόσημο)»);
   - a dose that does not fit the form («TAB» + «1 ΕΝΕΣΗ», «SUPP» + «ΔΙΣΚΙΑ»);
   - insulin written in ml («22 ML» of 100 U/ml). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const parse = (PD, drug, dose) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + P);
const DAILY = (d) => '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x ' + (d || 30) + ' ημέρες';

/* The row with every choice made and nothing confirmed. */
function settled(it) {
	return Object.assign({}, it, { dailyTime: 'morning', customWeekday: 1, customMonthDay: 1 });
}

function assertNotSilent(PD, it, what) {
	assert.notStrictEqual(PD.rxItemProblem(settled(it)), '', what + ' planned silently: ' + JSON.stringify(it.warnings));
}

test('a strength glued to a dot or comma is never planned silently («.5MG» is 0,5)', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const drug of ['FOOXAN TAB .5MG BTx30', 'FOOXAN TAB ,25MG BTx30']) {
		const it = parse(PD, drug, DAILY()).items[0];
		/* 1.28.5: the zero is put back and the row goes through the form. */
		assert.ok(it.warnings.includes('strengthZero'), drug + ' ' + it.warnings);
		assertNotSilent(PD, it, drug);
	}
	/* the ordinary shapes stay quiet */
	for (const drug of ['FOOXAN TAB 0,5MG BTx30', 'FOOXAN TAB 0.25MG BTx30', 'FOOXAN F.C.TAB 5MG BTx30']) {
		const it = parse(PD, drug, DAILY()).items[0];
		assert.ok(!it.warnings.includes('strength'), drug + ' ' + it.warnings);
	}
});

test('fractions after the pack end the drug line: shown as unread, never dropped', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const tail of ['1/4 1/2 1/4 1/2', '1/2', '3/4', '(1/2)', '+1/2', '1+1/2', '1/2-1/4', '1/2 (Γενόσημο)', '20MG']) {
		const drug = 'FOOTROM TAB 4MG BTx20 ' + tail;
		const it = parse(PD, drug, DAILY(7)).items[0];
		assert.strictEqual(it.name, 'FOOTROM TAB 4MG', drug);
		assert.ok(it.warnings.includes('extra'), drug + ' ' + it.warnings);
		assertNotSilent(PD, it, drug);
	}
	/* wrapped onto the next line */
	const r = PD.parsePrescription(HEAD + 'FOOTROM TAB 4MG\nBTx20 1/2\nΔΟΣΟΛΟΓΙΑ : ' + DAILY(7) + '\n' + P);
	assertNotSilent(PD, r.items[0], 'wrapped «BTx20 1/2»');
	/* a strength written with a slash is still a strength */
	for (const [drug, name] of [['FOOFORGE F.C.TAB 5/160 MG BTx28', 'FOOFORGE F.C.TAB 5/160MG'], ['FOOMET CR TAB 200/50 MG BTx100', 'FOOMET CR TAB 200/50MG']]) {
		assert.strictEqual(parse(PD, drug, DAILY()).items[0].name, name, drug);
	}
});

test('an instruction is never taken for the status wrap of the drug line', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const wrap of ['1/2ΔΙΣΚΙΟ (Γενόσημο)', '3X2TABS (Γενόσημο)']) {
		const it = PD.parsePrescription(HEAD + 'FOOSPAR TAB 10MG BTx30\n' + wrap + '\nΔΟΣΟΛΟΓΙΑ : ' + DAILY() + '\n' + P).items[0];
		assertNotSilent(PD, it, wrap);
	}
	/* the real status wrap stays quiet */
	const ok = PD.parsePrescription(HEAD + 'FOOSPAR TAB 10MG BTx30\n(Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : ' + DAILY() + '\n' + P).items[0];
	assert.strictEqual(PD.rxItemProblem(settled(ok)), '', ok.warnings.join(','));
});

test('a dose that does not fit the form of the box goes to the form (unitForm)', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, dose] of [
		['FOOSPAR TAB 10MG BTx30', '1 ΕΝΕΣΗ x 1 φορά την ημέρα x 30 ημέρες'],
		['FOOTAREN SUPP 100MG BTx10', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 5 ημέρες'],
		['FOOTAREN F.C.TAB 50MG BTx20', '1 ΥΠΟΘΕΤΑ x 1 φορά την ημέρα x 5 ημέρες']
	]) {
		const it = parse(PD, drug, dose).items[0];
		assert.ok(it.warnings.includes('unitForm'), drug + ' ' + it.warnings);
		assert.strictEqual(it.doseUnit, '', drug);
		assertNotSilent(PD, it, drug);
		assert.notStrictEqual(PD.prescriptionWarningText('unitForm'), 'unitForm');
	}
	/* matching pairs stay quiet */
	for (const [drug, dose] of [
		['FOOTAREN SUPP 100MG BTx10', '1 ΥΠΟΘΕΤΑ x 1 φορά την ημέρα x 5 ημέρες'],
		['FOOVAG VAG.TAB 500MG BTx6', '1 ΚΟΛΠΙΚΑ ΔΙΣΚΙΑ x 1 φορά την ημέρα x 6 ημέρες'],
		['FOOSPAR TAB 10MG BTx30', DAILY()]
	]) {
		const it = parse(PD, drug, dose).items[0];
		assert.ok(!it.warnings.includes('unitForm'), drug + ' ' + it.warnings);
	}
});

test('insulin written in ml or mg is never re-read as units', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const dose of ['22 ML x 1 φορά την ημέρα x 30 ημέρες', '2 ML x 1 φορά την ημέρα x 30 ημέρες']) {
		const it = parse(PD, 'LANTUS INJ.SOL 100U/ML BTx5 PENSx3ML', dose).items[0];
		assert.ok(it.warnings.includes('insulinMl'), dose + ' ' + it.warnings);
		assert.notStrictEqual(it.doseUnit, 'iu', dose);
		assertNotSilent(PD, Object.assign({}, it, { confirmed: { name: true, qty: true, freq: true, days: true } }), dose);
	}
	/* units stay units, with the usual confirmation */
	const it = parse(PD, 'LANTUS INJ.SOL 100U/ML BTx5 PENSx3ML', '22 ΜΟΝΑΔΕΣ x 1 φορά την ημέρα x 30 ημέρες').items[0];
	assert.strictEqual(it.doseUnit, 'iu');
	assert.ok(it.warnings.includes('iuConfirm'));
});
