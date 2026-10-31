// Kudde screens as "Plaashek Kudde". Server on :8030 with seed_kudde.py loaded.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = 'http://localhost:8030';
const OUT = path.join(__dirname, 'shots-kudde');
fs.mkdirSync(OUT, { recursive: true });
const RENAME = fs.readFileSync(path.join(__dirname, 'rename.js'), 'utf8');

async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait || 1500);
  await page.evaluate(RENAME);
  await page.evaluate((full) => {
    // The module keeps its name; the header shows it under the suite's.
    document.querySelectorAll('div.font-bold').forEach(el => { if (el.childElementCount === 0 && el.textContent.trim() === 'Kudde') el.textContent = 'Plaashek Kudde'; });
    document.querySelectorAll('input').forEach(inp => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(inp.value || '');
      if (m) { inp.type = 'text'; inp.value = `${m[3]}/${m[2]}/${m[1]}`; }
    });
    if (full) document.querySelectorAll('.sticky').forEach(el => { el.style.position = 'static'; });
  }, !!opts.full);
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: !!opts.full });
  console.log('shot', name);
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=en-GB'] });
  const which = process.argv[2] || 'all';
  const want = (k) => which === 'all' || which === k;

  if (want('field')) {
    const ctx = await browser.newContext({ viewport: { width: 430, height: 932 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', e.message));
    await page.goto(BASE + '/field/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    await page.click('#animalList button:has-text("Rivierkamp")').catch(() => page.click('#animalList >> text=Rivierkamp'));
    await shot(page, 'field-01-herd', { full: true, wait: 1000 });
    await page.click('#animalList button:has-text("Rivierkamp")').catch(() => {});
    await page.click('#needsWeighingBtn');
    await shot(page, 'field-02-needs-weighing', { full: true, wait: 1000 });
    await page.click('#needsWeighingBtn');
    await page.waitForTimeout(500);
    await page.evaluate(() => openAnimalDetail('C7'));
    await shot(page, 'field-03-animal', { wait: 1500 });
    await page.click('#animalDetailModal .action-btn[data-kind="weight"]');
    await page.waitForTimeout(500);
    await page.fill('#eventValue', '438');
    await shot(page, 'field-04-weight', { wait: 800 });
    await page.click('#cancelEventBtn');
    await page.click('#animalDetailModal .action-btn[data-kind="treatment"]');
    await page.waitForTimeout(500);
    await page.fill('#eventProduct', 'Ivermectin'); await page.fill('#eventDose', '8 ml'); await page.fill('#eventWithdrawal', '28');
    await shot(page, 'field-05-treatment', { wait: 800 });
    await page.click('#cancelEventBtn');
    await page.click('#closeDetailBtn');
    await page.waitForTimeout(400);
    await page.click('#moveCampBtn');
    await page.waitForTimeout(600);
    await shot(page, 'field-06-move-pick', { wait: 600 });
    await page.click('#moveLocationsList button.move-location-btn:has-text("Dam camp")');
    await page.waitForTimeout(800);
    await page.evaluate(() => { const i = document.getElementById('moveToLocation'); i.value = 'Kraal'; i.dispatchEvent(new Event('input', { bubbles: true })); });
    await shot(page, 'field-07-move', { wait: 600 });
    await page.click('#backMoveBtn'); await page.click('#cancelMoveBtn');
    await page.click('#weighSessionBtn');
    await shot(page, 'field-08-weigh-session', { wait: 800 });
    await ctx.close();
  }
  if (want('admin')) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', e.message));
    await page.goto(BASE + '/admin/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    await shot(page, 'admin-01-dashboard', { full: true });
    await page.click('.tab-btn[data-tab="herd"]');
    await shot(page, 'admin-02-herd', { wait: 1500 });
    await page.click('#herdTableBody tr[data-tag="C7"]');
    await shot(page, 'admin-03-animal', { wait: 1500 });
    await page.click('#cancelAnimalBtn').catch(() => {});
    await page.click('.tab-btn[data-tab="settings"]');
    await shot(page, 'admin-04-settings', { wait: 2500, full: true });
    await ctx.close();
  }
  await browser.close();
})();
