/* 1.28.7: the last parser hole of the 1.28.6 review, and the QR notice for
   a dropped pharmacy name.
   - «. 25MG» (space / no-break space / tab after the separator) and «..5MG»,
     «.,5MG» were read as 25MG / 5MG — the spaced form with no warning at
     all. Now 0.25MG / 0.5MG and the row must go through the form.
   - «0. 25MG», «1 . 5MG», «2 ,5MG»: a number split by a space was read from
     its last part behind a plain confirmation. Now joined back as written
     (0.25MG, 1.5MG, 2,5MG) and through the form («strengthSplit»). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const DAILY = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';
const parse = (PD, drug) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + DAILY + '\n' + P).items[0];

/* Every choice made and every confirmation ticked: only a hard warning
   (the form) can still hold the row back. */
function allConfirmed(it) {
	return Object.assign({}, it, {
		dailyTime: 'morning', customWeekday: 1, customMonthDay: 1,
		confirmed: { name: true, qty: true, freq: true, days: true }
	});
}

test('a separator standing apart from the number is still the leading zero', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, name] of [
		['XANAX TAB . 25MG BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB , 5MG BTx30', 'XANAX TAB 0,5MG'],
		['XANAX TAB . 25MG BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB . 25MG BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB .\t25MG BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB . 25 MG/TAB BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB ..5MG BTx30', 'XANAX TAB 0.5MG'],
		['XANAX TAB (. 25MG) BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB (.\u00a025MG) BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB /. 5MG BTx30', 'XANAX TAB 0.5MG'],
		['XANAX TAB -. 25MG BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB .,5MG BTx30', 'XANAX TAB 0,5MG']
	]) {
		const it = parse(PD, drug);
		assert.strictEqual(it.name, name, JSON.stringify(drug));
		assert.ok(it.warnings.includes('strengthZero'), JSON.stringify(drug) + ' ' + it.warnings);
		assert.strictEqual(PD.rxItemProblem(allConfirmed(it)), 'warnings', JSON.stringify(drug) + ' could be added with «Επιβεβαιώνω»');
	}
});

test('a number split by a space is joined back and goes through the form', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, name] of [
		['XANAX TAB 0. 25MG BTx30', 'XANAX TAB 0.25MG'],
		['XANAX TAB 0 .25MG BTx30', 'XANAX TAB 0.25MG'],
		['FOO TAB 1 . 5MG BTx30', 'FOO TAB 1.5MG'],
		['FOO TAB 2 ,5MG BTx30', 'FOO TAB 2,5MG'],
		['FOO TAB 0, 125MG BTx30', 'FOO TAB 0,125MG']
	]) {
		const it = parse(PD, drug);
		assert.strictEqual(it.name, name, JSON.stringify(drug));
		assert.ok(it.warnings.includes('strengthSplit'), JSON.stringify(drug) + ' ' + it.warnings);
		assert.strictEqual(PD.rxItemProblem(allConfirmed(it)), 'warnings', JSON.stringify(drug) + ' could be added with «Επιβεβαιώνω»');
	}
	assert.ok(PD.prescriptionWarningText('strengthSplit').length > 20);
	const src = require('fs').readFileSync(require('path').join(require('./harness').JS_DIR, 'rx-review.js'), 'utf8');
	assert.match(src, /var WARN_FIELD = \{[^}]*strengthSplit: 'name'/);
});

test('any other dot, comma or number right before the strength goes through the form', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const drug of ['XANAX TAB 1X. 5MG BTx30', 'XANAX TAB ΤΑΒ. 25MG BTx30', 'XANAX TAB 0 25MG BTx30', 'XANAX TAB 1 5MG BTx30']) {
		const it = parse(PD, drug);
		assert.ok(it.warnings.includes('strengthSep'), drug + ' ' + it.warnings);
		assert.strictEqual(PD.rxItemProblem(allConfirmed(it)), 'warnings', drug + ' could be added with «Επιβεβαιώνω»');
	}
	assert.ok(PD.prescriptionWarningText('strengthSep').length > 20);
});

test('ordinary strengths and abbreviations are left alone', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, name] of [
		['FOO TAB 0.25MG BTx30', 'FOO TAB 0.25MG'],
		['FOO TAB 2.5MG BTx30', 'FOO TAB 2.5MG'],
		['FOO TAB 10 MG BTx30', 'FOO TAB 10MG'],
		['FOO F.C.TAB 5MG BTx30', 'FOO F.C.TAB 5MG']
	]) {
		const it = parse(PD, drug);
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('strengthZero') && !it.warnings.includes('strengthSplit') && !it.warnings.includes('strengthSep'), drug + ' ' + it.warnings);
	}
	/* «SR. 5MG»: the dot closes an abbreviation — never made 0.5MG. */
	const sr = parse(PD, 'FOO TAB SR. 5MG BTx30');
	assert.doesNotMatch(String(sr.name), /0[.,]5/);
});

/* ---- QR: the pharmacy name left out is said too ---- */

function med(name, freq, days) {
	return { name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: '' };
}

test('QR: when the pharmacy name had to be left out, the preview says so', () => {
	const e = load({ config: { calendarUrl: 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.28.7' } });
	const PD = e.PD;
	PD.s.startDate = isoAhead(0);
	PD.s.firstSlot = 'morning';
	PD.s.items = Array.from({ length: 6 }, (_, i) => med('ΦΑΡΜΑΚΟ ' + 'Α'.repeat(20) + ' ' + i + ' 500MG', '12h', 30));
	PD.s.header = { name: 'Φαρμακείο Παπαδοπούλου Αθηνών Κέντρο Αγίου' };
	PD.beginDayPass();
	assert.ok(PD.calendarLink(), 'a QR is made');
	assert.ok(!decodeURIComponent(PD.calendarLink()).includes('Παπαδοπούλου'));
	assert.match(PD.calendarQrNotice(), /φαρμακείου/);
	/* Without a pharmacy name there is nothing to say. */
	PD.s.header = { name: '' };
	assert.doesNotMatch(PD.calendarQrNotice(), /φαρμακείου/);
});

/* Every unit, both separators, glued / space / no-break space: always the
   zero back, and never past a plain «Επιβεβαιώνω». 5 units × 2 × 3 = 30. */
test('leading zero: MG, MCG, G, ML, % × «.» «,» × glued / space / NBSP', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const forms = { MG: 'TAB', MCG: 'TAB', G: 'TAB', ML: 'ORAL.SOL', '%': 'CREAM' };
	let n = 0;
	for (const [unit, form] of Object.entries(forms)) {
		for (const sep of ['.', ',']) {
			for (const gap of ['', ' ', ' ']) {
				const drug = 'FOO ' + form + ' ' + sep + gap + '25' + unit + ' BTx30';
				const it = parse(PD, drug);
				assert.strictEqual(it.name, 'FOO ' + form + ' 0' + sep + '25' + unit, JSON.stringify(drug));
				assert.ok(it.warnings.includes('strengthZero'), JSON.stringify(drug) + ' ' + it.warnings);
				assert.strictEqual(PD.rxItemProblem(allConfirmed(it)), 'warnings', JSON.stringify(drug) + ' could be added with «Επιβεβαιώνω»');
				n++;
			}
		}
	}
	assert.strictEqual(n, 30);
});
