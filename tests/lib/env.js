/* Shared test configuration: every value can be overridden from the
   environment; the defaults match the local test WordPress described in
   tests/README.md (/home/claude/wpenv on http://127.0.0.1:8899). TEST-ONLY. */
'use strict';
const fs = require('fs');
const path = require('path');

const env = process.env;

const TESTS_DIR = path.resolve(__dirname, '..');

const BASE = (env.PD_BASE || 'http://127.0.0.1:8899').replace(/\/+$/, '');

const DB = {
	host: env.PD_DB_HOST || '127.0.0.1',
	port: env.PD_DB_PORT || '',
	user: env.PD_DB_USER || 'wp',
	pass: env.PD_DB_PASS || 'wp',
	name: env.PD_DB_NAME || 'wptest',
	prefix: env.PD_DB_PREFIX || 'wp_'
};

const WP_PATH = env.PD_WP_PATH || '/home/claude/wpenv';
/* wp-cli command for that WordPress (a wrapper that adds --path). */
const WP_CLI = env.PD_WP_CLI || path.join(WP_PATH, 'wp');
const DEBUG_LOG = env.PD_DEBUG_LOG || path.join(WP_PATH, 'debug.log');

/* free: a «Φαρμακείο» account without Pro; pro: one with an active Pro. */
const USERS = {
	free: { login: env.PD_FREE_USER || 'pharm1', pass: env.PD_FREE_PASS || 'pharmpass' },
	pro: { login: env.PD_PRO_USER || 'pharmpro', pass: env.PD_PRO_PASS || 'pharmpass' }
};

/* The plugin's assets/js: PLANDOSE_JS, else the plugin next to tests/
   (tests/ and plandose/ side by side, the repo layout plugin/plandose/,
   or tests/ inside the plugin). */
function findJsDir() {
	const candidates = env.PLANDOSE_JS ? [env.PLANDOSE_JS] : [
		path.join(TESTS_DIR, '..', 'plandose', 'assets', 'js'),
		path.join(TESTS_DIR, '..', 'plugin', 'plandose', 'assets', 'js'),
		path.join(TESTS_DIR, '..', 'assets', 'js')
	];
	return candidates.find((dir) => fs.existsSync(path.join(dir, 'state.js'))) || '';
}

function pluginDir() {
	const js = findJsDir();
	return js ? path.resolve(js, '..', '..') : '';
}

function newestIn(dir, prefix, rel) {
	let names = [];
	try {
		names = fs.readdirSync(dir).filter((n) => n.startsWith(prefix));
	} catch (e) {
		return '';
	}
	names.sort((a, b) => (parseInt(b.slice(prefix.length), 10) || 0) - (parseInt(a.slice(prefix.length), 10) || 0));
	for (const n of names) {
		for (const r of rel) {
			const p = path.join(dir, n, r);
			if (fs.existsSync(p)) {
				return p;
			}
		}
	}
	return '';
}

/* Chromium for playwright-core: PD_CHROMIUM, else (legacy) PW_CHROMIUM,
   else the newest full Chromium / headless shell under
   PLAYWRIGHT_BROWSERS_PATH (default /opt/pw-browsers, then
   ~/.cache/ms-playwright), else a system chromium. undefined lets
   playwright-core use its own registry. */
function chromiumPath() {
	if (env.PD_CHROMIUM) {
		return env.PD_CHROMIUM;
	}
	if (env.PW_CHROMIUM) {
		return env.PW_CHROMIUM;
	}
	const roots = [env.PLAYWRIGHT_BROWSERS_PATH, '/opt/pw-browsers',
		path.join(env.HOME || '/root', '.cache', 'ms-playwright')].filter(Boolean);
	for (const root of roots) {
		const hit = newestIn(root, 'chromium-', ['chrome-linux/chrome', 'chrome-linux64/chrome', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium', 'chrome-win/chrome.exe']) ||
			newestIn(root, 'chromium_headless_shell-', ['chrome-linux/headless_shell', 'chrome-headless-shell-linux64/chrome-headless-shell']);
		if (hit) {
			return hit;
		}
	}
	for (const p of ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome']) {
		if (fs.existsSync(p)) {
			return p;
		}
	}
	return undefined;
}

module.exports = { TESTS_DIR, BASE, DB, WP_PATH, WP_CLI, DEBUG_LOG, USERS, findJsDir, pluginDir, chromiumPath };
