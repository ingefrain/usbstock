const { review, login, shot, origin, output } = require('./browser.cjs');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const colors = require('./contrast.cjs');
const only = process.env.FOLLOWUP_ONLY;
const report = { checks: [], errors: [], responsive: [], consecutive: {}, controls: [] };
async function check(name, fn) { if (only && !name.includes(only)) return; try { await fn(); report.checks.push({ name, passed: true }); console.log('PASS ' + name); } catch (e) { report.checks.push({ name, passed: false, error: e.message }); console.log('FAIL ' + name + ': ' + e.message.slice(0, 300)); } fs.writeFileSync(output + (only ? '/followup-extra.json' : '/followup.json'), JSON.stringify(report, null, 2)); }
review(async browser => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    await ctx.route('**/*', r => new URL(r.request().url()).origin === origin ? r.continue() : r.abort());
    const page = await ctx.newPage(); page.on('pageerror', e => report.errors.push(e.message));
    const go = async url => { const response = await page.goto(origin + url, { waitUntil: 'networkidle' }); assert.equal(response.status(),200); await page.evaluate(async () => { await document.fonts.ready; await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); }); await page.waitForFunction(() => getComputedStyle(document.documentElement).getPropertyValue('--usb-action').trim() === '#c51636'); };
    const add = async (slug, quantity, printing) => { await go('/producto/' + slug); await page.locator('[name=variant]:not(:disabled)').first().check(); await page.locator('[name=quantity]').fill(String(quantity)); await page.locator('[name=printing]').fill(printing); await page.getByRole('button', { name: 'Agregar a mi solicitud' }).click(); await page.waitForURL('**/solicitar-cotizacion'); await page.waitForLoadState('networkidle'); };
    await check('two consecutive public requests, clean fields, seller cookie and real loading', async () => {
        const run = Date.now().toString(36); report.consecutive.run = run;
        await add('usb017', 50, 'Impresión primera');
        await page.locator('[name=name]').fill('Visual consecutiva 1 ' + run); await page.locator('[name=email]').fill('first@example.test'); await page.locator('[name=phone]').fill('5550000001'); await page.locator('[name=message]').fill('Observación exclusiva de primera');
        await page.locator('[name=has_seller][value="1"]').check(); await page.locator('[name=seller_name]').fill(' Josè Luis ');
        await page.evaluate(() => document.addEventListener('submit', event => {
            if (event.target.action.endsWith('/solicitar-cotizacion')) sessionStorage.setItem('qa-submit-feedback', JSON.stringify({ busy: event.submitter?.getAttribute('aria-busy'), disabled: event.submitter?.disabled }));
        }));
        await page.getByRole('button', { name: 'Enviar solicitud' }).click(); await page.waitForURL('**/solicitud-recibida');
        report.consecutive.loading = await page.evaluate(() => JSON.parse(sessionStorage.getItem('qa-submit-feedback')));
        assert.equal(report.consecutive.loading.busy, 'true'); assert.equal(report.consecutive.loading.disabled, false);
        report.consecutive.firstReference = await page.locator('.receipt strong').innerText(); await go('/solicitar-cotizacion'); assert(page.url().endsWith('/solicitud-recibida'));
        await add('usb027', 75, ''); assert.equal(await page.locator('.selection-item').count(), 1); assert(!(await page.locator('.selection-item').innerText()).includes('USB017'));
        for (const name of ['name', 'email', 'phone', 'company', 'country', 'message']) assert.equal(await page.locator(`[name=${name}]`).inputValue(), '');
        assert.equal(await page.locator('.selection-edit [name=printing]').inputValue(), ''); assert.equal(await page.locator('[name=seller_name]').inputValue(), ' Josè Luis ');
        await page.locator('[name=name]').fill('Visual consecutiva 2 ' + run); await page.locator('[name=email]').fill('second@example.test'); await page.locator('[name=phone]').fill('5550000002'); await page.locator('[name=has_seller][value="0"]').check();
        await shot(page, 'after-consecutive-clean-form', true); await page.getByRole('button', { name: 'Enviar solicitud' }).click(); await page.waitForURL('**/solicitud-recibida');
        report.consecutive.secondReference = await page.locator('.receipt strong').innerText(); assert.notEqual(report.consecutive.firstReference, report.consecutive.secondReference); assert(!(await ctx.cookies()).some(c => c.name === 'usbstock_seller_preference'));
        await shot(page, 'after-confirmation');
    });
    await check('public action text, field boundaries and focus contrast', async () => {
        for (const url of ['/', '/catalogo', '/producto/usb017', '/contacto']) {
            await go(url);
            for (const element of await page.locator('.button:visible, .text-button:visible, .text-link:visible, .card-bottom a:visible, .search button:visible').all()) {
                const value = await element.evaluate(colors); report.controls.push({ url, type: 'text', ...value }); assert(value.ratio >= 4.5, value.text + ': ' + value.ratio);
            }
            for (const field of await page.locator('input:not([type=hidden]):not([type=radio]):not([type=checkbox]):visible, select:visible, textarea:visible').all()) {
                const value = await field.evaluate(colors); report.controls.push({ url, type: 'control', ...value }); assert(value.borderRatio >= 3, value.text + ' boundary: ' + value.borderRatio);
                await field.focus(); const focus = await field.evaluate(colors); assert(focus.outline !== 'none' && focus.outlineRatio >= 3);
            }
        }
    });
    await login(page);
    await check('table overflow fixed at 1440, 820 and 390; final admin captures', async () => {
        for (const width of [1440, 820, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const [name, url] of [['dashboard', '/admin'], ['documents', '/admin/documents'], ['products', '/admin/products'], ['clients', '/admin/clients'], ['sellers', '/admin/commercial-sellers']]) {
                await go(url); const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1); report.responsive.push({ name, width, overflow }); assert.equal(overflow, false, name + ' ' + width); await shot(page, `after-${name}-${width}`);
            }
        }
    });
    await check('single-file drop, multiple-file rejection and image preview', async () => {
        await page.setViewportSize({ width: 1440, height: 1000 }); await go('/admin/components/dropzone');
        await page.locator('[data-dropzone]').evaluate(el => { const transfer = new DataTransfer(); transfer.items.add(new File(['a'], 'a.txt', { type: 'text/plain' })); transfer.items.add(new File(['b'], 'b.txt', { type: 'text/plain' })); el.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: transfer })); });
        assert((await page.locator('[data-upload-error]').innerText()).includes('un solo archivo')); assert.equal(await page.locator('input[type=file]').evaluate(el => el.files.length), 0);
        await page.locator('input[type=file]').setInputFiles('resources/pdf/proposals/usb-metal.jpg'); await page.waitForFunction(() => document.querySelector('[data-preview]')?.naturalWidth > 0); await shot(page, 'after-dropzone-photo');
        await page.getByRole('button', { name: 'Quitar selección' }).click(); assert(await page.locator('[data-preview]').isHidden());
    });
    await check('QA static server keeps private paths inaccessible', async () => {
        for (const path of ['/.env', '/tools/commercial-review/bootstrap.php', '/storage/app/private/qa/commercial/access.json', '/%2e%2e/.env']) { const response = await ctx.request.get(origin + path); assert([403, 404].includes(response.status())); }
    });
    await check('no browser errors', async () => assert.deepEqual(report.errors, []));
    await ctx.close();
}).then(() => { process.exitCode = report.checks.some(c => !c.passed) ? 1 : 0; }).catch(e => { console.error(e.message); process.exitCode = 1; });
