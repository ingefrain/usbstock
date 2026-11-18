// Real local browser QA authorized by the UX task. Creates exactly two marked requests.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer, origin } = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/qa/operational-ux');
const data = JSON.parse(fs.readFileSync(path.join(root, 'before.json')));
assert(data.environment.safe && data.database === 'usbstock_dev');
const reportFile = path.join(root, 'browser-report.json');
const report = process.argv.includes('--resume') ? JSON.parse(fs.readFileSync(reportFile)) : { run: Date.now().toString(36), checks: [], screenshots: [], errors: [], requests: [] };
const save = () => fs.writeFileSync(reportFile, JSON.stringify(report, null, 2));
const check = async (name, fn) => {
    if (report.checks.some(c => c.name === name && c.passed)) return;
    try { await fn(); report.checks.push({ name, passed: true }); console.log('PASS ' + name); }
    catch (e) { report.failure = { name, message: e.message, stack: e.stack }; throw e; }
    finally { save(); }
};
let browser, server;
(async () => {
    server = await startServer(path.join(root, 'server.log'), true);
    browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
    async function context(options = {}) {
        const c = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ...options });
        await c.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        c.on('page', p => { p.setDefaultTimeout(12000); p.setDefaultNavigationTimeout(60000); p.on('pageerror', e => report.errors.push(e.message)); });
        return c;
    }
    const go = async (p, url) => { const r = await p.goto(origin + url, { waitUntil: 'networkidle' }); assert.equal(r.status(), 200, url); await p.evaluate(() => document.fonts.ready); };
    const shot = async (p, name, fullPage = false) => {
        await p.evaluate(() => Promise.all([...document.images].filter(i => i.getBoundingClientRect().top < innerHeight).map(i => i.decode().catch(() => {}))));
        await p.screenshot({ path: path.join(root, name + '.png'), fullPage }); report.screenshots.push(name); save();
    };
    const layout = async p => { assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'horizontal overflow'); };
    const state = async p => p.evaluate(async () => (await fetch(document.body.dataset.cartState, { headers: { Accept: 'application/json' } })).json());
    async function mutate(p, form) {
        const before = p.url();
        const button = form.locator('button').first();
        await button.scrollIntoViewIfNeeded();
        const y = await p.evaluate(() => scrollY);
        const response = p.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.startsWith('/seleccion'), { timeout: 45000 });
        let r;
        try { await button.click(); r = await response; }
        catch (error) {
            await shot(p, 'failed-mutation');
            report.mutationFailure = await form.evaluate(f => ({ url: location.pathname, valid: f.checkValidity(), busy: f.getAttribute('aria-busy'), controls: [...f.elements].filter(e => e.name !== '_token').map(e => ({ name: e.name, disabled: e.disabled, valid: e.validity?.valid })) }));
            throw error;
        }
        assert.equal(r.status(), 200, 'Unexpected cart HTTP status');
        await p.waitForFunction(() => !document.querySelector('[data-cart-action][aria-busy="true"]'));
        assert.equal(p.url(), before, 'AJAX must retain URL');
        return { y, afterY: await p.evaluate(() => scrollY) };
    }
    async function add(p, item, qty, printing = '') {
        await go(p, '/producto/' + item.slug);
        await p.locator(`[name=variant][value="${item.variant_uuid}"]`).check();
        await p.locator('[name=quantity]').fill(String(qty));
        await p.locator('[name=printing]').fill(printing);
        const scroll = await mutate(p, p.locator('[data-cart-action=add]'));
        assert(Math.abs(scroll.y - scroll.afterY) <= 2, 'adding shifted the page');
        assert(await p.locator('[data-quote-tab]').isVisible());
    }
    for (const width of [1440, 820, 390]) await check('AJAX selection and responsive tab ' + width, async () => {
        const c = await context({ viewport: { width, height: 1000 } }); const p = await c.newPage();
        await go(p, '/'); assert(await p.locator('[data-quote-tab]').isHidden());
        await add(p, data.products[0], 50, 'PRUEBA UX un color');
        await add(p, data.products[1], 80);
        await add(p, data.products[1], 90);
        let s = await state(p); assert.equal(s.selection.line_count, 2); assert.equal(s.selection.piece_count, 140);
        await shot(p, 'product-' + width); await layout(p);
        await p.locator('[data-quote-tab]').click(); await p.waitForURL('**/solicitar-cotizacion#seleccion'); await p.waitForLoadState('networkidle');
        const first = p.locator(`[data-selection-variant="${data.products[0].variant_uuid}"]`);
        await p.locator('[name=name]').fill('Conservar datos del cliente');
        await first.locator('[name=quantity]').fill('100'); await mutate(p, first.locator('[data-cart-action=update]'));
        assert.equal(await p.locator('[name=name]').inputValue(), 'Conservar datos del cliente');
        assert.equal((await state(p)).selection.piece_count, 190);
        await mutate(p, p.locator(`[data-selection-variant="${data.products[1].variant_uuid}"] [data-cart-action=remove]`));
        assert.equal((await state(p)).selection.line_count, 1);
        await shot(p, 'selection-' + width, true); await layout(p);
        if (width < 1100) { await p.locator('[name=name]').focus(); assert(await p.locator('[data-quote-tab]').isHidden()); await p.locator('h1').click(); }
        for (const url of ['/', '/contacto', '/catalogo', ...(data.category_slug ? ['/catalogo/categoria/' + data.category_slug] : [])]) {
            await go(p, url); assert(await p.locator('[data-quote-tab]').isVisible()); await layout(p);
        }
        await go(p, '/solicitar-cotizacion'); await mutate(p, p.locator('[data-cart-action=remove]'));
        assert(await p.locator('[data-quote-tab]').isHidden()); assert(await p.getByText('Tu selección está vacía').isVisible());
        await c.close();
    });
    await check('errors, double click, cross-tab synchronization and progressive catalog', async () => {
        const c = await context(); const p = await c.newPage();
        await add(p, data.products[0], 50); await go(p, '/solicitar-cotizacion');
        await p.locator('[name=name]').fill('No borrar este nombre');
        const original = await state(p);
        for (const status of [401, 403, 419, 422, 409, 429, 500, 503]) {
            const pattern = '**/seleccion';
            await p.route(pattern, route => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'DO NOT SHOW RAW SQL', ...(status === 409 ? { selection: original.selection } : {}) }) }));
            await p.locator('[data-cart-action=update] [name=quantity]').fill('75');
            await p.locator('[data-cart-action=update] button').click();
            await p.waitForFunction(() => document.querySelector('[data-cart-feedback].is-error') && !document.querySelector('[aria-busy="true"]'));
            assert.equal(await p.locator('[name=name]').inputValue(), 'No borrar este nombre');
            assert.equal(await p.locator('[data-selection-variant]').count(), 1);
            assert(!(await p.locator('[data-cart-message]').innerText()).includes('SQL'));
            await p.unroute(pattern);
        }
        await p.route('**/seleccion', route => route.abort()); await p.locator('[data-cart-action=update] button').click();
        await p.waitForFunction(() => !document.querySelector('[aria-busy="true"]')); await p.unroute('**/seleccion');
        assert.equal((await state(p)).selection.piece_count, 50);
        let posts = 0;
        await p.route('**/seleccion', async route => { posts++; await new Promise(r => setTimeout(r, 250)); await route.continue(); });
        await p.locator('[data-cart-action=update]').evaluate(form => { form.requestSubmit(); form.requestSubmit(); });
        await p.waitForFunction(() => !document.querySelector('[aria-busy="true"]')); assert.equal(posts, 1); await p.unroute('**/seleccion');
        assert.equal((await state(p)).selection.piece_count, 75);
        const other = await c.newPage(); await add(other, data.products[1], 60);
        await p.waitForFunction(() => document.querySelectorAll('[data-selection-variant]').length === 2);
        assert.equal(await p.locator('[name=name]').inputValue(), 'No borrar este nombre');
        await shot(p, 'review-cross-tab');
        await go(p, '/catalogo'); await p.locator('[data-catalog-sentinel]').scrollIntoViewIfNeeded();
        await p.waitForFunction(() => document.querySelectorAll('[data-product-id]').length >= 48);
        const href = await p.locator('[data-product-id] a').first().getAttribute('href');
        await p.goto(href); await p.goBack({ waitUntil: 'networkidle' });
        assert(await p.locator('[data-product-id]').count() >= 48); assert(await p.locator('[data-quote-tab]').isVisible());
        await c.close();
    });
    await check('native forms without JavaScript', async () => {
        const c = await context({ javaScriptEnabled: false, viewport: { width: 390, height: 1000 } }); const p = await c.newPage();
        await go(p, '/producto/' + data.products[0].slug);
        await p.locator(`[name=variant][value="${data.products[0].variant_uuid}"]`).check();
        await p.locator('[data-cart-action=add] button').click(); await p.waitForURL('**/solicitar-cotizacion');
        await p.locator('[name=quantity]').fill('90'); await p.locator('[data-cart-action=update] button').click(); await p.waitForLoadState('networkidle');
        assert(await p.getByText('90 piezas', { exact: false }).isVisible());
        await shot(p, 'native-selection-390');
        await p.locator('[data-cart-action=remove] button').click(); await p.waitForLoadState('networkidle');
        assert(await p.getByText('Tu selección está vacía').isVisible()); await c.close();
    });
    await check('two consecutive marked local requests and seller cookie', async () => {
        assert.equal(report.requests.length, 0, 'Resume manually from saved request evidence; do not duplicate a submitted request.');
        const c = await context({ viewport: { width: 390, height: 1000 } }); const p = await c.newPage();
        for (let i = 0; i < 2; i++) {
            await add(p, data.products[i], 50, i === 0 ? 'Impresión exclusiva primera solicitud UX' : '');
            await go(p, '/solicitar-cotizacion');
            for (const field of ['name', 'email', 'phone', 'company', 'message']) assert.equal(await p.locator(`[name=${field}]`).inputValue(), '');
            if (i === 1) { assert.equal(await p.locator('[name=seller_name]').inputValue(), 'Asesor UX de prueba'); assert.equal(await p.locator('[name=printing]').inputValue(), ''); }
            const name = `PRUEBA LOCAL UX ${i + 1} ${report.run}`;
            await p.locator('[name=name]').fill(name); await p.locator('[name=email]').fill('ux@example.test'); await p.locator('[name=phone]').fill('5550000099');
            await p.locator('[name=has_seller][value="1"]').check(); await p.locator('[name=seller_name]').fill('Asesor UX de prueba');
            await p.locator('[name=company]').fill('PRUEBA FICTICIA SIN VALIDEZ COMERCIAL');
            await p.getByRole('button', { name: 'Enviar solicitud' }).click(); await p.waitForURL('**/solicitud-recibida');
            report.requests.push({ name, reference: await p.locator('.receipt strong').innerText() }); save();
            assert(await p.locator('[data-quote-tab]').isHidden());
            const cookie = (await c.cookies()).find(cookie => cookie.name === 'usbstock_seller_preference'); assert(cookie?.httpOnly);
        }
        await shot(p, 'consecutive-request-receipt'); await c.close();
    });
    await check('final contrast, keyboard, modal and screenshots', async () => {
        const c = await context(); const p = await c.newPage();
        for (const width of [1440, 820, 390]) {
            await p.setViewportSize({ width, height: 1000 }); await add(p, data.products[0], 50);
            await p.waitForTimeout(500);
            assert.equal(await p.locator('[data-quote-tab]').evaluate(n => getComputedStyle(n).color), 'rgb(255, 255, 255)');
            await shot(p, 'product-final-' + width); await go(p, '/solicitar-cotizacion');
            await shot(p, 'selection-final-' + width, true); await layout(p);
            await p.locator('[data-quote-tab]').focus(); assert(await p.locator('[data-quote-tab]').evaluate(n => n === document.activeElement));
            await p.evaluate(() => { const dialog = document.createElement('dialog'); dialog.textContent = 'Prueba modal local'; document.body.append(dialog); dialog.showModal(); });
            assert(await p.locator('[data-quote-tab]').isHidden());
            await p.evaluate(() => document.querySelector('dialog').remove());
            await p.locator('#catalog-footer').scrollIntoViewIfNeeded();
            if (width < 1100) {
                const footer = await p.locator('#catalog-footer').boundingBox(); const tab = await p.locator('[data-quote-tab]').boundingBox();
                assert(footer.y + footer.height <= tab.y, 'footer must scroll above the fixed tab');
            }
        }
        await c.close();
    });
    await check('administrative lists, controls, filters and responsive icons', async () => {
        const c = await context({ storageState: path.join(root, 'browser-access.json') }); const p = await c.newPage();
        const urls = ['/admin/products', '/admin/quote-requests', '/admin/quotes', '/admin/clients', '/admin/commercial-sellers', '/admin/users', '/admin/roles', '/admin/documents', '/admin/catalogs', '/admin/imports', '/admin/supplier-sync', '/admin/audit', '/admin/reports/users', '/admin/notifications', '/admin/organizations', '/admin/locations', '/admin/system-audit/login-security'];
        if (data.catalog_uuid) urls.push('/admin/catalogs/' + data.catalog_uuid + '/items');
        for (const url of urls) {
            await go(p, url + '?per_page=20'); assert.equal(await p.locator('[data-quote-tab]').count(), 0);
            assert.equal(await p.locator('[data-admin-pagination]').count(), 1, url);
            const text = await p.locator('[data-admin-pagination]').innerText(); assert(!/Showing|Previous|Next|results/i.test(text));
            assert(await p.locator('[data-admin-pagination] select[name=per_page]').inputValue() === '20');
        }
        for (const width of [1440, 820, 390]) {
            await p.setViewportSize({ width, height: 1000 }); await go(p, '/admin/products?q=USB&per_page=20');
            assert((await p.locator('.admin-pagination-info').innerText()).includes('Mostrando 1–20'));
            await p.locator('[data-admin-pagination] a[rel=next]').click(); await p.waitForLoadState('networkidle');
            assert(new URL(p.url()).searchParams.get('q') === 'USB');
            assert((await p.locator('.admin-pagination-info').innerText()).includes('21–40'));
            await p.locator('[data-admin-pagination] select[name=page]').selectOption('3');
            await p.locator('[data-admin-pagination]').getByRole('button', { name: 'Ir', exact: true }).click(); await p.waitForLoadState('networkidle');
            assert((await p.locator('.admin-pagination-info').innerText()).includes('41–60'));
            await p.locator('[data-admin-pagination] select[name=per_page]').selectOption('50');
            await p.locator('[data-admin-pagination]').getByRole('button', { name: 'Aplicar', exact: true }).click(); await p.waitForLoadState('networkidle');
            assert.equal(new URL(p.url()).searchParams.get('page'), '1');
            assert((await p.locator('.admin-pagination-info').innerText()).includes('1–50'));
            const sizes = await p.locator('[data-admin-pagination] svg').evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().width)); assert(sizes.every(n => n >= 16 && n <= 20));
            await p.locator('[data-admin-pagination]').scrollIntoViewIfNeeded(); await layout(p); await shot(p, 'admin-pagination-' + width);
        }
        await go(p, '/admin/products?q=UX_NO_EXISTE&page=999&per_page=9999999'); assert(await p.getByText('Sin resultados', { exact: true }).isVisible());
        await shot(p, 'admin-empty-390');
        await p.getByLabel('Abrir menú de perfil').click();
        await p.locator('form[action$="/logout"] button').click(); await p.waitForURL('**/admin/login');
        await c.close(); fs.unlinkSync(path.join(root, 'browser-access.json'));
    });
    await check('reduced motion', async () => {
        const c = await context({ reducedMotion: 'reduce' }); const p = await c.newPage(); await add(p, data.products[0], 50);
        assert.equal(await p.locator('[data-quote-tab]').evaluate(n => getComputedStyle(n).animationName), 'none'); await c.close();
    });
    await check('accessible live feedback and session-scoped removal', async () => {
        const c = await context(); const p = await c.newPage(); await add(p, data.products[0], 50);
        const live = p.locator('[data-cart-announcement]'); assert.equal(await live.getAttribute('aria-live'), 'polite');
        assert((await live.innerText()).includes('Agregamos la variante'));
        const response = await p.evaluate(async uuid => {
            const r = await fetch('/seleccion/' + uuid, { method: 'DELETE', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ revision: Number(document.body.dataset.cartRevision) }) });
            return { status: r.status, data: await r.json() };
        }, data.products[1].variant_uuid);
        assert.equal(response.status, 200); assert.equal(response.data.item.variant, null); assert.equal(response.data.selection.line_count, 1);
        await go(p, '/solicitar-cotizacion'); await mutate(p, p.locator('[data-cart-action=remove]'));
        assert.equal((await state(p)).selection.line_count, 0); assert(await p.locator('[data-quote-tab]').isHidden());
        assert((await p.locator('[data-cart-announcement]').innerText()).includes('Quitamos la variante'));
        await c.close();
    });
    assert.equal(report.errors.length, 0, JSON.stringify(report.errors)); delete report.failure; report.complete = true; save();
})().catch(e => { console.error(e.stack); process.exitCode = 1; }).finally(async () => { await browser?.close(); await server?.stop(); save(); });
