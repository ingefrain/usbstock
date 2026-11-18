const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL, fileURLToPath } = require('node:url');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.EDGE_EXECUTABLE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
    const report = [];
    try {
        const context = await browser.newContext();
        await context.route('**/*', route => route.request().url().startsWith('file:') ? route.continue() : route.abort());
        const page = await context.newPage();
        for (const [name, directory] of [['web', 'visual'], ['pdf', 'designs']]) {
            const url = pathToFileURL(path.resolve(`storage/app/private/qa/${directory}/index.html`)).href;
            for (const width of [1440, 820, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.goto(url, { waitUntil: 'load' });
                await page.evaluate(() => Promise.all([...document.images].filter(image => image.getBoundingClientRect().top < innerHeight).map(image => image.decode())));
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
                const links = await page.locator('a[href], img[src]').evaluateAll(elements => elements.map(element => element.href || element.src));
                for (const link of links) assert(fs.existsSync(fileURLToPath(new URL(link))), link);
                await page.screenshot({ path: `storage/app/private/qa/visual/gallery-${name}-${width}.png` });
                report.push({ name, width, links: links.length, all_files_exist: true, overflow: false });
            }
        }
        fs.writeFileSync('storage/app/private/qa/visual/galleries-verification.json', JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
