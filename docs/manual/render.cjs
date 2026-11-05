// Prints docs/manual/manual.html to docs/Trinix-Admission-User-Manual.pdf (A4, page numbers in the footer).
// Run: node docs/manual/render.cjs
const path = require('path');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage();
    await page.goto('file://' + path.join(__dirname, 'manual.html'), { waitUntil: 'networkidle' });
    await page.pdf({
        path: path.join(__dirname, '..', 'Trinix-Admission-User-Manual.pdf'),
        format: 'A4',
        printBackground: true,
        displayHeaderFooter: true,
        headerTemplate: '<div></div>',
        footerTemplate:
            '<div style="width:100%;font-size:8px;color:#64748b;padding:0 14mm;display:flex;justify-content:space-between;font-family:sans-serif">' +
            '<span>Trinix Admission · User manual</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>',
        margin: { top: '14mm', bottom: '16mm', left: '14mm', right: '14mm' },
    });
    await browser.close();
})();
