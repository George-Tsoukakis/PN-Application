/* 1.26.1: accessibility — background inert while the popup is open, focus
   rescued after step changes / deletes, Escape bound once. Run: node --test */
'use strict';
const fs = require('fs');
const path = require('path');
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

const JS_DIR = process.env.PLANDOSE_JS || path.join(__dirname, '..', 'plandose', 'assets', 'js');

function drug(name) {
	return {
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: '12h',
		dailyTime: 'morning', customMode: 'days', customIntervalDays: '7',
		customWeekday: 1, days: '7', notes: ''
	};
}

function open(w, PD) {
	PD.refreshNonce = function () { return new w.Promise(function () {}); };
	PD.startNonceRefresh = function () {};
	PD.loadHeader = function () {};
	PD.openModal();
}

test('open makes the page behind inert, close restores exactly that', () => {
	const { w, PD } = load();
	const doc = w.document;
	const page = doc.createElement('main');
	const hiddenAlready = doc.createElement('div');
	hiddenAlready.setAttribute('aria-hidden', 'true');
	doc.body.insertBefore(page, doc.body.firstChild);
	doc.body.appendChild(hiddenAlready);

	open(w, PD);
	assert.ok(page.hasAttribute('inert'));
	assert.strictEqual(page.getAttribute('aria-hidden'), 'true');
	assert.ok(PD.trigger.hasAttribute('inert'));
	assert.ok(!PD.overlay.hasAttribute('inert'));

	PD.requestCloseModal();
	const confirm = doc.getElementById('plandose-confirm-close');
	assert.ok(confirm && !confirm.hasAttribute('inert'), 'confirm box stays usable');

	doc.getElementById('plandose-confirm-yes').click();
	assert.ok(!page.hasAttribute('inert'));
	assert.ok(!page.hasAttribute('aria-hidden'));
	assert.ok(!PD.trigger.hasAttribute('inert'));
	assert.strictEqual(hiddenAlready.getAttribute('aria-hidden'), 'true', 'page-owned attribute kept');
	assert.strictEqual(doc.activeElement, PD.trigger);
});

test('Back to step 1 moves focus from the disabled button to the step dot', () => {
	const { w, PD } = load();
	open(w, PD);
	PD.s.items = [drug('A')];
	PD.goToStep(2);
	const prev = w.document.getElementById('pd-prev-step');
	prev.focus();
	prev.click();
	assert.strictEqual(PD.s.currentStep, 1);
	assert.strictEqual(w.document.activeElement.getAttribute('data-step'), '1');
	/* Focus that is not lost is left alone. */
	const patient = w.document.getElementById('pd-patient');
	patient.focus();
	PD.goToStep(1);
	assert.strictEqual(w.document.activeElement, patient);
});

test('deleting a card focuses the next, then the previous, then «Προσθήκη»', () => {
	const { w, PD } = load();
	open(w, PD);
	PD.s.items = [drug('A'), drug('B'), drug('C')];
	PD.renderMedicationList();
	const del = () => w.document.querySelectorAll('#pd-rows [data-action="delete"]');

	del()[1].focus();
	del()[1].click();
	assert.strictEqual(w.document.activeElement, del()[1], 'card C took B\'s place');
	assert.match(w.document.activeElement.getAttribute('aria-label'), /C$/);

	del()[1].click();
	assert.strictEqual(w.document.activeElement, del()[0], 'previous card');

	del()[0].click();
	assert.strictEqual(w.document.activeElement.id, 'pd-add');
});

test('app.js injected twice binds Escape once', () => {
	const { w, PD } = load();
	w.eval(fs.readFileSync(path.join(JS_DIR, 'app.js'), 'utf8'));
	open(w, PD);
	w.document.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape' }));
	assert.ok(w.document.getElementById('plandose-confirm-close'), 'prompt shown and kept');
});
