/* 1.27.0 round 2: day cards never taller than an A4 page — long notes in
   full only in the medicine list, dense full-width cards, and a visible
   «(συνέχεια)» split as the last resort. Real page layout is checked by
   `npm run test:visual`; this checks the logic in jsdom. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

const NOTE = 'Λαμβάνεται με άφθονο νερό, μισή ώρα πριν από το φαγητό, όρθιος. Όχι μαζί με γάλα, γιαούρτι ή σίδηρο.';

function med(name, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: '6h', dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null,
		days: '2', notes: ''
	}, extra || {});
}

function sheet(PD, items) {
	PD.s.items = items;
	PD.s.header = { name: 'Φαρμακείο', phone_1: '210' };
	const html = PD.buildPrintHtml();
	const doc = new PD.app.ownerDocument.defaultView.DOMParser().parseFromString(html, 'text/html');
	return doc;
}

test('short notes print whole in the cards; long ones are cut and point to the list, which has them in full', () => {
	const { PD } = load();
	assert.strictEqual(PD.dayCardNotesText('Μετά το φαγητό.'), 'Μετά το φαγητό.');
	const cut = PD.dayCardNotesText(NOTE);
	assert.match(cut, /… \(βλ\. λίστα φαρμάκων\)$/);
	assert.ok(NOTE.startsWith(cut.split('…')[0]));
	const doc = sheet(PD, [med('Augmentin', { notes: NOTE })]);
	assert.strictEqual(doc.querySelector('.pd-med-key .pd-drug-notes').textContent, NOTE, 'full notes in the list');
	doc.querySelectorAll('.pd-day-dose-notes').forEach((el) => assert.strictEqual(el.textContent, cut));
});

test('a normal day stays a half-width card', () => {
	const { PD } = load();
	const doc = sheet(PD, [med('Depon', { freq: '8h' }), med('Xozal', { freq: '24h' })]);
	assert.ok(doc.querySelectorAll('.pd-day-card').length > 0);
	assert.strictEqual(doc.querySelectorAll('.pd-day-card-dense').length, 0);
});

test('ten medicines every 6 hours → full-width card, no split', () => {
	const { PD } = load();
	const items = Array.from({ length: 10 }, (_, i) => med('ΦΑΡΜΑΚΟ ' + (i + 1) + ' ΔΟΚΙΜΗΣ F.C.TAB 500MG/TAB', { notes: i % 2 ? 'Μετά το φαγητό.' : '' }));
	const doc = sheet(PD, items);
	const cards = doc.querySelectorAll('.pd-day-card');
	assert.strictEqual(cards.length, 2, 'one card per day');
	cards.forEach((c) => assert.ok(c.classList.contains('pd-day-card-dense')));
	assert.doesNotMatch(doc.body.textContent, /συνέχεια/);
});

test('a day too long even at full width is split into visible «(συνέχεια)» cards, every dose once', () => {
	const { PD } = load();
	const items = Array.from({ length: 12 }, (_, i) => med('AMOXICILLIN/CLAVULANIC ACID SANDOZ F.C.TAB ' + (i + 1) + ' (875+125)MG', { notes: NOTE, days: '1' }));
	PD.s.items = items;
	PD.beginDayPass();
	const groups = PD.dayDoses(0);
	const layout = PD.dayCardLayout(groups);
	assert.ok(layout.parts.length > 1);
	layout.parts.forEach((p) => assert.ok(PD.estimateDayCardHeight(p, true) <= PD.DAY_CARD_MAX_PX));
	const doc = sheet(PD, items);
	const cards = Array.from(doc.querySelectorAll('.pd-day-card'));
	assert.strictEqual(cards.length, layout.parts.length);
	const first = cards[0].querySelector('.pd-day-card-head strong').textContent;
	cards.slice(1).forEach((c) => {
		assert.strictEqual(c.querySelector('.pd-day-card-head strong').textContent, first + ' (συνέχεια)');
		assert.ok(c.classList.contains('pd-day-card-dense'));
	});
	/* 12 medicines × 4 doses, each exactly once. */
	assert.strictEqual(doc.querySelectorAll('.pd-day-dose').length, 48);
	const total = groups.reduce((n, g) => n + g.entries.length, 0);
	assert.strictEqual(total, 48);
	/* The slot label is repeated in the part that continues a slot. */
	cards.forEach((c) => assert.ok(c.querySelector('.pd-day-slot-label').textContent));
});

test('print styles: dense card is full width; cards (not only rows) avoid page breaks; key row cannot overflow', () => {
	const { PD } = load();
	assert.match(PD.PRINT_STYLES, /\.pd-day-card-dense \{ flex-basis: 100%; \}/);
	assert.match(PD.PRINT_STYLES, /\.pd-day-card \{[^}]*break-inside: avoid/);
	assert.match(PD.PRINT_STYLES, /\.pd-med-key-row \{[^}]*grid-template-columns: minmax\(0, 38fr\) minmax\(0, 62fr\)/);
});
