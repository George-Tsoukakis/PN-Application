/* PlanDose 1.27.0 — register_print / get_header against the real test WordPress
   (tests/README.md). Uses a throw-away pdt_* pharmacy (lib/wp-client.js
   tempUser, needs wp-cli) and pd-test-mu.php. Run: npm run test:wp */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, id, md5 } = require('../lib/wp-client.js');

let temp = null;
let wp = null;

const receipt = (token) => md5('plandose_print_receipt|' + token);
const rowFor = (s, token) => s.charge_rows.filter((r) => r.token_hash === receipt(token));

test.before(async () => {
	temp = tempUser();
	wp = new WpClient(temp);
	await wp.login();
});

test.after(async () => {
	if (wp) {
		await wp.setFault('').catch(() => {});
	}
	if (temp) {
		temp.remove();
	}
});

test.beforeEach(async () => {
	await wp.reset();
});

test('replay of a request id: free for the whole window (not only 60 s), at most 2 times', async () => {
	const token = id();
	const rid = id();
	const first = await wp.registerPrint(token, rid);
	assert.strictEqual(first.json && first.json.success, true, first.status + ' ' + first.text.slice(0, 300));
	await wp.testPost('age', { seconds: 120 });
	const one = await wp.registerPrint(token, rid);
	assert.strictEqual(one.json.data.already_recorded, true, 'replay after 120 s is still a replay');
	const two = await wp.registerPrint(token, rid);
	assert.strictEqual(two.json.data.already_recorded, true, 'second replay');
	const s = await wp.state();
	assert.strictEqual(Number(rowFor(s, token)[0].reprints), 0, 'replays use no free reprint');
	assert.strictEqual(s.print_count, 1);
	const three = await wp.registerPrint(token, rid);
	assert.strictEqual(three.status, 409, three.text.slice(0, 200));
	assert.strictEqual(three.json.success, false);
	assert.strictEqual(three.json.data.replay_limit, true, 'third replay refused');
	assert.strictEqual((await wp.state()).print_count, 1, 'refusal charges nothing');
});

test('lost answer on the LAST free reprint: the retry after 61 s is free and prints; a 3rd replay is refused', async () => {
	const token = id();
	assert.strictEqual((await wp.registerPrint(token, id())).json.success, true, 'charge');
	assert.strictEqual((await wp.registerPrint(token, id())).json.data.already_recorded, true, 'free reprint 1');
	const lastRid = id();
	/* The server records the last free reprint; its answer is "lost" (ignored). */
	const lost = await wp.registerPrint(token, lastRid);
	assert.strictEqual(lost.json.data.free_reprints_left, 0, 'that was the last free reprint');

	await wp.testPost('age', { seconds: 61 });
	const retry = await wp.registerPrint(token, lastRid);
	assert.strictEqual(retry.json && retry.json.success, true, retry.text.slice(0, 300));
	assert.strictEqual(retry.json.data.already_recorded, true, 'answered from the record: prints, free');
	assert.strictEqual((await wp.state()).print_count, 1, 'not charged (1.26.1 promise kept)');

	const second = await wp.registerPrint(token, lastRid);
	assert.strictEqual(second.json && second.json.success, true, 'second replay still answered');
	const third = await wp.registerPrint(token, lastRid);
	assert.strictEqual(third.json.data.replay_limit, true, 'third replay refused');
	assert.strictEqual((await wp.state()).print_count, 1, 'never charged');
});

test('concurrent replays of one request: never more than 2 answered', async () => {
	const token = id();
	const rid = id();
	assert.strictEqual((await wp.registerPrint(token, rid)).json.success, true);
	const all = await Promise.all(Array.from({ length: 6 }, () => wp.registerPrint(token, rid)));
	const answered = all.filter((r) => r.json && r.json.success && r.json.data.already_recorded).length;
	const refused = all.filter((r) => r.json && r.json.data && r.json.data.replay_limit).length;
	assert.strictEqual(answered, 2, 'exactly 2 replays answered: ' + all.map((r) => r.status).join(','));
	assert.strictEqual(refused, 4);
	assert.strictEqual((await wp.state()).print_count, 1);
});

test('a request id recorded for another token is refused', async () => {
	const rid = id();
	assert.strictEqual((await wp.registerPrint(id(), rid)).json.success, true);
	const other = await wp.registerPrint(id(), rid);
	assert.strictEqual(other.status, 400);
	assert.strictEqual(other.json.data.invalid_request, true);
	assert.strictEqual((await wp.state()).print_count, 1);
});

test('free reprint: connection lost right before its COMMIT → «try again», nothing used; the retry uses one', async () => {
	const token = id();
	assert.strictEqual((await wp.registerPrint(token, id())).json.success, true);
	const rid = id();
	await wp.setFault('kill_before_commit');
	const r = await wp.registerPrint(token, rid);
	await wp.setFault('');
	assert.strictEqual(r.json && r.json.success, false, 'no success for a rolled-back reprint: ' + r.text.slice(0, 300));
	assert.strictEqual(r.json.data.server_error, true);
	let s = await wp.state();
	assert.strictEqual(Number(rowFor(s, token)[0].reprints), 0, 'nothing used');

	const again = await wp.registerPrint(token, rid);
	assert.strictEqual(again.json && again.json.success, true, again.text.slice(0, 300));
	assert.strictEqual(again.json.data.already_recorded, true);
	s = await wp.state();
	assert.strictEqual(Number(rowFor(s, token)[0].reprints), 1, 'one free reprint used');
	assert.strictEqual(s.print_count, 1, 'never charged again');
});

test('get_header sends no contactName / account_type', async () => {
	const body = new URLSearchParams({ action: 'plandose_get_header', nonce: wp.nonce });
	const res = await fetch(require('../lib/env.js').BASE + '/wp-admin/admin-ajax.php', { method: 'POST', body, headers: { cookie: wp.cookieHeader() } });
	const json = await res.json();
	assert.strictEqual(json && json.success, true, JSON.stringify(json).slice(0, 200));
	assert.ok('name' in json.data);
	assert.ok(!('contactName' in json.data));
	assert.ok(!('account_type' in json.data));
});
