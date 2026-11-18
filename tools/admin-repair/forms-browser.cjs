// Read-only form audit in the existing isolated SQLite application.
const fs = require('node:fs');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer } = require('../development-catalog/server.cjs');
const root = 'storage/app/private/taxonomy-20261118/admin-repair/qa';
const paths = ['users','users/create','roles','roles/create','permissions','organizations','organizations/create','locations','locations/create','catalogs','catalogs/create','documents','imports','imports/new/organizations','imports/new/locations','reports','reports/users','reports/organizations','system-audit/login-security','audit','notifications','settings','branding','profile','products','product-categories','quotes','quotes/create','clients','commercial-sellers','commercial-settings','price-scales','price-scales/create','quote-requests','supplier-sync','pdf-designs'];
if (process.argv.length > 2) paths.splice(0, paths.length, ...process.argv.slice(2));
(async () => {
    const server = await startServer(root+'/forms.log', false, 'tools/admin-repair/qa-router.php');
    let browser;
    const report = {checks:[],errors:[]};
    try {
        browser = await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless:true});
        const context = await browser.newContext();
        await context.route('**/*', route => new URL(route.request().url()).origin === server.origin ? route.continue() : route.abort());
        const page = await context.newPage();
        const access = JSON.parse(fs.readFileSync(root+'/access.json'));
        await page.goto(server.origin+'/admin/login', {waitUntil:'domcontentloaded'});
        await page.locator('[name=email]').fill(access.email);
        await page.locator('[name=password]').fill(access.password);
        await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('/login'),{waitUntil:'domcontentloaded'}),page.locator('button[type=submit]').click()]);
        await context.unroute('**/*');
        await context.route('**/*', route => new URL(route.request().url()).origin === server.origin && route.request().method() === 'GET' ? route.continue() : route.abort());
        page.on('pageerror', e=>report.errors.push(e.message));
        for (const path of paths) {
            const response = await page.goto(server.origin+'/admin/'+path,{waitUntil:'domcontentloaded'});
            if(response.status()!==200) {report.errors.push(path+': HTTP '+response.status());continue;}
            if (['users','roles','products','quotes','price-scales'].includes(path)) {
                const links=await page.locator('main a[href]').evaluateAll(nodes=>nodes.map(n=>new URL(n.href).pathname));
                const detail=links.find(url=>new RegExp('^/admin/'+path+'/[a-zA-Z0-9-]+(?:/edit)?$').test(url) && !url.endsWith('/create'));
                if(detail && !paths.includes(detail.slice(7))) paths.push(detail.slice(7));
            }
            await page.locator('details.record-row').evaluateAll(nodes=>nodes.forEach(n=>n.open=true));
            for(const width of [390,900,1024,1039,1100,1440]) {
                await page.setViewportSize({width,height:1000});
                const result = await page.evaluate(()=>{
                    const visible = e=>e.getBoundingClientRect().width>0 && e.getBoundingClientRect().height>0;
                    const rows = [...document.querySelectorAll('.filter-bar,.admin-search-filters,.request-filters')].map(f=>{
                        const fields=[...f.querySelectorAll('input:not([type=hidden]),select,.button')].filter(visible);
                        return fields.map(e=>({tag:e.tagName,top:e.getBoundingClientRect().top,h:e.getBoundingClientRect().height,bottom:e.getBoundingClientRect().bottom}));
                    });
                    const overflow = [...document.querySelectorAll('main form input,main form select,main form textarea,main form .button')].filter(visible).filter(e=>!e.closest('.table-wrap')).filter(e=>e.getBoundingClientRect().right>innerWidth+1 || e.getBoundingClientRect().left< -1).map(e=>e.id||e.name||e.tagName);
                    return {scroll:document.documentElement.scrollWidth,rows,overflow};
                });
                if(result.scroll>width+1 || result.overflow.length) report.errors.push({path,width,overflow:result.overflow,scroll:result.scroll});
                for(const row of result.rows) {
                    if(row.some(e=>e.h<43)) report.errors.push({path,width,shortControls:row});
                    for(const a of row) for(const b of row) if(Math.abs(a.top-b.top)<15 && Math.abs(a.bottom-b.bottom)>1) report.errors.push({path,width,misaligned:[a,b]});
                }
                report.checks.push({path,width,...result});
                if(['users/create','commercial-settings','products','price-scales','quotes/create'].includes(path) && [390,1440].includes(width)) await page.screenshot({path:root+'/forms-'+path.replaceAll('/','-')+'-'+width+'.png',fullPage:true});
            }
            console.log(path+' checked');
        }
    } finally {
        fs.writeFileSync(root+(process.argv.length > 2 ? '/forms-focused-report.json' : '/forms-report.json'),JSON.stringify(report,null,2));
        await browser?.close();await server.stop();
    }
    console.log(JSON.stringify({checks:report.checks.length,errors:report.errors}));
    if(report.errors.length)process.exitCode=1;
})().catch(e=>{console.error(e.message);process.exitCode=1;});
