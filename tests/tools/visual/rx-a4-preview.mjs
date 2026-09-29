/* Visual aid, no assertions: the real A4 plan (PD.buildPrintHtml +
   PRINT_STYLES) for one pasted prescription, printed to PDF by Chromium
   from the local test WordPress. Automated A4 checks: tests/visual/.
   SAMPLE=<file in rx-samples> OUT=<file.pdf>. TEST-ONLY. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, openTool } from '../../lib/wp-browser.mjs';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const TESTS = path.join(HERE, '..', '..');
const DEFAULT_OUT = path.join(TESTS, 'visual', 'out', 'tools');
const SAMPLE = process.env.SAMPLE || '16-three-drugs-two-sprays.txt';
const OUT = process.env.OUT || path.join(DEFAULT_OUT, 'rx-a4-plan.pdf');
fs.mkdirSync(path.dirname(OUT), { recursive: true });
const text = fs.readFileSync(path.join(TESTS, 'rx-samples', SAMPLE), 'utf8');
const browser = await launch();
const page = await browser.newPage();
await openTool(page, 'free', '#pd-patient', 'attached');
const doc = await page.evaluate((text) => {
	const PD = window.__PlandoseNS;
	PD.s.header = { name: 'PharmacyNeeds', email: 'info@pharmacyneeds.gr' };
	const r = PD.parsePrescription(text);
	PD.s.items = r.items.map((it) => Object.assign({}, it));
	document.getElementById('pd-patient').value = r.patient;
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Πλάνο Δοσολογίας</title><style>' +
		PD.PRINT_STYLES + '</style></head><body>' + PD.buildPrintHtml() + '</body></html>';
}, text);
const p2 = await browser.newPage();
await p2.setContent(doc, { waitUntil: 'load' });
await p2.pdf({ path: OUT, format: 'A4', printBackground: true });
console.log('wrote ' + OUT);
await browser.close();
