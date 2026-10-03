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
async function newTool(browser) {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  const t = { page, context, requests: [], handlers: {}, errors: [] };
  page.on('pageerror', (e) => t.errors.push(String(e)));

  await context.route(ORIGIN + '/**', async (route) => {
    const req = route.request();
    const url = req.url();
    if (url === ORIGIN + '/tool/') {
      return route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: fs.readFileSync(FIXTURE) });
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
    // 2.16.0 αφαίρεσε το #qrrp-summary-provenance· null-safe ώστε το state() να μη σκάει.
    provenance: !$('qrrp-summary-provenance') || $('qrrp-summary-provenance').hidden ? null : $('qrrp-summary-provenance').textContent,
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

// 2.16.0 αφαίρεσε τη σημείωση «Δηλωμένο…» (CHANGELOG 2.16.0): η provenance του server δεν φαίνεται στη σύνοψη.
tests['f. user_declared provenance: no note (2.16.0)'] = async (browser) => {
  const t = await newTool(browser);
  await scanAndBuild(t, 1, { provenance: 'user_declared' });
  const s = await state(t.page);
  check('[f] output rendered for provenance user_declared', !s.outputHidden && !s.printDisabled, s);
  check('[f] no «Δηλωμένο» note anywhere in the tool (removed in 2.16.0)',
    s.provenance === null && !(await t.page.$eval('#qrrp-app', (el) => el.textContent.includes('Δηλωμένο'))), s.provenance);
  check('[f] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
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

// ---------------------------------------------------------------- 2.16.1
// Το window.open του εργαλείου τυλίγεται ώστε το print() του popup να είναι stub:
// mode 'afterprint' (επιστρέφει αμέσως, afterprint σε 30 ms), 'none' (χωρίς afterprint),
// 'block' (κρατά 600 ms σαν μπλοκαρισμένος διάλογος, χωρίς afterprint).
async function stubPrint(page, mode) {
  await page.evaluate((mode) => {
    window.__prints = 0;
    window.__popups = [];
    const orig = window.__origOpen || window.open;
    window.__origOpen = orig;
    window.open = function (...args) {
      const w = orig.apply(window, args);
      if (w) {
        w.print = function () {
          window.__prints++;
          if (mode === 'block') { const end = Date.now() + 600; while (Date.now() < end) { /* busy */ } }
          if (mode === 'afterprint') setTimeout(() => w.dispatchEvent(new Event('afterprint')), 30);
        };
        window.__popups.push(w);
      }
      return w;
    };
  }, mode);
}
const prints = (page) => page.evaluate(() => window.__prints);
const lastPopupClosed = (page) => page.evaluate(() => { const p = window.__popups[window.__popups.length - 1]; return !p || p.closed; });

tests['h. 2.16.1 Fix 1: scanner focus after output actions, burst never re-clicks Print'] = async (browser) => {
  const t = await newTool(browser);
  const { page } = t;
  await scanAndBuild(t, 1);
  let s = await state(page);
  check('[h] after successful rebuild focus returns to #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);

  await stubPrint(page, 'none');
  await page.click('#qrrp-print-qr');
  await page.waitForFunction(() => window.__prints === 1);
  s = await state(page);
  check('[h] after Print focus is on #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);

  // Η κατάσταση πριν από το fix: εστίαση στο κουμπί «Εκτύπωση» όταν φτάνει η επόμενη σάρωση.
  await page.focus('#qrrp-print-qr');
  await sleep(300);
  const f2 = fieldsFor(2);
  const gate = deferred();
  t.handlers.qrrp_parse = async () => { await gate.promise; return parseOk(f2); };
  const raw2 = '01' + f2.PC + '17280331';
  await scan(page, raw2);
  await waitRequests(t, 'qrrp_parse', 2).catch(() => {});
  await sleep(100);
  s = await state(page);
  check('[h] burst on focused Print: no reprint of the previous label', (await prints(page)) === 1, await prints(page));
  check('[h] burst routed into the scanner field (focus on #qrrp-hw-input)', s.active === 'qrrp-hw-input', s.active);
  const p2 = t.requests.filter((r) => r.action === 'qrrp_parse')[1];
  const sent = p2 ? p2.params.get('raw') : null;
  check('[h] the whole burst (first char included) reached the parser', sent === raw2, sent);
  check('[h] new-scan flow: old output hidden and Print disabled while parsing', s.outputHidden && s.printDisabled, s);
  gate.resolve();
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');

  // Γνήσιο πάτημα Enter / Space σε κουμπί (χωρίς προηγούμενη σάρωση) λειτουργεί.
  await page.click('#qrrp-regenerate');
  await page.waitForFunction(() => !document.getElementById('qrrp-print-qr').disabled);
  await page.focus('#qrrp-print-qr');
  await sleep(300);
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => window.__prints === 2, null, { timeout: 2000 }).catch(() => {});
  check('[h] genuine Enter on focused Print still prints', (await prints(page)) === 2, await prints(page));
  await page.focus('#qrrp-print-qr');
  await sleep(300);
  await page.keyboard.press('Space');
  await page.waitForFunction(() => window.__prints === 3, null, { timeout: 2000 }).catch(() => {});
  check('[h] genuine Space on focused Print still prints', (await prints(page)) === 3, await prints(page));

  // Αντιγραφή / λήψη: εστίαση πίσω στον σαρωτή.
  await page.click('#qrrp-copy-raw');
  await page.waitForFunction(() => /αντιγράφηκαν|απέτυχε/.test(document.getElementById('qrrp-status').textContent));
  s = await state(page);
  check('[h] after Copy focus is on #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);
  await page.click('#qrrp-download-qr');
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'qrrp-hw-input', null, { timeout: 2000 }).catch(() => {});
  s = await state(page);
  check('[h] after Download focus is on #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);

  // Δεν κλέβει την εστίαση από πεδίο όπου γράφει ο χρήστης· πληκτρολόγηση εκεί δεν πάει στον σαρωτή.
  await page.focus('#qrrp-customer-name');
  await page.keyboard.type('Maria');
  s = await state(page);
  check('[h] typing in customer name stays there', s.active === 'qrrp-customer-name' &&
    (await page.$eval('#qrrp-customer-name', (e) => e.value)) === 'Maria', s.active);

  // Email: η εστίαση γυρίζει μόνο αν έμεινε στο κουμπί.
  const eg = deferred();
  t.handlers.qrrp_send_email = async () => { await eg.promise; return json({ success: true, data: {} }); };
  await page.fill('#qrrp-email-input', 'a@example.org');
  await page.click('#qrrp-send-email');
  await waitRequests(t, 'qrrp_send_email', 1);
  eg.resolve();
  await page.waitForFunction(() => /στάλθηκε/.test(document.getElementById('qrrp-status').textContent));
  s = await state(page);
  check('[h] after email send (focus left on button) focus returns to #qrrp-hw-input', s.active === 'qrrp-hw-input', s.active);
  const eg2 = deferred();
  t.handlers.qrrp_send_email = async () => { await eg2.promise; return json({ success: true, data: {} }); };
  await page.click('#qrrp-send-email');
  await waitRequests(t, 'qrrp_send_email', 2);
  await page.focus('#qrrp-email-input');
  eg2.resolve();
  await sleep(200);
  s = await state(page);
  check('[h] email completion does not steal focus from the email input', s.active === 'qrrp-email-input', s.active);
  check('[h] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
};

tests['i. 2.16.1 Fix 2: print window closes after printing'] = async (browser) => {
  for (const mode of ['afterprint', 'block', 'none']) {
    const t = await newTool(browser);
    const { page } = t;
    await scanAndBuild(t, 1);
    await stubPrint(page, mode);
    await page.click('#qrrp-print-qr');
    await page.waitForFunction(() => window.__prints === 1);
    if (mode === 'none') {
      await sleep(150);
      check('[i/none] without afterprint the window is NOT closed early', !(await lastPopupClosed(page)));
      await page.evaluate(() => { document.getElementById('qrrp-print-qr').focus(); window.dispatchEvent(new Event('focus')); });
    }
    await page.waitForFunction(() => window.__popups[0].closed, null, { timeout: 2000 }).catch(() => {});
    check(`[i/${mode}] print window closed`, await lastPopupClosed(page));
    const s = await state(page);
    check(`[i/${mode}] focus back on #qrrp-hw-input`, s.active === 'qrrp-hw-input', s.active);
    check(`[i/${mode}] no JS errors`, t.errors.length === 0, t.errors);
    await t.context.close();
  }
};

tests['j. 2.16.1 Fix 3: email button state with overlapping sends'] = async (browser) => {
  const t = await newTool(browser);
  const { page } = t;
  await scanAndBuild(t, 1);
  const label = await page.$eval('#qrrp-send-email', (b) => b.textContent);
  const btn = () => page.$eval('#qrrp-send-email', (b) => ({ text: b.textContent, busy: b.getAttribute('aria-busy'), disabled: b.disabled }));
  const g1 = deferred();
  const g2 = deferred();
  t.handlers.qrrp_send_email = async (p, n) => { await (n === 1 ? g1 : g2).promise; return json({ success: true, data: {} }); };
  await page.fill('#qrrp-email-input', 'a@example.org');
  await page.click('#qrrp-send-email');
  await waitRequests(t, 'qrrp_send_email', 1);
  let b = await btn();
  check('[j] first send in flight: «Αποστολή…», aria-busy, disabled', b.busy === 'true' && b.disabled && b.text !== label, b);

  // Νέος κωδικός όσο εκκρεμεί η αποστολή.
  await page.click('#qrrp-regenerate');
  await waitRequests(t, 'qrrp_rebuild', 2);
  await page.waitForFunction(() => !document.getElementById('qrrp-send-email').disabled);
  b = await btn();
  check('[j] new code re-enables the button with its real label, no aria-busy', b.text === label && b.busy === null && !b.disabled, b);

  await page.click('#qrrp-send-email');
  await waitRequests(t, 'qrrp_send_email', 2);
  g1.resolve();
  await sleep(200);
  b = await btn();
  check('[j] old send completing does not reset the running second send', b.busy === 'true' && b.disabled && b.text !== label, b);
  g2.resolve();
  await page.waitForFunction(() => !document.getElementById('qrrp-send-email').hasAttribute('aria-busy'));
  b = await btn();
  check('[j] second send completing restores label and re-enables', b.text === label && b.busy === null && !b.disabled, b);
  check('[j] no JS errors', t.errors.length === 0, t.errors);
  await t.context.close();
};

tests['k. 2.16.1 Fixes 4-7: strings, a11y, CSS specificity'] = async (browser) => {
  const t = await newTool(browser);
  const { page } = t;
  const info = await page.evaluate(() => {
    const hw = document.getElementById('qrrp-hw-input');
    const hint = document.getElementById(hw.getAttribute('aria-describedby') || '-');
    return {
      warnRole: document.getElementById('qrrp-warnings').getAttribute('role'),
      hint: hint ? hint.className : null,
      emailSent: Object.prototype.hasOwnProperty.call(window.QRRP.i18n, 'emailSent'),
      fieldsChanged: window.QRRP.i18n.fieldsChanged,
      regenLabel: document.getElementById('qrrp-regenerate').textContent.trim(),
    };
  });
  check('[k] #qrrp-warnings has no role=alert', info.warnRole === null, info.warnRole);
  check('[k] scanner input aria-describedby -> .qrrp-hw-hint', info.hint === 'qrrp-hw-hint', info.hint);
  check('[k] dead i18n key emailSent removed', !info.emailSent);
  check('[k] fieldsChanged names the real button label', info.fieldsChanged.includes('«' + info.regenLabel + '»'), info);
  const js = fs.readFileSync(path.join(PDIR, 'assets/js/qrrp-app.js'), 'utf8');
  check('[k] JS fallback of fieldsChanged identical to the localized string', js.includes("t( 'fieldsChanged', '" + info.fieldsChanged + "' )"));

  // Προειδοποιήσεις: μία ανακοίνωση, στο #qrrp-status-live.
  t.handlers.qrrp_parse = async () => parseOk(fieldsFor(1), { warnings: ['WARN-ONE.'] });
  await scan(page, '0108006540718101172803311001');
  await page.waitForSelector('#qrrp-results-panel:not([hidden])');
  await page.waitForFunction(() => document.getElementById('qrrp-status-live').textContent !== '');
  const s = await state(page);
  check('[k] parse warnings announced via #qrrp-status-live, not the alert region', /WARN-ONE\./.test(s.statusLive) && s.alertLive === '', s);
  check('[k] warnings still visible in #qrrp-warnings', /WARN-ONE\./.test(s.warnings), s.warnings);

  // Theme rule τύπου .entry-content button δεν πατά τα κουμπιά του εργαλείου.
  const css = await page.evaluate(() => {
    const st = document.createElement('style');
    st.textContent = '.entry-content button, .entry-content a { background: rgb(255, 0, 0); color: rgb(0, 255, 0); border-radius: 0; }';
    document.head.appendChild(st);
    document.body.classList.add('entry-content');
    const cs = (id) => getComputedStyle(document.getElementById(id));
    return { primaryBg: cs('qrrp-regenerate').backgroundImage, primaryColor: cs('qrrp-regenerate').color,
      plainBg: cs('qrrp-rescan').backgroundColor, radius: cs('qrrp-rescan').borderTopLeftRadius };
  });
  check('[k] .qrrp-btn-primary keeps its gradient and white text under theme button rules',
    /gradient/.test(css.primaryBg) && css.primaryColor === 'rgb(255, 255, 255)', css);
  check('[k] .qrrp-btn keeps its background/radius under theme button rules', css.plainBg !== 'rgb(255, 0, 0)' && css.radius === '8px', css);
  check('[k] no JS errors', t.errors.length === 0, t.errors);
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
