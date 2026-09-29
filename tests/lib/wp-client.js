/* HTTP client for the wp-integration tests: logs in, calls the PlanDose AJAX
   endpoints and the pd-test-mu.php helpers. TEST-ONLY. */
'use strict';
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const env = require('./env.js');

class WpClient {
	constructor(user) {
		this.user = typeof user === 'string' ? env.USERS[user] : (user || env.USERS.free);
		this.cookies = { wordpress_test_cookie: 'WP%20Cookie%20check' };
		this.nonce = '';
		this.testNonce = '';
	}

	cookieHeader() {
		return Object.entries(this.cookies).map(([k, v]) => k + '=' + v).join('; ');
	}

	keep(res) {
		for (const c of res.headers.getSetCookie()) {
			const [pair] = c.split(';');
			const i = pair.indexOf('=');
			this.cookies[pair.slice(0, i)] = pair.slice(i + 1);
		}
	}

	async login() {
		const res = await fetch(env.BASE + '/wp-login.php', {
			method: 'POST', redirect: 'manual',
			headers: { cookie: this.cookieHeader(), 'content-type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ log: this.user.login, pwd: this.user.pass, testcookie: '1', 'wp-submit': 'Log In' })
		});
		this.keep(res);
		if (res.status !== 302) {
			throw new Error('login as ' + this.user.login + ' failed: HTTP ' + res.status);
		}
		const n = await this.testGet('nonce');
		if (!n.nonce || !n.test_nonce) {
			throw new Error('pd-test-mu.php not active or ' + this.user.login + ' not in PLANDOSE_TEST_USERS: ' + JSON.stringify(n));
		}
		this.nonce = n.nonce;
		this.testNonce = n.test_nonce;
		return this;
	}

	/* pd-test-mu.php read-only action. */
	async testGet(action) {
		const res = await fetch(env.BASE + '/?pd_test=' + encodeURIComponent(action), { headers: { cookie: this.cookieHeader() }, redirect: 'manual' });
		return res.json();
	}

	/* pd-test-mu.php state-changing action (POST + nonce). */
	async testPost(action, fields) {
		const body = new URLSearchParams(Object.assign({ _pd_test_nonce: this.testNonce }, fields || {}));
		const res = await fetch(env.BASE + '/?pd_test=' + encodeURIComponent(action), {
			method: 'POST', body, headers: { cookie: this.cookieHeader() }, redirect: 'manual'
		});
		const json = await res.json();
		if (json && json.error) {
			throw new Error('pd_test=' + action + ': ' + json.error);
		}
		return json;
	}

	state() {
		return this.testGet('state');
	}

	reset() {
		return this.testPost('reset');
	}

	setFault(value) {
		return this.testPost('fault', { value: value || '' });
	}

	/* The real print-charge AJAX call. opts.signal aborts the request. */
	async registerPrint(token, requestId, opts) {
		const body = new URLSearchParams({ action: 'plandose_register_print', nonce: this.nonce, token });
		if (requestId) {
			body.set('request_id', requestId);
		}
		const res = await fetch(env.BASE + '/wp-admin/admin-ajax.php', {
			method: 'POST', body, headers: { cookie: this.cookieHeader() }, signal: opts && opts.signal
		});
		const text = await res.text();
		let json = null;
		try { json = JSON.parse(text); } catch (e) { /* keep text */ }
		return { status: res.status, json, text };
	}
}

function wpCli(args) {
	return execFileSync(env.WP_CLI, args, { encoding: 'utf8' }).trim();
}

/* A throw-away «Φαρμακείο» account (login pdt_<random>, allowed by
   PLANDOSE_TEST_USERS 'pdt_*'), so parallel runs never share a counter.
   Needs wp-cli (PD_WP_CLI). opts.pro grants Pro for 30 days.
   Returns { login, pass, id, remove() }. */
function tempUser(opts) {
	const login = 'pdt_' + crypto.randomBytes(5).toString('hex');
	const pass = crypto.randomBytes(12).toString('hex');
	const uid = parseInt(wpCli(['user', 'create', login, login + '@example.test', '--role=subscriber', '--user_pass=' + pass, '--porcelain']), 10);
	wpCli(['user', 'meta', 'update', String(uid), 'account_type', 'Φαρμακείο']);
	if (opts && opts.pro) {
		wpCli(['eval', 'Plandose_Subscriptions::grant_pro_days_if_unchanged(' + uid + ', 30, Plandose_Subscriptions::state_token( Plandose_Subscriptions::get_row(' + uid + ') ) );']);
	}
	return {
		login, pass, id: uid,
		remove() {
			try {
				wpCli(['eval', 'if ( class_exists( "Plandose_Print_Charges" ) ) { Plandose_Print_Charges::delete_for_user(' + uid + '); }']);
				wpCli(['user', 'delete', String(uid), '--yes']);
			} catch (e) { /* best effort */ }
		}
	};
}

const id = () => crypto.randomBytes(16).toString('hex');
const md5 = (s) => crypto.createHash('md5').update(s).digest('hex');

module.exports = { WpClient, tempUser, wpCli, id, md5, env };
