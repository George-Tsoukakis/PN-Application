/* The on-screen preview («Αυτό είναι το πλάνο που θα εκτυπωθεί») is styled
   by the print stylesheet itself (PD.PRINT_STYLES), scoped to the preview's
   paper by preview.js — not by a look-alike copy in plandose.css, which
   drifted: half-width previews of full-width day cards, a solid green day
   header, grey italic notes. Real Chromium layout checks: tests/visual/
   (the preview and the print sheet are compared box by box). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const env = require('./lib/env.js');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const SCOPE = '#pd-preview-area .pd-preview-sheet';

function med(name, extra) {
	return Object.assign({
		name, doseAmount: 1, doseUnit: 'tablet', freq: '12h', dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: '3', notes: ''
	}, extra || {});
}

function app(items) {
	const e = load();
	e.PD.s.startDate = isoAhead(1);
	e.PD.s.header = { name: 'Φαρμακείο Δοκιμής' };
	e.PD.buildApp();
	e.PD.s.items = items;
	e.PD.renderPreview();
	return e;
}

/* Every style rule of a stylesheet text as { media, selectors, body }. */
function rules(css) {
	const dom = new JSDOM('<!doctype html><style>' + css + '</style>');
	const out = [];
	const walk = (list, media) => {
		for (const r of list) {
			if (r.cssRules && r.media) {
				walk(r.cssRules, r.media.mediaText);
			} else if (r.selectorText) {
				out.push({ media, selectors: r.selectorText.split(/,(?![^(]*\))/).map((s) => s.trim()), body: r.style.cssText });
			}
		}
	};
	walk(dom.window.document.styleSheets[0].cssRules, '');
	dom.window.close();
	return out;
}

function scoped(w) {
	const el = w.document.getElementById('pd-preview-print-styles');
	assert.ok(el, 'scoped print stylesheet in the page');
	return el.textContent;
}

function body(css, selector) {
	const hit = rules(css).find((r) => r.selectors.length === 1 && r.selectors[0] === selector);
	assert.ok(hit, 'rule for ' + selector);
	return hit.body;
}

test('the preview sheet is the printed sheet, on a paper styled by PRINT_STYLES', () => {
	const { PD, w } = app([med('ΦΑΡΜΑΚΟ Α', { notes: 'Μετά το φαγητό.' })]);
	const sheet = w.document.querySelector('#pd-preview-area .pd-preview-page > .pd-preview-sheet');
	assert.ok(sheet, 'paper in the preview');
	const doc = sheet.querySelector(':scope > .pd-print-document');
	assert.ok(doc, 'the sheet on the paper');
	const t = w.document.createElement('template');
	t.innerHTML = PD.buildPrintHtml();
	assert.strictEqual(doc.outerHTML, t.innerHTML, 'the same markup print.js prints');
});

test('every print rule reaches the preview, scoped to its paper; @page does not', () => {
	const { PD, w } = app([med('ΦΑΡΜΑΚΟ Α')]);
	const css = scoped(w);
	const print = rules(PD.PRINT_STYLES).filter((r) => !r.selectors[0].startsWith('@'));
	const shown = rules(css);
	assert.strictEqual(shown.length, print.length, 'one scoped rule per print rule');
	for (const r of shown) {
		for (const s of r.selectors) {
			assert.ok(s === SCOPE || s.startsWith(SCOPE + ' '), 'scoped: ' + s);
		}
	}
	assert.doesNotMatch(css, /@page/);
	/* Print-only rules stay print-only. */
	assert.ok(shown.some((r) => r.media === 'print'), '@media print kept');
	assert.ok(shown.filter((r) => r.media === 'print').length === print.filter((r) => r.media === 'print').length);
	/* html/body of the print document are the paper. */
	assert.match(body(css, SCOPE), /font-family: Arial/);
	assert.ok(!/(^|,)\s*(html|body)\b/.test(css.replace(/\{[^}]*\}/g, '')), 'no bare html/body selector left');
});

test('the three known mismatches: full-width dense cards, light day header, upright black notes', () => {
	const { w } = app([med('ΦΑΡΜΑΚΟ Α', { notes: 'Μετά το φαγητό.' })]);
	const css = scoped(w);
	assert.match(body(css, SCOPE + ' .pd-day-card-dense'), /flex-basis: 100%/);
	const head = body(css, SCOPE + ' .pd-day-card-head');
	assert.match(head, /background: #e3f1ec|background-color: #e3f1ec|rgb\(227, 241, 236\)/);
	assert.match(head, /(^|; )color: (#0b4d3c|rgb\(11, 77, 60\))/);
	const notes = body(css, SCOPE + ' .pd-day-dose-notes');
	assert.match(notes, /font-style: normal/);
	assert.match(notes, /(^|; )color: (#1a1a1a|rgb\(26, 26, 26\))/);
});

test('plandose.css no longer styles the sheet (only the preview frame)', () => {
	const css = fs.readFileSync(path.join(env.pluginDir(), 'assets', 'css', 'plandose.css'), 'utf8');
	const sheetClasses = ['pd-print-document', 'pd-print-header', 'pd-print-brand', 'pd-print-intro', 'pd-info-grid', 'pd-info-box',
		'pd-cal-box', 'pd-cal-qr', 'pd-cal-text', 'pd-med-key', 'pd-drug-course', 'pd-drug-notes', 'pd-day-cards', 'pd-day-row', 'pd-day-card',
		'pd-day-slot', 'pd-day-dose', 'plandose-box', 'plandose-disclaimer', 'pd-print-thanks', 'pd-drugs'];
	const hits = [];
	for (const r of rules(css)) {
		for (const s of r.selectors) {
			for (const c of sheetClasses) {
				if (new RegExp('\\.' + c + '(?![\\w-])').test(s)) {
					hits.push(s);
				}
			}
		}
	}
	assert.deepStrictEqual(hits, []);
	assert.match(css, /\.pd-preview-sheet \{[^}]*width: 210mm;[^}]*padding: 12mm 10mm;/, 'A4 paper with the @page margins');
});

test('the scoped stylesheet is added once, however often the preview renders', () => {
	const { PD, w } = app([med('ΦΑΡΜΑΚΟ Α')]);
	PD.renderPreview();
	PD.s.items.push(med('ΦΑΡΜΑΚΟ Β'));
	PD.renderPreview();
	assert.strictEqual(w.document.querySelectorAll('#pd-preview-print-styles').length, 1);
	/* The throwaway parsing <style> is gone. */
	assert.strictEqual(w.document.querySelectorAll('style[media="not all"]').length, 0);
});

test('scopeSelector / splitSelectorList: html and body are the paper, commas inside :has() stay', () => {
	const { PD } = load();
	const s = (x) => PD.scopeSelector(x, '#a .b');
	assert.strictEqual(s('body'), '#a .b');
	assert.strictEqual(s('html'), '#a .b');
	assert.strictEqual(s('html body .x'), '#a .b .x');
	assert.strictEqual(s('body > .x'), '#a .b > .x');
	assert.strictEqual(s('*'), '#a .b *');
	assert.strictEqual(s('.body-text'), '#a .b .body-text');
	assert.strictEqual(s('bodyx'), '#a .b bodyx');
	assert.deepStrictEqual(Array.from(PD.splitSelectorList('.a:has(.b, .c), [data-x="1,2"], .d')), ['.a:has(.b, .c)', '[data-x="1,2"]', '.d']);
	assert.strictEqual(PD.scopeStylesheet('html, body, * { color: red; } @page { margin: 1mm; }', '#a .b'), '#a .b, #a .b * { color: red; }');
	assert.strictEqual(PD.scopeStylesheet('', '#a .b'), '');
});

test('fitPreviewSheet scales the A4 paper down to the preview width, never up', () => {
	const { PD, w } = app([med('ΦΑΡΜΑΚΟ Α')]);
	const area = w.document.getElementById('pd-preview-area');
	const page = area.querySelector('.pd-preview-page');
	const sheet = area.querySelector('.pd-preview-sheet');
	/* No layout (hidden step, jsdom): left alone. */
	PD.fitPreviewSheet(area);
	assert.strictEqual(sheet.style.transform, '');
	assert.strictEqual(page.style.width, '');
	const size = (el, props) => {
		for (const [k, v] of Object.entries(props)) {
			Object.defineProperty(el, k, { configurable: true, get: () => v });
		}
	};
	area.style.padding = '10px';
	size(area, { clientWidth: 417 });
	size(sheet, { offsetWidth: 794, offsetHeight: 2000 });
	PD.fitPreviewSheet(area);
	assert.strictEqual(sheet.style.transform, 'scale(0.5)');
	assert.strictEqual(page.style.width, '397px');
	assert.strictEqual(page.style.height, '1000px');
	/* Wider than the paper: natural size again. */
	size(area, { clientWidth: 1000 });
	PD.fitPreviewSheet(area);
	assert.strictEqual(sheet.style.transform, '');
	assert.strictEqual(page.style.width, '');
	assert.strictEqual(page.style.height, '');
});
