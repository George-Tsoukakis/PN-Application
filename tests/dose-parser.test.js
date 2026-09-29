/* 1.24.2 fix #2: "11/2" is refused (it read as 5,5 tablets). Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

const TAB = 'tablet';

test('improper fraction without whole part (≥10 numerator) is refused as "mixed"', () => {
	const { PD } = load();
	for (const raw of ['11/2', '21/2', '13/4', '11 / 2', '19/2']) {
		const r = PD.checkDoseAmount(raw, TAB);
		assert.strictEqual(r.error, 'mixed', raw);
		assert.ok(Number.isNaN(r.value), raw);
	}
});

test('message offers the mixed reading', () => {
	const { PD } = load();
	const msg = PD.doseAmountMessage('mixed', '11/2', 'X', TAB);
	assert.match(msg, /«11\/2»/);
	assert.match(msg, /5,5/);
	assert.match(msg, /1 1\/2/);
	const msg2 = PD.doseAmountMessage('mixed', '13/4', 'X', TAB);
	assert.match(msg2, /1 3\/4/);
	assert.match(msg2, /3,25/);
});

test('no nonsense suggestion when the split is not a proper fraction', () => {
	const { PD } = load();
	for (const [raw, lit] of [['10/2', '5'], ['15/4', '3,75'], ['12/2', '6']]) {
		assert.strictEqual(PD.checkDoseAmount(raw, TAB).error, 'mixed', raw);
		const msg = PD.doseAmountMessage('mixed', raw, 'X', TAB);
		assert.ok(msg.includes('«' + raw + '»') && msg.includes(' ' + lit + '.'), msg);
		assert.doesNotMatch(msg, /Αν εννοείτε/, raw);
	}
});

test('everything that was valid before is still valid', () => {
	const { PD } = load();
	const ok = { '1': 1, '1,5': 1.5, '1.5': 1.5, '0,25': 0.25, '½': 0.5, '1½': 1.5, '1 ½': 1.5,
		'1/2': 0.5, '3/4': 0.75, '3/2': 1.5, '1 1/2': 1.5, '2 1/4': 2.25, '9/4': 2.25 };
	for (const [raw, val] of Object.entries(ok)) {
		const r = PD.checkDoseAmount(raw, TAB);
		assert.strictEqual(r.error, '', raw);
		assert.strictEqual(r.value, val, raw);
	}
});

test('other refusals unchanged', () => {
	const { PD } = load();
	const bad = { '': 'empty', '1.000': 'thousands', '1/3': 'decimals', '0': 'zero', '-1': 'zero',
		'1/0': 'format', 'abc': 'format', '25': 'max' };
	for (const [raw, err] of Object.entries(bad)) {
		assert.strictEqual(PD.checkDoseAmount(raw, TAB).error, err, raw);
	}
});

test('already-saved numeric doses are unaffected', () => {
	const { PD } = load();
	assert.strictEqual(PD.checkDoseAmount(5.5, TAB).value, 5.5);
});
