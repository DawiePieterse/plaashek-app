// Packhouse screens as "Plaashek". Server on :8060 with seed_packhouse.py loaded.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = 'http://localhost:8060';
const OUT = path.join(__dirname, 'shots-ph');

const RENAME = fs.readFileSync(path.join(__dirname, 'rename.js'), 'utf8');

async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait || 1200);
  await page.evaluate(RENAME);
  await page.evaluate((full) => {
    document.querySelectorAll('input[type="date"]').forEach(inp => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(inp.value || '');
      if (m) { inp.type = 'text'; inp.value = `${m[3]}/${m[2]}/${m[1]}`; }
    });
    if (full) document.querySelectorAll('.sticky, .fixed').forEach(el => { el.style.position = 'static'; });
  }, !!opts.full);
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: !!opts.full });
  console.log('shot', name);
}

async function open(browser, hash, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
  await ctx.addInitScript((o) => {
    localStorage.setItem('ph_device', 'tablet-1');
    if (o.operator) localStorage.setItem('ph_operator', JSON.stringify({ worker: { id: '003', name: 'Maria van Wyk' }, until: Date.now() + 15 * 60 * 1000 }));
  }, opts);
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log('pageerror', hash, e.message));
  await page.goto(BASE + '/' + hash, { waitUntil: 'networkidle' });
  return { ctx, page };
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=en-GB'] });
  const tablet = { width: 1180, height: 820 };   // landscape tablet on the floor
  const phone = { width: 430, height: 932 };     // phone for pallets, notes and juice
  const which = process.argv[2] || 'all';
  const want = (k) => which === 'all' || which === k;

  if (want('home')) {
    const { ctx, page } = await open(browser, '', tablet);
    await page.waitForTimeout(800);
    // Show every module the suite has, plus a tile for a farm's own process.
    await page.evaluate(() => {
      MODULES.forEach(m => { m.parked = false; });
      renderHome();
      document.getElementById('home').insertAdjacentHTML('beforeend', `
        <a href="#" class="bg-white rounded-2xl shadow p-4 flex flex-col items-center justify-center text-center min-h-[10rem] md:min-h-[12rem]" style="border: 2px dashed #94a3b8; box-shadow: none">
          <i class="fa-solid fa-plus text-5xl md:text-6xl" style="color: var(--boord-blue)"></i>
          <div class="font-bold text-lg md:text-xl mt-3 leading-tight">Your Process</div>
          <div class="text-xs text-slate-400 mt-1">Any pack-house step, added as a screen</div>
        </a>`);
    });
    await shot(page, 'home-01');
    await ctx.close();
    const s = await open(browser, '#sulfur', tablet);
    await shot(s.page, 'scan-01-badge');
    await s.ctx.close();
  }
  if (want('sulfur')) {
    const { ctx, page } = await open(browser, '#sulfur', tablet, { operator: true });
    await shot(page, 'sulfur-01-tents', { wait: 2000, full: true });
    await ctx.close();
  }
  if (want('acid')) {
    const { ctx, page } = await open(browser, '#acid', tablet, { operator: true });
    await shot(page, 'acid-01-baths', { wait: 2000, full: true });
    await ctx.close();
  }
  if (want('pallets')) {
    const { ctx, page } = await open(browser, '#palletstack', phone, { operator: true });
    await shot(page, 'pallet-01-stack', { wait: 2000, full: true });
    await ctx.close();
  }
  if (want('notes')) {
    const { ctx, page } = await open(browser, '#deliverynote', phone, { operator: true });
    await shot(page, 'note-01-list', { wait: 2000, full: true });
    await ctx.close();
  }
  if (want('juice')) {
    const { ctx, page } = await open(browser, '#juice', phone, { operator: true });
    await shot(page, 'juice-01-bins', { wait: 2000, full: true });
    await ctx.close();
  }

  if (want('forms')) {
    const p = await open(browser, '#palletstack', phone, { operator: true });
    await p.page.waitForTimeout(1500);
    await p.page.evaluate(() => { openPallet(null); });
    await p.page.waitForTimeout(400);
    await p.page.evaluate(() => {
      const bc = document.querySelector('#palletstack input[placeholder*="arcode"], #palletstack input[inputmode="text"], #palletstack input[type="text"]');
      if (bc) { bc.value = '(00)060099005963000111'; bc.dispatchEvent(new Event('input', { bubbles: true })); }
      const c = document.querySelector('#palletstack input[inputmode="numeric"]:not([value])');
    });
    await shot(p.page, 'pallet-02-form', { wait: 800, full: true });
    await p.ctx.close();

    const n = await open(browser, '#deliverynote', phone, { operator: true });
    await n.page.waitForTimeout(1500);
    await n.page.evaluate(() => { openNote(null); });
    await n.page.waitForTimeout(500);
    // Tick the two EU pallets so the market lock and totals show.
    await n.page.click('button[data-pick="pl-107"]');
    await n.page.waitForTimeout(300);
    await n.page.click('button[data-pick="pl-108"]');
    await n.page.waitForTimeout(300);
    await n.page.selectOption('#deliverynote select >> nth=0', { index: 1 }).catch(() => {});
    await n.page.selectOption('#deliverynote select >> nth=1', { index: 1 }).catch(() => {});
    await shot(n.page, 'note-02-form', { wait: 800, full: true });
    await n.ctx.close();

    const j = await open(browser, '#juice', phone, { operator: true });
    await j.page.waitForTimeout(1500);
    await j.page.evaluate(() => { openBin(null); });
    await shot(j.page, 'juice-02-form', { wait: 800, full: true });
    await j.ctx.close();
  }
  if (want('settings')) {
    const ctx = await browser.newContext({ viewport: tablet, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
    await ctx.addInitScript(() => { localStorage.setItem('ph_device', 'tablet-1'); });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror settings', e.message));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.goto(BASE + '/#settings', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await shot(page, 'settings-00-pin');
    for (const k of ['1', '2', '3', '4']) { await page.click(`#pin button[data-key="${k}"]`); await page.waitForTimeout(150); }
    await page.waitForTimeout(1200);
    for (const [id, name] of [['deliverynote', 'settings-01-deliverynote'], ['palletstack', 'settings-02-palletstack'], ['sulfur', 'settings-03-sulfur'], ['setup', 'settings-04-setup']]) {
      await page.evaluate((h) => { location.hash = h; }, '#settings/' + id);
      await page.waitForTimeout(800);
      await shot(page, name, { wait: 1500, full: true });
    }
    // The sulfur daily report table.
    await page.evaluate(() => { location.hash = '#settings/sulfur'; });
    await page.waitForTimeout(1200);
    const show = page.locator('[data-settings-page="sulfur"] button:has-text("Show")');
    await page.evaluate(() => {
      // Undo the dd/mm/yyyy display conversion from the earlier shot so the report query is valid.
      document.querySelectorAll('[data-settings-page="sulfur"] input[type="text"]').forEach(inp => {
        const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(inp.value || '');
        if (m) { inp.type = 'date'; inp.value = `${m[3]}-${m[2]}-${m[1]}`; }
      });
    });
    await show.scrollIntoViewIfNeeded();
    await show.click();
    await page.waitForTimeout(1500);
    await page.evaluate(() => { const h = [...document.querySelectorAll('[data-settings-page="sulfur"] *')].find(el => el.children.length === 0 && /Daily report/.test(el.textContent)); if (h) h.scrollIntoView(); window.scrollBy(0, -12); });
    await shot(page, 'settings-05-sulfur-report', { wait: 800 });
    await ctx.close();
  }
  await browser.close();
})();
