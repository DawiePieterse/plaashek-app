// Boord Labour screens as "Plaashek". Server on :8040 with seed_labour.py loaded.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = 'http://localhost:8040';
const OUT = path.join(__dirname, 'shots-labour');
fs.mkdirSync(OUT, { recursive: true });
const RENAME = fs.readFileSync(path.join(__dirname, 'rename.js'), 'utf8');

async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait || 1500);
  await page.evaluate(RENAME);
  await page.evaluate((full) => {
    document.querySelectorAll('input[type="date"], input#repStart, input#repEnd').forEach(inp => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(inp.value || '');
      if (m) { inp.type = 'text'; inp.value = `${m[3]}/${m[2]}/${m[1]}`; }
    });
    if (full) document.querySelectorAll('.fixed, .sticky').forEach(el => { if (!el.id.startsWith('result') && el.id !== 'result') el.style.position = 'static'; });
  }, !!opts.full);
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: !!opts.full });
  console.log('shot', name);
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=en-GB'] });
  const phone = { width: 430, height: 932 };
  const mk = async (url) => {
    const ctx = await browser.newContext({ viewport: phone, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
    await ctx.addInitScript(() => { localStorage.setItem('labour_device_label', 'Door 1'); });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', e.message));
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    return { ctx, page };
  };

  // The door phone.
  {
    const { ctx, page } = await mk('/');
    await shot(page, 'door-01-scan', { full: true });
    await page.evaluate(() => { showResult({ scan: { direction: 'in', timestamp: new Date().toISOString() }, worker: { name: 'Jan Botha', photo: '' } }); clearTimeout(resultTimer); });
    await shot(page, 'door-02-in', { wait: 600 });
    await page.evaluate(() => { showResult({ scan: { direction: 'out', timestamp: new Date().toISOString() }, worker: { name: 'Maria van Wyk', photo: '' } }); clearTimeout(resultTimer); });
    await shot(page, 'door-03-out', { wait: 600 });
    await page.evaluate(() => hideResult());
    await page.click('[data-nav="today"]');
    await shot(page, 'door-04-inside', { wait: 1000 });
    await ctx.close();
  }
  // The admin phone.
  {
    const { ctx, page } = await mk('/admin/');
    await shot(page, 'admin-00-pin', { wait: 800 });
    await page.fill('#pinInput', '1234');
    await page.click('#pinForm button');
    await page.waitForTimeout(2000);
    await shot(page, 'admin-01-today', { full: true });
    await page.click('[data-nav="fix"]');
    await shot(page, 'admin-02-fix', { full: true, wait: 2000 });
    await page.click('#exceptionList button:has-text("Add exit time")');
    await page.waitForTimeout(600);
    await page.fill('#scanFormReason', 'Forgot to scan out, left at 16:40 with the others');
    await page.evaluate(() => { const t = document.getElementById('scanFormTime'); t.value = '2026-10-28T16:40'; t.dispatchEvent(new Event('input', { bubbles: true })); t.dispatchEvent(new Event('change', { bubbles: true })); });
    await page.evaluate(() => { const t = document.getElementById('scanFormTime'); t.type = 'text'; t.value = '28/10/2026 16:40'; });
    await page.evaluate(() => { document.querySelector('#scanForm form').scrollIntoView(); });
    await shot(page, 'admin-02b-fix-form', { wait: 800 });
    await page.click('#scanFormCancel');
    await page.click('[data-nav="report"]');
    await page.waitForTimeout(800);
    await page.click('[data-preset="this"]');
    await page.waitForTimeout(400);
    await page.click('#repShow');
    await shot(page, 'admin-03-pay', { full: true, wait: 2500 });
    await page.click('[data-nav="settings"]');
    await shot(page, 'admin-04-settings', { full: true, wait: 1500 });
    await ctx.close();
  }
  await browser.close();
})();
