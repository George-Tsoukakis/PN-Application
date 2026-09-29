/* 1.27.0 round 4: fail-closed reading of the medicine list. A drug line
   is recognised by its whole ΗΔΙΚΑ signature (brand, form, strength,
   pack); every other line inside the list is a dose line, a price row,
   the status wrap of a drug line, known boilerplate — or UNKNOWN, which
   is never part of a name, never dropped, and flags the medicine(s) next
   to it. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΠΑΠΑΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΓΙΩΡΓΟΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const A = 'DEPON TAB 500MG BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\n';
const B = 'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n';
const J = (v) => JSON.parse(JSON.stringify(v));
const DIR = path.join(__dirname, 'rx-samples');
const SAMPLES = fs.readdirSync(DIR).filter((x) => x.endsWith('.txt')).sort();

/* Flagged = a warning that sends the row to the form (not a mere choice or
   confirmation), so the row cannot be ticked. */
const SETTLED_IN_ROW = ['time', 'weekDay', 'monthly', 'duplicate', 'inPlan', 'strength', 'iuConfirm', 'highQty', 'sameDrugOtherDose', 'startDay', 'intervalCount', 'monthlyCount'];
const flagged = (PD, it) => it.warnings.some((w) => SETTLED_IN_ROW.indexOf(w) === -1) && PD.rxItemProblem(it) === 'warnings';

const PHRASES = [
	'TAKE WITH FOOD', 'CHEW WELL', 'AS DIRECTED', 'APPLY THIN LAYER', 'HALF TAB AT BEDTIME', '1/2 TAB AT BEDTIME',
	'MAX 3 TABS PER 24H', 'NOT WITH ALCOHOL', 'PRO RE NATA', 'Q8H', 'BID', 'X 2 ΜΕΡΕΣ', 'OFF THURSDAY', 'SKIP SUN',
	'2 TABS SAT', 'Όποτε χρειάζεται', 'Προ του ύπνου', 'Μισό την Κυριακή', 'για πόνο', 'Με άδειο στομάχι', 'NO SAT SUN',
	'ΑΝΑΛΟΓΑ ΜΕ ΤΟ ΣΑΚΧΑΡΟ', 'ACCORDING TO INR', 'TAPER', 'ALTERNATE DAYS 5MG', 'ΕΝΑΛΛΑΞ 1 ΚΑΙ 1/2', 'TABLET 2 AT NIGHT',
	'PAUSE 7D', '21 ON 7 OFF', 'HALF TAB 10MG', 'TAKE 1 TAB 10MG DAILY', 'ΜΙΣΟ TAB 10MG ΤΟ ΒΡΑΔΥ', 'ALTERNATE WITH SINTROM 1MG',
	'Με άφθονο νερό', 'SOS', 'EXCEPT SUNDAY 1/2 TAB', 'INR 2-3 TAB 1/4 ΤΗΝ ΚΥΡΙΑΚΗ', 'Σε περίπτωση πυρετού',
	'STOP 3 DAYS BEFORE SURGERY TAB', 'CAPS 20MG MORNING ONLY'
];

/* 1: the signature */
test('every drug line of the 22 samples is recognised; every medicine keeps its name', () => {
	const { PD } = load();
	for (const f of SAMPLES) {
		const r = PD.parsePrescription(fs.readFileSync(path.join(DIR, f), 'utf8'));
		for (const it of r.items) {
			assert.ok(it.name && !it.warnings.includes('name') && !it.warnings.includes('extra'), f + ' ' + it.name);
		}
	}
	for (const line of [
		'ATROST F.C.TAB 40MG/TAB BTx 28 (BLISTER 4x7) (Γενόσημο)', 'PLAVIX F.C.TAB 75MG/TAB BT x 28 (Πρωτότυπο σε θεραπευτική κατηγορία με',
		'INDERAL F.C.TAB 40MG/TAB ΒΤx30 (BLIST 1x30)', 'CIPROXIN F.C.TAB 500MG/TAB ΒΤΧ10', 'LADOSE CAPS 20MG/CAP btx28',
		'OZEMPIC INJ.SOL 1MG/0,74 (1 δόση) 1,34MG/ML 1 πρ. συσκ. τύπου', 'DYNASTAT PS.INJ.SOL 40MG/VIAL 1VIALX40MG+1AMPX2ML (Πρωτότυπο σε',
		'ZOVIRAX CREAM 5% (W/W) TUB x 10 G', 'TETAGAM P INJ.SO.PFS 250 IU/ML BT x 1 AMP x 1ML', 'NORDIMET INJ.SOL 15MG/0,6ML BTX 1PF.SYR X0,6ML',
		'TOUJEO (SOLOSTAR) IN.SO.PF.P 300 Units/ml BTx3 PF.PENS (Solostar) x1,5ml', 'MADOPAR TAB (200+50)MG/TAB BTx1FLx30 (Πρωτότυπο)',
		'SERETIDE DISKUS INH.PD.DOS (250+50)MC/DOSE BTx1 DISKUSx60 (Πρωτότυπο)', 'DEXA-RHINASPRAY-N NASPR.SUS (0,028+0,1717)MG/DOS FLx10 ML(100'
	]) {
		assert.strictEqual(PD.rxIsDrugLine(line), true, line);
	}
	for (const line of ['HALF TAB 10MG', 'HALF TAB AT BEDTIME', 'MAX 3 TABS PER 24H', 'TAKE WITH FOOD', 'ALTERNATE WITH SINTROM 1MG', 'BESPAR TAB 10MG',
		'ΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες', '25% 1 1,00 1,00', 'κατηγορία με γενόσημο)', 'THEN-X TAB 10MG']) {
		assert.strictEqual(PD.rxIsDrugLine(line), false, line);
	}
});

/* The reviewer's list of false refusals: read normally now. */
test('names with keyword-like words are ordinary drug lines', () => {
	const { PD } = load();
	const cases = [
		['SINEMET CR TAB 200/50 MG BTx100', 'SINEMET CR TAB 200/50MG'],
		['EXFORGE F.C.TAB 5/160 MG BTx28', 'EXFORGE F.C.TAB 5/160MG'],
		['SERETIDE DISKUS INH.PD.DOS 50/500 MCG/DOSE BTx1', 'SERETIDE DISKUS INH.PD.DOS 50/500MCG/δόση'],
		['PANADOL NIGHT F.C.TAB 500MG BTx20', 'PANADOL NIGHT F.C.TAB 500MG'],
		['ALGOFREN DAY & NIGHT TAB 400MG BTx10', 'ALGOFREN DAY & NIGHT TAB 400MG'],
		['ZYRTEC F.C.TAB 10MG BTx30 (ΔΙΣΚΙΑ)', 'ZYRTEC F.C.TAB 10MG'],
		['STOPTUSSIN TAB 4MG BTx20', 'STOPTUSSIN TAB 4MG'],
		['INREBIC CAPS 100MG BTx120', 'INREBIC CAPS 100MG'],
		['MICARDIS PLUS TAB 80/12,5 MG BTx28', 'MICARDIS PLUS TAB 80/12,5MG'],
		['DUODART CAPS 0,5/0,4 MG BTx30', 'DUODART CAPS 0,5/0,4MG']
	];
	for (const [line, name] of cases) {
		/* 1.28.2: capsules take «ΚΑΨΟΥΛΑ», as ΗΔΙΚΑ writes it (a tablet
		   dose for a box of capsules is now flagged «unitForm»). */
		const word = / CAPS /.test(line) ? 'ΚΑΨΟΥΛΑ' : 'ΔΙΣΚΙΑ';
		const it = PD.parsePrescription(HEAD + line + '\nΔΟΣΟΛΟΓΙΑ : 1 ' + word + ' x 2 φορές την ημέρα x 10 ημέρες\n' + P).items[0];
		assert.strictEqual(it.name, name, line);
		/* Round 6: a brand of several words is confirmed once. Round 7:
		   not when the other words are known qualifiers (PLUS, DISKUS). */
		const multi = /^(SINEMET CR|PANADOL NIGHT|ALGOFREN DAY) /.test(name);
		assert.deepStrictEqual(J(it.warnings), multi ? ['brandWords'] : [], line);
		it.confirmed = { name: true };
		assert.strictEqual(PD.rxItemProblem(it), '', line);
	}
});

/* 2: the reviewer's repros, both layouts */
test('instruction lines after DEPON (before or after its price line) are flagged, never a name, BESPAR never lost', () => {
	const { PD } = load();
	for (const s of PHRASES) {
		for (const layout of ['after', 'before']) {
			const tag = layout + ' ' + s;
			const r = PD.parsePrescription(HEAD + A + (layout === 'after' ? P + s + '\n' : s + '\n' + P) + B + P);
			assert.deepStrictEqual(J(r.items.map((i) => i.name)), ['DEPON TAB 500MG', 'BESPAR TAB 10MG'], tag);
			assert.ok(r.items[0].warnings.includes('extra'), tag);
			assert.ok(r.items[0].origText.includes(s), tag);
			assert.strictEqual(PD.rxItemProblem(r.items[0]), 'warnings', tag);
			if (layout === 'after') {
				/* Directly before BESPAR's drug line: whose is it? Both flagged. */
				assert.ok(r.items[1].warnings.includes('extra'), tag);
				assert.ok(r.items[1].origText.includes(s), tag);
			} else {
				assert.ok(!r.items[1].warnings.includes('extra'), tag);
			}
		}
	}
});

test('review: «HALF TAB AT BEDTIME» and «TAKE WITH FOOD» — no row ticked, nothing added', () => {
	for (const s of ['HALF TAB AT BEDTIME', 'MAX 3 TABS PER 24H', 'TAKE WITH FOOD']) {
		const { PD, w } = load();
		PD.s.startDate = isoAhead(1);
		PD.buildApp();
		const d = w.document;
		const drop = d.getElementById('pd-rx-drop');
		drop.value = HEAD + A + P + s + '\n' + B + P;
		drop.dispatchEvent(new w.Event('input', { bubbles: true }));
		const boxes = Array.from(d.querySelectorAll('[data-rx-include]'));
		assert.strictEqual(boxes.length, 2, s);
		assert.ok(boxes.every((b) => !b.checked && b.disabled), s);
		assert.ok(Array.from(d.querySelectorAll('.pd-rx-name'), (e) => e.textContent).every((t) => !t.includes(s)), s);
		d.getElementById('pd-rx-add').click();
		assert.strictEqual(PD.s.items.length, 0, s);
		closeAll();
	}
});

test('lines between the drug line and its dose line flag that medicine and are shown', () => {
	const { PD } = load();
	for (const s of ['Με άφθονο νερό', 'TAKE WITH FOOD', 'ALTERNATE WITH SINTROM 1MG', '1/2 ΤΟ ΒΡΑΔΥ', 'HALF TAB 10MG']) {
		const it = PD.parsePrescription(HEAD + 'BESPAR TAB 10MG BTx30\n' + s + '\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες\n' + P).items[0];
		assert.strictEqual(it.name, 'BESPAR TAB 10MG', s);
		assert.ok(it.warnings.includes('extra'), s);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', s);
		assert.ok(it.origText.includes(s), s);
	}
});

test('3: a dose line with no drug line above gets no name (form), and the lines above are shown', () => {
	const { PD } = load();
	const r = PD.parsePrescription(HEAD + A + P + 'HALF TAB 10MG\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 10 ημέρες\n' + P);
	assert.strictEqual(r.items.length, 2);
	assert.strictEqual(r.items[1].name, '');
	assert.ok(r.items[1].warnings.includes('name'));
	assert.ok(r.items[1].origText.includes('HALF TAB 10MG'));
	assert.ok(r.items[0].warnings.includes('extra'));
});

/* 4: L2 */
test('4: a mangled anchor, also wrapped over lines, never loses the medicine', () => {
	const { PD } = load();
	const Z = 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n';
	const variants = [
		'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓ1Α : 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n',
		'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓ1Α : 1 ΔΙΣΚΙΑ x κάθε 8 ώρες x 7 ημέρες\n',
		'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓ1Α : 1 ΔΙΣΚΙΑ x 1 φορά την\nημέρα x 30\nημέρες\n',
		'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓΙΑ ; 1 ΔΙΣΚΙΑ x κάθε 12 ώρες x 10 ημέρες\n',
		'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓΙΑ 1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες\n',
		'BESPAR TAB 10MG BTx30\nΔΟΣΟΛΟΓ1Α : 1 ΔΙΣΚΙΑ x εφάπαξ x 1 ημέρα\n'
	];
	for (const v of variants) {
		const r = PD.parsePrescription(HEAD + Z + v);
		assert.deepStrictEqual(J(r.items.map((i) => i.name)), ['ZINADOL F.C.TAB 500MG', 'BESPAR TAB 10MG'], v);
		assert.ok(r.items[1].warnings.includes('noDose'), v);
		assert.ok(r.expectedCount > r.readCount, v);
		assert.ok(r.unreadDoseLines >= 1, v);
	}
});

/* 5: fuzz */
function firstDrugLine(lines, PD) {
	return lines.findIndex((l) => /ΔΟΣΟΛΟΓΙΑ/.test(l) || PD.rxIsDrugLine(l.trim()));
}

/* The phrase is visible where it must be: on a medicine that cannot be
   added as it is, or in the blocking note (unreadText). */
function assertCaught(PD, r, s, tag) {
	const flat = (t) => String(t || '').replace(/\s+/g, ' ');
	const owners = r.items.filter((i) => flat(i.origText).includes(s) || flat(i.source).includes(s));
	const noted = (r.unreadText || []).some((t) => t.includes(s));
	assert.ok(owners.length >= 1 || noted, tag + ' not shown');
	assert.ok(owners.every((i) => flagged(PD, i)), tag + ' not flagged');
}

test('fuzz: 40 instruction phrases as a line of their own at every position of 3 samples', () => {
	const { PD } = load();
	for (const f of ['04-nine-drugs-weekly-injection-2pages.txt', '19-foster-oral-solution-ml.txt', '20-five-drugs-dose-line-split.txt']) {
		const lines = fs.readFileSync(path.join(DIR, f), 'utf8').replace(/\r/g, '').split('\n');
		const base = PD.parsePrescription(lines.join('\n'));
		const baseNames = J(base.items.map((i) => i.name));
		const first = firstDrugLine(lines, PD);
		for (let pos = 0; pos <= lines.length; pos++) {
			if (pos < first && pos % 5) {
				continue; /* the first page's header: a sample of positions */
			}
			for (const s of PHRASES) {
				const r = PD.parsePrescription(lines.slice(0, pos).concat([s], lines.slice(pos)).join('\n'));
				const tag = f + ' @' + pos + ' ' + s;
				assert.ok(r.items.length >= base.items.length, tag);
				assert.ok(r.items.every((i) => !i.name.includes(s)), tag);
				if (pos > first) {
					assertCaught(PD, r, s, tag);
				} else if (pos < first) {
					assert.deepStrictEqual(J(r.items.map((i) => i.name)), baseNames, tag);
				}
			}
		}
	}
});

/* Round 5: the phrase JOINED onto the start or the end of every line
   (drug, dose, price, status, header, footer, totals, barcode pages). */
test('fuzz: instruction phrases joined onto the start and end of every line after the first medicine', () => {
	const { PD } = load();
	const phrases = ['TAKE WITH FOOD', 'HALF TAB AT BEDTIME', 'MAX 3 TABS PER 24H', 'Προ του ύπνου', 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ',
		'0,25 0,50 0,25', 'TAKE 1 TAB 10MG DAILY', 'Με άδειο στομάχι', 'SOS'];
	for (const f of ['04-nine-drugs-weekly-injection-2pages.txt', '19-foster-oral-solution-ml.txt', '20-five-drugs-dose-line-split.txt']) {
		const lines = fs.readFileSync(path.join(DIR, f), 'utf8').replace(/\r/g, '').split('\n');
		const base = PD.parsePrescription(lines.join('\n'));
		const first = firstDrugLine(lines, PD);
		for (let pos = first; pos < lines.length; pos++) {
			if (!lines[pos].trim()) {
				continue;
			}
			for (const s of phrases) {
				for (const where of ['end', 'start']) {
					const joined = where === 'end' ? lines[pos] + ' ' + s : s + ' ' + lines[pos];
					const r = PD.parsePrescription(lines.slice(0, pos).concat([joined], lines.slice(pos + 1)).join('\n'));
					const tag = f + ' @' + pos + ' ' + where + ' ' + s + ' | ' + lines[pos];
					assert.ok(r.items.length >= base.items.length, tag);
					assert.ok(r.items.every((i) => !i.name.includes(s)), tag);
					assertCaught(PD, r, s, tag);
				}
			}
		}
	}
});
