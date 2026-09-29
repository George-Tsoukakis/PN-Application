/* 1.27.4 round 12: batch 4 (R372–R388). Single-dose oral solution vials
   (SOLUMAG, confirmed by a pharmacist), two drug-line wraps (TOUJEO
   DOUBLESTAR, METHOX-F with its needle). Synthetic strings in the shape
   of the real lines. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const J = (v) => JSON.parse(JSON.stringify(v));
const parse = (PD, drug, dose) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + P);
const SD = 'FOOMAG FORTE OR.SOL.SD 2,810G/10ML BTx20 VIALSx10 ML (Γενόσημο)';
const DOSES = (n) => n + ' ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ x 1 φορά την ημέρα x 28 ημέρες';

test('«N ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ» of a single-dose oral solution in vials is N vials («Αμπούλα»)', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, n] of [[SD, '1'], [SD, '2'], ['FOOMAG OR.SOL.SD 1G/10ML BT x 10 AMPS x 10ML', '1'], ['FOOMAG OR.SOL.SD 1G/10ML BTx20 φιαλίδια', '1']]) {
		const it = parse(PD, drug, DOSES(n)).items[0];
		assert.strictEqual(it.doseUnit, 'ampoule', drug);
		assert.strictEqual(it.doseAmount, Number(n), drug);
		assert.deepStrictEqual(J(it.warnings), ['time'], drug);
	}
	/* The quantity check counts vials: 20 for 28, shown, never a warning. */
	const it = parse(PD, SD, DOSES('1')).items[0];
	assert.deepStrictEqual(J(PD.rxQuantityCheck(it)), { dispensed: 20, needed: 28, reliable: false, warn: false, short: false });
});

test('anything else than exactly that shape keeps the hard unit warning', () => {
	const { PD } = load();
	const bad = [
		['FOO3-SOLE OR.SOL.SD 25000IU/2,5ML BTX 4 VIALS X2,5ML (Γενόσημο)', '1 ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ 5ML x 1 φορά την εβδομάδα x 28 ημέρες'],
		['FOOFER PS.OR.SOL 300MG/15G VIAL BT x 10 VIALS (Γενόσημο)', '1 ΠΟΣ.ΣΚΟΝΗ ΔΟΣ ΔΙΑΛΥΜΑ x 2 φορές την ημέρα x 30 ημέρες'],
		[SD, DOSES('1/2')],
		[SD, DOSES('1,5')],
		['FOOMAG FORTE OR.SOL 2,810G/10ML BTx20 VIALSx10 ML', DOSES('1')],
		['FOOMAG FORTE OR.SOL.SD 2,810G/10ML FLx200 ML', DOSES('1')],
		[SD, '1 ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ ML x 1 φορά την ημέρα x 28 ημέρες'],
		[SD, '1 ΠΟΣ.ΔΙΑΛΥΜΑ ML x 1 φορά την ημέρα x 28 ημέρες']
	];
	for (const [drug, dose] of bad) {
		const it = parse(PD, drug, dose).items[0];
		assert.notStrictEqual(it.doseUnit, 'ampoule', drug + ' | ' + dose);
		if ('ml' !== it.doseUnit) {
			assert.strictEqual(PD.rxItemProblem(Object.assign({}, it, { dailyTime: 'morning', customWeekday: 1 })), 'warnings', drug + ' | ' + dose);
		}
	}
});

test('TOUJEO «(DOUBLESTAR) X 3ML (Πρωτότυπο)» is a wrap; the insulin units warning stays', () => {
	const { PD } = load();
	const drug = 'TOUJEO (DOUBLESTAR) IN.SO.PF.P 300 Units/ml BT X 3 PF. PEN\n(DOUBLESTAR) X 3ML (Πρωτότυπο)';
	for (const dose of ['1/2 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες']) {
		const r = parse(PD, drug, dose);
		const it = r.items[0];
		assert.strictEqual(it.name, 'TOUJEO (DOUBLESTAR) IN.SO.PF.P 300 IU/ML');
		assert.deepStrictEqual(J(it.warnings), ['insulinUnits', 'time']);
		assert.deepStrictEqual(J(r.unreadText), []);
		assert.ok(it.origText.startsWith(drug + '\n'));
	}
	/* An instruction in that place is still flagged. */
	const odd = parse(PD, 'TOUJEO (DOUBLESTAR) IN.SO.PF.P 300 Units/ml BT X 3 PF. PEN\n(DOUBLESTAR) X 2 ΤΟ ΠΡΩΙ (Πρωτότυπο)', '20 ΜΟΝΑΔΕΣ x 1 φορά την ημέρα x 30 ημέρες').items[0];
	assert.ok(odd.warnings.includes('extra'));
});

test('METHOX-F «+ ΕΝΣΩΜΑΤΩΜΕΝΗ» / «ΒΕΛΟΝΑ (Γενόσημο)» is a wrap; weekly methotrexate as before', () => {
	const { PD } = load();
	const drug = 'METHOX-F INJ.SO.PFS 15MG/0,4ML BT X 1 PF.SYR (0,4ML) + ΕΝΣΩΜΑΤΩΜΕΝΗ\nΒΕΛΟΝΑ (Γενόσημο)';
	const weekly = parse(PD, drug, '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 28 ημέρες').items[0];
	assert.strictEqual(weekly.name, 'METHOX-F INJ.SO.PFS 15MG/0,4ML');
	assert.deepStrictEqual(J(weekly.warnings), ['weekDay']);
	assert.strictEqual(PD.rxIsDrugLine('FOO INJ.SO.PFS 15MG/0,4ML BT X 1 PF.SYR (0,4ML) ΜΕ ΕΝΣΩΜΑΤΩΜΕΝΗ ΒΕΛΟΝΑ'), true);
	const daily = parse(PD, drug, '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες').items[0];
	assert.ok(daily.warnings.includes('methotrexateDaily'));
});
