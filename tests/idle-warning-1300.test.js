/* A plan left idle is cleared after PRINTED_IDLE_MS (privacy on a shared
   pharmacy PC). A minute before, the popup now warns on screen — an
   assertive live region, focus left where it is — with a «Κράτα το» button
   that restarts the countdown; any activity dismisses it too. Ignored, the
   plan is cleared exactly as before. Timers are driven by a fake clock
   (lib/fake-clock.js): no real waiting. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
const clock = require('./lib/fake-clock');

test.afterEach(closeAll);

const MIN = 60 * 1000;

function med(name) {
	return {
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: '30', notes: ''
	};
}

function openTool(opts) {
	const { w, PD } = load(opts);
	const c = clock.install(w);
	PD.refreshNonce = () => new w.Promise(() => {});
	PD.startNonceRefresh = () => {};
	PD.loadHeader = () => {};
	PD.openModal();
	const d = w.document;
	const box = () => d.getElementById('pd-idle-warning');
	const shown = () => !!box() && box().classList.contains('is-active');
	const keep = () => d.getElementById('pd-idle-keep');
	return { w, PD, c, d, box, shown, keep };
}

/* A patient typed in, as the pharmacist would: the input event arms the
   draft timer. */
function typePlan(t) {
	const patient = t.d.getElementById('pd-patient');
	patient.focus();
	patient.value = 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΓΙΑΝΝΗΣ';
	t.PD.s.items = [med('LANITOP TAB 0,1MG')];
	patient.dispatchEvent(new t.w.Event('input', { bubbles: true }));
	return patient;
}

test('the warning is a constant lead time ahead of the wipe', () => {
	const { PD } = load();
	assert.strictEqual(PD.IDLE_WARNING_MS, MIN);
	assert.strictEqual(PD.PRINTED_IDLE_MS, 10 * MIN);
});

test('unprinted plan: warning at 9 minutes, focus untouched; ignored, the plan is cleared at 10 as before', () => {
	const t = openTool();
	const patient = typePlan(t);
	assert.ok(t.PD.s.draftIdleTimer, 'armed by activity');
	t.c.advance(9 * MIN - 1);
	assert.ok(!t.shown(), 'nothing before 9 minutes');
	t.c.advance(1);
	assert.ok(t.shown(), 'warning at 9 minutes');
	const text = t.d.getElementById('pd-idle-warning-text');
	assert.strictEqual(text.textContent, 'Το πλάνο θα καθαριστεί σε 1 λεπτό λόγω αδράνειας. Πατήστε «Κράτα το» ή συνεχίστε την εργασία σας για να μείνει στην οθόνη.');
	assert.strictEqual(text.getAttribute('role'), 'alert');
	assert.strictEqual(text.getAttribute('aria-live'), 'assertive');
	assert.ok(!text.contains(t.keep()), 'the button is not read out as part of the alert');
	assert.strictEqual(t.keep().hidden, false);
	assert.strictEqual(t.keep().textContent, 'Κράτα το');
	assert.strictEqual(t.keep().type, 'button');
	assert.strictEqual(t.keep().tabIndex, 0, 'in the tab order');
	assert.strictEqual(t.d.activeElement, patient, 'focus is not stolen');
	assert.ok(t.box().closest('.plandose-actionbar'), 'in the sticky action bar, in view on either step');

	t.c.advance(MIN - 1);
	assert.strictEqual(t.PD.s.items.length, 1, 'not before 10 minutes');
	t.c.advance(1);
	assert.strictEqual(t.PD.s.items.length, 0, 'cleared at 10 minutes');
	assert.strictEqual(String(t.PD.getPatientName() || ''), '');
	assert.match(t.d.getElementById('pd-message').textContent, /χωρίς δραστηριότητα/);
	assert.ok(!t.shown(), 'no warning left behind');
});

test('«Κράτα το» cancels the wipe and re-arms the full countdown', () => {
	const t = openTool();
	typePlan(t);
	t.c.advance(9 * MIN);
	assert.ok(t.shown());
	t.keep().dispatchEvent(new t.w.Event('pointerdown', { bubbles: true }));
	t.keep().click();
	assert.ok(!t.shown(), 'dismissed');
	assert.strictEqual(t.keep().hidden, true);
	assert.strictEqual(t.d.getElementById('pd-idle-warning-text').textContent, '');
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 1, 'not cleared at the old deadline');
	t.c.advance(8 * MIN - 1);
	assert.ok(!t.shown());
	t.c.advance(1);
	assert.ok(t.shown(), 'warned again 9 minutes after «Κράτα το»');
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 0, 'and cleared 10 minutes after it');
});

test('«Κράτα το» from the keyboard: Tab / Shift+Tab keep the warning up until it is reached; pressing it hands focus back, not to <body>', () => {
	const t = openTool();
	const patient = typePlan(t);
	t.c.advance(9 * MIN);
	assert.ok(t.shown());
	/* Moving focus restarts the countdown but leaves the warning up, or a
	   keyboard user could never get to the button. */
	for (const key of ['Tab', 'Shift', 'Tab']) {
		t.d.activeElement.dispatchEvent(new t.w.KeyboardEvent('keydown', { key, shiftKey: 'Tab' !== key, bubbles: true }));
	}
	assert.ok(t.shown(), 'still there while the keyboard user moves towards it');
	t.keep().focus();
	t.keep().dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
	assert.ok(t.shown(), 'a key on the button itself does not hide it before its click');
	t.keep().click();
	assert.ok(!t.shown());
	assert.notStrictEqual(t.d.activeElement, t.d.body, 'focus handed back');
	assert.ok(t.d.getElementById('plandose-modal').contains(t.d.activeElement));
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 1);

	/* Tab counted as activity even while it left the warning up. */
	t.c.advance(8 * MIN);
	assert.ok(t.shown());
	patient.focus();
	patient.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
	assert.ok(t.shown());
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 1, 'not cleared: Tab restarted the countdown');
	patient.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'Β', bubbles: true }));
	assert.ok(!t.shown(), 'any other key dismisses it');
});

test('any activity in the popup dismisses the warning and re-arms the countdown', () => {
	const t = openTool();
	const patient = typePlan(t);
	t.c.advance(9 * MIN);
	assert.ok(t.shown());
	patient.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'Α', bubbles: true }));
	assert.ok(!t.shown(), 'a key press dismisses it');
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 1, 'not cleared');

	t.c.advance(8 * MIN);
	assert.ok(t.shown());
	t.d.getElementById('plandose-modal').dispatchEvent(new t.w.Event('pointerdown', { bubbles: true }));
	assert.ok(!t.shown(), 'a click dismisses it');
	t.c.advance(9 * MIN);
	assert.ok(t.shown());
	patient.dispatchEvent(new t.w.Event('input', { bubbles: true }));
	assert.ok(!t.shown(), 'typing dismisses it');
	t.c.advance(9 * MIN + 59 * 1000);
	assert.strictEqual(t.PD.s.items.length, 1);
});

test('printed plan left on screen: warned at 9 minutes, «Κράτα το» keeps it, ignored it is cleared at 10', () => {
	const t = openTool();
	typePlan(t);
	/* What a completed print leaves behind (see settle() in doPrintSafely()). */
	t.PD.s.pendingPrintToken = 'tok';
	t.PD.s.lastPrintToken = 'tok';
	t.PD.s.confirmedPatient = null;
	t.PD.armPrintedIdleTimer();
	t.c.advance(9 * MIN);
	assert.ok(t.shown(), 'printed-plan timer warns too');
	t.keep().click();
	assert.ok(!t.shown());
	assert.ok(t.PD.s.printedIdleTimer, 'printed-plan timer re-armed');
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 1, 'kept');
	t.c.advance(8 * MIN);
	assert.ok(t.shown(), 'warned again');
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 0, 'cleared when ignored');
	assert.strictEqual(t.PD.s.printedIdleTimer, null);
	assert.ok(!t.shown());
});

test('printed plan edited meanwhile (no wipe coming from that timer): no false warning from it', () => {
	const t = openTool();
	typePlan(t);
	t.PD.s.pendingPrintToken = 'tok';
	t.PD.s.lastPrintToken = 'other';
	t.PD.armPrintedIdleTimer();
	t.c.advance(9 * MIN);
	/* The draft timer is suppressed while the printed timer runs, and the
	   printed timer will not wipe an edited plan: nothing to warn about. */
	assert.ok(!t.shown());
	t.c.advance(MIN);
	assert.strictEqual(t.PD.s.items.length, 1);
});

test('no warning (and no wipe) when the form holds no patient data', () => {
	const t = openTool();
	let built = 0;
	const orig = t.PD.buildApp;
	t.PD.buildApp = function () { built++; return orig.apply(this, arguments); };
	t.d.getElementById('plandose-modal').dispatchEvent(new t.w.Event('pointerdown', { bubbles: true }));
	assert.ok(t.PD.s.draftIdleTimer, 'the countdown runs');
	t.c.advance(9 * MIN);
	assert.ok(!t.shown(), 'nothing to warn about');
	t.c.advance(MIN);
	assert.strictEqual(built, 0, 'nothing cleared');
});

test('closing the popup takes the warning and its timers away', () => {
	const t = openTool();
	typePlan(t);
	t.c.advance(9 * MIN);
	assert.ok(t.shown());
	t.PD.closeModal();
	assert.ok(!t.shown());
	assert.strictEqual(t.PD.s.draftWarnTimer, null);
	assert.strictEqual(t.PD.s.printedWarnTimer, null);
	assert.strictEqual(t.c.pending(), 0, 'no idle timer left running');
});

test('the lead time follows PD.IDLE_WARNING_MS, and the texts come from the dictionaries', () => {
	const t = openTool({ i18n: { el: { idleWarningMany: 'Σε %d λεπτά.', idleWarningKeep: 'Κράτα' } } });
	t.PD.IDLE_WARNING_MS = 2 * MIN;
	typePlan(t);
	t.c.advance(8 * MIN - 1);
	assert.ok(!t.shown());
	t.c.advance(1);
	assert.ok(t.shown());
	assert.strictEqual(t.d.getElementById('pd-idle-warning-text').textContent, 'Σε 2 λεπτά.');
	assert.strictEqual(t.keep().textContent, 'Κράτα');
	t.c.advance(2 * MIN);
	assert.strictEqual(t.PD.s.items.length, 0);
});

test('an idle time shorter than the lead time: no warning, the wipe unchanged', () => {
	const t = openTool();
	t.PD.PRINTED_IDLE_MS = 30 * 1000;
	typePlan(t);
	t.c.advance(30 * 1000 - 1);
	assert.ok(!t.shown());
	t.c.advance(1);
	assert.strictEqual(t.PD.s.items.length, 0);
});

test('the warning is styled for high contrast and without motion when reduced motion is asked for', () => {
	const css = require('fs').readFileSync(require('path').join(require('./harness').JS_DIR, '..', 'css', 'plandose.css'), 'utf8');
	assert.match(css, /@media \(forced-colors: active\) \{[^@]*\.pd-idle-keep \{\s*border: 2px solid ButtonText;/);
	assert.match(css, /@media \(prefers-reduced-motion: no-preference\) \{\s*#plandose-modal \.pd-idle-warning\.is-active \{\s*animation:/);
	assert.ok(!/\.pd-idle-warning[^{]*\{[^}]*animation/.test(css.replace(/@media \(prefers-reduced-motion: no-preference\) \{[^@]*?\}\s*\}/g, '')), 'animation only under no-preference');
	assert.match(css, /\.pd-idle-keep\[hidden\] \{\s*display: none;/);
});
