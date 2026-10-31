// Boord Notes screens as "Plaashek". Server on :8020 with seed_notes.py loaded.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = 'http://localhost:8020';
const OUT = path.join(__dirname, 'shots-notes');
fs.mkdirSync(OUT, { recursive: true });
const RENAME = fs.readFileSync(path.join(__dirname, 'rename.js'), 'utf8');

async function shot(page, name, opts = {}) {
  await page.waitForTimeout(opts.wait || 1500);
  await page.evaluate(RENAME);
  await page.evaluate((full) => { if (full) document.querySelectorAll('.fixed, .sticky, nav').forEach(el => { el.style.position = 'static'; }); }, !!opts.full);
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: !!opts.full });
  console.log('shot', name);
}

(async () => {
  const browser = await chromium.launch({ args: ['--lang=en-GB'] });
  const phone = { width: 430, height: 932 };
  const ctx = await browser.newContext({ viewport: phone, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'en-GB', timezoneId: 'Africa/Johannesburg', colorScheme: 'light' });
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log('pageerror', e.message));
  await page.goto(BASE + '/app/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);
  const tab = async (t, w = 1800) => { await page.click(`.tab-btn[data-tab="${t}"]`); await page.waitForTimeout(w); };

  // Capture, mid-dictation.
  await page.fill('#entryTitle', 'Flush on 8a needs pruning');
  await page.selectOption('#entryBlockSelect', { label: 'Block 8a' }).catch(() => page.fill('#entryBlock', 'Block 8a'));
  await page.fill('#entryBody', 'Walked 8a this morning. Strong vegetative flush on the north rows, about 20 cm, coming through the fruit. If we leave it the fruit will stay small. Prune the flush out this week before the heat.');
  await page.evaluate(() => { addAction('Prune', { detail: 'flush on north rows, hand pruners', focus: false }); currentTags = ['Block Maintenance', 'Fruit Development & Ripening']; renderTagChips(); });
  await shot(page, 'notes-01-capture', { full: true });

  await tab('todo');
  await shot(page, 'notes-02-todo', { full: true });
  // The done sheet for the first open action.
  await page.click('#todoList [data-done] >> nth=0');
  await page.waitForTimeout(600);
  await page.fill('#doneNote', 'Hand pruned, north rows only').catch(() => {});
  await shot(page, 'notes-03-done-sheet', { wait: 600 });
  await page.click('#doneCancelBtn');

  await tab('dashboard');
  await shot(page, 'notes-04-dashboard', { full: true });

  await tab('entries');
  await shot(page, 'notes-05-notes', { full: true });
  // Block 8a plus Fertilise... use Block 9 + Scout for a history view.
  await page.selectOption('#blockFilter', { label: 'Block 9' });
  await page.waitForTimeout(800);
  await page.selectOption('#actionFilter', { label: 'Scout' });
  await shot(page, 'notes-06-history', { wait: 1500 });
  await page.selectOption('#blockFilter', { index: 0 }); await page.selectOption('#actionFilter', { index: 0 });
  await page.waitForTimeout(800);
  // Open the hail note (it has a photo).
  await page.click('#entriesList .row:has-text("Hail on 10")');
  await shot(page, 'notes-07-detail', { wait: 1800 });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);

  await tab('settings');
  await shot(page, 'notes-08-settings', { full: true });
  await ctx.close();
  await browser.close();
})();
