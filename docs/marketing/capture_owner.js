// Boord Owner screens as "Plaashek". Server on :8010 with seed_owner.py loaded.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = 'http://localhost:8010';
const OUT = path.join(__dirname, 'shots-owner');
fs.mkdirSync(OUT, { recursive: true });
const RENAME = fs.readFileSync(path.join(__dirname, 'rename.js'), 'utf8');

async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait || 1500);
  await page.evaluate(RENAME);
  await page.evaluate((full) => {
    document.querySelectorAll('input[type="date"]').forEach(inp => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(inp.value || '');
      if (m) { inp.type = 'text'; inp.value = `${m[3]}/${m[2]}/${m[1]}`; }
    });
    if (full) document.querySelectorAll('.sticky, .owner-tabs, .admin-tabs').forEach(el => { el.style.position = 'static'; });
    // Notices about services this demo box cannot reach, and the optional AI key.
    document.querySelectorAll('div, p, span').forEach(el => {
      if (el.children.length <= 1 && /temporarily unavailable|Not set up on the farm server|forecast unavailable/i.test(el.textContent)) {
        const card = el.closest('.bg-white.rounded-xl');
        (card && /Ask AI/.test(card.textContent) ? card : el).remove();
      }
    });
  }, !!opts.full);
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: !!opts.full });
  console.log('shot', name);
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=en-GB'] });
  const which = process.argv[2] || 'all';
  const want = (k) => which === 'all' || which === k;
  const mk = async (viewport, mobile) => {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: !!mobile, hasTouch: !!mobile, locale: 'en-GB', timezoneId: 'Africa/Johannesburg' });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', e.message));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    return { ctx, page };
  };
  const tab = async (page, id) => { await page.click(`.tab-btn[data-tab="${id}"]`); await page.waitForTimeout(2500); };

  if (want('desktop')) {
    const { ctx, page } = await mk({ width: 1440, height: 900 }, false);
    await page.click('#dashSeasonBtn');
    await page.waitForTimeout(1500);
    await shot(page, 'owner-01-dashboard');
    await tab(page, 'analysis');
    await shot(page, 'owner-02-analysis', { full: true, wait: 2500 });
    await tab(page, 'estimate');
    await shot(page, 'owner-03-estimate', { full: true, wait: 2500 });
    await tab(page, 'weather');
    for (const y of ['2022', '2024']) { await page.click(`#weatherYearFilter label:has-text("${y}")`); await page.waitForTimeout(600); }
    await page.click('#weatherMetricFilter label:has-text("Precipitation")');
    await shot(page, 'owner-04-weather', { wait: 2500 });
    await tab(page, 'risk');
    await shot(page, 'owner-05-risk', { full: true, wait: 3000 });
    await tab(page, 'market');
    await shot(page, 'owner-06-markets', { full: true, wait: 2500 });
    await ctx.close();
  }
  if (want('phone')) {
    const { ctx, page } = await mk({ width: 430, height: 932 }, true);
    await page.click('#dashSeasonBtn');
    await shot(page, 'owner-07-phone-dashboard', { wait: 1500 });
    await tab(page, 'risk');
    await shot(page, 'owner-08-phone-risk', { wait: 2500 });
    await ctx.close();
  }
  await browser.close();
})();
