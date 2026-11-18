const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer } = require('../development-catalog/server.cjs');
const root = path.resolve('storage/app/private/taxonomy-20261118/admin-repair');
(async () => {
    const server = await startServer(path.join(root, 'before-browser.log'), true);
    const browser = await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless:true, args:['--disable-renderer-backgrounding','--disable-background-timer-throttling','--disable-backgrounding-occluded-windows','--disable-features=CalculateNativeWinOcclusion']});
    try {
        const c = await browser.newContext({storageState: JSON.parse(fs.readFileSync(path.join(root,'browser-access.json'))), viewport:{width:1039,height:1000}});
        await c.route('**/*', r => new URL(r.request().url()).origin === server.origin && r.request().method() === 'GET' ? r.continue() : r.abort());
        const page = await c.newPage(); page.setDefaultNavigationTimeout(60000);
        await page.goto(server.origin + '/admin/quotes/01a1ed9e-eb26-72cf-8bce-af9b244d4a72'); await page.evaluate(() => document.fonts.ready);
        const result = await page.evaluate(() => {
            const sidebar = document.querySelector('.sidebar'), workspace = document.querySelector('.workspace');
            return {width:innerWidth, sidebar:sidebar.getBoundingClientRect().toJSON(), workspace:workspace.getBoundingClientRect().toJSON(), sidebarPosition:getComputedStyle(sidebar).position, grid:getComputedStyle(document.querySelector('.admin-shell')).gridTemplateColumns, prices:[...document.querySelectorAll('[name$="[unit_price]"]')].map(i => i.value)};
        });
        await page.screenshot({path:path.join(root,'before-1039.png')}); fs.writeFileSync(path.join(root,'before-layout.json'),JSON.stringify(result,null,2)); console.log(JSON.stringify(result));
    } finally { await browser.close(); await server.stop(); }
})();
