/* 1.25.0: the cases the independent review of the prescription import
   found, one test each. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, isoAhead, closeAll } = require('./harness');
test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
/* Round 4: a drug line is recognised by its full ΗΔΙΚΑ signature (brand,
   form, strength, pack). These tests are about the dose phrase, so a pack
   is added where the test name has none. */
const withPack = (drug) => (/(^|[^A-Za-z])(BT|FL|TUB|AMP|VIAL)|ΒΤ/i.test(drug) ? drug : drug + ' BTx30');
const one = (drug, dose) => HEAD + withPack(drug) + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + PRICE;
const TAB = 'ZINADOL F.C.TAB 500MG/TAB BTx10';
const first = (PD, text) => PD.parsePrescription(text).items[0];
const J = (x) => JSON.parse(JSON.stringify(x));

/* (1) A dose written as a mass is never read as tablets / ml. */
test('dose phrase in MG → unit mg, not the form', () => {
	const { PD } = load();
	const it = first(PD, one(TAB, '20 MG x 1 φορά την ημέρα x 7 ημέρες'));
	assert.strictEqual(it.doseUnit, 'mg');
	assert.strictEqual(it.doseAmount, 20);
});

test('dose phrase in MCG or G → no unit, flagged', () => {
	const { PD } = load();
	for (const d of ['100 MCG x 1 φορά την ημέρα x 7 ημέρες', '1 G x 2 φορές την ημέρα x 7 ημέρες']) {
		const it = first(PD, one(TAB, d));
		assert.strictEqual(it.doseUnit, '', d);
		assert.ok(it.warnings.includes('unit'), d);
		assert.ok(PD.rxItemProblem(it), d);
	}
});

test('dose phrase in units (ΜΟΝΑΔΕΣ / IU) → iu, to be confirmed', () => {
	const { PD } = load();
	const it = first(PD, one('HUMALOG KWIKPEN INJ.SOL 100U/ML', '10 ΜΟΝΑΔΕΣ x 3 φορές την ημέρα x 30 ημέρες'));
	assert.strictEqual(it.doseUnit, 'iu');
	assert.ok(it.warnings.includes('iuConfirm'));
});

test('a form word AND a mass in the phrase («1 ΔΙΣΚΙΟ 20 MG») → not guessed', () => {
	const { PD } = load();
	for (const d of ['1 ΔΙΣΚΙΟ 20 MG', '1 ΔΙΣΚΙΟ MG', '5 ML 250 MG']) {
		const it = first(PD, one(TAB, d + ' x 1 φορά την ημέρα x 7 ημέρες'));
		assert.strictEqual(it.doseUnit, '', d);
		assert.ok(it.warnings.includes('unit') || it.warnings.includes('phrase'), d);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', d);
	}
});

test('ml in the phrase of an oral solution still ml', () => {
	const { PD } = load();
	const it = first(PD, one('FOO OR.SOL 10MG/ML', '5 ML x 2 φορές την ημέρα x 5 ημέρες'));
	assert.strictEqual(it.doseUnit, 'ml');
	assert.strictEqual(it.doseAmount, 5);
});

/* (2) Mixed numbers, ranges, numbers split by the line break. */
test('mixed numbers are read whole: 1 1/2, 2 1/2, 1 ½, 1½', () => {
	const { PD } = load();
	for (const [q, v] of [['1 1/2', 1.5], ['2 1/2', 2.5], ['1 ½', 1.5], ['1½', 1.5]]) {
		const it = first(PD, one(TAB, q + ' ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες'));
		assert.strictEqual(it.doseAmount, v, q);
		assert.strictEqual(it.doseUnit, 'tablet', q);
		assert.deepStrictEqual(J(it.warnings), [], q);
	}
});

test('a range «1 - 2» or «1 ή 2» is not read as 1', () => {
	const { PD } = load();
	for (const q of ['1 - 2', '1 ή 2', '1 έως 2']) {
		const it = first(PD, one(TAB, q + ' ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες'));
		assert.notStrictEqual(it.doseAmount, 1, q);
		assert.ok(it.warnings.includes('amount'), q);
		assert.ok(PD.rxItemProblem(it), q);
	}
});

test('a number cut by the line break is not trusted (quantity, frequency, days)', () => {
	const { PD } = load();
	const cases = [
		'1\n0 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες',
		'1\n1/2 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες',
		'1 ΔΙΣΚΙΑ x 1\n2 φορές την ημέρα x 7 ημέρες',
		'1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 1\n4 ημέρες'
	];
	for (const c of cases) {
		const it = first(PD, one(TAB, c));
		assert.ok(PD.rxItemProblem(it), c);
		assert.ok(it.warnings.includes('dose') || it.warnings.includes('amount'), c);
	}
	/* A wrap between words is fine. */
	const ok = first(PD, one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 14\nημέρες'));
	assert.strictEqual(ok.days, '14');
	assert.strictEqual(PD.rxItemProblem(ok), '');
});

test('«ΔΟΣΟΛΟΓΙΑ :» alone on its line → the quantity is flagged', () => {
	const { PD } = load();
	const it = first(PD, one(TAB, '\n1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες').replace('ΔΟΣΟΛΟΓΙΑ : \n', 'ΔΟΣΟΛΟΓΙΑ :\n'));
	assert.ok(PD.rxItemProblem(it));
});

/* (3) The form never defaults what the prescription did not give. */
function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

function paste(env, text) {
	const d = env.w.document;
	/* The drop zone: whatever lands in it is read on the input event. */
	const drop = d.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new env.w.Event('input', { bubbles: true }));
}

test('«Συμπλήρωση στη φόρμα»: unknown frequency and unit are left to choose; add refuses until chosen', () => {
	const env = app();
	const { PD, w } = env;
	const d = w.document;
	paste(env, one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την εβδομάδα x 28 ημέρες') +
		one('FOO TAB 5MG', '2 ΤΕΜΑΧΙΑ x 1 φορά την ημέρα x 5 ημέρες'));
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.rxNeedsFreq, true);
	assert.strictEqual(d.querySelectorAll('.plandose-chip[data-val].active').length, 0);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0, 'no frequency chosen → not added');
	/* Choosing a frequency (here «Εξειδικευμένη», every 7 days) unlocks it. */
	d.querySelector('.plandose-chip[data-val="custom"]').click();
	assert.strictEqual(PD.s.rxNeedsFreq, false);
	PD.setFieldValue('pd-custom-interval-days', '7');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	assert.strictEqual(PD.s.items[0].freq, 'custom');

	/* Unknown unit: «Είδος» empty, add refused until chosen. */
	const idx = PD.s.rx.rows.findIndex((r) => !r.loaded);
	d.querySelector('[data-rx-toform="' + idx + '"]').click();
	assert.strictEqual(d.getElementById('pd-dose-unit').value, '');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'no unit chosen → not added');
	PD.setFieldValue('pd-dose-unit', 'sachet');
	/* 1.27.0: once a day from a prescription: the time is chosen too. */
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'no time chosen → not added');
	d.querySelector('.pd-daily-time-chip[data-time="evening"]').click();
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 2);
	assert.strictEqual(PD.s.items[1].doseUnit, 'sachet');
});

test('«Καθαρισμός» clears the pending frequency choice', () => {
	const env = app();
	const { PD, w } = env;
	paste(env, one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την εβδομάδα x 28 ημέρες'));
	w.document.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.rxNeedsFreq, true);
	PD.resetDrugForm();
	assert.strictEqual(PD.s.rxNeedsFreq, false);
});

/* (10) The row stays, marked as loaded. */
test('a medicine sent to the form stays in the list, marked, and is not added twice', () => {
	const env = app();
	const { PD, w } = env;
	const d = w.document;
	paste(env, one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την εβδομάδα x 28 ημέρες') +
		one('BESPAR TAB 10MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.rx.rows.length, 2);
	assert.ok(PD.s.rx.rows[0].loaded);
	assert.strictEqual(d.querySelectorAll('.pd-rx-row.is-loaded').length, 1);
	PD.resetDrugForm();
	/* 1.27.0: the once-a-day time is chosen in the row first. */
	assert.ok(d.getElementById('pd-rx-add').disabled);
	d.querySelector('[data-rx-row="1"][data-rx-time="morning"]').click();
	d.getElementById('pd-rx-add').click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => i.name)), ['BESPAR TAB 10MG']);
	assert.strictEqual(PD.s.items[0].dailyTime, 'morning');
	assert.strictEqual(PD.s.rx, null);
});

/* (4) Methotrexate. */
test('methotrexate: Greek name, wrapped brand, name only in the lines → flagged and blocked', () => {
	const { PD } = load();
	const cases = [
		one('ΜΕΘΟΤΡΕΞΑΤΗ TAB 2,5MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες'),
		HEAD + 'METHOTREX\nATE/EBEWE TAB 2,5MG/TAB BTx50\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες\n' + PRICE
	];
	for (const c of cases) {
		const it = first(PD, c);
		assert.ok(it.warnings.includes('methotrexateDaily'), c);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', c);
	}
});

test('methotrexate: every 2 days is too often; weekly, monthly and weekday are fine', () => {
	const { PD } = load();
	const base = { name: 'METHOTREXATE/EBEWE TAB', freq: 'custom', customMode: 'days' };
	assert.strictEqual(PD.methotrexateTooOften(Object.assign({}, base, { customIntervalDays: '2' })), true);
	assert.strictEqual(PD.methotrexateTooOften(Object.assign({}, base, { customIntervalDays: '7' })), false);
	assert.strictEqual(PD.methotrexateTooOften(Object.assign({}, base, { customIntervalDays: '30' })), false);
	assert.strictEqual(PD.methotrexateTooOften(Object.assign({}, base, { customMode: 'weekday' })), false);
	assert.strictEqual(PD.methotrexateTooOften({ name: 'ΜΕΘΟΤΡΈΞΑΤΗ', freq: '24h' }), true);
	assert.strictEqual(PD.methotrexateTooOften({ name: 'ZINADOL', freq: '24h' }), false);
});

test('methotrexate daily is never added by «Προσθήκη στο πλάνο», even if the warning was lost', () => {
	const env = app();
	const { PD, w } = env;
	paste(env, one('METHOTREXATE/EBEWE TAB 2,5MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες') +
		one('BESPAR TAB 10MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	PD.s.rx.rows[0].item.warnings = [];
	PD.s.rx.rows[0].include = true;
	w.document.querySelector('[data-rx-row="1"][data-rx-time="evening"]').click();
	w.document.getElementById('pd-rx-add').click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => i.name)), ['BESPAR TAB 10MG']);
});

/* (5) Insulin. */
test('insulin by generic name or strength «U/ML» → units', () => {
	const { PD } = load();
	for (const drug of ['INSULIN GLARGINE/BIOSIM IN.SO.PF.P 100U/ML', 'NEWBRAND IN.SO.PF.P 100 U/ML']) {
		const it = first(PD, one(drug, '12 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες'));
		assert.strictEqual(it.doseUnit, 'iu', drug);
		assert.ok(it.warnings.includes('iuConfirm'), drug);
	}
});

/* (6) Route. */
test('inhalation capsules are inhalations; vaginal tablets are suppositories', () => {
	const { PD } = load();
	let it = first(PD, one('SPIRIVA INH.CAPS 18MCG', '1 ΚΑΨΟΥΛΑ ΓΙΑ ΕΙΣΠΝΟΗ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.strictEqual(it.doseUnit, 'inhale');
	it = first(PD, one('GYNO VAG.TAB 100MG', '1 ΚΟΛΠΙΚΑ ΔΙΣΚΙΑ x 1 φορά την ημέρα x 6 ημέρες'));
	assert.strictEqual(it.doseUnit, 'suppository');
});

/* (7) A week is never daily; extra text after «ημέρες». */
test('a daily-looking phrase that mentions a week is not daily', () => {
	const { PD } = load();
	const it = first(PD, one(TAB, '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα 5 ημέρες την εβδομάδα x 28 ημέρες'));
	assert.strictEqual(it.freq, null);
	assert.ok(it.warnings.includes('freqWeekly'));
});

test('words after «x N ημέρες» are flagged; prices on the same line are not', () => {
	const { PD } = load();
	let it = first(PD, one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες και μετά 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα'));
	assert.ok(it.warnings.includes('extra'));
	assert.ok(PD.rxItemProblem(it));
	it = first(PD, one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες 25% 1 6,00 6,00 6,00 0,00 1,50 4,50'));
	assert.ok(!it.warnings.includes('extra'));
	assert.strictEqual(PD.rxItemProblem(it), '');
});

test('separators Χ / χ (Greek) are read like x', () => {
	const { PD } = load();
	const it = first(PD, one(TAB, '1 ΔΙΣΚΙΑ Χ 2 φορές την ημέρα Χ 7 ημέρες'));
	assert.strictEqual(it.freq, '12h');
	assert.strictEqual(it.days, '7');
});

/* (8) Duplicates and patients. */
test('the same medicine twice, or one already in the plan, is shown but not selected', () => {
	const env = app();
	const { PD, w } = env;
	PD.s.items.push({ name: 'BESPAR TAB 10MG', doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: 1, days: '30', notes: '' });
	const z = one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες');
	paste(env, z + z + one('BESPAR TAB 10MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	const rows = PD.s.rx.rows;
	assert.deepStrictEqual(J(rows.map((r) => r.include)), [true, false, false]);
	assert.ok(rows[1].item.warnings.includes('duplicate'));
	assert.ok(rows[2].item.warnings.includes('inPlan'));
	w.document.getElementById('pd-rx-add').click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => i.name)), ['BESPAR TAB 10MG', 'ZINADOL F.C.TAB 500MG']);
});

const patientHead = (s, f) => 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ' + s + '\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ' + f + '\n';

test('prescriptions of two patients in one paste: name not filled, warning shown', () => {
	const env = app();
	const { PD, w } = env;
	paste(env, patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες') +
		patientHead('ΒΗΤΑ', 'ΔΕΥΤΕΡΟΣ') + one('BESPAR TAB 10MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.strictEqual(PD.s.rx.usePatient, false);
	assert.match(PD.s.rx.patientNote, /ΑΛΦΑ ΠΡΩΤΟΣ, ΒΗΤΑ ΔΕΥΤΕΡΟΣ/);
	assert.ok(w.document.querySelector('.pd-rx-note'));
	w.document.getElementById('pd-rx-add').click();
	assert.strictEqual(w.document.getElementById('pd-patient').value, '');
});

test('the same patient on two pasted prescriptions is one patient', () => {
	const { PD } = load();
	const r = PD.parsePrescription(patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες') +
		patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + one('BESPAR TAB 10MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.deepStrictEqual(J(r.patients), ['ΑΛΦΑ ΠΡΩΤΟΣ']);
});

test('a different patient already in the plan: kept, and the pharmacist is told', () => {
	const env = app();
	const { PD, w } = env;
	w.document.getElementById('pd-patient').value = 'ΓΑΜΜΑ ΤΡΙΤΟΣ';
	paste(env, patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες'));
	assert.strictEqual(PD.s.rx.usePatient, false);
	assert.match(PD.s.rx.patientNote, /ΓΑΜΜΑ ΤΡΙΤΟΣ/);
	w.document.getElementById('pd-rx-add').click();
	assert.strictEqual(w.document.getElementById('pd-patient').value, 'ΓΑΜΜΑ ΤΡΙΤΟΣ');
});

/* (9) A name never absorbs the tail of a wrapped dose line. */
test('the tail of a wrapped dose line is not part of the next name', () => {
	const { PD } = load();
	const text = HEAD + 'BESPAR TAB 10MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ\nx 1 φορά την ημέρα x 28 ημέρες\nZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.deepStrictEqual(J(r.items.map((i) => [i.name, i.freq, i.days])), [['BESPAR TAB 10MG', '24h', '28'], ['ZINADOL F.C.TAB 500MG', '12h', '7']]);
});

/* (11) Line endings and spacer lines. */
test('CR-only line endings and a blank line after every line read the same', () => {
	const fs = require('fs');
	const path = require('path');
	const { PD } = load();
	const dir = path.join(__dirname, 'rx-samples');
	const sig = (t) => JSON.stringify(PD.parsePrescription(t).items.map((i) => [i.name, i.doseAmount, i.doseUnit, i.freq, i.days, i.warnings]));
	for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.txt'))) {
		const t = fs.readFileSync(path.join(dir, f), 'utf8');
		assert.strictEqual(sig(t.replace(/\n/g, '\r')), sig(t), f + ' (CR)');
		assert.strictEqual(sig(t.replace(/\n/g, '\n\n')), sig(t), f + ' (blank lines)');
	}
});

/* ---- Second review (whitelist reading) ---- */

test('R2-1: «ΔΟΣΟΛΟΓΙΑ» on the name line, after a bullet or in lower case is still found', () => {
	const { PD } = load();
	const text = HEAD +
		'XOZAL F.C.TAB 5MG/TAB BTx30 (Γενόσημο) ΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 1 φορά την ημέρα x 30 ημέρες 25% 1 6,00\n' +
		'BESPAR TAB 10MG/TAB BTx30\n• ΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + PRICE +
		'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔοσολογία : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.deepStrictEqual(J(r.items.map((i) => [i.name, i.freq, i.days])),
		[['XOZAL F.C.TAB 5MG', '24h', '30'], ['BESPAR TAB 10MG', '24h', '30'], ['ZINADOL F.C.TAB 500MG', '12h', '7']]);
});

test('R2-2: anything more in the frequency or unit text is not read', () => {
	const { PD } = load();
	const cases = [
		'1 ΔΙΣΚΙΑ x κάθε 6 ώρες επί πόνου x 5 ημέρες',
		'1 ΔΙΣΚΙΑ x 1 φορά την ημέρα κάθε 2 ημέρες x 10 ημέρες',
		'1 ΔΙΣΚΙΑ x μέρα παρά μέρα x 10 ημέρες',
		'1 ΔΙΣΚΙΑ x 3 φορές την ημέρα για 3 ημέρες και μετά 2 x 7 ημέρες',
		'1 ΔΙΣΚΙΑ ΠΡΩΙ ΚΑΙ 2 ΒΡΑΔΥ x 2 φορές την ημέρα x 7 ημέρες',
		'1 ΔΙΣΚΙΑ ΕΠΙΚΑΛ ΚΑΙ 1/2 x 2 φορές την ημέρα x 7 ημέρες',
		'1 ΔΙΣΚΙΑ x 21 φορά την εβδομάδα x 28 ημέρες',
		'1 ΔΙΣΚΙΑ x 1 φορά την ημέρα εκτός Σαββατοκύριακου x 28 ημέρες'
	];
	for (const c of cases) {
		const it = first(PD, one(TAB, c));
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', c + ' → ' + JSON.stringify(it.warnings));
	}
});

test('R2-3/4/6: ml only when written, never against another unit word; unknown words not guessed', () => {
	const { PD } = load();
	const refuse = [
		['FOO NEB.SUSP 1MG/2ML', '2 ΕΝΑΙΩΡΗΜΑ ΓΙΑ ΕΙΣΠΝΟΗ ML'],
		['FOO OR.DRO.SOL 10MG/ML', '1 ΠΟΣ.ΣΤΑΓΟΝΕΣ ΔΙΑΛ ML'],
		['FOO INJ.SOL 10MG/ML', '2 ΕΝΕΣ.ΔΙΑΛΥΜΑ ML'],
		['FOO SYR 250MG/5ML', '5 ML 250 MG'],
		['FOO SYR 250MG/5ML', '1 ΚΟΥΤΑΛΑΚΙ'],
		['FOO ORAL.SOL 10MG/ML', '1 ΔΟΣΗ'],
		['FOO OR.DRO.SOL 10MG/ML', '5 CC ΣΤΑΓΟΝΕΣ'],
		['MOVICOL POWD.OR.SOL', '1 ΚΟΝΙΣ ΓΙΑ ΠΟΣ.ΔΙΑΛΥΜΑ'],
		[TAB, '10 ΧΙΛΙΟΣΤΟΛΙΤΡΑ ΔΙΣΚΙΑ']
	];
	for (const [drug, phrase] of refuse) {
		const it = first(PD, one(drug, phrase + ' x 2 φορές την ημέρα x 5 ημέρες'));
		assert.strictEqual(it.doseUnit, '', phrase);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', phrase);
	}
	const ok = [['FOO SYR 250MG/5ML', '5 ΧΙΛΙΟΣΤΟΛΙΤΡΑ', 'ml'], ['FOO OR.SOL', '3 ΠΟΣ.ΔΙΑΛΥΜΑ ML', 'ml'], ['FOO OR.DRO.SOL', '20 ΣΤΑΓΟΝΕΣ', 'drops']];
	for (const [drug, phrase, unit] of ok) {
		const it = first(PD, one(drug, phrase + ' x 2 φορές την ημέρα x 5 ημέρες'));
		assert.strictEqual(it.doseUnit, unit, phrase);
	}
});

test('R2-5: vaginal cream or gel is an application, vaginal tablet a suppository', () => {
	const { PD } = load();
	assert.strictEqual(first(PD, one('GYNO CREAM 2%', '1 ΚΟΛΠΙΚΗ ΚΡΕΜΑ x 1 φορά την ημέρα x 7 ημέρες')).doseUnit, 'application');
	assert.strictEqual(first(PD, one('GYNO GEL 2%', '1 ΚΟΛΠΙΚΗ ΓΕΛΗ x 1 φορά την ημέρα x 7 ημέρες')).doseUnit, 'application');
	assert.strictEqual(first(PD, one('GYNO VAG.TAB', '1 ΚΟΛΠΙΚΑ ΔΙΣΚΙΑ x 1 φορά την ημέρα x 7 ημέρες')).doseUnit, 'suppository');
	const alone = first(PD, one('GYNO VAG', '1 ΚΟΛΠΙΚΟ x 1 φορά την ημέρα x 7 ημέρες'));
	assert.strictEqual(alone.doseUnit, '');
});

test('R2-7: units-per-ml strength with a small quantity is never silently «injections»', () => {
	const { PD } = load();
	const it = first(PD, one('NEWINSULIN INJ.SOL 100U/ML', '2 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 3 φορές την ημέρα x 30 ημέρες'));
	/* 1.27.2: the insulin rule — never injections, units in the form. */
	assert.ok(it.warnings.includes('insulinUnits'));
	assert.strictEqual(it.doseUnit, '');
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
	const big = first(PD, one('NEWINSULIN INJ.SOL 100U/ML', '12 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες'));
	assert.strictEqual(big.doseUnit, 'iu');
});

test('R2-8: a wrapped «x» / «28 ημέρες 25% …» tail is not the next name', () => {
	const { PD } = load();
	const text = HEAD + 'BESPAR TAB 10MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 1 φορά την ημέρα x\n28 ημέρες 25% 1 6,00 6,00 6,00 0,00 1,50 4,50\n' +
		'COZAAR F.C.TAB 50MG/TAB BTx28\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες\n' + PRICE;
	const r = PD.parsePrescription(text);
	assert.deepStrictEqual(J(r.items.map((i) => [i.name, i.days])), [['BESPAR TAB 10MG', '28'], ['COZAAR F.C.TAB 50MG', '28']]);
});

test('R2-9/10: any letter after «ημέρες», any symbol in the quantity → not added', () => {
	const { PD } = load();
	for (const d of ['1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες ή 10', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες x 2', '1 ,5 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες', '1 + 1/2 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες']) {
		assert.strictEqual(PD.rxItemProblem(first(PD, one(TAB, d))), 'warnings', d);
	}
	/* The price table header joined to the line is fine. */
	const ok = first(PD, one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες 0% 10% 25% Άλλο'));
	assert.strictEqual(PD.rxItemProblem(ok), '');
});

test('R2-11: Επεξεργασία of another item clears a pending frequency choice', () => {
	const env = app();
	const { PD, w } = env;
	PD.s.items.push({ name: 'BESPAR TAB 10MG', doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: 1, days: '30', notes: '' });
	PD.renderMedicationList();
	paste(env, one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την εβδομάδα x 28 ημέρες'));
	w.document.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.rxNeedsFreq, true);
	/* While the loaded medicine is in the form, editing is refused and the
	   pending choice stays. */
	PD.editItem(0);
	assert.strictEqual(PD.s.rxNeedsFreq, true);
	PD.setFieldValue('pd-drug', '');
	PD.setFieldValue('pd-days', '');
	PD.setFieldValue('pd-dose-unit', 'tablet');
	PD.setFieldValue('pd-dose-amount', PD.DEFAULT_DOSE_AMOUNT);
	PD.editItem(0);
	assert.strictEqual(PD.s.rxNeedsFreq, false);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	assert.strictEqual(PD.s.editingIndex, null, 'unchanged save goes through');
});

test('εφάπαξ over several days goes through the form; weekly gets a day note', () => {
	const { PD } = load();
	const once = first(PD, one(TAB, '1 ΔΙΣΚΙΑ x εφάπαξ x 3 ημέρες'));
	assert.strictEqual(PD.rxItemProblem(once), 'warnings');
	const wk = first(PD, one('FOSAMAX TAB 70MG', '1 ΔΙΣΚΙΑ x 1 φορά την εβδομάδα x 28 ημέρες'));
	assert.ok(wk.warnings.includes('weekDay'));
	/* 1.27.0: the weekday is chosen, never preset. */
	assert.strictEqual(wk.customMode, 'weekday');
	assert.strictEqual(wk.customWeekday, null);
	assert.strictEqual(PD.rxItemProblem(wk), 'warnings');
	wk.customWeekday = 2;
	assert.strictEqual(PD.rxItemProblem(wk), '');
});

test('EMTHEXATE daily is blocked like any methotrexate', () => {
	const { PD } = load();
	const it = first(PD, one('EMTHEXATE TAB 2,5MG', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες'));
	assert.ok(it.warnings.includes('methotrexateDaily'));
});


/* ---- Drop zone (the closed state is the paste field) ---- */

test('drop zone: a paste event is read at once and never lands in the page', () => {
	const env = app();
	const { PD, w } = env;
	const d = w.document;
	const drop = d.getElementById('pd-rx-drop');
	assert.ok(drop, 'box rendered');
	assert.match(d.querySelector('.pd-rx-drop').textContent, /Επικολλήστε εδώ ολόκληρη/);
	assert.ok(d.querySelectorAll('.pd-rx-drop kbd').length >= 6, 'keys drawn as <kbd>');
	const ev = new w.Event('paste', { bubbles: true, cancelable: true });
	ev.clipboardData = { getData: () => one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες') };
	drop.dispatchEvent(ev);
	assert.ok(ev.defaultPrevented, 'the paste itself is cancelled');
	assert.strictEqual(PD.s.rx.stage, 'review');
	assert.strictEqual(PD.s.rx.rows.length, 1);
	/* 1.27.0: the medicine's own lines are shown next to the fields
	   (review #15); nothing else of the paste is. */
	assert.ok(!d.body.innerHTML.includes('Μονάδος Αποζ'));
	assert.strictEqual(drop.value || '', '');
});

test('drop zone: nothing found → a small note under the box, no panel; it goes after a good paste', () => {
	const env = app();
	const { PD, w } = env;
	const d = w.document;
	paste(env, 'καλημέρα');
	assert.strictEqual(PD.s.rx, null);
	assert.match(d.querySelector('.pd-rx-hint').textContent, /Δεν βρέθηκαν φάρμακα/);
	assert.ok(d.getElementById('pd-rx-drop'), 'the box stays');
	assert.strictEqual(d.querySelector('.pd-rx-panel'), null);
	paste(env, one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες'));
	assert.strictEqual(PD.s.rx.stage, 'review');
	d.getElementById('pd-rx-cancel').click();
	assert.strictEqual(d.querySelector('.pd-rx-hint'), null);
});

test('drop zone: ordinary typing is not read letter by letter', () => {
	const env = app();
	const { PD, w } = env;
	const drop = w.document.getElementById('pd-rx-drop');
	drop.value = 'Δ';
	drop.dispatchEvent(new w.InputEvent('input', { bubbles: true, inputType: 'insertText', data: 'Δ' }));
	assert.strictEqual(PD.s.rx, null);
	assert.strictEqual(drop.value, 'Δ');
	assert.strictEqual(w.document.querySelector('.pd-rx-hint'), null);
});

test('drop zone: whitespace only does nothing', () => {
	const env = app();
	const { PD } = env;
	paste(env, '   \n  ');
	assert.strictEqual(PD.s.rx, null);
});
