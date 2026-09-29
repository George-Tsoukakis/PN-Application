/* PlanDose — plandose-admin.js: <details data-pd-lazy-url> loads its body
   on first opening (the «Χρήστες που δεν μετράνε» box). TEST-ONLY.
   - nothing is fetched while the box stays closed;
   - opening fetches once, same-origin, and inserts the server's HTML;
   - a refusal shows the server message as text and puts the no-JS link
     back; the next opening retries. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { JS_DIR } = require('./harness.js');

const SRC = fs.readFileSync(path.join(JS_DIR, 'plandose-admin.js'), 'utf8');

const BOX = '<details id="pd-uncounted" data-pd-lazy-url="https://example.test/wp-admin/admin-ajax.php?action=plandose_uncounted_users&amp;nonce=abc">' +
	'<summary>Χρήστες: 2</summary>' +
	'<div class="pd-uncounted-details" data-pd-lazy-target><p><a href="?pd_uncounted=1#pd-uncounted">Εμφάνιση των χρηστών</a></p></div>' +
	'</details>';

function page(responses) {
	const dom = new JSDOM('<!doctype html><html><body>' + BOX + '</body></html>', { runScripts: 'outside-only', url: 'https://example.test/wp-admin/admin.php?page=plandose' });
	const calls = [];
	dom.window.fetch = (url, opts) => {
		calls.push({ url, opts });
		const next = responses.shift();
		if (next instanceof Error) {
			return Promise.reject(next);
		}
		return Promise.resolve({ ok: next.status < 400, status: next.status, json: () => Promise.resolve(next.body) });
	};
	dom.window.eval(SRC);
	dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
	return { dom, calls };
}

function open(dom) {
	const box = dom.window.document.querySelector('details');
	// jsdom fires 'toggle' itself (asynchronously) when .open changes.
	box.open = true;
	return box;
}

const tick = () => new Promise((r) => setTimeout(r, 0));
const settle = async () => { for (let i = 0; i < 5; i++) { await tick(); } };

test('closed box fetches nothing; opening loads once and inserts the HTML', async () => {
	const { dom, calls } = page([{ status: 200, body: { success: true, data: { html: '<table class="pd-uncounted-users"><tr><td>a@example.test</td></tr></table>' } } }]);
	await tick();
	assert.strictEqual(calls.length, 0, 'no request while closed');
	const box = open(dom);
	await settle();
	assert.strictEqual(calls.length, 1);
	assert.strictEqual(calls[0].url, 'https://example.test/wp-admin/admin-ajax.php?action=plandose_uncounted_users&nonce=abc');
	assert.strictEqual(calls[0].opts.credentials, 'same-origin');
	const target = box.querySelector('[data-pd-lazy-target]');
	assert.ok(target.querySelector('table.pd-uncounted-users'));
	assert.strictEqual(target.hasAttribute('aria-busy'), false);
	box.open = false;
	await settle();
	open(dom);
	await settle();
	assert.strictEqual(calls.length, 1, 'not fetched again after closing and reopening');
	dom.window.close();
});

test('refusal: message as text, fallback link back, retried on next opening', async () => {
	const { dom, calls } = page([
		{ status: 403, body: { success: false, data: { message: '<img src=x onerror=alert(1)>Δεν έχετε δικαίωμα' } } },
		new Error('network'),
		{ status: 200, body: { success: true, data: { html: '<p class="ok">ok</p>' } } },
	]);
	const box = open(dom);
	await settle();
	const target = box.querySelector('[data-pd-lazy-target]');
	assert.strictEqual(target.querySelector('img'), null, 'server message is not parsed as HTML');
	assert.ok(target.textContent.includes('Δεν έχετε δικαίωμα'));
	assert.ok(target.querySelector('a[href*="pd_uncounted=1"]'), 'no-JS link is back');

	box.open = false;
	await settle();
	open(dom);
	await settle();
	assert.strictEqual(calls.length, 2, 'retried after a failure');
	assert.ok(target.querySelector('a[href*="pd_uncounted=1"]'), 'network error: link still there');

	box.open = false;
	await settle();
	open(dom);
	await settle();
	assert.strictEqual(calls.length, 3);
	assert.ok(target.querySelector('p.ok'));
	dom.window.close();
});

test('a box rendered open (no-JS link followed) is not fetched', async () => {
	const dom = new JSDOM('<!doctype html><html><body><details open><div data-pd-lazy-target><table></table></div></details></body></html>', { runScripts: 'outside-only', url: 'https://example.test/wp-admin/' });
	let n = 0;
	dom.window.fetch = () => { n++; return Promise.reject(new Error('x')); };
	dom.window.eval(SRC);
	dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
	await tick();
	assert.strictEqual(n, 0);
	dom.window.close();
});
