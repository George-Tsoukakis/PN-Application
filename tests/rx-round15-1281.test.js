/* 1.28.1: a newborn («ΑΡΡΕΝ-1») with a single vaccine — the name was lost
   because it was written only after a medicine went in directly; and the
   wrapped pack «(γυάλινη) (DOSE) + 2 / βελόνες» was unread text.
   Anonymised: the real prescription is NOT in the repository. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, isoAhead, closeAll } = require('./harness');

const HEAD = 'ΣΤΟΙΧΕΙΑ ΙΑΤΡΟΥ ΣΤΟΙΧΕΙΑ ΑΣΘΕΝΗ\n' +
	'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΔΟΚΙΜΑΣΤΙΚΟΣ\n' +
	'ΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΡΡΕΝ-1\n' +
	'ΕΤΟΣ ΓΕΝΝΗΣΗΣ : 2026\n' +
	'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const VAX = 'FOOVAX INJ.SUSP 0,5ML/DOSE BTx1PF.SYRx0,5ML (γυάλινη) (DOSE) + 2 \n' +
	'βελόνες (Πρωτότυπο) \n' +
	'ΔΟΣΟΛΟΓΙΑ : 1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x εφάπαξ x 1 ημέρες\n' +
	'0% 1 66,70 66,70 66,70 0,00 0,00 66,70\n';
const TAB = 'ZINADOL F.C.TAB 500MG/TAB BTx10\nΔΟΣΟΛΟΓΙΑ : 1 ΔΙΣΚΙΑ x 2 φορές την ημέρα x 7 ημέρες\n25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';

function app() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	return env;
}

function paste(env, text) {
	const drop = env.w.document.getElementById('pd-rx-drop');
	drop.value = text;
	drop.dispatchEvent(new env.w.Event('input', { bubbles: true }));
}

test.after(closeAll);

test('«ΑΡΡΕΝ-1» is read as the patient name', () => {
	const { PD } = load();
	assert.strictEqual(PD.parsePrescription(HEAD + VAX).patient, 'ΔΟΚΙΜΑΣΤΙΚΟΣ ΑΡΡΕΝ-1');
});

test('the wrapped glass-syringe pack is part of the drug line (only the time is left to choose)', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const it = PD.parsePrescription(HEAD + VAX).items[0];
	assert.strictEqual(it.doseUnit, 'injection');
	assert.strictEqual(it.days, '1');
	assert.deepStrictEqual(JSON.parse(JSON.stringify(it.warnings)), ['time']);
});

test('the name is filled when the only medicine goes to the form', () => {
	const env = app();
	const d = env.w.document;
	/* An unreadable dose: the only row can only go to the form. */
	paste(env, HEAD + VAX.replace('1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x εφάπαξ x 1 ημέρες', 'ΟΠΩΣ ΟΡΙΣΤΗΚΕ'));
	assert.strictEqual(env.PD.s.rx.usePatient, true);
	d.querySelector('[data-rx-toform="0"]').click();
	assert.strictEqual(d.getElementById('pd-patient').value, 'ΔΟΚΙΜΑΣΤΙΚΟΣ ΑΡΡΕΝ-1');
});

test('the real flow: vaccine, choose the time, «Προσθήκη» → medicine and name in the plan', () => {
	const env = app();
	const d = env.w.document;
	paste(env, HEAD + VAX);
	d.querySelector('[data-rx-row="0"][data-rx-time="morning"]').click();
	d.getElementById('pd-rx-add').click();
	assert.strictEqual(env.PD.s.items.length, 1);
	assert.strictEqual(d.getElementById('pd-patient').value, 'ΔΟΚΙΜΑΣΤΙΚΟΣ ΑΡΡΕΝ-1');
});

test('unticked «Όνομα ασθενή», or a name already typed: left alone', () => {
	const env = app();
	const d = env.w.document;
	paste(env, HEAD + TAB);
	const box = d.getElementById('pd-rx-use-patient');
	box.checked = false;
	box.dispatchEvent(new env.w.Event('change', { bubbles: true }));
	d.getElementById('pd-rx-add').click();
	assert.strictEqual(d.getElementById('pd-patient').value, '');

	const env2 = app();
	const d2 = env2.w.document;
	d2.getElementById('pd-patient').value = 'ΑΛΛΟΣ ΑΣΘΕΝΗΣ';
	paste(env2, HEAD + TAB);
	d2.getElementById('pd-rx-add').click();
	assert.strictEqual(d2.getElementById('pd-patient').value, 'ΑΛΛΟΣ ΑΣΘΕΝΗΣ');
});
