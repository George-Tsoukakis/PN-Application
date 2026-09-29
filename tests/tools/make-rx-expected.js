/* Compares what the current prescription parser reads from every
   rx-samples/*.txt with rx-samples/expected.json (the reviewed truth that
   rx-import.test.js checks against).

     node tools/make-rx-expected.js           print the differences; exit 1 if any
     node tools/make-rx-expected.js --write   rewrite expected.json from the parser

   Review every difference by hand before using --write. TEST-ONLY. */
'use strict';
const fs = require('fs');
const path = require('path');
const { load, closeAll } = require('../harness.js');

const SAMPLES = path.join(__dirname, '..', 'rx-samples');
const EXPECTED = path.join(SAMPLES, 'expected.json');
const write = process.argv.includes('--write');

/* Frequency column, as rx-import.test.js writes it: a weekly item is a
   weekday to choose («weekday?»), a monthly one a day of the month
   («monthday?»), other custom ones «every<N>». Keep the two in step. */
function freqCol(item) {
	if (item.freq !== 'custom') {
		return item.freq;
	}
	if (item.customMode === 'weekday') {
		return 'weekday' + (item.customWeekday == null ? '?' : item.customWeekday);
	}
	if (item.customMode === 'monthday') {
		return 'monthday' + (item.customMonthDay == null ? '?' : item.customMonthDay);
	}
	return 'every' + item.customIntervalDays;
}

/* One row per medicine: [name, dose, unit, frequency, days, warnings]. */
function summarize(item) {
	return JSON.parse(JSON.stringify([
		item.name,
		item.doseAmount,
		item.doseUnit,
		freqCol(item),
		+item.days,
		item.warnings.join(',')
	]));
}

function readAll() {
	const { PD } = load();
	const out = {};
	const files = fs.readdirSync(SAMPLES).filter((f) => f.endsWith('.txt')).sort();
	for (const f of files) {
		const r = PD.parsePrescription(fs.readFileSync(path.join(SAMPLES, f), 'utf8'));
		out[f] = { patient: r.patient, items: r.items.map(summarize) };
	}
	closeAll();
	return out;
}

function diff(expected, actual) {
	const lines = [];
	const names = Array.from(new Set(Object.keys(expected).concat(Object.keys(actual)))).sort();
	for (const f of names) {
		const e = expected[f];
		const a = actual[f];
		if (!e) {
			lines.push('+ ' + f + ': new sample, not in expected.json');
			continue;
		}
		if (!a) {
			lines.push('- ' + f + ': in expected.json, sample file missing');
			continue;
		}
		const head = [];
		if (e.patient !== a.patient) {
			head.push('  patient: ' + JSON.stringify(e.patient) + ' → ' + JSON.stringify(a.patient));
		}
		const n = Math.max(e.items.length, a.items.length);
		for (let i = 0; i < n; i++) {
			const ei = JSON.stringify(e.items[i]);
			const ai = JSON.stringify(a.items[i]);
			if (ei !== ai) {
				head.push('  item ' + (i + 1) + ':\n    - ' + (ei || '(none)') + '\n    + ' + (ai || '(none)'));
			}
		}
		if (head.length) {
			lines.push(f + '\n' + head.join('\n'));
		}
	}
	return lines;
}

const actual = readAll();

if (write) {
	fs.writeFileSync(EXPECTED, JSON.stringify(actual, null, 1));
	console.log('wrote ' + EXPECTED);
	process.exit(0);
}

const expected = fs.existsSync(EXPECTED) ? JSON.parse(fs.readFileSync(EXPECTED, 'utf8')) : {};
const d = diff(expected, actual);
if (!d.length) {
	console.log('expected.json matches the parser (' + Object.keys(actual).length + ' samples).');
	process.exit(0);
}
console.log(d.join('\n'));
console.log('\n' + d.length + ' sample(s) differ. Review, then run with --write to update expected.json.');
process.exit(1);
