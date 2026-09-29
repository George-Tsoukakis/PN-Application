/* 1.27.0: prescription reading — medicines that were silently dropped,
   continuation lines, strengths, long lines, weekly/monthly, plausibility
   (review items 2, 3, 5, 7, 8, 12, 13). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
/* Round 4: a drug line is recognised by its full ΗΔΙΚΑ signature (brand,
   form, strength, pack). These tests are about the dose phrase, so a pack
   is added where the test name has none. */
const withPack = (drug) => (/(^|[^A-Za-z])(BT|FL|TUB|AMP|VIAL)|ΒΤ/i.test(drug) ? drug : drug + ' BTx1');
const one = (drug, dose) => HEAD + withPack(drug) + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + PRICE;
const J = (v) => JSON.parse(JSON.stringify(v));
const ZIN = 'ZINADOL F.C.TAB 500MG/TAB BTx10';
const BESPAR = 'BESPAR TAB 10MG/TAB BTx30';
const DOSE2 = '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες';
const DOSE1 = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';

/* (2) */
test('«ΔΟΣΟΛΟΓΙΑ» with Latin look-alike letters, soft hyphen, zero-width characters or NBSP is still read', () => {
	const { PD } = load();
	const anchors = [
		'ΔOΣOΛOΓIA :',            /* Latin O, I, A */
		'ΔΟΣΟΛΟΓΙA :',            /* Latin A only */
		'ΔοσoλoγIα:',             /* mixed case and scripts */
		'ΔΟΣΟ\u00adΛΟΓΙΑ :',      /* soft hyphen */
		'ΔΟΣ\u200bΟΛΟΓΙΑ :',      /* zero-width space */
		'Δ\u200dΟΣΟΛΟΓΙΑ\u2060 :', /* zero-width joiner, word joiner */
		'\ufeffΔΟΣΟΛΟΓΙΑ :',      /* BOM */
		'ΔΟΣΟΛΟΓΙΑ\u00a0:\u00a0',  /* NBSP around the colon */
		'ΔΟΣΟΛΟΓΙΑ\t:',
		'ΔΟΣΟΛΟΓΙΑ\u00a0:',
		'ΔΟΣΟΛΟΓΙΑ\n:',            /* colon on the next line */
		'ΔΟΣΟΛΟΓΙΑ\u0301 :'   /* decomposed accent → NFC */
	];
	for (const a of anchors) {
		const text = HEAD + ZIN + '\n' + a + ' ' + DOSE2 + '\n' + PRICE + BESPAR + '\nΔΟΣΟΛΟΓΙΑ : ' + DOSE1 + '\n' + PRICE;
		const r = PD.parsePrescription(text);
		assert.deepStrictEqual(J(r.items.map((i) => [i.name, i.freq, i.days])),
			[['ZINADOL F.C.TAB 500MG', '12h', '7'], ['BESPAR TAB 10MG', '24h', '30']], JSON.stringify(a));
		assert.strictEqual(r.expectedCount, 2, JSON.stringify(a));
		assert.strictEqual(r.readCount, 2, JSON.stringify(a));
	}
});

test('NBSP and invisible characters inside the dose phrase are read too', () => {
	const { PD } = load();
	const r = PD.parsePrescription(one(ZIN, '1\u00a0ΔΙΣ\u00adΚΙΑ x\u00a02 φορές την ημ\u200bέρα x 7\u00a0ημέρες'));
	assert.strictEqual(r.items[0].freq, '12h');
	assert.strictEqual(r.items[0].doseUnit, 'tablet');
	assert.deepStrictEqual(J(r.items[0].warnings), []);
});

test('a medicine block whose dose line cannot be found is listed, and the count mismatch is reported', () => {
	const { PD } = load();
	/* «ΔΟΣΟΛΟΓΙΑ ;» cannot be recognised — but the price row is there. */
	const text = HEAD + ZIN + '\nΔΟΣΟΛΟΓΙΑ ; ' + DOSE2 + '\n' + PRICE + BESPAR + '\nΔΟΣΟΛΟΓΙΑ : ' + DOSE1 + '\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.strictEqual(r.expectedCount, 2);
	assert.strictEqual(r.readCount, 1);
	assert.strictEqual(r.items.length, 2);
	assert.strictEqual(r.items[0].name, 'ZINADOL F.C.TAB 500MG');
	/* The unreadable dose line is shown and flagged on it (round 4). */
	assert.deepStrictEqual(J(r.items[0].warnings), ['noDose', 'extra']);
	assert.match(r.items[0].origText, /ΔΟΣΟΛΟΓΙΑ ;/);
	assert.strictEqual(PD.rxItemProblem(r.items[0]), 'warnings');
	/* A block with a price row and no dose line at all. */
	const r2 = PD.parsePrescription(HEAD + 'FOO CAPS 20MG/CAP BTx14\n' + PRICE + BESPAR + '\nΔΟΣΟΛΟΓΙΑ : ' + DOSE1 + '\n' + PRICE);
	assert.deepStrictEqual(J(r2.items.map((i) => [i.name, i.warnings.join(',')])), [['FOO CAPS 20MG', 'noDose'], ['BESPAR TAB 10MG', 'time']]);
	assert.strictEqual(r2.expectedCount, 2);
	assert.strictEqual(r2.readCount, 1);
});

test('price rows joined to the dose line (mid-line) are counted once per medicine', () => {
	const { PD } = load();
	const text = HEAD + ZIN + '\nΔΟΣΟΛΟΓΙΑ : ' + DOSE2 + ' 25% 1 6,00 6,00 6,00 0,00 1,50 4,50\n' +
		BESPAR + ' ΔΟΣΟΛΟΓΙΑ : ' + DOSE1 + ' 25% 1 6,90 5,91 5,91 0,99 1,48 4,43\n0% 10% 25% Άλλο\n0,00 0,00 12,00 0,00\nΣΥΝΟΛΟ : 12,00 €\n';
	const r = PD.parsePrescription(text);
	assert.strictEqual(r.items.length, 2);
	assert.strictEqual(r.expectedCount, 2);
	assert.strictEqual(r.readCount, 2);
});

test('every sample prescription: all medicines read, no count mismatch', () => {
	const { PD } = load();
	const dir = path.join(__dirname, 'rx-samples');
	for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.txt'))) {
		const r = PD.parsePrescription(fs.readFileSync(path.join(dir, f), 'utf8'));
		assert.ok(r.expectedCount >= 1, f);
		assert.strictEqual(r.expectedCount, r.readCount, f);
		assert.ok(!r.items.some((i) => i.warnings.includes('noDose')), f);
	}
});

/* (3) */
test('repro: «SOS σε περίπτωση πόνου» after the dose line is flagged, not dropped', () => {
	const { PD } = load();
	const text = HEAD + 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\nSOS σε περίπτωση πόνου\n' + PRICE +
		BESPAR + '\nΔΟΣΟΛΟΓΙΑ : ' + DOSE1 + '\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.strictEqual(r.items.length, 2);
	const dep = r.items[0];
	assert.ok(dep.warnings.includes('extra'));
	assert.ok(dep.warnings.includes('asNeeded'));
	assert.match(dep.source, /SOS σε περίπτωση πόνου/);
	assert.match(dep.origText, /SOS σε περίπτωση πόνου/);
	assert.strictEqual(PD.rxItemProblem(dep), 'warnings');
	assert.strictEqual(r.items[1].name, 'BESPAR TAB 10MG');
});

test('continuation lines that are not a medicine: flagged on the medicine above', () => {
	const { PD } = load();
	const cases = [
		['SOS σε περίπτωση πόνου', true],
		['INR ελέγχος κάθε εβδομάδα', false],
		['AN ΠΟΝΑΕΙ', true],              /* Latin A and N */
		['αν χρειαστεί', true],
		['εάν έχει πυρετό', true],
		['2', false],
		['1/2', false],
		['PRN', true]
	];
	for (const [line, asNeeded] of cases) {
		/* With and without a price row between it and the next medicine. */
		for (const sep of [PRICE, '']) {
			const text = HEAD + 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\n' + line + '\n' + sep +
				BESPAR + '\nΔΟΣΟΛΟΓΙΑ : ' + DOSE1 + '\n' + PRICE;
			const r = PD.parsePrescription(text);
			const tag = line + (sep ? ' +price' : '');
			assert.strictEqual(r.items.length, 2, tag);
			assert.ok(r.items[0].warnings.includes('extra'), tag);
			assert.strictEqual(r.items[0].warnings.includes('asNeeded'), asNeeded, tag);
			assert.strictEqual(r.items[1].name, 'BESPAR TAB 10MG', tag);
			assert.strictEqual(PD.rxItemProblem(r.items[0]), 'warnings', tag);
		}
	}
});

test('a line that is not a full drug line right before the next «ΔΟΣΟΛΟΓΙΑ» is never a name; both medicines are flagged', () => {
	const { PD } = load();
	const text = HEAD + 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\nMOVICOL\nΔΟΣΟΛΟΓΙΑ : 1 ΦΑΚΕΛΟΣ x 1 φορά την ημέρα x 10 ημέρες\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.strictEqual(r.items.length, 2);
	assert.ok(r.items[0].warnings.includes('extra'));
	/* Round 4: no drug line above → no name; the form asks for it. */
	assert.strictEqual(r.items[1].name, '');
	assert.ok(r.items[1].warnings.includes('name'));
	assert.ok(r.items[1].warnings.includes('extra'));
	assert.match(r.items[1].origText, /MOVICOL/);
});

test('the next medicine right after a dose line (compressed copy) is still a name, not a continuation', () => {
	const { PD } = load();
	const text = HEAD + 'DELIPOST F.C.TAB 10MG/TAB BTx28\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες\n' +
		'TOUJEO (SOLOSTAR) IN.SO.PF.P 300 IU/ML BTx3 PF.PENS\nΔΟΣΟΛΟΓΙΑ : 22 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες\n' +
		'CARVEDILEN F.C.TAB 12,5MG/TAB BTX30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.deepStrictEqual(J(r.items.map((i) => i.warnings.includes('extra'))), [false, false, false]);
	assert.strictEqual(r.items[1].doseUnit, 'iu');
	assert.strictEqual(r.items[2].name, 'CARVEDILEN F.C.TAB 12,5MG');
});

test('«SOS» or «σε περίπτωση» inside the dose line itself is a hard warning', () => {
	const { PD } = load();
	for (const d of ['1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες SOS', '1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες σε περίπτωση πόνου', '1 ΔΙΣΚΙΑ x SOS x 5 ημέρες']) {
		const it = PD.parsePrescription(one('DEPON TAB 500MG/TAB BTx20', d)).items[0];
		assert.ok(it.warnings.includes('asNeeded'), d);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', d);
	}
	const plainIt = PD.parsePrescription(one('DEPON TAB 500MG/TAB BTx20', '1 ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 3 φορές την ημέρα x 5 ημέρες')).items[0];
	assert.ok(!plainIt.warnings.includes('asNeeded'));
});

/* (5) */
test('strengths: combinations with slashes, units in U, nothing from the pack', () => {
	const { PD } = load();
	const cases = [
		['FOSTER AER.MD.INH 100/6MCG/DOSE BTx1x180 DOSES', 'FOSTER AER.MD.INH 100/6MCG/δόση'],
		['SERETIDE DISKUS INH.PD.DOS 50/500MCG/DOSE BTx60', 'SERETIDE DISKUS INH.PD.DOS 50/500MCG/δόση'],
		['LANTUS SOLOSTAR INJ.SOL 100U/ML BTx5 PENSx3ML', 'LANTUS SOLOSTAR INJ.SOL 100U/ML'],
		['FOO TAB 5/10/20MG BTx30', 'FOO TAB 5/10/20MG'],
		['NORDIMET INJ.SOL 15MG/0,6ML BTX 1PF.SYR X0,6ML', 'NORDIMET INJ.SOL 15MG/0,6ML'],
		['PRENODOM ORAL.SOL 10MG/ML BT X 1BOTTLE X30ML', 'PRENODOM ORAL.SOL 10MG/ML'],
		['FOSTER NEXTHALER PD.INH.MD (100+6)MC/DOSE BTx1Χ120 ΔΟΣΕΙΣ', 'FOSTER NEXTHALER PD.INH.MD (100+6)MCG/δόση']
	];
	for (const [drug, name] of cases) {
		const it = PD.parsePrescription(one(drug, '1 ΕΙΣΠΝΟΕΣ x 2 φορές την ημέρα x 10 ημέρες')).items[0];
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('strength'), drug);
	}
});

test('a strength found only after the pack is never the strength (never «3ML»); the name is read (1.27.2: nothing before the pack, nothing to confirm)', () => {
	const { PD } = load();
	const it = PD.parsePrescription(one('FOO INJ.SOL BTx5 PENSx3ML', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 10 ημέρες')).items[0];
	/* Round 7: a drug line with no strength (a vaccine) is a drug line;
	   its name is always confirmed («strength»), never completed. */
	assert.strictEqual(it.name, 'FOO INJ.SOL');
	assert.ok(!/3ML/.test(it.name));
	assert.ok(!it.warnings.includes('strength'));
	assert.ok(!it.warnings.includes('name'));
	assert.match(it.origText, /FOO INJ\.SOL BTx5 PENSx3ML/);
	assert.strictEqual(PD.rxItemProblem(Object.assign({}, it, { dailyTime: 'morning' })), '');
});

/* (7) */
test('a very long dose line is refused quickly with a warning', () => {
	const { PD } = load();
	const long = '1 ' + 'ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 1 '.repeat(4000) + ' x 3 ημέρες';
	const t0 = Date.now();
	const r = PD.parsePrescription(one('FOO TAB 5MG/TAB BTx30', long) + one(ZIN, DOSE2));
	assert.ok(Date.now() - t0 < 1500, 'took ' + (Date.now() - t0) + ' ms');
	assert.deepStrictEqual(J(r.items[0].warnings), ['tooLong']);
	assert.ok(r.items[0].source.length < 300);
	assert.strictEqual(PD.rxItemProblem(r.items[0]), 'warnings');
	assert.strictEqual(r.items[1].name, 'ZINADOL F.C.TAB 500MG');
	/* Just under the cap: read normally. */
	const pad = ' '.repeat(10);
	const ok = PD.parsePrescription(one(ZIN, DOSE2 + pad)).items[0];
	assert.ok(!ok.warnings.includes('tooLong'));
	/* A long wrapped dose line spread over several lines is refused too. */
	const wrapped = PD.parsePrescription(HEAD + ZIN + '\nΔΟΣΟΛΟΓΙΑ : 1 ' + 'Α'.repeat(600) + '\n' + 'Β'.repeat(600) + '\nx 2 φορές την ημέρα x 7 ημέρες\n' + PRICE).items[0];
	assert.ok(wrapped.warnings.includes('tooLong'));
});

test('no lookbehind regex is left in the tool code (Safari < 16.4)', () => {
	const dir = require('./lib/env.js').findJsDir();
	for (const f of ['rx-text.js', 'rx-lines.js', 'rx-parse.js', 'rx-review.js', 'rx-import.js', 'state.js', 'validation.js', 'preview.js', 'medicine-form.js', 'app.js']) {
		const src = fs.readFileSync(path.join(dir, f), 'utf8');
		assert.ok(!/\(\?<[=!]/.test(src), f);
	}
});

test('the Latin look-alike folding still reads «ΕΙΣΠΝOΕΣ» and leaves «ML» alone', () => {
	const { PD } = load();
	assert.strictEqual(PD.parsePrescription(one('FOO 5MG BTx1', '2 ΕΙΣΠΝOΕΣ ΣΚΟΝΗ ΔΟΣΕΙΣ x 2 φορές την ημέρα x 10 ημέρες')).items[0].doseUnit, 'inhale');
	assert.strictEqual(PD.parsePrescription(one('FOO OR.SOL 10MG/ML', '3 ΠΟΣ.ΔΙΑΛΥΜΑ ML x 1 φορά την ημέρα x 2 ημέρες')).items[0].doseUnit, 'ml');
});

/* (8) */
test('more than 4 tablets per intake is flagged for confirmation', () => {
	const { PD } = load();
	const it = PD.parsePrescription(one('FOO TAB 5MG/TAB BTx30', '6 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 3 ημέρες')).items[0];
	assert.ok(it.warnings.includes('highQty'));
	assert.deepStrictEqual(J(PD.rxPending(it)), [{ code: 'highQty', field: 'qty', kind: 'confirm' }]);
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
	it.confirmed = { qty: true };
	assert.strictEqual(PD.rxItemProblem(it), '');
	const four = PD.parsePrescription(one('FOO TAB 5MG/TAB BTx30', '4 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 3 ημέρες')).items[0];
	assert.ok(!four.warnings.includes('highQty'));
	const ml = PD.parsePrescription(one('FOO OR.SOL 10MG/ML', '40 ML x 1 φορά την ημέρα x 3 ημέρες')).items[0];
	assert.ok(ml.warnings.includes('highQty'));
});

/* (12) (13) */
test('«1 φορά τον μήνα» is a day of the month to choose, never every 30 days', () => {
	const { PD } = load();
	const it = PD.parsePrescription(one('PROLIA INJ.SOL 60MG/ML BTx1', '1 ΕΝΕΣΗ x 1 φορά τον μήνα x 60 ημέρες')).items[0];
	assert.strictEqual(it.freq, 'custom');
	assert.strictEqual(it.customMode, 'monthday');
	assert.strictEqual(it.customMonthDay, null);
	assert.strictEqual(it.customIntervalDays, '');
	assert.ok(it.warnings.includes('monthly'));
	assert.match(PD.prescriptionWarningText('monthly'), /τελευταία ημέρα του μήνα/);
	assert.ok(!/30 ημέρες/.test(PD.prescriptionWarningText('monthly')));
	assert.strictEqual(PD.rxItemProblem(it), 'warnings', 'cannot be skipped');
	it.customMonthDay = 10;
	/* Round 3: then the number of doses is confirmed (always, for monthly). */
	assert.ok(it.warnings.includes('monthlyCount'));
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
	it.confirmed = { days: true };
	assert.strictEqual(PD.rxItemProblem(it), '');
});

test('weekly is a weekday to choose; «every 2 weeks» stays every 14 days', () => {
	const { PD } = load();
	const wk = PD.parsePrescription(one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 1 φορά την εβδομάδα x 28 ημέρες')).items[0];
	assert.strictEqual(wk.customMode, 'weekday');
	assert.strictEqual(wk.customWeekday, null);
	assert.ok(wk.warnings.includes('weekDay'));
	assert.match(PD.prescriptionWarningText('weekDay'), /επιλέξτε ημέρα της εβδομάδας/);
	const bi = PD.parsePrescription(one('FOO INJ.SOL 10MG/ML BTx1', '1 ΕΝΕΣΗ x κάθε 2 εβδομάδες x 28 ημέρες')).items[0];
	assert.strictEqual(bi.customMode, 'days');
	assert.strictEqual(bi.customIntervalDays, '14');
	assert.ok(bi.warnings.includes('startDay'));
	/* 1.27.2: and the number of doses, with the dates in view. */
	assert.ok(bi.warnings.includes('intervalCount'));
	/* The first dose falls on the start date: confirmed with the dates in view. */
	assert.strictEqual(PD.rxItemProblem(bi), 'warnings');
	bi.confirmed = { freq: true, days: true };
	assert.strictEqual(PD.rxItemProblem(bi), '');
});

test('once a day: no time preset', () => {
	const { PD } = load();
	const it = PD.parsePrescription(one(BESPAR, DOSE1)).items[0];
	assert.strictEqual(it.dailyTime, null);
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
	it.dailyTime = 'noon';
	assert.strictEqual(PD.rxItemProblem(it), '');
});

test('original text: the drug lines, the dose line and a continuation, without prices', () => {
	const { PD } = load();
	const text = HEAD + 'NEXIUM GR.TAB 20MG/TAB BTx28 (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες 25% 1 6,00 3,73\nπριν το φαγητό\n' + PRICE;
	const it = PD.parsePrescription(text).items[0];
	assert.strictEqual(it.origText, 'NEXIUM GR.TAB 20MG/TAB BTx28 (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)\nΔΟΣΟΛΟΓΙΑ: 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\nπριν το φαγητό');
});
