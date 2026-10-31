const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const browser = await chromium.launch();
  const name = process.argv[2] || 'overview'; const outName = process.argv[3] || 'Plaashek_Harvest_Overview'; const footer = process.argv[4] || 'Plaashek · Harvest';
  const page = await browser.newPage();
  await page.goto('file://' + path.join(__dirname, name + '.html'), { waitUntil: 'networkidle' });
  await page.pdf({ path: path.join(__dirname, outName + '.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true, landscape: name === 'ecosystem',
    displayHeaderFooter: true, headerTemplate: '<div></div>',
    footerTemplate: '<div style="width:100%;font-size:8px;color:#64748b;padding:0 14mm;display:flex;justify-content:space-between;font-family:Helvetica,Arial,sans-serif;"><span>' + footer + '</span><span class="pageNumber"></span></div>' });
  await browser.close();
  console.log('pdf written');
})();
