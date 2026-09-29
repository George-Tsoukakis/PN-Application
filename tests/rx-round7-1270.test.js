/* 1.27.1 round 7: what 235 real ΗΔΙΚΑ print-outs showed (anonymised
   report; every string below is synthetic). Combination strengths kept
   whole or confirmed, the page furniture and the pharmacy stickers
   recognised, wrapped drug lines joined, drug-line variants, the brand
   qualifiers, new dose words, the once-a-week medicines, the dispensed
   quantity, «Ίδια ώρα για όλα», and unread furniture in the top note only. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const price = (boxes) => '25% ' + (boxes || 1) + ' 1,00 1,00 1,00 0,00 0,25 0,75\n';
const block = (drug, dose, boxes) => drug + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + price(boxes);
const TOTALS = '0% 10% 25% Άλλο\n0,00 0,00 1,00 0,00\nΣΥΝΟΛΟ : 1,00 €\n';
const DAILY30 = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';
const J = (v) => JSON.parse(JSON.stringify(v));
const parse = (PD, text) => PD.parsePrescription(text);
const first = (PD, drug, dose) => parse(PD, HEAD + block(drug, dose || DAILY30)).items[0];

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	env.d = env.w.document;
	return env;
}

function paste(env, text) {
	const drop = env.d.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new env.w.Event('input', { bubbles: true }));
}

/* ---- 1. strengths ---- */

test('a combination strength with IU / mcg / % is kept whole in the name, with no confirmation needed', () => {
	const { PD } = load();
	const cases = [
		['FOOVANCE TAB (70mg+140mcg) (5600IU)/TAB BTx4 σε BLIST (Πρωτότυπο σε θεραπευτική κατηγορία με γενόσημο)', 'FOOVANCE TAB (70MG+140MCG) (5600IU)'],
		['BARCAL OR.DISP.TA (1500MG+2000IU)/TAB BOTTLEx30 TABS (Γενόσημο)', 'BARCAL OR.DISP.TA (1500MG+2000IU)'],
		['BARCAL OR.DISP.TA (1500MG+1000 IU)/TAB BOTTLEx30 TABS (Γενόσημο)', 'BARCAL OR.DISP.TA (1500MG+1000IU)'],
		['FOODROP EY.DRO.SOL (2%+0,5%) w/v BTx 1VIAL x 5ML', 'FOODROP EY.DRO.SOL (2%+0,5%)'],
		/* The ones already kept whole stay as they were. */
		['FOOMET F.C.TAB (50+1000)MG/TAB BT X60 ΣΕ BLISTERS (Γενόσημο)', 'FOOMET F.C.TAB (50+1000)MG']
	];
	for (const [drug, name] of cases) {
		const it = first(PD, drug);
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('strength'), drug);
		assert.ok(!it.warnings.includes('extra') && !it.warnings.includes('name'), drug);
	}
});

test('a strength read only in part fires the «strength» confirmation — never a silently shortened name', () => {
	const { PD } = load();
	for (const drug of [
		'FOOLIN PLUS ORAL.SOL 800MG+0,200(0,185) MG/15ML VIAL BT X 10 (VIALS X 15ML)',
		'FOOPIC INJ.SOL 1MG/0,74 (1 δόση) 1,34MG/ML 5MG 1 πρ. συσκ. τύπου πέναςX1, 5ML+4 βελόνες (Πρωτότυπο)',
		'FOOCARD GR.OR.SD 1229,6(121,5Mg)MG/SACH BTX20SACHX5G (Πρωτότυπο)'
	]) {
		const it = first(PD, drug, '1 ΦΑΚΕΛΑΚΙ x 1 φορά την ημέρα x 28 ημέρες');
		assert.ok(it.warnings.includes('strength'), drug + ' ' + it.warnings);
		it.dailyTime = 'morning';
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', drug);
		it.confirmed = { name: true };
		assert.notStrictEqual(PD.rxPending(it).map((p) => p.code).indexOf('strength'), 0, drug);
	}
	/* Property: whenever a number with a unit of the drug line's strength
	   part is missing from the name, the confirmation is there. */
	const lines = [
		'FOO TAB (5MG+10MG) (20IU)/TAB BTx30', 'FOO TAB 5MG+10MG/TAB BTx30', 'FOO TAB 1(5MG)MG BTx30',
		'FOO TAB 10MG 20MG BTx30', 'FOO TAB 10MG/TAB BTx30', 'FOO TAB (10+20)MG/TAB BTx30', 'FOO TAB (1MG+2MCG)/TAB BTx30'
	];
	for (const drug of lines) {
		const it = first(PD, drug);
		const head = drug.replace(/^FOO TAB /, '').replace(/ BTx30$/, '').toUpperCase().replace(/\/TAB$/, '');
		const nums = head.match(/\d+(?:[.,]\d+)?/g) || [];
		const allIn = nums.every((x) => it.name.includes(x));
		assert.ok(allIn || it.warnings.includes('strength'), drug + ' → ' + it.name);
	}
});

test('μg / µg is micrograms, read before any Greek/Latin folding (never «MG»)', () => {
	const { PD } = load();
	for (const drug of ['FOOROX TAB 112μg/TAB BTx30 (Γενόσημο)', 'FOOROX TAB 112 µg/TAB BTx30 (Γενόσημο)', 'FOOROX TAB 112μG/TAB BTx30']) {
		const it = first(PD, drug);
		if (/μG/.test(drug)) {
			/* «μG» is not the micro sign followed by «g»: never read as MCG,
			   never as MG — no strength and the confirmation. */
			assert.ok(!/112MG/.test(it.name), drug);
			continue;
		}
		assert.strictEqual(it.name, 'FOOROX TAB 112MCG', drug);
		assert.ok(!it.warnings.includes('name') && !it.warnings.includes('extra'), drug);
	}
});

test('the PDF line-break hyphen U+FFFE reads as «-»', () => {
	const { PD } = load();
	const it = first(PD, 'FOOSTER INH.SOL.P (100+6)MCG/DOSE (EX\ufffeVALVE) BTX1 περιέκτη με μετρητή δόσηςX120 DOSES (Πρωτότυπο)',
		'1 ΕΙΣΠΝΟΕΣ ΔΙΑΛ ΔΟΣΕΙΣ x 2 φορές την ημέρα x 30 ημέρες');
	assert.strictEqual(it.name, 'FOOSTER INH.SOL.P (100+6)MCG/δόση');
	assert.deepStrictEqual(J(it.warnings), []);
	assert.match(it.origText, /EX-VALVE/);
});

/* ---- 2. furniture, stickers, wrapped lines ---- */

const FURNITURE = [
	'\u00cdABCDEFGH1\u00ce', '\u00cdABCDEFGH1\u00ce ΗΜ/ΝΙΑ ΕΚΤΕΛΕΣΗΣ:', '0000000000000 130',
	'Ι.Κ.Α. - πρώην Ο.Π.Α.Δ.', 'E.T.A.A. - Τ.Υ.Μ.Ε.Δ.Ε.', 'Δικαιούχοι του Ν.4368/2016', 'Πολίτες Εξωτερικού',
	'Ίδρυμα Κοινωνικών Ασφαλίσεων - πρώην Ο.Π.Α.Δ. 0000000000000 130', 'ΕΤΑΑ - Τομέας Υγείας Υγειονομικών 0000000000000 140',
	'ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΚΥΗΣΗ ΚΑΙ ΛΟΧΕΙΑ', '(ΥΠΟΓΡΑΦΗ) (ΥΠΟΓΡΑΦΗ ΑΣΦΑΛΙΣΜΕΝΟΥ) (ΥΠΟΓΡΑΦΗ-ΣΦΡΑΓΙΔΑ)Σελίδα 1/1',
	'(ΥΠΟΓΡΑΦΗ) (ΥΠΟΓΡΑΦΗ-ΣΦΡΑΓΙΔΑ)Σελίδα 1/2', 'Batch: 25-025'
];

test('F01–F08: page furniture matched whole is never unread text and never flags a medicine', () => {
	const { PD } = load();
	for (const f of FURNITURE) {
		for (const where of ['after', 'between']) {
			const text = where === 'after'
				? HEAD + block('DEPON TAB 500MG/TAB BTx20 (Γενόσημο)', DAILY30) + TOTALS + f + '\n'
				: f + '\n' + HEAD + block('DEPON TAB 500MG/TAB BTx20 (Γενόσημο)', DAILY30) + TOTALS;
			const r = parse(PD, text);
			assert.deepStrictEqual(J(r.unreadText), [], where + ' ' + f);
			assert.deepStrictEqual(J(r.items[0].warnings), ['time'], where + ' ' + f);
		}
	}
});

test('furniture with anything added to it is still unread text, named in the note', () => {
	const { PD } = load();
	for (const f of ['0000000000000 130 ΠΡΩΙ', 'ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: ΚΥΗΣΗ 1 ΔΙΣΚΙΟ', 'Ι.Κ.Α. - πρώην Ο.Π.Α.Δ. SOS', 'Batch: 25-025-1 x2',
		'(ΥΠΟΓΡΑΦΗ)Σελίδα 1/1 ΜΙΣΟ', '\u00cdABCDEFGH1\u00ce ΒΡΑΔΥ']) {
		const r = parse(PD, HEAD + block('DEPON TAB 500MG/TAB BTx20 (Γενόσημο)', DAILY30) + TOTALS + f + '\n');
		assert.ok(r.unreadText.some((x) => x.includes(f.split(' ').pop())), f + ' ' + J(r.unreadText));
	}
});

test('point 6: unread text outside the list goes to the top note only; text inside a medicine block flags that medicine', () => {
	const { PD } = load();
	const r = parse(PD, HEAD + block('DEPON TAB 500MG/TAB BTx20 (Γενόσημο)', DAILY30) + TOTALS + 'ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n');
	assert.deepStrictEqual(J(r.unreadText), ['ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ']);
	assert.ok(!r.items[0].warnings.includes('extra'));
	assert.ok(!r.items[0].origText.includes('ΠΟΝΑΕΙ'));
	/* Inside the block (between the drug line and its dose line, or
	   after the dose line in the list): the medicine is flagged. */
	const inside = parse(PD, HEAD + 'DEPON TAB 500MG/TAB BTx20 (Γενόσημο)\nΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\nΔΟΣΟΛΟΓΙΑ : ' + DAILY30 + '\n' + price() + TOTALS);
	assert.ok(inside.items[0].warnings.includes('extra'));
	assert.ok(inside.items[0].warnings.includes('asNeeded'));
	const after = parse(PD, HEAD + 'DEPON TAB 500MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : ' + DAILY30 + '\nΜΟΝΟ ΑΝ ΠΟΝΑΕΙ\n' + price() + TOTALS);
	assert.ok(after.items[0].warnings.includes('extra'));
});

test('the review shows unread furniture-like text in the blocking note, not on the last medicine', () => {
	const env = app();
	paste(env, HEAD + block('DEPON TAB 500MG/TAB BTx20 (Γενόσημο)', DAILY30) + block('BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)', DAILY30) + TOTALS + 'ΚΑΤΙ ΑΓΝΩΣΤΟ\n');
	const note = env.d.getElementById('pd-rx-count-note');
	assert.ok(note && note.textContent.includes('ΚΑΤΙ ΑΓΝΩΣΤΟ'));
	assert.ok(!env.PD.s.rx.rows[1].item.warnings.includes('extra'));
});

/* A list block wrapped over several lines, and its sticker after the
   totals (wrapped differently, with its Batch glued on). */
const WRAPPED = 'FOOSTATIN/WIN MEDICA F.C.TAB\n10MG/TAB BTx30 (BLIST\n3x10) (Γενόσημο)';

test('S01: a wrapped drug line is one medicine; the prescription text keeps the original line breaks', () => {
	const { PD } = load();
	const r = parse(PD, HEAD + block(WRAPPED, DAILY30) + TOTALS);
	assert.strictEqual(r.items.length, 1);
	const it = r.items[0];
	assert.strictEqual(it.name, 'FOOSTATIN/WIN MEDICA F.C.TAB 10MG');
	assert.deepStrictEqual(J(it.warnings), ['time']);
	assert.strictEqual(it.origText, WRAPPED + '\nΔΟΣΟΛΟΓΙΑ: ' + DAILY30);
	assert.deepStrictEqual(J(r.unreadText), []);
});

test('S01: an instruction line inside a wrapped drug line is never absorbed', () => {
	const { PD } = load();
	for (const ins of ['X 2', '2', '1/2', 'ΜΙΣΟ', 'X2', 'PO']) {
		const r = parse(PD, HEAD + block('FOOSTATIN F.C.TAB 10MG/TAB\n' + ins + '\nBTx30 (Γενόσημο)', DAILY30) + TOTALS);
		const flagged = r.items.some((it) => it.warnings.includes('extra') || it.warnings.includes('name')) || r.unreadText.length > 0;
		assert.ok(flagged, ins + ' ' + J(r.items.map((i) => [i.name, i.warnings])));
		assert.ok(r.items.every((it) => !it.name.includes(ins) || /^FOOSTATIN/.test(ins)), ins);
	}
});

test('S01: the end of a wrapped pack with its status joins; an instruction before the status never does', () => {
	const { PD } = load();
	const joins = [
		'FOOLINOR ORAL.SOL 2MG/5ML BT X 1 BOTTLE X 150ML + ΔΟΣΟΜΕΤΡΙΚΗ\nΣΥΡΙΓΓΑ Χ 5ML (Γενόσημο)',
		'FOOCORT INH.SUS.N 0,5MG/ML BTx40 πλαστ. φιαλίδια (8 φακ. x 5 πλαστ.\nφιαλίδια ) x2ML (Πρωτότυπο σε θεραπευτική κατηγορία με γενόσημο)',
		'FOOMISTA NASPR.SUS (137+50)MCG/ACTUATION BTX 1 ΦΙΑΛΗ(25ML) X 23G\n(ΤΟΥΛΑΧΙΣΤΟΝ 120 ΨΕΚΑΣΜΟΙ) (Γενόσημο)',
		'FOOSTER INH.SOL.P (100+6)MCG/DOSE (EX-VALVE) BTX1 περιέκτη με μετρητή\nδόσηςX120 DOSES(EX-VALVE) (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)'
	];
	for (const drug of joins) {
		const r = parse(PD, HEAD + block(drug, '1 ΡΙΝΙΚΟ ΣΠΡΑΥ ΔΟΣΕΙΣ x 2 φορές την ημέρα x 15 ημέρες') + TOTALS);
		assert.deepStrictEqual(J(r.items[0].warnings), [], drug);
		assert.ok(r.items[0].origText.startsWith(drug + '\n'), drug);
	}
	for (const ins of ['2 TABS (Γενόσημο)', 'X 2 (Γενόσημο)', '1/2 (Γενόσημο)', 'ΜΙΣΟ (Γενόσημο)', 'X3 (Γενόσημο)']) {
		const r = parse(PD, HEAD + block('FOOSTATIN F.C.TAB 10MG/TAB BTx30\n' + ins, DAILY30) + TOTALS);
		assert.ok(r.items[0].warnings.includes('extra'), ins + ' ' + r.items[0].warnings);
	}
});

test('S01: a wrap glued after a price row keeps a one-word brand («PO» never reaches a name)', () => {
	const { PD } = load();
	const r = parse(PD, HEAD + 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ : ' + DAILY30 + '\n' + price().trim() + ' PO BESPAR TAB 10MG/TAB\nBTx30 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : ' + DAILY30 + '\n' + price() + TOTALS);
	assert.strictEqual(r.items.length, 2);
	assert.ok(r.items.every((i) => !/(^|\s)PO\s/.test(i.name)), J(r.items.map((i) => i.name)));
	/* The second medicine is not settled without the pharmacist. */
	assert.strictEqual(PD.rxItemProblem(Object.assign({}, r.items[1], { dailyTime: 'morning' })), 'warnings');
	assert.match(r.items[1].origText, /PO BESPAR/);
});

test('S03: the sticker repeating the whole list block (wrapped otherwise, Batch glued) is not a medicine', () => {
	const { PD } = load();
	const sticker = 'FOOSTATIN/WIN MEDICA\nF.C.TAB 10MG/TAB BTx30 (BLIST 3x10)\n(Γενόσημο) Batch: 25-025\nBarcode: 0000000000000\nPC: 00000000000000\nSN: ABC12345\n';
	const r = parse(PD, HEAD + block(WRAPPED, DAILY30) + TOTALS + sticker);
	assert.strictEqual(r.items.length, 1);
	assert.deepStrictEqual(J(r.unreadText), []);
	assert.deepStrictEqual(J(r.items[0].warnings), ['time']);
	assert.strictEqual(r.expectedCount, 1);
	/* A sticker of a medicine that is not in the list is still a medicine
	   without a dose line, flagged. */
	const other = 'BARSTATIN F.C.TAB 20MG/TAB BTx30\nBarcode: 0000000000000\nPC: 00000000000000\n';
	const r2 = parse(PD, HEAD + block(WRAPPED, DAILY30) + TOTALS + other);
	assert.ok(r2.items.some((it) => /BARSTATIN/.test(it.name) && it.warnings.includes('noDose')));
});

/* ---- drug-line variants (V01–V11) ---- */

test('V01–V11: the drug-line variants of the real print-outs are drug lines', () => {
	const { PD } = load();
	const cases = [
		['FOOMAG 300 EF.TAB 300MG/TAB BTx20 (Γενόσημο)', 'FOOMAG 300 EF.TAB 300MG'],
		['GARDASIL 9 INJ.SU.PFS 0,5ML (DOSE) BTx1 PF.SYR x 0,5ML (Πρωτότυπο)', 'GARDASIL 9 INJ.SU.PFS 0,5ML'],
		['FOODIC /TARGET CREAM 2% TUBX30G (Γενόσημο)', 'FOODIC /TARGET CREAM 2%'],
		['FOOTA(ΕΜΒΟΛΙΟ ΚΑΤΑ ΤΗΣ ΗΠΑΤΙΤΙΔΑΣ Α) INJ.SUSP 25 U/0,5ML(1ΔΟΣΗ) BTx1PF,SYR,x 0,5 ML (Πρωτότυπο)', 'FOOTA(ΕΜΒΟΛΙΟ ΚΑΤΑ ΤΗΣ ΗΠΑΤΙΤΙΔΑΣ Α) INJ.SUSP 25U/0,5ML'],
		['FOSAMAX ONCE WEEKLY TAB 70MG/TAB BTx4 (BLISTER)', 'FOSAMAX ONCE WEEKLY TAB 70MG'],
		['FOOBAN OINTMENT 2% W/W TUBx15G (Πρωτότυπο)', 'FOOBAN OINTMENT 2%'],
		['FOOPROST EY.DRO.SOL 0,005% W/V BT x 1 VIAL x 2,5 ML (Γενόσημο)', 'FOOPROST EY.DRO.SOL 0,005%'],
		['FOOSOR F.C.TAB 100MG 4X10 (Πρωτότυπο)', 'FOOSOR F.C.TAB 100MG'],
		['FOOHEP INJ.SOL 4500antiXA iu/0,45ml PF,SYR BTx10PF,SYRS,x0,45ML (Πρωτότυπο)', 'FOOHEP INJ.SOL 4500ANTIXA IU/0,45ML'],
		['FOORAPID FLEX PEN INJ.SOL 100 U/ML 5PF,SYR,X3ML (Πρωτότυπο)', 'FOORAPID FLEX PEN INJ.SOL 100U/ML'],
		['FOOSAN SUPP 100MG/SUP BTX5', 'FOOSAN SUPP 100MG'],
		['FOOTAB TAB 40MG/TAB BT x 36 (BLIST 3 x12) (Γενόσημο)', 'FOOTAB TAB 40MG'],
		['FOOCA CAPS 75MG/CAP BTX56ΚΥΨΕΛΗ (Πρωτότυπο)', 'FOOCA CAPS 75MG'],
		['FOOSTER INH.SOL.P (100+6)MCG/DOSE BTX1 συσκευή εισπνοών x120 (Πρωτότυπο)', 'FOOSTER INH.SOL.P (100+6)MCG/δόση'],
		['FOOTROL TAB 10MG/TAB BTx30 (Blister PVC/PVDC/ALUMINIUM)', 'FOOTROL TAB 10MG']
	];
	for (const [line, name] of cases) {
		assert.strictEqual(PD.rxIsDrugLine(line), true, line);
		const it = first(PD, line, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 10 ημέρες');
		assert.strictEqual(it.name, name, line);
		assert.ok(!it.warnings.includes('brandWords') && !it.warnings.includes('extra') && !it.warnings.includes('name'), line + ' ' + it.warnings);
	}
	/* Still not drug lines: no pack, instruction counts, «ONCE WEEKLY» elsewhere. */
	for (const line of ['BESPAR TAB 10MG', 'BESPAR TAB 10MG 1X1', 'BESPAR TAB 10MG 2X1', 'BESPAR ONCE WEEKLY TAB 10MG/TAB BTx30', 'TAKE 300 TAB BTx20']) {
		assert.strictEqual(PD.rxIsDrugLine(line), false, line);
	}
});

test('a vaccine with no strength is read; 1.27.2: with no number and unit on the line, nothing to confirm', () => {
	const { PD } = load();
	for (const line of ['FOOYON INJ.SUSP BTx1PF SYR x0,5ML (Πρωτότυπο)', 'FOORIX HEXA PD.SU.IN.S BTx1VIAL+1PF,SYR,x (Πρωτότυπο)',
		'FOOSERO INJ.SUSP BTx1 PF SYR x0,5ML με βελόνα με βελόνα (Πρωτότυπο)']) {
		const it = first(PD, line, '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x εφάπαξ x 1 ημέρες');
		assert.ok(it.name && !it.warnings.includes('name'), line);
		assert.ok(!it.warnings.includes('strength'), line);
		assert.deepStrictEqual(J(PD.rxPending(Object.assign({}, it, { dailyTime: 'morning' }))), [], line);
	}
});

test('B01: known qualifiers, numbers and «INN/MAH» are not glued words; anything else still asks («PO BESPAR»)', () => {
	const { PD } = load();
	for (const line of ['FOO PLUS TAB 10MG/TAB BTx30', 'FOO FORTE TAB 10MG/TAB BTx30', 'FOO DISKUS INH.PD.DOS (250+50)MC/DOSE BTx1 DISKUSx60',
		'FOO TOCAS PR.TAB 0,4MG/TAB BTx30', 'FOO P INJ.SO.PFS 250 IU/ML BT x 1 AMP x 1ML', 'FOO GR GR.TAB 35MG/TAB BTX4',
		'FOOSTATIN/WIN MEDICA F.C.TAB 10MG/TAB BTx30', 'PREVENAR 20 INJ.SUSP 0,5ML/PF.SYR 1 PF.SYR X 0,5ML + 1 ΒΕΛΟΝΑ']) {
		const it = first(PD, line, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 10 ημέρες');
		assert.ok(!it.warnings.includes('brandWords'), line);
	}
	for (const line of ['PO BESPAR TAB 10MG/TAB BTx30', 'EXTRA BESPAR TAB 10MG/TAB BTx30', 'PO/BID BESPAR TAB 10MG/TAB BTx30', 'BESPAR MISC TAB 10MG/TAB BTx30',
		'XX BESPAR PLUS TAB 10MG/TAB BTx30']) {
		const it = first(PD, line, '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 10 ημέρες');
		assert.ok(it.warnings.includes('brandWords') || it.warnings.includes('extra'), line + ' ' + it.warnings);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', line);
	}
});

test('dose words: ΟΦΘ., ΟΤΙΚΟ, ΕΛΕΓΧ ΑΠΟΔ, ΒΡΑΔΕΙΑΣ ΑΠΟΔΕΣ (slow release, not evening), ΚΟΝΙΣ, ΕΛΕΩΔΕΣ, ΔΟΣ', () => {
	const { PD } = load();
	const cases = [
		['FOO EY.DRO.SOL 0,5% BT x 1 VIAL x 5 ML', '1 ΟΦΘ.ΣΤΑΓΟΝΕΣ x 2 φορές την ημέρα x 10 ημέρες', 'drops'],
		['FOO EA.DRO.SOL 0,3% FLx5 ML', '2 ΟΤΙΚΟ ΣΤΑΓΟΝΕΣ x 2 φορές την ημέρα x 7 ημέρες', 'drops'],
		['FOO PR.TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ ΕΛΕΓΧ ΑΠΟΔ x 1 φορά την ημέρα x 30 ημέρες', 'tablet'],
		['FOO PR.TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ ΒΡΑΔΕΙΑΣ ΑΠΟΔΕΣ x 1 φορά την ημέρα x 30 ημέρες', 'tablet'],
		['FOO INH.PD 200MCG/DOSE BTx1 x 60 DOSES', '1 ΚΟΝΙΣ ΓΙΑ ΕΙΣΠΝΟΗ x 2 φορές την ημέρα x 30 ημέρες', 'inhale'],
		['FOO OILY.INJ 200MG/ML AMP BTx1 AMPx1 ML', '1 ΕΝΕΣΗ ΕΛΕΩΔΕΣ ΔΙΑΛ x 1 φορά την εβδομάδα x 28 ημέρες', 'injection']
	];
	for (const [drug, dose, unit] of cases) {
		const it = first(PD, drug, dose);
		assert.strictEqual(it.doseUnit, unit, dose);
		assert.ok(!it.warnings.includes('phrase') && !it.warnings.includes('unit'), dose + ' ' + it.warnings);
	}
	/* «ΒΡΑΔΕΙΑΣ ΑΠΟΔΕΣ» never picks the evening: the time is still chosen. */
	const slow = first(PD, 'FOO PR.TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ ΒΡΑΔΕΙΑΣ ΑΠΟΔΕΣ x 1 φορά την ημέρα x 30 ημέρες');
	assert.strictEqual(slow.dailyTime, null);
	assert.ok(slow.warnings.includes('time'));
	/* «ΔΟΣ» describes; with no countable word the unit is still asked. */
	const pow = first(PD, 'FOO PS.OR.SOL 1G BTx30 SACH', '1 ΠΟΣ.ΣΚΟΝΗ ΔΟΣ ΔΙΑΛΥΜΑ x 1 φορά την ημέρα x 30 ημέρες');
	assert.strictEqual(pow.doseUnit, '');
	assert.ok(pow.warnings.includes('unit'));
});

test('insulin: «1/2 ΕΝΕΣΗ» is not ½ IU — the pharmacist decides', () => {
	const { PD } = load();
	const it = first(PD, 'FOORAPID FLEX PEN INJ.SOL 100 U/ML 5PF,SYR,X3ML (Πρωτότυπο)', '1/2 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 3 φορές την ημέρα x 28 ημέρες');
	assert.notStrictEqual(it.doseUnit, 'iu');
	assert.ok(it.warnings.includes('insulinUnits'));
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
	/* 22 units of a named insulin: units, confirmed. */
	const big = first(PD, 'TOUJEO (SOLOSTAR) IN.SO.PF.P 300 Units/ml BTx3 PF.PENS (Solostar) x1,5ml', '22 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες');
	assert.strictEqual(big.doseUnit, 'iu');
	assert.ok(big.warnings.includes('iuConfirm'));
});

/* ---- 3. once-a-week medicines ---- */

test('weekly-only medicines scheduled more often than weekly: flagged in the import and sent to the form', () => {
	const { PD } = load();
	const daily = [
		['FOOPIC', 'OZEMPIC INJ.SOL 0,25MG/0,19 (1 δόση) 1,34MG/ML 1 πρ. συσκ. τύπου πέναςX1, 5ML+4 βελόνες (Πρωτότυπο)', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'TRULICITY INJ.SOL 1,5MG/0,5ML BTx4 PF.PENS', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'MOUNJARO INJ.SOL 5MG/0,5ML BTx4 PF.PENS', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'BYDUREON PS.INJ.SUS 2MG BTx4 PF.PENS', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'FOSAMAX ONCE WEEKLY TAB 70MG/TAB BTx4 (BLISTER)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'FOSAVANCE TAB (70mg+140mcg) (5600IU)/TAB BTx4 σε BLIST', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'ALENDRONATE/ACME TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 28 ημέρες'],
		['', 'ACTONEL OAW F.C.TAB 35MG/TAB BTx 4 (σε BLISTER)', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες'],
		['', 'RISEDRONATE/ACME F.C.TAB 35MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες']
	];
	for (const [, drug, dose] of daily) {
		const it = first(PD, drug, dose);
		assert.ok(it.warnings.includes('weeklyOnly'), drug + ' ' + it.warnings);
		it.warnings = [];
		assert.strictEqual(PD.rxItemProblem(Object.assign({}, it, { dailyTime: 'morning' })), 'warnings', 'even with the warning lost: ' + drug);
	}
	/* Weekly, daily-form brands and RYBELSUS: nothing. */
	const fine = [
		['OZEMPIC INJ.SOL 1MG/0,74 (1 δόση) 1,34MG/ML 1 πρ. συσκ. τύπου πέναςX1, 5ML+4 βελόνες (Πρωτότυπο)', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 28 ημέρες'],
		['RYBELSUS TAB 7MG/TAB BTx30', DAILY30],
		['FOSAMAX TAB 10MG/TAB BTx30', DAILY30],
		['ACTONEL F.C.TAB 5MG/TAB BTx28', DAILY30],
		['BYETTA INJ.SOL 10MCG/DOSE BTx1 PF.PENS', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 2 φορές την ημέρα x 30 ημέρες']
	];
	for (const [drug, dose] of fine) {
		assert.ok(!first(PD, drug, dose).warnings.includes('weeklyOnly'), drug);
	}
});

test('weekly-only names: look-alike Greek letters, generic names, and the frequency test', () => {
	const { PD } = load();
	const often = (name, extra) => PD.weeklyOnlyTooOften(Object.assign({ name: name, freq: '24h' }, extra || {}));
	assert.strictEqual(often('ΟΖΕΜΡΙC'), true, 'Greek Ο Ζ Ε Μ Ρ Ι with a Latin C');
	assert.strictEqual(often('ΟΖΕΜΠΙΚ'), true);
	assert.strictEqual(often('SEMAGLUTIDE INJ.SOL 1MG'), true);
	assert.strictEqual(often('SEMAGLUTIDE TAB 7MG'), false);
	assert.strictEqual(often('DULAGLUTIDE'), true);
	assert.strictEqual(often('TIRZEPATIDE'), true);
	assert.strictEqual(often('ΑΛΕΝΔΡΟΝΙΚΟ 70MG'), true);
	assert.strictEqual(often('ALENDRONATE 10MG'), false);
	assert.strictEqual(often('ALENDRONATE 170MG'), false);
	assert.strictEqual(often('RISEDRONATE 35MG'), true);
	assert.strictEqual(often('RISEDRONATE 5MG'), false);
	assert.strictEqual(often('FOSA MAX 70'), true, 'wrapped');
	assert.strictEqual(often('OZEMPIC', { freq: 'custom', customMode: 'weekday' }), false);
	assert.strictEqual(often('OZEMPIC', { freq: 'custom', customMode: 'days', customIntervalDays: '7' }), false);
	assert.strictEqual(often('OZEMPIC', { freq: 'custom', customMode: 'days', customIntervalDays: '3' }), true);
	assert.strictEqual(often('OZEMPIC', { freq: 'custom', customMode: 'monthday' }), false);
	assert.strictEqual(often('ZINADOL'), false);
});

test('weekly-only: the form warns after adding, and printing needs a second press', () => {
	const env = load();
	const { PD, w } = env;
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	PD.setFieldValue('pd-drug', 'OZEMPIC INJ.SOL 1MG');
	PD.setFieldValue('pd-dose-amount', '1');
	PD.setFieldValue('pd-dose-unit', 'injection');
	PD.setFieldValue('pd-days', '7');
	PD.s.currentFreq = '24h';
	PD.s.currentDailyTime = 'morning';
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1, 'a warning, not a block');
	assert.match(w.document.getElementById('pd-message').textContent, /μία φορά την εβδομάδα/);
	assert.strictEqual(PD.validatePlanForPrint(), false, 'first press');
	assert.match(w.document.getElementById('pd-message').textContent, /OZEMPIC/);
	assert.strictEqual(PD.validatePlanForPrint(), true, 'second press');
	/* The same check the Pro labels call. */
	PD.s.methotrexateAck = '';
	assert.strictEqual(PD.methotrexateNeedsConfirm(PD.s.items), true);
	assert.strictEqual(PD.methotrexateNeedsConfirm(PD.s.items), false);
	/* Weekly: nothing to confirm. */
	PD.s.items[0] = Object.assign({}, PD.s.items[0], { freq: 'custom', customMode: 'weekday', customWeekday: 1, days: '28' });
	PD.s.methotrexateAck = '';
	assert.strictEqual(PD.methotrexateNeedsConfirm(PD.s.items), false);
});

test('weekly-only in the review: sent to the form, red, never added by «Προσθήκη»', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, HEAD + block('TRULICITY INJ.SOL 1,5MG/0,5ML BTx4 PF.PENS', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες') +
		block('BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)', DAILY30));
	const row = d.querySelectorAll('.pd-rx-row')[0];
	assert.ok(row.classList.contains('needs-form'));
	assert.ok(d.getElementById('pd-rx-w-0-weeklyOnly').classList.contains('is-danger'));
	PD.s.rx.rows[0].item.warnings = [];
	PD.s.rx.rows[0].include = true;
	d.querySelector('[data-rx-row="1"][data-rx-time="morning"]').click();
	d.getElementById('pd-rx-add').click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => i.name)), ['BESPAR TAB 10MG']);
});

/* ---- 4. dispensed quantity ---- */

test('quantity check: pack × boxes against the plan; warns only on the two measured rules', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const check = (drug, dose, boxes) => PD.rxQuantityCheck(parse(PD, HEAD + block(drug, dose, boxes)).items[0]);
	/* Exact. */
	assert.deepStrictEqual(J(check('FOO TAB 10MG/TAB BTx30', DAILY30, 1)), { dispensed: 30, needed: 30, reliable: true, warn: false, short: false });
	/* Pack rounding (14 for 5 days, 2 boxes of 20 for 30 days): fine. */
	assert.strictEqual(check('FOO TAB 10MG/TAB BTx14', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 5 ημέρες', 1).warn, false);
	assert.strictEqual(check('FOO TAB 10MG/TAB BTx20', DAILY30, 2).warn, false);
	/* One box more than needed: (2 − 1) × 30 ≥ 28. */
	assert.strictEqual(check('FOO TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες', 2).warn, true);
	/* Under-dispensing is normal down to 1/3 … */
	assert.strictEqual(check('FOO TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες', 1).warn, false);
	assert.strictEqual(check('FOO TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 30 ημέρες', 1).warn, false, 'exactly 1/3');
	/* … below it, not (a weekly read as daily). */
	assert.strictEqual(check('FOO TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες', 1).warn, true);
	/* Packs: «BTX1 VIAL HDPE X 31 CAPS», «BTX1X30», «4X10», «BTx1FLx30», one syringe. */
	assert.strictEqual(check('FOO GR.CAP 40MG/CAP BTX1 VIAL HDPE X 31 CAPS (Γενόσημο)', '1 ΚΑΨΟΥΛΑ x 1 φορά την ημέρα x 31 ημέρες', 1).dispensed, 31);
	assert.strictEqual(check('FOO TAB 10MG/TAB BTX1X30', DAILY30, 1).dispensed, 30);
	assert.strictEqual(check('FOO F.C.TAB 100MG 4X10 (Πρωτότυπο)', DAILY30, 1).dispensed, 40);
	assert.strictEqual(check('FOO TAB (200+50)MG/TAB BTx1FLx30 (Πρωτότυπο)', DAILY30, 1).dispensed, 30);
	assert.deepStrictEqual(J(check('FOO INJ.SO.PFS 250 IU/ML BT x 1 PF.SYR x 1ML', '1 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ x εφάπαξ x 1 ημέρες', 1)),
		{ dispensed: 1, needed: 1, reliable: true, warn: false, short: false });
	/* Tier 2: shown, never warned. */
	const inh = check('FOO AER.MD.INH 100MCG/DOSE FLx200 DOSES', '2 ΕΙΣΠΝΟΕΣ x 2 φορές την ημέρα x 10 ημέρες', 3);
	assert.deepStrictEqual(J(inh), { dispensed: 600, needed: 40, reliable: false, warn: false, short: false });
	const ml = check('FOO SYR 50MG/5ML FLX125ML', '5 ML x 3 φορές την ημέρα x 30 ημέρες', 1);
	assert.deepStrictEqual(J(ml), { dispensed: 125, needed: 450, reliable: false, warn: false, short: false });
	/* A pen holds several doses: never counted as one. */
	assert.strictEqual(check('FOO INJ.SOL 1MG/0,5ML BTx1 PF.PEN', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 28 ημέρες', 1), null);
	/* Not comparable: a cream, a tablet pack with an «mg» dose. */
	assert.strictEqual(check('FOO CREAM 1% TUBx30G', '1 ΔΕΡΜ ΕΠΑΛΕΙΨΗ x 2 φορές την ημέρα x 10 ημέρες', 1), null);
	assert.strictEqual(check('FOO TAB 10MG/TAB BTx30', '20 MG x 1 φορά την ημέρα x 30 ημέρες', 1), null);
});

test('quantity check in the review: shown on the row; a mismatch needs «Επιβεβαιώνω» on the duration', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, HEAD + block('FOO TAB 10MG/TAB BTx30', DAILY30, 1) + block('BAR TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες', 1));
	const rows = d.querySelectorAll('.pd-rx-row');
	assert.match(rows[0].querySelector('.pd-rx-qty').textContent, /Χορηγούνται 30 · το πλάνο χρειάζεται 30/);
	assert.ok(!rows[0].querySelector('.pd-rx-qty').classList.contains('is-warn'));
	assert.ok(rows[1].querySelector('.pd-rx-qty').classList.contains('is-warn'));
	assert.ok(PD.s.rx.rows[1].item.warnings.includes('dispensedQty'));
	assert.ok(d.getElementById('pd-rx-w-1-dispensedQty'));
	/* The time chosen, row 1 still waits for its confirmation. */
	d.querySelector('[data-rx-row="0"][data-rx-time="morning"]').click();
	d.querySelector('[data-rx-row="1"][data-rx-time="morning"]').click();
	assert.ok(d.getElementById('pd-rx-add').disabled);
	const cb = d.getElementById('pd-rx-cf-1-days');
	assert.ok(cb);
	assert.match(cb.getAttribute('aria-describedby'), /pd-rx-w-1-dispensedQty/);
	cb.checked = true;
	cb.dispatchEvent(new env.w.Event('change', { bubbles: true }));
	assert.ok(!d.getElementById('pd-rx-add').disabled);
	d.getElementById('pd-rx-add').click();
	assert.strictEqual(PD.s.items.length, 2);
});

/* ---- 5. «Ίδια ώρα για όλα τα 1×ημέρα» ---- */

test('bulk time: only once-daily rows without a time; never overwrites; marked «(για όλα)»; each row stays editable', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, HEAD +
		block('AAA TAB 10MG/TAB BTx30', DAILY30) +
		block('BBB TAB 10MG/TAB BTx30', DAILY30) +
		block('CCC TAB 10MG/TAB BTx30', DAILY30) +
		block('DDD TAB 10MG/TAB BTx30', DAILY30) +
		block('EEE TAB 10MG/TAB BTx60', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες') +
		block('FFF INJ.SUSP 0,5ML/PF.SYR BTx1 PF.SYR X 0,5ML', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x εφάπαξ x 1 ημέρες') +
		block('XX GGG TAB 10MG/TAB BTx30', DAILY30));
	/* A time already chosen in row 3 (DDD). */
	d.querySelector('[data-rx-row="3"][data-rx-time="noon"]').click();
	const group = d.querySelector('.pd-rx-bulk');
	assert.ok(group);
	assert.strictEqual(group.getAttribute('role'), 'group');
	assert.strictEqual(d.getElementById(group.getAttribute('aria-labelledby')).textContent, 'Ίδια ώρα για όλα τα 1×ημέρα (4):');
	assert.strictEqual(group.querySelectorAll('button').length, 4);
	d.getElementById('pd-rx-bulk-evening').click();
	const items = PD.s.rx.rows.map((r) => r.item);
	assert.deepStrictEqual(J(items.map((i) => i.dailyTime)), ['evening', 'evening', 'evening', 'noon', null, null, 'evening']);
	/* Only the time: GGG still waits for its name confirmation, FFF (εφάπαξ) was not touched. */
	assert.strictEqual(PD.rxItemProblem(items[6]), 'warnings');
	assert.ok(PD.rxPending(items[5]).some((p) => p.code === 'time'));
	const status = d.getElementById('pd-rx-bulk-status');
	assert.strictEqual(status.getAttribute('role'), 'status');
	assert.match(status.textContent, /ορίστηκε σε 4 φάρμακα/);
	assert.strictEqual(d.activeElement, status);
	/* Nothing left without a time: the chooser is gone, the status stays. */
	assert.strictEqual(d.querySelector('.pd-rx-bulk'), null);
	const rows = d.querySelectorAll('.pd-rx-row');
	assert.match(rows[0].querySelector('.pd-rx-times').textContent, /\(για όλα\)/);
	assert.ok(!/\(για όλα\)/.test(rows[3].querySelector('.pd-rx-times').textContent));
	/* A row changed on its own is no longer «για όλα». */
	d.querySelector('[data-rx-row="1"][data-rx-time="morning"]').click();
	assert.strictEqual(PD.s.rx.rows[1].item.dailyTime, 'morning');
	assert.ok(!/\(για όλα\)/.test(d.querySelectorAll('.pd-rx-row')[1].querySelector('.pd-rx-times').textContent));
	assert.strictEqual(PD.s.rx.rows[0].item.dailyTime, 'evening');
});

test('bulk time: hidden with fewer than two rows to set', () => {
	const env = app();
	const { d } = env;
	paste(env, HEAD + block('AAA TAB 10MG/TAB BTx30', DAILY30) + block('EEE TAB 10MG/TAB BTx60', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες'));
	assert.strictEqual(d.querySelector('.pd-rx-bulk'), null);
	env.PD.closeRxImport();
	paste(env, HEAD + block('AAA TAB 10MG/TAB BTx30', DAILY30) + block('BBB TAB 10MG/TAB BTx30', DAILY30));
	assert.ok(d.querySelector('.pd-rx-bulk'));
	d.querySelector('[data-rx-row="0"][data-rx-time="morning"]').click();
	assert.strictEqual(d.querySelector('.pd-rx-bulk'), null, 'one left');
});
