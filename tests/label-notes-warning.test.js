/* 1.24.2 fix #3: cut notes on fixed-size labels need a second press.
   jsdom has no layout, so fitLabel()/labelSize() are stubbed. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

function setup(trimNotes) {
	const env = load();
	const PD = env.PD;
	PD.labelSize = () => ({ id: '50x30', w: 50, h: 30 });
	PD.fitLabel = (item) => ({ ok: true, scale: 1, opts: {}, trimmed: trimNotes && item.notes ? ['notes'] : [] });
	PD.LABEL_ACK_MIN_MS = 0;
	return env;
}

const items = [
	{ name: 'Ciproxin', notes: 'ΟΧΙ μαζί με γάλα ή γιαούρτι, 2 ώρες πριν' },
	{ name: 'Depon', notes: '' }
];

test('first press warns and names the medicine, second press of the same labels goes ahead', () => {
	const { PD } = setup(true);
	const first = PD.labelNotesNeedAck(items, 'Δοκιμή');
	assert.match(first, /Ciproxin/);
	assert.doesNotMatch(first, /Depon/);
	assert.strictEqual(PD.labelNotesNeedAck(items, 'Δοκιμή'), '');
});

test('any change to the labels warns again', () => {
	const { PD } = setup(true);
	PD.labelNotesNeedAck(items, 'Δοκιμή');
	const changed = [{ name: 'Ciproxin', notes: 'άλλη σημείωση που δεν χωράει' }];
	assert.notStrictEqual(PD.labelNotesNeedAck(changed, 'Δοκιμή'), '');
	PD.labelNotesNeedAck(items, 'Δοκιμή');
	assert.notStrictEqual(PD.labelNotesNeedAck(items, 'Γιώργος'), '', 'other patient');
});

test('no warning when nothing is cut, or with the printer-sized (auto) label', () => {
	const { PD } = setup(false);
	assert.strictEqual(PD.labelNotesNeedAck(items, 'Δοκιμή'), '');
	const env2 = setup(true);
	env2.PD.labelSize = () => ({ id: 'auto' });
	assert.strictEqual(env2.PD.labelNotesNeedAck(items, 'Δοκιμή'), '');
});

test('runLabelPrint stops before opening any window on the first press', () => {
	const { PD, w } = setup(true);
	let opened = 0;
	w.open = () => { opened++; return null; };
	PD.labelFitProblem = () => '';
	PD.runLabelPrint(items, 'Δοκιμή');
	assert.strictEqual(opened, 0);
	assert.notStrictEqual(PD.s.labelNotesAck, '', 'warning recorded');
	PD.runLabelPrint(items, 'Δοκιμή');
	assert.strictEqual(opened, 1, 'second press opens the label window');
});

test('the test label is never held back', () => {
	const { PD, w } = setup(true);
	let opened = 0;
	w.open = () => { opened++; return null; };
	PD.labelFitProblem = () => '';
	PD.runLabelPrint([PD.testLabelItem()], 'TEST', true);
	assert.strictEqual(opened, 1);
});

test('a double-click right after the warning does not print', () => {
	const { PD } = setup(true);
	PD.LABEL_ACK_MIN_MS = 800;
	assert.notStrictEqual(PD.labelNotesNeedAck(items, 'Δοκιμή'), '');
	assert.notStrictEqual(PD.labelNotesNeedAck(items, 'Δοκιμή'), '', 'second click 0 ms later still warns');
	PD.s.labelNotesAckAt -= 1000;
	assert.strictEqual(PD.labelNotesNeedAck(items, 'Δοκιμή'), '', 'a deliberate second press prints');
});

test('no patient data is kept for the warning, and it is cleared with the plan', () => {
	const { PD } = setup(true);
	PD.labelNotesNeedAck(items, 'Δοκιμή');
	assert.doesNotMatch(PD.s.labelNotesAck, /Δοκιμή|Ciproxin|γάλα/);
	PD.invalidatePendingPrintToken();
	assert.strictEqual(PD.s.labelNotesAck, '');
});
