/* Dose units («Είδος») in a real browser with the real PHP dictionaries,
   as the Pro pharmacy (PD_PRO_USER). Run: npm run test:browser. TEST-ONLY. */
import { launch, openTool, checker } from '../lib/wp-browser.mjs';
const browser = await launch();
const page = await browser.newPage();
const { check, done } = checker();
await openTool(page, 'pro', '#pd-dose-unit');

const opts = await page.$$eval('#pd-dose-unit option', (os) => os.map((o) => o.textContent.trim()));
console.log('   dropdown: ' + opts.join(' | '));
check(opts.includes('Ψεκασμός(οι)') && !opts.includes('Ψεκασμός(οί)'), 'Ψεκασμός(οι)');
for (const u of ['Υπόθετο(α)', 'Έμπλαστρο(α)', 'Ένεση(εις)', 'Μονάδες (IU)']) {
	check(opts.includes(u), 'dropdown has ' + u);
}
check(opts.indexOf('Μονάδες (IU)') < opts.indexOf('ml'), 'new units before ml/mg');

const res = await page.evaluate(() => {
	const PD = window.__PlandoseNS;
	/* Number and unit are joined by a no-break space (U+00A0) on every label. */
	const la = (u, n) => {
		const t = PD.labelAmount({ doseUnit: u, doseAmount: n });
		return /^[\d,.]+\u00a0\S/.test(t) ? t.replace('\u00a0', ' ') : 'NO-NBSP:' + t;
	};
	const out = { el: {}, en: {}, dose: {}, a4: PD.doseLabel({ doseUnit: 'iu', doseAmount: 12 }) };
	for (const u of ['suppository', 'patch', 'injection', 'iu', 'spray']) {
		out.el[u] = [la(u, 1), la(u, 2)];
	}
	PD.setLang('en');
	for (const u of ['suppository', 'patch', 'injection', 'iu']) {
		out.en[u] = [la(u, 1), la(u, 2)];
	}
	PD.setLang('el');
	for (const [u, ok, bad] of [['suppository', '1', '4'], ['patch', '2', '5'], ['injection', '1', '4'], ['iu', '150', '250']]) {
		out.dose[u] = [PD.checkDoseAmount(ok, u).error, PD.checkDoseAmount(bad, u).error];
	}
	return out;
});
const want = {
	suppository: ['1 Υπόθετο', '2 Υπόθετα'],
	patch: ['1 Έμπλαστρο', '2 Έμπλαστρα'],
	injection: ['1 Ένεση', '2 Ενέσεις'],
	iu: ['1 Μονάδα IU', '2 Μονάδες IU'],
	spray: ['1 Ψεκασμός', '2 Ψεκασμοί']
};
for (const [u, w] of Object.entries(want)) {
	check(JSON.stringify(res.el[u]) === JSON.stringify(w), 'label EL ' + u + ': ' + res.el[u].join(' / '));
}
const wantEn = { suppository: ['1 Suppository', '2 Suppositories'], patch: ['1 Patch', '2 Patches'], injection: ['1 Injection', '2 Injections'], iu: ['1 IU', '2 IU'] };
for (const [u, w] of Object.entries(wantEn)) {
	check(JSON.stringify(res.en[u]) === JSON.stringify(w), 'label EN ' + u + ': ' + res.en[u].join(' / '));
}
for (const [u, e] of Object.entries(res.dose)) {
	check(e[0] === '' && e[1] === 'max', 'ceiling ' + u + ': ' + JSON.stringify(e));
}
check(res.a4 === '12 Μονάδες (IU)', 'A4 plan dose: ' + res.a4);
await browser.close();
done();
