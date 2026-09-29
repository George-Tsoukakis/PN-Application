/* 1.30.1: fixes from the 1.30.0 review.
   - «1.000MG» in a pasted prescription went into the printed name with no
     warning at all: a Greek reader sees a thousand, an English one sees one.
     Now the row goes through the form («strengthThousands»), in mg, mcg and
     units only («2,810G» is never 2810 grams). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
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
