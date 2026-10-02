// PN Chat end-to-end in a real browser, against the WordPress from setup-wp.sh.
//   node pn-chat/tests/e2e.mjs
// Env: PNCHAT_BASE [http://127.0.0.1:8898], PNCHAT_WP_PATH [/tmp/pnchat-wp],
//      CHROMIUM (executable; default: playwright's own), PNCHAT_SHOTS (folder for screenshots).
import { chromium } from 'playwright-core';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';

const BASE = process.env.PNCHAT_BASE || 'http://127.0.0.1:8898';
const WP = process.env.PNCHAT_WP_PATH || '/tmp/pnchat-wp';
const SHOTS = process.env.PNCHAT_SHOTS || '';
const MAIL = `${WP}/wp-content/mail.log`;

let fails = 0;
function check(label, cond, extra = '') {
	console.log(`${cond ? 'PASS' : 'FAIL'} ${label}${cond ? '' : ' ' + extra}`);
	if (!cond) fails++;
}
function mails() {
	return existsSync(MAIL) ? readFileSync(MAIL, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : [];
}
async function shot(page, name) {
	if (SHOTS) {
		mkdirSync(SHOTS, { recursive: true });
		await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
	}
}

const launch = { headless: true };
if (process.env.CHROMIUM) launch.executablePath = process.env.CHROMIUM;
const browser = await chromium.launch(launch);
const errors = [];

// ---- visitor on a phone ------------------------------------------------------------
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
const p = await phone.newPage();
p.on('pageerror', (e) => errors.push(e.message));
await p.goto(BASE + '/');
const launcher = p.locator('.pnchat__launcher');
check('launcher visible', await launcher.isVisible());
await shot(p, '1-phone-closed');
await launcher.click();
const panel = p.locator('.pnchat__panel');
check('panel opens', await panel.isVisible());
const box = await panel.boundingBox();
check('panel is full screen on a phone', box && box.width === 390 && box.x === 0, JSON.stringify(box));
check('welcome shown', (await p.locator('.pnchat__msg--bot').first().innerText()).includes('Γεια σας'));
check('suggestion chips', (await p.locator('.pnchat__chip').count()) === 3);
check('input is 16px (no iOS zoom)', (await p.locator('.pnchat__input').evaluate((n) => getComputedStyle(n).fontSize)) === '16px');
await shot(p, '2-phone-open');

await p.locator('.pnchat__chip').first().click();
await p.locator('.pnchat__answer').first().waitFor();
check('chip asks and answers', (await p.locator('.pnchat__answer').first().innerText()).includes('πλάνα δοσολογίας'));

await p.fill('.pnchat__input', 'Τι είναι το PlanDose και ποιοι μπορούν να το χρησιμοποιήσουν;');
await p.keyboard.press('Enter');
await p.waitForFunction(() => document.querySelectorAll('.pnchat__answer').length >= 3);
const titles = await p.locator('.pnchat__answer-title').allInnerTexts();
check('combined answer shows both titles', titles.includes('Τι είναι το PlanDose') && titles.includes('Ποιοι έχουν πρόσβαση'), JSON.stringify(titles));
await shot(p, '3-phone-combined');

await p.fill('.pnchat__input', 'Τι δόση depon να πάρω;');
await p.keyboard.press('Enter');
await p.locator('.pnchat__answer--block').waitFor();
check('refusal shown', (await p.locator('.pnchat__answer--block').innerText()).includes('Δεν μπορούμε'));

const q = 'Μπορώ να πληρώσω με κάρτα;';
await p.fill('.pnchat__input', q);
await p.keyboard.press('Enter');
await p.locator('.pnchat__email').waitFor();
check('unknown -> fallback text', (await p.locator('.pnchat__bubble--notice').last().innerText()).includes('Αφήστε το e-mail σας'));
await shot(p, '4-phone-email-form');
const before = mails().length;
await p.fill('.pnchat__email input[type=email]', 'farmakeio@example.gr');
await p.fill('.pnchat__email input[autocomplete=name]', 'Φαρμακείο Δοκιμή');
await p.click('.pnchat__email .pnchat__btn');
await p.locator('.pnchat__bubble--ok').waitFor();
check('email accepted', (await p.locator('.pnchat__bubble--ok').innerText()).includes('farmakeio@example.gr'));
check('admin notified', mails().length === before + 1);

// No trained answer, but a page of the site has it (setup-wp.sh creates it).
await p.fill('.pnchat__input', 'Ποιο είναι το ωράριο των φαρμακείων τον Αύγουστο;');
await p.keyboard.press('Enter');
await p.locator('.pnchat__answer--site').waitFor();
const siteCard = p.locator('.pnchat__answer--site').first();
check('site page shown with title and link', (await siteCard.innerText()).includes('Ωράριο φαρμακείων το καλοκαίρι') && (await siteCard.locator('a').count()) === 1);
await shot(p, '4b-phone-site-search');

await p.reload();
check('conversation survives page change', (await p.locator('.pnchat__msg--user').count()) === 5);
await p.keyboard.press('Escape');
check('Esc closes', !(await panel.isVisible()));
await phone.close();

// ---- desktop with the PlanDose button in the same corner ---------------------------
const pc = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const d = await pc.newPage();
d.on('pageerror', (e) => errors.push(e.message));
// Same size and place as PlanDose's #plandose-trigger (plandose-trigger.css).
await d.addInitScript(() => {
	document.addEventListener('DOMContentLoaded', () => {
		const b = document.createElement('button');
		b.id = 'plandose-trigger';
		b.textContent = 'PlanDose';
		b.style.cssText = 'position:fixed;bottom:24px;right:24px;height:56px;min-width:138px;z-index:9998';
		document.body.appendChild(b);
	});
});
await d.goto(BASE + '/');
await d.waitForTimeout(800);
const chatBox = await d.locator('.pnchat__launcher').boundingBox();
const pdBox = await d.locator('#plandose-trigger').boundingBox();
check('chat button sits above the PlanDose button', chatBox.y + chatBox.height <= pdBox.y && Math.abs((chatBox.x + chatBox.width) - (pdBox.x + pdBox.width)) <= 1, JSON.stringify({ chatBox, pdBox }));
check('subtitle says «για την PharmacyNeeds»', (await d.locator('.pnchat__subtitle').count()) === 1 && (await d.locator('.pnchat__subtitle').textContent()).includes('για την PharmacyNeeds'));
check('no note under the input', (await d.locator('.pnchat__privacy').count()) === 0);
await shot(d, '0-desktop-with-plandose');

// Close button: black circle with a red ×, even under theme CSS that restyles buttons and icons.
await d.addStyleTag({ content: 'button{background:transparent;color:inherit;padding:20px;border:0} svg{display:none} svg path{fill:currentColor;stroke:none}' });
await d.locator('.pnchat__launcher').click({ force: true });
const close = d.locator('.pnchat__close');
const cs = await close.evaluate((n) => {
	const b = getComputedStyle(n);
	const icon = n.querySelector('svg');
	const ic = getComputedStyle(icon);
	const path = getComputedStyle(icon.querySelector('path'));
	const r = icon.getBoundingClientRect();
	return { bg: b.backgroundColor, display: ic.display, stroke: path.stroke, w: r.width, h: r.height };
});
check('close button: black background, visible red ×', cs.bg === 'rgb(17, 17, 17)' && cs.display === 'block' && cs.stroke === 'rgb(239, 68, 68)' && cs.w >= 16 && cs.h >= 16, JSON.stringify(cs));
const sendIcon = await d.locator('.pnchat__send svg').boundingBox();
check('send icon survives theme CSS', sendIcon && sendIcon.width >= 16, JSON.stringify(sendIcon));
await shot(d, '0b-desktop-open-close-button');
await close.click();
check('close button closes', !(await d.locator('.pnchat__panel').isVisible()));
await pc.close();

// ---- admin: questions -> training -> e-mail reply ---------------------------------
const desk = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const a = await desk.newPage();
a.on('pageerror', (e) => errors.push(e.message));
await a.goto(BASE + '/wp-login.php');
await a.fill('#user_login', 'admin');
await a.fill('#user_pass', 'admin');
await a.click('#wp-submit');
await a.waitForURL(/wp-admin/);

for (const slug of ['pn-chat', 'pn-chat-questions', 'pn-chat-blocks', 'pn-chat-brain', 'pn-chat-settings']) {
	const res = await a.goto(`${BASE}/wp-admin/admin.php?page=${slug}`);
	const body = await a.content();
	check(`admin page ${slug} loads`, res.ok() && !/Fatal error|Warning:|Notice:|Deprecated:/.test(body));
}

await a.goto(`${BASE}/wp-admin/admin.php?page=pn-chat-questions&filter=email`);
const row = a.locator('tr', { hasText: q });
check('question waits with e-mail', (await row.count()) === 1 && (await row.innerText()).includes('farmakeio@example.gr'));
await shot(a, '5-admin-questions');
await row.getByRole('link', { name: 'Εκπαίδευση' }).click();
check('training form prefilled', (await a.inputValue('#pnchat-phr')).includes(q));
await a.fill('#pnchat-title', 'Πληρωμή με κάρτα');
await a.locator('#pnchat-answer-html').click().catch(() => {});
await a.evaluate(() => {
	const txt = 'Ναι, δεχόμαστε <strong>όλες τις κάρτες</strong> (παράδειγμα).';
	if (window.tinymce && window.tinymce.get('pnchat-answer')) window.tinymce.get('pnchat-answer').setContent(txt);
	document.getElementById('pnchat-answer').value = txt;
});
await a.click('#submit');
await a.waitForURL(/reply=/);
check('after training: reply screen', (await a.inputValue('#pnchat-body')).includes('όλες τις κάρτες'));
await shot(a, '6-admin-reply');
await a.click('#submit');
await a.waitForURL(/pnchat_msg=replied/);
const last = mails().at(-1);
check('reply e-mail sent to visitor', last && last.to === 'farmakeio@example.gr' && last.message.includes('όλες τις κάρτες'), JSON.stringify(last));

// The brain learned it.
await a.goto(`${BASE}/wp-admin/admin.php?page=pn-chat&test=` + encodeURIComponent('mporw na plirwsw me karta?'));
check('test box: learned answer', (await a.locator('.pnchat-test-result').innerText()).includes('Πληρωμή με κάρτα'));
await shot(a, '7-admin-test');

// Refusal training from a question.
const r = await a.request.post(`${BASE}/?rest_route=/pn-chat/v1/ask`, { data: { question: 'Ποια είναι η τιμή του Depon στο φαρμακείο;' } });
const rid = (await r.json()).id;
await a.goto(`${BASE}/wp-admin/admin.php?page=pn-chat-blocks&new=1&from_question=${rid}`);
await a.fill('#pnchat-kw', 'τιμή φαρμάκου\ndepon');
await a.fill('#pnchat-answer', 'Δεν δίνουμε τιμές φαρμάκων μέσα από το chat.');
await a.click('#submit');
await a.waitForURL(/block_saved/);
const r2 = await (await a.request.post(`${BASE}/?rest_route=/pn-chat/v1/ask`, { data: { question: 'Πόσο κάνει το depon;' } })).json();
check('trained refusal works', r2.status === 'blocked' && r2.items[0].html.includes('Δεν δίνουμε τιμές'), JSON.stringify(r2));

// Brain download.
await a.goto(`${BASE}/wp-admin/admin.php?page=pn-chat-brain`);
const [dl] = await Promise.all([a.waitForEvent('download'), a.click('input[value="Λήψη εγκεφάλου (.json)"]')]);
const brain = JSON.parse(readFileSync(await dl.path(), 'utf8'));
check('brain download', brain.format === 'pn-chat-brain' && brain.entries.some((e) => e.title === 'Πληρωμή με κάρτα') && !brain.questions, dl.suggestedFilename());

// Clean up what this run trained (also tests deleting), so the next run starts the same.
a.on('dialog', (d) => d.accept());
for (const [slug, text] of [['pn-chat', 'Πληρωμή με κάρτα'], ['pn-chat-blocks', 'Ποια είναι η τιμή του Depon']]) {
	await a.goto(`${BASE}/wp-admin/admin.php?page=${slug}&s=` + encodeURIComponent(text));
	const del = a.locator('tr', { hasText: text }).locator('a.pnchat-danger');
	const n = await del.count();
	for (let i = 0; i < n; i++) {
		await a.locator('tr', { hasText: text }).locator('a.pnchat-danger').first().click();
		await a.waitForURL(/pnchat_msg=deleted/);
		await a.goto(`${BASE}/wp-admin/admin.php?page=${slug}&s=` + encodeURIComponent(text));
	}
	check(`deleted test entry on ${slug}`, n >= 1 && (await a.locator('tr', { hasText: text }).count()) === 0);
}
await desk.close();

check('no JavaScript errors', errors.length === 0, JSON.stringify(errors));
await browser.close();
console.log(fails ? `\n${fails} failed` : '\nall passed');
process.exit(fails ? 1 : 0);
