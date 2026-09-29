/* 1.27.1 round 8: the verifier's repros (verify/rx15.js, rx16.js, rx17.js)
   against the round-7 relaxations. A Greek mu before «G» is never folded
   to «MG»; furniture only with the values seen on real print-outs; after
   the box count only the real pack continuations; brand numbers, «/WORD»
   and «INN/MAH» only as seen; a sticker repeat only in the sticker area.
   Synthetic strings only. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEADER = 'ΣΤΟΙΧΕΙΑ ΙΑΤΡΟΥ ΣΤΟΙΧΕΙΑ ΑΣΘΕΝΗ\nΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75';
const DEP = 'DEPON TAB 500MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες';
const BES = 'BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες';
const FOOT = '0% 10% 25% Άλλο\n0,00 0,00 1,00 0,00\nΣΥΝΟΛΟ : 1,00 €';
const IN = (s) => HEADER + DEP + '\n' + P + '\n' + s + '\n' + BES + '\n' + P + '\n' + FOOT + '\n';
const AFTER = (s) => HEADER + DEP + '\n' + P + '\n' + BES + '\n' + P + '\n' + FOOT + '\n' + s + '\n';
const ONE = (drug, dose) => HEADER + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + (dose || '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') + '\n' + P + '\n';
const STK = (drug) => drug + '\nBarcode: 0000000000000\nPC: 11111111111111\nSN: 2222AB22\nBatch: 33\n';
const J = (v) => JSON.parse(JSON.stringify(v));
const settled = (PD, it) => PD.rxItemProblem(Object.assign({}, it, { dailyTime: 'morning', customWeekday: 1 }));

/* Nothing in the paste can be added without the pharmacist: every
   medicine is flagged, or the paste has a blocking note. */
function guarded(PD, text, tag) {
	const r = PD.parsePrescription(text);
	const note = (r.unreadText && r.unreadText.length > 0) || r.expectedCount > r.readCount || r.unreadDoseLines > 0;
	assert.ok(note || r.items.every((it) => settled(PD, it) !== ''), tag + ' ' + JSON.stringify(r.items.map((i) => [i.name, i.warnings])));
	return r;
}

/* ---- M1 ---- */

test('M1: a Greek mu / micro sign before G is never «MG»: every combination', () => {
	const { PD } = load();
	const mus = ['μ', 'Μ', 'µ'];
	const gs = ['g', 'G', 'γ', 'Γ'];
	for (const mu of mus) {
		for (const g of gs) {
			for (const sp of ['', ' ']) {
				const unit = mu + g;
				const it = PD.parsePrescription(ONE('EUTHYROX TAB 100' + sp + unit + '/TAB BTx50')).items[0];
				const tag = JSON.stringify(sp + unit);
				assert.ok(!/100 ?MG/.test(it.name), tag + ' → ' + it.name);
				if ((mu === 'μ' || mu === 'µ') && g === 'g') {
					/* The plain lowercase micrograms. */
					assert.strictEqual(it.name, 'EUTHYROX TAB 100MCG', tag);
				} else {
					assert.strictEqual(settled(PD, it), 'warnings', tag + ' ' + it.warnings);
					assert.ok(it.warnings.includes('strength') || it.warnings.includes('name'), tag + ' ' + it.warnings);
				}
			}
		}
	}
	/* Latin M with a Greek gamma is not «MG» either; Latin MG is. */
	const lat = PD.parsePrescription(ONE('EUTHYROX TAB 100MΓ/TAB BTx50')).items[0];
	assert.ok(!/100MG/.test(lat.name));
	assert.strictEqual(settled(PD, lat), 'warnings');
	assert.strictEqual(PD.parsePrescription(ONE('EUTHYROX TAB 100MG/TAB BTx50')).items[0].name, 'EUTHYROX TAB 100MG');
	/* «μgr» after a number: not read. */
	assert.strictEqual(settled(PD, PD.parsePrescription(ONE('EUTHYROX TAB 100μgr/TAB BTx50')).items[0]), 'warnings');
});

test('M1: in the dose phrase too, «ΜG» / «μG» / «µG» is never mg', () => {
	const { PD } = load();
	for (const u of ['ΜG', 'μG', 'µG', 'ΜΓ']) {
		const it = PD.parsePrescription(ONE('FOO TAB 100MCG/TAB BTx50', '100 ' + u + ' x 1 φορά την ημέρα x 30 ημέρες')).items[0];
		assert.notStrictEqual(it.doseUnit, 'mg', u);
		assert.strictEqual(settled(PD, it), 'warnings', u);
	}
});

/* ---- M2 ---- */

test('M2: furniture with a value that was never seen is unknown text (in the list: flags; after it: the note)', () => {
	const { PD } = load();
	const lines = ['ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ', 'ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΚΥΗΣΗ ΚΑΙ ΛΟΧΕΙΑ ΠΡΩΙ', 'P.O.', 'Π.Χ.-Μ.Φ.', 'Π.Ο.Τ.',
		'Barcode: SOS', 'Barcode: 0000', 'PC: PRN', 'PC: 1111', 'SN: HS', 'SN: STOPSTOP', 'Batch: STOP-3D', 'Batch: STOP', 'Batch: 1234567890',
		'Μόνο Αν Πονάει 1234567 1', 'Μόνο Αν Πονάει 0000000000000 130'];
	for (const l of lines) {
		const inList = PD.parsePrescription(IN(l));
		assert.ok(inList.items[0].warnings.includes('extra'), 'in ' + l);
		guarded(PD, IN(l), 'in ' + l);
		const after = guarded(PD, AFTER(l), 'after ' + l);
		assert.ok(after.unreadText.includes(l), 'after ' + l);
	}
});

test('M2: the values seen on real print-outs are still furniture', () => {
	const { PD } = load();
	for (const l of ['ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΚΥΗΣΗ ΚΑΙ ΛΟΧΕΙΑ', 'ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΝΕΦΡΟΠΑΘΕΙΣ ΣΕ ΑΙΜΟΚΑΘΑΡΣΗ',
		'ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΤΕΛ. ΣΤΑΔ. ΧΡΟΝ. ΝΕΦΡΙΚΗΣ ΝΟΣΟΥ ΕΞΩΝΕΦΡΙΚΗ ΚΑΘΑΡΣΗ', 'O.N.', 'Ι.Κ.Α.-E.T.A.M.',
		'Barcode: 0000000000000', 'PC: 00000000000000', 'SN: ABCDE1FGHI', 'SN: 0000000000000000', 'Batch: 12', 'Batch: A12B', 'Batch: 25-025', 'Batch: 123456789',
		'Οργανισμός Γεωργικών Ασφαλίσεων 0000000000000 120', 'Οίκος Ναύτου 0000000000000 120']) {
		const r = PD.parsePrescription(AFTER(l));
		assert.deepStrictEqual(J(r.unreadText), [], l);
	}
});

/* ---- pack continuations ---- */

test('after the box count only the real pack continuations: an extra count, «x N» or «TABS» is not the drug line', () => {
	const { PD } = load();
	const bad = [
		ONE('DEPON TAB 500MG/TAB BTx20 3X5'),
		ONE('DEPON TAB 500MG/TAB BTx20 1X2'),
		ONE('DEPON TAB 500MG/TAB\nBTx20 X 2 TABS'),
		ONE('DEPON TAB 500MG/TAB BTx20 X2'),
		ONE('DEPON TAB 500MG/TAB BTx20 2 TABS'),
		ONE('DEPON TAB 500MG/TAB BT x 20 x 3'),
		ONE('DEPON TAB 500MG/TAB BTx20 BLIST TABS')
	];
	for (const t of bad) {
		const r = guarded(PD, t, t);
		assert.ok(r.items.every((it) => settled(PD, it) !== ''), t);
	}
	for (const line of ['DEPON TAB 500MG/TAB BTx20 3X5', 'DEPON TAB 500MG/TAB BTx20 1X2', 'DEPON TAB 500MG/TAB BTx20 X 2 TABS']) {
		assert.strictEqual(PD.rxIsDrugLine(line), false, line);
	}
	/* The shapes of the real print-outs stay drug lines. */
	for (const line of ['FOO GR.CAP 40MG/CAP BTX1 VIAL HDPE X 31 CAPS', 'FOO TAB 40MG/TAB BT x 36 (BLIST 3x12)', 'FOO F.C.TAB 5MG/TAB BTX 60 Tabs - Blister (PVC/PVDC/alu)',
		'FOO GR.CAP 40MG/CAP BTx1 VIALX28 CAPS', 'FOO INJ.SOL 5MC/ML BTx5 AMP x 1 ML', 'FOO TAB 100MG/TAB BT x 60 (6 x 10)', 'FOO F.C.TAB 20MG/TAB BTx30 TABS',
		'FOO PS.INJ.SUS BTx 1 VIAL+1 PF.SYR. x 0,5 ML SOLV ( 1 Δόσ.) + 2 βελόνες', 'FOO NASPR.SUS (137+50)MCG/ACTUATION BTX 1 ΦΙΑΛΗ(25ML) X 23G',
		'FOO INH.SUS.N 1MG/2ML BTx30x2 ML', 'FOO OR.SOL.SD 2,810G/10ML BTx20 VIALSx10 ML', 'FOO F.C.TAB 100MG 4X10']) {
		assert.strictEqual(PD.rxIsDrugLine(line), true, line);
	}
});

/* ---- brand exceptions ---- */

test('brand numbers, «/WORD» and «INN/MAH» only as seen; anything else asks or is flagged', () => {
	const { PD } = load();
	const text = (drug) => HEADER + DEP + '\n' + P + '\n' + drug + '\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n' + P + '\n';
	for (const drug of ['BESPAR 2 TAB 10MG/TAB BTx30', 'BESPAR 2 X TAB 10MG/TAB BTx30', 'BESPAR /PM TAB 10MG/TAB BTx30', 'NIGHTLY/ORAL ROSUVA F.C.TAB 10MG/TAB BTx30',
		'FOOSTATIN/ACME PHARMA F.C.TAB 10MG/TAB BTx30', 'FOOSTATIN/WIN PM F.C.TAB 10MG/TAB BTx30']) {
		const r = PD.parsePrescription(text(drug));
		const it = r.items[r.items.length - 1];
		assert.strictEqual(settled(PD, it), 'warnings', drug + ' ' + it.warnings);
	}
	for (const [drug, name] of [['ORBIMAG 300 EF.TAB 300MG/TAB BTx20', 'ORBIMAG 300 EF.TAB 300MG'], ['FOOMAG 250 EF.TAB 250MG/TAB BTx20', 'FOOMAG 250 EF.TAB 250MG'],
		['FUSIDIC /TARGET CREAM 2% TUBX30G', 'FUSIDIC /TARGET CREAM 2%'], ['FOOSTATIN/WIN MEDICA F.C.TAB 10MG/TAB BTx30', 'FOOSTATIN/WIN MEDICA F.C.TAB 10MG'],
		['FOOMIN/MEDICAL PHARMAQUALITY F.C.TAB 20MG/TAB BTx30', 'FOOMIN/MEDICAL PHARMAQUALITY F.C.TAB 20MG']]) {
		const it = PD.parsePrescription(text(drug)).items[1];
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('brandWords') && !it.warnings.includes('extra'), drug + ' ' + it.warnings);
	}
});

/* ---- rx16: sticker repeats only in the sticker area ---- */

test('rx16: a drug line right after a price row, before a barcode line, is a medicine without a dose line — not a sticker', () => {
	const { PD } = load();
	const S = 'SINTROM TAB 4MG/TAB BTx20 (Γενόσημο)';
	const D = 'ΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';
	const second = PD.parsePrescription(HEADER + S + '\n' + D + '\n' + P + '\n' + S + '\nBarcode: 0000000000000\n');
	assert.strictEqual(second.items.length, 2);
	assert.ok(second.items[1].warnings.includes('noDose'));
	assert.ok(second.expectedCount > second.readCount, 'the count note blocks');
	/* In the sticker area (after the totals, after another sticker, on a
	   page after the totals): a repeat, ignored. */
	const stickers = PD.parsePrescription(HEADER + S + '\n' + D + '\n' + P + '\n' + FOOT + '\n' + STK(S) + STK(S));
	assert.strictEqual(stickers.items.length, 1);
	assert.deepStrictEqual(J(stickers.unreadText), []);
	const page2 = PD.parsePrescription(HEADER + S + '\n' + D + '\n' + P + '\n' + FOOT + '\nΣελίδα 1 από 2\n' + STK(S) + HEADER + BES + '\n' + P + '\n' + FOOT + '\n' + STK('BESPAR TAB 10MG/TAB BTx30'));
	assert.deepStrictEqual(J(page2.items.map((i) => i.name)), ['SINTROM TAB 4MG', 'BESPAR TAB 10MG']);
	/* The same drug twice with two doses, then its stickers: two medicines. */
	const twice = PD.parsePrescription(HEADER + S + '\n' + D + '\n' + P + '\n' + S + '\nΔΟΣΟΛΟΓΙΑ : 1/2 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + P + '\n' + FOOT + '\n' + STK(S) + STK(S));
	assert.strictEqual(twice.items.length, 2);
	assert.strictEqual(twice.expectedCount, twice.readCount);
	/* A sticker with an instruction in it is not a repeat. */
	guarded(PD, HEADER + S + '\n' + D + '\n' + P + '\n' + FOOT + '\n' + S + '\nΜΟΝΟ ΑΝ INR<2\nBarcode: 0000000000000\n', 'stickerWithInstr');
	/* Unknown text above a real sticker stays unknown (note); the sticker is still a repeat. */
	const above = PD.parsePrescription(HEADER + S + '\n' + D + '\n' + P + '\n' + FOOT + '\nΚΑΤΙ ΑΓΝΩΣΤΟ\n' + STK(S));
	assert.strictEqual(above.items.length, 1);
	assert.deepStrictEqual(J(above.unreadText), ['ΚΑΤΙ ΑΓΝΩΣΤΟ']);
});

/* ---- rx17: the review flow of the verifier ---- */

test('rx17: bulk time never overwrites, never unlocks another check; nothing unconfirmed is added', () => {
	const env = load();
	const { PD, w } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	const d = w.document;
	const L = (b, dose, q) => b + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + P.replace('25% 1 ', '25% ' + (q || 1) + ' ') + '\n';
	const text = HEADER +
		L('SINTROM TAB 4MG/TAB BTx20 (Γενόσημο)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
		L('SINTROM TAB 4MG/TAB BTx20 (Γενόσημο)', '1/2 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
		L('BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
		L('OZEMPIC INJ.SOL 1MG/DOSE BTx1 PF.PENS (Πρωτότυπο)', '1 ΕΝΕΣΗ x 1 φορά την ημέρα x 30 ημέρες') +
		L('ATROST F.C.TAB 40MG/TAB BTx30 (Γενόσημο)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες', 3);
	const drop = d.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new w.Event('input', { bubbles: true }));
	d.getElementById('pd-rx-t-2-noon').click();
	d.getElementById('pd-rx-bulk-evening').click();
	const rows = PD.s.rx.rows.map((r) => r.item);
	assert.strictEqual(rows[2].dailyTime, 'noon', 'a chosen time is kept');
	assert.ok(rows[3].warnings.includes('weeklyOnly'));
	assert.strictEqual(rows[3].dailyTime, null, 'a medicine sent to the form is not touched');
	assert.ok(rows[4].warnings.includes('dispensedQty'));
	assert.strictEqual(PD.rxItemProblem(rows[4]), 'warnings', 'the time does not settle the quantity');
	d.getElementById('pd-rx-add').click();
	assert.ok(!PD.s.items.some((i) => /ATROST|OZEMPIC|SINTROM/.test(i.name)), J(PD.s.items.map((i) => i.name)));
});
