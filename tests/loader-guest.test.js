/* plandose-loader.js (lazy loading of the tool) and plandose-guest.js (the
   teaser for visitors), each on its own page as the plugin serves them. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { loadLoader, loadGuest, closeAll } = require('./harness.js');

test.afterEach(closeAll);

function until(cond, ms) {
	const end = Date.now() + (ms || 3000);
	return new Promise((resolve, reject) => {
		(function poll() {
			if (cond()) {
				return resolve();
			}
			if (Date.now() > end) {
				return reject(new Error('timed out'));
			}
			setTimeout(poll, 10);
		})();
	});
}

test('loader: nothing of the tool loads with the page', async () => {
	const { w, requested } = await loadLoader();
	assert.deepStrictEqual(requested.filter((u) => !/plandose-loader\.js$/.test(u)), []);
	assert.strictEqual(w.__PlandoseNS, undefined);
});

test('loader: first click loads every module in enqueue order and opens the tool once', async () => {
	const { w, requested } = await loadLoader();
	const trigger = w.document.getElementById('plandose-trigger');
	trigger.click();
	trigger.click(); /* double click while loading */
	await until(() => w.__PlandoseNS && w.__PlandoseNS.__lazyReady);
	const js = requested.filter((u) => /\/js\//.test(u) && !/plandose-loader/.test(u)).map((u) => u.replace(/^.*\//, ''));
	assert.deepStrictEqual(js, ['state.js', 'api.js', 'validation.js', 'preview.js', 'medicine-form.js',
		'print-styles.js', 'calendar-qr.js', 'print.js', 'pro-labels.js', 'rx-text.js', 'rx-lines.js', 'rx-parse.js', 'rx-review.js', 'rx-import.js', 'modal.js', 'app.js'].filter((f) => js.includes(f)));
	assert.ok(js.indexOf('rx-import.js') < js.indexOf('modal.js'), 'rx-import before modal');
	assert.strictEqual(new Set(js).size, js.length, 'no module loaded twice');
	assert.strictEqual(w.document.getElementById('plandose-overlay').hidden, false, 'tool open');
	assert.ok(w.document.getElementById('pd-drug'), 'form built');
	assert.strictEqual(trigger.hasAttribute('aria-busy'), false);
});

test('loader: Free account never asks for pro-labels.js', async () => {
	const { w, requested } = await loadLoader({ pro: false });
	w.document.getElementById('plandose-trigger').click();
	await until(() => w.__PlandoseNS && w.__PlandoseNS.__lazyReady);
	assert.ok(!requested.some((u) => /pro-labels/.test(u)));
});

test('loader: a module that fails to load shows the error, not a half-built tool', async () => {
	const { w } = await loadLoader({ failUrl: (u) => /print\.js$/.test(u) });
	w.document.getElementById('plandose-trigger').click();
	await until(() => w.document.querySelector('.plandose-loader-error'));
	assert.strictEqual(w.document.getElementById('plandose-overlay').hidden, false);
	assert.strictEqual(w.document.querySelector('.plandose-loader-error').getAttribute('role'), 'alert');
	assert.ok(!w.document.getElementById('pd-drug'));
});

test('guest: the button opens the teaser, Escape closes it and focus returns', () => {
	const { w } = loadGuest();
	const d = w.document;
	const overlay = d.getElementById('plandose-overlay');
	overlay.hidden = true;
	const trigger = d.getElementById('plandose-trigger');
	trigger.focus();
	trigger.click();
	assert.strictEqual(overlay.hidden, false);
	d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
	assert.strictEqual(overlay.hidden, true);
	assert.strictEqual(d.activeElement, trigger);
});
