/* PlanDose 1.30.1 — the print request id is required (real test WordPress,
   tests/README.md). Run: npm run test:wp

   Before 1.30.1 a register_print without a request id (or with a malformed
   one) was accepted «the pre-1.24 way»: while another same-token request
   held the print lock it was answered «already recorded, print» with
   nothing charged. Now it is refused like a malformed token, charges
   nothing, and a well-formed request still prints. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, id } = require('../lib/wp-client.js');

let user = null;
let wp = null;

test.before(async () => {
	user = tempUser();
	wp = await new WpClient(user).login();
});

test.after(() => {
	if (user) {
		user.remove();
	}
});

test('register_print without a request id is refused and charges nothing', async () => {
	await wp.reset();
	const r = await wp.registerPrint(id());
	assert.ok(r.json && false === r.json.success, r.status + ' ' + r.text.slice(0, 200));
	assert.strictEqual((await wp.state()).print_count, 0, 'nothing charged');
});

test('a malformed request id is refused too', async () => {
	await wp.reset();
	for (const rid of ['x', 'ZZZZ', '../../etc', 'a'.repeat(33)]) {
		const r = await wp.registerPrint(id(), rid);
		assert.ok(r.json && false === r.json.success, JSON.stringify(rid) + ' → ' + r.status + ' ' + r.text.slice(0, 200));
	}
	assert.strictEqual((await wp.state()).print_count, 0, 'nothing charged');
});

test('concurrent same-token requests without an id never print uncharged', async () => {
	await wp.reset();
	const token = id();
	const results = await Promise.all(Array.from({ length: 6 }, () => wp.registerPrint(token)));
	for (const r of results) {
		assert.ok(r.json && false === r.json.success, r.status + ' ' + r.text.slice(0, 200));
	}
	assert.strictEqual((await wp.state()).print_count, 0, 'nothing charged, nothing printed');
});

test('a well-formed request still prints and is charged once', async () => {
	await wp.reset();
	const r = await wp.registerPrint(id(), id());
	assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 200));
	assert.strictEqual((await wp.state()).print_count, 1);
});
