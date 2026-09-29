/* Print charging when the database connection is lost at each step of the
   charge (a real KILL of the request's MariaDB connection, pd-test-mu.php),
   or when the answer never reaches the browser, followed by what the
   browser does next (api.js):

     1. the same press again  (same token + same request id)  → exactly one
        charge row and the counter +1 in total;
     2. «Εκτύπωση ξανά»       (same token, new request id, within the
        30-minute window)      → a free reprint: counter unchanged, reprints +1;
     3. the same plan after the window                         → a new charge.

   Also: a success answer must mean the print is recorded (charge row,
   request row, counter) — otherwise the sheet printed without being counted.

   Needs the test WordPress of tests/README.md, wp-cli (PD_WP_CLI) for a
   throw-away user. Run: npm run test:wp */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, id, md5 } = require('../lib/wp-client.js');

const TTL = 1800; /* Plandose_Ajax::PRINT_RECEIPT_TTL */

let temp = null;
let wp = null;

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

const receipt = (token) => md5('plandose_print_receipt|' + token);
const requestHash = (rid) => md5('plandose_print_request|' + rid);

function rowFor(state, token) {
	return state.charge_rows.filter((r) => r.token_hash === receipt(token));
}

async function waitFor(cond, ms) {
	const end = Date.now() + ms;
	for (;;) {
		const s = await wp.state();
		if (cond(s)) {
			return s;
		}
		if (Date.now() > end) {
			return s;
		}
		await new Promise((r) => setTimeout(r, 100));
	}
}

/* The browser's next steps after the first attempt (whatever it answered). */
async function afterFirstAttempt(t, token, rid, first) {
	await wp.setFault('');

	if (first && first.json && first.json.success) {
		await t.test('a success answer means the print is recorded', async () => {
			const s = await wp.state();
			assert.strictEqual(s.print_count, 1, 'counter +1: ' + JSON.stringify(first.json.data));
			assert.strictEqual(rowFor(s, token).length, 1, 'charge row');
			assert.ok(s.request_rows.some((r) => r.request_hash === requestHash(rid)), 'request row');
		});
	}

	await t.test('retry, same token + same request id → one charge, counter +1', async () => {
		const r = await wp.registerPrint(token, rid);
		assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 300));
		const s = await wp.state();
		assert.strictEqual(s.print_count, 1, 'charged exactly once');
		assert.strictEqual(s.charges, 1, 'one charge row');
		assert.strictEqual(rowFor(s, token).length, 1);
		assert.strictEqual(Number(rowFor(s, token)[0].reprints), 0, 'no free reprint used by the retry');
		/* And once more: still answered from the record. */
		const again = await wp.registerPrint(token, rid);
		assert.strictEqual(again.json && again.json.success, true, again.text.slice(0, 300));
		assert.strictEqual((await wp.state()).print_count, 1);
	});

	await t.test('«Εκτύπωση ξανά» within the window (new request id) → free reprint', async () => {
		const r = await wp.registerPrint(token, id());
		assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 300));
		assert.strictEqual(r.json.data.already_recorded, true, 'answered as a free reprint');
		const s = await wp.state();
		assert.strictEqual(s.print_count, 1, 'not charged');
		assert.strictEqual(Number(rowFor(s, token)[0].reprints), 1, 'one free reprint used');
	});

	await t.test('the same plan after the window → a new charge', async () => {
		await wp.testPost('age', { seconds: TTL + 60 });
		const r = await wp.registerPrint(token, id());
		assert.strictEqual(r.json && r.json.success, true, r.text.slice(0, 300));
		assert.ok(!r.json.data.already_recorded, 'not a free reprint');
		const s = await wp.state();
		assert.strictEqual(s.print_count, 2, 'charged again');
		assert.strictEqual(rowFor(s, token).length, 1, 'the same row, charged anew');
		assert.strictEqual(Number(rowFor(s, token)[0].reprints), 0, 'free reprints start again');
		assert.ok(Number(rowFor(s, token)[0].charged_ts) >= s.now - 60, 'charged now');
	});
}

for (const [fault, what] of [
	['kill_before_charge_insert', 'connection lost right BEFORE the charge-row write'],
	['kill_after_charge_insert', 'connection lost AFTER the charge-row write, before COMMIT'],
	['kill_before_commit', 'connection lost right before COMMIT']
]) {
	test(what, async (t) => {
		await wp.reset(); /* not in beforeEach: that also runs before subtests */
		const token = id();
		const rid = id();
		await wp.setFault(fault);
		const first = await wp.registerPrint(token, rid);
		const s = await wp.state();
		t.diagnostic('first answer: ' + first.text.slice(0, 160) + ' | counter ' + s.print_count + ', charge rows ' + s.charges + ', request rows ' + s.requests);
		assert.strictEqual(s.fault, '', 'the fault fired (disarms itself)');
		assert.ok(s.print_count <= 1 && s.charges <= 1, 'never charged twice');
		await afterFirstAttempt(t, token, rid, first);
	});
}

test('COMMIT went through, the answer never reached the browser', async (t) => {
	await wp.reset();
	const token = id();
	const rid = id();
	await wp.setFault('stall_after_commit:4000');
	const ac = new AbortController();
	const pending = wp.registerPrint(token, rid, { signal: ac.signal }).catch((e) => ({ aborted: e.name }));
	const s = await waitFor((x) => x.print_count === 1 && x.charges === 1, 4000);
	assert.strictEqual(s.print_count, 1, 'committed before the browser gave up');
	ac.abort();
	const first = await pending;
	assert.ok(first.aborted, 'the browser got no answer');
	/* Let the stalled PHP request finish before the retry. */
	await new Promise((r) => setTimeout(r, 4200));
	await afterFirstAttempt(t, token, rid, null);
});

/* 1.27.0 (php-core, round 3): a lost answer on the LAST free reprint. The
   retry of that same press later in the window (here 61 s) is still answered
   from its record — free, prints — and one request id is answered at most
   MAX_REPLAYS (2) times; the 3rd replay is refused, neither charged nor printed. */
test('answer lost on the last free reprint → retry after 61 s is free; 3rd replay refused', async () => {
	await wp.reset();
	const token = id();
	assert.strictEqual((await wp.registerPrint(token, id())).json.success, true, 'charge');
	assert.strictEqual((await wp.registerPrint(token, id())).json.data.already_recorded, true, 'free reprint 1');

	/* The last free reprint is recorded, but its answer is lost (the
	   browser never sees it; stall_after_commit only covers the charge
	   transaction, so the loss is simulated by ignoring the answer). */
	const rid = id();
	const lost = await wp.registerPrint(token, rid);
	assert.strictEqual(lost.json.data.free_reprints_left, 0, 'the last free reprint was recorded');

	await wp.testPost('age', { seconds: 61 });
	const retry = await wp.registerPrint(token, rid);
	assert.strictEqual(retry.json && retry.json.success, true, retry.text.slice(0, 300));
	assert.strictEqual(retry.json.data.already_recorded, true, 'free, prints');
	assert.strictEqual((await wp.state()).print_count, 1, 'not charged');

	assert.strictEqual((await wp.registerPrint(token, rid)).json.success, true, '2nd replay answered');
	const third = await wp.registerPrint(token, rid);
	assert.strictEqual(third.json && third.json.data && third.json.data.replay_limit, true, '3rd replay refused: ' + third.text.slice(0, 200));
	assert.strictEqual((await wp.state()).print_count, 1, 'never charged');
});
