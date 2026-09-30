/* Job Listings — browser checks of the inline scripts (Chromium, no WordPress). TEST-ONLY.
   Run: cd tests && npm run test:jobs */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { execFileSync } = require('child_process');
const path = require('path');
const { chromium } = require('playwright-core');
const env = require('../lib/env');

const render = (f) => execFileSync('php', [path.join(__dirname, 'fixtures', f)], { encoding: 'utf8' });

let browser;
test.before(async () => { browser = await chromium.launch({ executablePath: env.chromiumPath() }); });
test.after(async () => { if (browser) { await browser.close(); } });

test('URL import: one request per link, progress, failed and over-limit links kept', async () => {
	const html = render('import-page.php');
	const page = await browser.newPage();
	const seen = [];
	await page.route('http://t.local/**', (r) => {
		if (!r.request().url().includes('admin-ajax')) { return r.fulfill({ contentType: 'text/html; charset=utf-8', body: html }); }
		const url = decodeURIComponent(((r.request().postData() || '').match(/name="url"\r\n\r\n([^\r]*)/) || [])[1] || '');
		seen.push(url);
		if (url.includes('boom')) { return r.fulfill({ status: 500, body: 'fatal' }); }
		const status = url.includes('dup') ? 'duplicate' : 'created';
		return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { status, row: `<tr><td>${url}</td></tr>` } }) });
	});
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	await page.goto('http://t.local/import');
	await page.fill('textarea', 'https://a.gr/1\nhttps://a.gr/dup  https://a.gr/1\nhttps://a.gr/boom\nhttps://a.gr/extra');
	await page.click('#jbli_import_submit');
	await page.waitForFunction(() => !document.getElementById('jbli_import_submit').disabled && document.getElementById('jbli_import_progress').textContent.includes('Ολοκληρώθηκε'));
	const out = await page.evaluate(() => ({
		rows: document.querySelectorAll('#jbli_import_rows tr').length,
		progress: document.getElementById('jbli_import_progress').textContent,
		left: document.querySelector('textarea').value,
		label: document.getElementById('jbli_import_submit').textContent,
	}));
	assert.deepStrictEqual(errors, []);
	assert.deepStrictEqual(seen, ['https://a.gr/1', 'https://a.gr/dup', 'https://a.gr/boom'], 'deduplicated, limited to 3');
	assert.strictEqual(out.rows, 3);
	assert.match(out.progress, /1 νέες, 1 υπήρχαν ήδη, 1 σφάλματα/);
	assert.strictEqual(out.left, 'https://a.gr/boom\nhttps://a.gr/extra', 'failed + over-limit links stay for the next run');
	assert.strictEqual(out.label, 'Εισαγωγή');
	await page.close();
});

test('View beacon: one request after 2s, count updated on the page', async () => {
	const html = render('single-page.php');
	const page = await browser.newPage();
	let calls = 0;
	let body = '';
	await page.route('http://t.local/**', (r) => {
		if (!r.request().url().includes('admin-ajax')) { return r.fulfill({ contentType: 'text/html; charset=utf-8', body: html }); }
		calls++;
		body = r.request().postData() || '';
		return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { views: 1234, counted: true } }) });
	});
	await page.goto('http://t.local/aggelia/x');
	await page.waitForTimeout(1000);
	assert.strictEqual(calls, 0, 'nothing sent before 2 seconds');
	await page.waitForTimeout(1800);
	assert.strictEqual(calls, 1);
	assert.ok(body.includes('jbli_view') && body.includes('42'));
	assert.strictEqual(await page.textContent('[data-jbli_views_count]'), '1.234');
	await page.waitForTimeout(2500);
	assert.strictEqual(calls, 1, 'sent only once');
	await page.close();
});
