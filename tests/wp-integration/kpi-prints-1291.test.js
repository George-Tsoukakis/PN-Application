/* PlanDose 1.29.1 — the «Εκτυπώσεις Μήνα» KPI counts a print at once.
   Since 1.26.1 a print no longer dropped the KPI cache, so the figure lagged
   by up to 5 minutes (STATS_CACHE_TTL): a pharmacist printed, the admin
   looked, and the number had not moved. Run: npm run test:wp */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, id, wpCli } = require('../lib/wp-client.js');

let temp = null;
let wp = null;
const kpiPrints = () => parseInt(wpCli(['eval', 'echo Plandose_Subscriber_Query::stats()["prints"];']), 10);

test.before(async () => {
	temp = tempUser();
	wp = new WpClient(temp);
	await wp.login();
	await wp.reset();
});

test.after(() => {
	if (temp) {
		try { wpCli(['eval', 'Plandose_Print_Log::delete_for_user(' + temp.id + ');']); } catch (e) { /* best effort */ }
		temp.remove();
	}
});

test('a charged print shows in «Εκτυπώσεις Μήνα» right away, not after the 5-minute cache', async () => {
	/* a first print creates this pharmacy's row (that alone drops the cache) */
	assert.strictEqual((await wp.registerPrint(id(), id())).json.success, true);
	for (let i = 0; i < 3; i++) {
		const before = kpiPrints(); /* primes the cache */
		assert.strictEqual(kpiPrints(), before, 'cached value read back');
		assert.strictEqual((await wp.registerPrint(id(), id())).json.success, true);
		assert.strictEqual(kpiPrints(), before + 1, 'print ' + (i + 2) + ' counted at once');
	}
});

test('a free reprint does not change the figure', async () => {
	const token = id();
	assert.strictEqual((await wp.registerPrint(token, id())).json.success, true);
	const after = kpiPrints();
	assert.strictEqual((await wp.registerPrint(token, id())).json.data.already_recorded, true);
	assert.strictEqual(kpiPrints(), after);
});
