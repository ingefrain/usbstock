// Real Laravel/Edge QA. Corporate responses and photographs are fulfilled locally.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer } = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/taxonomy-20261118/corporate-restoration');
const supplement = process.argv.includes('--supplement');
const report = supplement ? JSON.parse(fs.readFileSync(path.join(root, 'browser-report.json'))) : {groups: [], screenshots: [], geometry: [], externalBlocked: [], mockedPhotos: 0, pageErrors: []};
if (supplement && !report.success) throw Error('Supplement requires a successful full browser run.');
const photo = fs.readFileSync('public/brand/whatsapp-banner.webp');
const sellers = [
    {id: 901, name: 'Asesora QA', available: true, photo: 'https://grupotalento.com.mx/qa-portrait.webp', url: 'https://wa.me/12025550101?text=Prueba', ticket: 'synthetic-ticket'},
    {id: 902, name: 'Asesor QA', available: false, photo: 'https://grupotalento.com.mx/qa-portrait.webp', url: null, ticket: null},
    {id: 903, name: 'Sin foto QA', available: true, photo: null, url: 'https://wa.me/12025550103', ticket: null},
];
const overlap = (a, b) => a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
let currentPage;
async function check(name, work) { if (supplement && report.groups.includes(name)) return; await work(); report.groups.push(name); console.log('PASS ' + name); }
async function shot(page, name) { await page.waitForTimeout(250); await page.screenshot({path: path.join(root, name + '.png')}); if (!report.screenshots.includes(name)) report.screenshots.push(name); }
async function ready(page, url) { const r = await page.goto(url); assert.equal(r.status(), 200); await page.evaluate(() => document.fonts.ready); await page.waitForTimeout(700); }
async function clean(page) { assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Horizontal overflow'); }
async function open(page) { await page.locator('[data-contacts-open]').click(); await page.locator('.corporate-list li').first().waitFor(); await page.locator('[data-contacts]').evaluate(el => Promise.all(el.getAnimations().map(a => a.finished))); }
async function close(page) { await page.keyboard.press('Escape'); assert(await page.locator('[data-contacts]').isHidden()); assert(await page.locator('[data-contacts-open]').evaluate(el => el === document.activeElement)); }
(async () => {
    const server = await startServer(path.join(root, 'server.log'), true);
    const browser = await chromium.launch({executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true,
        args: ['--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows', '--disable-features=CalculateNativeWinOcclusion']});
    const origin = server.origin;
    async function context(options) {
        const c = await browser.newContext(options);
        await c.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
            if (url.href === sellers[0].photo) { report.mockedPhotos++; return route.fulfill({contentType: 'image/webp', body: photo}); }
            report.externalBlocked.push(url.origin); return route.abort();
        });
        await c.addInitScript(() => {
            // Exercise local click metrics without opening WhatsApp, even in a popup.
            document.addEventListener('click', e => { if (e.target.closest('a[href^="https://wa.me/"]')) e.preventDefault(); }, true);
            window.panelAnimations = [];
            const animate = Element.prototype.animate;
            Element.prototype.animate = function (frames, options) { if (this.matches('[data-contacts]')) window.panelAnimations.push(options); return animate.call(this, frames, options); };
        });
        c.on('page', page => page.on('pageerror', e => report.pageErrors.push(e.message)));
        return c;
    }
    try {
        for (const width of supplement ? [] : [1440, 820, 390]) {
            const c = await context({viewport: {width, height: 980}});
            const page = currentPage = await c.newPage(); page.setDefaultTimeout(15000);
            let rows = sellers, code = 200, lists = 0, clicks = 0;
            await ready(page, origin + '/');
            await check(`disabled-service-${width}`, async () => {
                await page.locator('[data-contacts-open]').click();
                await page.locator('[data-contacts-status]').filter({hasText: 'No pudimos'}).waitFor();
                assert.equal(await page.locator('.corporate-list li').count(), 0);
                await shot(page, `disabled-${width}`); await close(page);
            });
            await page.route('**/contactos-corporativos', route => { lists++; return route.fulfill({status: code, json: {sellers: rows}}); });
            await page.route('**/contactos-corporativos/clic', route => { clicks++; return route.fulfill({status: 503, json: {success: false}}); });
            await check(`original-panel-and-dock-${width}`, async () => {
                await shot(page, `closed-${width}`);
                const before = await page.locator('main').boundingBox();
                const beforeTab = await page.locator('.quote-tab').boundingBox();
                const beforeTrigger = await page.locator('.corporate-trigger').boundingBox();
                if (beforeTab) assert(!overlap(beforeTab, beforeTrigger));
                await open(page); await page.evaluate(() => document.fonts.ready);
                assert(await page.locator('[data-contacts-open]').isHidden());
                assert.equal(await page.locator('[data-contacts-open]').getAttribute('aria-expanded'), 'true');
                assert(await page.locator('[data-contacts-close]').evaluate(el => el === document.activeElement));
                assert.equal(await page.locator('dialog[open], [aria-modal="true"]:not([hidden])').count(), 0);
                assert.deepEqual(await page.locator('main').boundingBox(), before, 'Panel must not move page content');
                const box = await page.locator('[data-contacts]').boundingBox();
                const tab = await page.locator('.quote-tab').boundingBox();
                if (tab) assert(!overlap(box, tab), 'Panel blocks quotation dock');
                assert.deepEqual(tab, beforeTab, 'Opening must not move quotation dock');
                assert.equal(box.width, 260); assert(box.y >= 0 && box.y + box.height <= 980);
                const geometry = await page.evaluate(() => {
                    const bounds = selector => { const b = document.querySelector(selector).getBoundingClientRect(); return {width: b.width, height: b.height}; };
                    return {panel: bounds('[data-contacts]'), logo: bounds('[data-contacts] header img'), banner: bounds('.corporate-banner'), photo: bounds('.corporate-avatar'), font: getComputedStyle(document.querySelector('[data-contacts]')).fontFamily};
                });
                assert.equal(geometry.logo.width, 150); assert.equal(geometry.banner.width, geometry.banner.height); assert.equal(geometry.photo.width, 50);
                assert.equal(await page.locator('.corporate-list a').count(), 2);
                assert.equal(await page.locator('.corporate-status', {hasText: 'Offline'}).count(), 1);
                assert(await page.locator('.corporate-list img').first().evaluate(el => el.complete && el.naturalWidth > 0));
                report.geometry.push({width, ...geometry}); await clean(page); await shot(page, `panel-${width}`);
                await page.locator('[data-contacts]').screenshot({path: path.join(root, `panel-detail-${width}.png`)}); report.screenshots.push(`panel-detail-${width}`);
                await page.locator('[data-contacts-close]').focus(); await page.keyboard.press('Shift+Tab');
                assert(await page.evaluate(() => !document.querySelector('[data-contacts]').contains(document.activeElement)), 'Panel traps keyboard focus');
                await page.mouse.move(60, 800); await page.mouse.wheel(0, 450); await page.waitForTimeout(250);
                assert(await page.evaluate(() => scrollY > 100), 'Page scroll locked');
                await close(page); await page.evaluate(() => scrollTo(0, 0));
                await open(page); const count = lists;
                await page.locator('[data-contacts-open]').evaluate(el => { el.click(); el.click(); }); assert.equal(lists, count, 'Duplicate opening');
                await page.locator('[data-contacts-close]').click(); assert(await page.locator('[data-contacts-open]').evaluate(el => el === document.activeElement));
            });
            await check(`long-list-${width}`, async () => {
                rows = Array.from({length: 30}, (_, i) => ({...sellers[i % 3], id: 1000 + i, name: `Simulado QA ${i + 1}`}));
                await open(page);
                assert(await page.locator('.corporate-body').evaluate(el => el.scrollHeight > el.clientHeight));
                await shot(page, `long-list-${width}`);
                await page.locator('.corporate-list li').last().scrollIntoViewIfNeeded();
                assert(await page.locator('.corporate-body').evaluate(el => el.scrollTop > 0)); await clean(page); await shot(page, `long-list-end-${width}`); await close(page); rows = sellers;
            });
            await check(`navigation-without-arrows-${width}`, async () => {
                await page.evaluate(() => scrollTo({top: 0, behavior: 'instant'}));
                await open(page);
                if (width < 1100) await page.locator('.mobile-menu').click();
                const nav = page.locator(width < 1100 ? '.category-nav-mobile' : '.category-nav-desktop');
                const summary = nav.locator('summary').first();
                assert.equal(await nav.locator('summary svg').count(), 0);
                assert(!(await summary.innerText()).includes('⌄'));
                assert.equal(await summary.evaluate(el => getComputedStyle(el, '::marker').content), '""');
                if (width === 1440) await summary.hover(); else await summary.click();
                await page.waitForFunction(selector => document.querySelector(selector).getAttribute('aria-expanded') === 'true', (width < 1100 ? '.category-nav-mobile' : '.category-nav-desktop') + ' summary');
                assert(await page.locator('[data-contacts]').isHidden(), 'Contacts yields to navigation');
                if (width < 1100) assert((await summary.innerText()).includes('Cerrar'));
                await shot(page, `menu-${width}`); await summary.focus(); await page.keyboard.press('Escape');
                await page.waitForFunction(selector => document.querySelector(selector).getAttribute('aria-expanded') === 'false', (width < 1100 ? '.category-nav-mobile' : '.category-nav-desktop') + ' summary');
                await page.keyboard.press('Enter');
                await nav.getByRole('link', {name: 'Metálicas'}).click(); await page.waitForURL('**/catalogo/categoria/**');
                assert((await page.locator('.catalog-context').innerText()).includes('Metálicas'));
                assert(await page.locator('.category-nav [aria-current="page"]').count() > 0);
                await clean(page);
            });
            await check(`cart-and-critical-input-${width}`, async () => {
                await ready(page, origin + '/catalogo?q=USB017');
                await page.locator('.product-card h3 a').first().click(); await page.waitForURL('**/producto/**');
                await page.locator('[name="variant"]').first().check();
                const location = page.url(); await page.locator('[data-cart-action] button').click();
                await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent === '1'); assert.equal(page.url(), location);
                await page.locator('h1').click(); await open(page); await shot(page, `cart-with-panel-${width}`);
                assert(await page.locator('[data-cart-feedback]').isHidden(), 'Transient cart notice covers contacts');
                assert(!overlap(await page.locator('[data-contacts]').boundingBox(), await page.locator('.quote-tab').boundingBox()));
                await page.locator('[name="quantity"]').focus(); assert(await page.locator('[data-contacts]').isHidden());
                assert(await page.locator('[name="quantity"]').evaluate(el => el === document.activeElement));
                await page.locator('h1').click(); await open(page);
                const destination = await page.locator('.quote-tab').getAttribute('href');
                await page.locator('.quote-tab').click(); await page.waitForURL(destination);
                assert.equal(await page.locator('[data-cart-count]').first().innerText(), '1');
                await shot(page, `cart-accessible-${width}`);
            });
            if (width === 390) {
                await check('empty-error-missing-photo-invalid-url', async () => {
                    await ready(page, origin + '/'); rows = [];
                    await page.locator('[data-contacts-open]').click(); await page.getByText('No hay asesores disponibles por el momento.').waitFor(); await shot(page, 'empty'); await close(page);
                    code = 503; await page.locator('[data-contacts-open]').click(); await page.locator('[data-contacts-status]').filter({hasText: 'No pudimos'}).waitFor(); await shot(page, 'error');
                    code = 200; rows = [{...sellers[0], photo: 'https://grupotalento.com.mx/missing-qa.jpg', url: 'javascript:alert(1)'}];
                    await page.locator('[data-contacts-retry]').click(); await page.locator('.corporate-list li').waitFor();
                    await page.waitForFunction(() => document.querySelectorAll('.corporate-list img').length === 0);
                    assert.equal(await page.locator('.corporate-list a').count(), 0); assert.equal(await page.locator('.corporate-status').innerText(), 'Offline');
                    await shot(page, 'missing-photo-invalid-url'); await close(page);
                });
                await check('simulated-metrics-failure-refresh-deduplication', async () => {
                    rows = sellers; await open(page); const count = lists;
                    const refreshed = page.waitForResponse(r => r.url().endsWith('/contactos-corporativos') && r.request().method() === 'GET');
                    await page.locator('[data-contact-id="901"]').click(); await refreshed;
                    await page.waitForFunction(() => document.querySelector('[data-contacts-status]').textContent.includes('actual'));
                    assert(lists > count); await page.locator('[data-contact-id="901"]').click(); await page.waitForTimeout(200); assert.equal(clicks, 1);
                    await close(page); await open(page); await page.locator('[data-contact-id="901"]').click(); await page.waitForTimeout(200); assert.equal(clicks, 1);
                    assert(!(await c.cookies()).some(cookie => /seller|vendedor/i.test(cookie.name))); await close(page);
                });
                await check('short-viewport-and-dialog-coexistence', async () => {
                    await page.setViewportSize({width: 390, height: 500}); await open(page);
                    assert(await page.locator('.corporate-body').evaluate(el => el.clientHeight >= 100)); await clean(page); await shot(page, 'short-viewport');
                    await page.evaluate(() => { const d = document.createElement('dialog'); d.innerHTML = '<button>Cerrar prueba</button>'; document.body.append(d); d.showModal(); });
                    await page.waitForFunction(() => document.querySelector('[data-contacts]').hidden); await page.evaluate(() => document.querySelector('dialog').remove());
                });
            }
            await c.close();
        }
        const c = await context({viewport: {width: 1440, height: 980}, reducedMotion: 'reduce'});
        const page = currentPage = await c.newPage();
        await page.route('**/contactos-corporativos', route => route.fulfill({json: {sellers}}));
        if (supplement) await ready(page, origin + '/');
        await check('reduced-motion', async () => { await ready(page, origin + '/'); await open(page); assert.equal(await page.evaluate(() => panelAnimations.length), 0); await close(page); });
        await check('text-contrast-and-accessible-controls', async () => {
            await open(page);
            report.contrast = await page.evaluate(() => {
                const result = {};
                for (const selector of ['.corporate-role', '.corporate-list strong', '.is-available .corporate-status', '.corporate-list li:not(.is-available) .corporate-status']) {
                    const rgb = getComputedStyle(document.querySelector(selector)).color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
                    const luminance = rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
                    result[selector] = Number((1.05 / (luminance + .05)).toFixed(2));
                }
                return result;
            });
            assert(Object.values(report.contrast).every(ratio => ratio >= 4.5));
            assert(await page.getByRole('region', {name: 'Ejecutivos de ventas por WhatsApp'}).isVisible());
            assert((await page.getByRole('button', {name: 'Cerrar contactos'}).boundingBox()).height >= 44);
            assert((await page.locator('.corporate-seller').first().boundingBox()).height >= 44);
            assert.equal(await page.locator('[inert], [aria-modal="true"]:not([hidden])').count(), 0);
            await close(page);
        });
        await check('navigation-captures-with-visible-header', async () => {
            for (const width of [1440, 820, 390]) {
                await page.setViewportSize({width, height: 980}); await ready(page, origin + '/');
                if (width < 1100) await page.locator('.mobile-menu').click();
                const summary = page.locator(width < 1100 ? '.category-nav-mobile summary' : '.category-nav-desktop summary').first();
                if (width < 1100) await summary.click(); else await summary.hover();
                await page.evaluate(() => { scrollTo({top: 0, behavior: 'instant'}); document.querySelector('.mobile-nav').scrollTop = 0; });
                await page.waitForTimeout(200); assert.equal(await page.evaluate(() => scrollY), 0);
                await clean(page); await shot(page, `menu-${width}`);
            }
        });
        // Independent static reconstruction of the reviewed legacy HTML/CSS, never executing legacy PHP/JS.
        await check('legacy-static-reference', async () => {
            const face = photo.toString('base64'), logo = fs.readFileSync('public/brand/logo.webp').toString('base64');
            const font = fs.readFileSync('public/brand/fonts/montserrat-variable.ttf').toString('base64');
            const html = `<!doctype html><html lang="es"><meta charset="utf-8"><style>@font-face{font-family:Montserrat;src:url(data:font/ttf;base64,${font})}*{box-sizing:border-box}body{margin:0;background:#f3f3f3;font:16px/1.5 Montserrat,sans-serif}#whatsselector{position:fixed;background:white;border:1px solid #666;border-radius:5px;bottom:0;box-shadow:0 0 15px rgba(0,0,0,.8);height:auto;overflow:hidden;right:5px;width:260px}.head{padding:10px 0;text-align:center;position:relative}.head img{display:block;width:150px;margin:auto}.close{position:absolute;top:10px;right:10px;border:0;background:#f8f8f8;border-radius:50%;width:36px;height:36px;color:#666;font-size:23px}.banner{display:block;width:100%}.whats-ejecutivo{border-bottom:1px solid #999;padding:10px;display:flex;align-items:center;gap:15px}.whats-ejecutivo:last-child{border:0}.avatar{display:block;width:50px;height:50px;border-radius:50%;object-fit:cover;background:#eee}.whats-cargo{font-size:.7em;color:#999}.whats-nombre{color:#333;font-size:1em;font-weight:600}.whats-estado{font-size:.7em;color:#05a884}.offline{color:#aaa}</style><div id="whatsselector"><div class="head"><img src="data:image/webp;base64,${logo}" alt="USB Stock"><button class="close">×</button></div><img class="banner" src="data:image/webp;base64,${face}" alt="Envíanos un WhatsApp">${sellers.map((s, i) => `<div class="whats-ejecutivo">${i < 2 ? `<img class="avatar" src="data:image/webp;base64,${face}" alt="">` : '<span class="avatar"></span>'}<div><div class="whats-cargo">ventas</div><div class="whats-nombre">${s.name}</div><div class="whats-estado ${i === 1 ? 'offline' : ''}">${i === 1 ? '⊘ Offline' : '◉ Online'}</div></div></div>`).join('')}</div></html>`;
            fs.writeFileSync(path.join(root, 'reference-static.html'), html);
            await page.setContent(html); await page.evaluate(() => document.fonts.ready);
            await page.locator('#whatsselector').screenshot({path: path.join(root, 'reference-static.png')}); report.screenshots.push('reference-static');
            const b = await page.locator('#whatsselector').boundingBox(); assert.equal(b.width, report.geometry[0].panel.width);
            assert(Math.abs(b.height - report.geometry[0].panel.height) < 5, 'Legacy overall proportions differ');
        });
        await c.close(); assert.equal(report.pageErrors.length, 0); report.success = true;
    } catch (error) {
        report.success = false; report.error = error.stack; console.error(error);
        if (currentPage && !currentPage.isClosed()) await currentPage.screenshot({path: path.join(root, 'failure.png')}).catch(() => {});
        process.exitCode = 1;
    } finally {
        fs.writeFileSync(path.join(root, 'browser-report.json'), JSON.stringify(report, null, 2));
        fs.writeFileSync(path.join(root, 'gallery.html'), `<!doctype html><html lang="es"><meta charset="utf-8"><title>Selector corporativo — QA local</title><style>body{font:16px system-ui;background:#eee;margin:24px}section{display:flex;flex-wrap:wrap;gap:24px}figure{margin:0}img{max-width:100%;max-height:700px;object-fit:contain;object-position:top;display:block}figcaption{padding:12px}</style><h1>Selector corporativo — QA local</h1><p>Laravel real; vendedores simulados, fotos de prueba reutilizan el banner. Ningún contacto externo. La referencia es una reconstrucción estática del HTML/CSS revisado, no una captura de producción.</p><h2>Comparación del componente</h2><section><figure><img src="reference-static.png"><figcaption>Referencia heredada estática</figcaption></figure><figure><img src="panel-detail-1440.png"><figcaption>Laravel — escritorio</figcaption></figure><figure><img src="panel-detail-390.png"><figcaption>Laravel — móvil</figcaption></figure></section><h2>Escenarios</h2><section>${report.screenshots.map(name => `<figure><a href="${name}.png"><img src="${name}.png" loading="lazy" alt="${name}"></a><figcaption>${name}</figcaption></figure>`).join('')}</section></html>`);
        await browser.close(); await server.stop();
    }
})();
