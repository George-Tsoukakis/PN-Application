/* Runs each script (node) in turn, prints its output, and exits 1 if any
   of them failed — all are run even after a failure. TEST-ONLY. */
'use strict';
const { spawnSync } = require('child_process');
const path = require('path');

const scripts = process.argv.slice(2);
if (!scripts.length) {
	console.error('Usage: node tools/run-scripts.js <script> [<script> ...]');
	process.exit(1);
}
const failed = [];
for (const s of scripts) {
	console.log('\n=== ' + s);
	const r = spawnSync(process.execPath, [s], { stdio: 'inherit', cwd: path.join(__dirname, '..') });
	if (r.status !== 0) {
		failed.push(s + ' (exit ' + (r.status === null ? r.signal : r.status) + ')');
	}
}
console.log('\n' + (failed.length ? 'FAILED: ' + failed.join(', ') : 'ALL SCRIPTS OK'));
process.exit(failed.length ? 1 : 0);
