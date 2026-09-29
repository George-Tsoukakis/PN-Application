/* PlanDose 1.27.0 — plandose-admin.js (wp-admin). TEST-ONLY.
   - forms marked .pd-confirm-submit ask data-confirm (inline onsubmit gone);
   - choosing an invoice file submits its form (inline onchange gone);
   - checkbox pills carry no aria-checked on the <label> (invalid there). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { JS_DIR } = require('./harness.js');

const SRC = fs.readFileSync(path.join(JS_DIR, 'plandose-admin.js'), 'utf8');

function page(body) {
	const dom = new JSDOM('<!doctype html><html><body>' + body + '</body></html>', { runScripts: 'outside-only', url: 'https://example.test/wp-admin/' });
	dom.window.eval(SRC);
	// jsdom is still 'loading' here; the script waits for DOMContentLoaded.
	dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
	return dom;
}

test('confirm-submit: cancel blocks the submit, OK lets it through', () => {
	const dom = page('<form class="pd-confirm-submit" data-confirm="Sure?"><button>x</button></form>');
	const { window } = dom;
	const form = window.document.querySelector('form');
	const asked = [];
	let answer = false;
	window.confirm = (m) => { asked.push(m); return answer; };
	const ev1 = new window.Event('submit', { cancelable: true });
	form.dispatchEvent(ev1);
	assert.deepStrictEqual(asked, ['Sure?']);
	assert.strictEqual(ev1.defaultPrevented, true);
	answer = true;
	const ev2 = new window.Event('submit', { cancelable: true });
	form.dispatchEvent(ev2);
	assert.strictEqual(ev2.defaultPrevented, false);
	dom.window.close();
});

test('no inline handlers left in the admin PHP', () => {
	const inc = path.join(JS_DIR, '..', '..', 'includes');
	for (const f of ['class-plandose-admin-invoices.php', 'class-plandose-admin-subscriptions.php', 'class-plandose-diagnostics.php']) {
		const php = fs.readFileSync(path.join(inc, f), 'utf8');
		assert.ok(!/\son(submit|change|click)=/.test(php), f + ' has no inline event handler');
	}
});

test('invoice file input submits its form on change', () => {
	const dom = page('<form><label><input type="file" class="pd-invoice-file"></label></form>');
	const { window } = dom;
	const form = window.document.querySelector('form');
	let submitted = 0;
	form.requestSubmit = () => { submitted++; };
	const input = window.document.querySelector('input');
	Object.defineProperty(input, 'files', { value: [{ name: 'a.pdf' }] });
	input.dispatchEvent(new window.Event('change'));
	assert.strictEqual(submitted, 1);
	dom.window.close();
});

test('checkbox pills: class toggles, no aria-checked on the label', () => {
	const dom = page('<label class="pd-checkbox-pill"><input type="checkbox"><span>PDF</span></label>');
	const { window } = dom;
	const pill = window.document.querySelector('label');
	const input = pill.querySelector('input');
	input.checked = true;
	input.dispatchEvent(new window.Event('change'));
	assert.ok(pill.classList.contains('is-checked'));
	assert.strictEqual(pill.hasAttribute('aria-checked'), false);
	dom.window.close();
});
