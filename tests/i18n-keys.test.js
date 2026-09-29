/* Every dictionary key the JS asks for exists in BOTH PHP dictionaries
   (Greek build_i18n / 'i18n' and English build_i18n_en in
   class-plandose-frontend.php), and the %s/%d placeholders agree between the
   JS fallback, the Greek and the English string.

   The PHP side is read statically by tools/extract-i18n.php (needs `php`).
   Keys that agents requested in ../i18n-requests/*.json but that are not in
   the PHP yet are named as such in the failure message. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const path = require('path');
const { execFileSync } = require('child_process');
const env = require('./lib/env.js');
const i18n = require('./lib/i18n.js');
const { load, closeAll, JS_DIR } = require('./harness.js');

let hasPhp = true;
try {
	execFileSync(process.env.PHP || 'php', ['-v'], { stdio: 'ignore' });
} catch (e) {
	hasPhp = false;
}
const skip = hasPhp ? false : 'php not available';

test.afterEach(closeAll);

function uses() {
	const { PD } = load();
	return i18n.jsKeyUses(JS_DIR).concat(i18n.dynamicKeys(PD));
}

function where(u) {
	return u.via ? u.via : u.file + ':' + u.line;
}

function missingReport(list, dict, requests) {
	const byKey = new Map();
	for (const u of list) {
		if (!Object.prototype.hasOwnProperty.call(dict, u.key)) {
			if (!byKey.has(u.key)) {
				byKey.set(u.key, []);
			}
			byKey.get(u.key).push(where(u));
		}
	}
	return Array.from(byKey, ([k, at]) => k + ' (' + at.join(', ') + ')' +
		(requests[k] ? ' — requested in i18n-requests/' + requests[k].file + ', not added yet' : ''));
}

const REQUESTS = i18n.requestedKeys(path.join(env.TESTS_DIR, '..', 'i18n-requests'));

test('the extractor finds both dictionaries with the same keys', { skip }, () => {
	const d = i18n.phpDictionaries(env.pluginDir());
	const el = Object.keys(d.el);
	const en = Object.keys(d.en);
	assert.ok(el.length > 100 && en.length > 100, 'dictionaries found: ' + el.length + ' / ' + en.length);
	assert.deepStrictEqual(el.filter((k) => !d.en[k]), [], 'Greek keys missing from English');
	assert.deepStrictEqual(en.filter((k) => !d.el[k]), [], 'English keys missing from Greek');
	const unreadable = el.filter((k) => d.el[k].value === null).concat(en.filter((k) => d.en[k].value === null).map((k) => 'en:' + k));
	assert.deepStrictEqual(unreadable, [], 'values the extractor could not evaluate');
});

test('the JS key scanner sees the known call styles', () => {
	const u = i18n.jsKeyUses(JS_DIR);
	assert.ok(u.length > 150, 'found ' + u.length + ' uses');
	assert.ok(u.some((x) => x.file === 'rx-import.js'), 't() wrapper in rx-import.js');
	assert.ok(u.some((x) => x.key === 'startDate'), 'PD.txt(\'startDate\', ...)');
});

test('every key the JS asks for is in the Greek dictionary', { skip }, () => {
	const d = i18n.phpDictionaries(env.pluginDir());
	const missing = missingReport(uses().filter((u) => u.kind === 'txt'), d.el, REQUESTS);
	if (missing.length) {
		assert.fail('missing in Greek:\n  ' + missing.join('\n  '));
	}
});

test('every key the JS asks for is in the English dictionary', { skip }, () => {
	const d = i18n.phpDictionaries(env.pluginDir());
	const missing = missingReport(uses().filter((u) => u.kind === 'txt'), d.en, REQUESTS);
	if (missing.length) {
		assert.fail('missing in English:\n  ' + missing.join('\n  '));
	}
});

/* pro-labels.js labelWord(key, el, en) has built-in wording for both
   languages, so a key not in the dictionaries is not a bug — reported only. */
test('label words (labelWord) in the dictionaries', { skip }, (t) => {
	const d = i18n.phpDictionaries(env.pluginDir());
	const lw = uses().filter((u) => u.kind === 'labelWord');
	const missing = missingReport(lw, d.el, REQUESTS).concat(missingReport(lw, d.en, REQUESTS).map((m) => 'en: ' + m));
	if (missing.length) {
		t.todo('built-in wording used for: ' + missing.join('; '));
	}
});

test('%s / %d placeholders agree between JS fallback, Greek and English', { skip }, () => {
	const d = i18n.phpDictionaries(env.pluginDir());
	const bad = [];
	const seen = new Set();
	const sig = (s) => JSON.stringify(i18n.placeholders(s));
	for (const u of uses()) {
		const el = d.el[u.key];
		const en = d.en[u.key];
		const id = u.key + '|' + u.fallback;
		if (seen.has(id)) {
			continue;
		}
		seen.add(id);
		/* sprintf() on the server: the page gets the finished sentence. */
		if ((el && el.formatted) || (en && en.formatted)) {
			continue;
		}
		const ref = el ? el.value : (typeof u.fallback === 'string' ? u.fallback : null);
		const cmp = [['JS fallback', u.fallback], ['el', el && el.value], ['en', en && en.value], ['JS en fallback', u.fallbackEn]]
			.filter(([, v]) => typeof v === 'string');
		if (ref === null) {
			continue;
		}
		for (const [name, v] of cmp) {
			if (sig(v) !== sig(ref)) {
				bad.push(u.key + ' (' + where(u) + '): ' + name + ' ' + sig(v) + ' vs ' + (el ? 'el' : 'JS fallback') + ' ' + sig(ref));
			}
		}
	}
	/* The dictionaries among themselves, for keys the JS reaches dynamically. */
	for (const k of Object.keys(d.el)) {
		if (d.en[k] && !d.el[k].formatted && !d.en[k].formatted && sig(d.el[k].value) !== sig(d.en[k].value)) {
			bad.push(k + ': el ' + sig(d.el[k].value) + ' vs en ' + sig(d.en[k].value));
		}
	}
	assert.deepStrictEqual(Array.from(new Set(bad)), [], 'placeholder mismatch:\n  ' + Array.from(new Set(bad)).join('\n  '));
});

test('pending i18n requests are well-formed (el + en, same placeholders)', () => {
	const bad = [];
	for (const [k, v] of Object.entries(REQUESTS)) {
		if (k.startsWith('__invalid__')) {
			bad.push(v.file + ': invalid JSON (' + v.error + ')');
			continue;
		}
		if (typeof v.el !== 'string' || typeof v.en !== 'string') {
			bad.push(k + ' (' + v.file + '): needs both "el" and "en" strings');
			continue;
		}
		if (JSON.stringify(i18n.placeholders(v.el)) !== JSON.stringify(i18n.placeholders(v.en))) {
			bad.push(k + ' (' + v.file + '): placeholders differ between el and en');
		}
	}
	assert.deepStrictEqual(bad, [], bad.join('\n'));
});

test('harness can load the real dictionaries', { skip }, () => {
	const { PD } = load({ i18n: 'real' });
	assert.strictEqual(PD.txt('startDate', 'x'), i18n.phpDictionaries(env.pluginDir()).el.startDate.value);
});
