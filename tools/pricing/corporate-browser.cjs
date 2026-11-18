// Render the validated real directory snapshot without repeating its upstream request.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer } = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/taxonomy-20261118/corporate-pricing');
const data = JSON.parse(fs.readFileSync(path.join(root, 'corporate-validated.json')));
const allowedPhotos = new Set(data.sellers.map(s => s.photo).filter(Boolean));
(async () => {
    const server = await startServer(path.join(root, 'corporate-browser.log'), true);
    const browser = await chromium.launch({executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true,
        args: ['--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows', '--disable-features=CalculateNativeWinOcclusion']});
    const report = {directorySource: 'single-validated-real-response', upstreamDirectoryRequests: 0, metricRequests: 0, blocked: 0, photoResponses: [], screenshots: [], pageErrors: []};
    try {
        const c = await browser.newContext({viewport: {width: 1440, height: 980}});
        await c.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.origin === server.origin) {
                if (url.pathname === '/contactos-corporativos') return route.fulfill({json: data});
                if (url.pathname === '/contactos-corporativos/clic') { report.metricRequests++; return route.abort(); }
                return route.continue();
            }
            if (allowedPhotos.has(url.href)) return route.continue();
            report.blocked++; return route.abort();
        });
        const page = await c.newPage(); page.on('pageerror', e => report.pageErrors.push(e.message));
        page.on('response', r => { if (allowedPhotos.has(r.url())) report.photoResponses.push({status: r.status(), image: true}); });
        assert.equal((await page.goto(server.origin + '/catalogo')).status(), 200);
        await page.locator('[data-contacts-open]').click(); await page.locator('.corporate-list li').first().waitFor();
        await page.evaluate(() => document.fonts.ready);
        await page.waitForFunction(() => [...document.querySelectorAll('.corporate-list img')].every(i => i.complete));
        assert.equal(await page.locator('.corporate-list li').count(), data.sellers.length);
        report.photosRendered = await page.locator('.corporate-list img').evaluateAll(images => images.filter(i => i.naturalWidth > 0).length);
        report.sellersRendered = await page.locator('.corporate-list strong').allTextContents();
        assert.deepEqual(report.sellersRendered, data.sellers.map(s => s.name));
        assert.equal(await page.locator('.corporate-list a').count(), data.sellers.filter(s => s.url).length);
        for (const width of [1440, 820, 390]) {
            await page.setViewportSize({width, height: 980}); await page.waitForTimeout(300);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            assert.equal(await page.locator('dialog[open], [aria-modal="true"]:not([hidden])').count(), 0);
            const file = `corporate-real-${width}.png`; await page.screenshot({path: path.join(root, file)}); report.screenshots.push(file);
        }
        await page.keyboard.press('Escape'); assert(await page.locator('[data-contacts]').isHidden());
        assert.equal(report.metricRequests, 0); assert.equal(report.blocked, 0); assert.equal(report.pageErrors.length, 0);
        report.success = true;
    } catch (error) { report.success = false; report.errorType = error.name; process.exitCode = 1; }
    finally { fs.writeFileSync(path.join(root, 'corporate-browser.json'), JSON.stringify(report, null, 2)); await browser.close(); await server.stop(); }
    console.log(JSON.stringify({success: report.success, photographs: report.photosRendered, widths: [1440, 820, 390], metrics: report.metricRequests, additionalDirectoryRequests: report.upstreamDirectoryRequests}));
})();
