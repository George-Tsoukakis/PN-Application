/* 1.27.0 round 3: instruction lines never become (part of) a drug name
   and are never lost past a price line; monthly dose count confirmed;
   linear anchor splitting and the input cap; dose-like lines that were
   not read. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll, isoAhead } = require('./harness');
const perf = require('./lib/perf.js');

test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const J = (v) => JSON.parse(JSON.stringify(v));
const DEPON = 'DEPON TAB 500MG BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\n';
const ZIN = 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n';
const SINTROM = 'SINTROM TAB 4MG BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n';
const BESPAR = 'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n';

const parse = (PD, t) => PD.parsePrescription(t);
const has = (it, w) => it.warnings.includes(w);

/* H1 */
test('H1 repro: «Σε περίπτωση πυρετού» after DEPON\'s own price line flags DEPON (and ZINADOL), never enters a name', () => {
	const { PD } = load();
	const r = parse(PD, HEAD + DEPON + P + 'Σε περίπτωση πυρετού\n' + ZIN + P);
	assert.deepStrictEqual(J(r.items.map((i) => i.name)), ['DEPON TAB 500MG', 'ZINADOL F.C.TAB 500MG']);
	for (const it of r.items) {
		assert.ok(has(it, 'extra') && has(it, 'asNeeded'), it.name);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', it.name);
		assert.match(it.origText, /Σε περίπτωση πυρετού/);
	}
	assert.strictEqual(r.expectedCount, 2);
	assert.strictEqual(r.readCount, 2);
});

test('H1 repro in the review: neither row is ticked, nothing is added, no name carries the instruction', () => {
	const env = load();
	const { PD, w } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	const d = w.document;
	const drop = d.getElementById('pd-rx-drop');
	drop.value = HEAD + DEPON + P + 'Σε περίπτωση πυρετού\n' + ZIN + P;
	drop.dispatchEvent(new w.Event('input', { bubbles: true }));
	const boxes = Array.from(d.querySelectorAll('[data-rx-include]'));
	assert.strictEqual(boxes.length, 2);
	assert.ok(boxes.every((b) => !b.checked && b.disabled));
	assert.ok(Array.from(d.querySelectorAll('.pd-rx-name'), (e) => e.textContent).every((n) => !/περίπτωση/.test(n)));
	d.getElementById('pd-rx-add').click();
	assert.strictEqual(PD.s.items.length, 0);
});

test('an instruction line after the last price line (before the totals) is not lost', () => {
	const { PD } = load();
	const it = parse(PD, HEAD + DEPON + P + 'Σε περίπτωση πυρετού\n0% 10% 25% Άλλο\n0,00 0,00 1,00 0,00\nΣΥΝΟΛΟ : 1,00 €\n').items[0];
	assert.ok(has(it, 'extra') && has(it, 'asNeeded'));
});

test('Latin-capital instruction lines with a form code are never part of the next name', () => {
	const { PD } = load();
	const lines = ['EXCEPT SUNDAY 1/2 TAB', 'STOP 3 DAYS BEFORE SURGERY TAB', 'INR 2-3 TAB 1/4 ΤΗΝ ΚΥΡΙΑΚΗ', '1/2 TAB ΤΗΝ ΚΥΡΙΑΚΗ', 'ΕΚΤΟΣ ΚΥΡΙΑΚΗΣ 1/2 TAB'];
	for (const line of lines) {
		for (const sep of ['', P]) {
			const tag = line + (sep ? ' +price' : '');
			const r = parse(PD, HEAD + SINTROM + sep + line + '\n' + BESPAR + P + (sep ? '' : P));
			assert.deepStrictEqual(J(r.items.map((i) => i.name)), ['SINTROM TAB 4MG', 'BESPAR TAB 10MG'], tag);
			assert.ok(has(r.items[0], 'extra'), tag);
			assert.match(r.items[0].origText, new RegExp(line.replace(/[/()]/g, '.')), tag);
			if (sep) {
				/* Past a price line: unclear which medicine — both flagged. */
				assert.ok(has(r.items[1], 'extra'), tag);
			}
		}
	}
});

test('«INR …» directly followed by a price line is flagged on SINTROM, not dropped', () => {
	const { PD } = load();
	for (const text of [HEAD + SINTROM + 'INR 2-3 TAB 1/4 ΤΗΝ ΚΥΡΙΑΚΗ\n' + P,
		HEAD + 'SINTROM TAB 4MG BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες 25% 1 1,00 1,00 1,00 0,00 0,25 0,75\nSTOP 3 DAYS BEFORE SURGERY TAB\n']) {
		const r = parse(PD, text);
		assert.strictEqual(r.items.length, 1);
		assert.ok(has(r.items[0], 'extra'));
	}
});

test('an instruction between the brand line and the dose line is moved out of the name', () => {
	const { PD } = load();
	const it = parse(PD, HEAD + 'SINTROM TAB 4MG BTx20\nΕΚΤΟΣ ΚΥΡΙΑΚΗΣ\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n' + P).items[0];
	assert.strictEqual(it.name, 'SINTROM TAB 4MG');
	assert.ok(has(it, 'extra'));
	assert.match(it.origText, /ΕΚΤΟΣ ΚΥΡΙΑΚΗΣ/);
});

test('status wraps stay part of the name without warnings; a name fragment on a line of its own is shown and flagged', () => {
	const { PD } = load();
	const r = parse(PD, HEAD + 'NEXIUM GR.TAB 20MG/TAB BTx28 (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n' + P +
		'METHOTREX\nATE/EBEWE TAB 2,5MG/TAB BTx50\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την εβδομάδα x 28 ημέρες\n' + P +
		'ΜΕΘΟΤΡΕΞΑΤΗ TAB 2,5MG\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την εβδομάδα x 28 ημέρες\n' + P);
	/* Round 4: «METHOTREX» is not a drug line: never in a name, flagged on
	   the medicine above (after its price row) and the one below. */
	assert.deepStrictEqual(J(r.items.map((i) => [i.name, has(i, 'extra')])), [['NEXIUM GR.TAB 20MG', true], ['ATE/EBEWE TAB 2,5MG', true], ['', true]]);
	assert.match(r.items[1].origText, /^METHOTREX\nATE\/EBEWE/);
});

test('all 22 samples: no instruction or extra warning introduced', () => {
	const { PD } = load();
	const dir = path.join(__dirname, 'rx-samples');
	for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.txt'))) {
		const r = parse(PD, fs.readFileSync(path.join(dir, f), 'utf8'));
		assert.ok(!r.items.some((i) => has(i, 'extra') || has(i, 'asNeeded')), f);
		assert.strictEqual(r.unreadDoseLines, 0, f);
	}
});

/* M2 */
test('M2: monthly x 90 days is 2 or 3 doses depending on the day — the count is always confirmed', () => {
	const { PD, w } = load();
	PD.s.dayPassFrozen = true;
	PD.s.planDayZero = new w.Date(2026, 8, 15).getTime();
	PD.s.planToday = PD.s.planDayZero;
	const it = parse(PD, HEAD + 'PROLIA INJ.SOL 60MG BTx1\nΔΟΣΟΛΟΓΙΑ : 1 ΕΝΕΣΗ x 1 φορά τον μήνα x 90 ημέρες\n' + P).items[0];
	for (const [day, doses] of [[14, 2], [15, 3], [16, 3]]) {
		it.customMonthDay = day;
		it.confirmed = {};
		assert.strictEqual(PD.doseTotals(it).doses, doses, String(day));
		assert.deepStrictEqual(J(PD.rxPending(it)), [{ code: 'monthlyCount', field: 'days', kind: 'confirm' }], String(day));
		assert.strictEqual(PD.rxItemProblem(it), 'warnings');
		it.confirmed = { days: true };
		assert.strictEqual(PD.rxItemProblem(it), '');
	}
	assert.match(PD.prescriptionWarningText('monthlyCount'), /πλήθος των δόσεων/);
});

/* L1 */
test('L1: 1 MB without line breaks is refused at once; 190 KB of anchors is read in linear time', () => {
	const { PD } = load();
	const t0 = Date.now();
	const big = parse(PD, 'ΔΟΣΟΛΟΓΙΑ:'.repeat(100000));
	assert.ok(Date.now() - t0 < 1000 * perf.factor(), (Date.now() - t0) + ' ms');
	assert.strictEqual(big.tooLarge, true);
	assert.strictEqual(big.items.length, 0);
	/* Under 1.5 s on an idle machine; under load, linear growth is enough
	   (lib/perf.js) — a backtracking regex grows at least quadratically. */
	const t = perf.linearWithin((n) => parse(PD, 'ΔΟΣΟΛΟΓΙΑ:'.repeat(n)), 19000, 1500);
	assert.ok(t.ok, t.why);
	const many = t.result;
	assert.strictEqual(many.items.length, 19000);
	/* The split itself is still right: an anchor mid-line starts a line. */
	const joined = parse(PD, HEAD + 'XOZAL F.C.TAB 5MG/TAB BTx30 ΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες 25% 1 6,00\n' + ZIN.replace('\nΔΟΣΟΛΟΓΙΑ', ' ΔΟΣΟΛΟΓΙΑ') + P);
	assert.deepStrictEqual(J(joined.items.map((i) => i.name)), ['XOZAL F.C.TAB 5MG', 'ZINADOL F.C.TAB 500MG']);
});

test('L1: the review refuses an oversized paste with a note', () => {
	const env = load();
	const { PD, w } = env;
	PD.buildApp();
	const drop = w.document.getElementById('pd-rx-drop');
	drop.value = 'x'.repeat(PD.RX_MAX_INPUT + 1);
	drop.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.strictEqual(PD.s.rx, null);
	assert.match(w.document.querySelector('.pd-rx-hint').textContent, /πολύ μεγάλο/);
});

/* L2 */
test('L2: a mangled anchor with no price lines is reported as an unread dose line, and blocks «Προσθήκη»', () => {
	const env = load();
	const { PD, w } = env;
	const text = HEAD + 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓ1Α : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n' +
		'COZAAR F.C.TAB 50MG/TAB BTx28\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 28 ημέρες\n';
	const r = parse(PD, text);
	/* Round 4: ZINADOL's drug line has no dose line under it: listed. */
	assert.strictEqual(r.readCount, 1);
	assert.deepStrictEqual(J(r.items.map((i) => [i.name, i.warnings.includes('noDose')])), [['ZINADOL F.C.TAB 500MG', true], ['COZAAR F.C.TAB 50MG', false]]);
	assert.strictEqual(r.expectedCount, 2);
	assert.strictEqual(r.unreadDoseLines, 1);
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	const d = w.document;
	const drop = d.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.match(d.getElementById('pd-rx-count-note').textContent, /1 γραμμές που μοιάζουν με δοσολογία/);
	assert.strictEqual(d.getElementById('pd-rx-add').disabled, true);
	const ack = d.getElementById('pd-rx-count-ack');
	ack.checked = true;
	ack.dispatchEvent(new w.Event('change', { bubbles: true }));
	/* The mangled line sits between ZINADOL's drug line and COZAAR's:
	   both are flagged and go through the form, so nothing is addable. */
	assert.strictEqual(d.getElementById('pd-rx-add').disabled, true);
	assert.ok(PD.s.rx.rows.every((row) => row.item.warnings.includes('extra')));
});

test('L2: wrapped dose tails that were read are not counted', () => {
	const { PD } = load();
	const r = parse(PD, HEAD + 'BESPAR TAB 10MG\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ\nx 1 φορά την ημέρα x 28 ημέρες\n' + ZIN + P);
	assert.strictEqual(r.unreadDoseLines, 0);
	assert.strictEqual(r.items.length, 2);
});
