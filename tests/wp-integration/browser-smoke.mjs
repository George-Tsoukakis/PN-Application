/* Real-browser smoke test (Chromium via playwright-core) against the local
   test WordPress, as the Pro pharmacy (PD_PRO_USER, default pharmpro).
   Run: npm run test:browser. TEST-ONLY. */
import { launch, openTool, checker } from '../lib/wp-browser.mjs';
const browser = await launch();
const page = await browser.newPage();
const errors = [];
/* Only errors from PlanDose itself count: the theme and core scripts are not under test. */
page.on('pageerror', (e) => { if (/plandose|__PlandoseNS|\bPD\./i.test(String(e.stack || '') + e.message)) errors.push('pageerror: ' + e.message); });
page.on('response', (r) => { if (r.status() >= 400 && /plandose/i.test(r.url())) errors.push(r.status() + ' ' + r.url()); });
const { check, done } = checker();

await openTool(page, 'pro', '#pd-drug');
check(true, 'tool loads and opens (lazy loader)');

/* The dictionaries come from their own scripts, not from the page. */
const dict = await page.evaluate(() => ({
	inline: JSON.stringify(window.PlandoseConfig).length,
	el: !!(window.PlandoseI18n && window.PlandoseI18n.el && window.PlandoseI18n.el.modalTitle),
	en: window.__PlandoseNS.dicts.en.modalTitle,
	elTitle: window.__PlandoseNS.dicts.el.modalTitle
}));
check(dict.el && dict.elTitle === 'Δημιουργία Πλάνου Δοσολογίας', 'Greek dictionary loaded by the loader');
check(dict.en === 'Create Dosage Plan', 'Pro: English dictionary loaded by the loader');
check(dict.inline < 4000, 'PlandoseConfig inline without dictionaries (' + dict.inline + ' chars)');

/* Fix 2 in the real form. */
await page.fill('#pd-drug', 'Amoxil 500mg');
await page.fill('#pd-dose-amount', '11/2');
await page.fill('#pd-days', '7');
await page.click('#pd-add');
const msg = await page.evaluate(() => document.body.innerText);
check(/διαβάζεται ως 5,5/.test(msg) && /1 1\/2/.test(msg), 'dose «11/2» refused with the 1 1/2 hint');
check(await page.evaluate(() => window.__PlandoseNS.s.items.length) === 0, 'medicine not added');

await page.fill('#pd-dose-amount', '1 1/2');
await page.click('#pd-add');
check(await page.evaluate(() => window.__PlandoseNS.s.items.length) === 1, '«1 1/2» accepted');

/* Fix 1: start tomorrow, then «Νέος ασθενής», with the real layout engine. */
const res = await page.evaluate(() => {
	const PD = window.__PlandoseNS;
	PD.setStartDate ? PD.setStartDate(PD.isoDateAhead(1)) : (PD.s.startDate = PD.isoDateAhead(1));
	PD.beginDayPass();
	const before = PD.labelStart(PD.s.items[0]);
	const pageBefore = PD.buildLabelsPage ? PD.buildLabelsPage() : '';
	PD.resetForNextPatient('manual');
	const after = PD.labelStart(PD.labelItems()[0]);
	const pageAfter = PD.buildLabelsPage ? PD.buildLabelsPage() : '';
	return { before, after, startNow: PD.s.startDate, hasBefore: /Έναρξη/.test(pageBefore), hasAfter: /Έναρξη/.test(pageAfter), items: PD.s.items.length };
});
check(/Έναρξη/.test(res.before), 'label before: ' + res.before);
check(res.after === res.before && res.startNow === '' && res.items === 0, 'label after Νέος ασθενής unchanged: ' + res.after);
check(res.hasAfter, 'label preview page after reset still shows «Έναρξη»');

/* Fix 3 with a real fixed label size: long notes get cut → first press warns. */
const warn = await page.evaluate(() => {
	const PD = window.__PlandoseNS;
	const item = { name: 'Amoxicillin/Clavulanic acid 875mg/125mg', doseAmount: 1, doseUnit: 'tablet', freq: '6h', days: '10',
		notes: 'ΟΧΙ μαζί με γάλα, γιαούρτι ή ασβέστιο — τουλάχιστον 2 ώρες πριν ή 6 ώρες μετά. Πολλά υγρά. Ολόκληρο το σχήμα.' };
	const presets = PD.LABEL_SIZES.filter((s) => s.id !== 'auto' && s.id !== 'custom');
	const small = presets.sort((a, b) => a.w * a.h - b.w * b.h)[0];
	PD.labelSize = () => small;
	PD.LABEL_ACK_MIN_MS = 0;
	const fit = PD.fitLabel(item, 'Δοκιμή Δοκιμαστοπούλου', small);
	return { size: small.id, fit, warn: PD.labelNotesNeedAck([item], 'Δοκιμή Δοκιμαστοπούλου'), second: PD.labelNotesNeedAck([item], 'Δοκιμή Δοκιμαστοπούλου') };
});
check(warn.fit.ok && warn.fit.trimmed.includes('notes'), 'real layout cuts the notes on ' + warn.size + ': ' + JSON.stringify(warn.fit.trimmed));
check(/Amoxicillin/.test(warn.warn) && warn.second === '', 'first check warns, second goes ahead');

check(errors.length === 0, 'no JS errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
await browser.close();
done();
