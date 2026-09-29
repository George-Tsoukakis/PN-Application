/* PlanDose — guards for the PHP static analysis setup (tools/php-qa). TEST-ONLY.
   Runs without Composer, so a plain `npm test` catches the shortcuts that
   would make PHPStan/PHPCS pass without fixing anything:
   - no PHPStan baseline, and every ignoreErrors entry is scoped
     (identifier plus message or path) and has a reason comment above it;
   - PHPCS runs the security/DB/i18n/compatibility sniffs on the plugin and
     does not exclude plugin code;
   - every phpcs:ignore / phpcs:disable in the plugin names specific sniffs
     (never a whole standard or category) and carries a `-- reason`;
   - the tooling is dev-only: CI runs it, the zip build leaves it out. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JS_DIR } = require('./harness.js');

const QA_DIR = path.join(__dirname, 'tools', 'php-qa');
const PLUGIN_DIR = path.resolve(JS_DIR, '..', '..');
const REPO_DIR = path.resolve(__dirname, '..');

function read(p) {
	return fs.readFileSync(p, 'utf8');
}

function phpFiles(dir) {
	const out = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (entry.name !== 'node_modules') {
				out.push(...phpFiles(full));
			}
		} else if (entry.name.endsWith('.php')) {
			out.push(full);
		}
	}
	return out;
}

test('phpstan.neon: level >= 5, no baseline, every ignore scoped and explained', () => {
	const neon = read(path.join(QA_DIR, 'phpstan.neon'));
	const level = /^\s*level:\s*(\d+)/m.exec(neon);
	assert.ok(level && Number(level[1]) >= 5, 'PHPStan level must be at least 5');
	assert.ok(!/baseline/i.test(neon), 'no PHPStan baseline');
	assert.ok(/szepeviktor\/phpstan-wordpress\/extension\.neon/.test(neon), 'WordPress extension included');
	const baselines = fs.readdirSync(QA_DIR).filter((f) => /baseline/i.test(f));
	assert.deepStrictEqual(baselines, [], 'no baseline file in tools/php-qa');

	const block = neon.split(/^\tignoreErrors:\s*$/m)[1].split(/^\S/m)[0];
	const entries = block.split(/^\t\t-\s*$/m).slice(1);
	assert.ok(entries.length > 0);
	for (const entry of entries) {
		assert.ok(/^\t\t\tidentifier: \S+/m.test(entry), 'ignore without identifier:\n' + entry);
		assert.ok(/^\t\t\t(message|path): /m.test(entry), 'ignore without message/path (too broad):\n' + entry);
		assert.ok(!/^\t\t\tpaths?: .*\*/m.test(entry), 'wildcard path in ignore:\n' + entry);
	}
	/* Each entry sits under a "# reason" comment; entries sharing one
	   reason follow each other directly. */
	const lines = block.split('\n');
	lines.forEach((line, i) => {
		if (line === '\t\t-') {
			assert.ok(/^\t\t(#|\t)/.test(lines[i - 1] || ''), 'ignore without a reason comment at entry ' + i);
		}
	});
	assert.ok(/^\t\t#/.test(lines[lines.indexOf('\t\t-') - 1]), 'first ignore needs a reason comment');
});

test('phpcs.xml: security, DB, i18n and compatibility sniffs on the whole plugin', () => {
	const xml = read(path.join(QA_DIR, 'phpcs.xml'));
	for (const ref of [
		'WordPress.Security.EscapeOutput',
		'WordPress.Security.NonceVerification',
		'WordPress.Security.ValidatedSanitizedInput',
		'WordPress.DB.PreparedSQL',
		'WordPress.DB.PreparedSQLPlaceholders',
		'WordPress.DB.DirectDatabaseQuery',
		'WordPress.WP.I18n',
		'PHPCompatibilityWP',
	]) {
		assert.ok(xml.includes('<rule ref="' + ref + '"'), 'missing sniff ' + ref);
	}
	assert.ok(/<element value="plandose"\/>/.test(xml), 'text domain plandose');
	assert.ok(xml.includes('<file>../../../plandose</file>'));
	const excludes = [...xml.matchAll(/<exclude-pattern>([^<]*)<\/exclude-pattern>/g)].map((m) => m[1]);
	assert.deepStrictEqual(excludes, ['*/node_modules/*'], 'no plugin code excluded');
	assert.ok(!/<exclude name=/.test(xml), 'no sniff excluded from an enabled rule');
	assert.ok(/formatting sniffs/i.test(xml), 'phpcs.xml documents why formatting sniffs are off');
});

test('every phpcs:ignore/disable in the plugin names sniffs and gives a reason', () => {
	const bad = [];
	for (const file of phpFiles(PLUGIN_DIR)) {
		read(file).split('\n').forEach((line, i) => {
			const m = /phpcs:(ignore|disable)\b(.*)$/.exec(line);
			if (!m) {
				return;
			}
			const where = path.relative(PLUGIN_DIR, file) + ':' + (i + 1);
			const [codes, reason] = m[2].split(/\s--\s/);
			const list = (codes || '').split(',').map((s) => s.trim()).filter(Boolean);
			if (!list.length) {
				bad.push(where + ' (no sniff named)');
			}
			for (const code of list) {
				if (code.split('.').length < 3) {
					bad.push(where + ' (too broad: ' + code + ')');
				}
			}
			if (!reason || !reason.trim()) {
				bad.push(where + ' (no reason)');
			}
		});
	}
	assert.deepStrictEqual(bad, []);
});

test('php-qa is dev only: run in CI, gitignored vendor, left out of the zip', () => {
	const ci = read(path.join(REPO_DIR, '.github', 'workflows', 'ci.yml'));
	assert.ok(/^\s{2}php-qa:/m.test(ci), 'CI job php-qa');
	assert.ok(/vendor\/bin\/phpstan analyse/.test(ci) && /vendor\/bin\/phpcs/.test(ci));
	assert.ok(/needs: \[[^\]]*php-qa/.test(ci), 'build waits for php-qa');
	assert.ok(fs.existsSync(path.join(QA_DIR, 'composer.lock')), 'composer.lock committed');
	const ignore = read(path.join(QA_DIR, '.gitignore'));
	assert.ok(/^vendor\/$/m.test(ignore) && /^\.phpstan-cache\/$/m.test(ignore));
	const zip = read(path.join(REPO_DIR, 'build', 'build-zip.sh'));
	assert.ok(/PLUGIN_DIR="\$HERE\/\.\.\/plandose"/.test(zip), 'zip is built from plandose/ only');
});
