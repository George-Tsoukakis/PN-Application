/* 1.24.2 fix #1: labels of the plan just printed keep «Έναρξη» / «1η δόση»
   after «Νέος ασθενής» cleared the form. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, isoAhead, closeAll } = require('./harness');
test.afterEach(closeAll);

function drug(extra) {
	return Object.assign({
		name: 'Amoxil 500mg', doseAmount: 1, doseUnit: 'Δισκίο(α)', freq: '12h',
		dailyTime: 'morning', customMode: 'days', customIntervalDays: '7',
		customWeekday: 1, days: '7', notes: ''
	}, extra || {});
}

function printAndReset(PD) {
	/* What doPrintSafely() leaves behind, then «Νέος ασθενής». */
	PD.overlay.hidden = false;
	PD.beginDayPass();
	PD.resetForNextPatient('manual');
}

test('start tomorrow: label keeps «Έναρξη» after Νέος ασθενής', () => {
	const { PD } = load();
	const item = drug();
	PD.s.items = [item];
	PD.s.startDate = isoAhead(1);
	PD.beginDayPass();
	const before = PD.labelStart(item);
	assert.match(before, /Έναρξη/);

	printAndReset(PD);
	assert.strictEqual(PD.s.startDate, '', 'form was cleared');
	assert.strictEqual(PD.s.items.length, 0);
	assert.strictEqual(PD.labelItems()[0], item, 'printed plan offered for labels');
	assert.strictEqual(PD.labelStart(PD.labelItems()[0]), before);
	/* The whole label, as the preview and the print build it. */
	assert.strictEqual(PD.labelParts(PD.labelItems()[0], {}).start, before);
});

test('first dose Βράδυ today: label keeps «1η δόση» after Νέος ασθενής', () => {
	const { PD } = load();
	const item = drug();
	PD.s.items = [item];
	PD.s.startDate = '';
	PD.s.firstSlot = 'evening';
	PD.beginDayPass();
	const before = PD.labelStart(item);
	assert.match(before, /1η\sδόση/);

	printAndReset(PD);
	assert.strictEqual(PD.s.firstSlot, 'morning', 'form was cleared');
	assert.strictEqual(PD.labelStart(PD.labelItems()[0]), before);
});

test('the new plan is not affected by the printed plan context', () => {
	const { PD } = load();
	const item = drug();
	PD.s.items = [item];
	PD.s.startDate = isoAhead(2);
	PD.beginDayPass();
	printAndReset(PD);

	/* Labels of the old plan use its context… */
	assert.match(PD.labelStart(PD.labelItems()[0]), /Έναρξη/);
	/* …and afterwards the new, empty plan still starts today. */
	assert.strictEqual(PD.s.startDate, '');
	assert.strictEqual(PD.s.dayPassFrozen, false);
	PD.beginDayPass();
	assert.strictEqual(PD.startsLater(), false);
	/* A different medicine (e.g. the test label) gets no start line. */
	assert.strictEqual(PD.labelStart(drug({ name: 'Other' })), '');
});

test('context is restored even if the callback throws', () => {
	const { PD } = load();
	PD.s.lastPrintedPlan = { startDate: isoAhead(3), firstSlot: 'noon', dayZero: 1, today: 0 };
	PD.s.startDate = '';
	PD.s.firstSlot = 'morning';
	PD.beginDayPass();
	const zero = PD.s.planDayZero;
	assert.throws(() => PD.withPrintedPlan(() => { throw new Error('x'); }));
	assert.strictEqual(PD.s.startDate, '');
	assert.strictEqual(PD.s.firstSlot, 'morning');
	assert.strictEqual(PD.s.planDayZero, zero);
	assert.strictEqual(PD.s.dayPassFrozen, false);
});

test('editing the new plan drops the printed plan context', () => {
	const { PD } = load();
	const item = drug();
	PD.s.items = [item];
	PD.s.startDate = isoAhead(1);
	printAndReset(PD);
	assert.ok(PD.s.lastPrintedPlan);
	PD.invalidatePendingPrintToken();
	assert.strictEqual(PD.s.lastPrintedPlan, null);
	assert.strictEqual(PD.s.lastPrintedItems, null);
});
