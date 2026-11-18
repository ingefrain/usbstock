// Read-only QA against the actual local application. All non-GET/external requests are blocked.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const {startServer} = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/taxonomy-20261118/admin-repair');
const widths = [390,600,768,820,900,1024,1039,1100,1280,1440];
const quotePath = '/admin/quotes/01a1ed9e-eb26-72cf-8bce-af9b244d4a72';
(async () => {
    const server = await startServer(path.join(root,'browser.log'),true);
    const browser = await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--disable-renderer-backgrounding','--disable-background-timer-throttling','--disable-backgrounding-occluded-windows','--disable-features=CalculateNativeWinOcclusion']});
    const report = {checks:[],screenshots:[],layouts:[],errors:[],blocked:0}; let page;
    try {
        const context = await browser.newContext({storageState:JSON.parse(fs.readFileSync(path.join(root,'browser-access.json'))), viewport:{width:1039,height:1000}});
        await context.route('**/*', route => { if (new URL(route.request().url()).origin === server.origin && route.request().method() === 'GET') return route.continue(); report.blocked++; return route.abort(); });
        page = await context.newPage(); page.setDefaultTimeout(45000); page.on('pageerror', e => report.errors.push(e.message));
        async function go(url) { assert.equal((await page.goto(server.origin+url,{waitUntil:'domcontentloaded'})).status(),200,url); await page.evaluate(() => document.fonts.ready); }
        async function capture(name) { await page.waitForTimeout(150); await page.screenshot({path:path.join(root,name+'.png')}); report.screenshots.push(name+'.png'); }
        async function layout(name,width) {
            const result = await page.evaluate(() => {
                const side=document.querySelector('.sidebar'), work=document.querySelector('.workspace');
                return {width:innerWidth,scroll:document.documentElement.scrollWidth,sideWidth:side.getBoundingClientRect().width,sidePosition:getComputedStyle(side).position,sideVisibility:getComputedStyle(side).visibility,workX:work.getBoundingClientRect().x,workY:work.getBoundingClientRect().y+scrollY,open:document.querySelector('.admin-shell').classList.contains('drawer-open'),inert:work.inert};
            });
            assert(result.scroll<=width+1,`${name} overflow at ${width}: ${result.scroll}`);
            assert(!result.open && !result.inert,`${name} blocked workspace`);
            assert(result.workY<200,`${name} sidebar displaced the workspace`);
            if(width<=1050) { assert.equal(result.sidePosition,'fixed'); assert.equal(result.sideVisibility,'hidden'); assert.equal(result.workX,0); }
            else { assert(result.sideWidth<=258); assert.equal(result.workX,result.sideWidth); }
            report.layouts.push({module:name,...result});
        }
        await go(quotePath);
        const lines=page.locator('[data-quote-lines] [data-quote-line]');
        assert.deepEqual(await lines.locator('[data-price-proposed]').allTextContents(),['$5.40','$14.85']);
        assert.deepEqual(await lines.locator('[name$="[unit_price]"]').evaluateAll(el=>el.map(i=>i.value)),['0.00','0.00']);
        assert.equal(await page.locator('[data-pricing-apply-zero]').isEnabled(),true);
        for (const width of widths) {
            await page.setViewportSize({width,height:1000}); await page.evaluate(()=>scrollTo(0,0)); await layout('affected-quote',width); await capture('quote-'+width);
            await lines.first().scrollIntoViewIfNeeded(); await capture('quote-prices-'+width);
            if(width<=1050) {
                const toggle=page.locator('[data-menu-toggle]'); await toggle.click();
                assert(await page.locator('.workspace').evaluate(el=>el.inert));
                assert.equal(await page.locator('[data-drawer-dismiss]').evaluate(el=>el===document.activeElement),true);
                await page.keyboard.press('Shift+Tab'); assert(await page.locator('.sidebar').evaluate(el=>el.contains(document.activeElement)));
                await page.keyboard.press('Tab'); assert.equal(await page.locator('[data-drawer-dismiss]').evaluate(el=>el===document.activeElement),true);
                if(width===1039 || width===390) await capture('drawer-'+width);
                await page.keyboard.press('Escape'); assert.equal(await toggle.getAttribute('aria-expanded'),'false'); assert(await toggle.evaluate(el=>el===document.activeElement));
                await toggle.click(); await page.locator('[data-drawer-close]').click({position:{x:width-8,y:300}}); assert.equal(await toggle.getAttribute('aria-expanded'),'false');
            }
        }
        await page.setViewportSize({width:1039,height:1000}); await page.locator('[data-menu-toggle]').click();
        await page.setViewportSize({width:1440,height:1000}); await layout('drawer-resize-wide',1440);
        assert.equal(await page.locator('body').evaluate(el=>getComputedStyle(el).overflow),'visible');
        await page.setViewportSize({width:1039,height:1000}); await layout('drawer-resize-compact',1039);
        report.checks.push('drawer_focus_escape_scrim_and_resize');
        await page.locator('[data-pricing-apply-zero]').click();
        assert.deepEqual(await lines.locator('[name$="[unit_price]"]').evaluateAll(el=>el.map(i=>i.value)),['5.40','14.85']);
        await lines.first().scrollIntoViewIfNeeded(); await capture('quote-applied-unsaved-1039');
        await go(quotePath); assert.deepEqual(await lines.locator('[name$="[unit_price]"]').evaluateAll(el=>el.map(i=>i.value)),['0.00','0.00']);
        await lines.first().locator('[data-pricing-check]').click(); await page.waitForFunction(()=>document.querySelector('[data-pricing-status]').textContent.includes('$5.40'));
        report.checks.push('actual_draft_proposals_visible_api_valid_bulk_stages_without_save');
        const modules=['products','supplier-sync','quote-requests','quotes','clients','commercial-sellers','price-scales','commercial-settings','settings','documents','reports','users','roles'];
        let productForm;
        for(const module of modules) {
            await page.setViewportSize({width:1039,height:1000}); await go('/admin/'+module);
            if(module==='products') productForm=await page.locator('main a[href]').evaluateAll(a=>a.map(x=>new URL(x.href).pathname).find(p=>/^\/admin\/products\/[^/]+$/.test(p)));
            for(const width of widths) { await page.setViewportSize({width,height:1000}); await layout(module,width); if([390,1039,1440].includes(width)) await capture(module+'-'+width); }
        }
        for(const [name,url] of [['product-edit',productForm],['user-create','/admin/users/create']]) {
            assert(url); await go(url);
            for(const width of widths) { await page.setViewportSize({width,height:1000}); await layout(name,width); await capture(name+'-'+width); }
        }
        report.checks.push('thirteen_modules_and_long_forms_all_ten_widths');
        assert.equal(report.errors.length,0); assert.equal(report.blocked,0); report.success=true;
    } catch(error) { report.success=false; report.failure=error.message; process.exitCode=1; if(page) { report.path=new URL(page.url()).pathname; await page.screenshot({path:path.join(root,'failure.png')}).catch(()=>{}); } }
    finally { fs.writeFileSync(path.join(root,'browser.json'),JSON.stringify(report,null,2)); await browser.close(); await server.stop(); }
    console.log(JSON.stringify({success:report.success,checks:report.checks,layouts:report.layouts.length,screenshots:report.screenshots.length,errors:report.errors,failure:report.failure}));
})();
