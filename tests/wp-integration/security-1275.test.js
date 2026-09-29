/* PlanDose 1.27.5 — access-control checks against the real test WordPress
   (tests/README.md). Run: npm run test:wp

   - a guest never reaches a PlanDose AJAX handler;
   - a pharmacy with a missing / wrong nonce, or a GET, is refused and
     charged nothing;
   - a logged-in account that is not a «Φαρμακείο» is refused even with a
     valid nonce;
   - one pharmacy's print token is never «already recorded» for another
     (no cross-account replay or free reprint), and never touches the
     other's counter;
   - a pharmacy cannot open, upload or delete invoices (admin-post) — its
     own or another's (IDOR). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { WpClient, tempUser, wpCli, id, env } = require('../lib/wp-client.js');

const AJAX = env.BASE + '/wp-admin/admin-ajax.php';
const ADMIN_POST = env.BASE + '/wp-admin/admin-post.php';

let a = null;
let b = null;
let other = null;
let wa = null;
let wb = null;

test.before(async () => {
	a = tempUser();
	b = tempUser();
	wa = await new WpClient(a).login();
	wb = await new WpClient(b).login();
});

test.after(() => {
	for (const u of [a, b, other]) {
		if (u) {
			u.remove();
		}
	}
});

async function post(client, fields) {
	const res = await fetch(AJAX, {
		method: 'POST',
		body: new URLSearchParams(fields),
		headers: client ? { cookie: client.cookieHeader() } : {},
		redirect: 'manual'
	});
	return { status: res.status, text: await res.text() };
}

test('guest: every PlanDose AJAX action answers «0» (no nopriv handler)', async () => {
	for (const action of ['plandose_check_print', 'plandose_register_print', 'plandose_get_header', 'plandose_refresh_nonce']) {
		const r = await post(null, { action, nonce: 'x', token: id() });
		assert.ok(['0', '-1'].includes(r.text.trim()), action + ' → ' + r.status + ' ' + r.text.slice(0, 120));
	}
});

test('pharmacy: missing or wrong nonce is refused and charges nothing', async () => {
	await wa.reset();
	for (const nonce of ['', 'deadbeef00', wb.nonce]) {
		const r = await post(wa, { action: 'plandose_register_print', nonce, token: id() });
		assert.ok(r.status === 403 || r.text.trim() === '-1', 'nonce «' + nonce + '» → ' + r.status + ' ' + r.text.slice(0, 120));
	}
	assert.strictEqual((await wa.state()).print_count, 0, 'nothing charged');
});

test('pharmacy: a GET to register_print is refused and charges nothing', async () => {
	await wa.reset();
	const res = await fetch(AJAX + '?action=plandose_register_print&nonce=' + encodeURIComponent(wa.nonce) + '&token=' + id(), {
		headers: { cookie: wa.cookieHeader() }, redirect: 'manual'
	});
	const text = await res.text();
	assert.ok(res.status >= 400 || /"success":false/.test(text), 'GET → ' + res.status + ' ' + text.slice(0, 120));
	assert.strictEqual((await wa.state()).print_count, 0, 'nothing charged');
});

test('an account that is not a «Φαρμακείο» is refused even with a valid nonce', async () => {
	other = tempUser();
	wpCli(['user', 'meta', 'update', String(other.id), 'account_type', 'Εταιρία']);
	const wo = await new WpClient(other).login();
	const r = await post(wo, { action: 'plandose_register_print', nonce: wo.nonce, token: id() });
	const json = JSON.parse(r.text);
	assert.strictEqual(json.success, false, r.text.slice(0, 200));
	assert.strictEqual(json.data.not_allowed, true, r.text.slice(0, 200));
});

test('a print token of pharmacy A is never a replay or free reprint for pharmacy B', async () => {
	await wa.reset();
	await wb.reset();
	const token = id();
	const first = await wa.registerPrint(token, id());
	assert.strictEqual(first.json && first.json.success, true, first.text.slice(0, 200));
	const cross = await wb.registerPrint(token, id());
	assert.strictEqual(cross.json && cross.json.success, true, cross.text.slice(0, 200));
	assert.notStrictEqual(cross.json.data.already_recorded, true, 'B must be charged as a new print, not a replay of A');
	assert.strictEqual((await wb.state()).print_count, 1, 'B charged once');
	assert.strictEqual((await wa.state()).print_count, 1, 'A untouched by B');
});

test('a pharmacy cannot view, upload or delete invoices (its own or another pharmacy\'s)', async () => {
	for (const target of [a.id, b.id]) {
		const view = await fetch(ADMIN_POST + '?action=plandose_view_invoice&user_id=' + target + '&i=0&_wpnonce=x', {
			headers: { cookie: wa.cookieHeader() }, redirect: 'manual'
		});
		assert.ok([302, 403].includes(view.status), 'view user ' + target + ' → HTTP ' + view.status);
		assert.ok(!/%PDF-/.test(await view.text()), 'no invoice bytes');

		for (const action of ['plandose_upload_invoice', 'plandose_delete_invoice']) {
			const res = await fetch(ADMIN_POST, {
				method: 'POST',
				body: new URLSearchParams({ action, user_id: String(target), i: '0', _wpnonce: 'x' }),
				headers: { cookie: wa.cookieHeader() }, redirect: 'manual'
			});
			assert.ok([302, 403].includes(res.status), action + ' user ' + target + ' → HTTP ' + res.status);
		}
	}
});
