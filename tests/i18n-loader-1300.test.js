/* The dictionaries as their own cacheable script: plandose-loader.js fetches
   them with the modules (never with the page) and runs them first; state.js
   reads window.PlandoseI18n and still accepts the legacy inline
   PlandoseConfig.i18n; a dictionary that fails to load is not fatal. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const env = require('./lib/env.js');
const i18n = require('./lib/i18n.js');
const { load, loadLoader, closeAll } = require('./harness.js');
const { until } = require('./lib/until.js');

test.afterEach(closeAll);

const ORIGIN = 'https://example.test';
const AJAX = ORIGIN + '/wp-admin/admin-ajax.php?action=plandose_i18n&locale=el';
const EL_URL = AJAX + '&lang=el&v=0123456789abcdef';
const EN_URL = AJAX + '&lang=en&v=fedcba9876543210';

let dicts = null;
function real() {
	if (!dicts) {
		dicts = i18n.flatDictionaries(env.pluginDir(), 90);
	}
	return dicts;
}

function script(lang, dict) {
	return 'window.PlandoseI18n=window.PlandoseI18n||{};window.PlandoseI18n.' + lang + '=' + JSON.stringify(dict) + ';\n';
}


/* A page as the plugin now prints it: only the pharmacy's texts inline. */
function page(opts) {
	const el = Object.assign({}, real().el, { modalTitle: 'Τίτλος από το script' });
	const pro = opts.pro !== false;
	return Object.assign({
		config: { i18n: { disclaimer: 'Σημείωση του φαρμακείου' }, i18nEn: undefined },
		loader: { i18n: pro ? [EL_URL, EN_URL] : [EL_URL] },
		serve: { [EL_URL]: script('el', el), [EN_URL]: script('en', real().en) }
	}, opts);
}

async function open(opts) {
	const r = await loadLoader(opts);
	r.w.document.getElementById('plandose-trigger').click();
	await until(() => (r.w.__PlandoseNS && r.w.__PlandoseNS.__lazyReady) || r.w.document.querySelector('.plandose-loader-error'));
	return r;
}

test('loader: the dictionary is not fetched with the page', async () => {
	const { requested } = await loadLoader(page({ pro: false }));
	assert.deepStrictEqual(requested.filter((u) => !/plandose-loader\.js$/.test(u)), []);
});

test('loader: first click fetches the Greek dictionary before state.js, and the tool uses it', async () => {
	const { w, requested } = await open(page({ pro: false }));
	const iDict = requested.indexOf(EL_URL);
	const iState = requested.findIndex((u) => /\/state\.js$/.test(u));
	assert.ok(iDict !== -1 && iState !== -1 && iDict < iState, 'dictionary requested before state.js: ' + requested.join(' '));
	assert.ok(!requested.includes(EN_URL), 'Free: no English dictionary');
	assert.strictEqual(requested.filter((u) => u === EL_URL).length, 1, 'fetched once');
	const PD = w.__PlandoseNS;
	assert.strictEqual(PD.txt('modalTitle'), 'Τίτλος από το script', 'string from the dictionary script');
	assert.strictEqual(PD.txt('rxOpen'), real().el.rxOpen);
	assert.strictEqual(PD.txt('disclaimer'), 'Σημείωση του φαρμακείου', 'inline pharmacy text wins');
	assert.strictEqual(w.document.getElementById('plandose-overlay').hidden, false, 'tool open');
	assert.ok(w.document.getElementById('pd-drug'), 'form built');
	assert.ok(!w.document.querySelector('.plandose-loader-error'));
});

test('loader: Pro gets the English dictionary too; English overrides the inline Greek texts', async () => {
	const { w, requested } = await open(page({}));
	assert.ok(requested.indexOf(EN_URL) < requested.findIndex((u) => /\/state\.js$/.test(u)), 'English before state.js');
	const PD = w.__PlandoseNS;
	assert.strictEqual(PD.dicts.en.modalTitle, real().en.modalTitle);
	assert.strictEqual(PD.dicts.en.disclaimer, real().en.disclaimer);
	assert.strictEqual(PD.dicts.el.disclaimer, 'Σημείωση του φαρμακείου');
	assert.strictEqual(PD.dicts.en.rxOpen, real().en.rxOpen);
});

test('loader: a dictionary that fails to load is not fatal — the tool opens with its Greek fallbacks', async () => {
	const { w, requested } = await open(page({ failUrl: (u) => /action=plandose_i18n/.test(u) }));
	assert.ok(requested.includes(EL_URL), 'it was asked for');
	assert.ok(!w.document.querySelector('.plandose-loader-error'), 'no load error');
	assert.strictEqual(w.document.getElementById('plandose-overlay').hidden, false, 'tool open');
	assert.ok(w.document.getElementById('pd-drug'), 'form built');
	const PD = w.__PlandoseNS;
	assert.strictEqual(PD.txt('modalTitle', 'Προεπιλογή'), 'Προεπιλογή', 'PD.txt falls back');
	assert.strictEqual(PD.txt('disclaimer'), 'Σημείωση του φαρμακείου', 'the pharmacy\'s printed texts are still there');
	assert.ok(/Φάρμακα/.test(w.document.getElementById('plandose-app').textContent), 'Greek fallback labels shown');
});

test('loader: a module that fails still shows the load error, dictionaries or not', async () => {
	const { w } = await open(page({ failUrl: (u) => /print\.js$/.test(u) }));
	assert.ok(w.document.querySelector('.plandose-loader-error'));
	assert.ok(!w.document.getElementById('pd-drug'));
});

test('state.js: legacy inline PlandoseConfig.i18n / i18nEn still work without the global', () => {
	const { PD, w } = load({ i18n: { el: { modalTitle: 'Α' }, en: { modalTitle: 'A' } } });
	assert.strictEqual(w.PlandoseI18n, undefined);
	assert.strictEqual(PD.dicts.el.modalTitle, 'Α');
	assert.strictEqual(PD.dicts.en.modalTitle, 'A');
	assert.strictEqual(PD.txt('modalTitle'), 'Α');
});

test('state.js: a malformed inline i18nEn is ignored', () => {
	const { PD } = load({ i18n: { el: { modalTitle: 'Α' } }, config: { i18nEn: 'x' } });
	assert.strictEqual(PD.dicts.el.modalTitle, 'Α');
	assert.strictEqual(PD.dicts.en.modalTitle, 'Α', 'a non-object i18nEn is skipped, not spread');
});
