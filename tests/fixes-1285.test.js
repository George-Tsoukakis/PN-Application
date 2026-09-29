/* 1.28.5: the fixes of the joint review (two independent audits of 1.28.4).
   - «.25MG» / «,5MG»: the leading zero is put back and the row must go
     through the form (not a plain «Επιβεβαιώνω»);
   - the parser keeps nothing of the pasted text after the parse;
   - the phone-reminder QR tells the pharmacist when it dropped the notes
     or could not be made at all;
   - a plan that was never printed is cleared after the idle time too. */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll, isoAhead } = require('./harness');
const fakeClock = require('./lib/fake-clock');

test.afterEach(closeAll);

const HEAD = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 1,00 1,00 1,00 0,00 0,25 0,75\n';
const DAILY = '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες';
const parse = (PD, drug, dose) => PD.parsePrescription(HEAD + drug + '\nΔΟΣΟΛΟΓΙΑ : ' + (dose || DAILY) + '\n' + P);

/* The row with every choice made and every confirmation ticked. */
function allConfirmed(it) {
	return Object.assign({}, it, {
		dailyTime: 'morning', customWeekday: 1, customMonthDay: 1,
		confirmed: { name: true, qty: true, freq: true, days: true }
	});
}

test('a strength without its leading zero gets the zero back, never 100× the strength', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const cases = [
		['XANAX TAB .25MG BTx30', 'XANAX TAB 0.25MG', '0.25mg'],
		['XANAX TAB .5MG/TAB BTx30', 'XANAX TAB 0.5MG', '0.5mg'],
		['FOOXAN TAB ,25MG BTx30', 'FOOXAN TAB 0,25MG', '0,25mg'],
		['FOOXAN TAB ,5MG BTx30', 'FOOXAN TAB 0,5MG', '0,5mg'],
		['FOOXAN TAB .125MG BTx30', 'FOOXAN TAB 0.125MG', '0.125mg']
	];
	for (const [drug, name] of cases) {
		const it = parse(PD, drug).items[0];
		assert.strictEqual(it.name, name, drug);
		assert.ok(it.warnings.includes('strengthZero'), drug + ' ' + it.warnings);
		assert.doesNotMatch(it.name, /(^|[^0-9.,])(25|5|125)MG/, drug + ' read as a larger strength');
	}
});

test('the restored zero keeps an ion bracket in place, and the review marks the name as doubtful', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const it = parse(PD, 'FOOCAL TAB .1229,6(121,5MG++)MG BTx30').items[0];
	assert.match(it.name, /0\.1229,6\(121,5MG\+\+\)MG/, it.name);
	assert.ok(it.warnings.includes('strengthZero'));
	const src = require('fs').readFileSync(require('path').join(require('./harness').JS_DIR, 'rx-review.js'), 'utf8');
	assert.match(src, /var WARN_FIELD = \{[^}]*strengthZero: 'name'/);
});

test('«strengthZero» cannot be settled with «Επιβεβαιώνω»: the row goes through the form', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	const it = parse(PD, 'XANAX TAB .25MG BTx30').items[0];
	assert.strictEqual(PD.rxItemProblem(allConfirmed(it)), 'warnings');
	assert.ok(PD.prescriptionWarningText('strengthZero').length > 20);
});

test('ordinary strengths are unchanged and not flagged', () => {
	const { PD } = load();
	PD.s.startDate = isoAhead(1);
	for (const [drug, name] of [
		['FOOXAN TAB 0.25MG BTx30', 'FOOXAN TAB 0.25MG'],
		['FOOXAN TAB 0,025MG BTx30', 'FOOXAN TAB 0,025MG'],
		['FOOXAN F.C.TAB 5MG BTx30', 'FOOXAN F.C.TAB 5MG'],
		['FOOXAN F.C.TAB 25MG/TAB BTx30', 'FOOXAN F.C.TAB 25MG']
	]) {
		const it = parse(PD, drug).items[0];
		assert.strictEqual(it.name, name, drug);
		assert.ok(!it.warnings.includes('strengthZero'), drug + ' ' + it.warnings);
	}
});

test('the parser keeps nothing of the pasted text once the parse ends', () => {
	const fs = require('fs');
	const path = require('path');
	const { JS_DIR } = require('./harness');
	const { w, PD } = load({ skip: ['rx-lines.js', 'rx-parse.js', 'rx-review.js', 'rx-import.js', 'modal.js', 'app.js'] });
	/* The same file, with a read-only look at its private memo. */
	const src = fs.readFileSync(path.join(JS_DIR, 'rx-lines.js'), 'utf8')
		.replace('PD.rxIsDrugLine = isBrandLine;', 'PD.rxIsDrugLine = isBrandLine; PD.__memoKeys = function () { return Object.keys(brandMemo); };');
	assert.match(src, /__memoKeys/, 'hook not injected (rx-lines.js changed shape)');
	w.eval(src);
	w.eval(fs.readFileSync(path.join(JS_DIR, 'rx-parse.js'), 'utf8'));
	PD.s.startDate = isoAhead(1);
	const r = PD.parsePrescription('ΕΠΩΝΥΜΟ : ΙΑΤΡΟΣ ΕΠΩΝΥΜΟ : ΠΑΠΑΔΟΠΟΥΛΟΣ\nΑ.Μ.Κ.Α. : 12345678901 Α.Μ.Κ.Α. : 01019012345\n' +
		'Μονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\nLANITOP TAB 0,1MG/TAB BTx30\nΔΟΣΟΛΟΓΙΑ : ' + DAILY + '\n' + P);
	assert.strictEqual(r.items.length, 1);
	assert.strictEqual(PD.__memoKeys().length, 0);
	/* also when the parse throws half way */
	PD.parsePrescription('ΑΜΚΑ 12345678901\nLANITOP TAB 0,1MG/TAB BTx30');
	assert.strictEqual(PD.__memoKeys().length, 0);
});

/* ---- the phone-reminder QR tells the pharmacist what it left out ---- */

const CAL_URL = 'https://pharmacy.test/wp-content/plugins/plandose/public/calendar.html?v=1.28.5';
function med(name, freq, days, extra) {
	return Object.assign({
		name: name, doseAmount: 1, doseUnit: 'tablet', freq: freq, dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: 1, days: String(days), notes: ''
	}, extra || {});
}
function qrSetup(items, calendarUrl) {
	const e = load({ config: { calendarUrl: undefined === calendarUrl ? CAL_URL : calendarUrl } });
	e.PD.s.startDate = isoAhead(0);
	e.PD.s.firstSlot = 'morning';
	e.PD.s.items = items;
	e.PD.s.header = { name: 'Φαρμακείο Δοκιμής' };
	e.PD.beginDayPass();
	const area = e.w.document.createElement('div');
	area.id = 'pd-preview-area';
	e.w.document.body.appendChild(area);
	return e;
}

test('QR: notes left out → the preview says so, the printed sheet does not', () => {
	const many = [];
	for (let i = 0; i < 5; i++) {
		many.push(med('ΦΑΡΜΑΚΟ ΜΕ ΜΕΓΑΛΟ ΟΝΟΜΑ ' + i + ' 500MG', '12h', 30, { notes: 'Με άδειο στομάχι, μισή ώρα πριν το φαγητό, με νερό ' + i }));
	}
	const { PD, w } = qrSetup(many);
	assert.ok(PD.calendarLink(), 'a QR is still made');
	const notice = PD.calendarQrNotice();
	assert.match(notice, /σημειώσεις/);
	PD.renderPreview();
	const shown = w.document.querySelector('#pd-preview-area .pd-preview-notice');
	assert.ok(shown, 'notice above the preview');
	assert.strictEqual(shown.textContent, notice);
	assert.ok(!PD.buildPrintHtml().includes(notice), 'never on the printed sheet');
});

test('QR: a plan too large for any QR → the preview says the sheet prints without it', () => {
	const huge = [];
	for (let i = 0; i < 30; i++) {
		huge.push(med('ΠΟΛΥ ΜΕΓΑΛΟ ΟΝΟΜΑ ΦΑΡΜΑΚΟΥ ΑΡΙΘΜΟΣ ' + i + ' 1000MG TAB', '6h', 60));
	}
	const { PD, w } = qrSetup(huge);
	assert.strictEqual(PD.calendarLink(), '');
	assert.match(PD.calendarQrNotice(), /χωρίς QR/);
	PD.renderPreview();
	assert.ok(w.document.querySelector('#pd-preview-area .pd-preview-notice'));
});

test('QR: nothing to say when it fits whole, when there were no notes to lose, or when the QR is off', () => {
	assert.strictEqual(qrSetup([med('A', '12h', 7, { notes: 'μετά το φαγητό' })]).PD.calendarQrNotice(), '');
	const noNotes = [];
	for (let i = 0; i < 6; i++) {
		noNotes.push(med('ΦΑΡΜΑΚΟ ' + i + ' 500MG', '12h', 30));
	}
	const nn = qrSetup(noNotes).PD;
	assert.ok(nn.calendarLink());
	assert.strictEqual(nn.calendarQrNotice(), '');
	const off = qrSetup([med('A', '12h', 7)], '');
	assert.strictEqual(off.PD.calendarQrNotice(), '');
	off.PD.renderPreview();
	assert.ok(!off.w.document.querySelector('.pd-preview-notice'));
});

/* ---- a plan that was never printed is cleared after the idle time too ---- */

function openTool(w, PD) {
	PD.refreshNonce = () => new w.Promise(() => {});
	PD.startNonceRefresh = () => {};
	PD.loadHeader = () => {};
	PD.openModal();
}

test('an unprinted plan left idle is cleared; activity restarts the countdown', () => {
	const { w, PD } = load();
	/* A fake clock: real sleeps raced the 300 ms timer under CPU load. */
	const c = fakeClock.install(w);
	openTool(w, PD);
	PD.PRINTED_IDLE_MS = 300;
	const patient = w.document.getElementById('pd-patient');
	patient.value = 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΓΙΑΝΝΗΣ';
	PD.s.items = [med('LANITOP TAB 0,1MG', '24h', 30)];
	patient.dispatchEvent(new w.Event('input', { bubbles: true }));
	assert.ok(PD.s.draftIdleTimer, 'armed by activity');
	c.advance(200);
	w.document.getElementById('plandose-modal').dispatchEvent(new w.KeyboardEvent('keydown', { bubbles: true }));
	c.advance(200);
	assert.strictEqual(PD.s.items.length, 1, 'activity restarted the countdown');
	c.advance(100);
	assert.strictEqual(PD.s.items.length, 0, 'cleared after the idle time');
	assert.strictEqual(String(PD.getPatientName() || ''), '');
	assert.match(w.document.getElementById('pd-message').textContent, /χωρίς δραστηριότητα|inactivity/);
});

test('idle clearing waits for a running print and does nothing on an empty form or a closed popup', () => {
	const { w, PD } = load();
	const c = fakeClock.install(w);
	openTool(w, PD);
	PD.PRINTED_IDLE_MS = 30;
	PD.s.items = [med('A', '24h', 7)];
	PD.s.isPrinting = true;
	PD.armDraftIdleTimer();
	c.advance(60);
	assert.strictEqual(PD.s.items.length, 1, 'never under a running print');
	PD.s.isPrinting = false;
	c.advance(60);
	assert.strictEqual(PD.s.items.length, 0);

	const built = [];
	const orig = PD.buildApp;
	PD.buildApp = function () { built.push(1); return orig.apply(this, arguments); };
	PD.armDraftIdleTimer();
	c.advance(60);
	assert.strictEqual(built.length, 0, 'empty form: nothing to clear');

	PD.s.items = [med('A', '24h', 7)];
	PD.armDraftIdleTimer();
	PD.closeModal();
	assert.strictEqual(PD.s.draftIdleTimer, null, 'closing stops it');
});
