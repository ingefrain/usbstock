const fs = require('node:fs');
const path = require('node:path');
const { startServer } = require('../commercial-review/server.cjs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin = 'http://127.0.0.1:4388';
const output = path.resolve('storage/app/private/qa/visual');
async function review(callback) {
    fs.mkdirSync(output, { recursive: true });
    const server = await startServer(path.join(output, 'server.log'));
    let browser;
    try {
        for (let i = 0; i < 40; i++) { try { if ((await fetch(origin + '/up')).ok) break; } catch {} if (i === 39) throw Error('Isolated Laravel unavailable'); await new Promise(r => setTimeout(r, 250)); }
        browser = await chromium.launch({ executablePath: process.env.EDGE_EXECUTABLE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
        await callback(browser);
    } finally { await browser?.close(); await server.stop(); }
}
async function login(page) {
    const access = JSON.parse(fs.readFileSync('storage/app/private/qa/commercial/access.json'));
    await page.goto(origin + '/admin/login');
    await page.locator('[name=email]').fill(access.email); await page.locator('[name=password]').fill(access.password);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(url => !url.pathname.includes('/login'));
}
async function shot(page, name, fullPage = false) {
    await page.evaluate(async () => { await document.fonts.ready; await Promise.all([...document.images].filter(i => i.getBoundingClientRect().top < innerHeight).map(i => i.decode().catch(() => {}))); });
    await page.screenshot({ path: path.join(output, name + '.png'), fullPage });
}
module.exports = { review, login, shot, origin, output };
