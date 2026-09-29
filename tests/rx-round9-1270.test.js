/* 1.27.2 round 9: the batch-2 findings (126 more real print-outs).
   The insulin safety rule, needless strength confirmations, drug-line
   variants, the short-duration check, «κάθε N ημέρες / εβδομάδες», and
   the «επί …» as-needed phrases. Synthetic strings, plus the anonymised
   corpus in tests/rx-corpus (read only). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const price = (boxes) => '25% ' + (boxes || 1) + ' 1,00 1,00 1,00 0,00 0,25 0,75\n';
const block = (drug, dose, boxes) => drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + price(boxes);
const DAILY30 = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';
const J = (v) => JSON.parse(JSON.stringify(v));
const first = (PD, drug, dose, boxes) => PD.parsePrescription(HEAD + block(drug, dose || DAILY30, boxes)).items[0];
const settle = (PD, it) => PD.rxItemProblem(Object.assign({}, it, { dailyTime: 'morning', customWeekday: 1, customMonthDay: 1,
	confirmed: { name: true, qty: true, freq: true, days: true } }));
const CORPUS = path.join(__dirname, 'rx-corpus');

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	env.d = env.w.document;
	return env;
}

function paste(env, text) {
	const drop = env.d.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new env.w.Event('input', { bubbles: true }));
}

/* ---- 1. insulin ---- */

const TRESIBA = 'TRESIBA INJ.SOL 100U/ML 5 PF.PEN-γυαλί(FlexTouch)x3ML (Πρωτότυπο)';
const INJ1 = '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες';
const PFS1 = '1 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ x εφάπαξ x 1 ημέρες';

test('insulin: the repro — name read, «1 ΕΝΕΣΗ» never planned as an injection, a hard units warning', () => {
	const { PD } = load();
	const r = PD.parsePrescription(HEAD + TRESIBA + '\nΔΟΣΟΛΟΓΙΑ : ' + INJ1 + ' 10% 1 60,00 60,00 60,00 0,00 6,00 54,00\n');
	const it = r.items[0];
	assert.strictEqual(it.name, 'TRESIBA INJ.SOL 100U/ML');
	assert.strictEqual(it.doseUnit, '');
	assert.ok(it.warnings.includes('insulinUnits'));
	assert.strictEqual(settle(PD, it), 'warnings');
	assert.deepStrictEqual(J(r.unreadText), []);
	/* The FlexTouch pen is a pack only on an insulin line. */
	assert.strictEqual(PD.rxIsDrugLine('FOO INJ.SOL 10MG/ML 5 PF.PEN-γυαλί(FlexTouch)x3ML'), false);
});

test('insulin: by name or by units per ml, whether or not the drug line is recognised; «N μονάδες» is units', () => {
	const { PD } = load();
	const lines = [
		'FOORAPID FLEX PEN INJ.SOL 100 U/ML 5PF,SYR,X3ML (Πρωτότυπο)',
		'NEWBRAND INJ.SOL 300 IU/ML BTx3 PF.PENS',
		'NEWBRAND INJ.SOL 100 Units/ml BTx5 CARTRIDGES x3ML',
		'LANTUS WEIRD TEXT THAT IS NOT A DRUG LINE',
		'ΑΓΝΩΣΤΟ ΚΕΙΜΕΝΟ 100U/ML INJ'
	];
	for (const line of lines) {
		for (const dose of [INJ1, '1/2 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 3 φορές την ημέρα x 28 ημέρες', '2 ΕΝΕΣΗ x 1 φορά την ημέρα x 30 ημέρες', PFS1]) {
			const it = first(PD, line, dose);
			assert.notStrictEqual(it.doseUnit, 'injection', line + ' | ' + dose);
			assert.ok(it.warnings.includes('insulinUnits'), line + ' | ' + dose + ' ' + it.warnings);
			assert.strictEqual(settle(PD, it), 'warnings', line + ' | ' + dose);
		}
		const units = first(PD, line, '10 ΜΟΝΑΔΕΣ x 3 φορές την ημέρα x 30 ημέρες');
		assert.strictEqual(units.doseUnit, 'iu', line);
		assert.ok(units.warnings.includes('iuConfirm'), line);
	}
	/* Over 3 «ενέσεις» can only be units: read as units, confirmed. */
	const big = first(PD, lines[1], '22 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες');
	assert.strictEqual(big.doseUnit, 'iu');
	assert.ok(big.warnings.includes('iuConfirm'));
});

test('the TETAGAM exception: one pre-filled syringe of an immunoglobulin in IU/ML is one injection', () => {
	const { PD } = load();
	const it = first(PD, 'TETAGAM P INJ.SO.PFS 250 IU/ML BT x 1 AMP x 1ML', PFS1);
	assert.strictEqual(it.doseUnit, 'injection');
	assert.deepStrictEqual(J(it.warnings), ['time']);
	/* Round 10: an unknown product in IU/ML: one injection, confirmed. */
	const unknown = first(PD, 'FOOGAM P INJ.SO.PFS 250 IU/ML BT x 1 AMP x 1ML', PFS1);
	assert.strictEqual(unknown.doseUnit, 'injection');
	assert.deepStrictEqual(J(unknown.warnings), ['iuSyringe', 'time']);
	/* Not with other words, another quantity, a pen, or a known insulin. */
	for (const [drug, dose] of [
		['FOOGAM P INJ.SO.PFS 250 IU/ML BT x 1 AMP x 1ML', INJ1],
		['FOOGAM P INJ.SO.PFS 250 IU/ML BT x 1 AMP x 1ML', '2 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ x εφάπαξ x 1 ημέρες'],
		['FOOGAM INJ.SOL 250 IU/ML BTx5 PF.PENS', PFS1],
		['LANTUS INJ.SO.PFS 100 IU/ML BT x 1 AMP x 1ML', PFS1]
	]) {
		const x = first(PD, drug, dose);
		assert.notStrictEqual(x.doseUnit, 'injection', drug + ' | ' + dose);
		assert.strictEqual(settle(PD, x), 'warnings', drug + ' | ' + dose);
	}
});

test('insulin in the form and the last check: only in units (IU)', () => {
	const env = load();
	const { PD, w } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	for (const name of ['TRESIBA INJ.SOL 100U/ML', 'LΑΝΤUS', 'NEWBRAND INJ.SOL 300 IU/ML PEN', 'insulin glargine']) {
		PD.setFieldValue('pd-drug', name);
		PD.setFieldValue('pd-dose-amount', '1');
		PD.setFieldValue('pd-dose-unit', 'injection');
		PD.setFieldValue('pd-days', '7');
		PD.s.currentFreq = '24h';
		PD.s.currentDailyTime = 'morning';
		PD.addOrUpdateItem();
		assert.strictEqual(PD.s.items.length, 0, name);
		assert.match(w.document.getElementById('pd-message').textContent + ' ' + (w.document.getElementById('pd-dose-unit').getAttribute('aria-invalid') || ''), /μονάδες|true/, name);
		assert.strictEqual(PD.isInsulinName(name), true, name);
	}
	PD.setFieldValue('pd-dose-amount', '10');
	PD.setFieldValue('pd-dose-unit', 'iu');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'units are fine');
	/* TETAGAM-like (one syringe) and a non-insulin are not insulin. */
	assert.strictEqual(PD.isInsulinName('TETAGAM P INJ.SO.PFS 250 IU/ML'), false);
	assert.strictEqual(PD.isInsulinName('D3 DROPS 10000IU/ML'), false);
	const item = { name: 'NOVORAPID FLEX PEN INJ.SOL 100U/ML', doseAmount: 1, doseUnit: 'injection', freq: '24h', dailyTime: 'morning', customMode: 'days', days: '7' };
	assert.strictEqual(PD.planItemInvalid(item), 'unit');
	assert.strictEqual(PD.planItemInvalid(Object.assign({}, item, { doseUnit: 'iu' })), '');
});

/* ---- 2. strength confirmations ---- */

test('no needless strength confirmation; what is dropped still asks', () => {
	const { PD } = load();
	const clean = [
		['FOOPIC INJ.SOL 1MG/0,74 (1 δόση) 1,34MG/ML 1 πρ. συσκ. τύπου πέναςX1, 5ML+4 βελόνες (Πρωτότυπο)', 'FOOPIC INJ.SOL 1MG'],
		['FOOYON INJ.SUSP BTx1PF SYR x0,5ML (Πρωτότυπο)', 'FOOYON INJ.SUSP'],
		['FOOLEAU ORAL.SOL 10mg/5ml (2mg/ml) BTx1 BOTTLE x50ML (Γενόσημο)', 'FOOLEAU ORAL.SOL 10MG/5ML'],
		['FOOCARD GR.OR.SD 1229,6(121,5Mg++)MG/SACH BTX20SACHX5G (Πρωτότυπο)', 'FOOCARD GR.OR.SD 1229,6(121,5MG++)MG'],
		['FOO-SIL CREAM (1%+0.2)% W/W BTx1TUBx100G (Γενόσημο)', 'FOO-SIL CREAM (1%+0.2)%'],
		['FOOMICA GENUAIR PD.INH.MD 340mcg+12mcg BTx1 inhaler (plastic/stainless\nsteel) with 60 actuations (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς γενόσημο)', 'FOOMICA GENUAIR PD.INH.MD 340MCG+12MCG']
	];
	for (const [drug, name] of clean) {
		const it = first(PD, drug, '1 ΕΙΣΠΝΟΕΣ x 1 φορά την ημέρα x 30 ημέρες');
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('strength') && !it.warnings.includes('extra') && !it.warnings.includes('name'), drug + ' ' + it.warnings);
	}
	for (const drug of [
		'FOOPIC INJ.SOL 1MG/0,74 (1 δόση) 1,34MG/ML 5MG 1 πρ. συσκ. τύπου πέναςX1, 5ML+4 βελόνες',
		'FOOLEAU ORAL.SOL 10mg/5ml (2mg/ml) 7MG BTx1 BOTTLE x50ML',
		'FOOLIN PLUS ORAL.SOL 800MG+0,200(0,185) MG/15ML VIAL BT X 10 (VIALS X 15ML)',
		'FOOCARD GR.OR.SD 1229,6(121,5Mg)MG/SACH BTX20SACHX5G',
		'FOOGORAL CREAM 0,02 TUBx30 G (Πρωτότυπο)'
	]) {
		const it = first(PD, drug, '1 ΕΙΣΠΝΟΕΣ x 1 φορά την ημέρα x 30 ημέρες');
		assert.ok(it.name, drug);
		assert.ok(it.warnings.includes('strength'), drug + ' ' + it.warnings);
	}
});

/* ---- 3. drug-line variants ---- */

test('drug-line variants: «1G/SUPP», a unitless cream strength, «MOD.R.CA.H», a line repeating its own tail', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const sup = first(PD, 'FOOFALK SUPP 1G/SUPP BTx30 (Πρωτότυπο σε θεραπευτική κατηγορία με\nγενόσημο)', '1 ΥΠΟΘΕΤΑ x 2 φορές την ημέρα x 10 ημέρες');
	assert.strictEqual(sup.name, 'FOOFALK SUPP 1G');
	assert.deepStrictEqual(J(sup.warnings), []);
	const cream = first(PD, 'FOOGORAL CREAM 0,02 TUBx30 G (Πρωτότυπο)', '1 ΔΕΡΜ ΕΠΑΛΕΙΨΗ x 2 φορές την ημέρα x 10 ημέρες');
	assert.strictEqual(cream.name, 'FOOGORAL CREAM');
	assert.deepStrictEqual(J(cream.warnings), ['strength']);
	const cap = first(PD, 'FOOSIL MOD.R.CA.H 15MG/CAP BTX20 (Πρωτότυπο)', '1 ΚΑΨΟΥΛΑ ΕΛΕΓΧΟΜ ΑΠΟΔΕΣΜ x 3 φορές την ημέρα x 60 ημέρες', 4);
	assert.strictEqual(cap.name, 'FOOSIL MOD.R.CA.H 15MG');
	assert.deepStrictEqual(J(PD.rxQuantityCheck(cap)), { dispensed: 80, needed: 180, reliable: true, warn: false, short: false });
	const rep = 'FOOQUAD PS.INJ.SUS BTx 1 VIAL+1 PF.SYR. x 0,5 ML SOLV ( 1 Δόσ.) + 2\nβελόνες x 0,5 ML SOLV ( 1 Δόσ.) + 2 βελόνες (Πρωτότυπο)';
	const vac = first(PD, rep, '1 ΕΝΕΣΗ ΣΚΟΝΗ ΦΙΑΛΗ x εφάπαξ x 1 ημέρες');
	assert.strictEqual(vac.name, 'FOOQUAD PS.INJ.SUS');
	assert.deepStrictEqual(J(vac.warnings), ['time']);
	assert.ok(vac.origText.startsWith(rep + '\n'));
	/* A continuation that is not a repeat of the line stays unknown. */
	for (const other of ['βελόνες x 0,5 ML ΜΙΣΟ ΤΟ ΒΡΑΔΥ (Πρωτότυπο)', 'βελόνες x 5 ML SOLV ( 2 Δόσ.) + 9 βελόνες (Πρωτότυπο)']) {
		const bad = first(PD, 'FOOQUAD PS.INJ.SUS BTx 1 VIAL+1 PF.SYR. x 0,5 ML SOLV ( 1 Δόσ.) + 2\n' + other, '1 ΕΝΕΣΗ ΣΚΟΝΗ ΦΙΑΛΗ x εφάπαξ x 1 ημέρες');
		assert.ok(bad.warnings.includes('extra'), other + ' ' + bad.warnings);
	}
});

/* ---- 4. short duration ---- */

test('short duration: 15 times the need for 3 days or less is confirmed on «Διάρκεια»', () => {
	const env = app();
	const { PD, d } = env;
	assert.strictEqual(PD.rxQuantityCheck(first(PD, 'FOO TAB 100MG/TAB BT x 60 (6 x 10)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 1 ημέρες')).short, true);
	assert.strictEqual(PD.rxQuantityCheck(first(PD, 'FOO TAB 100MG/TAB BT x 60 (6 x 10)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 4 ημέρες')).short, false);
	assert.strictEqual(PD.rxQuantityCheck(first(PD, 'FOO TAB 100MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 3 ημέρες')).short, false, '10×');
	paste(env, HEAD + block('FOO TAB 100MG/TAB BT x 60 (6 x 10)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 1 ημέρες'));
	const item = PD.s.rx.rows[0].item;
	assert.ok(item.warnings.includes('shortDuration'));
	assert.ok(d.getElementById('pd-rx-w-0-shortDuration').textContent.includes('πολύ μικρή'));
	d.querySelector('[data-rx-row="0"][data-rx-time="morning"]').click();
	assert.strictEqual(PD.rxItemProblem(item), 'warnings');
	const cb = d.getElementById('pd-rx-cf-0-days');
	cb.checked = true;
	cb.dispatchEvent(new env.w.Event('change', { bubbles: true }));
	assert.strictEqual(PD.rxItemProblem(PD.s.rx.rows[0].item), '');
});

/* ---- 5. every N days / weeks ---- */

test('«κάθε N ημέρες / εβδομάδες» is every N (7N) days with the dose count confirmed; «N φορές την εβδομάδα» stays refused', () => {
	const env = app();
	const { PD, d } = env;
	const cases = [['κάθε 4 εβδομάδες', '28'], ['κάθε 3 ημέρες', '3'], ['κάθε 2 εβδομάδες', '14'], ['κάθε 12 εβδομάδες', '84'], ['κάθε 90 ημέρες', '90']];
	for (const [freq, n] of cases) {
		const it = first(PD, 'FOO OILY.INJ 200MG/ML AMP BTx1 AMPx1 ML', '1 ΕΝΕΣΗ ΕΛΕΩΔΕΣ ΔΙΑΛ x ' + freq + ' x 30 ημέρες');
		assert.strictEqual(it.freq, 'custom', freq);
		assert.strictEqual(it.customMode, 'days', freq);
		assert.strictEqual(it.customIntervalDays, n, freq);
		assert.ok(it.warnings.includes('intervalCount') && it.warnings.includes('startDay'), freq + ' ' + it.warnings);
		assert.strictEqual(settle(PD, it), '', freq);
	}
	for (const freq of ['κάθε 13 εβδομάδες', 'κάθε 91 ημέρες', '3 φορές την εβδομάδα', '2 φορές την εβδομάδα']) {
		const it = first(PD, 'FOO TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x ' + freq + ' x 30 ημέρες');
		assert.strictEqual(it.freq, null, freq);
		assert.strictEqual(settle(PD, it), 'warnings', freq);
	}
	/* The review shows the dates next to the confirmation. */
	paste(env, HEAD + block('FOO OILY.INJ 200MG/ML AMP BTx1 AMPx1 ML', '1 ΕΝΕΣΗ ΕΛΕΩΔΕΣ ΔΙΑΛ x κάθε 4 εβδομάδες x 30 ημέρες'));
	const total = d.querySelector('.pd-rx-row .pd-rx-total');
	assert.ok(total && /\d{2}\/\d{2}/.test(total.textContent), total && total.textContent);
	assert.ok(d.getElementById('pd-rx-cf-0-days'));
});

/* ---- 6. as needed ---- */

test('«επί δύσπνοιας / δυσπνοίας / βήχα / πυρετού / ναυτίας / αϋπνίας / κρίσεως» is as needed', () => {
	const { PD } = load();
	for (const p of ['επί δύσπνοιας', 'επί δυσπνοίας', 'επί βήχα', 'επί πυρετού', 'επί ναυτίας', 'επί αϋπνίας', 'επί κρίσεως', 'ΕΠΙ ΔΥΣΠΝΟΙΑΣ']) {
		const it = first(PD, 'FOOLIN AER.MD.INH 100MCG/DOSE ΣΥΣΚΕΥΗ 200 ΔΟΣΕΙΣ (Πρωτότυπο)', '1 ΕΙΣΠΝΟΕΣ ΔΙΑΛ ΔΟΣΕΙΣ x ' + p + ' x 30 ημέρες');
		assert.ok(it.warnings.includes('asNeeded'), p);
		assert.strictEqual(settle(PD, it), 'warnings', p);
	}
});

/* ---- the anonymised corpus ---- */

test('anonymised corpus: TETAGAM is one injection, «κάθε 4 εβδομάδες» is every 28 days, no vaccine or pen asks about its strength', () => {
	const { PD } = load();
	let tetagam = 0;
	let every = 0;
	for (const browser of ['chrome', 'firefox']) {
		for (const f of fs.readdirSync(path.join(CORPUS, browser)).filter((x) => x.endsWith('.txt'))) {
			const r = PD.parsePrescription(fs.readFileSync(path.join(CORPUS, browser, f), 'utf8'));
			for (const it of r.items) {
				if (/^TETAGAM/.test(it.name)) {
					tetagam++;
					assert.strictEqual(it.doseUnit, 'injection', f);
					assert.ok(!it.warnings.includes('insulinUnits') && !it.warnings.includes('unitsOrInjection'), f);
				}
				if (/κάθε 4 εβδομάδες/.test(it.source)) {
					every++;
					assert.strictEqual(it.customIntervalDays, '28', f);
					assert.ok(it.warnings.includes('intervalCount'), f);
				}
				if (/^(OZEMPIC|HEXYON|BEXSERO|INFANRIX|BOOSTRIX|PROQUAD|DEXALEAU|TROFOCARD)/.test(it.name)) {
					assert.ok(!it.warnings.includes('strength') && !it.warnings.includes('extra'), f + ' ' + it.name + ' ' + it.warnings);
				}
				if (/^NOVORAPID/.test(it.name) && /ΕΝΕΣΗ/.test(it.source)) {
					assert.ok(it.warnings.includes('insulinUnits'), f);
				}
			}
		}
	}
	assert.ok(tetagam >= 6 && every >= 1, tetagam + ' ' + every);
});
