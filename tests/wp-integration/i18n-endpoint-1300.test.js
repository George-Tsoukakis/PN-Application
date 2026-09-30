/* The front-end dictionaries over real HTTP (tests/README.md). Run: npm run test:wp

   - a Free / Pro pharmacy page no longer inlines the dictionaries; the
     loader names content-versioned dictionary scripts (English for Pro only);
   - admin-ajax.php?action=plandose_i18n answers with JavaScript and a
     year-long public immutable cache for the current version, for a
     logged-in pharmacy and for a guest alike; a stale version is an
     uncached 302 to the current URL (1.30.2); an unknown dictionary is a 400;
   - locales: a page rendered in another locale (here a throw-away zz_ZZ
     translation) gets its own URL, and the script answers in the page's
     locale even when admin-ajax runs in the user's own (en_US). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, wpCli, env } = require('../lib/wp-client.js');

let free = null;
let pro = null;
const pages = {};

async function frontPage(client) {
	const res = await fetch(env.BASE + '/', { headers: { cookie: client.cookieHeader() } });
	return res.text();
}

function loaderData(html) {
	const m = html.match(/var PlandoseLoader = (\{.*?\});/);
	return m ? JSON.parse(m[1]) : null;
}

function inlineConfig(html) {
	const m = html.match(/<script[^>]*>\s*var PlandoseConfig = [\s\S]*?<\/script>/);
	return m ? m[0] : '';
}

test.before(async () => {
	free = tempUser();
	pro = tempUser({ pro: true });
	for (const [name, u] of [['free', free], ['pro', pro]]) {
		const c = await new WpClient(u).login();
		const html = await frontPage(c);
		pages[name] = { client: c, html, loader: loaderData(html), config: inlineConfig(html) };
	}
});

test.after(() => {
	for (const u of [free, pro]) {
		if (u) {
			u.remove();
		}
	}
});

test('pages: PlandoseConfig is small and carries no dictionary', () => {
	for (const name of ['free', 'pro']) {
		const { config } = pages[name];
		assert.ok(config, name + ': PlandoseConfig printed');
		assert.ok(!/modalSubtitle|rxDropHelp|"i18nEn"/.test(config), name + ': no dictionary inline');
		assert.ok(Buffer.byteLength(config) < 4000, name + ': inline config ' + Buffer.byteLength(config) + ' bytes');
		console.log('# ' + name + ' inline PlandoseConfig: ' + Buffer.byteLength(config) + ' bytes');
	}
});

test('pages: the loader names the dictionary scripts, English for Pro only', () => {
	const langs = (name) => pages[name].loader.i18n.map((u) => new URL(u).searchParams.get('lang'));
	assert.deepStrictEqual(langs('free'), ['el']);
	assert.deepStrictEqual(langs('pro'), ['el', 'en']);
	assert.strictEqual(pages.free.loader.i18n[0], pages.pro.loader.i18n[0], 'one Greek URL for everyone');
	for (const u of pages.pro.loader.i18n) {
		const q = new URL(u).searchParams;
		assert.strictEqual(q.get('action'), 'plandose_i18n');
		assert.match(q.get('v'), /^[0-9a-f]{16}$/);
		assert.ok(q.get('locale'), 'locale in the URL');
	}
});

test('endpoint: JavaScript, cached for a year; Greek for everyone, English for Pro only', async () => {
	for (const url of pages.pro.loader.i18n) {
		const lang = new URL(url).searchParams.get('lang');
		for (const [who0, cookie] of [['Pro pharmacy', pages.pro.client.cookieHeader()], ['Free pharmacy', pages.free.client.cookieHeader()], ['guest', '']]) {
			const res = await fetch(url, { headers: cookie ? { cookie } : {} });
			const who = lang + ' (' + who0 + ')';
			if (lang === 'en' && who0 !== 'Pro pharmacy') {
				assert.strictEqual(res.status, 403, who + ': the English toggle is Pro-only');
				assert.doesNotMatch(await res.text(), /Create Dosage Plan/, who);
				assert.match(res.headers.get('cache-control'), /no-cache/, who);
				continue;
			}
			assert.strictEqual(res.status, 200, who);
			assert.match(res.headers.get('content-type'), /^application\/javascript/, who);
			const cc = res.headers.get('cache-control');
			assert.match(cc, lang === 'en' ? /private/ : /public/, who + ': ' + cc);
			assert.match(cc, /max-age=31536000/, who + ': ' + cc);
			assert.match(cc, /immutable/, who + ': ' + cc);
			assert.doesNotMatch(cc, lang === 'en' ? /no-store|no-cache|public/ : /no-store|no-cache|private/, who + ': ' + cc);
			assert.ok(!res.headers.get('pragma'), who + ': no Pragma');
			assert.ok(!res.headers.get('set-cookie'), who + ': no cookie');
			assert.strictEqual(res.headers.get('x-content-type-options'), 'nosniff');
			const body = await res.text();
			const prefix = 'window.PlandoseI18n=window.PlandoseI18n||{};window.PlandoseI18n.' + lang + '=';
			assert.ok(body.startsWith(prefix), who + ': ' + body.slice(0, 80));
			const dict = JSON.parse(body.slice(prefix.length).replace(/;\s*$/, ''));
			assert.ok(Object.keys(dict).length > 100, who + ': whole dictionary');
			assert.strictEqual(dict.modalTitle, lang === 'en' ? 'Create Dosage Plan' : 'Δημιουργία Πλάνου Δοσολογίας', who);
			const etag = res.headers.get('etag');
			const again = await fetch(url, { headers: Object.assign({ 'if-none-match': etag }, cookie ? { cookie } : {}) });
			assert.strictEqual(again.status, 304, who + ': revalidation → 304');
		}
	}
});

test('endpoint: stale version redirected uncached, unknown dictionary 400', async () => {
	const u = new URL(pages.free.loader.i18n[0]);
	u.searchParams.set('v', '0000000000000000');
	const stale = await fetch(u, { redirect: 'manual' });
	assert.strictEqual(stale.status, 302);
	assert.match(stale.headers.get('cache-control'), /no-cache/);
	assert.strictEqual(new URL(stale.headers.get('location')).searchParams.get('v'), new URL(pages.free.loader.i18n[0]).searchParams.get('v'));
	u.searchParams.set('lang', 'xx');
	const bad = await fetch(u);
	assert.strictEqual(bad.status, 400);
	assert.match(bad.headers.get('cache-control'), /no-cache/);
});

test('locales: the dictionary follows the page\'s locale, not the admin-ajax user\'s', async () => {
	const php = [
		'$d = WP_LANG_DIR; wp_mkdir_p( $d . "/plugins" );',
		'$core = new MO(); $core->export_to_file( $d . "/zz_ZZ.mo" );',
		'$mo = new MO(); $mo->add_entry( new Translation_Entry( array( "singular" => "Δημιουργία Πλάνου Δοσολογίας", "translations" => array( "ZZ plan title" ) ) ) );',
		'$mo->export_to_file( $d . "/plugins/plandose-zz_ZZ.mo" );',
		'echo get_option( "WPLANG", "" );'
	].join(' ');
	const before = wpCli(['eval', php]);
	try {
		wpCli(['option', 'update', 'WPLANG', 'zz_ZZ']);
		wpCli(['user', 'meta', 'update', String(pro.id), 'locale', 'en_US']);
		const c = await new WpClient(pro).login();
		const loader = loaderData(await frontPage(c));
		const el = loader.i18n.find((u) => new URL(u).searchParams.get('lang') === 'el');
		assert.strictEqual(new URL(el).searchParams.get('locale'), 'zz_ZZ');
		assert.notStrictEqual(el, pages.pro.loader.i18n[0], 'another locale, another URL');
		for (const cookie of [c.cookieHeader(), '']) {
			const res = await fetch(el, { headers: cookie ? { cookie } : {} });
			const body = await res.text();
			const who = cookie ? 'pharmacy with an en_US profile' : 'guest';
			assert.ok(body.includes('"modalTitle":"ZZ plan title"'), who + ': translated: ' + body.slice(0, 160));
			assert.match(res.headers.get('cache-control'), /immutable/, who + ': the page\'s version matches');
		}
		/* The en_US page's URL still gets the en_US (source) strings. */
		const old = await fetch(pages.pro.loader.i18n[0]);
		const oldBody = await old.text();
		assert.ok(oldBody.includes('"modalTitle":"Δημιουργία Πλάνου Δοσολογίας"'), 'en_US URL: untranslated');
		assert.match(old.headers.get('cache-control'), /immutable/);
	} finally {
		if (before) {
			wpCli(['option', 'update', 'WPLANG', before]);
		} else {
			wpCli(['option', 'delete', 'WPLANG']);
		}
		wpCli(['user', 'meta', 'delete', String(pro.id), 'locale']);
		wpCli(['eval', '@unlink( WP_LANG_DIR . "/zz_ZZ.mo" ); @unlink( WP_LANG_DIR . "/plugins/plandose-zz_ZZ.mo" );']);
	}
});
