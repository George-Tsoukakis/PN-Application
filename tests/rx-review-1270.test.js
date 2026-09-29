/* 1.27.0: the prescription review screen — original text next to the
   fields, explicit choices and confirmations before «Προσθήκη», same drug
   at two doses, other patients, missing medicines, and the prescription
   text above the form (review items 4, 6, 12–16). */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');

test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
/* Round 4: a drug line is recognised by its full ΗΔΙΚΑ signature (brand,
   form, strength, pack). These tests are about the dose phrase, so a pack
   is added where the test name has none. */
const withPack = (drug) => (/(^|[^A-Za-z])(BT|FL|TUB|AMP|VIAL)|ΒΤ/i.test(drug) ? drug : drug + ' BTx1');
const one = (drug, dose) => HEAD + withPack(drug) + '\nΔΟΣΟΛΟΓΙΑ : ' + dose + '\n' + PRICE;
const J = (v) => JSON.parse(JSON.stringify(v));
const patientHead = (s, f) => 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ' + s + '\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ' + f + '\n';
const ZIN = one('ZINADOL F.C.TAB 500MG/TAB BTx10', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες');
const BESPAR = one('BESPAR TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες');

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

const addBtn = (env) => env.d.getElementById('pd-rx-add');
const names = (PD) => J(PD.s.items.map((i) => i.name));

function change(env, el, value) {
	if (typeof value === 'boolean') {
		el.checked = value;
	} else {
		el.value = value;
	}
	el.dispatchEvent(new env.w.Event('change', { bubbles: true }));
}

/* (15) */
test('each row shows its prescription text next to the fields read from it', () => {
	const env = app();
	paste(env, HEAD + 'NEXIUM GR.TAB 20MG/TAB BTx28 (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ ΕΝΤΕΡΟΔΙΑΛΥΤΑ x 1 φορά την ημέρα x 30 ημέρες\n' + PRICE);
	const row = env.d.querySelector('.pd-rx-row');
	const orig = row.querySelector('.pd-rx-orig-text');
	assert.strictEqual(orig.textContent, 'NEXIUM GR.TAB 20MG/TAB BTx28 (Πρωτότυπο σε θεραπευτική κατηγορία χωρίς\nγενόσημο)\nΔΟΣΟΛΟΓΙΑ: 1 ΔΙΣΚΙΑ ΕΝΤΕΡΟΔΙΑΛΥΤΑ x 1 φορά την ημέρα x 30 ημέρες');
	assert.ok(orig.getAttribute('aria-labelledby'));
	const fields = J(Array.from(row.querySelectorAll('.pd-rx-field dt'), (e) => e.textContent));
	assert.deepStrictEqual(fields, ['Φάρμακο', 'Ποσότητα', 'Συχνότητα', 'Διάρκεια']);
	const values = J(Array.from(row.querySelectorAll('.pd-rx-value'), (e) => e.textContent));
	assert.strictEqual(values[0], 'NEXIUM GR.TAB 20MG');
	assert.strictEqual(values[3], '30 ημέρες');
	/* Once a day: no time lit, «Προσθήκη» waits for it. */
	assert.match(values[2], /επιλέξτε ώρα/);
	assert.strictEqual(row.querySelectorAll('.pd-rx-time.active').length, 0);
	assert.strictEqual(addBtn(env).disabled, true);
	assert.strictEqual(addBtn(env).getAttribute('aria-describedby'), 'pd-rx-add-help');
	assert.ok(env.d.getElementById('pd-rx-add-help'));
	/* The patient header is never shown. */
	assert.ok(!env.d.body.innerHTML.includes('Μονάδος Αποζ'));
});

test('time chosen in the row: focus stays on the chip, «Προσθήκη» enabled, the time is added', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, BESPAR);
	d.getElementById('pd-rx-t-0-evening').click();
	assert.strictEqual(d.activeElement.id, 'pd-rx-t-0-evening');
	assert.strictEqual(d.getElementById('pd-rx-t-0-evening').getAttribute('aria-pressed'), 'true');
	assert.strictEqual(addBtn(env).disabled, false);
	assert.ok(!d.getElementById('pd-rx-add-help'));
	addBtn(env).click();
	assert.strictEqual(PD.s.items[0].dailyTime, 'evening');
});

test('a pending row blocks «Προσθήκη» only while it is ticked', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, ZIN + BESPAR);
	assert.strictEqual(addBtn(env).disabled, true, 'BESPAR has no time yet');
	change(env, d.getElementById('pd-rx-inc-1'), false);
	assert.strictEqual(d.activeElement.id, 'pd-rx-inc-1');
	assert.strictEqual(addBtn(env).disabled, false);
	assert.match(addBtn(env).textContent, /\(1\)/);
	addBtn(env).click();
	assert.deepStrictEqual(names(PD), ['ZINADOL F.C.TAB 500MG']);
	/* The pending one stays for later. */
	assert.strictEqual(PD.s.rx.rows.length, 1);
});

test('confirmation per doubtful field: insulin units and an unusual quantity', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('LANTUS SOLOSTAR INJ.SOL 100U/ML BTx5 PENSx3ML', '14 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες') +
		one('FOO TAB 5MG/TAB BTx30', '6 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 3 ημέρες'));
	const cf0 = d.getElementById('pd-rx-cf-0-qty');
	assert.ok(cf0, 'insulin quantity has «Επιβεβαιώνω»');
	assert.match(cf0.parentNode.textContent, /Επιβεβαιώνω/);
	assert.strictEqual(cf0.getAttribute('aria-describedby'), 'pd-rx-w-0-iuConfirm');
	assert.ok(d.getElementById('pd-rx-w-0-iuConfirm'));
	const cf1 = d.getElementById('pd-rx-cf-1-qty');
	assert.strictEqual(cf1.getAttribute('aria-describedby'), 'pd-rx-w-1-highQty');
	assert.ok(cf1.closest('.pd-rx-field').classList.contains('is-doubt'));
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-cf-1-qty'), true);
	assert.strictEqual(d.activeElement.id, 'pd-rx-cf-1-qty');
	assert.strictEqual(addBtn(env).disabled, true, 'insulin row still open');
	d.getElementById('pd-rx-t-0-morning').click();
	/* Round 6: «LANTUS SOLOSTAR» — two words before the form: confirmed,
	   with the first word marked. */
	assert.strictEqual(d.querySelector('.pd-rx-brand-lead').textContent, 'LANTUS');
	change(env, d.getElementById('pd-rx-cf-0-name'), true);
	assert.strictEqual(addBtn(env).disabled, true, 'insulin units not confirmed');
	change(env, d.getElementById('pd-rx-cf-0-qty'), true);
	assert.strictEqual(addBtn(env).disabled, false);
	/* Unconfirming closes it again. */
	change(env, d.getElementById('pd-rx-cf-0-qty'), false);
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-cf-0-qty'), true);
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => [i.doseAmount, i.doseUnit])), [[14, 'iu'], [6, 'tablet']]);
});

test('warnings that need the form still send the row to the form (no confirm box there)', () => {
	const env = app();
	const { d } = env;
	paste(env, HEAD + 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\nSOS σε περίπτωση πόνου\n' + PRICE);
	const row = d.querySelector('.pd-rx-row');
	assert.ok(row.classList.contains('needs-form'));
	assert.strictEqual(d.getElementById('pd-rx-inc-0').disabled, true);
	assert.strictEqual(row.querySelectorAll('[data-rx-confirm]').length, 0);
	assert.ok(row.querySelector('[data-rx-toform]'));
	assert.ok(row.querySelector('#pd-rx-w-0-asNeeded.is-danger'));
	assert.match(row.querySelector('.pd-rx-orig-text').textContent, /SOS σε περίπτωση πόνου/);
});

/* (13) (14) */
test('weekly row: the weekday is chosen in the row, then the dates and the duration are confirmed', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('TRULICITY INJ.SOL 1,5MG/0,5ML BTx4', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 30 ημέρες'));
	const row = () => d.querySelector('.pd-rx-row');
	assert.strictEqual(row().querySelectorAll('[data-rx-weekday]').length, 7);
	assert.strictEqual(row().querySelectorAll('[data-rx-weekday][aria-pressed="true"]').length, 0);
	assert.strictEqual(row().querySelector('.pd-rx-total'), null, 'no dates before a day is chosen');
	assert.strictEqual(addBtn(env).disabled, true);
	d.getElementById('pd-rx-wd-0-4').click();
	assert.strictEqual(d.activeElement.id, 'pd-rx-wd-0-4');
	const total = row().querySelector('.pd-rx-total').textContent;
	assert.match(total, /^Σύνολο: [45] δόσεις/);
	assert.ok((total.match(/Πέμ \d\d\/\d\d/g) || []).length >= 4, total);
	/* 30 days is not whole weeks: the duration is confirmed with the dates in view. */
	assert.ok(d.getElementById('pd-rx-cf-0-days'));
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-cf-0-days'), true);
	assert.strictEqual(addBtn(env).disabled, false);
	addBtn(env).click();
	const it = PD.s.items[0];
	assert.strictEqual(it.customMode, 'weekday');
	assert.strictEqual(it.customWeekday, 4);
	assert.strictEqual(PD.planItemInvalid(it), '');
});

/* (12) */
test('monthly row: the day of the month is typed in the row; the last-day rule from 29 on', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('PROLIA INJ.SOL 60MG/ML BTx1', '1 ΕΝΕΣΗ x 1 φορά τον μήνα x 60 ημέρες'));
	assert.match(d.querySelector('.pd-rx-warns').textContent, /τελευταία ημέρα του μήνα/);
	assert.strictEqual(addBtn(env).disabled, true);
	assert.strictEqual(d.getElementById('pd-rx-cf-0-days'), null, 'no count to confirm before the day is chosen');
	const input = d.getElementById('pd-rx-md-0');
	assert.strictEqual(input.value, '');
	assert.ok(d.querySelector('label[for="pd-rx-md-0"]'));
	change(env, input, '45');
	assert.strictEqual(PD.s.rx.rows[0].item.customMonthDay, null);
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-md-0'), '31');
	assert.strictEqual(d.activeElement.id, 'pd-rx-md-0');
	const note = d.getElementById('pd-rx-md-0-note');
	assert.match(note.textContent, /τελευταία ημέρα/);
	assert.strictEqual(d.getElementById('pd-rx-md-0').getAttribute('aria-describedby'), 'pd-rx-md-0-note');
	assert.match(d.querySelector('.pd-rx-value').parentNode.parentNode.parentNode.textContent, /PROLIA/);
	assert.match(d.querySelector('.pd-rx-total').textContent, /Σύνολο: 2 δόσεις/);
	/* Round 3: the count is confirmed on «Διάρκεια», offered only now. */
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-cf-0-days'), true);
	assert.strictEqual(addBtn(env).disabled, false);
	/* Another day, other dates: confirmed again. */
	change(env, d.getElementById('pd-rx-md-0'), '30');
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-md-0'), '31');
	change(env, d.getElementById('pd-rx-cf-0-days'), true);
	assert.strictEqual(addBtn(env).disabled, false);
	addBtn(env).click();
	assert.strictEqual(PD.s.items[0].customMode, 'monthday');
	assert.strictEqual(PD.s.items[0].customMonthDay, 31);
	assert.match(PD.getFreqLabel(PD.s.items[0]), /στις 31 \(ή την τελευταία ημέρα του μήνα\)/);
});

/* (4) */
test('repro: SINTROM 4MG at 1 and at 1/2 tablet — both unticked, the choice is explicit', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('SINTROM TAB 4MG/TAB BTx20', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
		one('SINTROM TAB 4MG/TAB BTx20', '1/2 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') + ZIN);
	const rows = PD.s.rx.rows;
	assert.deepStrictEqual(J(rows.map((r) => r.include)), [false, false, true]);
	assert.ok(rows[0].item.warnings.includes('sameDrugOtherDose'));
	assert.ok(rows[1].item.warnings.includes('sameDrugOtherDose'));
	assert.ok(!rows[2].item.warnings.includes('sameDrugOtherDose'));
	assert.strictEqual(d.getElementById('pd-rx-inc-0').checked, false);
	/* Adding now adds ZINADOL only. */
	assert.strictEqual(addBtn(env).disabled, false);
	/* Ticking one of them asks for its dose to be confirmed (and its time). */
	change(env, d.getElementById('pd-rx-inc-1'), true);
	assert.strictEqual(addBtn(env).disabled, true);
	assert.strictEqual(d.getElementById('pd-rx-cf-1-qty').getAttribute('aria-describedby'), 'pd-rx-w-1-sameDrugOtherDose');
	change(env, d.getElementById('pd-rx-cf-1-qty'), true);
	d.getElementById('pd-rx-t-1-evening').click();
	assert.strictEqual(addBtn(env).disabled, false);
	addBtn(env).click();
	assert.deepStrictEqual(J(PD.s.items.map((i) => [i.name, i.doseAmount])), [['SINTROM TAB 4MG', 0.5], ['ZINADOL F.C.TAB 500MG', 1]]);
});

test('the same drug and dose twice is still a plain duplicate', () => {
	const env = app();
	paste(env, ZIN + ZIN);
	const rows = env.PD.s.rx.rows;
	assert.deepStrictEqual(J(rows.map((r) => r.include)), [true, false]);
	assert.ok(!rows[0].item.warnings.includes('sameDrugOtherDose'));
	assert.ok(rows[1].item.warnings.includes('duplicate'));
});

/* (6) */
test('two patients in one paste: nothing ticked, add needs the explicit confirmation', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + ZIN + patientHead('ΒΗΤΑ', 'ΔΕΥΤΕΡΟΣ') + one('BESPAR TAB 10MG', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 30 ημέρες'));
	assert.deepStrictEqual(J(PD.s.rx.rows.map((r) => r.include)), [false, false]);
	const ok = d.getElementById('pd-rx-patient-ok');
	assert.ok(ok);
	assert.strictEqual(d.activeElement.id, 'pd-rx-patient-ok');
	assert.strictEqual(ok.getAttribute('aria-describedby'), 'pd-rx-patient-note');
	change(env, d.getElementById('pd-rx-inc-0'), true);
	assert.strictEqual(addBtn(env).disabled, true, 'not confirmed yet');
	addBtn(env).disabled = false;
	addBtn(env).click();
	assert.strictEqual(PD.s.items.length, 0, 'the add handler checks it again');
	change(env, d.getElementById('pd-rx-patient-ok'), true);
	assert.strictEqual(addBtn(env).disabled, false);
	addBtn(env).click();
	assert.deepStrictEqual(names(PD), ['ZINADOL F.C.TAB 500MG']);
	assert.strictEqual(d.getElementById('pd-patient').value, '');
});

test('a different patient already in the plan: nothing ticked, confirmation required', () => {
	const env = app();
	const { PD, d } = env;
	d.getElementById('pd-patient').value = 'ΓΑΜΜΑ ΤΡΙΤΟΣ';
	paste(env, patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + ZIN);
	assert.deepStrictEqual(J(PD.s.rx.rows.map((r) => r.include)), [false]);
	assert.ok(d.getElementById('pd-rx-patient-ok'));
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-patient-ok'), true);
	change(env, d.getElementById('pd-rx-inc-0'), true);
	addBtn(env).click();
	assert.deepStrictEqual(names(PD), ['ZINADOL F.C.TAB 500MG']);
	assert.strictEqual(d.getElementById('pd-patient').value, 'ΓΑΜΜΑ ΤΡΙΤΟΣ');
});

test('the same patient (or none in the plan): no confirmation asked', () => {
	const env = app();
	paste(env, patientHead('ΑΛΦΑ', 'ΠΡΩΤΟΣ') + ZIN);
	assert.strictEqual(env.d.getElementById('pd-rx-patient-ok'), null);
	assert.strictEqual(addBtn(env).disabled, false);
});

/* (2) review side */
test('fewer medicines read than listed: a blocking note that must be acknowledged', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, HEAD + 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ ; 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n' + PRICE +
		one('COZAAR F.C.TAB 50MG/TAB BTx28', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 28 ημέρες'));
	const note = d.getElementById('pd-rx-count-note');
	assert.match(note.textContent, /^Η συνταγή φαίνεται να έχει 2 φάρμακα, διαβάστηκαν 1 — ελέγξτε\./);
	assert.strictEqual(note.parentNode.getAttribute('role'), 'alert');
	assert.strictEqual(d.activeElement.id, 'pd-rx-count-ack');
	/* The block without a dose line is listed for the form. */
	assert.ok(d.querySelector('#pd-rx-w-0-noDose'));
	assert.strictEqual(addBtn(env).disabled, true);
	change(env, d.getElementById('pd-rx-count-ack'), true);
	assert.strictEqual(addBtn(env).disabled, false);
	addBtn(env).click();
	assert.deepStrictEqual(names(PD), ['COZAAR F.C.TAB 50MG']);
	/* What is left keeps the acknowledgement. */
	assert.strictEqual(PD.s.rx.countAck, true);
});

/* (15) the text above the form */
test('«Συμπλήρωση στη φόρμα» shows the prescription text above the form until added, cleared or new patient', () => {
	const env = app();
	const { PD, d } = env;
	const text = HEAD + 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\nSOS σε περίπτωση πόνου\n' + PRICE;
	paste(env, text + one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 2 φορές την εβδομάδα x 28 ημέρες') + BESPAR);
	const box = d.getElementById('pd-rx-source');
	assert.strictEqual(box.hidden, true);
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(box.hidden, false);
	assert.strictEqual(box.getAttribute('role'), 'note');
	assert.match(box.textContent, /^Από τη συνταγή:/);
	assert.strictEqual(box.querySelector('.pd-rx-source-text').textContent, 'DEPON TAB 500MG/TAB BTx20\nΔΟΣΟΛΟΓΙΑ: 1 ΔΙΣΚΙΑ x 3 φορές την ημέρα x 5 ημέρες\nSOS σε περίπτωση πόνου');
	assert.strictEqual(PD.s.rxSourceText.split('\n').length, 3);
	/* Never printed. */
	PD.s.items.push({ name: 'X', doseAmount: 1, doseUnit: 'tablet', freq: '12h', dailyTime: 'morning', customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null, days: '3', notes: '' });
	assert.ok(!PD.buildPrintHtml().includes('SOS σε'));
	PD.s.items = [];
	/* Added → gone. */
	PD.setFieldValue('pd-notes', 'SOS');
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	assert.strictEqual(PD.s.rxSourceText, '');
	assert.strictEqual(box.hidden, true);
	/* Cleared → gone. */
	d.querySelector('[data-rx-toform="1"]').click();
	assert.match(d.getElementById('pd-rx-source').textContent, /FOSAMAX/);
	PD.clearDrugForm();
	assert.strictEqual(d.getElementById('pd-rx-source').hidden, true);
	/* New patient → gone. */
	d.querySelector('[data-rx-toform="2"]').click();
	assert.strictEqual(d.getElementById('pd-rx-source').hidden, false);
	PD.resetPlanState();
	PD.buildApp();
	assert.strictEqual(d.getElementById('pd-rx-source').hidden, true);
	assert.strictEqual(PD.s.rxSourceText, '');
});

test('the form from a once-daily prescription row: no time preset, add refuses until chosen', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('BESPAR TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες ΠΡΙΝ ΤΟ ΦΑΓΗΤΟ'));
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.currentDailyTime, null);
	assert.strictEqual(d.querySelectorAll('.pd-daily-time-chip.active').length, 0);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0);
	assert.match(d.getElementById('pd-message').textContent, /Επιλέξτε ώρα λήψης/);
	d.querySelector('.pd-daily-time-chip[data-time="noon"]').click();
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 1);
	assert.strictEqual(PD.s.items[0].dailyTime, 'noon');
	/* A new medicine typed by hand still starts at «Πρωί». */
	assert.strictEqual(PD.s.currentDailyTime, 'morning');
});

test('a weekly row sent to the form: no weekday preset there either', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('FOSAMAX TAB 70MG/TAB BTx4', '1 ΔΙΣΚΙΑ x 1 φορά την εβδομάδα x 28 ημέρες'));
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.currentCustomMode, 'weekday');
	assert.strictEqual(PD.s.currentWeekday, null);
	assert.strictEqual(d.querySelectorAll('.pd-weekday-chip.active').length, 0);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0);
	d.querySelector('.pd-weekday-chip[data-weekday="0"]').click();
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items[0].customWeekday, 0);
});

test('a monthly row sent to the form keeps the mode and needs the day', () => {
	const env = app();
	const { PD, d } = env;
	paste(env, one('PROLIA INJ.SOL 60MG/ML BTx1', '1 ΕΝΕΣΗ x 1 φορά τον μήνα x 60 ημέρες'));
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(PD.s.currentCustomMode, 'monthday');
	assert.strictEqual(d.getElementById('pd-custom-monthday-wrap').hidden, false);
	PD.addOrUpdateItem();
	assert.strictEqual(PD.s.items.length, 0);
});

/* (16) */
test('names and prescription text are escaped everywhere in the review', () => {
	const env = app();
	const { d } = env;
	paste(env, one('<IMG SRC=X ONERROR=ALERT(1)> TAB 5MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες') +
		HEAD + 'FOO TAB 5MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n<b>SOS</b> "\'\n' + PRICE);
	assert.strictEqual(d.querySelectorAll('#pd-rx img, #pd-rx b').length, 0);
	assert.match(d.querySelector('.pd-rx-orig-text').textContent, /<IMG SRC=X/);
	assert.match(d.querySelectorAll('.pd-rx-orig-text')[1].textContent, /<b>SOS<\/b> "'/);
});
