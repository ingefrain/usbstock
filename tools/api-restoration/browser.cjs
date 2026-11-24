// Real Edge against the prepared, isolated API worktree. Never run against production.
// Evidence stays private. No quote request, WhatsApp message or click metric is submitted.
delete process.env.DEBUG;
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '../..');
const origin = 'http://127.0.0.1:8001';
const apiUrl = `${origin}/api/v1/cotizaciones/3000`;
const out = path.join(root, 'storage/app/private/qa/api-restoration/browser');
const manifestPath = path.join(root, 'storage/logs/api-local-fixture.json');
const report = { ok: false, groups: [], screenshots: [], metrics: [], javascriptErrors: [], network: { corporatePhotos: 0, blockedExternal: 0, blockedMutations: 0 }, cartCleaned: false };
let stage = 'preflight';
let browser, context, page;
let bearer;

function check(condition, code) {
    if (!condition) {
        const error = new Error('Browser validation failed.');
        error.qaCode = code;
        throw error;
    }
}

function setting(source, name) {
    const rows = source.split(/\r?\n/).filter(line => line.startsWith(`${name}=`));
    check(rows.length === 1, 'private_setting_missing_or_duplicate');
    let value = rows[0].slice(name.length + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) value = value.slice(1, -1);
    return value;
}

function writeReport() {
    fs.mkdirSync(out, { recursive: true });
    fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
}

async function group(name, action) {
    stage = name;
    report.progress = { stage: name, startedAt: new Date().toISOString() };
    writeReport();
    console.log(`START ${name}`);
    await action();
    report.groups.push(name);
    writeReport();
    console.log(`PASS ${name}`);
}

async function ready(target, url) {
    check(new URL(url).origin === origin, 'navigation_must_remain_local');
    const response = await target.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    check(response && response.status() === 200, 'local_page_must_return_200');
    const fontsReady = await target.evaluate(() => Promise.race([
        document.fonts.ready.then(() => true),
        new Promise(resolve => setTimeout(() => resolve(false), 8000)),
    ]));
    check(fontsReady, 'fonts_not_ready_within_bound');
    return response;
}

async function visibleLayout(target) {
    check(await target.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'horizontal_overflow');
    await target.waitForFunction(() => [...document.images].filter(image => {
        const box = image.getBoundingClientRect();
        return box.width > 0 && box.height > 0 && box.top < innerHeight && box.bottom > 0;
    }).every(image => image.complete), null, { timeout: 25000 });
    check(await target.locator('img').evaluateAll(images => images.every(image => {
        const box = image.getBoundingClientRect();
        return !(box.width > 0 && box.height > 0 && box.top < innerHeight && box.bottom > 0) || image.naturalWidth > 0;
    })), 'visible_image_failed');
}

async function shot(target, name) {
    await target.screenshot({ path: path.join(out, `${name}.png`), animations: 'disabled', timeout: 15000 });
    report.screenshots.push(`${name}.png`);
}

function instrument() {
    window.__apiRestorationMotion = [];
    const start = performance.now();
    let firstGsapAt = null;
    // Laravel may serve the JS after five seconds under cold asset load. Keep
    // observing until the actual GSAP start; never manufacture/replay motion.
    const sampler = setInterval(() => {
        const title = document.querySelector('[data-h-line] > span');
        const photo = document.querySelector('[data-h-img]');
        const now = performance.now();
        if (title && photo) {
            const gsapAttached = Boolean(title._gsap || photo._gsap);
            if (gsapAttached && firstGsapAt === null) firstGsapAt = now;
            const transform = getComputedStyle(title).transform;
            const translateY = transform === 'none' ? 0 : new DOMMatrixReadOnly(transform).m42;
            window.__apiRestorationMotion.push({ ms: Math.round(now - start), translateY, opacity: Number(getComputedStyle(photo).opacity), gsapAttached });
        }
        if ((firstGsapAt !== null && now - firstGsapAt > 4000) || now - start > 60000) clearInterval(sampler);
    }, 60);
}

async function clearCart() {
    if (!page || page.isClosed()) return;
    await ready(page, `${origin}/solicitar-cotizacion`);
    for (let i = 0; i < 12 && await page.locator('[data-selection-variant]').count(); i++) {
        const before = await page.locator('[data-selection-variant]').count();
        await page.locator('[data-cart-action="remove"] button').first().click();
        await page.waitForFunction(count => document.querySelectorAll('[data-selection-variant]').length < count, before);
    }
    check(await page.locator('[data-selection-variant]').count() === 0, 'cart_cleanup_failed');
    check((await page.locator('[data-cart-count]').first().textContent()).trim() === '0', 'cart_counter_not_empty');
    report.cartCleaned = true;
}

(async () => {
    try {
        const privateEnv = fs.readFileSync(path.join(root, '.env'), 'utf8');
        bearer = setting(privateEnv, 'USBSTOCK_API_BEARER_TOKEN');
        check(/^[A-Za-z0-9._~+/=\-]{32,512}$/.test(bearer), 'private_bearer_invalid');
        check(setting(privateEnv, 'CORPORATE_CONTACTS_ENABLED') === 'true', 'corporate_directory_not_enabled');
        check(setting(privateEnv, 'CORPORATE_CONTACTS_RECORD_CLICKS') === 'false', 'corporate_clicks_must_remain_disabled');
        const fixture = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
        check(fixture.public_id === 3000, 'private_fixture_requires_public_id_3000');
        check(/^[a-f0-9]{64}$/.test(fixture.pdf_sha256) && Number.isInteger(fixture.pdf_bytes) && fixture.pdf_bytes > 5, 'private_fixture_pdf_metadata_invalid');
        fs.mkdirSync(out, { recursive: true });
        browser = await chromium.launch({ executablePath: process.env.EDGE_BINARY || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--disable-background-timer-throttling', '--disable-renderer-backgrounding'] });
        context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1, serviceWorkers: 'block' });
        context.setDefaultTimeout(15000);
        context.setDefaultNavigationTimeout(30000);
        await context.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin === origin) {
                const cartAction = request.method() === 'POST' && (url.pathname === '/seleccion' || /^\/seleccion\/[0-9a-f-]{36}$/i.test(url.pathname));
                if (!['GET', 'HEAD'].includes(request.method()) && !cartAction) {
                    report.network.blockedMutations++;
                    return route.abort();
                }
                return route.continue();
            }
            if (request.method() === 'GET' && request.resourceType() === 'image' && url.origin === 'https://grupotalento.com.mx' && !url.username && !url.password && !url.search && !url.hash && /\.(jpg|jpeg|png|webp)$/i.test(url.pathname)) {
                report.network.corporatePhotos++;
                return route.continue();
            }
            report.network.blockedExternal++;
            return route.abort();
        });
        await context.addInitScript(instrument);
        page = await context.newPage();
        page.setDefaultTimeout(15000);
        page.on('pageerror', error => report.javascriptErrors.push({ stage, type: error.name || 'Error' }));

        await group('lovable-home-gsap-desktop-mobile', async () => {
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
                await ready(page, origin);
                check(await page.locator('h1').isVisible(), 'home_title_not_visible');
                await page.waitForFunction(() => {
                    const photo = document.querySelector('[data-h-img]');
                    const title = document.querySelector('[data-h-line] > span');
                    return photo && title && (photo._gsap || title._gsap) && getComputedStyle(photo).opacity === '1'
                        && [...document.querySelectorAll('[data-h-line] > span')].every(node => getComputedStyle(node).transform === 'none')
                        && [...document.querySelectorAll('[data-h-chip]')].every(node => getComputedStyle(node).opacity === '1');
                }, null, { timeout: 15000 });
                await visibleLayout(page);
                const samples = await page.evaluate(() => window.__apiRestorationMotion);
                const transitions = samples.filter(sample => sample.gsapAttached && (Math.abs(sample.translateY) > 0.1 || (sample.opacity > 0 && sample.opacity < 1)));
                report.metrics.push({ width, homeVisible: true, gsapSamples: samples.length, firstGsapMs: samples.find(sample => sample.gsapAttached)?.ms ?? null, motionObserved: transitions.length > 0, transitionSamples: transitions.slice(0, 8), finalSample: samples.at(-1) ?? null });
                writeReport();
                check(transitions.length > 0, 'gsap_motion_not_observed');
                await shot(page, `home-${width}`);
            }
        });

        let products;
        await group('catalog-block-search-category-continuation', async () => {
            await page.setViewportSize({ width: 1440, height: 1000 });
            await ready(page, `${origin}/catalogo`);
            check(await page.locator('[data-product-id]').count() === 24, 'catalog_first_block_not_24');
            products = await page.locator('.product-card h3 a').evaluateAll(nodes => nodes.map(node => node.href));
            await visibleLayout(page);
            await shot(page, 'catalog-1440');
            const key = (await page.locator('.card-meta span:last-child').first().textContent()).trim();
            await page.locator('#search').fill(key);
            await page.waitForFunction(value => new URL(location.href).searchParams.get('q') === value && [...document.querySelectorAll('.card-meta span:last-child')].some(node => node.textContent.trim() === value), key);
            check(await page.locator('[data-product-id]').count() > 0, 'catalog_search_empty');
            await shot(page, 'catalog-search');
            await ready(page, `${origin}/catalogo`);
            const category = page.locator('.lv-filter-pills a[href*="/catalogo/categoria/"]').first();
            check(await category.count() === 1, 'catalog_categories_missing');
            await category.click();
            await page.waitForURL('**/catalogo/categoria/**');
            check(await page.locator('[data-product-id]').count() > 0, 'catalog_category_empty');
            await shot(page, 'catalog-category');
            await ready(page, `${origin}/catalogo`);
            await page.locator('[data-catalog-next]').click();
            await page.waitForFunction(() => document.querySelectorAll('[data-product-id]').length > 24);
            const ids = await page.locator('[data-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.productId));
            check(new Set(ids).size === ids.length, 'catalog_duplicate_products');
            report.catalog = { initialCount: 24, afterContinuation: ids.length, unique: true, search: true, category: true };
            await shot(page, 'catalog-continuation');
        });

        await group('product-minimum-stock-ajax-cart-update', async () => {
            let variantIndex = -1;
            for (const product of products) {
                await ready(page, product);
                const choices = await page.locator('[name="variant"]').evaluateAll(nodes => nodes.map(node => ({ disabled: node.disabled, stock: Number(node.dataset.stock) })));
                variantIndex = choices.findIndex(choice => !choice.disabled && choice.stock > 50);
                if (variantIndex >= 0) break;
            }
            check(variantIndex >= 0, 'no_public_variant_with_stock_above_minimum');
            await page.locator('[name="variant"]').nth(variantIndex).check();
            const quantity = page.locator('[data-product-selection] [name="quantity"]');
            check(await quantity.getAttribute('min') === '50', 'product_minimum_changed');
            const maximum = Number(await quantity.getAttribute('max'));
            check(Number.isInteger(maximum) && maximum > 50, 'product_stock_maximum_invalid');
            await quantity.fill('49');
            check(await quantity.evaluate(node => node.checkValidity()) === false, 'product_accepts_below_minimum');
            await quantity.fill('50');
            const before = page.url();
            const timeOrigin = await page.evaluate(() => performance.timeOrigin);
            await visibleLayout(page);
            await shot(page, 'product-1440');
            await page.locator('[data-product-selection] button.quote-button').click();
            await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent.trim() === '1');
            check(page.url() === before && await page.evaluate(() => performance.timeOrigin) === timeOrigin, 'cart_add_was_not_ajax');
            await page.locator('.quote-nav').click();
            await page.waitForURL('**/solicitar-cotizacion');
            check(await page.locator('[data-selection-variant]').count() === 1, 'cart_item_missing');
            const edit = page.locator('.selection-edit [name="quantity"]');
            const updated = Math.min(75, Number(await edit.getAttribute('max')));
            check(updated > 50, 'cart_quantity_cannot_change');
            const cartTimeOrigin = await page.evaluate(() => performance.timeOrigin);
            await edit.fill(String(updated));
            await page.locator('.selection-edit button').click();
            await page.waitForFunction(value => document.querySelector('.selection-total strong').textContent.trim() === String(value), updated);
            check(await page.evaluate(() => performance.timeOrigin) === cartTimeOrigin, 'cart_update_was_not_ajax');
            await visibleLayout(page);
            await shot(page, 'cart-updated-1440');
            await page.setViewportSize({ width: 390, height: 844 });
            await visibleLayout(page);
            await shot(page, 'cart-updated-390');
            report.cart = { minimum: 50, addedQuantity: 50, updatedQuantity: updated, boundedByStock: updated <= maximum, ajaxAdd: true, ajaxUpdate: true, submittedRequest: false };
        });

        await group('real-corporate-floating-selector-without-clicks', async () => {
            await ready(page, origin);
            const responsePromise = page.waitForResponse(response => response.url() === `${origin}/contactos-corporativos` && response.request().method() === 'GET');
            await page.locator('[data-contacts-open]').click();
            const response = await responsePromise;
            check(response.status() === 200, 'corporate_local_endpoint_failed');
            const data = await response.json();
            check(data.success === true && Array.isArray(data.sellers) && data.sellers.length > 0, 'corporate_real_data_missing');
            check(data.sellers.every(seller => seller.ticket === null), 'corporate_click_ticket_exposed');
            check(data.sellers.every(seller => seller.photo === null || new URL(seller.photo).origin === 'https://grupotalento.com.mx'), 'corporate_photo_host_invalid');
            await page.waitForFunction(count => document.querySelectorAll('.corporate-seller').length === count, data.sellers.length);
            await page.waitForFunction(() => [...document.querySelectorAll('.corporate-avatar img')].every(image => image.complete));
            const photoCount = data.sellers.filter(seller => seller.photo !== null).length;
            const loadedPhotos = await page.locator('.corporate-avatar img').evaluateAll(images => images.filter(image => image.complete && image.naturalWidth > 0).length);
            check(loadedPhotos === photoCount, 'corporate_photo_failed_to_load');
            check(await page.locator('[data-contacts]').isVisible(), 'corporate_panel_not_visible');
            check(await page.locator('[data-contacts]').getAttribute('aria-modal') !== 'true', 'corporate_panel_must_be_nonmodal');
            await visibleLayout(page);
            await shot(page, 'whatsapp-real-390');
            report.corporate = { localStatus: response.status(), sellers: data.sellers.length, available: data.sellers.filter(seller => seller.available === true).length, photosLoaded: loadedPhotos, clickTicketsAbsent: true, clickedLinks: false };
            await page.keyboard.press('Escape');
            check(await page.locator('[data-contacts]').isHidden(), 'corporate_panel_failed_to_close');
            check(await page.locator('[data-contacts-open]').evaluate(node => node === document.activeElement), 'corporate_trigger_focus_not_restored');
        });

        await group('browser-bearer-api-3000-and-private-pdf', async () => {
            const apiPage = await context.newPage();
            try {
                // Header only for this exact local endpoint, never for assets, photos or document links.
                await apiPage.route(apiUrl, route => route.request().method() === 'GET'
                    ? route.continue({ headers: { ...route.request().headers(), authorization: `Bearer ${bearer}`, accept: 'application/json' } })
                    : route.abort());
                const response = await apiPage.goto(apiUrl, { waitUntil: 'load', timeout: 30000 });
                check(response && response.status() === 200, 'browser_quote_api_not_200');
                const payload = await response.json();
                check(payload.success === true && payload.data?.id === 3000, 'browser_quote_api_contract_failed');
                check(payload.data.sale_amount_available === true && payload.data.amounts?.currency === 'MXN', 'browser_quote_amounts_unavailable');
                check(['sale_amount', 'shipping', 'tax', 'total'].every(key => typeof payload.data.amounts[key] === 'number' && Number.isFinite(payload.data.amounts[key])), 'browser_quote_amount_types_invalid');
                check(payload.data.pdf?.available === true && typeof payload.data.pdf.url === 'string', 'browser_quote_pdf_unavailable');
                const documentUrl = new URL(payload.data.pdf.url);
                check(documentUrl.origin === origin && /^\/api\/v1\/documentos\/[A-Za-z0-9_-]+$/.test(documentUrl.pathname) && !documentUrl.search && !documentUrl.hash, 'document_reference_must_remain_local');
                const pdf = await context.request.get(documentUrl.href, { maxRedirects: 0, timeout: 30000 });
                check(pdf.status() === 200 && pdf.headers()['content-type']?.startsWith('application/pdf'), 'private_pdf_download_failed');
                const bytes = await pdf.body();
                const digest = crypto.createHash('sha256').update(bytes).digest('hex');
                check(bytes.subarray(0, 5).toString() === '%PDF-' && bytes.length === fixture.pdf_bytes && digest === fixture.pdf_sha256, 'private_pdf_integrity_mismatch');
                fs.writeFileSync(path.join(out, 'quote-3000.pdf'), bytes);
                // Replace the browser's raw JSON view before any screenshot; never capture its capability URL.
                const summary = { http_status: response.status(), id: payload.data.id, items: payload.data.items.length, sale_amount_available: true, currency: 'MXN', numeric_amounts: true, pdf_available: true, pdf_bytes: bytes.length, pdf_matches_private_fixture: true };
                await apiPage.evaluate(data => {
                    document.body.replaceChildren();
                    document.body.style.cssText = 'padding:32px;font:18px/1.6 monospace;background:#f6f8fb;color:#182c4c';
                    const heading = document.createElement('h1'); heading.textContent = 'API local · verificación';
                    const note = document.createElement('p'); note.textContent = 'Resumen de la respuesta real. Credenciales y referencia documental omitidas.';
                    const pre = document.createElement('pre'); pre.textContent = JSON.stringify(data, null, 2);
                    document.body.append(heading, note, pre);
                }, summary);
                await shot(apiPage, 'api-3000-sanitized');
                report.api = summary;
            } finally {
                await apiPage.close();
            }
        });
        check(report.javascriptErrors.length === 0, 'javascript_error_detected');
        check(report.network.blockedMutations === 0, 'unexpected_mutation_attempted');
        check(report.network.blockedExternal === 0, 'unexpected_external_request_attempted');
        report.ok = true;
    } catch (error) {
        report.failure = { stage, type: /^[A-Za-z]+$/.test(error.name || '') ? error.name : 'Error', code: error.qaCode || 'browser_operation_failed' };
        report.ok = false;
        if (page && !page.isClosed()) await shot(page, 'storefront-failure').catch(() => {});
        process.exitCode = 1;
    } finally {
        try { await clearCart(); } catch { report.cartCleaned = false; report.ok = false; report.cleanupFailure = 'cart_cleanup_failed'; process.exitCode = 1; }
        bearer = undefined;
        writeReport();
        await browser?.close();
        console.log(`RESULT ${report.ok ? 'PASS' : 'FAIL'}; private report: ${path.join(out, 'report.json')}`);
    }
})();
