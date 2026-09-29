/* 1.27.0: modal — the dialog is inert under the close confirmation, and
   an ignored Close/Escape during a print is announced. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

function open(w, PD) {
	PD.refreshNonce = () => new w.Promise(() => {});
	PD.startNonceRefresh = () => {};
	PD.loadHeader = () => {};
	PD.openModal();
}

test('the dialog under the close-confirmation box is inert, and usable again after «Όχι»', () => {
	const { w, PD } = load();
	open(w, PD);
	const modal = w.document.getElementById('plandose-modal');
	PD.requestCloseModal();
	assert.ok(modal.hasAttribute('inert'));
	assert.strictEqual(modal.getAttribute('aria-hidden'), 'true');
	const confirm = w.document.getElementById('plandose-confirm-close');
	assert.ok(!confirm.closest('[inert]'), 'the confirmation itself is not inert');
	w.document.getElementById('plandose-confirm-no').click();
	assert.ok(!modal.hasAttribute('inert'));
	assert.ok(!modal.hasAttribute('aria-hidden'));
	assert.ok(modal.contains(w.document.activeElement), 'focus back inside the dialog');
});

test('Escape closes the confirmation and releases the dialog', () => {
	const { w, PD } = load();
	open(w, PD);
	PD.requestCloseModal();
	w.document.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape' }));
	assert.ok(!w.document.getElementById('plandose-confirm-close'));
	assert.ok(!w.document.getElementById('plandose-modal').hasAttribute('inert'));
});

test('Close during a print is announced instead of silently ignored', async () => {
	const { w, PD } = load();
	open(w, PD);
	PD.s.isPrinting = true;
	PD.requestCloseModal();
	assert.ok(!w.document.getElementById('plandose-confirm-close'), 'no confirmation');
	await new Promise((r) => setTimeout(r, 60));
	const note = w.document.getElementById('pd-close-blocked');
	assert.ok(note);
	assert.strictEqual(note.getAttribute('aria-live'), 'assertive');
	assert.match(note.textContent, /εκτύπωση βρίσκεται σε εξέλιξη/);
	PD.unlockPrintUi();
	assert.strictEqual(note.textContent, '', 'cleared when the print ends');
});
