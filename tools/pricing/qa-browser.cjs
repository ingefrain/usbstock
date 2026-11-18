// UI mutations are confined to qa-bootstrap.php's private SQLite instance.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer } = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/taxonomy-20261118/corporate-pricing/qa');
(async () => {
    const server = await startServer(path.join(root, 'browser.log'), false, 'tools/pricing/qa-router.php');
    const browser = await chromium.launch({executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true,
        args: ['--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows', '--disable-features=CalculateNativeWinOcclusion']});
    const report = {database: 'isolated_sqlite', checks: [], screenshots: [], pageErrors: [], blocked: 0};
    let page;
    try {
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === server.origin) return route.continue();
            report.blocked++; return route.abort();
        });
        page = await context.newPage(); page.setDefaultTimeout(45000);
        page.on('pageerror', e => report.pageErrors.push(e.message));
        const access = JSON.parse(fs.readFileSync(path.join(root, 'access.json')));
        assert.equal((await page.goto(server.origin + '/admin/login', {waitUntil: 'domcontentloaded'})).status(), 200);
        await page.locator('[name=email]').fill(access.email);
        await page.locator('[name=password]').fill(access.password);
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type=submit]').click()]);
        const capture = async name => {
            await page.evaluate(() => document.fonts.ready); await page.waitForTimeout(400);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            const file = name + '.png'; await page.screenshot({path: path.join(root, file)}); report.screenshots.push(file);
        };
        await page.goto(server.origin + '/admin/price-scales');
        await page.locator('#cost').fill('70'); await page.locator('#quantity').fill('50');
        await Promise.all([page.waitForURL('**cost=70**'), page.getByRole('button', {name: 'Simular sin guardar'}).click()]);
        assert.match(await page.locator('.notice').innerText(), /94\.50/);
        report.checks.push('simulation_variant_cost_70_quantity_50_price_94.50');
        for (const width of [1440, 820, 390]) { await page.setViewportSize({width, height: 1000}); await capture('scales-' + width); }
        await page.setViewportSize({width: 1440, height: 1000});
        const scale = page.locator('tr').filter({hasText: 'Escala heredada 2'});
        const toggle = scale.getByRole('switch');
        for (const expected of ['false', 'true']) {
            await toggle.click(); await page.waitForFunction(({expected}) => [...document.querySelectorAll('tr')].find(r => r.textContent.includes('Escala heredada 2')).querySelector('[role=switch]').getAttribute('aria-checked') === expected, {expected});
        }
        report.checks.push('repeat_toggle_updates_revision');
        await scale.getByRole('link', {name: 'Historial'}).click();
        assert.match(await page.locator('main').innerText(), /pricing\.scale_saved/);
        await capture('scale-history');
        await page.goto(server.origin + '/admin/price-scales');
        if (await page.locator('tr').filter({hasText: 'Escala de revisión aislada'}).count() === 0) {
        await page.goto(server.origin + '/admin/price-scales/create');
        await page.locator('#name').fill('Escala de revisión aislada');
        await page.locator('#increment').fill('10'); await page.locator('#lower').fill('4000'); await page.locator('#upper').fill('5000');
        await page.locator('#source_reference').fill('Fixture de interfaz: regla inactiva, sin uso comercial.');
        await Promise.all([page.waitForURL('**/price-scales'), page.getByRole('button', {name: 'Guardar escala'}).click()]);
        }
        const inactive = page.locator('tr').filter({hasText: 'Escala de revisión aislada'});
        const rejection = page.waitForResponse(r => ['POST', 'PATCH'].includes(r.request().method()) && r.url().includes('/status'));
        await inactive.getByRole('switch').click(); assert.equal((await rejection).status(), 422);
        assert.equal(await inactive.getByRole('switch').getAttribute('aria-checked'), 'false');
        report.checks.push('inactive_creation_overlap_activation_rejected');
        const prepared = JSON.parse(fs.readFileSync(path.join(root, 'prepared.json')));
        await page.goto(server.origin + '/admin/quote-requests');
        const request = page.locator('#request-' + prepared.request_uuid);
        const convert = request.getByRole('button', {name: 'Crear borrador para costos'});
        if (await convert.count()) {
            await Promise.all([page.waitForURL('**/quotes/*'), convert.click()]);
        } else {
            await request.getByRole('link', {name: /Continuar cotización/}).click();
        }
        const line = () => page.locator('[data-quote-line]').first();
        const price = () => line().locator('[name$="[unit_price]"]');
        const quantity = () => line().locator('[name$="[quantity]"]');
        const save = async () => { await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Guardar y calcular'}).click()]); };
        // A rerun restores only this isolated fixture through the same explicit UI action.
        if (await price().inputValue() !== '94.50' || await quantity().inputValue() !== '50') {
            await quantity().fill('50'); await line().locator('[data-pricing-check]').click();
            await line().locator('[data-pricing-use]').waitFor({state: 'visible'});
            await line().locator('[data-pricing-use]').click(); await save();
        }
        assert.equal(await price().inputValue(), '94.50');
        assert.match(await line().innerText(), /costo \$70\.00/);
        report.checks.push('conversion_uses_own_variant_cost');
        await quantity().fill('200'); await line().locator('[data-pricing-use]').waitFor({state: 'visible'});
        assert.match(await line().locator('[data-pricing-status]').innerText(), /90\.30/); assert.equal(await price().inputValue(), '94.50');
        await line().locator('[data-pricing-use]').click(); assert.equal(await price().inputValue(), '90.30');
        await save(); assert.equal(await price().inputValue(), '90.30');
        report.checks.push('explicit_recalculation_only');
        await price().fill('99'); await line().locator('[name$="[pricing_reason]"]').fill('Precio manual aprobado para esta prueba aislada');
        await save(); assert.equal(await price().inputValue(), '99.00');
        await quantity().fill('50'); await line().locator('[data-pricing-use]').waitFor({state: 'visible'});
        assert.equal(await price().inputValue(), '99.00'); await save(); assert.equal(await price().inputValue(), '99.00');
        report.checks.push('manual_price_retained_on_quantity_change');
        for (const width of [1440, 820, 390]) {
            await page.setViewportSize({width, height: 1000}); await line().scrollIntoViewIfNeeded(); await capture('quote-' + width);
        }
        assert.equal(report.blocked, 0); assert.equal(report.pageErrors.length, 0); report.success = true;
    } catch (error) {
        report.success = false; report.error = error.message; process.exitCode = 1;
        if (page) { report.page = new URL(page.url()).pathname; await page.screenshot({path: path.join(root, 'failure.png')}).catch(() => {}); }
    } finally {
        fs.writeFileSync(path.join(root, 'browser.json'), JSON.stringify(report, null, 2)); await browser.close(); await server.stop();
    }
    console.log(JSON.stringify(report));
})();
