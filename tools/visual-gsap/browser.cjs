// Real Edge/Laravel regression against the isolated snapshot; never development data.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer, privateRoot } = require('./server.cjs');
const out = path.join(privateRoot, 'browser');
fs.mkdirSync(out, { recursive: true });
const previousPath = path.join(out, 'report.json');
const previous = process.argv.includes('--resume') && fs.existsSync(previousPath) ? JSON.parse(fs.readFileSync(previousPath)) : null;
const report = previous || { widths: [1440, 1039, 820, 390], groups: [], screenshots: [], metrics: [], errors: [] };
delete report.failure;
report.ok = false;
if (process.argv.includes('--recapture')) {
    report.groups = report.groups.filter(name => !name.startsWith('responsive-'));
    report.metrics = [];
}
const instrument = () => {
    window.visualMetrics = { cls: 0, lcp: 0, motion: [] };
    new PerformanceObserver(list => list.getEntries().forEach(e => { if (!e.hadRecentInput) window.visualMetrics.cls += e.value; })).observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver(list => list.getEntries().forEach(e => { window.visualMetrics.lcp = e.startTime; })).observe({ type: 'largest-contentful-paint', buffered: true });
    const started = performance.now();
    const sampler = setInterval(() => {
        const title = document.querySelector('[data-h-line] > span');
        const photo = document.querySelector('[data-h-img]');
        if (title && photo) window.visualMetrics.motion.push({ ms: Math.round(performance.now() - started), titleTransform: getComputedStyle(title).transform, photoOpacity: getComputedStyle(photo).opacity, photoClip: getComputedStyle(photo).clipPath });
        if (performance.now() - started > 2400) clearInterval(sampler);
    }, 80);
};
async function ready(page, url) {
    const response = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
    assert.equal(response.status(), 200, url);
    await page.evaluate(() => document.fonts.ready);
}
async function clean(page) {
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Horizontal overflow at ${page.viewportSize().width}: ${page.url()}`);
    const broken = await page.locator('img').evaluateAll(imgs => imgs.filter(i => i.complete && i.naturalWidth === 0).map(i => i.getAttribute('src')));
    assert.deepEqual(broken, [], 'Broken images');
}
async function shot(page, name, fullPage = false) {
    await page.waitForFunction(() => [...document.images].filter(img => {
        const box = img.getBoundingClientRect();
        return box.width > 0 && box.height > 0 && box.top < innerHeight && box.bottom > 0;
    }).every(img => img.complete), null, { timeout: 30000 });
    await page.screenshot({ path: path.join(out, `${name}.png`), fullPage, animations: 'disabled' });
    if (!report.screenshots.includes(name)) report.screenshots.push(name);
}
async function loadFullHome(page) {
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    for (let y = 0; y < height; y += 700) {
        await page.evaluate(value => scrollTo(0, value), y);
        await page.waitForTimeout(100);
    }
    await page.waitForFunction(() => [...document.images].every(img => !img.getClientRects().length || img.complete), null, { timeout: 45000 });
    await page.evaluate(() => scrollTo(0, 0));
    await page.waitForTimeout(1200);
    await clean(page);
}
async function group(name, callback) {
    if (previous && report.groups.includes(name)) return;
    await callback(); report.groups.push(name);
    fs.writeFileSync(previousPath, JSON.stringify(report, null, 2));
    console.log(`PASS ${name}`);
}

(async () => {
    const server = await startServer();
    let browser, page;
    try {
        browser = await chromium.launch({ executablePath: process.env.EDGE_BINARY || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--disable-background-timer-throttling', '--disable-renderer-backgrounding'] });
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
        await context.route('**/*', route => new URL(route.request().url()).origin === server.origin ? route.continue() : route.abort());
        await context.addInitScript(instrument);
        page = await context.newPage();
        page.setDefaultTimeout(30000);
        page.on('pageerror', error => report.errors.push(error.message));
        for (const width of report.widths) {
            await group(`responsive-${width}`, async () => {
                await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
                await ready(page, server.origin);
                await page.waitForTimeout(1800);
                await page.waitForFunction(() => [...document.querySelectorAll('[data-h-chip], [data-h-img]')].every(el => getComputedStyle(el).opacity === '1'));
                await clean(page);
                report.metrics.push({ route: 'home', width, ...await page.evaluate(() => window.visualMetrics) });
                await shot(page, `home-${width}`);
                await loadFullHome(page);
                await shot(page, `home-full-${width}`, true);
                await ready(page, `${server.origin}/catalogo`);
                assert.equal(await page.locator('[data-product-id]').count(), 24);
                await page.waitForTimeout(1000);
                await clean(page);
                await shot(page, `catalog-${width}`);
                report.metrics.push({ route: 'catalog', width, ...await page.evaluate(() => window.visualMetrics) });
                await page.evaluate(() => scrollTo(0, document.querySelector('[data-catalog-grid]').getBoundingClientRect().top + scrollY - 168));
                await page.waitForTimeout(950);
                await shot(page, `cards-${width}`);
                await page.evaluate(() => scrollTo(0, 0));
                if (width < 1100) {
                    await page.locator('.mobile-menu').click();
                    assert.equal(await page.locator('.mobile-menu').getAttribute('aria-expanded'), 'true');
                    await page.locator('.category-nav-mobile .category-group > summary').first().click();
                    await clean(page);
                    await shot(page, `navigation-${width}`);
                    await page.keyboard.press('Escape');
                    // The first Escape closes the active category, the second closes navigation.
                    if (await page.locator('.mobile-menu').getAttribute('aria-expanded') === 'true') await page.keyboard.press('Escape');
                    assert.equal(await page.locator('.mobile-menu').getAttribute('aria-expanded'), 'false');
                } else {
                    await page.locator('[data-public-categories] > summary').hover();
                    await page.waitForTimeout(150);
                    await shot(page, `navigation-${width}`);
                    await page.keyboard.press('Escape');
                    await page.mouse.move(0, 900);
                }
                const product = await page.locator('.product-card h3 a').first().getAttribute('href');
                await ready(page, product);
                await clean(page);
                await shot(page, `product-${width}`);
            });
        }
        await group('search-category-history-continuation', async () => {
            await page.setViewportSize({ width: 1440, height: 1000 });
            await ready(page, `${server.origin}/catalogo`);
            const key = await page.locator('.card-meta span:last-child').first().textContent();
            await page.locator('#search').fill(key.trim());
            await page.waitForFunction(() => new URL(location.href).searchParams.has('q') && document.querySelectorAll('[data-product-id]').length === 1);
            assert((await page.locator('.card-meta').textContent()).includes(key.trim()));
            await page.goBack({ waitUntil: 'load' });
            await page.waitForFunction(() => document.querySelectorAll('[data-product-id]').length === 24);
            await page.locator('.lv-filter-pills a').nth(1).click();
            await page.waitForLoadState('load');
            assert(page.url().includes('/catalogo/categoria/'));
            assert((await page.locator('.catalog-context').textContent()).trim().length > 8);
            await ready(page, `${server.origin}/catalogo`);
            if (previous?.catalog?.total === 303) {
                report.catalog = previous.catalog;
                report.catalogReused = 'Previously verified complete browser traversal; catalog client unchanged.';
            } else {
            await page.evaluate(() => document.querySelector('[data-catalog-next]').focus({ preventScroll: true }));
            const counts = [24];
            for (let i = 0; i < 30 && await page.locator('[data-catalog-next]').isVisible(); i++) {
                const before = await page.locator('[data-product-id]').count();
                await page.locator('[data-catalog-next]').click();
                await page.waitForFunction(n => document.querySelectorAll('[data-product-id]').length > n, before);
                counts.push(await page.locator('[data-product-id]').count());
                await page.evaluate(() => document.querySelector('[data-catalog-next]').focus({ preventScroll: true }));
            }
            const ids = await page.locator('[data-product-id]').evaluateAll(nodes => nodes.map(n => n.dataset.productId));
            assert.equal(new Set(ids).size, ids.length);
            assert(ids.length > 24);
            assert(await page.locator('[data-catalog-next]').isHidden());
            assert(counts.slice(1).every((n, i) => n - counts[i] > 0 && n - counts[i] <= 24));
            report.catalog = { total: ids.length, counts };
            }
            // Return restoration contract is bounded to 240 products by the original catalog client.
            await ready(page, `${server.origin}/catalogo`);
            await page.locator('.product-card').nth(15).scrollIntoViewIfNeeded();
            await page.waitForTimeout(400);
            await page.evaluate(() => window.addEventListener('pagehide', () => sessionStorage.setItem('visualQA.departureY', String(scrollY)), { capture: true, once: true }));
            await page.locator('.product-card h3 a').nth(15).click();
            await page.waitForLoadState('load');
            const y = await page.evaluate(() => Number(sessionStorage.getItem('visualQA.departureY')));
            await page.goBack({ waitUntil: 'load' });
            await page.waitForFunction(target => Math.abs(scrollY - target) < 160, y);
            report.returnScroll = { before: y, after: await page.evaluate(() => scrollY) };
        });
        await group('ajax-cart-form-submission-isolated', async () => {
            await ready(page, `${server.origin}/catalogo`);
            const product = await page.locator('.product-card h3 a').first().getAttribute('href');
            await ready(page, product);
            await page.locator('[name=variant]').first().check();
            await page.locator('[name=quantity]').fill('49');
            assert.equal(await page.locator('[name=quantity]').evaluate(el => el.checkValidity()), false);
            await page.locator('[name=quantity]').fill('50');
            const navigation = page.url();
            await page.locator('[data-product-selection] button[type=submit], [data-product-selection] button.quote-button').click();
            await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent.trim() === '1');
            assert.equal(page.url(), navigation, 'Add stays AJAX');
            await page.locator('.quote-nav').click();
            await page.waitForLoadState('load');
            assert.equal(await page.locator('[data-selection-variant]').count(), 1);
            const quantity = page.locator('.selection-edit [name=quantity]');
            const updated = Math.min(75, Number(await quantity.getAttribute('max')));
            await quantity.fill(String(updated));
            await page.locator('.selection-edit button').click();
            await page.waitForFunction(n => document.querySelector('.selection-total strong').textContent.trim() === String(n), updated);
            await page.locator('[data-cart-action=remove] button').click();
            await page.waitForFunction(() => !document.querySelector('[data-selection-variant]'));
            await ready(page, product);
            await page.locator('[name=variant]').first().check();
            await page.locator('[data-product-selection] button.quote-button').click();
            await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent.trim() === '1');
            await page.locator('.quote-nav').click();
            await page.waitForLoadState('load');
            for (const width of report.widths) {
                await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
                await page.evaluate(() => scrollTo(0, 0));
                await clean(page);
                await shot(page, `request-${width}`);
            }
            await page.locator('#name').fill('Revisión visual aislada');
            await page.locator('#email').fill('visual-qa@example.test');
            await page.locator('#phone').fill('5555550100');
            await page.locator('[name=has_seller][value="0"]').check();
            await page.locator('#message').fill('Prueba exclusivamente en SQLite aislada del experimento visual.');
            await page.locator('[data-request-contact] button.quote-button').click();
            await page.waitForLoadState('load');
            assert((await page.locator('main').textContent()).includes('solicitud'));
            assert.equal(await page.locator('[data-request-contact]').count(), 0, 'Successful request receipt');
            await shot(page, 'request-receipt-390');
        });
        await group('corporate-selector-recovery-and-fixture', async () => {
            await page.setViewportSize({ width: 390, height: 844 });
            await ready(page, server.origin);
            await page.locator('[data-contacts-open]').click();
            await page.waitForFunction(() => !document.querySelector('[data-contacts-recovery]').hidden);
            await shot(page, 'whatsapp-unavailable-390');
            await page.keyboard.press('Escape');
            await context.route('**/contactos-corporativos', route => route.fulfill({ json: { sellers: [
                { id: 1, name: 'Asesor QA disponible', available: true, photo: null, url: 'https://wa.me/525555550100' },
                { id: 2, name: 'Asesor QA offline', available: false, photo: null, url: null },
            ] } }));
            await page.locator('[data-contacts-open]').click();
            await page.waitForSelector('.corporate-seller');
            assert.equal(await page.locator('.corporate-seller[href]').count(), 1);
            assert.equal(await page.locator('.corporate-seller:not([href])').count(), 1);
            await clean(page);
            await shot(page, 'whatsapp-fixture-390');
            await page.keyboard.press('Escape');
            assert(await page.locator('[data-contacts-open]').evaluate(el => el === document.activeElement));
        });
        await group('reduced-motion-and-no-javascript', async () => {
            const reduced = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
            const rp = await reduced.newPage();
            await ready(rp, server.origin);
            assert(await rp.locator('[data-h-line] > span').evaluateAll(nodes => nodes.every(n => getComputedStyle(n).transform === 'none' && getComputedStyle(n).opacity === '1')));
            await shot(rp, 'home-reduced-motion-1440');
            await reduced.close();
            const nojs = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
            const np = await nojs.newPage();
            await ready(np, server.origin);
            assert(await np.locator('h1').isVisible());
            assert(await np.locator('.mobile-nav').isVisible());
            await shot(np, 'home-nojs-390');
            await ready(np, `${server.origin}/catalogo`);
            assert.equal(await np.locator('[data-product-id]').count(), 24);
            const firstIds = await np.locator('[data-product-id]').evaluateAll(nodes => nodes.map(n => n.dataset.productId));
            await np.locator('[data-catalog-next]').click();
            await np.waitForLoadState('load');
            const nextIds = await np.locator('[data-product-id]').evaluateAll(nodes => nodes.map(n => n.dataset.productId));
            assert.equal(firstIds.filter(id => nextIds.includes(id)).length, 0);
            await np.locator('.product-card h3 a').first().click();
            await np.locator('[name=variant]').first().check();
            await np.locator('[data-product-selection] button.quote-button').click();
            await np.waitForLoadState('load');
            assert.equal((await np.locator('[data-cart-count]').first().textContent()).trim(), '1');
            await nojs.close();
        });
        await group('navigation-resize-and-keyboard', async () => {
            await page.setViewportSize({ width: 1039, height: 1000 });
            await ready(page, server.origin);
            await page.locator('.mobile-menu').click();
            await page.locator('.mobile-nav > nav:first-child a').first().focus();
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.waitForFunction(() => document.activeElement === document.querySelector('[data-public-categories] > summary'));
            await page.keyboard.press('Enter');
            await page.waitForFunction(() => document.querySelector('[data-public-categories]').open);
            await page.locator('[data-public-categories] .category-links a').first().focus();
            await page.setViewportSize({ width: 820, height: 1000 });
            await page.waitForFunction(() => document.activeElement === document.querySelector('.mobile-menu'));
            assert.equal(await page.locator('.mobile-menu').getAttribute('aria-expanded'), 'false');
            assert.equal(await page.locator('[data-public-categories]').evaluate(el => el.open), false);
        });
        // Keep the last complete measurement for each route/viewport across resumed QA.
        report.metrics = [...new Map(report.metrics.map(metric => [`${metric.route}-${metric.width}`, metric])).values()];
        assert.deepEqual(report.errors, [], 'JavaScript errors');
        report.ok = true;
    } catch (error) {
        report.ok = false; report.failure = error.stack;
        if (page) await shot(page, 'failure').catch(() => {});
        console.error(error.stack); process.exitCode = 1;
    } finally {
        fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
        await browser?.close();
        await server.stop();
    }
})();
