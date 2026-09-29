/* Visual aid, no assertions: the Pro labels the prescription import would
   produce for every sample in rx-samples/, with the plugin's real label
   code, in Chromium (Pro user). Output: PNG pages in OUT. ONLY=01,02 limits
   the samples. TEST-ONLY. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, openTool } from '../../lib/wp-browser.mjs';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const TESTS = path.join(HERE, '..', '..');
const DEFAULT_OUT = path.join(TESTS, 'visual', 'out', 'tools');
const OUT = process.env.OUT || DEFAULT_OUT;
fs.mkdirSync(OUT, { recursive: true });
const DIR = path.join(TESTS, 'rx-samples');
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const samples = fs.readdirSync(DIR).filter((f) => f.endsWith('.txt') && (!ONLY.length || ONLY.some((p) => f.startsWith(p)))).sort()
	.map((f) => ({ file: f, text: fs.readFileSync(path.join(DIR, f), 'utf8') }));

const browser = await launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1.5 });
await openTool(page, 'pro', '#pd-dose-unit');

const groups = [];
for (let g = 0; g < samples.length; g += 6) {
	groups.push([g, Math.min(g + 6, samples.length)]);
}
let n = 0;
for (const [from, to] of groups) {
	await page.evaluate(({ list, from }) => {
		const PD = window.__PlandoseNS;
		PD.s.header = { name: 'ΦΑΡΜΑΚΕΙΟ ΔΟΚΙΜΗΣ', phone_1: '2841000000' };
		const esc = PD.escapeHtml;
		const unitName = (u) => (u ? PD.unitLabel(u) : '—');
		const freqName = (it) => (it.freq ? PD.getFreqLabel(it) : '—');
		let html = '<style>' + PD.labelTextCss('#pd-preview-area ') + `
			body{margin:0;background:#f3f5f4;font-family:Arial,Helvetica,sans-serif}
			#rx-report{padding:24px 28px}
			.rx{background:#fff;border:1px solid #d9dfdc;border-radius:10px;padding:14px 16px;margin:0 0 18px}
			.rx h2{font-size:15px;margin:0 0 8px;color:#16453a}
			.rx table{border-collapse:collapse;font-size:12px;margin:0 0 10px;width:100%}
			.rx td,.rx th{border-bottom:1px solid #eef1ef;padding:3px 6px;text-align:left;vertical-align:top}
			.rx th{color:#6a756f;font-weight:600}
			.rx .src{color:#6a756f}
			.rx .warn{color:#8a5a00}
			.rx .bad{color:#b00020;font-weight:700}
			.rx .grid{display:flex;flex-wrap:wrap;gap:10px}
			.pd-lbl-card{background:#fff;border:1px dashed #9aa5a0;box-shadow:0 1px 2px rgba(0,0,0,.06)}
		</style><div id="rx-report"><div id="pd-preview-area">`;
		list.forEach((s, i) => {
			const r = PD.parsePrescription(s.text);
			PD.s.items = r.items.map((it) => Object.assign({}, it));
			const rows = r.items.map((it) => '<tr><td><b>' + esc(it.name) + '</b><div class="src">Συνταγή: ' + esc(it.source) + '</div></td>' +
				'<td>' + esc(PD.formatDose ? PD.formatDose(it.doseAmount) : it.doseAmount) + ' ' + esc(unitName(it.doseUnit)) + '</td>' +
				'<td>' + esc(freqName(it)) + '</td><td>' + esc(it.days) + '</td>' +
				'<td>' + it.warnings.map((w) => '<div class="' + (/methotrexate/.test(w) ? 'bad' : 'warn') + '">⚠︎ ' + esc(PD.prescriptionWarningText(w)) + '</div>').join('') + '</td></tr>').join('');
			const cards = PD.s.items.map((it) => '<div class="pd-lbl-card" style="width:100mm;min-height:47mm"><div class="pd-lbl" style="' + PD.labelBoxCss() + '">' + PD.labelInnerHtml(it, r.patient) + '</div></div>').join('');
			html += '<section class="rx"><h2>Συνταγή ' + (from + i + 1) + ' — ' + esc(s.file.replace(/\.txt$/, '')) + ' · Ασθενής: ' + esc(r.patient) + '</h2>' +
				'<table><tr><th>Φάρμακο</th><th>Δόση</th><th>Συχνότητα</th><th>Ημέρες</th><th>Έλεγχος</th></tr>' + rows + '</table>' +
				'<div class="grid">' + cards + '</div></section>';
		});
		html += '</div></div>';
		document.body.innerHTML = html;
		PD.s.items = [];
	}, { list: samples.slice(from, to), from });
	n++;
	const file = path.join(OUT, `rx-labels-preview${ONLY.length ? '-new' : ''}-${n}.png`);
	await page.screenshot({ path: file, fullPage: true });
	console.log('wrote ' + file);
}
await browser.close();
