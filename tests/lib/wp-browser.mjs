/* Chromium helpers for the wp-integration browser scripts. TEST-ONLY. */
import { chromium } from 'playwright-core';
import env from './env.js';

export const { BASE, USERS } = env;

export function launch(opts) {
	return chromium.launch(Object.assign({ executablePath: env.chromiumPath() }, opts || {}));
}

/* user: 'free' | 'pro' | { login, pass } */
export async function login(page, user) {
	const u = typeof user === 'string' ? USERS[user] : user;
	const ctx = page.context();
	if (!ctx.__pdOffline) {
		/* Only the test site: gravatar/emoji requests through a proxy can
		   stall the load event for many seconds. */
		ctx.__pdOffline = true;
		await ctx.route((url) => !String(url).startsWith(BASE) && !/^(data|blob|about):/.test(String(url)), (r) => r.abort());
	}
	/* The login page's own scripts (password toggle, strength meter) must
	   have run before typing: a click during their set-up was seen, about
	   once in 40 runs, to send no request at all. So: wait for "load", and
	   try once more if the form did not go. */
	for (let attempt = 1; ; attempt++) {
		await page.goto(BASE + '/wp-login.php?redirect_to=' + encodeURIComponent(BASE + '/'), { waitUntil: 'load' });
		await page.fill('#user_login', u.login);
		await page.fill('#user_pass', u.pass);
		try {
			await Promise.all([page.waitForURL((url) => !/wp-login\.php/.test(String(url)), { waitUntil: 'domcontentloaded', timeout: 15000 }), page.click('#wp-submit')]);
			return;
		} catch (e) {
			if (attempt >= 3) {
				throw e;
			}
			console.log('# login as ' + u.login + ' did not complete (attempt ' + attempt + '), retrying');
		}
	}
}

/* Log in, open the front page and the PlanDose modal; waits for selector. */
export async function openTool(page, user, selector, state) {
	await login(page, user);
	await page.goto(BASE + '/');
	await page.click('#plandose-trigger');
	await page.waitForSelector(selector || '#pd-drug', { state: state || 'visible', timeout: 15000 });
}

export function checker() {
	const c = { fails: 0 };
	c.check = (ok, what) => {
		console.log((ok ? 'ok     ' : 'NOT OK ') + what);
		if (!ok) {
			c.fails++;
		}
	};
	c.done = () => {
		console.log(c.fails ? 'FAILED: ' + c.fails : 'ALL OK');
		process.exit(c.fails ? 1 : 0);
	};
	return c;
}
