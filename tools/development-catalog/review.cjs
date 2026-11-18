// Operational browser review explicitly authorized to create fictitious requests
// and quotes in the populated LOCAL development app. Never a PHPUnit/test runner.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer, origin: reviewOrigin } = require('./server.cjs');
const existingServer = process.argv.includes('--existing-server');
const origin = existingServer ? 'http://127.0.0.1:8000' : reviewOrigin;
const root = path.resolve('storage/app/private/development-import/october-2026');
const output = path.join(root, 'visual');
fs.mkdirSync(output, { recursive: true });
const data = JSON.parse(fs.readFileSync(path.join(root, 'review-context.json')));
assert(data.environment === 'local' && data.database === 'usbstock_dev' && data.outbound_disabled);
const reportFile = path.join(root, 'browser-review.json');
const report = process.argv.includes('--resume') ? JSON.parse(fs.readFileSync(reportFile)) : { run: Date.now().toString(36), checks: [], pages: [], errors: [], external: [], requests: [], quotes: [], pdfs: [] };
if (process.argv.includes('--resume')) { report.checks = report.checks.filter(check => check.passed); delete report.failure; }
if (process.argv.includes('--refresh-public')) {
    assert(process.argv.includes('--resume') && report.checks.some(check => check.name === 'review session closed'));
    report.checks = report.checks.filter(check => !check.name.startsWith('public views and layout ')); report.pages = [];
}
const save = () => fs.writeFileSync(path.join(root, 'browser-review.json'), JSON.stringify(report, null, 2));
async function check(name, callback) {
    if (report.checks.some(check => check.name === name && check.passed)) return;
    try { await callback(); report.checks.push({ name, passed: true }); console.log('PASS ' + name); }
    catch (error) { report.checks.push({ name, passed: false, error: error.message }); console.log('FAIL ' + name + ': ' + error.message.slice(0, 220)); throw error; }
    finally { save(); }
}
let browser, server, admin;
(async () => {
    if (!existingServer) server = await startServer(path.join(output, 'server.log'), process.argv.includes('--reuse-laravel'));
    browser = await chromium.launch({ executablePath: process.env.EDGE_EXECUTABLE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
    async function context(options = {}) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ...options });
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === origin) return route.continue();
            report.external.push(route.request().url()); return route.abort();
        });
        await context.addInitScript(() => {
            window.__shifts = [];
            new PerformanceObserver(list => { for (const e of list.getEntries()) if (!e.hadRecentInput) window.__shifts.push(e.value); }).observe({ type: 'layout-shift', buffered: true });
        });
        context.on('page', page => { page.setDefaultTimeout(20000); page.setDefaultNavigationTimeout(60000); page.on('pageerror', error => report.errors.push(error.message)); });
        return context;
    }
    const publicContext = await context();
    const page = await publicContext.newPage();
    const go = async (page, route) => { const response = await page.goto(origin + route, { waitUntil: 'networkidle' }); assert.equal(response.status(), 200); await page.evaluate(() => document.fonts.ready); };
    async function shot(page, name, fullPage = false) {
        if (fullPage) {
            const previous = await page.evaluate(() => scrollY);
            for (let y = 0; y < await page.evaluate(() => document.documentElement.scrollHeight); y += 750) { await page.evaluate(y => scrollTo(0, y), y); await page.waitForTimeout(60); }
            await page.evaluate(() => Promise.all([...document.images].map(image => image.decode().catch(() => {}))));
            await page.evaluate(y => scrollTo(0, y), previous);
        }
        await page.evaluate(() => Promise.all([...document.images].filter(image => image.getBoundingClientRect().top < innerHeight).map(image => image.decode().catch(() => {}))));
        await page.screenshot({ path: path.join(output, name + '.png'), fullPage });
    }
    for (const width of [1440, 820, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await check('public views and layout ' + width, async () => {
            const routes = [['home', '/'], ['catalog', '/catalogo'], ['product', '/producto/' + data.products[0].slug], ['contact', '/contacto']];
            if (data.without_photo) routes.push(['without-photo', '/producto/' + data.without_photo]);
            for (const [name, route] of routes) {
                await go(page, route);
                assert(await page.getByText('ENTORNO DE DESARROLLO — Información de catálogo de referencia. Existencias y costos sujetos a actualización.', { exact: true }).isVisible());
                assert(!(await page.content()).includes('supplier_price_minor'));
                await shot(page, name + '-' + width, name === 'home' || name === 'product');
                const metrics = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > innerWidth + 1, cls: window.__shifts.reduce((a, b) => a + b, 0), brokenImages: [...document.images].filter(i => i.getBoundingClientRect().top < innerHeight && (!i.complete || !i.naturalWidth)).length }));
                assert.equal(metrics.overflow, false); assert.equal(metrics.brokenImages, 0); assert(metrics.cls < .1, JSON.stringify(metrics));
                report.pages.push({ name, width, ...metrics });
            }
        });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await check('24 → 48, complete catalog without duplicate IDs, filters and no JS', async () => {
        await go(page, '/catalogo'); assert.equal(await page.locator('[data-product-id]').count(), 24);
        await page.locator('[data-catalog-sentinel]').scrollIntoViewIfNeeded(); await page.waitForFunction(() => document.querySelectorAll('[data-product-id]').length >= 48);
        const visible = await page.locator('[data-product-id]').evaluateAll(elements => elements.map(element => element.dataset.productId));
        assert.equal(new Set(visible).size, visible.length); await shot(page, 'catalog-progressive-48');
        let next = origin + '/catalogo/bloques', all = [], blocks = [];
        while (next) {
            const endpoint = new URL('/catalogo/bloques', origin); endpoint.search = new URL(next).search;
            const response = await publicContext.request.get(endpoint.href, { headers: { Accept: 'application/json' } }); assert.equal(response.status(), 200);
            const block = await response.json();
            const ids = [...block.html.matchAll(/data-product-id="([^"]+)"/g)].map(match => match[1]);
            assert.equal(ids.length, block.count); assert(ids.length <= 24); all.push(...ids); blocks.push(ids.length); next = block.next;
        }
        assert.equal(all.length, data.catalog_count); assert.equal(new Set(all).size, data.catalog_count); report.catalog = { blocks, total: all.length, duplicates: 0 };
        await go(page, '/catalogo?available=1&q=USB017'); assert((await page.locator('[data-product-id]').count()) > 0);
        await go(page, '/catalogo?q=ZZZ_NO_EXISTE_OCTUBRE'); assert(await page.getByText('No encontramos modelos').isVisible()); await shot(page, 'catalog-empty-search');
        const plain = await context({ javaScriptEnabled: false, viewport: { width: 390, height: 1000 } });
        const native = await plain.newPage(); await go(native, '/catalogo'); assert.equal(await native.locator('[data-product-id]').count(), 24);
        await native.locator('[data-catalog-next]').click(); await native.waitForLoadState('networkidle'); assert.equal(await native.locator('[data-product-id]').count(), 24); await shot(native, 'catalog-no-js'); await plain.close();
    });
    async function add(item, quantity, printing = '') {
        await go(page, '/producto/' + item.slug); await page.locator(`[name=variant][value="${item.variant_uuid}"]`).check();
        await page.locator('[name=quantity]').fill(String(quantity)); await page.locator('[name=printing]').fill(printing);
        await page.getByRole('button', { name: 'Agregar a mi solicitud' }).click(); await page.waitForURL('**/solicitar-cotizacion'); await page.waitForLoadState('networkidle');
    }
    await check('two consecutive fictitious requests with real variants and seller cookie', async () => {
        await add(data.products[0], 50, 'LOGOTIPO DE PRUEBA OCTUBRE'); await add(data.products[1], 75);
        assert.equal(await page.locator('.selection-item').count(), 2);
        const firstName = 'PRUEBA LOCAL OCTUBRE A ' + report.run;
        await page.locator('[name=name]').fill(firstName); await page.locator('[name=email]').fill('octubre-a@example.test'); await page.locator('[name=phone]').fill('5550000011');
        await page.locator('[name=company]').fill('EMPRESA FICTICIA — SIN VALIDEZ COMERCIAL'); await page.locator('[name=country]').fill('México');
        await page.locator('[name=has_seller][value="1"]').check(); await page.locator('[name=seller_name]').fill(' Asesor Octubre Prueba '); await page.locator('[name=message]').fill('Observación exclusiva A — prueba local sin envío');
        await shot(page, 'request-first', true); await page.getByRole('button', { name: 'Enviar solicitud' }).click(); await page.waitForURL('**/solicitud-recibida');
        report.requests.push({ name: firstName, reference: await page.locator('.receipt strong').innerText() }); await shot(page, 'confirmation-first');
        const cookie = (await publicContext.cookies()).find(cookie => cookie.name === 'usbstock_seller_preference'); assert(cookie?.httpOnly && cookie.sameSite === 'Lax');
        await page.setViewportSize({ width: 390, height: 1000 }); await add(data.products[1], 60);
        assert.equal(await page.locator('.selection-item').count(), 1);
        for (const name of ['name', 'email', 'phone', 'company', 'country', 'message']) assert.equal(await page.locator(`[name=${name}]`).inputValue(), '');
        assert.equal(await page.locator('[name=seller_name]').inputValue(), ' Asesor Octubre Prueba ');
        assert.equal(await page.locator('.selection-edit [name=printing]').inputValue(), '');
        const secondName = 'PRUEBA LOCAL OCTUBRE B ' + report.run;
        await page.locator('[name=name]').fill(secondName); await page.locator('[name=email]').fill('octubre-b@example.test'); await page.locator('[name=phone]').fill('5550000022'); await page.locator('[name=has_seller][value="0"]').check();
        await shot(page, 'request-second-390', true); await page.getByRole('button', { name: 'Enviar solicitud' }).click(); await page.waitForURL('**/solicitud-recibida');
        report.requests.push({ name: secondName, reference: await page.locator('.receipt strong').innerText() }); assert.notEqual(report.requests[0].reference, report.requests[1].reference);
        assert(!(await publicContext.cookies()).some(cookie => cookie.name === 'usbstock_seller_preference')); await shot(page, 'confirmation-second-390');
    });
    admin = await context({ storageState: path.join(root, 'browser-access.json'), reducedMotion: 'reduce' });
    const adminPage = await admin.newPage();
    await check('existing administrator and explicitly fictitious issuer/seller', async () => {
        await go(adminPage, '/admin'); assert(!adminPage.url().includes('/login')); await shot(adminPage, 'admin-dashboard-1440');
        if (data.settings_empty) {
            await go(adminPage, '/admin/commercial-settings');
            for (const key of ['issuer_name', 'issuer_contact', 'terms']) assert.equal(await adminPage.locator('[name=' + key + ']').inputValue(), '');
            await adminPage.locator('[name=issuer_name]').fill('USB Stock · PRUEBA LOCAL SIN VALIDEZ COMERCIAL');
            await adminPage.locator('[name=issuer_contact]').fill('Revisión interna de desarrollo · desarrollo@example.test · no enviar');
            await adminPage.locator('[name=terms]').fill('DOCUMENTO DE PRUEBA LOCAL — SIN VALIDEZ COMERCIAL. Cliente ficticio. Precios de venta de prueba. Catálogo de referencia del 21 de octubre de 2026; existencias y costos sujetos a actualización. No enviar.');
            await adminPage.getByRole('button', { name: 'Guardar configuración' }).click(); await adminPage.waitForLoadState('networkidle');
        }
        await go(adminPage, '/admin/commercial-sellers');
        const form = adminPage.locator('form').filter({ has: adminPage.getByRole('button', { name: 'Registrar vendedor' }) });
        await form.locator('[name=name]').fill('Asesor ficticio · Revisión octubre'); await form.getByRole('button', { name: 'Registrar vendedor' }).click(); await adminPage.waitForLoadState('networkidle');
    });
    await check('seller association, idempotent conversion, costs, PDF and two historical versions', async () => {
        await go(adminPage, '/admin/quote-requests?q=' + encodeURIComponent(report.requests[0].name));
        const assignment = adminPage.locator('.seller-assignment').first(); const seller = await assignment.locator('option').nth(1).getAttribute('value');
        await assignment.locator('[name=seller_id]').selectOption(seller); await assignment.locator('[name=confirm]').check(); await assignment.getByRole('button', { name: 'Guardar asociación' }).click(); await adminPage.waitForLoadState('networkidle');
        const convert = adminPage.locator('form').filter({ has: adminPage.getByRole('button', { name: 'Crear borrador para costos' }) });
        const conversionAction = await convert.getAttribute('action'); const csrf = await convert.locator('[name=_token]').inputValue();
        await convert.getByRole('button', { name: 'Crear borrador para costos' }).click(); await adminPage.waitForURL(/\/admin\/quotes\//); await adminPage.waitForLoadState('networkidle');
        const quoteUrl = adminPage.url(); report.quotes.push(quoteUrl); assert.equal(await adminPage.locator('[data-quote-line]').count(), 2);
        const replay = await admin.request.post(conversionAction, { form: { _token: csrf } }); assert.equal(replay.status(), 200); assert.equal(replay.url(), quoteUrl);
        await adminPage.locator('[name="items[0][unit_price]"]').fill('100.00'); await adminPage.locator('[name="items[1][unit_price]"]').fill('125.00');
        await adminPage.locator('[name="items[0][printing_price]"]').fill('5.00'); await adminPage.locator('[name=shipping]').fill('50.00');
        await adminPage.getByRole('button', { name: 'Guardar y calcular' }).click(); await adminPage.waitForLoadState('networkidle');
        for (const width of [1440, 820, 390]) { await adminPage.setViewportSize({ width, height: 1000 }); assert.equal(await adminPage.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false); await shot(adminPage, 'admin-costs-' + width, true); }
        await adminPage.setViewportSize({ width: 1440, height: 1000 });
        const issue = async () => { const form = adminPage.locator('form').filter({ has: adminPage.getByRole('button', { name: 'Emitir cotización PDF' }) }); await form.locator('[name=confirm]').check(); await form.getByRole('button', { name: 'Emitir cotización PDF' }).click(); await adminPage.waitForLoadState('networkidle'); };
        const download = async version => { const response = await admin.request.get(await adminPage.getByRole('link', { name: 'Descargar versión ' + version }).getAttribute('href')); assert.equal(response.status(), 200); const bytes = await response.body(); assert.equal(bytes.subarray(0, 5).toString(), '%PDF-'); const name = 'october-fictitious-v' + version + '.pdf'; fs.writeFileSync(path.join(root, name), bytes); report.pdfs.push(name); return bytes; };
        await issue(); const original = await download(1); await shot(adminPage, 'admin-issued-v1');
        const revise = adminPage.locator('form').filter({ has: adminPage.getByRole('button', { name: 'Abrir revisión' }) }); await revise.locator('[name=confirm]').check(); await revise.getByRole('button', { name: 'Abrir revisión' }).click(); await adminPage.waitForLoadState('networkidle');
        await adminPage.locator('[name="items[0][unit_price]"]').fill('110.00'); await adminPage.getByRole('button', { name: 'Guardar y calcular' }).click(); await adminPage.waitForLoadState('networkidle'); await issue(); await download(2);
        const historical = await admin.request.get(await adminPage.getByRole('link', { name: 'Descargar versión 1' }).getAttribute('href')); assert.deepEqual(await historical.body(), original); await shot(adminPage, 'admin-issued-history');
    });
    await check('admin responsive, public hover/reduced motion and no external traffic', async () => {
        for (const width of [1440, 820, 390]) {
            await adminPage.setViewportSize({ width, height: 1000 });
            for (const [name, route] of [['products', '/admin/products'], ['requests', '/admin/quote-requests'], ['quotes', '/admin/quotes'], ['sync', '/admin/supplier-sync']]) {
                await go(adminPage, route); await shot(adminPage, 'admin-' + name + '-' + width);
                const layout = await adminPage.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth,
                    offenders: [...document.querySelectorAll('main *')].filter(e => e.getBoundingClientRect().right > innerWidth + 1).slice(-8).map(e => ({tag:e.tagName, cls:e.className, right:Math.round(e.getBoundingClientRect().right)})) }));
                assert(layout.scroll <= width + 1, route + ' ' + JSON.stringify(layout));
            }
        }
        await page.setViewportSize({ width: 1440, height: 1000 }); await go(page, '/catalogo'); const card = page.locator('.product-card').first(); await card.hover(); await page.waitForTimeout(400); report.hover = await card.evaluate(element => getComputedStyle(element.querySelector('img')).transform); assert.notEqual(report.hover, 'none');
        await page.emulateMedia({ reducedMotion: 'reduce' }); await go(page, '/'); assert.equal(await page.evaluate(() => document.getAnimations().length), 0);
        assert.deepEqual(report.errors, []); assert.deepEqual(report.external, []);
    });
    // Close only this review session through the normal logout route.
    await check('review session closed', async () => {
        await go(adminPage, '/admin'); const logout = adminPage.locator('form[action$="/logout"]');
        assert.equal(await logout.count(), 1);
        const response = await admin.request.post(await logout.getAttribute('action'), { form: { _token: await logout.locator('[name=_token]').inputValue() } });
        assert.equal(response.status(), 200);
    });
    await publicContext.close(); await admin.close();
})().catch(error => { report.failure = error.message; console.error(error.message); process.exitCode = 1; }).finally(async () => { save(); await browser?.close(); await server?.stop(); console.log(JSON.stringify({ checks: report.checks.length, failed: report.checks.filter(x => !x.passed).length, failure: report.failure })); });
