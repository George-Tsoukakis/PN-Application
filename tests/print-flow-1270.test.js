/* 1.27.0: A4 print flow — plan locked while printing, final check right
   before the sheet, honest outcome messages, billing status line, and a
   possibly charged token kept for the retry. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
const { until } = require('./lib/until');
test.afterEach(closeAll);

function drug(name) {
	return {
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: '12h',
		dailyTime: 'morning', customMode: 'days', customIntervalDays: '7',
		customWeekday: 1, days: '7', notes: ''
	};
}

function ok(data) {
	return { success: true, data: data };
}

/* fetch stub answering per admin-ajax action. A function answer gets the
   call number; 'hang' never answers. */
function setup(answers, opts) {
	const calls = [];
	const env = load(Object.assign({
		fetch: (url, init) => {
			const action = new URLSearchParams(init.body).get('action');
			calls.push(action);
			let a = answers[action];
			if (typeof a === 'function') {
				a = a(calls.filter((c) => c === action).length);
			}
			if ('hang' === a) {
				return new Promise(() => {});
			}
			if (a === undefined) {
				return Promise.reject(new Error('no answer for ' + action));
			}
			return Promise.resolve({ text: () => Promise.resolve(JSON.stringify(a)) });
		}
	}, opts || {}));
	const { w, PD } = env;
	PD.AJAX_TIMEOUT_MS = 40;
	PD.refreshNonce = () => new w.Promise(() => {});
	PD.startNonceRefresh = () => {};
	const realLoadHeader = PD.loadHeader;
	PD.loadHeader = () => {};
	PD.openModal();
	PD.loadHeader = realLoadHeader;
	PD.s.items = [drug('Depon')];
	PD.updateStepButtons();
	env.calls = calls;
	return env;
}

/* A print window that prints at once and fires 'afterprint'. */
function fakeWindow(opts) {
	opts = opts || {};
	const listeners = {};
	const win = {
		closed: false,
		html: '',
		printed: 0,
		document: {
			open() { win.html = ''; },
			write(h) { win.html += h; },
			close() {}
		},
		close() { win.closed = true; },
		focus() {},
		print() {
			win.printed++;
			if (!opts.noAfterprint && listeners.afterprint) {
				listeners.afterprint();
			}
		},
		addEventListener(t, f) { listeners[t] = f; },
		removeEventListener(t) { delete listeners[t]; }
	};
	return win;
}

function tick(ms) {
	return new Promise((r) => setTimeout(r, ms || 0));
}

/* The print flow has ended (released, whatever the outcome). Polled: a
   fixed wait failed under CPU load. */
function settled(PD) {
	return until(() => !PD.isPrintBusy(), 10000, 'the print flow to end');
}

const status = { print_count: 3, remaining: 10 };
const answersOk = {
	plandose_check_print: ok(status),
	plandose_get_header: ok({ name: 'Φαρμακείο Δοκιμή', phone_1: '2100000000' }),
	plandose_register_print: ok({ print_count: 4, remaining: 9 })
};

function step1(w) {
	return w.document.querySelector('[data-pd-step="1"]');
}

test('the step-1 panel is inert and aria-busy while a print runs, and released after', async () => {
	const env = setup(Object.assign({}, answersOk, { plandose_get_header: 'hang' }));
	const { w, PD } = env;
	const win = fakeWindow();
	w.open = () => win;
	PD.handlePrint();
	assert.ok(step1(w).hasAttribute('inert'), 'inert during preparation');
	assert.strictEqual(step1(w).getAttribute('aria-busy'), 'true');
	/* The header lookup times out → abort → released. */
	await settled(PD);
	assert.strictEqual(PD.isPrintBusy(), false);
	assert.ok(!step1(w).hasAttribute('inert'), 'released after abort');
	assert.ok(!step1(w).hasAttribute('aria-busy'));
});

test('a full print releases the lock, records the charge and shows the billing line', async () => {
	const env = setup(answersOk);
	const { w, PD } = env;
	const win = fakeWindow();
	w.open = () => win;
	PD.handlePrint();
	assert.ok(PD.isPrintBusy(), 'the flow started');
	await settled(PD);
	assert.strictEqual(win.printed, 1, 'printed once');
	assert.ok(!step1(w).hasAttribute('inert'));
	const line = w.document.getElementById('pd-billing-status');
	assert.ok(line, 'billing status line exists');
	assert.match(line.textContent, /\d\d:\d\d/, 'local time of the charge');
	assert.match(line.textContent, /Δωρεάν επανεκτυπώσεις: 2/, 'free reprints from config');
	assert.match(line.textContent, /δεν μπορεί να γνωρίζει/, 'says the browser cannot know');
	/* A later message does not remove it. */
	PD.setMessage('άλλο μήνυμα', 'error');
	assert.match(w.document.getElementById('pd-billing-status').textContent, /\d\d:\d\d/);
	/* The printed-state message names the same window and the idle wipe. */
	const msg = w.document.getElementById('pd-message').textContent;
	assert.ok(PD.s.billing && PD.s.billing.freeLeft === 2);
	assert.doesNotMatch(msg, /30 λεπτά/);
});

test('maxFreeReprints falls back to the server value 2; the window to 30 minutes', () => {
	const { PD } = load({ config: { maxFreeReprints: undefined } });
	delete PD.config.maxFreeReprints;
	assert.strictEqual(PD.maxFreeReprints(), 2);
	assert.strictEqual(PD.reprintWindowMinutes(), 30);
	PD.config.reprintWindowMinutes = '45';
	assert.strictEqual(PD.reprintWindowMinutes(), 45);
});

test('final check right before the sheet: a medicine typed meanwhile stops the print, nothing is sent', () => {
	const env = setup(answersOk);
	const { w, PD } = env;
	let consumed = 0;
	PD.consumePrintCreditOnce = () => { consumed++; };
	w.document.getElementById('pd-drug').value = 'Augmentin';
	const win = fakeWindow();
	PD.doPrintSafely(win);
	assert.strictEqual(consumed, 0, 'no register_print');
	assert.ok(win.closed, 'placeholder closed');
	assert.strictEqual(PD.s.isPrinting, false);
	assert.match(w.document.getElementById('pd-message').textContent, /δεν έχει προστεθεί/);
});

test('final check right before the sheet: an invalid plan (0 doses) stops the print', () => {
	const env = setup(answersOk);
	const { PD } = env;
	let consumed = 0;
	PD.consumePrintCreditOnce = () => { consumed++; };
	PD.doseTotals = () => ({ doses: 0, units: 0 });
	const win = fakeWindow();
	PD.doPrintSafely(win);
	assert.strictEqual(consumed, 0);
	assert.ok(win.closed);
});

test('an incomplete start date refuses to build the sheet instead of printing «today»', () => {
	const env = setup(answersOk);
	const { w, PD } = env;
	let consumed = 0;
	PD.consumePrintCreditOnce = () => { consumed++; };
	PD.s.startDate = PD.START_DATE_INCOMPLETE;
	assert.throws(() => PD.buildCheckedPrintHtml(), /invalid_start_date/);
	/* Even if the plan check were skipped, the build refuses. */
	PD.validatePlanForPrint = () => true;
	const win = fakeWindow();
	PD.doPrintSafely(win);
	assert.strictEqual(consumed, 0);
	assert.ok(win.closed);
	assert.strictEqual(PD.s.isPrinting, false);
	assert.notStrictEqual(w.document.getElementById('pd-message').textContent, '');
});

test('placeholder window already closed → a message, not silence', () => {
	const env = setup(answersOk);
	const { w, PD } = env;
	const win = fakeWindow();
	win.closed = true;
	PD.doPrintSafely(win);
	assert.match(w.document.getElementById('pd-message').textContent, /ακυρώθηκε ή το παράθυρο μπλοκαρίστηκε/);
});

test('hidden-frame fallback without afterprint reports an uncertain outcome, and the message is honest', () => {
	const { w, PD } = load();
	const timers = [];
	w.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
	/* print() suppressed silently: no error, no dialog, no afterprint. */
	const frame = w.document.createElement('iframe');
	frame.id = 'plandose-print-frame';
	w.document.body.appendChild(frame);
	frame.contentWindow.focus = () => {};
	frame.contentWindow.print = () => {};
	let result = null;
	PD.printViaHiddenFrame('<p>x</p>', (started, uncertain) => { result = { started, uncertain }; });
	timers.sort((a, b) => a.ms - b.ms).forEach((t) => t.fn());
	assert.deepStrictEqual(result, { started: true, uncertain: true });

	const env = setup(answersOk);
	env.PD.s.billing = { token: 't', at: Date.now(), freeLeft: 2 };
	env.PD.isReprintFree = () => true;
	env.PD.showPrintedState(false, null, true);
	assert.match(env.w.document.getElementById('pd-message').textContent, /αν δεν άνοιξε ο διάλογος εκτύπωσης/);
});

test('plan changed while the charge was in flight → old sheet not printed, token kept for a free retry', async () => {
	const env = setup(answersOk);
	const { w, PD } = env;
	const win = fakeWindow();
	PD.consumePrintCreditOnce = (token, cb) => {
		/* An edit lands while the request is in flight. */
		PD.s.items = [drug('Depon'), drug('Augmentin')];
		PD.invalidatePendingPrintToken();
		PD.s.lastPrintToken = token;
		cb(true, { alreadyRecorded: false });
	};
	PD.doPrintSafely(win);
	await tick(10);
	assert.strictEqual(win.printed, 0, 'the old sheet is not printed');
	assert.ok(PD.s.pendingPrintToken, 'token kept');
	assert.strictEqual(PD.s.printTokenNoSheet, true, 'charged without a sheet');
	assert.strictEqual(PD.keepsUnusedPrintToken(), true);
	assert.match(w.document.getElementById('pd-message').textContent, /χωρίς νέα χρέωση/);
	assert.ok(!step1(w).hasAttribute('inert'));
});

test('timeout after an edit dropped the token: the maybe-charged token is restored', async () => {
	const env = setup({ plandose_register_print: 'hang' });
	const { PD } = env;
	PD.renderPrintCounter = () => {};
	const token = PD.createPrintToken();
	PD.s.pendingPrintToken = token;
	PD.s.printTokenNoSheet = false;
	PD.s.printTokenSheetOpened = false;
	const done = new Promise((r) => PD.consumePrintCreditOnce(token, (okd) => r(okd)));
	PD.invalidatePendingPrintToken(); /* edit while in flight */
	assert.strictEqual(PD.s.pendingPrintToken, null);
	assert.strictEqual(await done, false);
	assert.strictEqual(PD.s.pendingPrintToken, token, 'restored for the retry');
	assert.strictEqual(PD.s.printTokenNoSheet, true);
});

test('a token that already printed a sheet is not carried to an edited plan', async () => {
	const env = setup({ plandose_register_print: 'hang' });
	const { PD } = env;
	PD.renderPrintCounter = () => {};
	const token = PD.createPrintToken();
	PD.s.pendingPrintToken = token;
	PD.s.printTokenSheetOpened = true;
	const done = new Promise((r) => PD.consumePrintCreditOnce(token, (okd) => r(okd)));
	PD.invalidatePendingPrintToken();
	await done;
	assert.strictEqual(PD.s.pendingPrintToken, null);
});

test('header: empty data is not «loaded»; a refusal shows the server message', async () => {
	let env = setup({ plandose_get_header: ok({}) });
	let loaded = await new Promise((r) => env.PD.loadHeader(r));
	assert.strictEqual(loaded, false);
	assert.strictEqual(env.PD.s.header, null);
	assert.match(env.PD.headerFailureMessage(), /στοιχεία του φαρμακείου/);

	env = setup({ plandose_get_header: ok({ name: '   ' }) });
	loaded = await new Promise((r) => env.PD.loadHeader(r));
	assert.strictEqual(loaded, false);

	env = setup({ plandose_get_header: { success: false, data: { message: 'Πολλά αιτήματα. Περιμένετε λίγο.' } } });
	loaded = await new Promise((r) => env.PD.loadHeader(r));
	assert.strictEqual(loaded, false);
	assert.strictEqual(env.PD.headerFailureMessage(), 'Πολλά αιτήματα. Περιμένετε λίγο.');

	/* And the print flow shows it instead of «Πρόβλημα σύνδεσης». */
	env = setup(Object.assign({}, answersOk, { plandose_get_header: { success: false, data: { message: 'Όριο αιτημάτων.' } } }));
	const win = fakeWindow();
	env.w.open = () => win;
	env.PD.handlePrint();
	assert.ok(env.PD.isPrintBusy(), 'the flow started');
	await settled(env.PD);
	assert.strictEqual(env.w.document.getElementById('pd-message').textContent, 'Όριο αιτημάτων.');
	assert.strictEqual(win.printed, 0);
});
