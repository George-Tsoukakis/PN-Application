/* The prescription reader split into rx-text / rx-lines / rx-parse /
   rx-review / rx-import: the two methotrexate checks (each catches a
   daily methotrexate on its own), the insulin threshold, the files
   loading (or not) as a set, and the paste box that opens on hover
   without taking the keyboard focus.
   Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, isoAhead, closeAll } = require('./harness');
test.afterEach(closeAll);

const HEAD = 'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const PRICE = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const DAILY = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες';
const WEEKLY = '1 ΔΙΣΚΙΑ x 1 φορά την εβδομάδα x 28 ημέρες';
const RX_FILES = ['rx-text.js', 'rx-lines.js', 'rx-parse.js', 'rx-review.js', 'rx-import.js'];

/* Wraps the methotrexate check: `answer(n, real)` decides call n. */
function wrapped(PD, answer) {
	const real = PD.methotrexateTooOften;
	const calls = { n: 0 };
	PD.methotrexateTooOften = function (item) {
		calls.n++;
		return answer(calls.n, real(item));
	};
	return calls;
}

const MTX = HEAD + 'METHOTREXATE/EBEWE TAB 2,5MG/TAB BTx50\nΔΟΣΟΛΟΓΙΑ : ' + DAILY + '\nΑΝ ΠΟΝΑΕΙ\n' + PRICE;

test('methotrexate named by the drug line: flagged once, the note before those of the unread text', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const calls = wrapped(PD, (n, real) => real);
	const it = PD.parsePrescription(MTX).items[0];
	/* The dose line's check flagged it; the second one sees it flagged. */
	assert.strictEqual(calls.n, 1);
	assert.strictEqual(it.warnings.filter((w) => 'methotrexateDaily' === w).length, 1, it.warnings.join());
	assert.ok(it.warnings.indexOf('time') < it.warnings.indexOf('methotrexateDaily'), it.warnings.join());
	assert.ok(it.warnings.indexOf('methotrexateDaily') < it.warnings.indexOf('extra'), it.warnings.join());
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
});

test('methotrexate: either of the two checks alone still flags it (defence in depth)', () => {
	for (const [label, answer] of [['only the dose line check answers', (n, real) => 1 === n && real],
		['only the check after the whole text answers', (n, real) => 1 !== n && real]]) {
		const { PD } = load();
		PD.s.startDate = isoAhead(1);
		const calls = wrapped(PD, answer);
		const it = PD.parsePrescription(MTX).items[0];
		assert.ok(calls.n >= 1, label);
		assert.strictEqual(it.warnings.filter((w) => 'methotrexateDaily' === w).length, 1, label + ': ' + it.warnings.join());
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', label);
		closeAll();
	}
});

test('methotrexate named only by a line wrapped above the drug line: flagged by the second check', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const calls = wrapped(PD, (n, real) => real);
	const it = PD.parsePrescription(HEAD + 'METHOTREX\nATE/EBEWE TAB 2,5MG/TAB BTx50\nΔΟΣΟΛΟΓΙΑ : ' + DAILY + '\n' + PRICE).items[0];
	assert.strictEqual(it.name, 'ATE/EBEWE TAB 2,5MG');
	assert.strictEqual(calls.n, 2, 'both checks asked');
	assert.strictEqual(it.warnings.filter((w) => 'methotrexateDaily' === w).length, 1, it.warnings.join());
	assert.strictEqual(PD.rxItemProblem(it), 'warnings');
});

test('methotrexate once a week, or another medicine daily: no methotrexate warning, both checks asked for each', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const calls = wrapped(PD, (n, real) => real);
	const r = PD.parsePrescription(HEAD + 'METHOTREXATE/EBEWE TAB 2,5MG/TAB BTx50\nΔΟΣΟΛΟΓΙΑ : ' + WEEKLY + '\n' + PRICE +
		'METHOTREX\nATE/EBEWE TAB 2,5MG/TAB BTx50\nΔΟΣΟΛΟΓΙΑ : ' + WEEKLY + '\n' + PRICE +
		'NEXIUM GR.TAB 20MG BTx28\nΔΟΣΟΛΟΓΙΑ : ' + DAILY + '\n' + PRICE);
	assert.strictEqual(r.items.length, 3);
	assert.strictEqual(calls.n, 6);
	r.items.forEach((it) => assert.ok(!it.warnings.includes('methotrexateDaily'), it.name + ' ' + it.warnings));
});

/* The insulin rule's threshold: «N ΕΝΕΣΕΙΣ» of an insulin over 3 is read as
   units to confirm; 3 or less is left empty for the pharmacist. */
test('insulin dosed as «N ΕΝΕΣΕΙΣ»: 4 and more are units (confirmed), 3 and less are left empty', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const read = (n) => PD.parsePrescription(HEAD + 'LANTUS SOLOSTAR INJ.SOL 100IU/ML BTx5 PENSx3ML\nΔΟΣΟΛΟΓΙΑ : ' + n +
		' ΕΝΕΣΕΙΣ x 1 φορά την ημέρα x 30 ημέρες\n' + PRICE).items[0];
	for (const n of [4, 12, 22]) {
		const it = read(n);
		assert.strictEqual(it.doseUnit, 'iu', n + ': ' + it.doseUnit);
		assert.ok(it.warnings.includes('iuConfirm'), n + ': ' + it.warnings);
		assert.notStrictEqual(it.doseUnit, 'injection');
	}
	for (const n of [1, 2, 3]) {
		const it = read(n);
		assert.strictEqual(it.doseUnit, '', n + ': ' + it.doseUnit);
		assert.ok(it.warnings.includes('insulinUnits'), n + ': ' + it.warnings);
		assert.strictEqual(PD.rxItemProblem(it), 'warnings', n);
	}
});

test('the rx files share one private namespace; the public entry points stay on PD', () => {
	const { PD } = load();
	assert.strictEqual(typeof PD.rx._, 'object');
	for (const f of ['parsePrescription', 'prescriptionPatients', 'prescriptionPatient', 'prescriptionWarningText', 'rxPending',
		'rxItemProblem', 'rxQuantityCheck', 'rxImportHtml', 'wireRxImport', 'closeRxImport', 'rxIsDrugLine']) {
		assert.strictEqual(typeof PD[f], 'function', f);
	}
	assert.strictEqual(PD.RX_MAX_INPUT, 200000);
});

test('a missing rx file leaves the whole import out, never half of it, and the tool still builds', () => {
	for (const missing of RX_FILES.slice(0, 4)) {
		const { PD, w } = load({ skip: [missing] });
		PD.s.startDate = isoAhead(1);
		assert.strictEqual(PD.rxImportHtml, undefined, missing);
		assert.strictEqual(PD.wireRxImport, undefined, missing);
		PD.buildApp();
		assert.ok(w.document.getElementById('pd-drug'), missing + ': form built');
		assert.strictEqual(w.document.getElementById('pd-rx'), null, missing + ': no paste box');
		closeAll();
	}
});

/* The paste box: hover may open it, only a click or the keyboard moves the focus (WCAG 3.2.1). */
function tool() {
	const env = load();
	env.PD.s.startDate = isoAhead(1);
	env.PD.buildApp();
	const d = env.w.document;
	return { env, d, drop: d.getElementById('pd-rx-drop'), box: d.querySelector('.pd-rx-drop') };
}

const mouse = (w, el, type) => el.dispatchEvent(new w.MouseEvent(type, { bubbles: 'click' === type }));

test('hovering the paste box opens it but leaves the focus where it was', () => {
	const { env, d, drop, box } = tool();
	const w = env.w;
	for (const before of [d.body, d.getElementById('plandose-close'), d.getElementById('pd-patient')]) {
		if (before !== d.body) {
			before.focus();
		} else if (d.activeElement && d.activeElement.blur) {
			d.activeElement.blur();
		}
		const was = d.activeElement;
		mouse(w, box, 'mouseenter');
		assert.ok(box.classList.contains('is-open'), 'open on hover');
		assert.strictEqual(d.activeElement, was, 'focus not moved by the hover (' + (was && was.id) + ')');
		assert.notStrictEqual(d.activeElement, drop);
		mouse(w, box, 'mouseleave');
		assert.ok(!box.classList.contains('is-open'), 'closed again when the pointer leaves');
		assert.strictEqual(d.activeElement, was);
	}
});

test('a click on the box, or Tab into the field, puts the caret there and keeps it open', () => {
	const { env, d, drop, box } = tool();
	const w = env.w;
	mouse(w, box, 'mouseenter');
	/* the «ΝΕΟ» badge, part of the box but not the field */
	mouse(w, box.querySelector('.pd-rx-new'), 'click');
	assert.strictEqual(d.activeElement, drop, 'click → focus');
	mouse(w, box, 'mouseleave');
	assert.ok(box.classList.contains('is-open'), 'a clicked box stays open');
	drop.blur();
	assert.ok(!box.classList.contains('is-open'));
	/* keyboard */
	drop.focus();
	assert.ok(box.classList.contains('is-open'), 'focus from the keyboard opens it');
	assert.strictEqual(d.activeElement, drop);
});

test('the rx files load before modal.js and app.js, in their dependency order', () => {
	const fs = require('fs');
	const path = require('path');
	const php = fs.readFileSync(path.join(require('./lib/env.js').pluginDir(), 'includes', 'class-plandose-frontend.php'), 'utf8');
	const lazy = /\$ordered\[\] = 'plandose-rx-text';\s*\$ordered\[\] = 'plandose-rx-lines';\s*\$ordered\[\] = 'plandose-rx-parse';\s*\$ordered\[\] = 'plandose-rx-review';\s*\$ordered\[\] = 'plandose-rx-import';\s*\$ordered\[\] = 'plandose-modal';/;
	assert.match(php, lazy, 'lazy loader list');
	for (const f of RX_FILES) {
		assert.ok(php.includes("'assets/js/" + f + "',\n"), f + ' in the readable-assets check');
		assert.ok(php.includes("=> 'assets/js/" + f + "'"), f + ' registered');
	}
});
