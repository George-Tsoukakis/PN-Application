/* The plugin's real PHP dictionaries (via tools/extract-i18n.php, no
   WordPress) and the i18n keys the JS asks for. TEST-ONLY. */
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const cache = {};

/* { el: { key: { value, formatted } }, en: { ... } } */
function phpDictionaries(pluginDir, maxDays) {
	const id = pluginDir + '|' + (maxDays || 90);
	if (!cache[id]) {
		const out = execFileSync(process.env.PHP || 'php', [
			path.join(__dirname, '..', 'tools', 'extract-i18n.php'), pluginDir, '--max-days=' + (maxDays || 90)
		], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
		cache[id] = JSON.parse(out);
	}
	return cache[id];
}

/* Plain { key: string } maps, as PlandoseConfig.i18n / i18nEn carry them. */
function flatDictionaries(pluginDir, maxDays) {
	const d = phpDictionaries(pluginDir, maxDays);
	const flat = (m) => Object.fromEntries(Object.entries(m).map(([k, v]) => [k, v.value == null ? '' : v.value]));
	return { el: flat(d.el), en: flat(d.en) };
}

const LIT = String.raw`'((?:[^'\\\n]|\\.)*)'|"((?:[^"\\\n]|\\.)*)"`;

function unescapeJs(s) {
	return s.replace(/\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/g, (m, e) => {
		if (e[0] === 'u' || e[0] === 'x') {
			return String.fromCharCode(parseInt(e.slice(1), 16));
		}
		return { n: '\n', t: '\t', r: '\r' }[e] || e;
	});
}

function lineOf(src, index) {
	return src.slice(0, index).split('\n').length;
}

/* Every literal key the JS passes to the dictionary:
   [{ key, fallback, fallbackEn, file, line, kind }] where kind is
   'txt' (PD.txt / a local t() wrapper / the start-date chip) or
   'labelWord' (pro-labels.js: falls back to its own per-language wording). */
function jsKeyUses(jsDir) {
	const uses = [];
	const files = fs.readdirSync(jsDir).filter((f) => f.endsWith('.js') && f !== 'plandose-admin.js');
	for (const f of files) {
		const src = fs.readFileSync(path.join(jsDir, f), 'utf8');
		const hasT = /function\s+t\s*\(\s*key\b[^)]*\)\s*\{\s*return\s+PD\.txt\(/.test(src);
		const patterns = [
			{ kind: 'txt', re: new RegExp(String.raw`\bPD\.txt\(\s*(?:${LIT})\s*(?:,\s*(?:${LIT})\s*)?[,)]`, 'g') },
			{ kind: 'labelWord', re: new RegExp(String.raw`\blabelWord\(\s*(?:${LIT})\s*,\s*(?:${LIT})\s*,\s*(?:${LIT})`, 'g') },
			{ kind: 'txt', re: new RegExp(String.raw`\bchip\(\s*[^,()]+,\s*(?:${LIT})\s*,\s*(?:${LIT})`, 'g') }
		];
		if (hasT) {
			patterns.push({ kind: 'txt', re: new RegExp(String.raw`(?:^|[^\w.$])t\(\s*(?:${LIT})\s*(?:,\s*(?:${LIT})\s*)?[,)]`, 'g') });
		}
		for (const { kind, re } of patterns) {
			let m;
			while ((m = re.exec(src))) {
				const g = m.slice(1).map((x) => (x === undefined ? undefined : unescapeJs(x)));
				const key = g[0] !== undefined ? g[0] : g[1];
				const fb = g[2] !== undefined ? g[2] : g[3];
				const fbEn = kind === 'labelWord' ? (g[4] !== undefined ? g[4] : g[5]) : undefined;
				uses.push({ key, fallback: fb, fallbackEn: fbEn, file: f, line: lineOf(src, m.index), kind });
			}
		}
	}
	return uses;
}

/* Keys asked for through data tables at runtime (unit and frequency names). */
function dynamicKeys(PD) {
	const keys = [];
	(PD.unitOptions || []).forEach((u) => {
		if (u && u.key) {
			keys.push({ key: u.key, fallback: u.val, file: 'state.js', line: 0, kind: 'txt', via: 'PD.unitOptions' });
		}
	});
	Object.keys(PD.freqLabelKey || {}).forEach((f) => {
		const k = PD.freqLabelKey[f];
		if (k) {
			keys.push({ key: k, fallback: undefined, file: 'state.js', line: 0, kind: 'txt', via: 'PD.freqLabelKey' });
		}
	});
	return keys;
}

/* Placeholder signature: '%s' / '%d' / '%1$s' normalised to the argument
   slots PD.format() / sprintf() consume, e.g. ['1s', '2d']. */
function placeholders(s) {
	if (typeof s !== 'string') {
		return null;
	}
	const out = [];
	let next = 0;
	s.replace(/%%|%(\d+\$)?([sd])/g, (m, pos, type) => {
		if (m === '%%') {
			return m;
		}
		const idx = pos ? parseInt(pos, 10) : ++next;
		out.push(idx + type);
		return m;
	});
	return Array.from(new Set(out)).sort();
}

/* Keys other agents asked the coordinator to add (i18n-requests/*.json). */
function requestedKeys(dir) {
	const out = {};
	if (!dir || !fs.existsSync(dir)) {
		return out;
	}
	for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.json'))) {
		try {
			const j = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
			for (const k of Object.keys(j)) {
				out[k] = Object.assign({ file: f }, j[k]);
			}
		} catch (e) {
			out['__invalid__' + f] = { file: f, error: e.message };
		}
	}
	return out;
}

module.exports = { phpDictionaries, flatDictionaries, jsKeyUses, dynamicKeys, placeholders, requestedKeys };
