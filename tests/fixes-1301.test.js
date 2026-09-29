/* 1.30.1: fixes from the 1.30.0 review.
   - «1.000MG» in a pasted prescription went into the printed name with no
     warning at all: a Greek reader sees a thousand, an English one sees one.
     Now the row goes through the form («strengthThousands»), in mg, mcg and
     units only («2,810G» is never 2810 grams).
   - The patient page (QR) listed a weekly medicine as «Πρωί · 01/10 – 22/10»,
     which reads as daily. It now says the rhythm (every day / once a week /
     every N days / the dates) and the number of dose days.
   - The phone gets dayparts, not clock hours: «κάθε 8/12 ώρες» became
     08:00 / 14:00 / 21:00. The page now tells the patient how to space the
     times when a medicine is taken Morning·Midday·Night or Morning·Night. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const env = require('./lib/env.js');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const DAILY = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';
const parse = (PD, drug) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + DAILY + '\n' + P).items[0];

function allConfirmed(it) {
	return Object.assign({}, it, {
		dailyTime: 'morning', customWeekday: 1, customMonthDay: 1,
		confirmed: { name: true, qty: true, freq: true, days: true }
	});
}

test('a strength with a thousands-looking separator goes through the form', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const drug of [
		'FOO TAB 1.000MG BTx30',
		'FOO TAB 1,000MG BTx30',
		'FOO TAB 2.500MG BTx30',
		'FOO CAPS 1.000MCG BTx30',
		'FOO TAB 1,250MG BTx30'
	]) {
		const it = parse(PD, drug);
		assert.ok(it.warnings.includes('strengthThousands'), JSON.stringify(drug) + ' ' + it.warnings);
		assert.strictEqual(PD.rxItemProblem(allConfirmed(it)), 'warnings', JSON.stringify(drug) + ' could be added with «Επιβεβαιώνω»');
		assert.ok(/[.,]\d{3}/.test(it.name), JSON.stringify(drug) + ': the strength stays as written, got ' + it.name);
	}
});

test('no thousands warning for a zero whole part, grams or plain decimals', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const drug of [
		'FOO TAB 0,125MG BTx30',
		'FOO TAB 0.125MG BTx30',
		'FOO TAB 1,5MG BTx30',
		'FOO TAB 12,5MG BTx30',
		'FOO TAB 1000MG BTx30',
		'FOO OR.SOL.SD 2,810G/10ML BTx20'
	]) {
		const it = parse(PD, drug);
		assert.ok(!it.warnings.includes('strengthThousands'), JSON.stringify(drug) + ' ' + it.warnings);
	}
});

test('the thousands warning has a text in both dictionaries', () => {
	const { PD } = load({ i18n: 'real' });
	const text = PD.prescriptionWarningText('strengthThousands');
	assert.ok(/1\.000MG/.test(text), text);
	assert.notStrictEqual(text, 'strengthThousands');
});

/* ---- patient page (public/calendar.js) ------------------------------------ */

const PUBLIC = path.join(env.pluginDir(), 'public');
const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.30.1';
const I18N = { unitTablet: 'Δισκίο(α)', morning: 'Πρωί', noon: 'Μεσημέρι', afternoon: 'Απόγευμα', evening: 'Βράδυ', anyTimeOfDay: 'Μέσα στην ημέρα' };

function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}

/* The patient page opened from the QR of a plan with these items. */
function patientPageFor(items) {
	const { PD } = load({ config: { i18n: I18N, calendarUrl: CAL_URL } });
	PD.s.startDate = isoAhead(1);
	PD.s.firstSlot = 'morning';
	PD.s.items = items;
	PD.s.header = { name: 'Φαρμακείο Δοκιμής' };
	PD.beginDayPass();
	const link = PD.calendarLink();
	assert.ok(link, 'the plan has a QR link');
	const html = fs.readFileSync(path.join(PUBLIC, 'calendar.html'), 'utf8')
		.replace(/<script src="calendar\.js[^"]*"><\/script>/, '')
		.replace(/<link[^>]*calendar\.css[^>]*>/, '');
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: CAL_URL + '#' + link.slice(link.indexOf('#') + 1) });
	dom.window.eval(fs.readFileSync(path.join(PUBLIC, 'calendar.js'), 'utf8'));
	return dom;
}

const whenOf = (dom) => Array.from(dom.window.document.querySelectorAll('.med-when')).map((n) => n.textContent);
const hintsOf = (dom) => Array.from(dom.window.document.querySelectorAll('.hint')).map((n) => n.textContent);

test('patient page: a weekly medicine never reads as daily', () => {
	const dom = patientPageFor([med('METHOTREXATE', 'custom', 28, { customMode: 'weekday', customWeekday: 3 })]);
	const [when] = whenOf(dom);
	assert.match(when, /1 φορά την εβδομάδα/, when);
	assert.match(when, /4 ημέρες με δόση/, when);
	assert.doesNotMatch(when, /κάθε ημέρα/, when);
	dom.window.close();
});

test('patient page: daily says «κάθε ημέρα», every N days says so with the count', () => {
	const dom = patientPageFor([
		med('DAILY', '24h', 10),
		med('EVERY3', 'custom', 20, { customMode: 'days', customIntervalDays: '3' })
	]);
	const [daily, every3] = whenOf(dom);
	assert.match(daily, /κάθε ημέρα/, daily);
	assert.match(every3, /κάθε 3 ημέρες/, every3);
	assert.match(every3, /\d+ ημέρες με δόση/, every3);
	dom.window.close();
});

test('patient page: irregular days are listed as dates', () => {
	const dom = patientPageFor([med('A', '24h', 3)]);
	const C = dom.window.PlanDoseCalendar;
	const plan = { start: '2026-10-01', days: 10, tracks: [{ med: 0, slot: 'm', bits: [0b00001011] }] };
	const when = C.medWhen(plan, 0, C.TEXT.el);
	assert.strictEqual(when, 'Πρωί · 3 ημέρες με δόση: 01/10, 02/10, 04/10');
	assert.strictEqual(C.medWhen({ start: '2026-10-01', days: 10, tracks: [{ med: 0, slot: 'm', bits: [0] }] }, 0, C.TEXT.el), '', 'a track with no dose: no date before the start');
	dom.window.close();
});

test('patient page: spacing hint for Morning·Midday·Night and Morning·Night only', () => {
	let dom = patientPageFor([med('ANTIBIOTIC', '8h', 7)]);
	let hints = hintsOf(dom);
	assert.strictEqual(hints.length, 1, String(hints));
	assert.match(hints[0], /κάθε 8 ώρες/);
	dom.window.close();

	dom = patientPageFor([med('B', '12h', 7), med('C', '12h', 5)]);
	hints = hintsOf(dom);
	assert.strictEqual(hints.length, 1, 'one hint for two medicines: ' + hints);
	assert.match(hints[0], /κάθε 12 ώρες/);
	dom.window.close();

	dom = patientPageFor([med('D', '24h', 7), med('E', '6h', 3)]);
	assert.deepStrictEqual(hintsOf(dom), [], 'no hint for once a day or four times a day');
	dom.window.close();
});
