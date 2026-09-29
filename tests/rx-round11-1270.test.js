/* 1.27.3 round 11: batch 3 (10 more real print-outs) — four drug-line
   variants read without needless flags; what must still ask, asks.
   Synthetic strings in the shape of the real lines. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const J = (v) => JSON.parse(JSON.stringify(v));
const parse = (PD, drug, dose) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + ' ' + P);
const DROPS = '2 ΟΦΘΑΛΜΙΚΕΣ ΣΤΑΓΟΝΕΣ ΔΙΑΛΥΜΑ x 2 φορές την ημέρα x 28 ημέρες';

test('single-dose containers wrapped before «ML»: one drug line, the original line breaks kept', () => {
	const { PD } = load();
	const drug = 'FOOFREE EY.DR.S.DC 2MG/ML BTX30 ΠΕΡΙΕΚΤΕΣ ΜΙΑΣ ΔΟΣΗΣ LDPE X 0,35\nML (Γενόσημο)';
	const r = parse(PD, drug, DROPS);
	assert.strictEqual(r.items.length, 1);
	assert.strictEqual(r.items[0].name, 'FOOFREE EY.DR.S.DC 2MG/ML');
	assert.deepStrictEqual(J(r.items[0].warnings), []);
	assert.deepStrictEqual(J(r.unreadText), []);
	assert.ok(r.items[0].origText.startsWith(drug + '\n'), r.items[0].origText);
	/* Only exactly that: «ΜΙΑΣ» elsewhere, or no «ML» on the next line, is not taken in. */
	for (const bad of ['FOOFREE EY.DR.S.DC 2MG/ML BTX30 ΜΙΑΣ ΔΟΣΗΣ LDPE X 0,35\nML (Γενόσημο)',
		'FOOFREE EY.DR.S.DC 2MG/ML BTX30 ΠΕΡΙΕΚΤΕΣ ΜΙΑΣ ΔΟΣΗΣ LDPE X 0,35\nΜΙΣΟ (Γενόσημο)',
		'FOOFREE EY.DR.S.DC 2MG/ML BTX30 ΠΕΡΙΕΚΤΕΣ ΜΙΑΣ ΔΟΣΗΣ LDPE X 0,35\nΤΟ ΒΡΑΔΥ']) {
		const x = parse(PD, bad, DROPS);
		assert.ok(x.items.some((it) => it.warnings.includes('extra') || it.warnings.includes('name')) || x.unreadText.length > 0, bad);
	}
});

test('a bottle «(OVAL)», an ear solution «EA.SOL EA, SOL FL x 10ML» with no strength', () => {
	const { PD } = load();
	const nev = parse(PD, 'FOOVANAC EY.DRO.SUS 3MG/ML BTx1 LDPE BOTTLE (OVAL) x 3 ML (Πρωτότυπο)', '1 ΟΦΘ.ΣΤΑΓΟΝΕΣ x 1 φορά την ημέρα x 20 ημέρες').items[0];
	assert.strictEqual(nev.name, 'FOOVANAC EY.DRO.SUS 3MG/ML');
	assert.deepStrictEqual(J(nev.warnings), ['time']);
	const ear = parse(PD, 'FOOTICIN EA.SOL EA, SOL FL x 10ML (Πρωτότυπο)', '2 ΟΤΙΚΟ ΣΤΑΓΟΝΕΣ x 2 φορές την ημέρα x 5 ημέρες');
	assert.strictEqual(ear.items[0].name, 'FOOTICIN EA.SOL');
	assert.strictEqual(ear.items[0].doseUnit, 'drops');
	assert.deepStrictEqual(J(ear.items[0].warnings), []);
	assert.deepStrictEqual(J(ear.unreadText), []);
	/* «EA,» only as that repeat right after «EA.SOL». */
	assert.strictEqual(PD.rxIsDrugLine('FOOTICIN EA.SOL EA, SOL FL x 10ML'), true);
	assert.strictEqual(PD.rxIsDrugLine('FOOTICIN EA.SOL FL x 10ML EA, SOL'), false);
	assert.strictEqual(PD.rxIsDrugLine('FOOTICIN TAB EA, SOL BTx10'), false);
	assert.strictEqual(PD.rxIsDrugLine('FOOTICIN EA.SOL EA, TAB FL x 10ML'), false);
	assert.strictEqual(PD.rxIsDrugLine('FOOVANAC EY.DRO.SUS 3MG/ML BTx1 LDPE BOTTLE OVAL x 3 ML'), false);
});

test('combination suffixes HCT, H, AM, COMP, DUO, MET are brand qualifiers; other glued words still ask', () => {
	const { PD } = load();
	for (const q of ['HCT', 'H', 'AM', 'COMP', 'DUO', 'MET']) {
		const it = parse(PD, 'FOOSIMIA ' + q + ' F.C.TAB (5+160+12,5)MG/TAB BTx30 σε blisters (Γενόσημο)', '1 ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 1 φορά την ημέρα x 28 ημέρες').items[0];
		assert.ok(!it.warnings.includes('brandWords'), q);
	}
	for (const w of ['PO', 'EXTRA', 'XX']) {
		const it = parse(PD, w + ' FOOSIMIA F.C.TAB (5+160+12,5)MG/TAB BTx30 (Γενόσημο)', '1 ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 1 φορά την ημέρα x 28 ημέρες').items[0];
		assert.ok(it.warnings.includes('brandWords') || it.warnings.includes('extra'), w);
	}
});

test('kept as they are: a unitless eye-drop strength asks, Greek «ΒΤΧ20(BLIST 2X10 )» reads clean', () => {
	const { PD } = load();
	const tob = parse(PD, 'FOOBREX EY.DRO.SOL 0,003 BTx1 FLx5ML (Πρωτότυπο)', '2 ΟΦΘ.ΣΤΑΓΟΝΕΣ x 3 φορές την ημέρα x 8 ημέρες').items[0];
	assert.strictEqual(tob.name, 'FOOBREX EY.DRO.SOL');
	assert.deepStrictEqual(J(tob.warnings), ['strength']);
	const sin = parse(PD, 'SINTROM TAB 4MG/TAB ΒΤΧ20(BLIST 2X10 ) (Πρωτότυπο)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες').items[0];
	assert.strictEqual(sin.name, 'SINTROM TAB 4MG');
	assert.deepStrictEqual(J(sin.warnings), ['time']);
	assert.strictEqual(sin.pack.count, 20);
});
