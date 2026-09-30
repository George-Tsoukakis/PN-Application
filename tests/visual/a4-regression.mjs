/* A4 plan regression in real Chromium, without WordPress.

   The plugin's own modules (same order as the plugin enqueues them) and its
   real PHP dictionaries build the A4 sheet (PD.buildPrintHtml() +
   PD.PRINT_STYLES) for hard plans: very long names (also without spaces),
   300-character notes, ten 6-hourly medicines, 60-day plans, weekly and
   monthly items, and all of them mixed. For each plan, with print media:

   - no cell/card/box overflows (scrollWidth/scrollHeight beyond its box),
     the page never scrolls sideways;
   - no two text blocks overlap;
   - no day card is split across pages (a marker at the top and bottom of
     every card must land on the same PDF page — pdftotext; without it,
     only the CSS rule: every card, and every row without a full-width
     dense card, is break-inside: avoid and shorter than a page);
   - every medicine name and note, and the footer (disclaimer and the two
     thanks lines, the plugin's default settings), is in the sheet in full;
   - the on-screen preview (#pd-preview-area in the popup, real
     plandose.css + plandose-guest.css) lays the sheet out exactly like
     the printed page: same boxes, same card widths, same colours;
   - the PDF (tests/visual/out/<plan>.pdf) matches the baseline PNGs
     (tests/visual/baseline/<plan>-<page>.png, pdftoppm) within a tolerance.

   npm run test:visual               check
   npm run test:visual -- --update   rewrite the baselines (review them!)
   PD_VISUAL_TOLERANCE=0.002         share of differing pixels allowed per page
   PD_VISUAL_ONLY=long-names,mixed   run only these plans
   PD_VISUAL_PIXELS=0                layout checks only, no baseline comparison

   Every plan runs twice: without and with the «Υπενθυμίσεις στο κινητό» QR
   (calendar-qr.js; on by default in the plugin, a third box in the header).
   The QR variant is named <plan>-qr.

   PD_VISUAL_ONLY=mixed-qr           just that variant (a plan id runs both)
   PD_VISUAL_SHOTS=<dir>             also write preview/print screenshots and
                                     their pixel diff there, per variant

   Baselines depend on the fonts of the machine that made them: regenerate
   them on the machine (or CI image) that checks them. TEST-ONLY. */
import test from 'node:test';
import assert from 'node:assert';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';
import env from '../lib/env.js';
import i18n from '../lib/i18n.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(HERE, 'out');
const BASELINE = path.join(HERE, 'baseline');
const UPDATE = process.argv.includes('--update');
const TOLERANCE = parseFloat(process.env.PD_VISUAL_TOLERANCE || '0.002');
const ONLY = (process.env.PD_VISUAL_ONLY || '').split(',').filter(Boolean);
const DPI = 60;
const PIXELS = process.env.PD_VISUAL_PIXELS !== '0';
const SHOTS = process.env.PD_VISUAL_SHOTS || '';

const JS_DIR = env.findJsDir();
const ORDER = ['state.js', 'api.js', 'validation.js', 'preview.js', 'medicine-form.js',
	'print-styles.js', 'calendar-qr.js', 'print.js', 'pro-labels.js',
	'rx-text.js', 'rx-lines.js', 'rx-parse.js', 'rx-review.js', 'rx-import.js', 'modal.js', 'app.js'];

/* What the plugin hands the page when the QR is on (calendar_url()). */
const CALENDAR_URL = 'https://pharmacy.example/wp-content/plugins/plandose/public/calendar.html?v=1.30.0';

/* A4 minus the @page margins of PRINT_STYLES (12mm 10mm), in CSS px. */
const MM = 96 / 25.4;
const PRINT_WIDTH = Math.floor((210 - 20) * MM);
const PRINT_HEIGHT = Math.floor((297 - 24) * MM);

/* Monday 5 January 2026, 09:00 Athens: dates on the sheet stay put. */
const NOW = new Date('2026-01-05T09:00:00+02:00');

function has(cmd) {
	try {
		execFileSync(cmd, ['-v'], { stdio: 'ignore' });
		return true;
	} catch (e) {
		return false;
	}
}
const HAS_POPPLER = has('pdftoppm') && has('pdftotext');

function dictionaries() {
	try {
		return i18n.flatDictionaries(env.pluginDir(), 90);
	} catch (e) {
		console.warn('# real dictionaries unavailable (' + e.message.split('\n')[0] + '); using the JS fallbacks');
		return { el: {}, en: {} };
	}
}

/* ---- the plans ------------------------------------------------------- */

const LONG_NOTE = ('Λαμβάνεται με άφθονο νερό, μισή ώρα πριν από το φαγητό, όρθιος. Όχι μαζί με γάλα, ' +
	'γιαούρτι, ασβέστιο, σίδηρο ή αντιόξινα — τουλάχιστον 2 ώρες πριν ή 6 ώρες μετά. Αν ξεχάσετε μια δόση, ' +
	'πάρτε την μόλις το θυμηθείτε, εκτός αν πλησιάζει η επόμενη. Ολοκληρώστε όλο το σχήμα χωρίς διακοπή.').slice(0, 300);

function med(name, extra) {
	return Object.assign({
		name, doseAmount: 1, doseUnit: 'tablet', freq: '24h', dailyTime: 'morning',
		customMode: 'days', customIntervalDays: '', customWeekday: null, customMonthDay: null,
		days: '7', notes: ''
	}, extra || {});
}

const PLANS = [
	{
		id: 'long-names',
		patient: 'Δοκιμίνα-Εξεταστίνα Δοκιμαστοπούλου-Υποθετικογεωργίου του Πλασματικού',
		items: [
			med('AMOXICILLIN/CLAVULANIC ACID SANDOZ F.C.TAB (875+125)MG/TAB BTx14 (ΓΕΝΟΣΗΜΟ ΕΠΙΚΑΛΥΜΜΕΝΟ ΔΙΣΚΙΟ)', { freq: '12h', days: '10', notes: LONG_NOTE }),
			med('ΠΟΛΥΒΙΤΑΜΙΝΟΥΧΟΣΥΜΠΛΗΡΩΜΑΔΙΑΤΡΟΦΗΣΜΕΣΙΔΗΡΟΦΥΛΛΙΚΟΟΞΥΚΑΙΒΙΤΑΜΙΝΗΒ12ΓΙΑΕΓΚΥΜΟΝΕΣΚΑΙΘΗΛΑΖΟΥΣΕΣ1000MG', { freq: '24h', days: '14', notes: 'ΧωρίςΚενάΣτιςΣημειώσειςΓιαΝαΔοκιμαστείΗΑναδίπλωσηΤουΚειμένουΣεΣτενόΚουτί'.repeat(3) }),
			med('METHYLPREDNISOLONE ACEPONATE CREAM 0,1% W/W ΤΟΠΙΚΗ ΧΡΗΣΗ ΣΤΟ ΠΡΟΣΩΠΟ ΚΑΙ ΣΤΑ ΧΕΡΙΑ ΜΟΝΟ', { doseUnit: 'application', freq: '8h', days: '5', notes: LONG_NOTE })
		]
	},
	{
		id: 'six-hourly-10',
		patient: 'Δοκιμής Πρώτος',
		qrTooBig: true,
		items: Array.from({ length: 10 }, (_, i) => med('ΦΑΡΜΑΚΟ ' + (i + 1) + ' ΔΟΚΙΜΗΣ F.C.TAB 500MG/TAB', {
			freq: '6h', days: '7', doseAmount: i % 3 === 0 ? 0.5 : 1, notes: i % 2 ? 'Μετά το φαγητό.' : ''
		}))
	},
	{
		id: 'sixty-days',
		patient: 'Δοκιμή Δεύτερη',
		items: [
			med('ATROST F.C.TAB 40MG', { freq: '24h', days: '60', dailyTime: 'evening' }),
			med('INDERAL F.C.TAB 40MG', { freq: '12h', days: '60' }),
			med('GLUCOPHAGE F.C.TAB 1000MG', { freq: '8h', days: '60', notes: 'Με το φαγητό.' })
		]
	},
	{
		id: 'weekly-monthly',
		patient: 'Δοκιμής Τρίτος',
		monthday: true,
		items: [
			med('OZEMPIC INJ.SOL 1MG/0,74ML', { doseUnit: 'injection', freq: 'custom', customMode: 'weekday', customWeekday: 3, days: '60' }),
			med('NORDIMET INJ.SOL 15MG/0,6ML (ΜΕΘΟΤΡΕΞΑΤΗ — ΜΙΑ ΦΟΡΑ ΤΗΝ ΕΒΔΟΜΑΔΑ)', { doseUnit: 'injection', freq: 'custom', customMode: 'weekday', customWeekday: 1, days: '60', notes: 'ΜΟΝΟ ΜΙΑ ΦΟΡΑ ΤΗΝ ΕΒΔΟΜΑΔΑ, κάθε Δευτέρα.' }),
			med('PROLIA INJ.SOL 60MG/1ML', { doseUnit: 'injection', freq: 'custom', customMode: 'monthday', customMonthDay: 15, days: '60' }),
			med('VITAMIN D3 50.000 IU CAPS', { doseUnit: 'capsule', freq: 'custom', customMode: 'days', customIntervalDays: '14', days: '60' })
		]
	},
	{
		id: 'mixed',
		patient: 'Υποθετικός Δοκιμαστόπουλος',
		qrTooBig: true,
		monthday: true,
		items: [
			med('AMOXICILLIN/CLAVULANIC ACID SANDOZ F.C.TAB (875+125)MG/TAB BTx14 (ΓΕΝΟΣΗΜΟ ΕΠΙΚΑΛΥΜΜΕΝΟ ΔΙΣΚΙΟ)', { freq: '12h', days: '10', notes: LONG_NOTE }),
			med('DEPON TAB 500MG', { freq: '6h', days: '5', doseAmount: 2 }),
			med('LANTUS SOLOSTAR INJ.SOL 100U/ML', { doseUnit: 'iu', doseAmount: 14, freq: '24h', days: '30', dailyTime: 'evening' }),
			med('OZEMPIC INJ.SOL 1MG/0,74ML', { doseUnit: 'injection', freq: 'custom', customMode: 'weekday', customWeekday: 5, days: '30' }),
			med('PROLIA INJ.SOL 60MG/1ML', { doseUnit: 'injection', freq: 'custom', customMode: 'monthday', customMonthDay: 20, days: '30' }),
			med('RHINASPRAY NASAL SPRAY', { doseUnit: 'spray', doseAmount: 2, freq: '8h', days: '7', notes: 'Σε κάθε ρουθούνι.' }),
			med('ΠΟΛΥΒΙΤΑΜΙΝΟΥΧΟΣΥΜΠΛΗΡΩΜΑΔΙΑΤΡΟΦΗΣΜΕΣΙΔΗΡΟΦΥΛΛΙΚΟΟΞΥΚΑΙΒΙΤΑΜΙΝΗΒ12ΓΙΑΕΓΚΥΜΟΝΕΣΚΑΙΘΗΛΑΖΟΥΣΕΣ1000MG', { days: '30' })
		]
	}
];

/* Each plan without and with the calendar QR. */
const VARIANTS = PLANS.flatMap((p) => [
	Object.assign({}, p, { plan: p.id, qr: false }),
	Object.assign({}, p, { plan: p.id, id: p.id + '-qr', qr: true })
]).filter((v) => !ONLY.length || ONLY.includes(v.id) || ONLY.includes(v.plan));

const HEADER = {
	name: 'ΦΑΡΜΑΚΕΙΟ ΔΟΚΙΜΗΣ ΔΟΚΙΜΑΣΤΙΚΟΥ ΦΑΡΜΑΚΟΠΟΙΟΥ',
	address: 'Λεωφόρος Κνωσού 123, Ηράκλειο Κρήτης 71409',
	phone_1: '2810 000000',
	email: 'info@pharmacy-test.example'
};

/* ---- in the page ----------------------------------------------------- */

/* Runs in Chromium: the sheet for one plan, or { skip } when the plugin
   cannot express it (e.g. no «day of the month» mode yet). */
function buildSheet(plan) {
	const PD = window.__PlandoseNS;
	if (plan.monthday && typeof PD.validMonthDay !== 'function') {
		plan.items = plan.items.filter((it) => it.customMode !== 'monthday');
		plan.skipped = 'monthday not supported by this PlanDose';
	}
	PD.config.calendarUrl = plan.qr ? plan.calendarUrl : '';
	PD.s.items = plan.items.map((it) => Object.assign({}, it));
	PD.s.header = plan.header;
	PD.s.startDate = '';
	PD.s.firstSlot = 'morning';
	const patient = document.getElementById('pd-patient');
	if (patient) {
		patient.value = plan.patient;
	}
	if (PD.getPatientName && PD.getPatientName() !== plan.patient) {
		PD.getPatientName = () => plan.patient;
	}
	const body = PD.buildPrintHtml();
	/* The same plan in the popup's preview step. */
	PD.s.currentStep = 2;
	PD.updateStepsUi();
	PD.renderPreview();
	const shown = document.querySelector('#pd-preview-area .pd-print-document');
	const parsed = (html) => {
		const t = document.createElement('template');
		t.innerHTML = html;
		return t.innerHTML;
	};
	return {
		html: '<!DOCTYPE html><html lang="el"><head><meta charset="utf-8"><title>Πλάνο</title><style>' +
			PD.PRINT_STYLES + '</style></head><body>' + body + '</body></html>',
		previewSame: !!shown && shown.outerHTML === parsed(body),
		hasQr: body.indexOf('pd-info-grid-cal') !== -1,
		qrNotice: plan.qr ? PD.calendarQrNotice() : '',
		skipped: plan.skipped || ''
	};
}

/* Runs in the print sheet and in the preview: where every block of the
   sheet sits, in the sheet's own unscaled CSS px (the preview is scaled
   down to fit the popup), plus the colours the pharmacist checks. */
function sheetLayout() {
	const doc = document.querySelector('.pd-print-document');
	const d = doc.getBoundingClientRect();
	const k = doc.offsetWidth / d.width;
	const round = (v) => Math.round(v * k * 10) / 10;
	const sel = '.pd-print-header, .pd-print-intro, .pd-info-grid, .pd-info-box, .pd-cal-qr, .pd-med-key, .pd-med-key-row, ' +
		'.pd-day-row, .pd-day-card, .pd-day-card-head, .pd-day-slot, .pd-day-dose, .pd-day-dose-notes, .plandose-disclaimer, .pd-print-thanks';
	const boxes = Array.from(doc.querySelectorAll(sel), (el, i) => {
		const r = el.getClientRects()[0] || el.getBoundingClientRect();
		return { i, cls: String(el.className), x: round(r.left - d.left), y: round(r.top - d.top), w: round(r.width), h: round(r.height) };
	});
	const look = (q, props) => {
		const el = doc.querySelector(q);
		if (!el) {
			return null;
		}
		const cs = getComputedStyle(el);
		return props.map((p) => p + ': ' + cs.getPropertyValue(p)).join('; ');
	};
	return {
		width: doc.offsetWidth,
		height: doc.offsetHeight,
		boxes,
		dense: doc.querySelectorAll('.pd-day-card-dense').length,
		head: look('.pd-day-card-head', ['background-color', 'color', 'border-bottom-color', 'font-size']),
		notes: look('.pd-day-dose-notes', ['color', 'font-style', 'font-weight', 'font-size', 'display']),
		key: look('.pd-med-key', ['background-color', 'font-size']),
		brand: look('.pd-print-brand strong', ['color', 'font-size', 'font-family'])
	};
}

/* Where the preview and the print layouts differ (empty when they match). */
function layoutDiff(print, preview) {
	const out = [];
	if (Math.abs(print.width - preview.width) > 1) {
		out.push('sheet width: print ' + print.width + 'px, preview ' + preview.width + 'px');
	}
	if (Math.abs(print.height - preview.height) > 2) {
		out.push('sheet height: print ' + print.height + 'px, preview ' + preview.height + 'px');
	}
	if (print.dense !== preview.dense) {
		out.push('full-width day cards: print ' + print.dense + ', preview ' + preview.dense);
	}
	for (const k of ['head', 'notes', 'key', 'brand']) {
		if (print[k] !== preview[k]) {
			out.push(k + ': print «' + print[k] + '», preview «' + preview[k] + '»');
		}
	}
	if (print.boxes.length !== preview.boxes.length) {
		out.push('blocks: print ' + print.boxes.length + ', preview ' + preview.boxes.length);
	}
	const n = Math.min(print.boxes.length, preview.boxes.length);
	for (let i = 0; i < n; i++) {
		const a = print.boxes[i];
		const b = preview.boxes[i];
		const off = ['x', 'y', 'w', 'h'].filter((p) => Math.abs(a[p] - b[p]) > 2);
		if (off.length) {
			out.push(a.cls + ' #' + i + ': ' + off.map((p) => p + ' ' + a[p] + '→' + b[p]).join(', '));
		}
	}
	return out;
}

/* Runs in the print-emulated sheet: overflow, overlap and text checks. */
function inspectSheet(expectTexts) {
	const problems = { overflow: [], overlap: [], missing: [], rows: [] };
	const label = (el) => el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ').join('.') : '') +
		' «' + (el.textContent || '').trim().slice(0, 40) + '»';

	if (document.documentElement.scrollWidth > document.documentElement.clientWidth + 1) {
		problems.overflow.push('page scrolls sideways: ' + document.documentElement.scrollWidth + ' > ' + document.documentElement.clientWidth);
	}
	const boxes = document.querySelectorAll('.pd-print-document *');
	for (const el of boxes) {
		const cs = getComputedStyle(el);
		if (cs.display === 'inline' || cs.display === 'none' || el.clientWidth === 0) {
			continue;
		}
		if (el.scrollWidth > el.clientWidth + 1) {
			problems.overflow.push(label(el) + ': scrollWidth ' + el.scrollWidth + ' > ' + el.clientWidth);
		}
		if (cs.overflowY !== 'visible' && el.scrollHeight > el.clientHeight + 1) {
			problems.overflow.push(label(el) + ': scrollHeight ' + el.scrollHeight + ' > ' + el.clientHeight);
		}
	}
	/* Text must also stay inside its day card / box horizontally. */
	for (const card of document.querySelectorAll('.pd-day-card, .pd-med-key, .pd-info-box')) {
		const cr = card.getBoundingClientRect();
		const walker = document.createTreeWalker(card, NodeFilter.SHOW_TEXT);
		for (let n = walker.nextNode(); n; n = walker.nextNode()) {
			if (!n.textContent.trim()) {
				continue;
			}
			const range = document.createRange();
			range.selectNodeContents(n);
			for (const r of range.getClientRects()) {
				if (r.left < cr.left - 1 || r.right > cr.right + 1) {
					problems.overflow.push(label(card) + ': text «' + n.textContent.trim().slice(0, 30) + '» sticks out');
					break;
				}
			}
		}
	}

	/* Text blocks: every element's own text lines. */
	const blocks = [];
	const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
	for (let n = walker.nextNode(); n; n = walker.nextNode()) {
		if (!n.textContent.trim()) {
			continue;
		}
		const range = document.createRange();
		range.selectNodeContents(n);
		for (const r of range.getClientRects()) {
			if (r.width > 0.5 && r.height > 0.5) {
				blocks.push({ el: n.parentElement, node: n, l: r.left, r: r.right, t: r.top, b: r.bottom });
			}
		}
	}
	blocks.sort((a, b) => a.t - b.t);
	for (let i = 0; i < blocks.length; i++) {
		const a = blocks[i];
		for (let j = i + 1; j < blocks.length && blocks[j].t < a.b; j++) {
			const b = blocks[j];
			if (a.node === b.node) {
				continue;
			}
			const w = Math.min(a.r, b.r) - Math.max(a.l, b.l);
			const h = Math.min(a.b, b.b) - Math.max(a.t, b.t);
			if (w > 1 && h > 2) {
				problems.overlap.push('«' + a.node.textContent.trim().slice(0, 30) + '» overlaps «' + b.node.textContent.trim().slice(0, 30) + '» (' + Math.round(w) + '×' + Math.round(h) + 'px)');
			}
		}
	}

	const text = document.body.innerText.replace(/\s+/g, ' ');
	for (const t of expectTexts) {
		if (!text.includes(t.replace(/\s+/g, ' ').trim())) {
			problems.missing.push(t.slice(0, 60) + '…');
		}
	}

	const avoids = (el) => {
		const cs = getComputedStyle(el);
		return cs.breakInside === 'avoid' || cs.breakInside === 'avoid-page' || cs.pageBreakInside === 'avoid';
	};
	for (const row of document.querySelectorAll('.pd-day-row')) {
		problems.rows.push({
			h: row.getBoundingClientRect().height,
			avoid: avoids(row),
			dense: !!row.querySelector('.pd-day-card-dense')
		});
	}
	problems.cardRules = Array.from(document.querySelectorAll('.pd-day-card'), (c) => ({ h: c.getBoundingClientRect().height, avoid: avoids(c) }));
	problems.cards = document.querySelectorAll('.pd-day-card').length;
	problems.cardHeights = Array.from(document.querySelectorAll('.pd-day-card'), (c) => Math.round(c.getBoundingClientRect().height));
	return problems;
}

/* Runs in the sheet: invisible markers at the top and bottom of every card
   (absolutely positioned: the layout does not move). */
function markCards() {
	const style = document.createElement('style');
	style.textContent = '.pd-day-card{position:relative}.pd-vt-mark{position:absolute;left:2px;font-size:3px;line-height:3px;color:#fff;white-space:nowrap}';
	document.head.appendChild(style);
	document.querySelectorAll('.pd-day-card').forEach((card, i) => {
		const s = document.createElement('span');
		s.className = 'pd-vt-mark';
		s.style.top = '1px';
		s.textContent = 'QQS' + i + 'QQ';
		const e = s.cloneNode();
		e.style.top = '';
		e.style.bottom = '1px';
		e.textContent = 'QQE' + i + 'QQ';
		card.appendChild(s);
		card.appendChild(e);
	});
	return document.querySelectorAll('.pd-day-card').length;
}

/* ---- helpers --------------------------------------------------------- */

/* PD_VISUAL_SHOTS: the preview as the popup shows it, then the preview's
   sheet unscaled next to the printed sheet, and their pixel diff. */
async function saveShots(id, sheet, diff) {
	fs.mkdirSync(SHOTS, { recursive: true });
	await builder.locator('#pd-preview-area').screenshot({ path: path.join(SHOTS, id + '-popup.png') });
	/* Unscaled and unclipped: a popup wide enough for the whole paper. */
	const viewport = builder.viewportSize();
	const tall = await builder.evaluate(() => {
		const style = document.createElement('style');
		style.id = 'pd-vt-unclip';
		style.textContent = '#plandose-modal{width:1000px!important;max-height:none!important;overflow:visible!important}' +
			'#plandose-overlay{position:absolute!important;align-items:flex-start!important}.plandose-actionbar{display:none!important}' +
			'#pd-preview-area{max-height:none!important;overflow:visible!important}#pd-preview-area .pd-preview-sheet{transform:none!important;padding:0!important;width:190mm!important}' +
			'#pd-preview-area .pd-preview-page{width:auto!important;height:auto!important;margin:0!important}';
		document.head.appendChild(style);
		return Math.ceil(document.documentElement.scrollHeight);
	});
	await builder.setViewportSize({ width: 1280, height: Math.min(tall + 100, 16000) });
	const pv = path.join(SHOTS, id + '-preview.png');
	const pr = path.join(SHOTS, id + '-print.png');
	await builder.locator('#pd-preview-area .pd-print-document').screenshot({ path: pv });
	await builder.evaluate(() => document.getElementById('pd-vt-unclip').remove());
	await builder.setViewportSize(viewport);
	await sheet.locator('.pd-print-document').screenshot({ path: pr });
	/* The preview's sheet is 190mm (718.1px), the print viewport 718px,
	   and the two pages antialias text differently (grey vs subpixel):
	   compare in grey, at the best of a ±1px shift, over the common area. */
	const a0 = PNG.sync.read(fs.readFileSync(pv));
	const b0 = PNG.sync.read(fs.readFileSync(pr));
	const cw = Math.min(a0.width, b0.width) - 2;
	const ch = Math.min(a0.height, b0.height) - 2;
	const grey = (png, dx, dy) => {
		const out = new PNG({ width: cw, height: ch });
		for (let y = 0; y < ch; y++) {
			for (let x = 0; x < cw; x++) {
				const i = ((y + dy) * png.width + x + dx) * 4;
				const o = (y * cw + x) * 4;
				const g = Math.round(0.299 * png.data[i] + 0.587 * png.data[i + 1] + 0.114 * png.data[i + 2]);
				out.data[o] = out.data[o + 1] = out.data[o + 2] = g;
				out.data[o + 3] = 255;
			}
		}
		return out;
	};
	const b = grey(b0, 1, 1);
	let best = null;
	for (const dy of [0, 1, 2]) {
		for (const dx of [0, 1, 2]) {
			const d = new PNG({ width: cw, height: ch });
			const px = pixelmatch(grey(a0, dx, dy).data, b.data, d.data, cw, ch, { threshold: 0.2 });
			if (!best || px < best.px) {
				best = { px, d, dx: dx - 1, dy: dy - 1 };
			}
		}
	}
	const px = best.px;
	fs.writeFileSync(path.join(SHOTS, id + '-diff.png'), PNG.sync.write(best.d));
	const w = Math.max(a0.width, b0.width);
	const h = Math.max(a0.height, b0.height);
	const side = new PNG({ width: w * 2 + 16, height: h });
	side.data.fill(0x99);
	PNG.bitblt(a0, side, 0, 0, a0.width, a0.height, 0, 0);
	PNG.bitblt(b0, side, 0, 0, b0.width, b0.height, w + 16, 0);
	fs.writeFileSync(path.join(SHOTS, id + '-side-by-side.png'), PNG.sync.write(side));
	fs.appendFileSync(path.join(SHOTS, 'report.txt'), id + ': preview ' + a0.width + '×' + a0.height + ', print ' + b0.width + '×' + b0.height +
		', ' + px + ' px differ (' + (100 * px / (cw * ch)).toFixed(3) + '% of ' + cw + '×' + ch + ', shift ' + best.dx + ',' + best.dy + ')' +
		', layout differences: ' + (diff.length ? '\n  ' + diff.slice(0, 40).join('\n  ') : 'none') + '\n');
}

function pdfPages(pdf) {
	const info = execFileSync('pdfinfo', [pdf], { encoding: 'utf8' });
	return parseInt((info.match(/Pages:\s+(\d+)/) || [])[1] || '0', 10);
}

function pageTexts(pdf) {
	const n = pdfPages(pdf);
	const out = [];
	for (let p = 1; p <= n; p++) {
		out.push(execFileSync('pdftotext', ['-f', String(p), '-l', String(p), pdf, '-'], { encoding: 'utf8' }).replace(/\s+/g, ''));
	}
	return out;
}

function toPngs(pdf, prefix) {
	for (const f of fs.readdirSync(OUT).filter((f) => f.startsWith(path.basename(prefix) + '-') && f.endsWith('.png'))) {
		fs.unlinkSync(path.join(OUT, f));
	}
	execFileSync('pdftoppm', ['-r', String(DPI), '-png', pdf, prefix]);
	return fs.readdirSync(OUT).filter((f) => f.startsWith(path.basename(prefix) + '-') && f.endsWith('.png')).sort()
		.map((f) => path.join(OUT, f));
}

/* page-01.png naming regardless of pdftoppm's zero padding. */
function pageName(id, i) {
	return id + '-' + String(i + 1).padStart(2, '0') + '.png';
}

function compare(actualFile, baselineFile, diffFile) {
	const a = PNG.sync.read(fs.readFileSync(actualFile));
	const b = PNG.sync.read(fs.readFileSync(baselineFile));
	if (a.width !== b.width || a.height !== b.height) {
		return { ratio: 1, why: 'size ' + a.width + '×' + a.height + ' vs ' + b.width + '×' + b.height };
	}
	const diff = new PNG({ width: a.width, height: a.height });
	const px = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 });
	const ratio = px / (a.width * a.height);
	if (px) {
		fs.writeFileSync(diffFile, PNG.sync.write(diff));
	}
	return { ratio, why: px + ' px differ' };
}

/* ---- the run --------------------------------------------------------- */

fs.mkdirSync(OUT, { recursive: true });
fs.mkdirSync(BASELINE, { recursive: true });

let browser;
let builder;
const dicts = dictionaries();
/* The printed footer (disclaimer, the two thanks lines), as a site that never
   edited them prints it: the dictionary values (Plandose_Settings defaults).
   Without the real dictionaries: the sheet shows the JS fallbacks, unchecked. */
const DICTS_REAL = Object.keys(dicts.el).length > 0;
const FOOTER_KEYS = ['disclaimer', 'thanksLine1', 'thanksLine2'];
const FOOTER = DICTS_REAL ? FOOTER_KEYS.map((k) => dicts.el[k] || '') : [];

test.before(async () => {
	assert.ok(JS_DIR, 'PlanDose assets/js not found (PLANDOSE_JS)');
	browser = await chromium.launch({ executablePath: env.chromiumPath() });
	const ctx = await browser.newContext({ locale: 'el-GR', timezoneId: 'Europe/Athens' });
	builder = await ctx.newPage();
	builder.on('pageerror', (e) => console.error('# page error: ' + e.message));
	await builder.clock.setFixedTime(NOW);
	/* The modal shell the plugin prints (render_modal()): without it the tool
	   stays inert. With the plugin's screen CSS: the preview is checked too. */
	const css = ['plandose-guest.css', 'plandose.css'].map((f) => fs.readFileSync(path.join(env.pluginDir(), 'assets', 'css', f), 'utf8')).join('\n');
	await builder.setContent('<!doctype html><html><head><style>' + css.replace(/<\/style/gi, '<\\/style') + '</style></head><body><button id="plandose-trigger"></button>' +
		'<div id="plandose-overlay" hidden><div id="plandose-modal"><button id="plandose-close"></button><div id="plandose-app"></div></div></div></body></html>');
	await builder.evaluate((cfg) => { window.PlandoseConfig = cfg; }, {
		isAllowed: true, isPro: '1', ajaxUrl: 'http://127.0.0.1:9/', nonce: 'n', maxDays: '90', maxFreeReprints: '2',
		i18n: dicts.el, i18nEn: dicts.en
	});
	for (const f of ORDER) {
		const file = path.join(JS_DIR, f);
		if (fs.existsSync(file)) {
			await builder.addScriptTag({ content: fs.readFileSync(file, 'utf8') + '\n//# sourceURL=' + f });
		}
	}
	assert.ok(await builder.evaluate(() => !!(window.__PlandoseNS && window.__PlandoseNS.buildPrintHtml)), 'PD.buildPrintHtml loaded');
	/* The popup open on its preview step, as the pharmacist sees it. */
	await builder.evaluate(() => {
		document.getElementById('plandose-overlay').hidden = false;
		window.__PlandoseNS.buildApp();
	});
});

test.after(async () => {
	if (browser) {
		await browser.close();
	}
});

for (const plan of VARIANTS) {
	test('A4: ' + plan.id, async (t) => {
		const built = await builder.evaluate(buildSheet, Object.assign({}, plan, { header: HEADER, calendarUrl: CALENDAR_URL }));
		if (built.skipped) {
			t.diagnostic(built.skipped + ' — those items left out');
		}
		const kept = plan.monthday && built.skipped ? plan.items.filter((it) => it.customMode !== 'monthday') : plan.items;
		const expectTexts = [plan.patient, HEADER.name].concat(kept.map((it) => it.name)).concat(kept.map((it) => it.notes).filter(Boolean)).concat(FOOTER.filter(Boolean));

		const ctx = await browser.newContext({ locale: 'el-GR', timezoneId: 'Europe/Athens', viewport: { width: PRINT_WIDTH, height: PRINT_HEIGHT } });
		const sheet = await ctx.newPage();
		await sheet.emulateMedia({ media: 'print' });
		await sheet.setContent(built.html, { waitUntil: 'load' });
		fs.writeFileSync(path.join(OUT, plan.id + '.html'), built.html);

		const pdf = path.join(OUT, plan.id + '.pdf');
		await sheet.pdf({ path: pdf, format: 'A4', printBackground: true, preferCSSPageSize: true });

		const r = await sheet.evaluate(inspectSheet, expectTexts);
		assert.ok(r.cards > 0, 'day cards rendered');
		if (plan.qr) {
			/* A plan too big for a readable QR prints without it (calendarLink()):
			   then the variant must say so, not silently lose the box. */
			if (plan.qrTooBig) {
				assert.ok(!built.hasQr && built.qrNotice, 'marked qrTooBig: no QR and the preview says why');
				t.diagnostic('too big for the calendar QR: printed without it');
			} else {
				assert.ok(built.hasQr, 'the calendar QR box is on the sheet (' + built.qrNotice + ')');
			}
		} else {
			assert.ok(!built.hasQr, 'no calendar QR box without a calendar URL');
		}

		await t.test('the on-screen preview is laid out like the printed sheet', async () => {
			const printed = await sheet.evaluate(sheetLayout);
			const shown = await builder.evaluate(sheetLayout);
			const diff = layoutDiff(printed, shown);
			if (SHOTS) {
				await saveShots(plan.id, sheet, diff);
			}
			assert.ok(built.previewSame, 'the preview shows the same sheet markup');
			assert.deepStrictEqual(diff.slice(0, 20), []);
		});

		await t.test('nothing overflows its box', () => {
			assert.deepStrictEqual(r.overflow, []);
		});
		await t.test('no text blocks overlap', () => {
			assert.deepStrictEqual(r.overlap.slice(0, 20), []);
		});
		await t.test('every name, note and footer text is there in full', () => {
			/* An empty dictionary value prints an empty footer: that is missing too. */
			assert.deepStrictEqual(FOOTER_KEYS.filter((k, i) => DICTS_REAL && !FOOTER[i]).map((k) => 'footer text «' + k + '» is empty'), []);
			assert.deepStrictEqual(r.missing, []);
		});
		await t.test('no day card split across pages', async () => {
			if (HAS_POPPLER) {
				const n = await sheet.evaluate(markCards);
				const marked = path.join(OUT, plan.id + '.marked.pdf');
				await sheet.pdf({ path: marked, format: 'A4', printBackground: true, preferCSSPageSize: true });
				const pages = pageTexts(marked);
				const split = [];
				for (let i = 0; i < n; i++) {
					const s = pages.findIndex((p) => p.includes('QQS' + i + 'QQ'));
					const e = pages.findIndex((p) => p.includes('QQE' + i + 'QQ'));
					if (s < 0 || e < 0 || s !== e) {
						const h = r.cardHeights[i];
						split.push('card ' + (i + 1) + ': top on page ' + (s + 1) + ', bottom on page ' + (e + 1) +
							(h > PRINT_HEIGHT ? ' (taller than a page: ' + h + 'px > ' + PRINT_HEIGHT + 'px)' : ' (' + h + 'px, fits on a page)'));
					}
				}
				assert.deepStrictEqual(split, []);
				t.diagnostic(n + ' cards on ' + pages.length + ' pages');
			} else {
				/* Not authoritative (the pdftotext path above is): the print CSS
				   rule itself. Every card is break-inside: avoid and fits on a
				   page. A row is kept whole too, except a row with a full-width
				   (dense) card: it may break between its cards
				   (.pd-day-row:has(.pd-day-card-dense), print-styles.js). */
				const bad = [];
				r.cardRules.forEach((c, i) => {
					if (!c.avoid) {
						bad.push('card ' + (i + 1) + ' is not break-inside: avoid');
					}
					if (c.h >= PRINT_HEIGHT) {
						bad.push('card ' + (i + 1) + ' is taller than a page: ' + Math.round(c.h) + 'px');
					}
				});
				r.rows.forEach((row, i) => {
					if (row.dense) {
						return;
					}
					if (!row.avoid) {
						bad.push('row ' + (i + 1) + ' is not break-inside: avoid');
					}
					if (row.h >= PRINT_HEIGHT) {
						bad.push('row ' + (i + 1) + ' is taller than a page: ' + Math.round(row.h) + 'px');
					}
				});
				assert.deepStrictEqual(bad.slice(0, 20), []);
				t.diagnostic('pdftotext not installed: checked the print CSS rule only');
			}
		});
		await t.test('matches the baseline', { skip: !PIXELS ? 'PD_VISUAL_PIXELS=0' : (HAS_POPPLER ? false : 'pdftoppm not installed (poppler-utils)') }, () => {
			const pngs = toPngs(pdf, path.join(OUT, plan.id + '-p'));
			const bad = [];
			/* A plan too big for the QR prints exactly as without it: checked
			   against that plan's baseline, no copy of its own. */
			const baseId = plan.qr && plan.qrTooBig ? plan.plan : plan.id;
			/* Exact names: «long-names-01.png», never «long-names-qr-01.png». */
			const mine = new RegExp('^' + baseId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '-\\d+\\.png$');
			const existing = fs.readdirSync(BASELINE).filter((f) => mine.test(f));
			if (UPDATE) {
				if (baseId !== plan.id) {
					t.diagnostic('uses the baseline of ' + baseId);
					return;
				}
				existing.forEach((f) => fs.unlinkSync(path.join(BASELINE, f)));
				pngs.forEach((f, i) => fs.copyFileSync(f, path.join(BASELINE, pageName(plan.id, i))));
				t.diagnostic('baseline updated: ' + pngs.length + ' page(s)');
				return;
			}
			if (!existing.length) {
				assert.fail('no baseline for ' + baseId + ' — run: npm run test:visual -- --update');
			}
			if (existing.length !== pngs.length) {
				bad.push('pages: ' + pngs.length + ', baseline: ' + existing.length);
			}
			pngs.forEach((f, i) => {
				const base = path.join(BASELINE, pageName(baseId, i));
				if (!fs.existsSync(base)) {
					return;
				}
				const c = compare(f, base, path.join(OUT, plan.id + '-diff-' + (i + 1) + '.png'));
				if (c.ratio > TOLERANCE) {
					bad.push('page ' + (i + 1) + ': ' + (c.ratio * 100).toFixed(3) + '% differs (' + c.why + '), see out/' + plan.id + '-diff-' + (i + 1) + '.png');
				}
			});
			assert.deepStrictEqual(bad, []);
		});
		await ctx.close();
	});
}
