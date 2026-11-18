// Real local Laravel review. All external browser requests are blocked; corporate JSON is synthetic.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer } = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/taxonomy-20261118');
const expected = JSON.parse(fs.readFileSync(path.join(root, 'browser-expected.json')));
const resume = process.argv.includes('--resume');
const report = resume ? JSON.parse(fs.readFileSync(path.join(root, 'browser-report.json'))) : { groups: [], screenshots: [], motions: [], blockedExternal: [], consoleErrors: [] };
let currentPage;
async function shot(page, name) { await page.waitForTimeout(350); const file = path.join(root, name + '.png'); await page.screenshot({path: file, fullPage: false}); if (!report.screenshots.includes(name)) report.screenshots.push(name); }
async function check(name, work) {
    if (resume && report.groups.includes(name) && !(process.argv.includes('--repeat-admin') && name === 'administration-taxonomy-stock-and-pagination')) return;
    await work(); if (!report.groups.includes(name)) report.groups.push(name); console.log('PASS ' + name);
}
async function clean(page) { assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Horizontal overflow'); }
async function goto(page, url) { const r = await page.goto(url, {waitUntil: 'domcontentloaded'}); assert.equal(r.status(), 200, 'Route must render'); await page.waitForLoadState('load'); }
const instrumentation = () => {
    window.reviewMotion = [];
    window.reviewCLS = 0;
    new PerformanceObserver(list => list.getEntries().forEach(e => { if (!e.hadRecentInput) window.reviewCLS += e.value; })).observe({type: 'layout-shift', buffered: true});
    const original = Element.prototype.animate;
    Element.prototype.animate = function (frames, options) {
        const record = {target: this.dataset.productId || this.dataset.heroStep || this.className, card: this.matches('.product-card'), hero: this.hasAttribute('data-hero-step'), options, at: performance.now(), frames: []};
        window.reviewMotion.push(record);
        const animation = original.call(this, frames, options);
        const node = this, start = performance.now();
        const sample = () => {
            const css = getComputedStyle(node);
            record.frames.push({t: Math.round(performance.now() - start), opacity: Number(css.opacity), transform: css.transform, translate: css.translate});
            if (performance.now() - start < 850) requestAnimationFrame(sample);
        };
        requestAnimationFrame(sample);
        return animation;
    };
};
(async () => {
    const server = await startServer(path.join(root, 'browser-server.log'), true);
    const browser = await chromium.launch({executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true,
        args: ['--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows', '--disable-features=CalculateNativeWinOcclusion']});
    const origin = server.origin;
    const context = async options => {
        const c = await browser.newContext(options);
        await c.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
            report.blockedExternal.push(url.origin); return route.abort();
        });
        await c.addInitScript(instrumentation);
        c.on('page', page => { page.on('pageerror', error => report.consoleErrors.push(error.message)); });
        return c;
    };
    try {
        for (const width of [1440, 820, 390]) {
            const c = await context({viewport: {width, height: 980}});
            const page = currentPage = await c.newPage(); page.setDefaultTimeout(15000);
            await check(`navigation-home-${width}`, async () => {
                await goto(page, origin + '/'); await clean(page);
                await page.waitForTimeout(1000);
                const motion = await page.evaluate(() => window.reviewMotion.filter(m => m.hero));
                assert(motion.length >= 6); assert(motion.some(m => m.frames.some(f => f.opacity < .9)));
                assert(motion.every(m => m.options.delay <= 450)); report.motions.push({width, home: motion});
                await shot(page, `home-${width}`);
                if (width < 1100) await page.locator('.mobile-menu').click();
                const nav = page.locator(width < 1100 ? '.category-nav-mobile' : '.category-nav-desktop');
                assert.equal(await nav.locator('.category-group').count(), 6);
                const summary = nav.locator('summary').first();
                if (width === 1440) await summary.hover(); else await summary.click();
                await page.waitForFunction(selector => document.querySelector(selector).open, (width < 1100 ? '.category-nav-mobile' : '.category-nav-desktop') + ' details');
                await shot(page, `menu-${width}`);
                await summary.focus(); await page.keyboard.press('Escape');
                await page.waitForFunction(selector => document.querySelector(selector).getAttribute('aria-expanded') === 'false', (width < 1100 ? '.category-nav-mobile' : '.category-nav-desktop') + ' summary');
                await page.keyboard.press('Enter');
                await nav.getByRole('link', {name: 'Metálicas'}).click();
                await page.waitForURL('**/catalogo/categoria/**');
                assert((await page.locator('.catalog-context').innerText()).includes('Metálicas'));
                assert(await page.locator('[data-product-id]').count() > 0); await clean(page); await shot(page, `category-${width}`);
            });
            await check(`catalog-cart-contacts-${width}`, async () => {
                await goto(page, origin + '/catalogo');
                assert.equal(await page.locator('[name="capacity"], [name="available"]').count(), 0);
                await page.locator('[data-product-id]').first().scrollIntoViewIfNeeded();
                await page.waitForTimeout(900);
                const initial = await page.evaluate(() => window.reviewMotion.filter(m => m.card));
                assert(initial.some(m => m.frames.some(f => f.opacity < .9)));
                assert(initial.every(m => m.options.delay <= 300));
                report.motions.push({width, initialCards: initial});
                const photo = page.locator('.product-image img').first();
                const before = await photo.evaluate(el => getComputedStyle(el).transform);
                await photo.hover(); await page.waitForTimeout(300);
                assert.equal(await photo.evaluate(el => getComputedStyle(el).transform), before);
                await shot(page, `catalog-${width}`);
                await goto(page, origin + '/catalogo?q=USB017');
                await page.locator('.product-card h3 a').first().click(); await page.waitForURL('**/producto/**');
                const radio = page.locator('[name="variant"]').first(); await radio.check();
                assert.equal(await page.locator('[name="quantity"]').inputValue(), '50');
                assert.equal(await page.locator('[name="quantity"]').getAttribute('max'), await radio.getAttribute('data-stock'));
                const location = page.url();
                await page.locator('[data-cart-action] button').click();
                await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent === '1'); assert.equal(page.url(), location);
                await page.locator('[name="quantity"]').fill('49'); await page.locator('[data-cart-action] button').click();
                assert.equal(await page.locator('[name="quantity"]').evaluate(el => el.validity.rangeUnderflow), true);
                await page.locator('[name="quantity"]').fill('50'); await page.locator('h1').click();
                const tab = await page.locator('.quote-tab').boundingBox(), wa = await page.locator('.corporate-trigger').boundingBox();
                assert(tab && wa && (tab.x + tab.width <= wa.x || wa.x + wa.width <= tab.x || tab.y + tab.height <= wa.y || wa.y + wa.height <= tab.y), 'Floating controls overlap');
                await shot(page, `product-dock-${width}`);
                await page.locator('[data-contacts-open]').click();
                await page.getByText('No pudimos consultar el directorio.', {exact: false}).waitFor();
                await shot(page, `contacts-disabled-${width}`);
                await page.keyboard.press('Escape'); assert(await page.locator('[data-contacts-open]').evaluate(el => el === document.activeElement));
                let clicks = 0, lists = 0;
                const fake = {success: true, sellers: [
                    {id: 901, name: 'Asesora ficticia · QA', available: true, photo: 'https://grupotalento.com.mx/qa-missing.jpg', url: 'https://wa.me/12025550101?text=Prueba', ticket: 'synthetic-ticket'},
                    {id: 902, name: 'Asesor ficticio · QA', available: false, photo: null, url: null, ticket: null}
                ]};
                await page.route('**/contactos-corporativos', route => { lists++; return route.fulfill({json: fake}); });
                await page.route('**/contactos-corporativos/clic', route => { clicks++; return route.fulfill({status: 503, json: {success: false}}); });
                await page.locator('[data-contacts-open]').click(); await page.locator('.corporate-list li').first().waitFor();
                assert.equal(await page.locator('.corporate-list a').count(), 1);
                assert.equal(await page.locator('.corporate-list img').count(), 0);
                await shot(page, `contacts-simulated-${width}`);
                await page.locator('.corporate-list a').focus();
                await page.keyboard.press('Tab'); assert(await page.locator('dialog').evaluate(el => el.contains(document.activeElement)));
                await page.locator('.corporate-list a').click();
                await page.waitForFunction(() => document.querySelector('[data-contacts-status]').textContent.includes('actual'));
                await page.locator('.corporate-list a').click();
                assert.equal(clicks, 1); assert(lists >= 2, 'List refreshes after a click even when metrics fail');
                assert(!(await c.cookies()).some(cookie => /seller|vendedor/i.test(cookie.name)));
                await page.keyboard.press('Escape');
                await page.locator('.quote-tab').click(); await page.waitForURL('**/solicitar-cotizacion#seleccion');
                assert.equal(await page.locator('[name="seller_name"]').inputValue(), '');
                await page.locator('[data-contacts-open]').click();
                await page.locator('[data-contacts-close]').click();
                await clean(page); await shot(page, `selection-${width}`);
            });
            if (width === 1440) await check('full-catalog-cursor-return-and-new-motion', async () => {
                await goto(page, origin + '/catalogo');
                let cycles = 0;
                while (await page.locator('[data-catalog-next]').isVisible()) {
                    assert(++cycles <= 15);
                    const prior = await page.locator('[data-product-id]').count();
                    await page.locator('[data-catalog-next]').click();
                    await page.waitForFunction(n => document.querySelectorAll('[data-product-id]').length > n || document.querySelector('[data-catalog-next]').hidden, prior);
                    await page.waitForFunction(() => document.querySelector('[data-catalog-grid]').getAttribute('aria-busy') === 'false');
                }
                const ids = await page.locator('[data-product-id]').evaluateAll(nodes => nodes.map(n => n.dataset.productId));
                assert.deepEqual(ids, expected.eligible); assert.equal(new Set(ids).size, 303);
                const motions = await page.evaluate(() => window.reviewMotion.filter(m => m.card));
                assert.equal(new Set(motions.map(m => m.target)).size, motions.length, 'Each old card animates once');
                assert(motions.length > 24); assert(motions.every(m => m.options.delay <= 300));
                report.motions.push({fullCatalog: motions}); await shot(page, 'catalog-final');
                await goto(page, origin + '/catalogo');
                await page.locator('[data-product-id]').nth(20).scrollIntoViewIfNeeded();
                const target = page.locator('[data-product-id]').nth(20).locator('h3 a'); const targetId = await page.locator('[data-product-id]').nth(20).getAttribute('data-product-id');
                await target.click(); await page.waitForURL('**/producto/**'); await page.goBack();
                await page.waitForFunction(id => document.activeElement?.closest('[data-product-id]')?.dataset.productId === id, targetId);
                assert(await page.evaluate(() => scrollY > 100));
            });
            await c.close();
        }
        await check('no-javascript-native-navigation-and-cart', async () => {
            const c = await context({javaScriptEnabled: false, viewport: {width: 390, height: 980}}); const page = currentPage = await c.newPage();
            await goto(page, origin + '/catalogo?q=USB017'); await clean(page);
            await page.locator('.product-card h3 a').first().click();
            await page.locator('[name="variant"]').first().check(); await page.locator('[name="quantity"]').fill('49'); await page.locator('.quote-button').click({force: true});
            assert(page.url().includes('/producto/'));
            await page.locator('[name="quantity"]').fill('50'); await page.locator('.quote-button').click({force: true}); await page.waitForURL('**/solicitar-cotizacion');
            assert((await page.locator('[data-selection-summary]').innerText()).includes('50 piezas')); await shot(page, 'no-javascript-selection'); await c.close();
        });
        await check('reduced-motion-visible-content', async () => {
            const c = await context({reducedMotion: 'reduce', viewport: {width: 1440, height: 980}}); const page = currentPage = await c.newPage();
            await goto(page, origin + '/'); assert.equal(await page.evaluate(() => window.reviewMotion.length), 0); await shot(page, 'reduced-motion-home'); await c.close();
        });
        await check('final-centered-panel-states-photos-and-layout', async () => {
            report.layouts = [];
            for (const width of [1440, 820, 390]) {
                const c = await context({viewport: {width, height: 980}}); const page = currentPage = await c.newPage();
                await goto(page, origin + '/'); await page.waitForTimeout(1100); await clean(page);
                const cls = await page.evaluate(() => window.reviewCLS); assert(cls < .1, 'Unexpected layout shift');
                await shot(page, `final-home-${width}`);
                await goto(page, origin + '/catalogo?q=USB017'); await page.locator('.product-image img').first().scrollIntoViewIfNeeded();
                await page.waitForFunction(() => [...document.querySelectorAll('.product-image img')].every(img => img.complete && img.naturalWidth > 0));
                await shot(page, `final-photo-${width}`);
                let body = {success: true, sellers: [{id: 901, name: 'Asesora ficticia · QA', available: true, photo: null, url: 'https://wa.me/12025550101', ticket: 'synthetic-ticket'}, {id: 902, name: 'Asesor ficticio · QA', available: false, photo: null, url: null, ticket: null}]};
                let timeout = false;
                await page.route('**/contactos-corporativos', async route => {
                    if (timeout) await new Promise(resolve => setTimeout(resolve, 7500));
                    await route.fulfill({json: body}).catch(() => {});
                });
                await page.locator('[data-contacts-open]').click(); await page.locator('.corporate-list li').first().waitFor(); await page.waitForTimeout(300);
                const box = await page.locator('dialog').boundingBox();
                assert(Math.abs(box.x + box.width / 2 - width / 2) < 2, 'Panel centered'); assert(box.y >= 0 && box.y + box.height <= 980);
                await shot(page, `final-contacts-${width}`);
                report.layouts.push({width, cls, dialog: box});
                await page.keyboard.press('Escape');
                if (width === 390) {
                    body = {success: true, sellers: []}; await page.locator('[data-contacts-open]').click(); await page.getByText('No hay asesores disponibles por el momento.', {exact: true}).waitFor(); await shot(page, 'contacts-empty'); await page.keyboard.press('Escape');
                    body = {sellers: 'invalid'}; await page.locator('[data-contacts-open]').click(); await page.locator('[data-contacts-retry]').waitFor(); assert.equal(await page.locator('.corporate-list li').count(), 0); await shot(page, 'contacts-invalid'); await page.keyboard.press('Escape');
                    timeout = true; await page.locator('[data-contacts-open]').click(); await page.locator('[data-contacts-retry]').waitFor({timeout: 11000}); await shot(page, 'contacts-timeout'); await page.keyboard.press('Escape');
                }
                await c.close();
            }
        });
        await check('administration-taxonomy-stock-and-pagination', async () => {
            const c = await context({viewport: {width: 1440, height: 980}, storageState: path.join(root, 'browser-access.json')}); const page = currentPage = await c.newPage();
            await goto(page, origin + '/admin/product-categories'); assert.equal(await page.locator('[name="name"]').count(), 32); await shot(page, 'admin-categories');
            await goto(page, origin + '/admin/products?availability=insufficient'); assert(await page.locator('tbody tr').count() > 0); await shot(page, 'admin-insufficient');
            await page.locator('tbody a').first().click(); await page.getByText('Clasificación histórica verificada').waitFor(); await shot(page, 'admin-product');
            await goto(page, origin + '/admin/products?per_page=50'); assert.equal(await page.locator('tbody tr').count(), 50);
            for (const width of [820, 390]) {
                await page.setViewportSize({width, height: 980});
                await goto(page, origin + '/admin/product-categories'); await clean(page); await shot(page, `admin-categories-${width}`);
                await goto(page, origin + '/admin/products?availability=insufficient'); await clean(page); await shot(page, `admin-products-${width}`);
            }
            const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
            await page.request.post(origin + '/admin/logout', {form: {_token: token}});
            await c.close(); fs.unlinkSync(path.join(root, 'browser-access.json'));
        });
        assert.deepEqual(report.consoleErrors, []); report.passed = true; delete report.failure; delete report.failureContext;
    } catch (error) {
        report.passed = false; report.failure = error.message; console.error(error.stack);
        if (currentPage && !currentPage.isClosed()) report.failureContext = await currentPage.evaluate(() => ({path: location.pathname, focus: document.activeElement?.outerHTML?.slice(0, 300), y: scrollY, saved: Boolean(sessionStorage.getItem('usbstock.catalog.return')), cards: document.querySelectorAll('[data-product-id]').length})).catch(() => null);
        if (currentPage && !currentPage.isClosed()) await shot(currentPage, 'failure').catch(() => {});
        process.exitCode = 1;
    } finally {
        report.screenshots = [...new Set(report.screenshots.filter(name => name !== 'failure'))];
        fs.writeFileSync(path.join(root, 'browser-report.json'), JSON.stringify(report, null, 2));
        await browser.close(); await server.stop();
        console.log(JSON.stringify({passed: report.passed, groups: report.groups.length, screenshots: report.screenshots.length}));
    }
})();
