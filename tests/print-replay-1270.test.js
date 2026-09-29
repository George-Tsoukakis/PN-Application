/* 1.27.0 round 3: a confirmed charge whose sheet never opened is retried
   with the SAME request id (a bounded free replay on the server); the
   server's 409 replay_limit is explained; the billing line uses the
   server's free_reprints_left. Run: node --test */
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

function setup(answers) {
	const sent = [];
	const env = load({
		fetch: (url, init) => {
			const params = new URLSearchParams(init.body);
			const action = params.get('action');
			sent.push({ action, token: params.get('token'), request: params.get('request_id') });
			let a = answers[action];
			if (typeof a === 'function') {
				a = a(sent.filter((c) => c.action === action).length);
			}
			return Promise.resolve({ text: () => Promise.resolve(JSON.stringify(a)) });
		}
	});
	const { w, PD } = env;
	PD.refreshNonce = () => new w.Promise(() => {});
	PD.startNonceRefresh = () => {};
	const lh = PD.loadHeader;
	PD.loadHeader = () => {};
	PD.openModal();
	PD.loadHeader = lh;
	PD.s.items = [drug('Depon')];
	PD.updateStepButtons();
	env.registers = () => sent.filter((c) => c.action === 'plandose_register_print');
	return env;
}

function fakeWindow(opts) {
	opts = opts || {};
	const listeners = {};
	const win = {
		closed: false, printed: 0,
		document: { open() {}, write() {}, close() {} },
		close() { win.closed = true; },
		focus() {},
		print() { win.printed++; if (listeners.afterprint) { listeners.afterprint(); } },
		addEventListener(t, f) { listeners[t] = f; },
		removeEventListener(t) { delete listeners[t]; }
	};
	return win;
}

/* Press «Εκτύπωση» and wait for the flow to end, whatever the outcome.
   Polled: a fixed wait failed under CPU load. */
async function printAndSettle(PD) {
	PD.handlePrint();
	assert.ok(PD.isPrintBusy(), 'the flow started');
	await until(() => !PD.isPrintBusy(), 10000, 'the print flow to end');
}
const base = {
	plandose_check_print: { success: true, data: { print_count: 3, remaining: 10 } },
	plandose_get_header: { success: true, data: { name: 'Φαρμακείο Δοκιμή', phone_1: '210' } }
};

test('charged but no sheet → the retry sends the SAME request id and prints free', async () => {
	let win;
	const env = setup(Object.assign({}, base, {
		plandose_register_print: (n) => {
			if (1 === n) {
				win.closed = true; /* closed while the charge was recorded */
				return { success: true, data: { print_count: 4 } };
			}
			return { success: true, data: { print_count: 4, already_recorded: true, free_reprints_left: 2 } };
		}
	}));
	const { w, PD } = env;
	win = fakeWindow();
	w.open = () => win;
	await printAndSettle(PD);
	assert.strictEqual(win.printed, 0);
	assert.strictEqual(PD.s.printTokenNoSheet, true);
	assert.match(w.document.getElementById('pd-message').textContent, /δεν θα χρεωθεί δεύτερη φορά/);

	const win2 = fakeWindow();
	w.open = () => win2;
	await printAndSettle(PD);
	const regs = env.registers();
	assert.strictEqual(regs.length, 2);
	assert.strictEqual(regs[1].token, regs[0].token, 'same token');
	assert.strictEqual(regs[1].request, regs[0].request, 'same request id → a replay, not a new reprint');
	assert.strictEqual(win2.printed, 1);
	assert.match(w.document.getElementById('pd-billing-status').textContent, /Δωρεάν επανεκτυπώσεις: 2/);
});

test('once a sheet opened, the next press is a new request (a counted reprint)', async () => {
	const env = setup(Object.assign({}, base, {
		plandose_register_print: (n) => (1 === n
			? { success: true, data: { print_count: 4 } }
			: { success: true, data: { print_count: 4, already_recorded: true, free_reprints_left: 1 } })
	}));
	const { w, PD } = env;
	w.open = () => fakeWindow();
	await printAndSettle(PD);
	await printAndSettle(PD);
	const regs = env.registers();
	assert.strictEqual(regs.length, 2, w.document.getElementById('pd-message').textContent + ' busy=' + PD.isPrintBusy());
	assert.strictEqual(regs[1].token, regs[0].token);
	assert.notStrictEqual(regs[1].request, regs[0].request);
	/* The server's count, not a local guess. */
	assert.strictEqual(PD.s.billing.freeLeft, 1);
	assert.match(w.document.getElementById('pd-billing-status').textContent, /Δωρεάν επανεκτυπώσεις: 1/);
});

test('409 replay_limit: clear message, nothing printed; the token is kept and the next press is a NEW request on it', async () => {
	let win;
	const env = setup(Object.assign({}, base, {
		plandose_register_print: (n) => {
			if (1 === n) {
				win.closed = true;
				return { success: true, data: { print_count: 4 } };
			}
			if (2 === n) {
				return { success: false, data: { print_count: 4, replay_limit: true, message: 'server text' } };
			}
			/* The server decides: here a free reprint is still left. */
			return { success: true, data: { print_count: 4, already_recorded: true, free_reprints_left: 1 } };
		}
	}));
	const { w, PD } = env;
	win = fakeWindow();
	w.open = () => win;
	await printAndSettle(PD);
	const token = PD.s.pendingPrintToken;
	const win2 = fakeWindow();
	w.open = () => win2;
	await printAndSettle(PD);
	assert.strictEqual(win2.printed, 0, 'nothing printed');
	const msg = w.document.getElementById('pd-message').textContent;
	assert.match(msg, /δεν χρεώθηκε και δεν τυπώθηκε/);
	assert.match(msg, /δωρεάν επανεκτύπωση αν απομένουν/);
	assert.match(msg, /νέα εκτύπωση/);
	assert.strictEqual(PD.s.pendingPrintToken, token, 'token kept');
	assert.strictEqual(PD.s.pendingPrintRequest, null, 'request id cleared');
	assert.ok(!w.document.querySelector('[data-pd-step="1"]').hasAttribute('inert'));

	const win3 = fakeWindow();
	w.open = () => win3;
	await printAndSettle(PD);
	const regs = env.registers();
	assert.strictEqual(regs.length, 3);
	assert.strictEqual(regs[2].token, regs[0].token, 'same token');
	assert.notStrictEqual(regs[2].request, regs[1].request, 'a new request id');
	assert.strictEqual(win3.printed, 1, 'the free reprint prints');
	assert.match(w.document.getElementById('pd-billing-status').textContent, /Δωρεάν επανεκτυπώσεις: 1/);
});

test('a new charge uses free_reprints_left when the server sends it', () => {
	const { PD } = load();
	PD.recordBilling('t1', { alreadyRecorded: false, freeReprintsLeft: 1 });
	assert.strictEqual(PD.s.billing.freeLeft, 1);
	PD.recordBilling('t2', { alreadyRecorded: false, freeReprintsLeft: null });
	assert.strictEqual(PD.s.billing.freeLeft, 2, 'configured maximum as the fallback');
});
