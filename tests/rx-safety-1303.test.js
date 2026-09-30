/* 1.30.3: prescription reading — safety and robustness fixes.
   - the same medicine ticked twice in the review (SINTROM 4MG 1 and 1/2
     tablet) is added only on a second «Προσθήκη» with the same selection;
   - «1 και 1/2», «1 ως 2», «2 x 1» … leave the amount empty (as «1 - 2»);
   - a dose in mg of an oral liquid goes to the form («unitForm»);
   - «x 7 ημερες» / «ΗΜΕΡΕΣ» without the accent, «ΣΟΣ» in Greek letters,
     «BTx2 BLIST x 14» / «BTx3x10» packs;
   - a long paste of form codes no longer freezes the page. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const one = (drug, dose) => HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + PRICE;
const J = (v) => JSON.parse(JSON.stringify(v));
const BESPAR = 'BESPAR TAB 10MG/TAB BTx30';
const DAILY = ' x 1 φορά την ημέρα x 7 ημέρες';
const SINTROM = 'SINTROM TAB 4MG/TAB BTx20';
const ZIN = one('ZINADOL F.C.TAB 500MG/TAB BTx10', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες');

function app(opts) {
	const env = load(opts);
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

function change(env, el, value) {
	el.checked = value;
	el.dispatchEvent(new env.w.Event('change', { bubbles: true }));
}

const addBtn = (env) => env.d.getElementById('pd-rx-add');
const msg = (env) => env.d.getElementById('pd-message');

/* Both SINTROM rows ticked, each dose confirmed and its time chosen. */
function sintromBoth(env) {
	const { d } = env;
	paste(env, one(SINTROM, '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
		one(SINTROM, '1/2 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') + ZIN);
	for (const i of [0, 1]) {
		change(env, d.getElementById('pd-rx-inc-' + i), true);
		change(env, d.getElementById('pd-rx-cf-' + i + '-qty'), true);
	}
	d.getElementById('pd-rx-t-0-morning').click();
	d.getElementById('pd-rx-t-1-evening').click();
	assert.strictEqual(addBtn(env).disabled, false);
}

/* ---- 1. the same medicine ticked twice -------------------------------- */

test('SINTROM 4MG 1 and 1/2 tablet both ticked: the first «Προσθήκη» only warns, naming the medicine and both doses', () => {
	const env = app();
	const { PD } = env;
	sintromBoth(env);
	addBtn(env).click();
	assert.strictEqual(PD.s.items.length, 0, 'nothing added on the first press');
	assert.ok(PD.s.rx && 'review' === PD.s.rx.stage, 'the review stays open');
	const m = msg(env);
	assert.match(m.className, /pd-message-error/);
	assert.strictEqual(m.getAttribute('role'), 'alert');
	assert.match(m.textContent, /SINTROM TAB 4MG θα μπει 2 φορές στο πλάνο/);
	assert.match(m.textContent, /\(1 \S+ και 0,5 \S+\)/);
	assert.match(m.textContent, /πατήστε ξανά «Προσθήκη»/);
});

test('SINTROM twice: a second press with the same selection adds both (1 morning + 1/2 evening is legitimate)', () => {
	const env = app();
	const { PD } = env;
	sintromBoth(env);
	addBtn(env).click();
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => [i.name, i.doseAmount, i.dailyTime])),
		[['SINTROM TAB 4MG', 1, 'morning'], ['SINTROM TAB 4MG', 0.5, 'evening'], ['ZINADOL F.C.TAB 500MG', 1, 'morning']]);
	assert.match(msg(env).className, /pd-message-success/);
	/* The form's own duplicate confirmation is not touched. */
	assert.strictEqual(PD.s.duplicateAck, '');
});

test('SINTROM twice: any change of the ticked rows asks again', () => {
	const env = app();
	const { PD, d } = env;
	sintromBoth(env);
	addBtn(env).click();
	/* Untick and tick ZINADOL again: the selection changed in between. */
	change(env, d.getElementById('pd-rx-inc-2'), false);
	change(env, d.getElementById('pd-rx-inc-2'), true);
	addBtn(env).click();
	assert.strictEqual(PD.s.items.length, 0, 'asked again after the change');
	assert.match(msg(env).textContent, /θα μπει 2 φορές/);
	/* Without ZINADOL: another selection, asked again too. */
	change(env, d.getElementById('pd-rx-inc-2'), false);
	addBtn(env).click();
	assert.strictEqual(PD.s.items.length, 0);
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => i.doseAmount)), [1, 0.5]);
});

test('SINTROM: one of the two doses ticked is added on the first press', () => {
	const env = app();
	const { PD, d } = env;
	sintromBoth(env);
	change(env, d.getElementById('pd-rx-inc-0'), false);
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => [i.name, i.doseAmount])), [['SINTROM TAB 4MG', 0.5], ['ZINADOL F.C.TAB 500MG', 1]]);
});

test('SINTROM twice: the warning comes from the dictionary (Greek and English keys exist)', () => {
	const env = app({ i18n: 'real' });
	sintromBoth(env);
	addBtn(env).click();
	assert.match(msg(env).textContent, /SINTROM TAB 4MG θα μπει 2 φορές στο πλάνο \(1 Δισκίο\(α\) και 0,5 Δισκίο\(α\)\)/);
	const d = require('./lib/i18n.js').flatDictionaries(require('./lib/env.js').pluginDir(), 90);
	for (const k of ['rxSameDrugTwice', 'rxAnd']) {
		assert.ok(d.el[k], 'el ' + k);
		assert.ok(d.en[k], 'en ' + k);
	}
});

test('form path unchanged: after the review added SINTROM twice, the form still asks before a third', () => {
	const env = app();
	const { PD } = env;
	sintromBoth(env);
	addBtn(env).click();
	addBtn(env).click();
	assert.strictEqual(PD.s.items.length, 3);
	PD.setFieldValue('pd-drug', 'SINTROM TAB 4MG');
	PD.setFieldValue('pd-dose-amount', '1');
	PD.setFieldValue('pd-days', '5');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 3, 'form: first press only warns');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 4, 'form: second press adds');
});

function planFromForm(PD, name) {
	PD.setFieldValue('pd-drug', name);
	PD.setFieldValue('pd-dose-amount', '1');
	PD.setFieldValue('pd-dose-unit', 'tablet');
	PD.setFieldValue('pd-days', '30');
	PD.addOrUpdateItem();
}

test('SINTROM already in the plan, ticked again in the review: the first press warns (plan dose listed), the second adds', () => {
	const env = app();
	const { PD, d } = env;
	planFromForm(PD, 'SINTROM TAB 4MG');
	assert.strictEqual(PD.s.items.length, 1);
	paste(env, one(SINTROM, '1/2 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	const row = PD.s.rx.rows[0];
	assert.ok(row.item.warnings.includes('inPlan'));
	assert.strictEqual(row.include, false, 'not preselected');
	change(env, d.getElementById('pd-rx-inc-0'), true);
	d.getElementById('pd-rx-t-0-evening').click();
	assert.strictEqual(addBtn(env).disabled, false);
	addBtn(env).click();
	assert.strictEqual(PD.s.items.length, 1, 'first press: warning only');
	assert.match(msg(env).className, /pd-message-error/);
	assert.match(msg(env).textContent, /SINTROM TAB 4MG θα μπει 2 φορές στο πλάνο \(1 \S+ και 0,5 \S+\)/);
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => [i.name, i.doseAmount])), [['SINTROM TAB 4MG', 1], ['SINTROM TAB 4MG', 0.5]]);
});

test('an unrelated medicine in the plan does not ask: the ticked row is added on the first press', () => {
	const env = app();
	const { PD, d } = env;
	planFromForm(PD, 'BESPAR TAB 10MG');
	/* The same medicine twice in the plan, but not ticked in the review:
	   never asks either. */
	planFromForm(PD, 'ZINADOL F.C.TAB 500MG');
	PD.s.items.push(Object.assign({}, PD.s.items[1]));
	assert.strictEqual(PD.s.items.length, 3);
	paste(env, one(SINTROM, '1/2 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
	d.getElementById('pd-rx-t-0-evening').click();
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => i.name)), ['BESPAR TAB 10MG', 'ZINADOL F.C.TAB 500MG', 'ZINADOL F.C.TAB 500MG', 'SINTROM TAB 4MG']);
	assert.match(msg(env).className, /pd-message-success/);
});

/* ---- 2. a quantity phrase is never cut to its first number ------------ */

test('«1 και 1/2», «1 ως 2», «1 έως 2», «2 x 1», «1 - 2», «1 ή 2», «1 + 1/2» … leave the amount empty, the text shown', () => {
	const { PD } = load();
	const cases = {
		'1 και 1/2 ΔΙΣΚΙΑ': '1 και 1/2',
		'1 ΚΑΙ ½ ΔΙΣΚΙΑ': '1 ΚΑΙ ½',
		'1 ως 2 ΔΙΣΚΙΑ': '1 ως 2',
		'1 έως 2 ΔΙΣΚΙΑ': '1 έως 2',
		'1 ΜΕΧΡΙ 2 ΔΙΣΚΙΑ': '1 ΜΕΧΡΙ 2',
		'2 x 1 ΔΙΣΚΙΑ': '2 x 1',
		'2 Χ 1 ΔΙΣΚΙΑ': '2 Χ 1',
		'1 x2 ΔΙΣΚΙΑ': '1 x2',
		'1 - 2 ΔΙΣΚΙΑ': '1 - 2',
		'1 ή 2 ΔΙΣΚΙΑ': '1 ή 2',
		'1 + 1/2 ΔΙΣΚΙΑ': '1 + 1/2',
		'1 ΗΜΙΣΥ ΔΙΣΚΙΑ': '1 ΗΜΙΣΥ'
	};
	for (const [qty, raw] of Object.entries(cases)) {
		const it = PD.parsePrescription(one(BESPAR, qty + DAILY)).items[0];
		assert.strictEqual(it.doseAmount, raw, qty);
		assert.ok(it.warnings.includes('amount'), qty);
		assert.ok(it.warnings.includes('phrase'), qty);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', qty);
	}
	/* A plain quantity is still read. */
	const ok = PD.parsePrescription(one(BESPAR, '1/2 ΔΙΣΚΙΑ' + DAILY)).items[0];
	assert.strictEqual(ok.doseAmount, 0.5);
	assert.strictEqual(ok.doseUnit, 'tablet');
});

test('the form shows the quantity phrase as written (no pre-filled 1)', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one(BESPAR, '1 και 1/2 ΔΙΣΚΙΑ' + DAILY));
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(d.getElementById('pd-dose-amount').value, '1 και 1/2');
	assert.strictEqual(PD.s.items.length, 0);
});

/* ---- 3. mg of an oral liquid ----------------------------------------- */

test('a dose in MG of a syrup, oral suspension or oral solution goes to the form («unitForm»)', () => {
	const { PD } = load();
	for (const drug of ['ZIRTEK SYR 1MG/ML FLx200ML', 'NUROFEN POR.SUSP 100MG/5ML FLx100ML',
		'AUGMENTIN PD.ORA.SUS (400+57)MG/5ML FLx70ML', 'DEPON ORAL.SOL 120MG/5ML FLx150ML', 'X OR.DR.SOL 20MG/ML FLx30ML']) {
		const it = PD.parsePrescription(one(drug, '5 MG' + DAILY)).items[0];
		assert.ok(it.warnings.includes('unitForm'), drug + ' ' + JSON.stringify(it.warnings));
		assert.strictEqual(it.doseUnit, '', drug);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', drug);
	}
	/* ml of a syrup, mg of a tablet or an injection: unchanged. */
	const syr = PD.parsePrescription(one('ZIRTEK SYR 1MG/ML FLx200ML', '5 ML' + DAILY)).items[0];
	assert.strictEqual(syr.doseUnit, 'ml');
	assert.ok(!syr.warnings.includes('unitForm'));
	for (const drug of [BESPAR, 'CLEXANE INJ.SOL 40MG/0,4ML BTx10 PF.SYR']) {
		const it = PD.parsePrescription(one(drug, '5 MG' + DAILY)).items[0];
		assert.strictEqual(it.doseUnit, 'mg', drug);
		assert.ok(!it.warnings.includes('unitForm'), drug);
	}
});

/* ---- 4. robustness ---------------------------------------------------- */

test('«x 7 ημερες» and «x 7 ΗΜΕΡΕΣ» without the accent are read', () => {
	const { PD } = load();
	for (const days of ['ημερες', 'ΗΜΕΡΕΣ', 'Ημέρες', 'ΗΜΈΡΕΣ']) {
		const it = PD.parsePrescription(one(BESPAR, '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 7 ' + days)).items[0];
		assert.deepStrictEqual(J([it.doseAmount, it.doseUnit, it.freq, it.days, it.warnings]), [1, 'tablet', '24h', '7', ['time']], days);
		assert.strictEqual(it.source, '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 7 ημέρες', days);
	}
});

test('«ΣΟΣ» in Greek letters is «when needed», like SOS', () => {
	const { PD } = load();
	for (const dose of ['1 ΔΙΣΚΙΑ ΣΟΣ' + DAILY, '1 ΔΙΣΚΙΑ σος' + DAILY, '1 ΔΙΣΚΙΑ SOS' + DAILY, '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα ΣΟΣ x 7 ημέρες']) {
		const it = PD.parsePrescription(one(BESPAR, dose)).items[0];
		assert.ok(it.warnings.includes('asNeeded'), dose + ' ' + JSON.stringify(it.warnings));
	}
	/* A word that merely contains the letters is not. */
	const it = PD.parsePrescription(one(BESPAR, '1 ΔΙΣΚΙΑ' + DAILY)).items[0];
	assert.ok(!it.warnings.includes('asNeeded'));
});

test('a box of blisters is counted whole: «BTx2 BLIST x 14» → 28, «BTx3x10» → 30', () => {
	const { PD } = load();
	const pack = (drug) => J(PD.parsePrescription(one(drug, '1 ΔΙΣΚΙΑ' + DAILY)).items[0].pack);
	assert.deepStrictEqual(pack('BESPAR TAB 10MG/TAB BTx2 BLIST x 14'), { count: 28 });
	assert.deepStrictEqual(pack('BESPAR TAB 10MG/TAB BTx3x10'), { count: 30 });
	assert.deepStrictEqual(pack('BESPAR TAB 10MG/TAB BTx30'), { count: 30 });
	assert.deepStrictEqual(pack('BESPAR TAB 10MG/TAB BTx1FLx30'), { count: 30 });
});

/* ---- 5. no freeze on an adversarial paste ----------------------------- */

test('a 30 KB paste of form codes («PVC TAB …») is parsed in well under a few seconds', () => {
	const { PD } = load();
	for (const word of ['PVC TAB ', '(PVC TAB ', 'PVC F.C.TAB 10MG ']) {
		const line = word.repeat(Math.floor(1000 / word.length)).trim();
		for (const n of [3, 30]) {
			const text = 'X TAB\n' + Array(n).fill(line).join('\n');
			const t0 = Date.now();
			const r = PD.parsePrescription(text);
			const ms = Date.now() - t0;
			assert.ok(Array.isArray(r.items));
			assert.ok(ms < 3000, JSON.stringify(word) + ' ×' + n + ': ' + ms + ' ms');
		}
	}
});

test('a drug line wrapped over lines is still joined after the length cap', () => {
	const { PD } = load();
	const r = PD.parsePrescription(HEAD + 'NEXIUM GR.TAB 20MG/TAB BTx28 (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ ΕΝΤΕΡΟΔΙΑΛΥΤΑ x 1 φορά την ημέρα x 30 ημέρες\n' + PRICE);
	assert.deepStrictEqual(J(r.items.map((i) => [i.name, i.pack])), [['NEXIUM GR.TAB 20MG', { count: 28 }]]);
});
