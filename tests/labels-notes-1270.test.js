/* 1.27.0: Pro labels — notes compared with the FULL text, cut at a word
   boundary, acknowledged in every mode (auto included) and for a customer
   shortened to initials; fixed sizes use the room they have; the A4
   checks run for labels; an auto continuation label repeats the medicine.
   jsdom has no layout, so the measuring host is faked. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

function drug(name, notes) {
	return {
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: '12h',
		dailyTime: 'morning', customMode: 'days', customIntervalDays: '7',
		customWeekday: 1, days: '7', notes: notes || ''
	};
}

const LONG = 'Να λαμβάνεται μετά το φαγητό με ένα γεμάτο ποτήρι νερό, ΟΧΙ μαζί με γάλα ή γιαούρτι, και να αποφεύγεται ο ήλιος για όλη τη διάρκεια της θεραπείας';

/* Fake measure: the label box is 100 px; the content is as tall as the
   number of characters in the notes row (so up to ~97 characters fit). */
function fakeMeasure(w, PD) {
	const host = {
		html: '',
		set innerHTML(v) { this.html = v; },
		get innerHTML() { return this.html; },
		querySelector() {
			const doc = new w.DOMParser().parseFromString(host.html, 'text/html');
			const notes = doc.querySelector('.pd-lbl-notes .pd-lbl-val');
			const len = notes ? Array.from(notes.textContent).length : 0;
			return { clientHeight: 100, style: {}, offsetHeight: len };
		}
	};
	PD.labelMeasureHost = () => host;
	PD.labelSize = () => ({ id: 'custom', w: 100, h: 50 });
}

test('labelNotes cuts at a word boundary with «…» and labelNotesFull never cuts', () => {
	const { PD } = load();
	const cut = PD.labelNotes(LONG, 50);
	assert.ok(cut.endsWith('…'));
	assert.ok(Array.from(cut).length <= 50);
	const body = cut.slice(0, -1);
	assert.ok(LONG.startsWith(body), 'a prefix of the notes');
	assert.ok(' ' === LONG.charAt(body.length) || ',' === LONG.charAt(body.length), 'ends at a word: «' + body + '»');
	assert.strictEqual(PD.labelNotesFull('  a \n  b '), 'a b');
	assert.strictEqual(PD.labelNotes('σύντομο', 50), 'σύντομο');
});

test('auto (printer-sized) labels: notes longer than the cut need the second press', () => {
	const { PD } = load();
	PD.labelSize = () => ({ id: 'auto', w: 0, h: 0 });
	PD.LABEL_ACK_MIN_MS = 0;
	const items = [drug('Ciproxin', LONG)];
	assert.deepStrictEqual([...PD.labelNotesTrimmed(items, '')], ['Ciproxin']);
	assert.match(PD.labelNotesNeedAck(items, ''), /Ciproxin/);
	assert.strictEqual(PD.labelNotesNeedAck(items, ''), '', 'second press prints');
	/* Short notes: nothing to acknowledge. */
	assert.strictEqual(PD.labelNotesNeedAck([drug('Depon', 'μετά το φαγητό')], ''), '');
});

test('fixed size: notes use the free room (more than 50 characters) and a cut is still reported', () => {
	const { w, PD } = load();
	fakeMeasure(w, PD);
	const fit = PD.fitLabel(drug('Ciproxin', LONG), '', PD.labelSize());
	assert.ok(fit.ok);
	const shown = PD.labelNotes(LONG, fit.opts.notesMax);
	assert.ok(Array.from(shown).length > 50, 'longer than the old hard 50: ' + shown.length);
	assert.ok(Array.from(shown).length <= 97);
	assert.deepStrictEqual([...fit.trimmed], ['notes']);

	const medium = LONG.slice(0, 80);
	const fit2 = PD.fitLabel(drug('Ciproxin', medium), '', PD.labelSize());
	assert.deepStrictEqual([...fit2.trimmed], [], '80 characters fit whole');
	assert.strictEqual(PD.labelNotes(medium, fit2.opts.notesMax), PD.labelNotesFull(medium));
});

test('a customer shortened to initials needs the same second press', () => {
	const { PD } = load();
	PD.labelSize = () => ({ id: 'custom', w: 57, h: 32 });
	PD.fitLabel = () => ({ ok: true, scale: 0.75, opts: { patientInitials: true }, trimmed: ['patient'] });
	PD.LABEL_ACK_MIN_MS = 0;
	const items = [drug('Depon')];
	const msg = PD.labelNotesNeedAck(items, 'Δοκιμή Δοκιμαστοπούλου');
	assert.match(msg, /αρχικά/);
	assert.strictEqual(PD.labelNotesNeedAck(items, 'Δοκιμή Δοκιμαστοπούλου'), '');
	assert.notStrictEqual(PD.labelNotesNeedAck(items, 'Άλλος Πελάτης'), '', 'other customer asks again');
});

function openApp(w, PD) {
	PD.refreshNonce = () => new w.Promise(() => {});
	PD.startNonceRefresh = () => {};
	const lh = PD.loadHeader;
	PD.loadHeader = () => {};
	PD.openModal();
	PD.loadHeader = lh;
}

test('label print runs the A4 checks: invalid start date and a medicine with no dose are refused', () => {
	const { w, PD } = load();
	openApp(w, PD);
	let opened = 0;
	w.open = () => { opened++; return null; };
	PD.s.items = [drug('Depon')];
	PD.s.startDate = PD.START_DATE_INCOMPLETE;
	PD.handlePrintLabels();
	assert.strictEqual(opened, 0, 'incomplete start date');

	PD.s.startDate = '';
	PD.doseTotals = () => ({ doses: 0, units: 0 });
	PD.handlePrintLabels();
	assert.strictEqual(opened, 0, 'no dose in the period');
	assert.match(w.document.getElementById('pd-message').textContent, /Depon/);
});

test('auto labels: the medicine row is a repeating table header', () => {
	const { w, PD } = load();
	PD.labelSize = () => ({ id: 'auto', w: 0, h: 0 });
	PD.s.header = { name: 'Φαρμακείο Δοκιμή', phone_1: '210' };
	const doc = new w.DOMParser().parseFromString(PD.buildLabelPrintDocument([drug('Augmentin 875mg', LONG)], 'Δοκιμή'), 'text/html');
	const thead = doc.querySelector('.pd-lbl thead');
	assert.ok(thead, 'thead present');
	assert.match(thead.textContent, /Augmentin 875mg/);
	assert.ok(thead.querySelector('.pd-lbl-drug'), 'the medicine row repeats');
	assert.ok(!thead.querySelector('.pd-lbl-head'), 'the pharmacy header stays first, outside the table');
	assert.ok(!thead.querySelector('.pd-lbl-dose'), 'the dosage is in the body');
	assert.ok(doc.querySelector('.pd-lbl tbody .pd-lbl-dose'));
	assert.ok(doc.querySelector('.pd-lbl tbody .pd-lbl-notes'));
	assert.match(doc.querySelector('style').textContent, /thead\{display:table-header-group;\}/);
	/* Same rows, same order as the single-block label. */
	const flat = new w.DOMParser().parseFromString('<div>' + PD.labelInnerHtml(drug('Augmentin 875mg', LONG), 'Δοκιμή') + '</div>', 'text/html');
	assert.strictEqual(doc.querySelector('.pd-lbl').textContent, flat.body.textContent);
});

test('the label flow locks the plan editor while it runs', () => {
	const { w, PD } = load();
	openApp(w, PD);
	PD.s.items = [drug('Depon')];
	const win = { closed: false, document: { open() {}, write() {}, close() {} }, close() { this.closed = true; }, addEventListener() {}, focus() {}, print() {} };
	w.open = () => win;
	PD.loadHeader = () => {}; /* never answers: the flow stays running */
	PD.labelFitProblem = () => '';
	PD.handlePrintLabels();
	const panel = w.document.querySelector('[data-pd-step="1"]');
	assert.ok(PD.s.isPrintingLabels);
	assert.ok(panel.hasAttribute('inert'));
	PD.closeLabelWindow();
	assert.ok(!panel.hasAttribute('inert'));
});
