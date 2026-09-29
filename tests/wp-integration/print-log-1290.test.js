/* PlanDose 1.29.0 — the admin print log (Plandose_Print_Log), against the
   real test WordPress. One row per print that went through: a charge, or a
   free reprint. Replays of the same request, refused prints and faulted
   (rolled-back) prints write nothing, so every print is listed exactly once.
   Run: npm run test:wp */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, id, wpCli } = require('../lib/wp-client.js');

let temp = null;
let wp = null;

const log = async () => (await wp.state()).log_rows;
const kinds = (rows) => rows.map((r) => r.kind);

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
		try { wpCli(['eval', 'Plandose_Print_Log::delete_for_user(' + temp.id + ');']); } catch (e) { /* best effort */ }
		temp.remove();
	}
});

test.beforeEach(async () => {
	await wp.reset();
});

test('a charged print is logged once, with the monthly count; its replays are not logged', async () => {
	const token = id();
	const rid = id();
	assert.strictEqual((await wp.registerPrint(token, rid)).json.success, true);
	let rows = await log();
	assert.deepStrictEqual(kinds(rows), ['charge']);
	assert.strictEqual(Number(rows[0].month_count), 1);
	assert.strictEqual(Number(rows[0].is_pro), 0);
	assert.ok(Math.abs(Number(rows[0].printed_ts) - Math.floor(Date.now() / 1000)) < 120, 'printed now');

	/* the same request again (lost answer): answered from the record */
	assert.strictEqual((await wp.registerPrint(token, rid)).json.data.already_recorded, true);
	rows = await log();
	assert.deepStrictEqual(kinds(rows), ['charge'], 'a replay adds nothing');
});

test('«Εκτύπωση ξανά» within the free reprints is logged as a reprint; past them, as a new charge', async () => {
	const token = id();
	assert.strictEqual((await wp.registerPrint(token, id())).json.success, true, 'charge');
	assert.strictEqual((await wp.registerPrint(token, id())).json.data.already_recorded, true, 'free reprint 1');
	assert.strictEqual((await wp.registerPrint(token, id())).json.data.already_recorded, true, 'free reprint 2');
	const third = await wp.registerPrint(token, id());
	assert.strictEqual(third.json.success, true, 'charged as a new print');
	const rows = await log();
	assert.deepStrictEqual(kinds(rows), ['charge', 'reprint', 'reprint', 'charge']);
	assert.strictEqual(Number(rows[3].month_count), 2);
	assert.strictEqual((await wp.state()).print_count, 2, 'the log agrees with the counter');
});

test('concurrent prints: exactly one log row per charge', async () => {
	const results = await Promise.all(Array.from({ length: 6 }, () => wp.registerPrint(id(), id())));
	const ok = results.filter((r) => r.json && r.json.success).length;
	const s = await wp.state();
	const charges = s.log_rows.filter((r) => r.kind === 'charge').length;
	assert.strictEqual(charges, s.print_count, 'log charges = counter');
	assert.strictEqual(charges, ok, 'log charges = successful answers');
});

test('a print rolled back by a lost connection is not logged; its retry is logged once', async () => {
	const token = id();
	const rid = id();
	await wp.setFault('kill_before_commit');
	const first = await wp.registerPrint(token, rid);
	const s1 = await wp.state();
	if (first.json && first.json.success) {
		/* the server confirmed the commit after all: one charge, one row */
		assert.strictEqual(s1.log_rows.length, s1.print_count);
	} else {
		assert.strictEqual(s1.log_rows.length, 0, 'nothing charged, nothing logged');
	}
	await wp.setFault('');
	const retry = await wp.registerPrint(token, rid);
	assert.strictEqual(retry.json && retry.json.success, true, retry.text.slice(0, 200));
	const s2 = await wp.state();
	assert.strictEqual(s2.print_count, 1, 'charged once');
	assert.strictEqual(s2.log_rows.filter((r) => r.kind === 'charge').length, 1, 'logged once');
});

test('without the log table a print still goes through, with a clean JSON answer', async () => {
	const t = wpCli(['eval', 'echo Plandose_Print_Log::table_name();']);
	wpCli(['db', 'query', 'RENAME TABLE ' + t + ' TO ' + t + '_pdt_away']);
	try {
		const r = await wp.registerPrint(id(), id());
		assert.strictEqual(r.json && r.json.success, true, r.status + ' ' + r.text.slice(0, 300));
		assert.strictEqual((await wp.state()).print_count, 1, 'charged');
	} finally {
		wpCli(['db', 'query', 'RENAME TABLE ' + t + '_pdt_away TO ' + t]);
	}
});
