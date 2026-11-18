const { review, login, shot, origin, output } = require('./browser.cjs');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const report = { checks: [], catalog: [], laboratory: [], errors: [] };
review(async browser => {
    const context = await browser.newContext({ viewport: { width: 390, height: 1000 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await context.addInitScript(() => {
        window.__shifts = [];
        new PerformanceObserver(list => { for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__shifts.push(entry.value); }).observe({ type: 'layout-shift', buffered: true });
    });
    const page = await context.newPage();
    page.on('pageerror', error => report.errors.push(error.message));
    for (const width of [390, 820, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.goto(origin + '/catalogo', { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const metrics = await page.evaluate(() => ({ width: innerWidth, cls: window.__shifts.reduce((sum, value) => sum + value, 0), overflow: document.documentElement.scrollWidth > innerWidth + 1, open: document.querySelector('#catalog-filters').open, cards: document.querySelectorAll('[data-catalog-grid] .product-card').length }));
        report.catalog.push(metrics);
        assert.equal(metrics.open, width > 600);
        assert.equal(metrics.cards, 24);
        assert.equal(metrics.overflow, false);
        assert(metrics.cls < .1, JSON.stringify(metrics));
        await shot(page, 'after-catalog-' + width);
    }
    await page.setViewportSize({ width: 390, height: 1000 });
    await page.goto(origin + '/catalogo', { waitUntil: 'networkidle' });
    await page.locator('[data-catalog-sentinel]').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelectorAll('[data-catalog-grid] .product-card').length >= 48);
    report.checks.push('24 → 48 productos, filtros móviles sin salto inicial');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(origin + '/', { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.getAnimations().every(a => a.playState !== 'running'));
    await shot(page, 'after-hero-1440');
    await login(page);
    for (const width of [1440, 820, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        const response = await page.goto(origin + '/admin/pdf-designs', { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200);
        assert.equal(await page.locator('.pdf-lab-card').count(), 3);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        await shot(page, 'after-pdf-lab-' + width, true);
        report.laboratory.push({ width, status: 200, overflow: false });
    }
    for (const key of ['b1', 'b2', 'b3']) {
        const href = await page.locator(`a[href*="/${key}?"][href*="format=pdf"]`).first().getAttribute('href');
        const response = await context.request.get(href);
        assert.equal(response.status(), 200);
        assert.equal((await response.body()).subarray(0, 5).toString(), '%PDF-');
        assert.match(response.headers()['cache-control'], /private/);
    }
    report.checks.push('Laboratorio real B1/B2/B3 responsive, PDF privados por Laravel');
    const nojs = await browser.newContext({ viewport: { width: 390, height: 1000 }, javaScriptEnabled: false });
    await nojs.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const plain = await nojs.newPage();
    await plain.goto(origin + '/catalogo', { waitUntil: 'networkidle' });
    assert(await plain.locator('#catalog-filters').getAttribute('open') !== null);
    assert.equal(await plain.locator('.product-card').count(), 24);
    await plain.locator('[data-catalog-next]').click();
    await plain.waitForLoadState('networkidle');
    assert.equal(await plain.locator('.product-card').count(), 24);
    assert(new URL(plain.url()).searchParams.has('cursor'));
    report.checks.push('Sin JavaScript: filtros abiertos y siguiente bloque nativo');
    assert.deepEqual(report.errors, []);
    await nojs.close();
    await context.close();
}).then(() => {
    fs.writeFileSync(output + '/final-browser.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
}).catch(error => { console.error(error); process.exitCode = 1; });
