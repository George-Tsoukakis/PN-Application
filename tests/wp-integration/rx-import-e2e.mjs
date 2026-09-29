/* «Επικόλληση συνταγής» end to end in Chromium against the test WordPress,
   as a Free (PD_FREE_USER, default pharm1) and a Pro (PD_PRO_USER, default
   pharmpro) pharmacy, with the real dictionaries. The review flow:

   - a daily medicine needs its time of day, a weekly one its weekday, a
     monthly one its day of the month — chosen in the row;
   - doubtful fields need «Επιβεβαιώνω»;
   - fewer medicines read than listed, or another patient, need a
     confirmation of the whole paste;
   - «Προσθήκη στο πλάνο (N)» stays disabled until all that is settled,
     and NOTHING enters the plan before;
   - what cannot be settled in the row goes to the form, with the
     prescription text shown as «Από τη συνταγή».

   Screenshots go to OUT (default tests/visual/out/rx-import).
   Run: npm run test:browser. TEST-ONLY. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, openTool, checker } from '../lib/wp-browser.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = process.env.OUT || path.join(HERE, '..', 'visual', 'out', 'rx-import');
fs.mkdirSync(OUT, { recursive: true });
const SAMPLES = path.join(HERE, '..', 'rx-samples');
const sample = (p) => fs.readFileSync(path.join(SAMPLES, fs.readdirSync(SAMPLES).find((f) => f.startsWith(p))), 'utf8');
const { check, done } = checker();

/* Prescription pieces as the e-prescription site prints them. */
const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const PATIENT = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\n';
const drug = (name, dose, label) => name + '\n' + (label || 'ΔΟΣΟΛΟΓΙΑ') + ' : ' + dose + '\n' + PRICE;

/* Paste into the drop zone the way a pharmacist does (click, then a real
   paste event carrying the text). */
async function pasteRx(page, text) {
	await page.click('#pd-rx-drop');
	await page.evaluate((t) => {
		const el = document.getElementById('pd-rx-drop');
		const dt = new DataTransfer();
		dt.setData('text/plain', t);
		el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
	}, text);
	await page.waitForSelector('#pd-rx-add, .pd-rx-hint', { timeout: 5000 });
}

/* The plan as [name, dose, unit, freq, time, days, mode, weekday, monthday]. */
const items = (page) => page.evaluate(() => window.__PlandoseNS.s.items.map((i) => [
	i.name, i.doseAmount, i.doseUnit, i.freq, i.dailyTime, String(i.days),
	i.freq === 'custom' ? i.customMode : '', i.freq === 'custom' && i.customMode === 'weekday' ? i.customWeekday : null,
	i.freq === 'custom' && i.customMode === 'monthday' ? i.customMonthDay : null
]));
const addLabel = async (page) => (await page.textContent('#pd-rx-add')).trim();
const addDisabled = (page) => page.isDisabled('#pd-rx-add');

async function fresh(page) {
	await page.evaluate(() => {
		const PD = window.__PlandoseNS;
		PD.resetPlanState();
		PD.buildApp();
	});
	await page.waitForSelector('#pd-rx-drop', { state: 'visible' });
}

/* Clicking a disabled «Προσθήκη» (as a script could) must not add anything either. */
async function forceAdd(page) {
	await page.evaluate(() => {
		const b = document.getElementById('pd-rx-add');
		b.disabled = false;
		b.click();
	});
}

async function run(user, tag) {
	const browser = await launch();
	const ctx = await browser.newContext({ viewport: { width: 1100, height: 1400 } });
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', (e) => { if (/plandose|rx-(?:text|lines|parse|review|import)|PD\./i.test(String(e.stack || '') + e.message)) errors.push(e.message); });
	await openTool(page, user, '#pd-rx-drop');
	const isPro = await page.evaluate(() => !!window.__PlandoseNS.PRO_LABELS);
	check(isPro === (user === 'pro'), tag + ' account is ' + (isPro ? 'Pro' : 'Free'));

	/* ---- 1. nine medicines: six daily (time), one weekly (weekday + confirm) ---- */
	{
		await pasteRx(page, sample('04'));
		check(await page.$$eval('.pd-rx-row', (r) => r.length) === 9, tag + ' 04: review lists 9 medicines');
		check(await page.evaluate(() => !document.body.innerHTML.includes('ΣΥΜΠΛΗΡΩΝΕΤΑΙ')), tag + ' 04: pasted text not left in the page');
		check(await addDisabled(page), tag + ' 04: «Προσθήκη» disabled while choices are open (' + await addLabel(page) + ')');
		check(await page.isVisible('#pd-rx-add-help'), tag + ' 04: the reason is shown under the button');
		check(await page.$$eval('[data-rx-time].active', (a) => a.length) === 0, tag + ' 04: no time of day preselected');
		check(await page.$$eval('[data-rx-weekday].active', (a) => a.length) === 0, tag + ' 04: no weekday preselected');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' 04: nothing added before the choices');

		/* «Ίδια ώρα για όλα τα 1×ημέρα»: offered for the six once-daily rows. */
		const bulkLabel = async () => ((await page.$('#pd-rx-bulk-label')) ? (await page.textContent('#pd-rx-bulk-label')).trim() : '');
		check(/\(6\)/.test(await bulkLabel()), tag + ' 04: bulk time chooser for the 6 once-daily rows: ' + await bulkLabel());
		/* Two rows get their own time first … */
		await page.click('#pd-rx-t-2-noon');
		await page.click('#pd-rx-t-3-evening');
		check(/\(4\)/.test(await bulkLabel()), tag + ' 04: bulk chooser now counts only the 4 rows without a time: ' + await bulkLabel());
		/* … then one click sets the rest, and leaves those two alone. */
		await page.click('#pd-rx-bulk-morning');
		const timesNow = await page.$$eval('[data-rx-time].active', (a) => a.map((b) => b.getAttribute('data-rx-row') + ':' + b.getAttribute('data-rx-time')).sort());
		check(JSON.stringify(timesNow) === JSON.stringify(['0:morning', '1:morning', '2:noon', '3:evening', '4:morning', '5:morning']),
			tag + ' 04: bulk «Πρωί» set rows 0,1,4,5 and kept noon/evening: ' + timesNow.join(' '));
		check(/4/.test(await page.textContent('#pd-rx-bulk-status')), tag + ' 04: bulk status: ' + (await page.textContent('#pd-rx-bulk-status')).trim());
		/* A row can still be changed on its own afterwards. */
		await page.click('#pd-rx-t-5-afternoon');
		check(await addDisabled(page), tag + ' 04: still disabled — the weekly medicine is open');
		await page.click('#pd-rx-wd-6-3');
		check(await addDisabled(page), tag + ' 04: still disabled — the number of doses needs «Επιβεβαιώνω»');
		check(await page.isVisible('#pd-rx-cf-6-days'), tag + ' 04: «Επιβεβαιώνω» next to Διάρκεια');
		await page.check('#pd-rx-cf-6-days');
		/* A name confirmation only when the strength was read with doubt
		   (1.27.1 asked it for OZEMPIC «1MG/0,74 … 1,34MG/ML»; 1.27.2 reads
		   that strength cleanly). Whatever the parser says, the button must
		   follow it. */
		const needsName = await page.evaluate(() => window.__PlandoseNS.s.rx.rows[6].item.warnings.indexOf('strength') !== -1);
		check(needsName === (await page.$('#pd-rx-cf-6-name') !== null), tag + ' 04: «Επιβεβαιώνω» on Φάρμακο shown exactly when the strength is in doubt (' + needsName + ')');
		if (needsName) {
			check(await addDisabled(page), tag + ' 04: still disabled — the OZEMPIC name/strength needs «Επιβεβαιώνω»');
			await forceAdd(page);
			check((await items(page)).length === 0, tag + ' 04: nothing added before the last confirmation');
			await page.check('#pd-rx-cf-6-name');
		}
		check(!(await addDisabled(page)) && /\(9\)/.test(await addLabel(page)), tag + ' 04: now enabled: ' + await addLabel(page));
		await page.$eval('#pd-rx', (el) => el.scrollIntoView());
		await (await page.$('#pd-rx')).screenshot({ path: path.join(OUT, tag + '-review.png') });
		await page.click('#pd-rx-add');
		const it = await items(page);
		const want = [
			['ATROST F.C.TAB 40MG', 1, 'tablet', '24h', 'morning', '30', '', null, null],
			['NEXIUM GR.TAB 20MG', 1, 'tablet', '24h', 'morning', '30', '', null, null],
			['COLCHICINA/ACARPIA TAB 1MG', 1, 'tablet', '24h', 'noon', '30', '', null, null],
			['ELEVEON F.C.TAB 50MG', 1, 'tablet', '24h', 'evening', '30', '', null, null],
			['COZAAR F.C.TAB 100MG', 1, 'tablet', '24h', 'morning', '30', '', null, null],
			['PLAVIX F.C.TAB 75MG', 1, 'tablet', '24h', 'afternoon', '30', '', null, null],
			['OZEMPIC INJ.SOL 1MG', 1, 'injection', 'custom', null, '30', 'weekday', 3, null],
			['INDERAL F.C.TAB 40MG', 1, 'tablet', '12h', null, '30', '', null, null],
			['SYNJARDY F.C.TAB (12,5+1000)MG', 1, 'tablet', '12h', null, '30', '', null, null]
		];
		const norm = (rows) => rows.map((r) => r.map((v, k) => (k === 4 && r[3] !== '24h' ? null : v)));
		check(JSON.stringify(norm(it)) === JSON.stringify(norm(want)), tag + ' 04: the plan has exactly the 9 medicines with the chosen times/weekday' +
			(JSON.stringify(norm(it)) === JSON.stringify(norm(want)) ? '' : ': ' + JSON.stringify(it)));
		check(await page.inputValue('#pd-patient') === 'ΑΣΘΕΝΟΥΣ ΑΣΘΕΝΗΣ', tag + ' 04: patient filled as ΕΠΩΝΥΜΟ ΟΝΟΜΑ');
		check(await page.$('.pd-rx-panel') === null, tag + ' 04: review closed');
	}

	/* ---- 2. repro: Latin-O «ΔOΣOΛOΓΙΑ» keeps its drug; «SOS» never added silently ---- */
	{
		await fresh(page);
		await pasteRx(page, HEAD +
			drug('ZINADOL F.C.TAB 500MG/TAB BTx10', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες', 'ΔOΣOΛOΓΙΑ') +
			drug('DEPON TAB 500MG/TAB BTx20', '1 ΔΙΣΚΙΑ x SOS σε περίπτωση πόνου x 5 ημέρες') +
			drug('BESPAR TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
		const names = await page.$$eval('.pd-rx-row .pd-rx-name', (n) => n.map((x) => x.textContent.trim()));
		check(names.length === 3 && names[0] === 'ZINADOL F.C.TAB 500MG', tag + ' Latin-O: ZINADOL read, 3 rows: ' + names.join(' | '));
		check(await page.$('#pd-rx-count-note') === null, tag + ' Latin-O: no medicine lost (no count warning)');
		check(await page.isDisabled('#pd-rx-inc-1') && !(await page.isChecked('#pd-rx-inc-1')), tag + ' SOS: DEPON cannot be ticked in the list');
		check(await page.$('#pd-rx-w-1-asNeeded.is-danger') !== null, tag + ' SOS: red «όταν χρειάζεται» note');
		check(await addDisabled(page), tag + ' SOS: «Προσθήκη» disabled until BESPAR has a time');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' SOS: nothing added before the choice');
		await page.click('#pd-rx-t-2-noon');
		check(!(await addDisabled(page)) && /\(2\)/.test(await addLabel(page)), tag + ' SOS: 2 addable: ' + await addLabel(page));
		await page.click('#pd-rx-add');
		const it = await items(page);
		check(JSON.stringify(it.map((r) => [r[0], r[3], r[3] === '24h' ? r[4] : null])) ===
			JSON.stringify([['ZINADOL F.C.TAB 500MG', '12h', null], ['BESPAR TAB 10MG', '24h', 'noon']]), tag + ' SOS: plan = ZINADOL + BESPAR (noon), no DEPON: ' + JSON.stringify(it.map((r) => r[0])));
		check(await page.$$eval('.pd-rx-row', (r) => r.length) === 1, tag + ' SOS: DEPON stays in the list for the form');
		await page.click('[data-rx-toform="0"]');
		check(await page.inputValue('#pd-drug') === 'DEPON TAB 500MG', tag + ' SOS: DEPON loaded in the form');
		check(await page.isVisible('#pd-rx-source') && /Από τη συνταγή/.test(await page.textContent('#pd-rx-source')) &&
			/SOS σε περίπτωση πόνου/.test(await page.textContent('#pd-rx-source')), tag + ' SOS: «Από τη συνταγή» shows the prescription text above the form');
		check(await page.$$eval('.plandose-chip[data-val].active', (a) => a.length) === 0, tag + ' SOS: no frequency preselected in the form');
		await page.click('#pd-add');
		check((await items(page)).length === 2, tag + ' SOS: «Προσθήκη Φαρμάκου» refused without a frequency');
		check(/συχνότητα/i.test(await page.textContent('#pd-message')), tag + ' SOS: asks for the frequency');
	}

	/* ---- 3. monthly: day of the month chosen in the row ---- */
	{
		await fresh(page);
		await pasteRx(page, HEAD + drug('PROLIA INJ.SOL 60MG/1ML BTx1 PF.SYR', '1 ΕΝΕΣΗ x 1 φορά τον μήνα x 60 ημέρες'));
		check(await page.inputValue('#pd-rx-md-0') === '', tag + ' monthly: no day preselected');
		check(await addDisabled(page), tag + ' monthly: «Προσθήκη» disabled');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' monthly: nothing added before the choice');
		/* Typed and left with Tab, as a pharmacist does (fires the native change). */
		await page.click('#pd-rx-md-0');
		await page.keyboard.type('15');
		await page.keyboard.press('Tab');
		/* The number of monthly doses in the period is confirmed after the day is known. */
		check(await addDisabled(page), tag + ' monthly: day chosen, still disabled until the count is confirmed');
		check(await page.isVisible('#pd-rx-cf-0-days'), tag + ' monthly: «Επιβεβαιώνω» next to Διάρκεια');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' monthly: nothing added before the confirmation');
		await page.check('#pd-rx-cf-0-days');
		check(!(await addDisabled(page)), tag + ' monthly: enabled after day 15 + «Επιβεβαιώνω»');
		await page.click('#pd-rx-add');
		const it = await items(page);
		check(it.length === 1 && it[0][3] === 'custom' && it[0][6] === 'monthday' && it[0][8] === 15, tag + ' monthly: PROLIA on day 15 of each month: ' + JSON.stringify(it));
	}

	/* ---- 4. fewer medicines read than listed + another patient in the plan ---- */
	{
		await fresh(page);
		await page.fill('#pd-patient', 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΓΙΩΡΓΟΣ');
		await pasteRx(page, PATIENT + HEAD +
			'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ ; 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n' + PRICE +
			drug('COZAAR F.C.TAB 50MG/TAB BTx28', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 28 ημέρες'));
		check(/2 φάρμακα, διαβάστηκαν 1/.test(await page.textContent('#pd-rx-count-note')), tag + ' count: «2 φάρμακα, διαβάστηκαν 1»');
		check(/ΑΣΘΕΝΟΥΣ ΑΣΘΕΝΗΣ/.test(await page.textContent('#pd-rx-patient-note')), tag + ' patient: the other patient is named');
		check(await page.$$eval('[data-rx-include]:checked', (a) => a.length) === 0, tag + ' patient: nothing preselected');
		check(await addDisabled(page), tag + ' count/patient: «Προσθήκη» disabled');
		await page.check('#pd-rx-count-ack');
		check(await addDisabled(page), tag + ' count acknowledged: still disabled (patient)');
		await page.check('#pd-rx-patient-ok');
		check(await addDisabled(page), tag + ' patient confirmed: still disabled (nothing chosen)');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' count/patient: nothing added before choosing');
		await page.check('#pd-rx-inc-1');
		check(!(await addDisabled(page)), tag + ' COZAAR chosen: enabled');
		await page.click('#pd-rx-add');
		check(JSON.stringify((await items(page)).map((r) => r[0])) === JSON.stringify(['COZAAR F.C.TAB 50MG']), tag + ' count/patient: only COZAAR added');
		check(await page.inputValue('#pd-patient') === 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΓΙΩΡΓΟΣ', tag + ' patient: the plan keeps its own patient');
	}

	/* ---- 6. insulin: «1 ΕΝΕΣΗ» is never a dose ---- */
	{
		await fresh(page);
		await pasteRx(page, HEAD +
			drug('NOVORAPID FLEXPEN INJ.SOL 100U/ML BTx5 PENSx3ML', '1 ΕΝΕΣΗ x 3 φορές την ημέρα x 30 ημέρες') +
			drug('TRESIBA FLEXTOUCH INJ.SOL 100U/ML BTx5 PENSx3ML', '1 ΕΝΕΣΗ x 1 φορά την ημέρα x 30 ημέρες'));
		for (const i of [0, 1]) {
			check(await page.isDisabled('#pd-rx-inc-' + i) && !(await page.isChecked('#pd-rx-inc-' + i)), tag + ' insulin: row ' + i + ' cannot be ticked');
			check(await page.$('#pd-rx-w-' + i + '-insulinUnits') !== null, tag + ' insulin: row ' + i + ' says the dose must be in units');
			check(await page.$('[data-rx-toform="' + i + '"]') !== null, tag + ' insulin: row ' + i + ' goes to the form');
		}
		check(await addDisabled(page), tag + ' insulin: «Προσθήκη» disabled (' + await addLabel(page) + ')');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' insulin: nothing added from the review');

		/* In the form: «1 Ένεση» for an insulin is refused; units are accepted. */
		await fresh(page);
		await page.fill('#pd-drug', 'LANTUS SOLOSTAR INJ.SOL 100U/ML');
		await page.fill('#pd-dose-amount', '1');
		await page.selectOption('#pd-dose-unit', 'injection');
		await page.click('.plandose-chip[data-val="24h"]');
		await page.click('.pd-daily-time-chip[data-time="evening"]');
		await page.fill('#pd-days', '30');
		await page.click('#pd-add');
		check((await items(page)).length === 0, tag + ' insulin form: «1 Ένεση» for Lantus refused');
		check(/μονάδες \(IU\)/i.test(await page.textContent('#plandose-app')), tag + ' insulin form: asks for units (IU)');
		await page.selectOption('#pd-dose-unit', 'iu');
		await page.fill('#pd-dose-amount', '14');
		await page.click('#pd-add');
		const it = await items(page);
		check(it.length === 1 && it[0][1] === 14 && it[0][2] === 'iu', tag + ' insulin form: 14 IU accepted: ' + JSON.stringify(it));
	}

	/* ---- 7. «κάθε 4 εβδομάδες»: every 28 days, with the dates shown ----
	   (56 days: the test site allows plans of up to 60 days.) */
	{
		await fresh(page);
		await pasteRx(page, HEAD + drug('PROLIA INJ.SOL 60MG/1ML BTx1 PF.SYR', '1 ΕΝΕΣΗ x κάθε 4 εβδομάδες x 56 ημέρες'));
		const want = await page.evaluate(() => [0, 28].map((n) => {
			const d = new Date();
			d.setDate(d.getDate() + n);
			return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');
		}));
		const total = (await page.textContent('.pd-rx-total')).trim();
		check(/2 δόσεις/.test(total) && want.every((x) => total.includes(x)), tag + ' every 4 weeks: 2 doses and their dates (' + want.join(', ') + '): ' + total);
		check(await addDisabled(page), tag + ' every 4 weeks: the start day needs «Επιβεβαιώνω»');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' every 4 weeks: nothing added before the confirmations');
		for (const field of ['freq', 'days']) {
			if (await page.$('#pd-rx-cf-0-' + field)) {
				await page.check('#pd-rx-cf-0-' + field);
			}
		}
		check(!(await addDisabled(page)), tag + ' every 4 weeks: enabled after «Επιβεβαιώνω»');
		await page.click('#pd-rx-add');
		const it = await items(page);
		check(it.length === 1 && it[0][3] === 'custom' && it[0][6] === 'days' && String(await page.evaluate(() => window.__PlandoseNS.s.items[0].customIntervalDays)) === '28',
			tag + ' every 4 weeks: plan item every 28 days: ' + JSON.stringify(it));
	}

	/* ---- 8. OZEMPIC every day: blocked in the review, second press to print ---- */
	{
		await fresh(page);
		await pasteRx(page, HEAD + drug('OZEMPIC INJ.SOL 1MG/0,74ML BTx1 PENx3ML', '1 ΕΝΕΣΗ x 1 φορά την ημέρα x 28 ημέρες'));
		check(await page.$('#pd-rx-w-0-weeklyOnly.is-danger') !== null, tag + ' OZEMPIC daily: red «μία φορά την εβδομάδα» note');
		check(await page.isDisabled('#pd-rx-inc-0') && await page.$('.pd-rx-row.needs-form') !== null, tag + ' OZEMPIC daily: cannot be added from the review, only via the form');
		check(await page.$('#pd-rx-bulk-label') === null && await page.$('[data-rx-time]') === null, tag + ' OZEMPIC daily: no time chooser offered for it');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' OZEMPIC daily: nothing added from the review');
		await page.click('[data-rx-toform="0"]');
		await page.click('.pd-daily-time-chip[data-time="morning"]');
		for (let i = 0; i < 2 && (await items(page)).length === 0; i++) {
			await page.click('#pd-add');
		}
		check((await items(page)).length === 1, tag + ' OZEMPIC daily: added through the form (the pharmacist\'s call)');
		const ajax = [];
		const onReq = (r) => { if (/admin-ajax\.php/.test(r.url()) && /action=plandose_(check|register)_print/.test(r.postData() || '')) { ajax.push(r.postData()); } };
		page.on('request', onReq);
		await page.click('#pd-next-step');
		await page.waitForSelector('#pd-print', { state: 'visible' });
		await page.click('#pd-print');
		await page.waitForTimeout(500);
		check(/συχνότερα από μία φορά την εβδομάδα/.test(await page.textContent('#pd-message')) && ajax.length === 0,
			tag + ' OZEMPIC daily: first «Εκτύπωση» only warns (no print request): ' + (await page.textContent('#pd-message')).trim().slice(0, 80));
		const popup = page.context().waitForEvent('page', { timeout: 3000 }).catch(() => null);
		await page.click('#pd-print');
		await page.waitForResponse((r) => /admin-ajax\.php/.test(r.url()), { timeout: 10000 }).catch(() => null);
		check(ajax.length > 0, tag + ' OZEMPIC daily: second press goes on to print (' + ajax.length + ' print request(s))');
		page.off('request', onReq);
		const win = await popup;
		if (win) {
			await win.close().catch(() => {});
		}
		await page.waitForTimeout(1500);
	}

	/* ---- 9. SOLUMAG single-dose vials: 1 Αμπούλα once a day, after a time pick ---- */
	{
		await fresh(page);
		await pasteRx(page, HEAD + drug('SOLUMAG FORTE OR.SOL.SD 2,810G/10ML BTx20 VIALSx10 ML (Γενόσημο)', '1 ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ x 1 φορά την ημέρα x 28 ημέρες'));
		check(await page.$$eval('.pd-rx-row', (r) => r.length) === 1, tag + ' SOLUMAG: one row read');
		check(/1 Αμπούλα/.test(await page.textContent('.pd-rx-row')), tag + ' SOLUMAG: quantity shown as «1 Αμπούλα»');
		check(await addDisabled(page), tag + ' SOLUMAG: «Προσθήκη» disabled until a time is picked');
		await forceAdd(page);
		check((await items(page)).length === 0, tag + ' SOLUMAG: nothing added before the time');
		await page.click('#pd-rx-t-0-morning');
		check(!(await addDisabled(page)) && /\(1\)/.test(await addLabel(page)), tag + ' SOLUMAG: enabled after «Πρωί»: ' + await addLabel(page));
		await page.click('#pd-rx-add');
		const it = await items(page);
		check(JSON.stringify(it) === JSON.stringify([['SOLUMAG FORTE OR.SOL.SD 2,810G/10ML', 1, 'ampoule', '24h', 'morning', '28', '', null, null]]),
			tag + ' SOLUMAG: plan = 1 ampoule, once a day, morning, 28 days: ' + JSON.stringify(it));
	}

	/* ---- 5. the drop zone itself (Pro only; same code for Free) ---- */
	if (user === 'pro') {
		await fresh(page);
		await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(page.url()).origin });
		await page.evaluate((t) => navigator.clipboard.writeText(t), sample('02'));
		await page.click('#pd-rx-drop');
		await page.keyboard.press('Control+V');
		await page.waitForSelector('#pd-rx-add');
		check(/ZINADOL/.test(await page.textContent('.pd-rx-panel')), tag + ' real Ctrl+V reads the prescription at once');
		await page.click('#pd-rx-cancel');
		check(await page.$('#pd-rx-drop') !== null && (await items(page)).length === 0, tag + ' cancel: box back, nothing added');
		await pasteRx(page, 'καλημέρα');
		check(/Δεν βρέθηκαν φάρμακα/.test(await page.textContent('.pd-rx-hint')), tag + ' nothing found: one line under the box');
	}

	check(errors.length === 0, tag + ' no JS errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
	await browser.close();
}

await run('free', 'Free');
await run('pro', 'Pro');
done();
