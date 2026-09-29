/* 1.27.0 round 5: every character after the first medicine is consumed
   by a fully matched known pattern, or it is an unknown fragment that is
   flagged. The reviewer's named cases (verify/rx10.js, rx11.js). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEADER = 'ΣΤΟΙΧΕΙΑ ΙΑΤΡΟΥ ΣΤΟΙΧΕΙΑ ΑΣΘΕΝΗ\nΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΑ.Μ.Κ.Α. : 00000000000 Α.Μ.Κ.Α. : 00000000000\nΔΙΑΓΝΩΣΗ : X00 Διάγνωση\nΣΥΜΠΛΗΡΩΝΕΤΑΙ ΑΠΟ ΤΟΝ ΦΑΡΜΑΚΟΠΟΙΟ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75';
const DEP = 'DEPON TAB 500MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες';
const BES = 'BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες';
const FOOT = '0% 10% 25% Άλλο\n0,00 0,00 1,00 0,00\nΣΥΝΟΛΟ : 1,00 €\nΚολλήστε\nταινία';
const J = (v) => JSON.parse(JSON.stringify(v));

/* [name, text, the fragment that must be caught] */
const CASES = [
	['instrAfterTotals', HEADER + DEP + '\n' + P + '\n' + FOOT + '\nΣε περίπτωση πυρετού μόνο', 'Σε περίπτωση πυρετού μόνο'],
	['instrAfterFooterLine', HEADER + DEP + '\n' + P + '\n0% 10% 25% Άλλο\nΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n0,00 0,00 1,00 0,00\nΣΥΝΟΛΟ : 1,00 €', 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['priceJoinedInstr', HEADER + DEP + '\n' + P + ' ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n' + BES + '\n' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['priceJoinedLatin', HEADER + DEP + '\n' + P + ' TAKE WITH FOOD\n' + BES + '\n' + P, 'TAKE WITH FOOD'],
	['totalsJoinedInstr', HEADER + DEP + '\n' + P + '\n0% 10% 25% Άλλο ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n0,00 0,00 1,00 0,00\nΣΥΝΟΛΟ : 1,00 €', 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['moneyLikeSchedule', HEADER + 'SINTROM TAB 4MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n0,25 0,50 0,25 0,50 0,25 0,50 0,75\n' + P, '0,25 0,50 0,25 0,50 0,25 0,50 0,75'],
	['moneyLikeAfterPrice', HEADER + 'SINTROM TAB 4MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + P + '\n0,25 0,50 0,25\n' + BES + '\n' + P, '0,25 0,50 0,25'],
	['statusAbsorb1', HEADER + 'DEPON TAB 500MG/TAB BTx20\n(Γενόσημο) SOS\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\n' + P, '(Γενόσημο) SOS'],
	['statusAbsorb2', HEADER + 'SINTROM TAB 4MG/TAB BTx20\nΚΥΡΙΑΚΗ 2MG (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + P, 'ΚΥΡΙΑΚΗ 2MG'],
	['statusAbsorb3', HEADER + 'SINTROM TAB 4MG/TAB BTx20\nΜΙΣΟ) (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + P, 'ΜΙΣΟ)'],
	['boilerStart', HEADER + DEP + '\n' + P + '\nΣυμ. Ποσότητα: ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n' + BES + '\n' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['boilerSign', HEADER + DEP + '\n' + P + '\n(ΥΠΟΓΡΑΦΗ) ΣΟΣ ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n' + BES + '\n' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['pageBreakMidList', HEADER + DEP + '\n' + P + '\nΣελίδα 1 από 2\nΕΛΛΗΝΙΚΗ ΔΗΜΟΚΡΑΤΙΑ\nΣε περίπτωση πυρετού\n' + HEADER + BES + '\n' + P, 'Σε περίπτωση πυρετού'],
	['pageBreakInstrBeforeHeader', HEADER + DEP + '\n' + P + '\nΣελίδα 1 από 2\nΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\nΣΤΟΙΧΕΙΑ ΙΑΤΡΟΥ ΣΤΟΙΧΕΙΑ ΑΣΘΕΝΗ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n' + BES + '\n' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['barcodeInstr', HEADER + DEP + '\n' + P + '\n' + FOOT + '\nDEPON TAB 500MG/TAB BTx20\nΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\nBarcode: 0000000000000', 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['headerLabelJoin', HEADER + DEP + '\n' + P + '\nΑ.Μ.Κ.Α. : 0 ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n' + BES + '\n' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['pdfJoinedAll', HEADER.replace(/\n/g, ' ') + DEP.replace(/\n/g, ' ') + ' ' + P + ' ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ ' + BES.replace(/\n/g, ' ') + ' ' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['pdfJoinedPairs', HEADER + DEP.replace(/\n/g, ' ') + ' ' + P + '\nΜΟΝΟ ΑΝ ΠΟΝΑΕΙ ' + BES.replace(/\n/g, ' ') + ' ' + P, 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ'],
	['instrAsBrand', HEADER + DEP + '\n' + P + '\nSWITCH TO PLAVIX TAB 75MG BTx28 AFTER 5 DAYS\n' + BES + '\n' + P, 'AFTER 5 DAYS'],
	['instrAsBrand2', HEADER + DEP + '\n' + P + '\nMAX 3 TABS 500MG/DAY FROM BTx20\n' + BES + '\n' + P, 'MAX 3 TABS 500MG/DAY FROM BTx20'],
	['instrAsBrandBetween', HEADER + 'SINTROM TAB 4MG/TAB BTx20\nALTERNATE WITH SINTROM TAB 1MG BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + P, 'ALTERNATE WITH']
];

const flat = (t) => String(t || '').replace(/\s+/g, ' ');

function review(text) {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	const d = env.w.document;
	const drop = d.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new env.w.Event('input', { bubbles: true }));
	return env;
}

for (const [name, text, frag] of CASES) {
	test('round 5 [' + name + ']: caught, never in a name, nothing added without the pharmacist', () => {
		const { PD } = load();
		const r = PD.parsePrescription(text);
		const owners = r.items.filter((i) => flat(i.origText).includes(frag) || flat(i.source).includes(frag));
		const noted = (r.unreadText || []).some((t) => flat(t).includes(frag));
		assert.ok(owners.length || noted, 'not caught');
		for (const it of owners) {
			assert.strictEqual(PD.rxItemProblem(it), 'warnings', it.name);
			assert.ok(it.warnings.includes('extra') || it.warnings.includes('noDose'), it.name);
		}
		for (const it of r.items) {
			assert.ok(!flat(it.name).includes(frag), it.name);
			assert.ok(/^(SINTROM|BESPAR|DEPON|PLAVIX)\b|^$/.test(it.name), 'name starts with the drug line: ' + it.name);
		}
		closeAll();
		const env = review(text);
		const add = env.w.document.getElementById('pd-rx-add');
		if (add && !add.disabled) {
			add.click();
		}
		/* No row that carries the fragment is added. */
		assert.ok(env.PD.s.items.every((i) => !owners.some((o) => o.name === i.name && o.doseAmount === i.doseAmount)), name);
	});
}

test('[pdfJoinedPairs] the name is the drug line only', () => {
	const { PD } = load();
	const r = PD.parsePrescription(CASES.find((c) => c[0] === 'pdfJoinedPairs')[1]);
	assert.deepStrictEqual(J(r.items.map((i) => i.name)), ['DEPON TAB 500MG', 'BESPAR TAB 10MG']);
	assert.ok(r.items.every((i) => i.warnings.includes('extra') && i.warnings.includes('asNeeded')));
});

test('[moneyLikeSchedule] a numeric weekday schedule is shown and marks a variable dose; SINTROM is not added as a fixed 1/4', () => {
	const env = review(HEADER + 'SINTROM TAB 4MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1/4 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n0,25 0,50 0,25 0,50 0,25 0,50 0,75\n' + P + '\n');
	const { PD, w } = env;
	const it = PD.s.rx.rows[0].item;
	assert.ok(it.warnings.includes('variableDose'));
	assert.ok(it.warnings.includes('extra'));
	assert.match(w.document.querySelector('.pd-rx-orig-text').textContent, /0,25 0,50 0,25 0,50 0,25 0,50 0,75/);
	assert.strictEqual(w.document.getElementById('pd-rx-inc-0').disabled, true);
	assert.match(PD.prescriptionWarningText('variableDose'), /σταθερή δόση/);
});

test('a wrapped price row: only the exact missing money fields are its tail', () => {
	const { PD } = load();
	const ok = PD.parsePrescription(HEADER + DEP + '\n25% 1 8,95 6,91\n6,91 2,04 1,73 5,18\n' + BES + '\n' + P);
	assert.ok(ok.items.every((i) => !i.warnings.includes('extra')), 'the 4 missing fields are the tail');
	const bad = PD.parsePrescription(HEADER + DEP + '\n25% 1 8,95 6,91\n6,91 2,04 1,73\n' + BES + '\n' + P);
	assert.ok(bad.items[0].warnings.includes('extra'), 'three fields where four are missing: not a tail');
});

test('the blocking note names text outside the medicine list', () => {
	const env = review(CASES[0][1]);
	const note = env.w.document.getElementById('pd-rx-count-note');
	assert.ok(note);
	assert.match(note.textContent, /Υπάρχει κείμενο στη συνταγή που δεν διαβάστηκε: «Σε περίπτωση πυρετού μόνο»/);
	assert.strictEqual(env.w.document.getElementById('pd-rx-add').disabled, true);
});

test('legitimate layouts still read: a drug line joined to the previous price row; barcode repeats; a drug after the barcode page', () => {
	const { PD } = load();
	const joined = PD.parsePrescription(HEADER + DEP + '\n' + P + ' BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n' + P);
	assert.deepStrictEqual(J(joined.items.map((i) => [i.name, i.warnings])), [['DEPON TAB 500MG', []], ['BESPAR TAB 10MG', []]]);
	const barcode = PD.parsePrescription(HEADER + DEP + '\n' + P + '\n' + FOOT + '\nDEPON TAB 500MG/TAB BTx20\nBarcode: 0000000000000\nPC: 00000000000000\nSN: 00000000\nBatch: 0000\nBESPAR TAB 10MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n' + P);
	assert.deepStrictEqual(J(barcode.items.map((i) => [i.name, i.warnings])), [['DEPON TAB 500MG', []], ['BESPAR TAB 10MG', []]]);
	assert.deepStrictEqual(J(barcode.unreadText), []);
});
