/* jsdom harness for the PlanDose front-end modules. TEST-ONLY — not shipped.

   load(opts)       the tool modules, in the plugin's real enqueue order
                    (class-plandose-frontend.php: state → api → validation →
                    preview → medicine-form → print-styles → calendar-qr → print →
                    [pro-labels, Pro only] → rx-text → rx-lines → rx-parse →
                    rx-review → rx-import → modal → app).
   loadLoader(opts) plandose-loader.js on a page, the modules fetched by the
                    loader itself through <script src> (as for a pharmacist).
   loadGuest(opts)  plandose-guest.js on a page (visitor who may not use it).

   opts.pro        false → Free account (no pro-labels.js, isPro '').
   opts.i18n       'real' → the plugin's own PHP dictionaries (Greek, and the
                   English one for Pro, as the server sends them), or an
                   object { el, en } of plain key → string maps.
   opts.config     extra PlandoseConfig fields.
   opts.fetch      window.fetch replacement (loadLoader: default answers
                   every request with a failed JSON reply).
   opts.skip       module file names not to load.
   opts.serve      loadLoader: { absolute URL: body } served as is (e.g. the
                   dictionary scripts named in opts.loader.i18n).

   The plugin is found through PLANDOSE_JS or next to tests/ (lib/env.js). */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM, ResourceLoader, VirtualConsole } = require('jsdom');
const env = require('./lib/env.js');

const JS_DIR = env.findJsDir();
if (!JS_DIR) {
	throw new Error('PlanDose assets/js not found. Set PLANDOSE_JS=/path/to/plandose/assets/js');
}

const ORDER = ['state.js', 'api.js', 'validation.js', 'preview.js', 'medicine-form.js',
	'print-styles.js', 'calendar-qr.js', 'print.js', 'pro-labels.js',
	'rx-text.js', 'rx-lines.js', 'rx-parse.js', 'rx-review.js', 'rx-import.js', 'modal.js', 'app.js'];

const ORIGIN = 'https://example.test';

const PAGE = '<!doctype html><html><head></head><body>' +
	'<button id="plandose-trigger"></button>' +
	'<div id="plandose-overlay"><div id="plandose-modal"><div id="plandose-modal-head"><h2></h2><p></p></div>' +
	'<button id="plandose-close"></button><div id="plandose-app"></div></div></div>' +
	'</body></html>';

const OPEN = [];
function closeAll() {
	while (OPEN.length) {
		try { OPEN.pop().close(); } catch (e) { /* ignore */ }
	}
}

function modules(opts) {
	const skip = new Set(opts.skip || []);
	return ORDER.filter((f) => {
		if (skip.has(f) || (f === 'pro-labels.js' && opts.pro === false)) {
			return false;
		}
		return fs.existsSync(path.join(JS_DIR, f)); /* e.g. rx-import.js in an older release */
	});
}

function dictionaries(opts) {
	if (opts.i18n === 'real') {
		const d = require('./lib/i18n.js').flatDictionaries(env.pluginDir(), (opts.config && opts.config.maxDays) || 90);
		return { i18n: d.el, i18nEn: opts.pro === false ? {} : d.en };
	}
	if (opts.i18n && typeof opts.i18n === 'object') {
		return { i18n: opts.i18n.el || {}, i18nEn: opts.i18n.en || {} };
	}
	return { i18n: {}, i18nEn: {} };
}

function config(opts) {
	return Object.assign({
		isAllowed: true,
		isPro: opts.pro === false ? '' : '1',
		ajaxUrl: ORIGIN + '/wp-admin/admin-ajax.php',
		nonce: 'n',
		maxDays: '90',
		maxFreeReprints: '2'
	}, dictionaries(opts), opts.config || {});
}

function load(opts) {
	opts = opts || {};
	const dom = new JSDOM(PAGE, { runScripts: 'outside-only', url: ORIGIN + '/', pretendToBeVisual: true });
	const w = dom.window;
	OPEN.push(w);
	w.PlandoseConfig = config(opts);
	if (opts.fetch) {
		w.fetch = opts.fetch;
	}
	w.print = function () {};
	for (const f of modules(opts)) {
		w.eval(fs.readFileSync(path.join(JS_DIR, f), 'utf8') + '\n//# sourceURL=' + f);
	}
	const PD = w.__PlandoseNS;
	if (!PD) {
		throw new Error('namespace not created');
	}
	return { dom, w, PD };
}

/* Serves ORIGIN/js/<file> from JS_DIR, a stub stylesheet for ORIGIN/css/,
   and records every URL asked for. */
class PluginResources extends ResourceLoader {
	constructor(requested, fail, serve) {
		super();
		this.requested = requested;
		this.fail = fail || (() => false);
		this.serve = serve || {};
	}
	fetch(url, options) {
		this.requested.push(url);
		const u = new URL(url);
		if (this.fail(url)) {
			return Promise.reject(new Error('blocked by test: ' + url));
		}
		if (Object.prototype.hasOwnProperty.call(this.serve, url)) {
			return Promise.resolve(Buffer.from(this.serve[url]));
		}
		if (u.origin === ORIGIN && u.pathname.startsWith('/js/')) {
			const file = path.join(JS_DIR, path.basename(u.pathname));
			return fs.existsSync(file) ? Promise.resolve(fs.readFileSync(file)) : Promise.reject(new Error('404 ' + url));
		}
		if (u.origin === ORIGIN && u.pathname.startsWith('/css/')) {
			return Promise.resolve(Buffer.from('/* test */'));
		}
		return super.fetch(url, options);
	}
}

/* opts as load(), plus opts.failUrl(url) → true to make that request fail.
   Resolves once the loader script has run (the modules load on demand). */
function loadLoader(opts) {
	opts = opts || {};
	const requested = [];
	const vc = new VirtualConsole();
	vc.sendTo(console, { omitJSDOMErrors: true });
	const html = PAGE.replace('</body>', '<script src="' + ORIGIN + '/js/plandose-loader.js"></script></body>');
	const dom = new JSDOM(html, {
		runScripts: 'dangerously',
		resources: new PluginResources(requested, opts.failUrl, opts.serve),
		url: ORIGIN + '/',
		pretendToBeVisual: true,
		virtualConsole: vc,
		beforeParse(w) {
			w.PlandoseConfig = config(opts);
			w.PlandoseLoader = Object.assign({
				style: ORIGIN + '/css/plandose.css',
				scripts: modules(opts).map((f) => ORIGIN + '/js/' + f),
				loading: 'Φόρτωση…'
			}, opts.loader || {});
			w.fetch = opts.fetch || function () {
				return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: false, data: {} }), text: () => Promise.resolve('{"success":false}') });
			};
			w.print = function () {};
		}
	});
	OPEN.push(dom.window);
	return new Promise((resolve) => {
		dom.window.addEventListener('load', () => resolve({ dom, w: dom.window, requested }));
	});
}

function loadGuest(opts) {
	opts = opts || {};
	const dom = new JSDOM(PAGE, { runScripts: 'outside-only', url: ORIGIN + '/', pretendToBeVisual: true });
	const w = dom.window;
	OPEN.push(w);
	w.eval(fs.readFileSync(path.join(JS_DIR, 'plandose-guest.js'), 'utf8') + '\n//# sourceURL=plandose-guest.js');
	return { dom, w };
}

function iso(d) {
	const p = (n) => (n < 10 ? '0' : '') + n;
	return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

function isoAhead(days) {
	const n = new Date();
	return iso(new Date(n.getFullYear(), n.getMonth(), n.getDate() + days));
}

module.exports = { load, loadLoader, loadGuest, iso, isoAhead, closeAll, JS_DIR, ORDER };
