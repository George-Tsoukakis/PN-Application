/* 1.27.0 round 2: the sheet is never built on an unusable start date, the
   billing line survives a rebuild, the notes hint, and the screen CSS. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const item = { name: 'A', doseAmount: 1, doseUnit: 'tablet', freq: '12h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: '3', notes: '' };

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

test('buildPrintHtml refuses an invalid, incomplete, past or too-far start date (never «today»)', () => {
	const { PD } = load();
	PD.s.items = [Object.assign({}, item)];
	for (const [value, problem] of [['2026-02-30', 'invalid'], [PD.START_DATE_INCOMPLETE, 'invalid'], [isoAhead(-1), 'past'], [isoAhead(PD.MAX_START_AHEAD_DAYS + 1), 'tooFar']]) {
		PD.s.startDate = value;
		assert.throws(() => PD.buildPrintHtml(), (e) => e.code === 'invalid_start_date' && e.message === 'invalid_start_date' && e.problem === problem, value);
		/* print.js's wrapper keeps its contract. */
		assert.throws(() => PD.buildCheckedPrintHtml(), (e) => e.message === 'invalid_start_date', value);
	}
	PD.s.startDate = '';
	assert.match(PD.buildPrintHtml(), /pd-print-document/);
	PD.s.startDate = isoAhead(2);
	assert.match(PD.buildPrintHtml(), /pd-print-start/);
});

test('the preview shows the start-date problem instead of crashing or showing a sheet', () => {
	const { PD, w } = app();
	PD.s.items = [Object.assign({}, item)];
	PD.s.startDate = isoAhead(-3);
	assert.doesNotThrow(() => PD.renderPreview());
	const area = w.document.getElementById('pd-preview-area');
	assert.strictEqual(area.querySelector('.pd-print-document'), null);
	assert.match(area.querySelector('.pd-preview-blocked').textContent, /έχει περάσει/);
	/* The step change and the start-date setter render it too. */
	assert.doesNotThrow(() => PD.setStartDate(PD.START_DATE_INCOMPLETE));
	assert.match(area.textContent, /δεν είναι έγκυρη/);
	PD.setStartDate(isoAhead(1));
	assert.ok(area.querySelector('.pd-print-document'));
});

test('a rebuild (language switch) keeps the billing status line', () => {
	const { PD, w } = app();
	PD.config.isAllowed = true;
	PD.overlay.hidden = false;
	PD.billingStatusText = () => 'Χρεώθηκε 1 εκτύπωση.';
	PD.renderBillingStatus();
	assert.strictEqual(w.document.getElementById('pd-billing-status').textContent, 'Χρεώθηκε 1 εκτύπωση.');
	PD.setLang('en');
	const el = w.document.getElementById('pd-billing-status');
	assert.ok(el, 'rebuilt');
	assert.strictEqual(el.textContent, 'Χρεώθηκε 1 εκτύπωση.');
});

test('the notes hint promises no fixed number of characters', () => {
	const { PD, w } = app();
	const hint = w.document.getElementById('pd-notes-label-count');
	assert.ok(hint, 'Pro');
	assert.strictEqual(hint.textContent, '');
	PD.setFieldValue('pd-notes', 'x'.repeat(80));
	assert.match(hint.textContent, /μπορεί να χωρέσει μέρος των σημειώσεων/);
	assert.ok(!/\d/.test(hint.textContent));
	assert.ok(!hint.classList.contains('is-over'));
});

const CSS = fs.readFileSync(path.join(require('./lib/env.js').findJsDir(), '..', 'css', 'plandose.css'), 'utf8');

test('plandose.css: one focus ring rule, no overridden duplicates', () => {
	for (const sel of ['.plandose-step-dot:focus-visible', '.plandose-chip:focus-visible', '.plandose-mini-btn:focus-visible', '#plandose-lang-toggle:focus-visible']) {
		const rules = CSS.split('}').filter((r) => r.includes(sel) && /outline:\s*\dpx solid/.test(r));
		assert.strictEqual(rules.length, 1, sel);
		assert.match(rules[0], /outline:\s*3px solid/, sel);
	}
	assert.strictEqual((CSS.match(/^\.plandose-preview-area \{/gm) || []).length, 1);
});

test('plandose.css: contrast and a11y fixes', () => {
	const loaded = /\.pd-rx-row\.is-loaded \{([^}]*)\}/.exec(CSS)[1];
	assert.ok(!/opacity/.test(loaded));
	assert.match(CSS, /content: "ⓘ " \/ "";/);
	assert.match(CSS, /content: "⚠ " \/ "";/);
	assert.match(CSS, /#plandose-overlay,\s*#plandose-confirm-close \{\s*color-scheme: light;/);
	assert.match(CSS, /#pd-billing-status,\s*\.pd-billing-status \{/);
	assert.match(CSS, /\.pd-close-blocked \{/);
	const small = /@media \(max-width: 620px\) \{\s*#plandose-modal \.pd-rx-drop\.is-open textarea,[^}]*font-size: 16px;/.exec(CSS);
	assert.ok(small, 'inputs are 16px on phones');
	for (const sel of ['#pd-rx-text', '.pd-rx-md-input', '.pd-lbl-size select', '.pd-lbl-custom input']) {
		assert.ok(small[0].includes(sel), sel);
	}
});
