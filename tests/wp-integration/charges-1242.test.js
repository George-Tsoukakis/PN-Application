/* Print charging against a real WordPress + MariaDB: the print lock, lost
   connections around the counter UPDATE (fault injection with a real KILL,
   see pd-test-mu.php), replays, free reprints and concurrent copies.
   Needs the test WordPress of tests/README.md (PD_BASE, PD_FREE_USER, …, see
   lib/env.js). Run: npm run test:wp */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const { WpClient, tempUser, id, env } = require('../lib/wp-client.js');

/* A user of its own (PD_CHARGES_USER=login:pass to use a fixed one): other
   runs on the same WordPress then cannot touch its counter. */
let temp = null;
let wp = null;
const get = (q) => wp.testGet(q.replace(/^pd_test=/, ''));
const registerPrint = (token, requestId) => wp.registerPrint(token, requestId);
const setFault = (value) => wp.setFault(value);

test.before(async () => {
	if (process.env.PD_CHARGES_USER) {
		const [login, pass] = process.env.PD_CHARGES_USER.split(':');
		wp = new WpClient({ login, pass });
	} else {
		temp = tempUser();
		wp = new WpClient(temp);
	}
	await wp.login();
});

test.after(() => {
	if (temp) {
		temp.remove();
	}
});

test.beforeEach(async () => {
	await wp.reset();
});

test('fix 4: lock held by a copy of this request, nothing recorded → «try again», never success', async () => {
	const token = id();
	const req = id();
	assert.strictEqual((await wp.testPost('hold_lock', { token, request: req })).held, true);
	const t0 = Date.now();
	const r = await registerPrint(token, req);
	assert.ok(Date.now() - t0 >= 2500, 'waited for the copy holding the lock');
	assert.strictEqual(r.json && r.json.success, false, 'not a success: ' + r.text.slice(0, 200));
	assert.strictEqual(r.json.data.server_error, true);
	assert.strictEqual((await get('pd_test=state')).print_count, 0);
});

test('1.27.0: lock held by ANOTHER request → «try again» at once, no wait', async () => {
	const token = id();
	assert.strictEqual((await wp.testPost('hold_lock', { token, request: id() })).held, true);
	const t0 = Date.now();
	const r = await registerPrint(token, id());
	assert.ok(Date.now() - t0 < 2000, 'did not wait');
	assert.strictEqual(r.json && r.json.success, false, r.text.slice(0, 200));
	assert.strictEqual(r.json.data.server_error, true);
	assert.strictEqual((await get('pd_test=state')).print_count, 0);
});

test('fix 4: lock held by a copy of this request, the token IS charged meanwhile → success (duplicate_ignored)', async () => {
	const token = id();
	const req = id();
	await wp.testPost('hold_lock', { token, request: req });
	const later = wp.testPost('charge_later', { token, ms: 1000 });
	const r = await registerPrint(token, req);
	await later;
	assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 200));
	/* Normally answered while waiting for the lock (duplicate_ignored). When
	   the PHP server starts this request only after the other copy's charge
	   landed (~1 in 20 runs on the built-in server), the token is already
	   charged on arrival and the answer is a free reprint. Either way: a
	   success, charged once. */
	assert.ok(r.json.data.duplicate_ignored === true || r.json.data.already_recorded === true, JSON.stringify(r.json.data));
	assert.strictEqual((await get('pd_test=state')).print_count, 1, 'charged once, by the other copy');
});

test('fix 5: connection lost before the counter, first put-back write fails → records still written, reprint free', async () => {
	await setFault('kill_before_increment_fail_once');
	const token = id();
	const r = await registerPrint(token, id());
	await setFault('');
	assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 300));
	let s = await get('pd_test=state');
	assert.strictEqual(s.print_count, 1);
	assert.strictEqual(s.charges, 1, 'charge row put back');
	assert.strictEqual(s.requests, 1, 'request record put back');

	/* «Εκτύπωση ξανά» of the same plan: a free reprint, not a new charge. */
	const again = await registerPrint(token, id());
	assert.strictEqual(again.json && again.json.success, true, again.text.slice(0, 200));
	assert.strictEqual(again.json.data.already_recorded, true);
	s = await get('pd_test=state');
	assert.strictEqual(s.print_count, 1, 'not charged twice');
});

test('fix 5: connection lost before the counter, no write failure → charged once', async () => {
	await setFault('kill_before_increment');
	const token = id();
	const r = await registerPrint(token, id());
	await setFault('');
	assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 300));
	const s = await get('pd_test=state');
	assert.strictEqual(s.print_count, 1);
	assert.strictEqual(s.charges, 1);
});

test('fix 5: put-back keeps failing → still success (counter was charged), and it is logged', async () => {
	const log = env.DEBUG_LOG;
	const before = fs.existsSync(log) ? fs.readFileSync(log, 'utf8').length : 0;
	await setFault('kill_before_increment_fail_always');
	const r = await registerPrint(id(), id());
	await setFault('');
	assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 300));
	assert.strictEqual((await get('pd_test=state')).print_count, 1);
	const added = fs.readFileSync(log, 'utf8').slice(before);
	assert.match(added, /PlanDose: print charged for user \d+, but its charge\/request record could not be written/);
});

test('normal print, replay and free reprints unchanged', async () => {
	const token = id();
	const rid = id();
	assert.strictEqual((await registerPrint(token, rid)).json.success, true);
	const replay = await registerPrint(token, rid);
	assert.strictEqual(replay.json.data.already_recorded, true, 'same request answered from its record');
	assert.strictEqual((await registerPrint(token, id())).json.data.already_recorded, true);
	assert.strictEqual((await registerPrint(token, id())).json.data.already_recorded, true);
	assert.strictEqual((await get('pd_test=state')).print_count, 1, '2 free reprints');
	const third = await registerPrint(token, id());
	assert.strictEqual(third.json.success, true);
	assert.ok(!third.json.data.already_recorded);
	assert.strictEqual((await get('pd_test=state')).print_count, 2, 'third reprint is a new print');
});

test('concurrent copies of one request charge once', async () => {
	const token = id();
	const rid = id();
	const all = await Promise.all(Array.from({ length: 8 }, () => registerPrint(token, rid)));
	for (const r of all) {
		assert.ok(r.json, r.text.slice(0, 200));
	}
	const s = await get('pd_test=state');
	assert.strictEqual(s.print_count, 1, 'charged exactly once');
	assert.ok(all.some((r) => r.json.success), 'at least one success');
});
