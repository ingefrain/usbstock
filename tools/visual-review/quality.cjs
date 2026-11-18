const { review, login, shot, origin, output } = require('./browser.cjs');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const only = process.env.QUALITY_ONLY;
const report = { checks: [], errors: [], contrast: [], motion: {}, cls: {} };
const save = () => fs.writeFileSync(output + (only ? '/quality-states.json' : '/quality.json'), JSON.stringify(report, null, 2));
async function check(name, fn) { if (only && !name.includes(only)) return; try { await fn(); report.checks.push({ name, passed: true }); console.log('PASS ' + name); } catch (e) { report.checks.push({ name, passed: false, error: e.message }); console.log('FAIL ' + name + ': ' + e.message.slice(0, 240)); } save(); }
const colors = require('./contrast.cjs');
review(async browser => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'no-preference' });
    await ctx.route('**/*', r => new URL(r.request().url()).origin === origin ? r.continue() : r.abort());
    await ctx.addInitScript(() => {
        window.__visual = { animations: [], shifts: [] };
        const original = Element.prototype.animate;
        Element.prototype.animate = function (frames, options) { const a = original.call(this, frames, options); window.__visual.animations.push({ class: this.className, frames, options, started: performance.now() }); return a; };
        new PerformanceObserver(list => { for (const e of list.getEntries()) if (!e.hadRecentInput) window.__visual.shifts.push({ value: e.value, time: e.startTime }); }).observe({ type: 'layout-shift', buffered: true });
    });
    const page = await ctx.newPage(); page.on('pageerror', e => report.errors.push(e.message)); page.on('requestfailed', r => { if (r.resourceType() === 'stylesheet') report.errors.push('Stylesheet failed: ' + r.url()); });
    const go = async url => { const r = await page.goto(origin + url, { waitUntil: 'networkidle' }); assert.equal(r.status(), 200); await page.evaluate(async () => { await document.fonts.ready; await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); }); await page.waitForFunction(() => getComputedStyle(document.documentElement).getPropertyValue('--usb-action').trim() === '#c51636'); };
    await check('real entrance, once-only reveal, hover and stable final position', async () => {
        await go('/'); await page.waitForTimeout(600);
        assert((await page.evaluate(() => __visual.animations.length)) > 0);
        await page.locator('.category-card').first().scrollIntoViewIfNeeded(); await page.waitForTimeout(70);
        const running = await page.evaluate(() => document.getAnimations().map(a => ({ state: a.playState, transform: getComputedStyle(a.effect.target).transform })));
        assert(running.some(a => a.state === 'running')); report.motion.running = running; await shot(page, 'motion-categories-running');
        await page.waitForTimeout(800); const first = await page.evaluate(() => __visual.animations.length);
        await page.evaluate(() => scrollTo(0, 0)); await page.waitForTimeout(100); await page.locator('.category-card').first().scrollIntoViewIfNeeded(); await page.waitForTimeout(650);
        assert.equal(await page.evaluate(() => __visual.animations.length), first);
        const card = page.locator('.category-card').first(); await card.hover(); await page.waitForTimeout(400);
        report.motion.hover = await card.evaluate(el => ({ transform: getComputedStyle(el).transform, image: getComputedStyle(el.querySelector('img')).transform }));
        assert.notEqual(report.motion.hover.image, 'none'); await shot(page, 'motion-category-hover');
        report.motion.entrances = await page.evaluate(() => __visual.animations);
        assert(report.motion.entrances.every(a => a.frames.every(f => Object.keys(f).every(k => ['opacity', 'transform'].includes(k)))));
        report.cls.home = await page.evaluate(() => __visual.shifts);
    });
    await check('menu, Escape and keyboard focus on mobile', async () => {
        await page.setViewportSize({ width: 390, height: 844 }); await go('/');
        const menu = page.locator('[data-mobile-menu-toggle]');
        const toggle = await menu.count() ? menu : page.locator('.mobile-menu');
        await toggle.click(); await page.waitForTimeout(70); assert(await page.locator('.mobile-nav').isVisible()); await shot(page, 'motion-menu-390');
        await page.keyboard.press('Escape'); assert(await page.locator('.mobile-nav').isHidden());
        await page.keyboard.press('Tab'); const focused = await page.locator(':focus').evaluate(colors); assert.notEqual(focused.outline, 'none');
    });
    await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
    await check('rendered action contrast across administrative modules', async () => {
        for (const url of ['/admin', '/admin/products', '/admin/quote-requests', '/admin/quotes', '/admin/clients', '/admin/commercial-sellers', '/admin/settings', '/admin/documents', '/admin/reports', '/admin/imports', '/admin/profile', '/admin/components/confirmations']) {
            await go(url);
            for (const button of await page.locator('.button:visible, .account-menu-button:visible').all()) report.contrast.push({ url, state: 'normal', ...await button.evaluate(colors) });
        }
        assert.deepEqual(report.contrast.filter(x => x.ratio < 4.5).map(x => ({ url: x.url, text: x.text, ratio: x.ratio })), []);
    });
    await check('normal hover focus active disabled loading states: all action variants', async () => {
        await go('/admin/components/utilities');
        for (const button of await page.locator('[data-button-specimens] button').all()) {
            for (const state of ['normal', 'hover', 'focus', 'active', 'disabled', 'disabled-hover', 'loading']) {
                await button.evaluate(el => { el.disabled = false; el.classList.remove('is-submitting'); el.removeAttribute('aria-busy'); el.blur(); }); await page.mouse.move(1, 1);
                if (state === 'hover') await button.hover();
                if (state === 'focus') { await page.keyboard.press('Tab'); await button.focus(); }
                if (state === 'active') { await button.hover(); await page.mouse.down(); }
                if (state.startsWith('disabled')) await button.evaluate(el => el.disabled = true); if (state === 'disabled-hover') await button.hover();
                if (state === 'loading') await button.evaluate(el => { el.classList.add('is-submitting'); el.setAttribute('aria-busy', 'true'); });
                await page.waitForTimeout(220); const value = await button.evaluate(colors); report.contrast.push({ url: '/admin/components/utilities', state, ...value });
                if (state === 'active') { await page.mouse.move(1, 1); await page.mouse.up(); }
                if(value.ratio < 4.5) await shot(page,'contrast-failure'); assert(value.ratio >= 4.5, `${value.text}/${state}: ${value.ratio}`);
                if (state === 'focus') assert(value.outline !== 'none' && value.outlineRatio >= 3);
            }
        }
        await go('/admin/components/utilities'); await shot(page, 'after-button-states');
    });
    await check('modal entry and dismissal, Escape, focus return and toggle feedback', async () => {
        await go('/admin/components/modal'); const opener = page.getByRole('button', { name: 'Abrir modal', exact: true }); await opener.click(); await page.waitForTimeout(60);
        assert(await page.locator('dialog').evaluate(el => el.getAnimations().length > 0)); await shot(page, 'motion-modal-open');
        await page.keyboard.press('Escape'); await page.waitForTimeout(30); assert(await page.locator('dialog').evaluate(el => el.getAnimations().length > 0));
        await page.waitForTimeout(250); assert(await page.locator('dialog').isHidden()); assert(await opener.evaluate(el => el === document.activeElement));
        await go('/admin/components/utilities'); const toggle = page.getByRole('switch', { name: 'Estado del ejemplo activo', exact: true }); const old = await toggle.getAttribute('aria-checked'); await toggle.click(); await page.waitForTimeout(800); assert.notEqual(await toggle.getAttribute('aria-checked'), old);
        await page.getByRole('button', { name: 'Éxito', exact: true }).click(); assert(await page.locator('[data-notify-stack]').isVisible()); await shot(page, 'after-toggle-notify');
    });
    await check('Dropzone keyboard, preview, clear, MIME and size rejection', async () => {
        await go('/admin/components/dropzone'); const input = page.locator('input[type=file]'); await input.focus(); assert(await input.evaluate(el => el === document.activeElement));
        await input.setInputFiles({ name: 'qa.txt', mimeType: 'text/plain', buffer: Buffer.from('Synthetic upload preview') }); assert(await page.locator('[data-selection]').isVisible()); await shot(page, 'after-dropzone-selection');
        await page.getByRole('button', { name: 'Quitar selección' }).click(); assert.equal(await input.evaluate(el => el.files.length), 0);
        await input.setInputFiles({ name: 'blocked.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('not executable') }); assert(await page.locator('[data-upload-error]').isVisible()); assert.equal(await input.evaluate(el => el.files.length), 0);
        await input.setInputFiles({ name: 'large.txt', mimeType: 'text/plain', buffer: Buffer.alloc(1024 * 1024 + 1) }); assert(await page.locator('[data-upload-error]').isVisible());
        await go('/admin/supplier-sync'); const csv = page.locator('input[type=file]'); assert.equal(await csv.getAttribute('multiple'), null);
        await csv.setInputFiles({ name: 'visual-review.csv', mimeType: 'text/csv', buffer: Buffer.from('SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nQA-VISUAL-A,50,Fixture visual sin aplicación,10.00') });
        await shot(page, 'after-supplier-dropzone'); await page.getByRole('button', { name: 'Revisar archivo', exact: true }).click(); await page.waitForLoadState('networkidle');
        assert(!page.url().endsWith('/supplier-sync')); await shot(page, 'after-supplier-preview-only');
    });
    await check('reduced motion cancels nonessential effects; content remains visible', async () => {
        await page.emulateMedia({ reducedMotion: 'reduce' }); await go('/'); await page.locator('.category-card').first().scrollIntoViewIfNeeded();
        assert.equal(await page.evaluate(() => __visual.animations.length), 0); assert.equal(await page.evaluate(() => document.getAnimations().length), 0);
        assert.equal(await page.locator('.category-card').first().evaluate(el => getComputedStyle(el).opacity), '1'); await shot(page, 'after-reduced-motion');
    });
    await ctx.close();
    await check('JavaScript failure keeps public and Dropzone content accessible', async () => {
        const nojs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } }); const p = await nojs.newPage();
        await p.goto(origin + '/'); assert(await p.getByRole('heading', { level: 1 }).isVisible()); assert.equal(await p.locator('[data-reveal]').first().evaluate(el => getComputedStyle(el).opacity), '1'); await shot(p, 'after-home-no-js', true);
        await login(p); await p.goto(origin + '/admin/supplier-sync'); assert(await p.locator('input[type=file]').isVisible()); await p.locator('input[type=file]').focus(); assert(await p.locator('input[type=file]').evaluate(el => el === document.activeElement)); await shot(p, 'after-dropzone-no-js'); await nojs.close();
    });
    await check('no JavaScript errors', async () => assert.deepEqual(report.errors, []));
    save();
}).then(() => { process.exitCode = report.checks.some(c => !c.passed) ? 1 : 0; }).catch(e => { console.error(e.message); save(); process.exitCode = 1; });
