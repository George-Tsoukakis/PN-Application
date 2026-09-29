/* 1.27.0: the A4 sheet survives background stripping and has readable
   notes. Reads PD.PRINT_STYLES. Run: node --test */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { load, closeAll } = require('./harness');
test.afterEach(closeAll);

function rule(css, selector) {
	const re = new RegExp('(^|\\n)' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}');
	const m = re.exec(css);
	assert.ok(m, 'rule for ' + selector);
	return m[2];
}

function px(decl, prop) {
	const m = new RegExp('(^|;)\\s*' + prop + '\\s*:\\s*([0-9.]+)px').exec(decl);
	assert.ok(m, prop + ' in ' + decl);
	return parseFloat(m[2]);
}

test('day-card date header: dark text on a light tint with a border, not white on green', () => {
	const { PD } = load();
	const head = rule(PD.PRINT_STYLES, '.pd-day-card-head');
	assert.doesNotMatch(head, /color:\s*#fff/i, 'no white text');
	assert.doesNotMatch(head, /background:\s*#0f6e56/i, 'no dark background');
	assert.match(head, /border-bottom:\s*[0-9.]+px solid/);
	assert.match(head, /(^|;)\s*color:\s*#0b4d3c/);
});

test('notes are at least 10.5px, upright, in the main text colour; disclaimer at least 9px', () => {
	const { PD } = load();
	const dayNotes = rule(PD.PRINT_STYLES, '.pd-day-dose-notes');
	assert.ok(px(dayNotes, 'font-size') >= 10.5);
	assert.match(dayNotes, /font-style:\s*normal/);
	assert.match(dayNotes, /(^|;)\s*color:\s*#1a1a1a/);
	const drugNotes = rule(PD.PRINT_STYLES, '.pd-drug-notes');
	assert.ok(px(drugNotes, 'font-size') >= 10.5);
	assert.match(drugNotes, /(^|;)\s*color:\s*#1a1a1a/);
	assert.ok(px(rule(PD.PRINT_STYLES, '.plandose-disclaimer'), 'font-size') >= 9);
});

test('day notes wrap inside the card (long unbroken text)', () => {
	const { PD } = load();
	assert.match(PD.PRINT_STYLES, /\.pd-day-dose-notes,[\s\S]*?overflow-wrap:\s*anywhere/);
});
