// Render an HTML page to PDF with no header/footer (the combined build stamps its own).
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const [name, out, landscape] = process.argv.slice(2);
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('file://' + path.join(__dirname, name + '.html'), { waitUntil: 'networkidle' });
  await page.pdf({ path: path.join(__dirname, 'build', out + '.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true, landscape: landscape === '1' });
  await browser.close();
  console.log('pdf', out);
})();
