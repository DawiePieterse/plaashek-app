// Captures Boord screens as "Plaashek" with demo data. Run with the dev
// server on :8811 (backend/run_preview.py) and the demo seed loaded.
const { chromium } = require('playwright');
const path = require('path');
const BASE = 'http://localhost:8811';
const OUT = path.join(__dirname, 'shots');

const RENAME = `
(function(){
  const map = [[/Bekfontein Boerdery/g,'Plaashek'],[/Bekfontein/g,'Plaashek'],[/Laughing Waters/g,'Plaashek'],[/Boord/g,'Plaashek']];
  function fix(s){ return map.reduce((t,[re,to])=>t.replace(re,to), s); }
  function walk(root){
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let n; while((n = w.nextNode())){ const v = n.nodeValue; const f = fix(v); if (f !== v) n.nodeValue = f; }
    root.querySelectorAll('[placeholder],[title],[aria-label],input').forEach(el=>{
      for (const a of ['placeholder','title','aria-label']) { const v = el.getAttribute(a); if (v) { const f = fix(v); if (f!==v) el.setAttribute(a,f); } }
      if (el.type === 'text' && el.value) { const f = fix(el.value); if (f !== el.value) el.value = f; }
    });
  }
  window.__plaashek = () => { walk(document.body); document.title = fix(document.title); };
  window.__plaashek();
  if (!window.__plaashekObs) { window.__plaashekObs = new MutationObserver(() => window.__plaashek()); window.__plaashekObs.observe(document.body, {subtree:true, childList:true, characterData:true}); }
})();`;

async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait || 1200);
  await page.evaluate(RENAME);
  await page.evaluate((full) => {
    document.querySelectorAll('div, p, span, td').forEach(el => {
      if (el.children.length === 0 && /not found on this server|No Packhouse data/i.test(el.textContent)) {
        const card = el.closest('.bg-white.rounded-xl, .bg-white.rounded-lg, .kpi, [class*="rounded"]');
        (card && card.querySelectorAll('*').length < 12 ? card : el).remove();
      }
    });
    if (full) document.querySelectorAll('.sticky, .admin-tabs').forEach(el => { el.style.position = 'static'; });
    // Chromium renders date inputs in US order whatever the locale; show them dd/mm/yyyy.
    document.querySelectorAll('input[type="date"]').forEach(inp => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(inp.value || '');
      if (m) { inp.type = 'text'; inp.value = `${m[3]}/${m[2]}/${m[1]}`; }
    });
    // The Server card is about this dev checkout, not the product.
    document.querySelectorAll('#tab-settings .font-semibold, #tab-settings .font-bold').forEach(h => {
      if (h.textContent.trim() === 'Server') { const card = h.closest('.bg-white'); if (card) card.remove(); }
    });
  }, !!opts.full);
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: !!opts.full });
  console.log('shot', name);
}

async function open(browser, device, url, viewport, mobile) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: !!mobile, hasTouch: !!mobile, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
  if (device) await ctx.addInitScript((id) => { localStorage.setItem('boord_device_id', id); }, device);
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log('pageerror', url, e.message));
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  return { ctx, page };
}

// Field app: pick a worker and type a weight through the app's own code
// paths, so the crate goes into IndexedDB and syncs like a real one.
async function fieldCrate(page, workerId, weight) {
  await page.evaluate((id) => { const s = document.getElementById('workerSelect'); s.value = id; updateWorkerDisplay(); }, workerId);
  for (const ch of String(weight)) await page.click(`.keypad-btn[data-k="${ch}"]`);
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=en-GB'] });
  const tablet = { width: 820, height: 1180 };
  const desktop = { width: 1440, height: 900 };
  const which = process.argv[2] || 'all';
  const want = (k) => which === 'all' || which === k;

  if (want('setup')) {
    const { ctx, page } = await open(browser, null, '/', tablet, true);
    await shot(page, 'setup-01-device');
    await ctx.close();
  }

  if (want('field')) {
    const { ctx, page } = await open(browser, 'device-03', '/field/', tablet, true);
    // A few crates so the load card, recent list and elapsed clock are live.
    const crates = [['002', '12.4'], ['004', '11.8'], ['001', '13.1'], ['006', '12.0'], ['003', '12.7']];
    for (const [w, kg] of crates) {
      await fieldCrate(page, w, kg);
      await page.click('#saveCrateBtn');
      await page.waitForTimeout(400);
    }
    await page.waitForTimeout(1500);
    // Leave the screen mid-capture: worker scanned, weight typed, not yet saved.
    await fieldCrate(page, '005', '12.9');
    await shot(page, 'field-01-capture');
    // The send-slip dialog.
    await page.click('#sendSlipBtn');
    await shot(page, 'field-02-send-slip', { wait: 800 });
    await ctx.close();
  }

  if (want('packhouse')) {
    const { ctx, page } = await open(browser, 'device-06', '/packhouse/', tablet, true);
    await shot(page, 'packhouse-01-queue');
    await page.click('.lot-item >> nth=0');
    await page.waitForTimeout(500);
    await page.fill('#actualCrates', '14'); await page.fill('#receivedBy', 'Thandi');
    await shot(page, 'packhouse-02-receive', { wait: 600 });
    await ctx.close();
  }

  if (want('admin')) {
    const { ctx, page } = await open(browser, 'admin-pc', '/admin/', desktop, false);
    // Dashboard: this week, with the Workers and Blocks lists open.
    await page.click('#tab-dashboard button:has-text("This Week")');
    await page.waitForTimeout(1200);
    await shot(page, 'admin-01-dashboard');
    await page.click('.collapsible-header[data-target="dash-workers-body"]');
    await page.click('.collapsible-header[data-target="dash-blocks-body"]');
    await page.waitForTimeout(400);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await shot(page, 'admin-02-dashboard-lists', { full: true });
    await page.evaluate(() => window.scrollTo(0, 0));

    // Payments: this week's wage run.
    await page.click('.tab-btn[data-tab="payments"]');
    await page.waitForTimeout(600);
    await page.click('#tab-payments button:has-text("This Week")');
    await page.waitForTimeout(600);
    await page.click('#calcPayBtn');
    await shot(page, 'admin-03-payments', { wait: 1500 });

    // Reports: the catalogue.
    await page.click('.tab-btn[data-tab="reports"]');
    await shot(page, 'admin-04-reports', { wait: 1200, full: true });

    // Planner: tick three blocks and their days, type what is available.
    await page.click('.tab-btn[data-tab="planner"]');
    await page.waitForTimeout(1500);
    await page.evaluate(() => { document.querySelectorAll('#tab-planner .text-amber-700, #tab-planner [class*="amber"]').forEach(el => { if (/not found/i.test(el.textContent)) el.remove(); }); });
    const plan = { '8a': ['4200', [0, 1, 2]], '8b': ['3100', [1, 2, 3]], '9': ['5000', [3, 4]] };
    for (const [bid, [kg, days]] of Object.entries(plan)) {
      await page.check(`input[data-plan-on="${bid}"]`);
      await page.waitForTimeout(200);
      await page.fill(`input[data-plan-avail="${bid}"]`, kg);
      await page.waitForTimeout(200);
      for (const d of days) { await page.check(`input[data-plan-day="${bid}:${d}"]`); await page.waitForTimeout(150); }
    }
    await shot(page, 'admin-05-planner', { wait: 1000, full: true });

    // Settings: master data.
    await page.click('.tab-btn[data-tab="settings"]');
    await page.waitForTimeout(800);
    await shot(page, 'admin-06-workers');
    await page.click('.subtab-btn[data-subtab="blocks"]');
    await shot(page, 'admin-07-blocks', { wait: 800 });
    await page.click('.subtab-btn[data-subtab="devices"]');
    await shot(page, 'admin-08-devices', { wait: 800 });
    await ctx.close();

    const b = await open(browser, 'admin-pc', '/admin/print-badge.html?ids=001,002,003,004', { width: 1100, height: 800 }, false);
    await shot(b.page, 'admin-09-badges', { wait: 1500 });
    await b.ctx.close();
  }
  await browser.close();
})();
