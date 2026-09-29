/* Regression corpus: 311 real ΗΔΙΚΑ prescriptions (235 in the first batch), anonymised
   (rx-corpus/README.md), each as a Chrome and a Firefox copy, against a
   ground truth read from the PDF coordinates (rx-corpus/expected.json),
   never from the parser.

   (a) HARD SAFETY, must always pass: every medicine is read (or the
       parse reports unread dose lines); no item is wrong in quantity,
       unit, frequency, days or the strength in its name while it could be
       added as it is (PD.rxItemProblem() === '' once the time / weekday /
       day-of-month choice is made); every «must flag» medicine is flagged.
   (b) NOISE BUDGET, a ratchet: medicines read right that still carry a
       warning other than a time / weekday / day-of-month choice or the
       «4 or 5 doses?» confirmation (needless), wrong-but-flagged items and
       extra items, each at most expected.json «budget». Lower the budget
       when the parser improves; never raise it. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { load, closeAll, isoAhead } = require('./harness');

const DIR = path.join(__dirname, 'rx-corpus');
const EXPECTED = JSON.parse(fs.readFileSync(path.join(DIR, 'expected.json'), 'utf8'));
const BROWSERS = ['chrome', 'firefox'];
/* Not noise: a choice, the «4 or 5 doses?» confirmation, the soft notes. */
const ALLOWED = new Set(['time', 'weekDay', 'monthly', 'monthDay', 'intervalCount', 'duplicate', 'inPlan']);
const SOFT = new Set(['duplicate', 'inPlan']);
/* A must-flag medicine is flagged by any hard warning, or by one of these
   confirmations (the one that asks the right question). */
const RELATED_CONFIRM = {
	weeklyNotWholeWeeks: ['intervalCount'],
	vaccineNoStrength: ['strength', 'brandWords'],
	strengthUnreadable: ['strength', 'brandWords'],
	weeklyOnlyDaily: ['weeklyOnly', 'dispensedQty'],
	fractionalInjection: ['iuConfirm'],
	/* 1.27.2 (round 9): 60 tablets for «x 1 ημέρες» — the soft confirm on «Διάρκεια». */
	shortDuration: ['shortDuration'],
	insulinInjections: [],
	unitUnclear: [],
	asNeeded: [],
	timesPerWeek: [],
	/* 1.27.2 (round 9): «κάθε N εβδομάδες» is read as every 7N days and
	   the number of doses is confirmed with the dates in view. */
	everyN: ['intervalCount'],
	doseUnreadable: []
};

/* … and these need that very warning, whatever else fires: an insulin
   dosed as «N ΕΝΕΣΗ» carries the hard «insulinUnits» (or, over 3, the
   units confirmation) and is never addable (1.27.2, round 9). */
const REQUIRED = {
	insulinInjections: ['insulinUnits', 'iuConfirm']
};

const ws = (s) => String(s || '').split(/\s+/).filter(Boolean).join(' ');
const GR = { Β: 'B', Τ: 'T', Χ: 'X', Μ: 'M', Ι: 'I', Κ: 'K', Ε: 'E', Α: 'A', Ο: 'O', Ν: 'N', Ρ: 'P', Η: 'H', Ζ: 'Z', Υ: 'Y' };
const fold = (s) => ws(s).toUpperCase().replace(/[ΒΤΧΜΙΚΕΑΟΝΡΗΖΥ]/g, (c) => GR[c]);
const firstTok = (s) => fold(s).split(' ')[0] || '';
const nums = (s) => (String(s || '').match(/\d+(?:[.,]\d+)?/g) || []).map((x) => parseFloat(x.replace(',', '.')));
const hasNum = (list, n) => list.some((x) => Math.abs(x - n) < 1e-9);

/* compare.py's matching: brand first word, strength numbers, dose text. */
function score(med, it) {
	let s = 0;
	if (it.name && firstTok(it.name) === firstTok(med.drug)) {
		s += 4;
		const dn = nums(med.drug);
		if (nums(it.name).every((n) => hasNum(dn, n))) {
			s += 2;
		}
	}
	if (ws(it.source).startsWith(ws(med.dose))) {
		s += 3;
	}
	return s;
}

function matchGroup(meds, idx, items, jdx, out) {
	const pairs = [];
	idx.forEach((i) => jdx.forEach((j) => {
		const s = score(meds[i], items[j]);
		if (s) {
			pairs.push([-s, Math.abs(i - j), i, j]);
		}
	}));
	pairs.sort((a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2] || a[3] - b[3]);
	const usedJ = new Set(Object.values(out));
	for (const [, , i, j] of pairs) {
		if (i in out || usedJ.has(j)) {
			continue;
		}
		out[i] = j;
		usedJ.add(j);
	}
}

function match(meds, items) {
	const out = {};
	const all = meds.map((m, i) => i);
	const dose = items.map((it, j) => j).filter((j) => !items[j].warnings.includes('noDose'));
	const noDose = items.map((it, j) => j).filter((j) => items[j].warnings.includes('noDose'));
	matchGroup(meds, all, items, dose, out);
	matchGroup(meds, all.filter((i) => !(i in out)), items, noDose, out);
	return out;
}

/* Wrong values: [] when the item says what the prescription says. */
function valueProblems(med, it) {
	const p = [];
	if (!ws(it.name)) {
		p.push('name empty');
	} else {
		if (firstTok(it.name) !== firstTok(med.drug)) {
			p.push('brand ' + it.name);
		}
		const nn = nums(it.name);
		const dn = nums(med.drug);
		const lost = med.strengthNums.filter((n) => !hasNum(nn, n));
		if (lost.length) {
			p.push('strength ' + it.name + ' lacks ' + lost.join('/'));
		}
		const invented = nn.filter((n) => !hasNum(dn, n));
		if (invented.length) {
			p.push('strength ' + it.name + ' invents ' + invented.join('/'));
		}
	}
	const e = med.expect;
	if (e) {
		if (!(Math.abs(Number(it.doseAmount) - e.doseAmount) < 1e-9)) {
			p.push('qty ' + it.doseAmount + '≠' + e.doseAmount);
		}
		if (!e.doseUnit.includes(it.doseUnit)) {
			p.push('unit ' + it.doseUnit + '≠' + e.doseUnit.join('|'));
		}
		if (!e.freq.some(([f, m]) => it.freq === f && ('custom' !== f || it.customMode === m))) {
			p.push('freq ' + it.freq + '/' + (it.customMode || '') + '≠' + e.freq.map((x) => x.join('/')).join('|'));
		}
		if (e.intervalDays && String(it.customIntervalDays) !== String(e.intervalDays)) {
			p.push('every ' + it.customIntervalDays + '≠' + e.intervalDays + ' days');
		}
		if (String(it.days) !== String(e.days)) {
			p.push('days ' + it.days + '≠' + e.days);
		}
	}
	return p;
}

/* The item as its review row shows it: the time / weekday / day-of-month
   choice made (a pending choice is not a warning about the values: it is
   asked of every once-a-day medicine), and the dispensed-quantity
   warnings the row adds (syncQuantityWarning() in rx-import.js, from the
   public PD.rxQuantityCheck(): «dispensedQty», and the round-9 soft
   «shortDuration» confirm, which parsePrescription() alone never sets). */
function reviewed(PD, it) {
	const c = Object.assign({}, it, { warnings: (it.warnings || []).slice() });
	if ('24h' === c.freq && !Object.prototype.hasOwnProperty.call(PD.dailyTimeLabels(), c.dailyTime)) {
		c.dailyTime = 'morning';
	}
	if ('weekday' === c.customMode && null === PD.validWeekday(c.customWeekday)) {
		c.customWeekday = 1;
	}
	if ('monthday' === c.customMode && null === PD.validMonthDay(c.customMonthDay)) {
		c.customMonthDay = 1;
	}
	const q = PD.rxQuantityCheck ? PD.rxQuantityCheck(c) : null;
	const sync = (code, on) => {
		const at = c.warnings.indexOf(code);
		if (on && -1 === at) {
			c.warnings.push(code);
		} else if (!on && -1 !== at) {
			c.warnings.splice(at, 1);
		}
	};
	sync('dispensedQty', !!(q && q.warn));
	sync('shortDuration', !!(q && q.short && !q.warn));
	return c;
}

function evaluate(PD, browser) {
	const res = { browser, files: 0, meds: 0, found: 0, clean: 0, choiceOnly: 0, needless: 0, wrongFlagged: 0, mustFlagWrong: 0,
		extraItems: 0, mustFlag: 0, mustFlagOk: 0, fails: [], needlessCodes: {}, needlessList: [], wrongList: [] };
	for (const [id, f] of Object.entries(EXPECTED.files)) {
		const text = fs.readFileSync(path.join(DIR, browser, id + '.txt'), 'utf8');
		const r = PD.parsePrescription(text);
		res.files++;
		const items = r.items || [];
		const m = match(f.meds, items);
		const used = new Set(Object.values(m));
		res.extraItems += items.filter((it, j) => !used.has(j)).length;
		/* 1.27.5: an unread dose line excuses at most ONE missing medicine
		   (it is shown to the pharmacist as unread text); more missing
		   medicines than unread dose lines is a silent loss. */
		let excused = r.tooLarge ? Infinity : Math.max(0, r.unreadDoseLines | 0);
		f.meds.forEach((med, i) => {
			res.meds++;
			const where = id + '/' + browser + ' ' + med.drug.slice(0, 40) + ' | ' + med.dose;
			if (!(i in m)) {
				res.missingNoted = (res.missingNoted || 0) + 1;
				if (excused > 0) {
					excused--;
				} else {
					res.fails.push('MISSING (no note): ' + where);
				}
				return;
			}
			res.found++;
			const it = reviewed(PD, items[m[i]]);
			const w = it.warnings;
			const pending = new Set(PD.rxPending(items[m[i]]).map((x) => x.code));
			['dispensedQty', 'shortDuration'].forEach((c) => pending.add(c));   /* confirmations */
			const hard = w.filter((c) => !pending.has(c) && !SOFT.has(c));
			const problem = PD.rxItemProblem(it);
			const wrong = valueProblems(med, it);
			if (wrong.length && '' === problem) {
				res.fails.push('SILENTLY WRONG: ' + where + ' → ' + wrong.join('; ') + ' [' + w.join(',') + ']');
			}
			if (med.mustFlag.length && wrong.length) {
				/* a must-flag medicine read wrong (flagged anyway): its own ratchet */
				res.mustFlagWrong++;
				res.wrongList.push('(must-flag) ' + where + ' → ' + wrong.join('; ') + ' [' + w.join(',') + ']');
			}
			if (med.mustFlag.length) {
				res.mustFlag++;
				/* 1.27.2 (round 9): «vaccineNoStrength» is no longer a must-flag
				   (expected.json has it as the note «noStrength»). */
				const ok = med.mustFlag.every((code) => (REQUIRED[code] ? REQUIRED[code].some((c) => w.includes(c)) :
					hard.length || (RELATED_CONFIRM[code] || []).some((c) => w.includes(c))));
				if (ok && '' !== problem) {
					res.mustFlagOk++;
				} else {
					res.fails.push('NOT FLAGGED (' + med.mustFlag.join(',') + '): ' + where + ' [' + w.join(',') + ']');
				}
				return;
			}
			if (wrong.length) {
				res.wrongFlagged++;
				res.wrongList.push(where + ' → ' + wrong.join('; ') + ' [' + w.join(',') + ']');
				return;
			}
			const extra = w.filter((c) => !ALLOWED.has(c));
			if (extra.length) {
				res.needless++;
				extra.forEach((c) => {
					res.needlessCodes[c] = (res.needlessCodes[c] || 0) + 1;
				});
				res.needlessList.push(where + ' [' + w.join(',') + ']');
			} else if (w.length) {
				res.choiceOnly++;
			} else {
				res.clean++;
			}
		});
	}
	return res;
}

let RESULTS = null;
function results() {
	if (!RESULTS) {
		const { PD } = load();
		PD.s.startDate = isoAhead(1);
		RESULTS = {};
		for (const b of BROWSERS) {
			RESULTS[b] = evaluate(PD, b);
		}
		closeAll();
	}
	return RESULTS;
}

test.after(closeAll);

test('rx corpus: the corpus and its truth are complete', () => {
	const ids = Object.keys(EXPECTED.files);
	assert.strictEqual(ids.length, 311);    /* 235 + 38 + 10 + 17 + 11 (batches 1–5) */
	for (const b of BROWSERS) {
		const files = fs.readdirSync(path.join(DIR, b)).filter((x) => x.endsWith('.txt'));
		assert.strictEqual(files.length, ids.length, b);
	}
	const meds = ids.reduce((n, id) => n + EXPECTED.files[id].meds.length, 0);
	assert.strictEqual(meds, 572);         /* 415 + 75 + 21 + 36 + 25 */
	/* the three name-truncation cases keep their full strength */
	const trunc = new Set(ids.flatMap((id) => EXPECTED.files[id].meds.filter((m) => m.knownTruncation).map((m) => m.strengthNums.join('+'))));
	assert.deepStrictEqual([...trunc].sort(), ['1500+1000', '1500+2000', '70+140+5600']);
	/* the insulin rule: NOVORAPID «1/2 ΕΝΕΣΗ» and TRESIBA «1 ΕΝΕΣΗ» are must-flags, TETAGAM (one syringe) is not */
	const meds2 = ids.flatMap((id) => EXPECTED.files[id].meds);
	assert.ok(meds2.some((m) => /^NOVORAPID/.test(m.drug) && m.mustFlag.includes('insulinInjections')));
	assert.ok(meds2.some((m) => /^TRESIBA/.test(m.drug) && m.mustFlag.includes('insulinInjections')));
	assert.ok(meds2.filter((m) => /^TETAGAM/.test(m.drug)).every((m) => !m.mustFlag.length));
	assert.ok(meds2.some((m) => /^FLECARYTHM/.test(m.drug) && m.mustFlag.includes('shortDuration')));
	assert.ok(meds2.every((m) => !m.mustFlag.includes('vaccineNoStrength')));
	/* batch 4: every methotrexate is expected weekly with a weekday pick (30 days: + intervalCount) */
	const mtx = meds2.filter((m) => /^(METHOTREXATE|METOJECT|NORDIMET|METHOX-F)/.test(m.drug));
	assert.ok(mtx.length >= 6 && mtx.every((m) => m.expect && 'custom' === m.expect.freq[0][0] && 'weekday' === m.expect.freq[0][1] &&
		(0 === m.days % 7) === !m.mustFlag.includes('weeklyNotWholeWeeks')));
	/* SOLUMAG «ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ» (.SD, vials): single-dose vials, no flag; DE3-SOLE «…5ML» and VIOFER stay flagged */
	assert.ok(meds2.filter((m) => /^SOLUMAG/.test(m.drug)).every((m) => !m.mustFlag.length && 'ampoule' === m.expect.doseUnit[0]));
	assert.ok(meds2.filter((m) => /^(DE3-SOLE|VIOFER)/.test(m.drug)).every((m) => m.mustFlag.includes('unitUnclear')));
});

for (const b of BROWSERS) {
	test('rx corpus (' + b + '): HARD SAFETY — nothing missing, nothing silently wrong, every must-flag flagged', () => {
		const r = results()[b];
		assert.ok(r.mustFlag > 0);
		assert.deepStrictEqual(r.fails, [], r.fails.length + ' hard-safety failure(s):\n' + r.fails.join('\n'));
		/* 1.27.5: every medicine of the corpus is found (a missing one, even
		   «with a note», hides must-flag checks), and every must-flag
		   medicine of expected.json was actually checked. */
		assert.strictEqual(r.found, r.meds, (r.meds - r.found) + ' medicine(s) not found');
		const mustFlagTotal = Object.values(EXPECTED.files).reduce((n, f) => n + f.meds.filter((m) => m.mustFlag.length).length, 0);
		assert.strictEqual(r.mustFlag, mustFlagTotal, 'must-flag medicines checked');
	});

	test('rx corpus (' + b + '): NOISE BUDGET (ratchet)', () => {
		const r = results()[b];
		const right = r.clean + r.choiceOnly + r.needless;
		const codes = Object.entries(r.needlessCodes).sort((x, y) => y[1] - x[1]).map(([c, n]) => c + ' ' + n).join(', ');
		console.log('[rx-corpus ' + b + '] ' + r.files + ' prescriptions, ' + r.meds + ' medicines, ' + r.found + ' found; ' +
			'must-flag ' + r.mustFlagOk + '/' + r.mustFlag + ' flagged; read right: ' + right +
			' (clean ' + r.clean + ', choice/interval only ' + r.choiceOnly + ', NEEDLESS ' + r.needless + ')' +
			'; wrong but flagged ' + r.wrongFlagged + ' (+ must-flag read wrong ' + r.mustFlagWrong + '); extra items ' + r.extraItems +
			(codes ? '\n  needless warnings: ' + codes : ''));
		if (process.env.RX_CORPUS_VERBOSE) {
			console.log('  needless:\n   ' + r.needlessList.join('\n   ') + '\n  wrong but flagged:\n   ' + r.wrongList.join('\n   '));
		}
		const budget = (EXPECTED.budget || {})[b];
		assert.ok(budget, 'expected.json has no budget for ' + b);
		for (const k of ['needless', 'wrongFlagged', 'mustFlagWrong', 'extraItems']) {
			assert.ok(r[k] <= budget[k], b + ' ' + k + ' ' + r[k] + ' > budget ' + budget[k] + ' (a regression: fix it, do not raise the budget)');
			if (r[k] < budget[k]) {
				console.log('  ' + k + ' ' + r[k] + ' is below the budget ' + budget[k] + ': lower «budget» in rx-corpus/expected.json');
			}
		}
	});
}
