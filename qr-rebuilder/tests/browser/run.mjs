// QR ReBuilder Pro 2.15.7 — browser regression tests for assets/js/qrrp-app.js.
// Loads fixture.html (rendered by render.php from the real shortcode) at
// http://qrrp.test/tool/ with every request answered by Playwright route():
// plugin assets from disk, admin-ajax.php from per-test mocks.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PDIR = path.resolve(process.env.PDIR || path.join(HERE, '../../qr-rebuilder-pro'));
const FIXTURE = path.join(HERE, 'fixture.html');
const ORIGIN = 'http://qrrp.test';
const PLUGIN_PREFIX = ORIGIN + '/wp-content/plugins/qr-rebuilder-pro/';
const AJAX = ORIGIN + '/wp-admin/admin-ajax.php';
const PAGE_NONCE = '0123abcd89';
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
const GS = '\x1D';

let failures = 0;
let passes = 0;
function check(name, ok, detail) {
  if (ok) { passes++; console.log('PASS  ' + name); }
  else { failures++; console.log('FAIL  ' + name + (detail !== undefined ? '  -> ' + JSON.stringify(detail) : '')); }
}

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const json = (obj, status = 200) => ({ status, body: JSON.stringify(obj), contentType: 'application/json; charset=UTF-8' });
const text = (body, status = 200) => ({ status, body, contentType: 'text/html; charset=UTF-8' });

function fieldsFor(n = 1) {
  return { PC: '0800654071810' + n, SN: 'SN000' + n, LOT: 'LOT' + n, EXP: '2028-03-31' };
}
function parseOk(fields, extra = {}) {
  return json({ success: true, data: Object.assign({
    fields, warnings: [], requires_confirmation: false, confidence: 'high', server_today: '2026-09-29',
  }, extra) });
}
// Mirrors the server: raw is built from exactly the POSTed fields (canonicalRaw in the JS).
function rebuildOkFor(p, extra = {}) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(p.get('exp') || '');
  const yymmdd = m ? m[1].slice(-2) + m[2] + m[3] : '';
  const raw = '01' + p.get('pc') + '17' + yymmdd + '10' + p.get('lot') + GS + '21' + p.get('sn');
  return json({ success: true, data: Object.assign({
    raw, png: PNG, validated_output: 'proof-' + p.get('sn'), provenance: 'scan', changed_fields: [], server_today: '2026-09-29',
  }, extra) });
}

// ---------------------------------------------------------------- harness
async function newTool(browser, opts = {}) {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  const t = { page, context, requests: [], handlers: {}, errors: [] };
  page.on('pageerror', (e) => t.errors.push(String(e)));

  await context.route(ORIGIN + '/**', async (route) => {
    const req = route.request();
    const url = req.url();
    if (url === ORIGIN + '/tool/') {
      let body = fs.readFileSync(FIXTURE, 'utf8');
      // 2.16.0: optional server-resolved email-link prefill (QRRP.prefill), as the shortcode emits it.
      if (opts.prefill) body = body.replace(';</script>\n<script src="', ';window.QRRP.prefill=' + JSON.stringify(opts.prefill) + ';</script>\n<script src="');
      return route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body });
    }
    if (url.startsWith(PLUGIN_PREFIX)) {
      const rel = url.slice(PLUGIN_PREFIX.length).split('?')[0];
      const file = path.join(PDIR, rel);
      if (!file.startsWith(PDIR) || !fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
      const type = rel.endsWith('.css') ? 'text/css' : rel.endsWith('.js') ? 'application/javascript' : 'application/octet-stream';
      return route.fulfill({ status: 200, contentType: type, body: fs.readFileSync(file) });
    }
    if (url.startsWith(AJAX) && req.method() === 'POST') {
      const params = new URLSearchParams(req.postData() || '');
      const action = params.get('action');
      const entry = { action, params, index: t.requests.length };
      t.requests.push(entry);
      const count = t.requests.filter((r) => r.action === action).length;
      const handler = t.handlers[action];
      if (!handler) return route.fulfill(text('0', 400));
      const res = await handler(params, count);
      try { await route.fulfill(res); } catch (e) { /* page closed */ }
      return;
    }
    return route.fulfill({ status: 404, body: '' });
  });

  await page.goto(ORIGIN + '/tool/');
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'qrrp-hw-input');
  return t;
}

const state = (page) => page.evaluate(() => {
  const $ = (id) => document.getElementById(id);
  return {
    outputHidden: $('qrrp-output-panel').hidden,
    resultsHidden: $('qrrp-results-panel').hidden,
    printDisabled: $('qrrp-print-qr').disabled,
    downloadDisabled: $('qrrp-download-qr').disabled,
    copyDisabled: $('qrrp-copy-raw').disabled,
    status: $('qrrp-status').textContent,
    statusIsError: $('qrrp-status').classList.contains('qrrp-status-error'),
    statusLive: $('qrrp-status-live').textContent,
    alertLive: $('qrrp-alert-live').textContent,
    active: document.activeElement ? document.activeElement.id : null,
    exp: $('qrrp-field-exp').value,
    warnings: $('qrrp-warnings').hidden ? '' : $('qrrp-warnings').textContent,
    outputText: $('qrrp-output-panel').textContent,
    summaryExp: $('qrrp-summary-exp').textContent,
  };
});

// Types a scan into the scanner field as fast keystrokes (digits only: Playwright's
// keyboard.type() does not hold Shift for capitals, and the JS reads case from shiftKey).
async function scan(page, digits, { enter = true, tab = false } = {}) {
  await page.keyboard.type(digits);
  if (enter) await page.keyboard.press('Enter');
  if (tab) await page.keyboard.press('Tab');
}

async function waitRequests(t, action, n) {
  const start = Date.now();
  while (t.requests.filter((r) => r.action === action).length < n) {
    if (Date.now() - start > 5000) throw new Error('timeout waiting for ' + n + 'x ' + action);
    await sleep(10);
  }
}

async function scanAndBuild(t, n = 1, rebuildExtra = {}) {
  const f = fieldsFor(n);
  t.handlers.qrrp_parse = async () => parseOk(f);
  t.handlers.qrrp_rebuild = async (p) => rebuildOkFor(p, rebuildExtra);
  await t.page.focus('#qrrp-hw-input');
  await scan(t.page, '01' + f.PC + '17280331');
  await t.page.waitForSelector('#qrrp-results-panel:not([hidden])');
  await t.page.click('#qrrp-regenerate');
  await t.page.waitForSelector('#qrrp-output-panel:not([hidden])');
  await t.page.waitForFunction(() => !document.getElementById('qrrp-print-qr').disabled);
  return f;
}

// ---------------------------------------------------------------- tests
const tests = {};

tests['a. Fix 1 stale label'] = async (browser) => {
  for (const variant of ['http500', 'success_false']) {
    const t = await newTool(browser);
    const { page } = t;
    await scanAndBuild(t, 1);
    let s = await state(page);
    check(`[a/${variant}] baseline: output visible, print/download/copy enabled`,
      !s.outputHidden && !s.printDisabled && !s.downloadDisabled && !s.copyDisabled, s);

    const gate = deferred();
    t.handlers.qrrp_parse = async () => {
      await gate.promise;
      return variant === 'http500'
        ? text('<html><body>Internal Server Error</body></html>', 500)
        : json({ success: false, data: { code: 'parse_error', message: 'PARSE-FAIL-MSG' } }, 400);
    };
    await page.focus('#qrrp-hw-input');
    await scan(page, '0108006540718109172803311099');
    await waitRequests(t, 'qrrp_parse', 2);
    s = await state(page);
    check(`[a/${variant}] while second parse pending: output hidden`, s.outputHidden, s);
    check(`[a/${variant}] while pending: print/download/copy disabled`, s.printDisabled && s.downloadDisabled && s.copyDisabled, s);
    check(`[a/${variant}] while pending: send-email disabled`, await page.$eval('#qrrp-send-email', (b) => b.disabled));
    check(`[a/${variant}] while pending: results panel hidden`, s.resultsHidden, s);

    gate.resolve();
    await page.waitForFunction(() => document.getElementById('qrrp-status').classList.contains('qrrp-status-error'));
    s = await state(page);
    check(`[a/${variant}] after failure: output hidden, buttons disabled, results hidden`,
      s.outputHidden && s.printDisabled && s.downloadDisabled && s.copyDisabled && s.resultsHidden, s);
    check(`[a/${variant}] after failure: error message shown`,
      variant === 'http500' ? /HTTP 500/.test(s.status) : s.status === 'PARSE-FAIL-MSG', s.status);
    check(`[a/${variant}] no JS errors`, t.errors.length === 0, t.errors);
    await t.context.close();
  }
};

tests['b. Fix 8 nonce refresh'] = async (browser) => {
  const staleText = (await (async () => {
    const q = JSON.parse(/window\.QRRP = (.*);<\/script>/.exec(fs.readFileSync(FIXTURE, 'utf8'))[1]);
    return q.i18n.staleNonce;
  })());

  // b1/b2: stale nonce (JSON invalid_nonce or bare "-1") -> refresh -> retry with new nonce.
  for (const variant of ['json_invalid_nonce', 'bare_minus_1']) {
    const t = await newTool(browser);
    const { page } = t;
    const f = fieldsFor(1);
    t.handlers.qrrp_parse = async (p, n) => {
      if (n === 1) {
        return variant === 'bare_minus_1' ? text('-1', 403)
          : json({ success: false, data: { code: 'invalid_nonce', message: 'nonce expired' } }, 403);
      }
      return p.get('nonce') === 'abcdef1234' ? parseOk(f)
        : json({ success: false, data: { code: 'invalid_nonce', message: 'still stale' } }, 403);
    };
    t.handlers.qrrp_refresh_nonce = async () => json({ success: true, data: { nonce: 'abcdef1234' } });
    t.handlers.qrrp_rebuild = async (p) => rebuildOkFor(p);
    await scan(page, '0108006540718101172803311001');
    await page.waitForSelector('#qrrp-results-panel:not([hidden])', { timeout: 5000 }).catch(() => {});
    const seq = t.requests.map((r) => r.action + ':' + r.params.get('nonce'));
    check(`[b/${variant}] sequence parse(old) -> refresh -> parse(new)`,
      JSON.stringify(seq) === JSON.stringify([
        'qrrp_parse:' + PAGE_NONCE, 'qrrp_refresh_nonce:' + PAGE_NONCE, 'qrrp_parse:abcdef1234']), seq);
    const retry = t.requests[2];
    check(`[b/${variant}] retried parse carries same raw`, retry && retry.params.get('raw') === t.requests[0].params.get('raw'));
    const s = await state(page);
    check(`[b/${variant}] results shown after retry`, !s.resultsHidden && s.statusIsError === false, s);
    // Later requests keep using the refreshed nonce.
    if (!s.resultsHidden) {
      await page.click('#qrrp-regenerate');
      await page.waitForSelector('#qrrp-output-panel:not([hidden])', { timeout: 5000 }).catch(() => {});
    }
    const rb = t.requests.find((r) => r.action === 'qrrp_rebuild');
    check(`[b/${variant}] subsequent rebuild uses refreshed nonce`, rb && rb.params.get('nonce') === 'abcdef1234', rb && rb.params.get('nonce'));
    await t.context.close();
  }

  // b3/b4: refresh itself fails -> stale nonce message, exactly 2 requests, no loop.
  for (const variant of ['json_invalid_nonce+refresh_fails', 'bare_minus_1+refresh_fails', 'json_invalid_nonce+refresh_minus_1']) {
    const t = await newTool(browser);
    const { page } = t;
    t.handlers.qrrp_parse = async () => variant.startsWith('bare') ? text('-1', 403)
      : json({ success: false, data: { code: 'invalid_nonce', message: 'nonce expired' } }, 403);
    t.handlers.qrrp_refresh_nonce = async () => variant.endsWith('minus_1') ? text('-1', 403)
      : json({ success: false, data: { code: 'rate_limited', message: 'no' } }, 429);
    await scan(page, '0108006540718101172803311001');
    await page.waitForFunction(() => document.getElementById('qrrp-status').textContent !== '');
    await sleep(600);
    const s = await state(page);
    const seq = t.requests.map((r) => r.action);
    check(`[b/${variant}] stale-nonce message shown as error`, s.status === staleText && s.statusIsError, s.status);
    check(`[b/${variant}] exactly parse+refresh, no loop`, JSON.stringify(seq) === '["qrrp_parse","qrrp_refresh_nonce"]', seq);
    check(`[b/${variant}] results stay hidden`, s.resultsHidden, s);
    await t.context.close();
  }

  // b5: retry is also rejected -> only one refresh round, stale message.
  {
    const t = await newTool(browser);
    const { page } = t;
    t.handlers.qrrp_parse = async () => json({ success: false, data: { code: 'invalid_nonce', message: 'nonce expired' } }, 403);
    t.handlers.qrrp_refresh_nonce = async () => json({ success: true, data: { nonce: 'abcdef1234' } });
    await scan(page, '0108006540718101172803311001');
    await page.waitForFunction(() => document.getElementById('qrrp-status').textContent !== '');
    await sleep(600);
    const s = await state(page);
    const seq = t.requests.map((r) => r.action);
    check('[b/retry_also_stale] parse,refresh,parse then stop', JSON.stringify(seq) === '["qrrp_parse","qrrp_refresh_nonce","qrrp_parse"]', seq);
    check('[b/retry_also_stale] stale-nonce message shown', s.status === staleText, s.status);
    await t.context.close();
  }
};

tests['c. Fix 9 scanner Tab suffix'] = async (browser) => {
  const t = await newTool(browser);
  const { page } = t;
  const f = fieldsFor(1);
  const gate = deferred();
  t.handlers.qrrp_parse = async () => { await gate.promise; return parseOk(f); };

  // c1: Enter + Tab suffix right after the burst; checked BEFORE the parse returns
  // (the parse's finally also refocuses the scanner, which would mask a regression).
  await page.focus('#qrrp-hw-input');
  await scan(page, '0108006540718101172803311001', { enter: true, tab: true });
  await waitRequests(t, 'qrrp_parse', 1);
  let s = await state(page);
  check('[c] Enter+Tab suffix: focus stays on #qrrp-hw-input (parse still pending)', s.active === 'qrrp-hw-input', s.active);
  gate.resolve();
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  s = await state(page);
  check('[c] after parse: focus on #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);

  // c2: human Tab, empty buffer, > 300 ms after the last character -> normal focus move.
  await sleep(350);
  await page.keyboard.press('Tab');
  s = await state(page);
  check('[c] human Tab >300ms later moves focus away', s.active !== 'qrrp-hw-input' && s.active !== null, s.active);
  check('[c] human Tab sent no request', t.requests.length === 1, t.requests.length);

  // c3: parse completes while the user is in another input -> focus returns to scanner.
  // The results panel (customer name) is hidden during any parse, so the other input
  // used is the paste textarea #qrrp-manual-input, which stays visible.
  await page.click('#qrrp-manual-toggle'); // results panel visible -> toggles paste area only
  await page.waitForSelector('#qrrp-manual-entry:not([hidden])');
  const gate2 = deferred();
  t.handlers.qrrp_parse = async () => { await gate2.promise; return parseOk(fieldsFor(2)); };
  await page.fill('#qrrp-manual-input', '0108006540718102172803311002');
  await page.click('#qrrp-manual-submit');
  await waitRequests(t, 'qrrp_parse', 2);
  await page.focus('#qrrp-manual-input');
  await page.keyboard.type('xyz');
  s = await state(page);
  check('[c] during parse user is in another input', s.active === 'qrrp-manual-input', s.active);
  gate2.resolve();
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'qrrp-hw-input', null, { timeout: 2000 }).catch(() => {});
  s = await state(page);
  check('[c] after parse completes focus returns to #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);

  // c4: a failed parse (paste path) also returns focus to the scanner field.
  const gate3 = deferred();
  t.handlers.qrrp_parse = async () => { await gate3.promise; return json({ success: false, data: { message: 'bad' } }, 400); };
  await page.fill('#qrrp-manual-input', '0108006540718103172803311003');
  await page.click('#qrrp-manual-submit');
  await waitRequests(t, 'qrrp_parse', 3);
  await page.focus('#qrrp-manual-input');
  gate3.resolve();
  await page.waitForFunction(() => document.getElementById('qrrp-status').textContent === 'bad');
  s = await state(page);
  check('[c] after failed parse focus returns to #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);
  check('[c] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
};

tests['d. Fix 10 a11y live regions'] = async (browser) => {
  const t = await newTool(browser);
  const { page } = t;
  const info = await page.evaluate(() => {
    const d = (id) => {
      const el = document.getElementById(id);
      if (!el) return null;
      return { role: el.getAttribute('role'), live: el.getAttribute('aria-live'), ariaHidden: el.getAttribute('aria-hidden'),
        display: getComputedStyle(el).display, visibility: getComputedStyle(el).visibility, text: el.textContent, hidden: el.hidden };
    };
    return { status: d('qrrp-status'), live: d('qrrp-status-live'), alert: d('qrrp-alert-live') };
  });
  check('[d] #qrrp-status-live exists with role=status', info.live && info.live.role === 'status', info.live);
  check('[d] #qrrp-alert-live exists with role=alert', info.alert && info.alert.role === 'alert', info.alert);
  check('[d] live regions empty and not display:none / hidden',
    info.live && info.alert && info.live.text === '' && info.alert.text === '' &&
    info.live.display !== 'none' && info.alert.display !== 'none' && !info.live.hidden && !info.alert.hidden &&
    info.live.visibility !== 'hidden' && info.alert.visibility !== 'hidden', info);
  check('[d] #qrrp-status has aria-hidden=true', info.status && info.status.ariaHidden === 'true', info.status);

  // Error -> alert region only.
  t.handlers.qrrp_parse = async () => json({ success: false, data: { message: 'ERR-XYZ' } }, 400);
  await scan(page, '0108006540718101172803311001');
  await page.waitForFunction(() => document.getElementById('qrrp-alert-live').textContent !== '').catch(() => {});
  let s = await state(page);
  check('[d] error message goes to #qrrp-alert-live', s.alertLive === 'ERR-XYZ' && s.statusLive === '', s);

  // Normal status -> status region only (and the alert region is cleared).
  const f = fieldsFor(1);
  t.handlers.qrrp_parse = async () => parseOk(f);
  t.handlers.qrrp_rebuild = async (p) => rebuildOkFor(p);
  await page.focus('#qrrp-hw-input');
  await scan(page, '0108006540718101172803311001');
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  await page.click('#qrrp-regenerate');
  await page.waitForSelector('#qrrp-output-panel:not([hidden])');
  await page.waitForFunction(() => document.getElementById('qrrp-status-live').textContent !== '').catch(() => {});
  s = await state(page);
  check('[d] normal status goes to #qrrp-status-live', s.statusLive === s.status && s.status !== '' && s.alertLive === '', s);
  const disp = await page.evaluate(() => [getComputedStyle(document.getElementById('qrrp-status-live')).display,
    getComputedStyle(document.getElementById('qrrp-alert-live')).display]);
  check('[d] live regions still rendered (not display:none) after use', disp.every((d) => d !== 'none'), disp);
  await t.context.close();
};

tests['e. Fix 6 DD=00 UI'] = async (browser) => {
  const t = await newTool(browser);
  const { page } = t;
  const f = { PC: '08006540718101', SN: 'SN0001', LOT: 'LOT1', EXP: '2028-02-00' };
  t.handlers.qrrp_parse = async () => parseOk(f);
  t.handlers.qrrp_rebuild = async (p) => rebuildOkFor(p);
  await scan(page, '0108006540718101172802001001');
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  let s = await state(page);
  check('[e] date input shows 2028-02-29', s.exp === '2028-02-29', s.exp);
  check('[e] ΗΗ=00 warning shown', /ΗΗ=00/.test(s.warnings), s.warnings);
  check('[e] no "expiry not readable" warning', !/δεν μπόρεσε να συμπληρωθεί/.test(s.warnings), s.warnings);

  await page.click('#qrrp-regenerate');
  await page.waitForSelector('#qrrp-output-panel:not([hidden])', { timeout: 5000 }).catch(() => {});
  let rb = t.requests.filter((r) => r.action === 'qrrp_rebuild');
  check('[e] qrrp_rebuild POST sends exp=2028-02-00', rb[0] && rb[0].params.get('exp') === '2028-02-00', rb[0] && rb[0].params.get('exp'));
  s = await state(page);
  check('[e] output accepted (server raw 17280200 matches canonical)', !s.outputHidden && !s.printDisabled, s);
  check('[e] summary shows month/year without day', /^02\/2028 \(/.test(s.summaryExp), s.summaryExp);

  await page.fill('#qrrp-field-exp', '2028-02-15');
  s = await state(page);
  check('[e] editing the date invalidates the output', s.outputHidden && s.printDisabled, s);
  await page.click('#qrrp-regenerate');
  await page.waitForSelector('#qrrp-output-panel:not([hidden])', { timeout: 5000 }).catch(() => {});
  rb = t.requests.filter((r) => r.action === 'qrrp_rebuild');
  check('[e] after user edit POST sends exp=2028-02-15', rb[1] && rb[1].params.get('exp') === '2028-02-15', rb[1] && rb[1].params.get('exp'));

  // Setting the date back to the end of month re-selects DD=00 (the field cannot tell them apart).
  await page.fill('#qrrp-field-exp', '2028-02-29');
  await page.click('#qrrp-regenerate');
  await page.waitForFunction(() => !document.getElementById('qrrp-output-panel').hidden, null, { timeout: 5000 }).catch(() => {});
  rb = t.requests.filter((r) => r.action === 'qrrp_rebuild');
  console.log('      info: after re-entering 2028-02-29 the POST sends exp=' + (rb[2] && rb[2].params.get('exp')));
  check('[e] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
};

tests['f. 2.16.0: no provenance note on screen or on the printed label'] = async (browser) => {
  const t = await newTool(browser);
  await scanAndBuild(t, 1, { provenance: 'user_declared' });
  const s = await state(t.page);
  check('[f] no «Δηλωμένο» note in the output panel', !/Δηλωμένο|Χειροκίνητη αλλαγή|Μη επαληθευμένη/.test(s.outputText), s.outputText);
  const [popup] = await Promise.all([t.page.waitForEvent('popup'), t.page.click('#qrrp-print-qr')]);
  await popup.waitForSelector('.qrrp-print-qr');
  const html = await popup.content();
  check('[f] printed label has no provenance block', !/qrrp-print-provenance|Δηλωμένο/.test(html));
  // 2.16.0: html/body carry height, so height:100% binds the label to one page.
  const pdf = await popup.pdf({ width: '62mm', height: '29mm', printBackground: true });
  const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
  check('[f] one label prints on exactly one page (62x29 mm)', pages === 1, pages);
  check('[f] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
};

tests['h. 2.16.0: a new scan does not spend the email link token'] = async (browser) => {
  const token = 'a'.repeat(32);
  const f0 = fieldsFor(1);
  const t = await newTool(browser, { prefill: { pc: f0.PC, sn: f0.SN, lot: f0.LOT, exp: f0.EXP, token } });
  // Rebuild straight from the email link: the token is sent.
  t.handlers.qrrp_rebuild = async (p) => rebuildOkFor(p);
  await t.page.click('#qrrp-regenerate');
  await waitRequests(t, 'qrrp_rebuild', 1);
  check('[h] rebuild from the email link carries rebuild_token', t.requests.filter((r) => r.action === 'qrrp_rebuild')[0].params.get('rebuild_token') === token);

  // Fresh tool from the same link, but the user scans a different pack first.
  const t2 = await newTool(browser, { prefill: { pc: f0.PC, sn: f0.SN, lot: f0.LOT, exp: f0.EXP, token } });
  await scanAndBuild(t2, 2);
  const rb = t2.requests.filter((r) => r.action === 'qrrp_rebuild');
  check('[h] rebuild of a newly scanned pack does not send the old link token', rb.length === 1 && rb[0].params.get('rebuild_token') === null, rb[0] && rb[0].params.get('rebuild_token'));
  check('[h] no JS errors', t.errors.length === 0 && t2.errors.length === 0, t.errors.concat(t2.errors));
  await t.context.close();
  await t2.context.close();
};

tests['g. 2.15.7: Greek passthrough, parse announcement, stale email status'] = async (browser) => {
  // Paste: Greek characters reach the server unchanged (the server recovers the layout and asks to confirm).
  let t = await newTool(browser);
  let { page } = t;
  t.handlers.qrrp_parse = async () => parseOk(fieldsFor(1), { requires_confirmation: true });
  await page.click('#qrrp-manual-toggle');
  await page.fill('#qrrp-manual-input', '0105012345678900' + '21ΑΒΣά9');
  await page.click('#qrrp-manual-submit');
  await waitRequests(t, 'qrrp_parse', 1);
  const sent = t.requests.find((r) => r.action === 'qrrp_parse').params.get('raw');
  check('[g] pasted Greek is sent to the server unchanged (no lossy Σ→S / ά→a in the browser)', sent === '010501234567890021ΑΒΣά9', sent);
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  await page.waitForFunction(() => document.getElementById('qrrp-status-live').textContent !== '').catch(() => {});
  let s = await state(page);
  check('[g] parse needing confirmation is announced in #qrrp-status-live', /επιβεβαίωση/.test(s.statusLive) && s.alertLive === '', s.statusLive);
  check('[g] focus still returns to #qrrp-hw-input for the next scan', s.active === 'qrrp-hw-input', s.active);
  await t.context.close();

  // Clean parse: plain "done" announcement.
  t = await newTool(browser);
  page = t.page;
  t.handlers.qrrp_parse = async () => parseOk(fieldsFor(1));
  await scan(page, '0108006540718101172803311001');
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  await page.waitForFunction(() => document.getElementById('qrrp-status-live').textContent !== '').catch(() => {});
  s = await state(page);
  check('[g] successful parse is announced in #qrrp-status-live', /ολοκληρώθηκε/.test(s.statusLive) && !/επιβεβαίωση/.test(s.statusLive), s.statusLive);
  await t.context.close();

  // Email answered after «Νέα σάρωση»: no «sent» on the reset tool, loader not left to the old request.
  t = await newTool(browser);
  page = t.page;
  const gate = deferred();
  await scanAndBuild(t, 1);
  t.handlers.qrrp_send_email = async () => { await gate.promise; return json({ success: true, data: {} }); };
  await page.fill('#qrrp-email-input', 'a@example.org');
  await page.click('#qrrp-send-email');
  await waitRequests(t, 'qrrp_send_email', 1);
  await page.click('#qrrp-rescan');
  gate.resolve();
  await sleep(300);
  s = await state(page);
  check('[g] email reply after «Νέα σάρωση» does not show «sent» on the reset tool', !/στάλθηκε/.test(s.status) && !/στάλθηκε/.test(s.statusLive), s);
  check('[g] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
};

// ---------------------------------------------------------------- main
const browser = await chromium.launch();
try {
  for (const [name, fn] of Object.entries(tests)) {
    console.log('\n== ' + name);
    try { await fn(browser); } catch (e) { check(name + ' (threw)', false, String(e && e.stack || e)); }
  }
} finally {
  await browser.close();
}
console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures ? 1 : 0);
