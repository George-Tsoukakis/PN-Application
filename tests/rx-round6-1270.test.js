/* 1.27.0 round 6: a word glued before a drug line never reaches the
   printed name (verify/rx13.js, rx14.js). A drug line that does not start
   its own physical line has a one-word brand; a brand of several words on
   its own line is confirmed («Επιβεβαιώνω»); route / timing abbreviations
   and Greeklish instruction words are never brand words. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEADER = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75';
const DEP = 'DEPON TAB 500MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες';
const BES = (pre) => pre + 'BESPAR TAB 10MG/TAB BTx30 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες';
const DIR = path.join(__dirname, 'rx-samples');
const J = (v) => JSON.parse(JSON.stringify(v));

const WORDS = ['PO', 'HS', 'QHS', 'Q8H', 'Q12H', 'STAT', 'AM', 'PM', 'IM', 'SC', 'IV', 'SL', 'PRN', 'BID', 'TID', 'QID', 'OD', 'BD', 'TDS',
	'WEAN', 'TITRATE', 'LOADING DOSE', 'EXTRA', 'STRENGTH', 'PRWI', 'BRADY', 'MESIMERI', 'MONO AN PONAEI', 'META FAGHTO', 'SE PERIPTOSI',
	'KATHE PRWI', 'MISO', '1X1', 'X3', 'DAY 1-5', 'BOLUS', 'CONTROL', 'RESCUE', 'BACKUP', 'TEST DOSE', 'KEEP', 'LOW DOSE', 'CRUSH', 'DISSOLVE'];

const layouts = {
	ownLine: (w) => HEADER + DEP + '\n' + P + '\n' + BES(w + ' ') + '\n' + P + '\n',
	afterPrice: (w) => HEADER + DEP + '\n' + P + ' ' + BES(w + ' ') + '\n' + P + '\n',
	afterDoseTail: (w) => HEADER + DEP + ' ' + P + ' ' + BES(w + ' ') + '\n' + P + '\n'
};

test('rx13: a glued word is never part of the name, and BESPAR is never addable without the pharmacist', () => {
	const { PD } = load();
	for (const w of WORDS) {
		for (const [layout, make] of Object.entries(layouts)) {
			const tag = layout + ' ' + w;
			const r = PD.parsePrescription(make(w));
			const bes = r.items.find((i) => /BESPAR/.test(i.name));
			assert.ok(bes, tag);
			assert.strictEqual(PD.rxItemProblem(Object.assign({}, bes, { dailyTime: 'morning' })), 'warnings', tag);
			if ('ownLine' === layout) {
				/* Flagged outright, or the extra word must be confirmed. */
				assert.ok(bes.warnings.includes('extra') || bes.warnings.includes('brandWords'), tag + ' ' + bes.warnings);
				if (bes.name !== 'BESPAR TAB 10MG') {
					assert.ok(bes.warnings.includes('brandWords'), tag);
				}
			} else {
				/* Glued after a price row / dose line: one-word brand. */
				assert.strictEqual(bes.name, 'BESPAR TAB 10MG', tag);
				assert.ok(bes.warnings.includes('extra'), tag);
				assert.ok(r.items[0].warnings.includes('extra'), tag + ' (DEPON)');
			}
		}
	}
});

test('rx13: abbreviations and Greeklish words are flagged outright, not just confirmed', () => {
	const { PD } = load();
	for (const w of ['PO', 'HS', 'QHS', 'Q8H', 'STAT', 'AM', 'PM', 'IM', 'SC', 'IV', 'SL', 'PRN', 'BID', 'OD', 'X3', 'PRWI', 'BRADY', 'MESIMERI', 'MISO', 'META FAGHTO', 'SE PERIPTOSI', 'KATHE PRWI', 'MONO AN PONAEI']) {
		const bes = PD.parsePrescription(layouts.ownLine(w)).items.find((i) => /BESPAR/.test(i.name));
		assert.strictEqual(bes.name, 'BESPAR TAB 10MG', w);
		assert.ok(bes.warnings.includes('extra'), w);
	}
});

test('rx14: « PO» glued onto the previous line before the second drug of every multi-drug sample never reaches a name', () => {
	const { PD } = load();
	let checked = 0;
	for (const f of fs.readdirSync(DIR).filter((x) => x.endsWith('.txt'))) {
		const src = fs.readFileSync(path.join(DIR, f), 'utf8');
		const r0 = PD.parsePrescription(src);
		if (r0.items.length < 2) {
			continue;
		}
		const b = r0.items[1].name.split(' ')[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		const t = src.replace(new RegExp('\\n(' + b + ')'), ' PO $1');
		const r = PD.parsePrescription(t);
		checked++;
		assert.ok(r.items.every((i) => !/^PO /.test(i.name)), f);
		assert.ok(r.items.length >= r0.items.length, f);
		const second = r.items.find((i) => i.name === r0.items[1].name);
		assert.ok(second && second.warnings.includes('extra'), f);
	}
	assert.strictEqual(checked, 14);
});

test('text glued after «Μονάδος Αποζ. …» is flagged on the next drug and named in the note', () => {
	const { PD } = load();
	const H = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου';
	for (const w of ['ΜΟΝΟ ΑΝ ΠΟΝΑΕΙ', 'TAKE WITH FOOD', 'PO']) {
		const r = PD.parsePrescription(H + ' ' + w + ' DEPON TAB 500MG/TAB BTx20 (Γενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\n' + P + '\n');
		assert.strictEqual(r.items[0].name, 'DEPON TAB 500MG', w);
		assert.ok(r.items[0].warnings.includes('extra'), w);
		assert.ok(r.unreadText.some((x) => x.includes(w)), w);
	}
});

test('the samples: no real multi-word brand asks for the name to be confirmed (round 7: qualifiers such as P, TOCAS, DISKUS, NEXTHALER)', () => {
	const { PD } = load();
	const multi = [];
	for (const f of fs.readdirSync(DIR).filter((x) => x.endsWith('.txt')).sort()) {
		for (const it of PD.parsePrescription(fs.readFileSync(path.join(DIR, f), 'utf8')).items) {
			if (it.warnings.includes('brandWords')) {
				multi.push(it.name);
			}
		}
	}
	assert.deepStrictEqual(J(Array.from(new Set(multi))), []);
});

test('review: the first word of a multi-word brand is marked, and «Επιβεβαιώνω» on the name unlocks the row', () => {
	const { PD, w } = load();
	PD.s.startDate = isoAhead(1);
	PD.buildApp();
	const d = w.document;
	const drop = d.getElementById('pd-rx-drop');
	/* Round 7: a real two-word brand (OMNIC TOCAS) is no longer asked
	   about; a word that is not a known qualifier still is. */
	drop.value = fs.readFileSync(path.join(DIR, '21-four-drugs-prolonged-release.txt'), 'utf8').replace('OMNIC TOCAS', 'EXTRA OMNIC');
	drop.dispatchEvent(new w.Event('input', { bubbles: true }));
	const i = PD.s.rx.rows.findIndex((r) => /OMNIC/.test(r.item.name));
	const row = d.querySelectorAll('.pd-rx-row')[i];
	assert.strictEqual(row.querySelector('.pd-rx-brand-lead').textContent, 'EXTRA');
	assert.ok(d.getElementById('pd-rx-cf-' + i + '-name'));
	assert.strictEqual(PD.rxItemProblem(Object.assign({}, PD.s.rx.rows[i].item, { dailyTime: 'noon' })), 'warnings');
	const cb = d.getElementById('pd-rx-cf-' + i + '-name');
	cb.checked = true;
	cb.dispatchEvent(new w.Event('change', { bubbles: true }));
	assert.strictEqual(PD.rxItemProblem(Object.assign({}, PD.s.rx.rows[i].item, { dailyTime: 'noon' })), '');
});

test('join fuzz: abbreviations glued onto the start and end of every line after the first medicine (samples 19, 20)', () => {
	const { PD } = load();
	const abbr = ['PO', 'HS', 'QHS', 'Q8H', 'STAT', 'AM', 'PM', 'IM', 'SC', 'IV', 'SL', 'BID', 'X3', 'PRWI', 'BRADY', 'MISO', 'META FAGHTO', 'SE PERIPTOSI'];
	for (const f of ['19-foster-oral-solution-ml.txt', '20-five-drugs-dose-line-split.txt']) {
		const lines = fs.readFileSync(path.join(DIR, f), 'utf8').replace(/\r/g, '').split('\n');
		const base = PD.parsePrescription(lines.join('\n'));
		const first = lines.findIndex((l) => /ΔΟΣΟΛΟΓΙΑ/.test(l) || PD.rxIsDrugLine(l.trim()));
		for (let pos = first; pos < lines.length; pos++) {
			if (!lines[pos].trim()) {
				continue;
			}
			for (const s of abbr) {
				for (const where of ['end', 'start']) {
					const joined = where === 'end' ? lines[pos] + ' ' + s : s + ' ' + lines[pos];
					const r = PD.parsePrescription(lines.slice(0, pos).concat([joined], lines.slice(pos + 1)).join('\n'));
					const tag = f + ' @' + pos + ' ' + where + ' ' + s;
					assert.ok(r.items.length >= base.items.length, tag);
					assert.ok(r.items.every((i) => !(' ' + i.name + ' ').includes(' ' + s + ' ')), tag);
					const flat = (t) => ' ' + String(t || '').replace(/\s+/g, ' ') + ' ';
					const owners = r.items.filter((i) => flat(i.origText).includes(' ' + s + ' ') || flat(i.source).includes(' ' + s + ' '));
					const noted = (r.unreadText || []).some((t) => flat(t).includes(' ' + s + ' '));
					assert.ok(owners.length || noted, tag + ' not shown');
					assert.ok(owners.every((i) => PD.rxItemProblem(Object.assign({}, i, { dailyTime: 'morning', customWeekday: 1, customMonthDay: 1, confirmed: { qty: true, days: true, freq: true } })) === 'warnings'), tag + ' not flagged');
				}
			}
		}
	}
});
