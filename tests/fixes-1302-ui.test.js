/* 1.30.2: browser-side fixes from the 1.30.1 review (UI / rx reader).
   - «Επεξεργασία» on each medicine card names the medicine for a screen
     reader, like the × (delete) button next to it.
   - Renaming a saved medicine while keeping an unusual quantity asks the
     «Ασυνήθιστα μεγάλη ποσότητα» confirmation again.
   - The prescription reader never falls back to parseFloat() for the
     quantity («1/2» must not become 1): without the checker it is unread.
   - «ΕΠΙ 7 ΗΜΕΡΕΣ» is a duration, not «όταν χρειάζεται»; «επί πόνου /
     ανάγκης» still is, and the row stays blocked by the unread words.
   - Older engines report no inputType: a typed character in the paste box
     is not read as a paste, a pasted chunk is.
   - Unreadable text in a number field survives a language switch.
   - The loader's error view sets aria-expanded on the button. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, loadLoader, isoAhead, closeAll, JS_DIR } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const one = (drug, dose) => HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + PRICE;
const TAB = 'ZINADOL F.C.TAB 500MG/TAB BTx10';

function app(opts) {
	const env = load(opts);
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

async function until(fn) {
	for (let i = 0; i < 200; i++) {
		if (fn()) {
			return;
		}
		await new Promise((r) => setTimeout(r, 10));
	}
	throw new Error('timeout');
}

/* (1) */
test('medicine card: «Επεξεργασία» names the medicine, like the delete button', () => {
	const { PD, w } = app();
	PD.setFieldValue('pd-drug', 'DEPON 500');
	PD.setFieldValue('pd-dose-amount', '1');
	PD.setFieldValue('pd-days', '5');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	const card = w.document.querySelector('#pd-rows .plandose-med-card');
	const edit = card.querySelector('[data-action="edit"]');
	const del = card.querySelector('[data-action="delete"]');
	assert.strictEqual(edit.getAttribute('aria-label'), 'Επεξεργασία: DEPON 500');
	assert.strictEqual(del.getAttribute('aria-label'), 'Διαγραφή: DEPON 500');
	assert.strictEqual(edit.textContent, 'Επεξεργασία', 'the visible text is unchanged');
});

test('medicine card: a name with quotes is escaped in the edit label', () => {
	const { PD, w } = app();
	PD.setFieldValue('pd-drug', 'A "B" <C>');
	PD.setFieldValue('pd-dose-amount', '1');
	PD.setFieldValue('pd-days', '5');
	PD.addOrUpdateItem();
	const edit = w.document.querySelector('#pd-rows [data-action="edit"]');
	assert.strictEqual(edit.getAttribute('aria-label'), 'Επεξεργασία: A "B" <C>');
});

/* (2) */
test('steps nav: the labelled container has a role, so the label is read', () => {
	const { w } = app();
	const nav = w.document.querySelector('.plandose-steps');
	assert.strictEqual(nav.getAttribute('role'), 'group');
	assert.ok(nav.getAttribute('aria-label'));
});

/* (8) */
test('editing: renaming a medicine with an unusual quantity asks the confirmation again', () => {
	const { PD, w } = app();
	const d = w.document;
	PD.setFieldValue('pd-drug', 'FOO');
	PD.setFieldValue('pd-dose-amount', '6');
	PD.setFieldValue('pd-days', '3');
	PD.addOrUpdateItem();
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'confirmed on the second press');

	PD.editItem(0);
	/* Unchanged (or only the notes changed): no second question. */
	PD.setFieldValue('pd-notes', 'μετά το φαγητό');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.editingIndex, null, 'saved at once');
	assert.strictEqual(PD.s.items[0].notes, 'μετά το φαγητό');

	PD.editItem(0);
	PD.setFieldValue('pd-drug', 'BAR');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items[0].name, 'FOO', 'the new name is not saved on the first press');
	assert.match(d.getElementById('pd-message').textContent, /Ασυνήθιστα μεγάλη ποσότητα/);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items[0].name, 'BAR', 'saved once confirmed');
	assert.strictEqual(PD.s.items[0].doseAmount, 6);
});

/* (10) */
test('rx: without PD.checkDoseAmount the quantity is unread, never parseFloat()', () => {
	const { PD } = load();
	const real = PD.checkDoseAmount;
	const withChecker = PD.parsePrescription(one(TAB, '1/2 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες')).items[0];
	assert.strictEqual(withChecker.doseAmount, 0.5);
	PD.checkDoseAmount = undefined;
	try {
		for (const qty of ['1/2', '1,5', '1']) {
			const it = PD.parsePrescription(one(TAB, qty + ' ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες')).items[0];
			assert.ok(it.warnings.includes('amount'), qty + ': ' + it.warnings);
			assert.strictEqual(it.doseAmount, qty, qty + ': kept as written, not a number');
		}
	} finally {
		PD.checkDoseAmount = real;
	}
	/* 'amount' is a hard warning: the row goes through the form. */
	const it = PD.parsePrescription(one(TAB, '1/2 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες')).items[0];
	it.warnings.push('amount');
	PD.s.startDate = isoAhead(1);
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
});

test('rx: the reader carries no parseFloat() fallback for the quantity', () => {
	const src = fs.readFileSync(path.join(JS_DIR, 'rx-parse.js'), 'utf8');
	assert.ok(!/parseFloat\(qtyRaw\)/.test(src));
});

/* (11) */
test('rx: «ΕΠΙ 7 ΗΜΕΡΕΣ» is a duration, not «όταν χρειάζεται»; the row stays blocked', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const tail of ['ΕΠΙ 7 ΗΜΕΡΕΣ ΜΕΤΑ ΤΟ ΦΑΓΗΤΟ', 'ΕΠΙ ΔΥΟ ΕΒΔΟΜΑΔΕΣ', 'ΕΠΙ ΜΑΚΡΟΝ', 'επί 10 ημέρες']) {
		const it = PD.parsePrescription(one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες ' + tail)).items[0];
		assert.ok(!it.warnings.includes('asNeeded'), tail + ': ' + it.warnings);
		assert.ok(it.warnings.includes('extra'), tail + ': still unread words');
		assert.strictEqual(PD.rxItemProblem(Object.assign({}, it, { confirmed: { name: true, qty: true, freq: true, days: true } })), 'warnings', tail);
	}
});

test('rx: «επί πόνου / ανάγκης», SOS, «αν χρειαστεί», bare «ΕΠΙ» still read as «όταν χρειάζεται»', () => {
	const { PD } = load();
	for (const tail of ['ΕΠΙ ΠΟΝΟΥ', 'ΕΠΙ ΑΝΑΓΚΗΣ', 'επί εμέτου', 'SOS', 'ΑΝ ΧΡΕΙΑΣΤΕΙ', 'ΣΕ ΠΕΡΙΠΤΩΣΗ ΠΟΝΟΥ', 'ΕΠΙ', 'ΕΠΙ 7 ΗΜΕΡΕΣ ΕΠΙ ΠΟΝΟΥ', 'ΕΑΝ ΠΟΝΑΕΙ']) {
		const it = PD.parsePrescription(one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες ' + tail)).items[0];
		assert.ok(it.warnings.includes('asNeeded'), tail + ': ' + it.warnings);
	}
});

/* (14) */
test('rx paste box: with no inputType a typed character is not read, a pasted chunk is', () => {
	const { PD, w } = app();
	const d = w.document;
	const drop = d.getElementById('pd-rx-drop');
	const input = () => drop.dispatchEvent(new w.Event('input', { bubbles: true })); /* no inputType */
	drop.value = 'Δ';
	input();
	assert.strictEqual(PD.s.rx, null);
	assert.strictEqual(drop.value, 'Δ', 'one character stays in the box');
	drop.value = 'ΔΟ';
	input();
	assert.strictEqual(PD.s.rx, null);
	assert.strictEqual(drop.value, 'ΔΟ', 'typing on, one character at a time, is still typing');
	assert.strictEqual(d.querySelector('.pd-rx-hint'), null);
	drop.value = 'ΔΟ' + one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες');
	input();
	assert.strictEqual(PD.s.rx && PD.s.rx.stage, 'review', 'a pasted chunk is read');
});

test('rx paste box: a modern paste (insertFromPaste) is read, insertText is not', () => {
	const { PD, w } = app();
	const drop = w.document.getElementById('pd-rx-drop');
	drop.value = 'ΔΟΣ';
	drop.dispatchEvent(new w.InputEvent('input', { bubbles: true, inputType: 'insertText', data: 'Σ' }));
	assert.strictEqual(PD.s.rx, null);
	drop.value = one(TAB, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες');
	drop.dispatchEvent(new w.InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
	assert.strictEqual(PD.s.rx.stage, 'review');
	assert.strictEqual(drop.value, '');
});

/* (12) */
test('rx review: one «Επιβεβαιώνω» describes every pending confirmation on its field', () => {
	const { PD, w } = app();
	const d = w.document;
	const drop = d.getElementById('pd-rx-drop');
	drop.value = one(TAB, '6 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες');
	drop.dispatchEvent(new w.InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
	const item = PD.s.rx.rows[0].item;
	/* Two confirmations on «Ποσότητα». */
	if (!item.warnings.includes('highQty')) {
		item.warnings.push('highQty');
	}
	item.warnings.push('sameDrugOtherDose');
	PD.rx._.rerender('pd-rx');
	const box = d.querySelector('[data-rx-confirm="0"][data-rx-field="qty"]');
	assert.ok(box, 'confirm box on quantity');
	const ids = box.getAttribute('aria-describedby').split(' ');
	assert.ok(ids.includes('pd-rx-w-0-highQty'), ids);
	assert.ok(ids.includes('pd-rx-w-0-sameDrugOtherDose'), ids);
	for (const id of ids) {
		assert.ok(d.getElementById(id), id + ' is on the page');
	}
});

/* (3) */
test('language switch: unreadable text in «Ημέρες» is kept, not turned into empty', () => {
	const { PD, w } = app();
	PD.config.isAllowed = true;
	PD.overlay.hidden = false;
	const d = w.document;
	const old = d.getElementById('pd-days');
	/* jsdom has no badInput; stand in for «επτά» typed in a number field. */
	Object.defineProperty(old, 'validity', { value: { badInput: true, valid: false } });
	PD.setLang('en');
	const now = d.getElementById('pd-days');
	assert.strictEqual(now, old, 'the field with the raw text is kept');
	assert.strictEqual(now.validity.badInput, true);
	assert.strictEqual(d.querySelectorAll('#pd-days').length, 1);
	assert.ok(PD.hasUnsavedDrugForm ? PD.hasUnsavedDrugForm() !== false : true);
	/* Its handlers still work (Enter adds / validates). */
	PD.setFieldValue('pd-drug', 'FOO');
	now.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
	assert.strictEqual(PD.s.items.length, 0, 'unreadable days are refused');
});

test('language switch: a readable number is restored as before', () => {
	const { PD, w } = app();
	PD.config.isAllowed = true;
	PD.overlay.hidden = false;
	const d = w.document;
	const old = d.getElementById('pd-days');
	old.value = '7';
	PD.setLang('en');
	assert.notStrictEqual(d.getElementById('pd-days'), old);
	assert.strictEqual(d.getElementById('pd-days').value, '7');
});

/* (4) */
test('loader: the error view sets aria-expanded on the button, and clears it on close', async () => {
	const { w } = await loadLoader({ failUrl: (u) => /print\.js$/.test(u) });
	const trigger = w.document.getElementById('plandose-trigger');
	trigger.click();
	await until(() => w.document.querySelector('.plandose-loader-error'));
	assert.strictEqual(trigger.getAttribute('aria-expanded'), 'true');
	w.document.getElementById('plandose-close').click();
	assert.strictEqual(trigger.getAttribute('aria-expanded'), 'false');
});

/* (9) */
test('preview: the resize observer is disconnected when the popup closes', () => {
	const env = load();
	const { PD, w } = env;
	let disconnected = 0;
	w.ResizeObserver = class {
		observe() {}
		disconnect() {
			disconnected++;
		}
	};
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	PD.overlay.hidden = false;
	const area = w.document.getElementById('pd-preview-area') || w.document.createElement('div');
	PD.watchPreviewSheet(area);
	const before = disconnected;
	PD.closeModal();
	assert.strictEqual(disconnected, before + 1);
	PD.unwatchPreviewSheet();
	assert.strictEqual(disconnected, before + 1, 'idempotent');
});

test('dose text: Greek singular up to one (0,5 Δισκίο), plural above; English plural', () => {
	const { PD } = load({ i18n: 'real' });
	const text = (a, u) => PD.doseAmountText({ doseAmount: a, doseUnit: u || 'tablet' });
	assert.strictEqual(text(0.5), '0,5 Δισκίο');
	assert.strictEqual(text(1), '1 Δισκίο');
	assert.strictEqual(text(1.5), '1,5 Δισκία');
	assert.strictEqual(text(2), '2 Δισκία');
	assert.strictEqual(text(0.5, 'capsule'), '0,5 Κάψουλα');
	PD.lang = 'en';
	PD.t = PD.dicts.en;
	assert.strictEqual(text(0.5), '0.5\u00a0Tablets');
	assert.strictEqual(text(1), '1\u00a0Tablet');
});
