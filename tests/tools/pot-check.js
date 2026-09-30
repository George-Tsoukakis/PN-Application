#!/usr/bin/env node
/* POT freshness check. TEST/DEV tool, not shipped.

   Regenerates the POT from the plugin sources (tools/make-pot.php) into
   build/plandose.pot.check and compares it with the committed
   plandose/languages/plandose.pot. The volatile header parts are ignored:
   the POT-Creation-Date and PO-Revision-Date lines and the year in the
   «# Copyright (C) YYYY» line (both come from the clock).

   Exit 0: identical. Exit 1: they differ (a unified diff is printed;
   regenerate with `npm run pot:update`). Exit 2: the generator failed.

   Usage: node tools/pot-check.js [--update]
     --update  write the regenerated POT over plandose/languages/plandose.pot
     PD_POT_FILE=<file>  compare against another POT (self-test of this tool) */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..', '..');
const pluginDir = path.join(root, 'plandose');
const committed = process.env.PD_POT_FILE ? path.resolve(process.env.PD_POT_FILE) : path.join(pluginDir, 'languages', 'plandose.pot');
const generated = path.join(root, 'build', 'plandose.pot.check');
const update = process.argv.includes('--update');

const gen = spawnSync('php', [path.join(__dirname, 'make-pot.php'), pluginDir, generated], { encoding: 'utf8' });
if (gen.error || gen.status !== 0) {
	process.stderr.write((gen.stderr || '') + (gen.error ? String(gen.error) + '\n' : ''));
	console.error('pot:check: make-pot.php failed');
	process.exit(2);
}
if (gen.stderr) process.stderr.write(gen.stderr);

if (update) {
	fs.copyFileSync(generated, committed);
	console.log('pot:update: wrote ' + path.relative(root, committed) + ' (' + gen.stdout.trim() + ')');
	process.exit(0);
}

function normalise(text) {
	return text
		.replace(/\r\n/g, '\n')
		.replace(/^# Copyright \(C\) \d{4} /m, '# Copyright (C) YEAR ')
		.replace(/^"POT-Creation-Date: [^"]*"$/m, '"POT-Creation-Date: (ignored)\\n"')
		.replace(/^"PO-Revision-Date: [^"]*"$/m, '"PO-Revision-Date: (ignored)\\n"');
}

if (!fs.existsSync(committed)) {
	console.error('pot:check: missing ' + path.relative(root, committed) + ' (create it with `npm run pot:update`)');
	process.exit(1);
}
const a = normalise(fs.readFileSync(committed, 'utf8'));
const b = normalise(fs.readFileSync(generated, 'utf8'));
if (a === b) {
	console.log('pot:check: plandose/languages/plandose.pot is up to date (' + gen.stdout.trim() + ')');
	process.exit(0);
}

/* Readable diff: `diff -u` on the normalised texts when available. */
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pd-pot-'));
const fa = path.join(tmp, 'committed.pot');
const fb = path.join(tmp, 'generated.pot');
fs.writeFileSync(fa, a);
fs.writeFileSync(fb, b);
const d = spawnSync('diff', ['-u', '--label', 'plandose/languages/plandose.pot (committed)', '--label', 'plandose.pot (from the sources)', fa, fb], { encoding: 'utf8' });
let out = d.error ? '' : d.stdout;
if (!out) {
	/* No diff(1): report the first differing line. */
	const la = a.split('\n');
	const lb = b.split('\n');
	let i = 0;
	while (i < la.length && i < lb.length && la[i] === lb[i]) i++;
	out = 'first difference at line ' + (i + 1) + ':\n- ' + (la[i] ?? '(end of file)') + '\n+ ' + (lb[i] ?? '(end of file)') + '\n';
}
fs.rmSync(tmp, { recursive: true, force: true });
const lines = out.split('\n');
const MAX = 400;
process.stdout.write(lines.slice(0, MAX).join('\n') + (lines.length > MAX ? '\n… (' + (lines.length - MAX) + ' more diff lines)\n' : '\n'));
console.error('pot:check: plandose/languages/plandose.pot is out of date with the sources.\n' +
	'  Regenerate it: cd tests && npm run pot:update   (then review and commit the .pot)');
process.exit(1);
