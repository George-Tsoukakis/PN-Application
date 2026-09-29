/* 1.24.2 fix #6: a server_error on a retry no longer clears «charged, no sheet».
   Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

function setup(answers) {
	let call = 0;
	const env = load({
		fetch: () => {
			const a = answers[call++];
			if ('timeout' === a) {
				return new Promise(() => {});
			}
			return Promise.resolve({ text: () => Promise.resolve(JSON.stringify(a)) });
		}
	});
	env.PD.AJAX_TIMEOUT_MS = 30;
	env.PD.renderPrintCounter = () => {};
	return env;
}

function consume(PD, token) {
	return new Promise((resolve) => PD.consumePrintCreditOnce(token, (ok) => resolve(ok)));
}

const status = { print_count: 5, limit: 450 };

test('timeout, then server_error → token still marked charged-without-sheet', async () => {
	const { PD } = setup(['timeout', { success: false, data: Object.assign({ server_error: true, message: 'x' }, status) }]);
	const token = PD.createPrintToken();
	PD.s.pendingPrintToken = token;
	assert.strictEqual(await consume(PD, token), false);
	assert.strictEqual(PD.s.printTokenNoSheet, true, 'after the timeout');
	assert.strictEqual(await consume(PD, token), false);
	assert.strictEqual(PD.s.printTokenNoSheet, true, 'kept after server_error');
	/* So an edit keeps the token instead of charging a fresh one. */
	assert.strictEqual(PD.keepsUnusedPrintToken(), true);
});

test('timeout, then limit_reached → cleared (definitely not charged)', async () => {
	const { PD } = setup(['timeout', { success: false, data: Object.assign({ limit_reached: true, message: 'x' }, status) }]);
	const token = PD.createPrintToken();
	PD.s.pendingPrintToken = token;
	await consume(PD, token);
	assert.strictEqual(PD.s.printTokenNoSheet, true);
	await consume(PD, token);
	assert.strictEqual(PD.s.printTokenNoSheet, false);
});

test('first attempt server_error with no history → stays false', async () => {
	const { PD } = setup([{ success: false, data: Object.assign({ server_error: true, message: 'x' }, status) }]);
	const token = PD.createPrintToken();
	PD.s.pendingPrintToken = token;
	await consume(PD, token);
	assert.strictEqual(PD.s.printTokenNoSheet, false);
});
