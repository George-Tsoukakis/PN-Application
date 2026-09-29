/* Visual aid, no assertions: screenshot of the «Επικόλληση συνταγής» review
   list with the safety notes (local test WordPress). OUT=<dir>. TEST-ONLY. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, openTool } from '../../lib/wp-browser.mjs';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const TESTS = path.join(HERE, '..', '..');
const DEFAULT_OUT = path.join(TESTS, 'visual', 'out', 'tools');

/* 1.25.0: paste into the drop zone the way a pharmacist does (click, then
   a real paste event carrying the text). */
async function pasteRx(page, text) {
	await page.click('#pd-rx-drop');
	await page.evaluate((t) => {
		const el = document.getElementById('pd-rx-drop');
		const dt = new DataTransfer();
		dt.setData('text/plain', t);
		el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
	}, text);
}
const OUT = process.env.OUT || DEFAULT_OUT;
fs.mkdirSync(OUT, { recursive: true });
const browser = await launch();
const page = await (await browser.newContext({ viewport: { width: 1000, height: 1800 } })).newPage();
await openTool(page, 'free', '#pd-rx-drop');
const H = 'ΕΠΩΝΥΜΟ : ΙΑΤΡΟΥ ΕΠΩΝΥΜΟ : ΑΣΘΕΝΟΥΣ\nΟΝΟΜΑ : ΓΙΑΤΡΟΣ ΟΝΟΜΑ : ΑΣΘΕΝΗΣ\nΜονάδος Αποζ. Ασφ. Ασφ/νου Ταμείου\n';
const P = '25% 1 6,00 6,00 6,00 0,00 1,50 4,50\n';
const rx = (d, x) => d + '\nΔΟΣΟΛΟΓΙΑ : ' + x + '\n' + P;
await pasteRx(page, H +
	rx('ZINADOL F.C.TAB 500MG/TAB BTx10 (Γενόσημο)', '1 1/2 ΔΙΣΚΙΑ ΕΠΙΚΑΛ x 2 φορές την ημέρα x 7 ημέρες') +
	rx('BESPAR TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες') +
	rx('OZEMPIC INJ.SOL 1MG/0,74ML', '1 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την εβδομάδα x 28 ημέρες') +
	rx('LYRICA CAPS 75MG/CAP BTx56', '75 MG x 2 φορές την ημέρα x 28 ημέρες') +
	rx('METHOTREXATE/EBEWE TAB 2,5MG/TAB BTx50', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 28 ημέρες') +
	rx('DEPON TAB 500MG/TAB BTx20', '1 - 2 ΔΙΣΚΙΑ x κάθε 6 ώρες επί πόνου x 5 ημέρες') +
	rx('LANTUS SOLOSTAR INJ.SOL 100U/ML BTx5 PENS', '14 ΕΝΕΣΗ ΔΙΑΛ ΦΥΣΙΓΓΕΣ x 1 φορά την ημέρα x 30 ημέρες') +
	rx('BESPAR TAB 10MG/TAB BTx30', '1 ΔΙΣΚΙΑ x 1 φορά την ημέρα x 30 ημέρες'));
const el = await page.$('#pd-rx');
await el.screenshot({ path: path.join(OUT, 'rx-review.png') });
console.log('wrote', path.join(OUT, 'rx-review.png'));
await browser.close();
