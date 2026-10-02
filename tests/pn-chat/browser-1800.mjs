/*
 * PN Chat 1.8.0+ in Chromium (TEST-ONLY): the e-mail form and «Σας βοήθησε;»
 * survive opening another page, Greek network errors, Tab kept inside the
 * full-screen chat; 1.8.2: suggestions right under the welcome, groups
 * open when they fit. Needs the test WordPress served at PN_BASE with PN Chat
 * active (floating button), and playwright-core from tests/node_modules.
 *   PN_BASE=http://127.0.0.1:8898 node pn-chat/browser-1800.mjs
 */
import { chromium } from 'playwright-core';

const BASE = process.env.PN_BASE || 'http://127.0.0.1:8898';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
let fails = 0;
let passes = 0;
function check(ok, what) {
	if (ok) { passes++; console.log('ok     ' + what); } else { fails++; console.log('NOT OK ' + what); }
}

async function openChat(page) {
	await page.waitForSelector('.pnchat__panel', { state: 'attached', timeout: 15000 }).catch(async () => {
		console.log('# no chat on ' + page.url() + ': ' + (await page.evaluate(() => document.body.innerHTML.slice(0, 300))));
	});
	if (await page.locator('.pnchat__panel').isHidden()) {
		await page.click('.pnchat__launcher');
	}
}
async function ask(page, q) {
	const n = await page.locator('.pnchat__msg--bot').count();
	await page.fill('.pnchat__input', q);
	await page.press('.pnchat__input', 'Enter');
	await page.waitForFunction((k) => document.querySelectorAll('.pnchat__msg--bot:not(.pnchat__typing)').length > k, n, { timeout: 30000 });
}

try {
	const ctx = await browser.newContext();
	await ctx.route((url) => !String(url).startsWith(BASE), (r) => r.abort());
	const page = await ctx.newPage();
	page.on('pageerror', (e) => check(false, 'no page error: ' + e.message));

	// ---- e-mail form after another page -------------------------------------
	await page.goto(BASE + '/', { waitUntil: 'load' });
	await openChat(page);
	await ask(page, 'Πού βρίσκεται το κατάστημα στη Λάρισα;');
	check(await page.locator('.pnchat__email').count() === 1, 'unanswered: the e-mail form is shown');
	await page.goto(BASE + '/?p=1', { waitUntil: 'load' });
	await openChat(page);
	check(await page.locator('.pnchat__email').count() === 1, 'another page: the e-mail form is still there (was lost in 1.7.0)');
	await page.fill('.pnchat__email input[type=email]', 'browser@example.test');
	await page.click('.pnchat__email button[type=submit]');
	await page.waitForSelector('.pnchat__bubble--ok', { timeout: 15000 });
	check(/browser@example\.test/.test(await page.locator('.pnchat__bubble--ok').innerText()), 'the restored form sends the e-mail (token kept)');
	await page.reload({ waitUntil: 'load' });
	await openChat(page);
	check(await page.locator('.pnchat__email').count() === 0, 'after sending: the form does not come back');

	// ---- feedback after another page -----------------------------------------
	await ask(page, 'Γεια σας');
	const rows = await page.locator('.pnchat__feedback').count();
	check(rows === 1, '«Σας βοήθησε;» shown');
	await page.reload({ waitUntil: 'load' });
	await openChat(page);
	check(await page.locator('.pnchat__feedback').count() === 1, 'another page: «Σας βοήθησε;» still there');
	await page.click('.pnchat__feedback .pnchat__vote >> nth=0');
	await page.waitForSelector('.pnchat__feedback-done');
	await page.reload({ waitUntil: 'load' });
	await openChat(page);
	check(await page.locator('.pnchat__feedback').count() === 0, 'after voting: it does not come back');

	// ---- network error in Greek ------------------------------------------------
	await page.route('**/pn-chat/v1/ask', (r) => r.abort('internetdisconnected'));
	await ask(page, 'Δοκιμή χωρίς δίκτυο');
	const last = await page.locator('.pnchat__msg--bot').last().innerText();
	check(/σύνδεση/.test(last) && !/Failed to fetch/.test(last), 'no network: Greek message («' + last.trim() + '»)');
	await page.unroute('**/pn-chat/v1/ask');
	await ctx.close();

	// ---- 1.8.2: suggestions right under the welcome, groups open if they fit ----
	async function layout(viewport, mobile) {
		const c = await browser.newContext(Object.assign({ viewport }, mobile ? { isMobile: true, hasTouch: true } : {}));
		await c.route((url) => !String(url).startsWith(BASE), (r) => r.abort());
		const pg = await c.newPage();
		await pg.goto(BASE + '/', { waitUntil: 'load' });
		await pg.click('.pnchat__launcher');
		await pg.waitForTimeout(300);
		const m = await pg.evaluate(() => {
			const welcome = document.querySelector('.pnchat__msg--bot').getBoundingClientRect();
			const chips = document.querySelector('.pnchat__chips');
			return {
				gap: chips.getBoundingClientRect().top - welcome.bottom,
				open: [...document.querySelectorAll('.pnchat__group')].map((h) => h.getAttribute('aria-expanded') === 'true'),
				scrolls: chips.scrollHeight > chips.clientHeight + 1
			};
		});
		return { c, pg, m };
	}
	for (const [label, vp, mobile] of [['desktop', { width: 1100, height: 760 }, false], ['phone', { width: 390, height: 780 }, true]]) {
		const { c, m } = await layout(vp, mobile);
		check(m.gap < 30, label + ': the suggestions start right under the welcome (gap ' + Math.round(m.gap) + 'px)');
		check(m.open.length > 1 && m.open.every(Boolean) && !m.scrolls, label + ': every group open, as they all fit');
		await c.close();
	}
	{
		const { c, pg, m } = await layout({ width: 1100, height: 480 }, false);
		check(m.open[0] && m.open.slice(1).every((o) => !o), 'low window: only the first group open (the rest do not fit)');
		check(m.gap < 30, 'low window: still no gap under the welcome');
		await ask(pg, 'Γεια σας');
		const logGrows = await pg.evaluate(() => getComputedStyle(document.querySelector('.pnchat__log')).flexGrow === '1' && document.querySelector('.pnchat__chips').hidden);
		check(logGrows, 'after a question: the conversation takes the space, suggestions behind «Συχνές ερωτήσεις»');
		await pg.click('.pnchat__topics');
		check(await pg.locator('.pnchat__chips').isVisible(), '«Συχνές ερωτήσεις» brings the suggestions back');
		pg.once('dialog', (d) => d.accept());
		await pg.click('.pnchat__new');
		const back = await pg.evaluate(() => document.querySelector('.pnchat__panel').classList.contains('pnchat__panel--start') && !document.querySelector('.pnchat__chips').hidden);
		check(back, '«Νέα συζήτηση»: suggestions under the welcome again');
		await c.close();
	}

	// ---- phone: Tab stays inside the full-screen chat ---------------------------
	const phone = await browser.newContext({ viewport: { width: 390, height: 780 }, isMobile: true, hasTouch: true });
	await phone.route((url) => !String(url).startsWith(BASE), (r) => r.abort());
	const p2 = await phone.newPage();
	await p2.goto(BASE + '/', { waitUntil: 'load' });
	await p2.click('.pnchat__launcher');
	check(await p2.getAttribute('.pnchat__panel', 'aria-modal') === 'true', 'phone: the full-screen chat is aria-modal');
	let inside = true;
	for (let i = 0; i < 25; i++) {
		await p2.keyboard.press(i % 7 === 6 ? 'Shift+Tab' : 'Tab');
		inside = inside && await p2.evaluate(() => !!document.activeElement.closest('.pnchat__panel'));
	}
	check(inside, 'phone: 25 Tab presses never leave the chat');
	await phone.close();
} finally {
	await browser.close();
}
console.log('\n' + passes + ' passed, ' + fails + ' failed');
process.exit(fails ? 1 : 0);
