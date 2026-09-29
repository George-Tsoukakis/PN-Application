/* 1.27.0 round 2: a monthly item's long frequency on a Pro label is
   printed in full or the label is refused — never cut. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

function monthly(day) {
	return {
		name: 'PROLIA INJ.SOL 60MG/1ML', doseAmount: 1, doseUnit: 'injection', freq: 'custom',
		dailyTime: 'morning', customMode: 'monthday', customMonthDay: day, customWeekday: null,
		customIntervalDays: '', days: '60', notes: ''
	};
}

test('the label carries getFreqLabel() of a monthly item in full', (t) => {
	const { w, PD } = load();
	if (typeof PD.isMonthDayCustom !== 'function') {
		t.skip('no monthly mode in this build');
		return;
	}
	const item = monthly(31);
	const freq = PD.getFreqLabel(item);
	assert.ok(freq.length > 20, freq);
	assert.ok(PD.labelParts(item).schedule.indexOf(freq) === 0, 'schedule starts with the full frequency');
	PD.s.header = { name: 'Φαρμακείο', phone_1: '210' };
	PD.labelSize = () => ({ id: 'auto' });
	const doc = new w.DOMParser().parseFromString(PD.buildLabelPrintDocument([item], ''), 'text/html');
	assert.ok(doc.querySelector('.pd-lbl-when').textContent.indexOf(freq) === 0);
	assert.doesNotMatch(doc.querySelector('.pd-lbl-when').textContent, /…/);
});

test('fixed size: a frequency that does not fit refuses the label instead of cutting it', (t) => {
	const { w, PD } = load();
	if (typeof PD.isMonthDayCustom !== 'function') {
		t.skip('no monthly mode in this build');
		return;
	}
	const item = monthly(31);
	/* Fake measure: only the dosage row counts, 1 px per character, box 30 px. */
	const host = {
		html: '',
		set innerHTML(v) { this.html = v; },
		querySelector() {
			const doc = new w.DOMParser().parseFromString(host.html, 'text/html');
			const dose = doc.querySelector('.pd-lbl-dose .pd-lbl-val');
			return { clientHeight: 30, style: {}, offsetHeight: dose ? dose.textContent.length : 0 };
		}
	};
	PD.labelMeasureHost = () => host;
	PD.labelSize = () => ({ id: 'dymo-11354', w: 57, h: 32 });
	assert.strictEqual(PD.fitLabel(item, '', PD.labelSize()).ok, false);
	assert.strictEqual(PD.labelFitProblem([item], ''), item.name);
});

test('weekday unset (null) or a bad month day never reaches a label', (t) => {
	const { PD } = load();
	if (typeof PD.isMonthDayCustom !== 'function') {
		t.skip('no monthly mode in this build');
		return;
	}
	const bad = monthly(null);
	PD.s.items = [bad];
	PD.goToStep = () => {};
	assert.ok(PD.planItemInvalid(bad) || PD.labelPlanProblem([bad]), 'refused before printing');
});
