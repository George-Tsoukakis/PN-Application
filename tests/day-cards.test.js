/* 1.26.0: the A4 sheet laid out by day (one card per day). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

const I18N = {
	unitTablet: 'Δισκίο(α)',
	unitCapsule: 'Κάψουλα(ες)',
	unitInjection: 'Ένεση(εις)',
	morning: 'Πρωί',
	noon: 'Μεσημέρι',
	afternoon: 'Απόγευμα',
	evening: 'Βράδυ',
	days: 'ημέρες',
	dayNumber: 'Ημέρα %d',
	anyTimeOfDay: 'Μέσα στην ημέρα',
	yourMedicines: 'Τα Φάρμακά σας:'
};

function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}

/* jsdom arrays come from another realm: compare plain copies. */
const plain = (v) => JSON.parse(JSON.stringify(v));

function setup(items, firstSlot) {
	const env = load({ config: { i18n: I18N } });
	const PD = env.PD;
	PD.s.startDate = isoAhead(0);
	PD.s.firstSlot = firstSlot || 'morning';
	PD.s.items = items;
	PD.beginDayPass();
	return env;
}

/* How many times each medicine (by index) appears on the day cards. */
function cardCounts(PD) {
	const counts = PD.s.items.map(() => 0);
	for (let d = 0; d < PD.planDayCount(); d++) {
		PD.dayDoses(d).forEach((g) => g.entries.forEach((e) => { counts[e.index]++; }));
	}
	return counts;
}

test.after(closeAll);

test('every dose on the cards matches doseTotals (fixed, weekly, every-N-days)', () => {
	const { PD } = setup([
		med('A', '12h', 7),
		med('B', '24h', 14, { dailyTime: 'evening' }),
		med('C', '8h', 5),
		med('D', '6h', 3),
		med('E', 'custom', 21, { customMode: 'weekday', customWeekday: 3 }),
		med('F', 'custom', 20, { customMode: 'days', customIntervalDays: '3' })
	]);
	const counts = cardCounts(PD);
	PD.s.items.forEach((item, i) => {
		assert.strictEqual(counts[i], PD.doseTotals(item).doses, item.name);
	});
});

test('a first dose in the evening moves the skipped doses to one extra day', () => {
	const { PD } = setup([med('AUGMENTIN', '12h', 3)], 'evening');
	assert.strictEqual(PD.planDayCount(), 4);
	assert.deepStrictEqual(plain(PD.dayDoses(0).map((g) => g.key)), ['evening']);
	assert.deepStrictEqual(plain(PD.dayDoses(3).map((g) => g.key)), ['morning']);
	assert.strictEqual(cardCounts(PD)[0], 6);
});

test('dayparts are listed in the order of the day, day-based medicines last', () => {
	const { PD } = setup([
		med('W', 'custom', 7, { customMode: 'days', customIntervalDays: '7' }),
		med('Q', '6h', 1)
	]);
	assert.deepStrictEqual(plain(PD.dayDoses(0).map((g) => g.key)), ['morning', 'noon', 'afternoon', 'evening', 'anytime']);
	assert.strictEqual(PD.dayDoses(0)[4].label, 'Μέσα στην ημέρα');
});

test('days without any dose get no card', () => {
	const { PD } = setup([med('FOSAMAX', 'custom', 28, { customMode: 'days', customIntervalDays: '7' })]);
	const html = PD.buildDayCards();
	assert.strictEqual((html.match(/class="pd-day-card"/g) || []).length, 4);
});

test('singular and plural unit on the cards', () => {
	const { PD } = setup([med('ONE', '24h', 1), med('TWO', '24h', 1, { doseAmount: 2 }), med('CAPS', '24h', 1, { doseAmount: 3, doseUnit: 'capsule' })]);
	const html = PD.buildDayCards();
	assert.match(html, /1 Δισκίο</);
	assert.match(html, /2 Δισκία</);
	assert.match(html, /3 Κάψουλες</);
});

test('two strengths of the same medicine stay distinct, with the full name', () => {
	const { PD } = setup([med('SINTROM TAB 1MG', '24h', 2), med('SINTROM TAB 4MG', '24h', 2)]);
	const html = PD.buildDayCards();
	assert.match(html, /SINTROM TAB 1MG/);
	assert.match(html, /SINTROM TAB 4MG/);
	assert.strictEqual(PD.dayDoses(0)[0].entries.length, 2);
});

test('medicine names and notes are escaped on the sheet', () => {
	const { PD } = setup([med('<img src=x onerror=alert(1)>', '24h', 1, { notes: '<b>x</b>' })]);
	const html = PD.buildMedicineKey() + PD.buildDayCards();
	assert.doesNotMatch(html, /<img/);
	assert.doesNotMatch(html, /<b>x/);
	assert.match(html, /&lt;img/);
});

test('the dose on the card is the amount alone, and the notes sit under the name', () => {
	const { PD } = setup([med('A', '24h', 1, { notes: 'Μετά το φαγητό' }), med('B', '24h', 1)]);
	const html = PD.buildDayCards();
	assert.match(html, /<span class="pd-day-dose-amount">1\u00a0Δισκίο<\/span>/);
	assert.doesNotMatch(html, /Δοσολογία/);
	assert.strictEqual((html.match(/pd-day-dose-notes">Μετά το φαγητό</g) || []).length, 1);
});

test('card title is the weekday and the full date', () => {
	const { PD } = setup([med('A', '24h', 1)]);
	assert.match(PD.dayCardTitle(0), /^\S+ \d{2}-\d{2}-\d{4}$/);
	assert.doesNotMatch(PD.buildDayCards(), /pd-drug-badge/);
});

test('the list of medicines says «Για N μέρες»', () => {
	const { PD } = setup([med('A', '24h', 30), med('B', '24h', 1)]);
	const html = PD.buildMedicineKey();
	assert.match(html, / \/ Για 30 μέρες</);
	assert.match(html, / \/ Για 1 μέρα</);
	assert.match(html, /Τα Φάρμακά σας:/);
});
