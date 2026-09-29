/* PlanDose 1.27.0 — php-admin HTTP checks against the test WordPress
   (tests/README.md). TEST-ONLY.
   Run: node --test --test-concurrency=1 tests/php/admin/diagnostics-http-1270.test.js

   - PlanDose → Διαγνωστικά renders for an administrator (PASS/WARN/FAIL
     rows, «Επανέλεγχος» button) and is refused (403) for a pharmacist;
   - «Επανέλεγχος» (POST + nonce) runs the live probe: the PHP built-in
     server ignores .htaccess, so the default folder is found PUBLIC;
   - the reprobe refuses GET (405) and a missing nonce;
   - 1.27.5: an upload into the PUBLIC default folder is refused on this
     non-production site too; with unverified storage (loopback blocked)
     it is accepted with the storage warning, and the stored file name is
     audited (L7);
   - with every outgoing request answered 403 (pd_test_block_loopback, a
     firewall / blocked loopback) «Επανέλεγχος» gives «unverified», never
     «private», and in production Διαγνωστικά shows FAIL;
   - with the production switch (lib/pd-prod-switch-mu.php, symlinked into
     mu-plugins, option pd_test_force_production = 1) the same upload into
     the public default folder is REFUSED with the problem + fix message,
     and Διαγνωστικά shows FAIL with the fix hint. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const { tempUser, wpCli, env } = require('../../lib/wp-client.js');

const MU_SRC = path.join(__dirname, 'lib', 'pd-prod-switch-mu.php');
const MU_DST = path.join(env.WP_PATH, 'wp-content', 'mu-plugins', 'pd-prod-switch-mu.php');

test.before(() => {
	if (!fs.existsSync(MU_DST)) {
		fs.symlinkSync(MU_SRC, MU_DST);
	}
	for (const o of ['pd_test_force_production', 'pd_test_block_loopback']) {
		try { wpCli(['option', 'delete', o]); } catch (e) { /* not set */ }
	}
});

test.after(() => {
	for (const o of ['pd_test_force_production', 'pd_test_block_loopback']) {
		try { wpCli(['option', 'delete', o]); } catch (e) { /* not set */ }
	}
});

class Session {
	constructor(login, pass) {
		this.login = login;
		this.pass = pass;
		this.cookies = { wordpress_test_cookie: 'WP%20Cookie%20check' };
	}
	header() {
		return Object.entries(this.cookies).map(([k, v]) => k + '=' + v).join('; ');
	}
	keep(res) {
		for (const c of res.headers.getSetCookie()) {
			const [pair] = c.split(';');
			const i = pair.indexOf('=');
			this.cookies[pair.slice(0, i)] = pair.slice(i + 1);
		}
	}
	async signIn() {
		const res = await fetch(env.BASE + '/wp-login.php', {
			method: 'POST', redirect: 'manual',
			headers: { cookie: this.header(), 'content-type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ log: this.login, pwd: this.pass, testcookie: '1', 'wp-submit': 'Log In' })
		});
		this.keep(res);
		assert.strictEqual(res.status, 302, 'login as ' + this.login);
		return this;
	}
	async get(path) {
		const res = await fetch(env.BASE + path, { headers: { cookie: this.header() }, redirect: 'manual' });
		return { status: res.status, text: await res.text(), location: res.headers.get('location') };
	}
	async post(path, body, referer) {
		const headers = { cookie: this.header() };
		if (referer) {
			headers.referer = env.BASE + referer;
		}
		const res = await fetch(env.BASE + path, { method: 'POST', body, headers, redirect: 'manual' });
		return { status: res.status, text: await res.text(), location: res.headers.get('location') };
	}
}

const ADMIN = { login: process.env.PD_ADMIN_USER || 'admin', pass: process.env.PD_ADMIN_PASS || 'admin' };
const DIAG = '/wp-admin/admin.php?page=plandose-diagnostics';

function nonceIn(html, action) {
	const form = html.split('<form').find((f) => f.includes('value="' + action + '"'));
	const m = form && form.match(/name="_wpnonce" value="([a-f0-9]+)"/);
	return m ? m[1] : '';
}

test('diagnostics page renders for an administrator', async () => {
	const admin = await new Session(ADMIN.login, ADMIN.pass).signIn();
	const r = await admin.get(DIAG);
	assert.strictEqual(r.status, 200);
	assert.match(r.text, /pd-diag-status is-(pass|warn|fail)/);
	assert.match(r.text, /plandose_diagnostics_reprobe/, 'site admin sees the reprobe button');
	for (const section of ['Προγραμματισμένες εργασίες', 'Αποθήκευση τιμολογίων', 'Βάση δεδομένων', 'Συμβατότητα με cache', 'Περιβάλλον']) {
		assert.ok(r.text.includes(section), 'section ' + section);
	}
	assert.ok(!/Fatal error|Warning:|Notice:/.test(r.text), 'no PHP errors in the page');
});

test('diagnostics page is refused (403) for a pharmacist', async () => {
	const ph = await new Session(env.USERS.free.login, env.USERS.free.pass).signIn();
	const r = await ph.get(DIAG);
	assert.strictEqual(r.status, 403);
	const p = await ph.post('/wp-admin/admin-post.php', new URLSearchParams({ action: 'plandose_diagnostics_reprobe' }));
	assert.notStrictEqual(p.status, 302, 'pharmacist cannot trigger the probe');
});

test('«Επανέλεγχος» probes the real server: default folder on the built-in server is PUBLIC', async () => {
	const admin = await new Session(ADMIN.login, ADMIN.pass).signIn();
	const page = await admin.get(DIAG);
	const nonce = nonceIn(page.text, 'plandose_diagnostics_reprobe');
	assert.ok(nonce, 'nonce in the form');

	const bad = await admin.get('/wp-admin/admin-post.php?action=plandose_diagnostics_reprobe&_wpnonce=' + nonce);
	assert.strictEqual(bad.status, 405, 'GET refused');

	const noNonce = await admin.post('/wp-admin/admin-post.php', new URLSearchParams({ action: 'plandose_diagnostics_reprobe' }), DIAG);
	assert.notStrictEqual(noNonce.status, 302, 'no nonce → refused');

	const r = await admin.post('/wp-admin/admin-post.php', new URLSearchParams({ action: 'plandose_diagnostics_reprobe', _wpnonce: nonce }), DIAG);
	assert.strictEqual(r.status, 302);
	assert.match(r.location, /plandose_probe=1/);

	const after = await admin.get(DIAG + '&plandose_probe=1');
	assert.match(after.text, /HTTP 200/, 'the probe got the canary back');
	assert.match(after.text, /pd-diag-status is-fail">FAIL<\/span><\/td>\s*<th scope="row">Ιδιωτικότητα/, 'privacy row is FAIL');
	const state = JSON.parse(wpCli(['option', 'get', 'plandose_invoice_privacy', '--format=json']));
	assert.strictEqual(state.status, 'public');
	const leftovers = wpCli(['eval', 'echo count( (array) glob( Plandose_Invoice_Storage::invoice_dir() . "/" . Plandose_Invoice_Storage::CANARY_PREFIX . "*" ) );']);
	assert.strictEqual(leftovers, '0', 'canary deleted');
});

test('blocked loopback (every request 403) → «Επανέλεγχος» says unverified, not private', async () => {
	try {
		wpCli(['option', 'update', 'pd_test_block_loopback', '1', '--autoload=no']);
		wpCli(['option', 'update', 'pd_test_force_production', '1', '--autoload=no']);
		const admin = await new Session(ADMIN.login, ADMIN.pass).signIn();
		const page = await admin.get(DIAG);
		const nonce = nonceIn(page.text, 'plandose_diagnostics_reprobe');
		const r = await admin.post('/wp-admin/admin-post.php', new URLSearchParams({ action: 'plandose_diagnostics_reprobe', _wpnonce: nonce }), DIAG);
		assert.strictEqual(r.status, 302);
		const state = JSON.parse(wpCli(['option', 'get', 'plandose_invoice_privacy', '--format=json']));
		assert.strictEqual(state.status, 'unverified', JSON.stringify(state));
		assert.strictEqual(state.reason, 'control_failed');
		const diag = await admin.get(DIAG);
		const row = diag.text.split('<tr>').find((t) => t.includes('Ιδιωτικότητα τιμολογίων'));
		assert.ok(row && row.includes('is-fail">FAIL'), 'production + unverified → FAIL');
		assert.ok(row.includes('firewall'), 'reason explains the refusal proves nothing');
		assert.ok(row.includes('Διόρθωση:'), 'fix hint');
		const left = wpCli(['eval', '$u = wp_get_upload_dir(); echo count( array_merge( (array) glob( $u["basedir"] . "/plandose-probe-*" ), (array) glob( Plandose_Invoice_Storage::invoice_dir() . "/plandose-probe-*" ) ) );']);
		assert.strictEqual(left, '0', 'both canaries deleted');
	} finally {
		for (const o of ['pd_test_block_loopback', 'pd_test_force_production']) {
			try { wpCli(['option', 'delete', o]); } catch (e) { /* gone */ }
		}
		wpCli(['option', 'delete', 'plandose_invoice_privacy']);
	}
});

async function upload(admin, ph, name) {
	const page = await admin.get('/wp-admin/admin.php?page=plandose-subscriptions&s=' + ph.login);
	assert.strictEqual(page.status, 200);
	const nonce = nonceIn(page.text, 'plandose_upload_invoice');
	assert.ok(nonce, 'upload form present');
	const form = new FormData();
	form.set('action', 'plandose_upload_invoice');
	form.set('user_id', String(ph.id));
	form.set('_wpnonce', nonce);
	form.set('plandose_invoice_file', new Blob([Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')], { type: 'application/pdf' }), name);
	const r = await admin.post('/wp-admin/admin-post.php', form, '/wp-admin/admin.php?page=plandose-subscriptions');
	assert.strictEqual(r.status, 302);
	return JSON.parse(wpCli(['eval', '$r = Plandose_Subscriptions::get_row( ' + ph.id + ', false ); echo wp_json_encode( $r ? Plandose_Subscriptions::decode_invoices( $r->invoices ) : array() );']));
}

function cleanup(ph, invoices) {
	if (invoices.length) {
		wpCli(['eval', 'Plandose_Invoice_Storage::delete_invoice_files( json_decode( ' + JSON.stringify(JSON.stringify(invoices)).replace(/^"|"$/g, "'").replace(/\\"/g, '"') + ', true ), Plandose_Invoice_Storage::invoice_dir() );']);
	}
	wpCli(['eval', 'Plandose_Subscriptions::delete_row( ' + ph.id + ' );']);
}

test('PRODUCTION: upload into the public default folder is refused with problem + fix; Διαγνωστικά FAIL with hint', async () => {
	const ph = tempUser();
	try {
		wpCli(['option', 'update', 'pd_test_force_production', '1', '--autoload=no']);
		const admin = await new Session(ADMIN.login, ADMIN.pass).signIn();
		const invoices = await upload(admin, ph, 'timologio.pdf');
		assert.strictEqual(invoices.length, 0, 'nothing stored');
		const leftovers = wpCli(['eval', 'echo count( (array) glob( Plandose_Invoice_Storage::invoice_dir() . "/*.pdf" ) );']);
		assert.strictEqual(leftovers, '0', 'no file (and no canary) left in the folder');

		const next = await admin.get('/wp-admin/admin.php?page=plandose-subscriptions');
		assert.match(next.text, /Το τιμολόγιο δεν αποθηκεύτηκε: ο φάκελος τιμολογίων είναι ανοιχτός στο internet/, 'clear refusal');
		assert.match(next.text, /Λύση: Ζητήστε από τον πάροχο φιλοξενίας/, 'concrete fix');
		assert.match(next.text, /<strong>PlanDose: ο φάκελος τιμολογίων είναι ανοιχτός στο internet, γι&#039;? αυτό τα νέα τιμολόγια δεν αποθηκεύονται\./, 'persistent screen notice');

		const audit = wpCli(['db', 'query', "SELECT COUNT(*) FROM wp_plandose_audit_log WHERE event='invoice_upload_refused_storage' AND target_user_id=" + ph.id, '--skip-column-names']);
		assert.strictEqual(audit, '1', 'refusal audited');

		const diag = await admin.get(DIAG);
		const row = diag.text.split('<tr>').find((t) => t.includes('Ιδιωτικότητα τιμολογίων'));
		assert.ok(row && row.includes('is-fail">FAIL'), 'Διαγνωστικά: privacy row FAIL');
		assert.ok(row.includes('Γι&#039; αυτό τα νέα τιμολόγια δεν αποθηκεύονται') || row.includes("Γι' αυτό τα νέα τιμολόγια δεν αποθηκεύονται"), 'says uploads are refused');
		assert.ok(row.includes('Διόρθωση: Ζητήστε από τον πάροχο φιλοξενίας'), 'fix hint');

		// 1.27.5: switched off, a PUBLIC folder is still refused.
		wpCli(['option', 'delete', 'pd_test_force_production']);
		const still = await upload(admin, ph, 'timologio.pdf');
		assert.strictEqual(still.length, 0, 'public folder refused outside production too (1.27.5)');
	} finally {
		try { wpCli(['option', 'delete', 'pd_test_force_production']); } catch (e) { /* already gone */ }
		ph.remove();
	}
});

test('upload on a non-production site with UNVERIFIED storage: accepted with the storage warning; audit names the stored file', async () => {
	const ph = tempUser();
	try {
		// Every outgoing request refused (a firewall): «unverified», never «public».
		wpCli(['option', 'update', 'pd_test_block_loopback', '1', '--autoload=no']);
		wpCli(['option', 'delete', 'plandose_invoice_privacy']);
		const admin = await new Session(ADMIN.login, ADMIN.pass).signIn();
		const invoices = await upload(admin, ph, 'timologio.js.pdf');
		assert.strictEqual(invoices.length, 1, '«timologio.js.pdf» accepted (L1)');
		const next = await admin.get('/wp-admin/admin.php?page=plandose-subscriptions');
		assert.match(next.text, /notice-warning"><p>Προσοχή: το τιμολόγιο αποθηκεύτηκε, αλλά δεν επιβεβαιώθηκε/, 'storage warning shown once');

		const audit = wpCli(['db', 'query', "SELECT meta FROM wp_plandose_audit_log WHERE event='invoice_upload' AND target_user_id=" + ph.id + ' ORDER BY id DESC LIMIT 1', '--skip-column-names']);
		assert.ok(audit.includes(invoices[0]), 'audit meta has the stored file name: ' + audit);
		cleanup(ph, invoices);
	} finally {
		try { wpCli(['option', 'delete', 'pd_test_block_loopback']); } catch (e) { /* gone */ }
		try { wpCli(['option', 'delete', 'plandose_invoice_privacy']); } catch (e) { /* gone */ }
		ph.remove();
	}
});
