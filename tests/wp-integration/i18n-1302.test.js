/* The dictionary script with a version it does not hold (1.30.2). Run: npm run test:wp

   A random ?v= used to bypass a CDN and make admin-ajax build the whole
   dictionary (~320 strings, perhaps under switch_to_locale()) for an
   uncached 200 on every request. Now any version other than the current
   one gets an uncached 302 to the current URL, read from the stored
   hashes; the current URL still gets the long-cached script. Also:
   - a made-up (not installed) locale redirects to the current locale, so
     it can neither be served nor fill the stored hashes;
   - no stored hash (e.g. just after a settings save) builds and stores it
     once, then redirects — the next page's URL is the same one;
   - 400 for an unknown dictionary and 403 for English without Pro are
     unchanged, and are not redirects;
   - plandose-guest.css (popup shell / teaser) no longer blocks rendering. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, wpCli, env } = require('../lib/wp-client.js');

let free = null;
let pro = null;
let freeC = null;
let proC = null;

async function page(cookie) {
	const res = await fetch(env.BASE + '/', { headers: cookie ? { cookie } : {} });
	return res.text();
}

function loaderUrls(html) {
	const m = html.match(/var PlandoseLoader = (\{.*?\});/);
	return m ? JSON.parse(m[1]).i18n : [];
}

function withV(url, v, extra) {
	const u = new URL(url);
	u.searchParams.set('v', v);
	for (const [k, val] of Object.entries(extra || {})) {
		u.searchParams.set(k, val);
	}
	return u.toString();
}

test.before(async () => {
	free = tempUser();
	pro = tempUser({ pro: true });
	freeC = await new WpClient(free).login();
	proC = await new WpClient(pro).login();
});

test.after(() => {
	for (const u of [free, pro]) {
		if (u) {
			u.remove();
		}
	}
});

test('random version: uncached 302 to the current URL, no dictionary in the body', async () => {
	const [el, en] = loaderUrls(await page(proC.cookieHeader()));
	assert.ok(el && en, 'Pro page names both dictionaries');
	for (const [url, cookie, who] of [[el, '', 'guest el'], [el, freeC.cookieHeader(), 'Free el'], [en, proC.cookieHeader(), 'Pro en']]) {
		for (const v of ['0123456789abcdef', 'x', '']) {
			const res = await fetch(withV(url, v), { redirect: 'manual', headers: cookie ? { cookie } : {} });
			assert.strictEqual(res.status, 302, who + ' v=' + v);
			assert.match(res.headers.get('cache-control'), /no-cache/, who);
			assert.doesNotMatch(res.headers.get('cache-control'), /immutable|public/, who);
			assert.strictEqual(res.headers.get('location'), url, who + ': to the page\'s own URL');
			assert.strictEqual(await res.text(), '', who + ': empty body');
		}
		const ok = await fetch(url, { headers: cookie ? { cookie } : {} });
		assert.strictEqual(ok.status, 200, who + ': current URL');
		assert.match(ok.headers.get('cache-control'), /immutable/, who);
	}
});

test('a followed redirect ends at the long-cached script', async () => {
	const [el] = loaderUrls(await page(freeC.cookieHeader()));
	const res = await fetch(withV(el, 'ffffffffffffffff'));
	assert.strictEqual(res.status, 200);
	assert.strictEqual(res.url, el);
	assert.match(res.headers.get('cache-control'), /max-age=31536000/);
	assert.ok((await res.text()).startsWith('window.PlandoseI18n='));
});

test('a made-up locale is redirected to the current one', async () => {
	const [el] = loaderUrls(await page(freeC.cookieHeader()));
	const res = await fetch(withV(el, '0000000000000000', { locale: 'qq_QQ' }), { redirect: 'manual' });
	assert.strictEqual(res.status, 302);
	assert.strictEqual(res.headers.get('location'), el);
	const matching = await fetch(withV(el, new URL(el).searchParams.get('v'), { locale: 'qq_QQ' }), { redirect: 'manual' });
	assert.strictEqual(matching.status, 302, 'even with the right hash, the canonical URL names the real locale');
	assert.strictEqual(matching.headers.get('location'), el);
	const stored = wpCli(['eval', 'echo wp_json_encode( get_option( "plandose_i18n_versions" ) );']);
	assert.doesNotMatch(stored, /qq_QQ/);
});

test('nothing stored yet: built and stored once, then redirected to the same URL a page gets', async () => {
	wpCli(['option', 'delete', 'plandose_i18n_versions']);
	const res = await fetch(env.BASE + '/wp-admin/admin-ajax.php?action=plandose_i18n&lang=el&locale=en_US&v=0000000000000000', { redirect: 'manual' });
	assert.strictEqual(res.status, 302);
	const stored = JSON.parse(wpCli(['eval', 'echo wp_json_encode( get_option( "plandose_i18n_versions" ) );']));
	assert.strictEqual(Object.keys(stored).length, 1, 'one fingerprint stored');
	const [el] = loaderUrls(await page(freeC.cookieHeader()));
	assert.strictEqual(res.headers.get('location'), el, 'the page names the same URL');
	const again = JSON.parse(wpCli(['eval', 'echo wp_json_encode( get_option( "plandose_i18n_versions" ) );']));
	assert.deepStrictEqual(Object.keys(again), Object.keys(stored), 'the page view reused it');
});

test('400 and 403 are unchanged, never redirects', async () => {
	const [el, en] = loaderUrls(await page(proC.cookieHeader()));
	const bad = await fetch(withV(el, '0000000000000000', { lang: 'xx' }), { redirect: 'manual' });
	assert.strictEqual(bad.status, 400);
	for (const v of [new URL(en).searchParams.get('v'), '0000000000000000']) {
		for (const cookie of ['', freeC.cookieHeader()]) {
			const res = await fetch(withV(en, v), { redirect: 'manual', headers: cookie ? { cookie } : {} });
			assert.strictEqual(res.status, 403, 'en without Pro, v=' + v);
			assert.ok(!res.headers.get('location'));
		}
	}
});

test('guest page: plandose-guest.css is preloaded, not render-blocking', async () => {
	const html = await page('');
	assert.match(html, /<link rel="preload" id="plandose-guest-css"[^>]*as="style"[^>]*onload=/);
	assert.match(html, /<noscript><link rel="stylesheet" id="plandose-guest-css"/);
	assert.match(html, /<link rel=['"]stylesheet['"] id=['"]plandose-trigger-css['"]/, 'the button\'s own stylesheet still blocks');
	assert.match(html, /<div id="plandose-overlay" hidden>/, 'the teaser stays hidden until the CSS arrives');
});
