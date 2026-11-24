// Disposable public Laravel preview. No .env, development storage or database is used.
const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const project = path.resolve(__dirname, '../..');
const privateRoot = path.join(project, 'storage/app/private/qa/visual-gsap');
const frontPort = 4392;
const phpPort = 4393;
const origin = `http://127.0.0.1:${frontPort}`;
const types = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.ttf': 'font/ttf', '.woff': 'font/woff', '.woff2': 'font/woff2' };

async function assertPortFree(port) {
    await new Promise((resolve, reject) => {
        const probe = net.createServer();
        probe.once('error', () => reject(new Error(`Preview port ${port} is already in use. Existing servers were preserved.`)));
        probe.listen(port, '127.0.0.1', () => probe.close(resolve));
    });
}

async function prepareConfiguration() {
    await new Promise((resolve, reject) => {
        const command = spawn('C:/xampp/php/php.exe', [path.join(__dirname, 'cache-config.php')], { cwd: project, windowsHide: true, stdio: 'ignore' });
        command.once('error', reject);
        command.once('exit', code => code === 0 ? resolve() : reject(new Error('The private Laravel configuration failed validation. Run tools/visual-gsap/cache-config.php to inspect the error.')));
    });
}

async function verifyStandardConfiguration(environment) {
    await new Promise((resolve, reject) => {
        const command = spawn('C:/xampp/php/php.exe', [path.join(__dirname, 'cache-config.php'), '--verify-standard'], { cwd: project, env: environment, windowsHide: true, stdio: 'ignore' });
        command.once('error', reject);
        command.once('exit', code => code === 0 ? resolve() : reject(new Error('The standard Laravel bootstrap did not load the isolated private configuration.')));
    });
}

function phpReady() {
    return new Promise(resolve => {
        const request = http.get({ hostname: '127.0.0.1', port: phpPort, path: '/up', agent: false, headers: { Connection: 'close' } }, response => {
            response.resume();
            response.once('end', () => resolve(response.statusCode === 200));
            response.once('error', () => resolve(false));
        });
        request.setTimeout(3000, () => request.destroy());
        request.once('error', () => resolve(false));
    });
}

async function startServer(logFile = path.join(privateRoot, 'server.log')) {
    if (!fs.existsSync(path.join(privateRoot, 'prepared.json'))) throw new Error('Prepare the isolated catalog snapshot first.');
    if (!fs.existsSync(path.join(project, 'public/build/manifest.json'))) throw new Error('Build the experiment frontend before starting the preview.');
    await assertPortFree(frontPort);
    await assertPortFree(phpPort);
    await prepareConfiguration();
    const standardRouter = path.join(project, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
    // Laravel normalizes cache env paths relative to basePath. Its default
    // absolute prefixes do not include Windows drive letters, so use native
    // project-relative cache paths and verify their resolved absolute target.
    const privateRelative = 'storage/app/private/qa/visual-gsap';
    const environment = {
        ...process.env,
        APP_CONFIG_CACHE: `${privateRelative}/web-config.php`,
        APP_ROUTES_CACHE: `${privateRelative}/unused-routes.php`,
        APP_SERVICES_CACHE: `${privateRelative}/services.php`,
        APP_PACKAGES_CACHE: `${privateRelative}/packages.php`,
        LARAVEL_STORAGE_PATH: path.join(privateRoot, 'runtime'),
        APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: origin,
        DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(privateRoot, 'review.sqlite'), DB_URL: '',
        SESSION_DRIVER: 'file', SESSION_COOKIE: 'usbstock_visual_gsap_review', CACHE_STORE: 'file',
        MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync',
        SUPPLIER_FTP_ENABLED: 'false', SUPPLIER_SYNC_ENABLED: 'false', SUPPLIER_SYNC_AUTO_APPLY: 'false',
        SUPPLIER_TELEGRAM_ENABLED: 'false', TELEGRAM_ENABLED: 'false', COMMERCE_NOTIFY_EMAIL: '',
        CORPORATE_CONTACTS_ENABLED: 'false', CORPORATE_CONTACTS_KEY: '', CORPORATE_CONTACTS_RECORD_CLICKS: 'false',
    };
    await verifyStandardConfiguration(environment);
    const log = fs.openSync(logFile, 'a');
    const php = spawn('C:/xampp/php/php.exe', ['-S', `127.0.0.1:${phpPort}`, '-t', path.join(project, 'public'), standardRouter], { cwd: path.join(project, 'public'), env: environment, windowsHide: true, stdio: ['ignore', log, log] });
    let spawnError;
    php.once('error', error => { spawnError = error; });
    const publicRoot = fs.realpathSync(path.join(project, 'public')) + path.sep;
    const agent = new http.Agent({ keepAlive: false, maxSockets: 1 });
    const front = http.createServer((req, res) => {
        if (!['127.0.0.1', '::1'].includes(req.socket.remoteAddress) || req.headers.host !== `127.0.0.1:${frontPort}`) {
            res.writeHead(403).end();
            return;
        }
        let candidate;
        try {
            const pathname = decodeURIComponent(new URL(req.url, origin).pathname);
            if (pathname === '/admin' || pathname.startsWith('/admin/')) { res.writeHead(404).end(); return; }
            const resolved = fs.realpathSync(path.join(publicRoot, pathname));
            if (resolved.startsWith(publicRoot) && types[path.extname(resolved).toLowerCase()] && fs.statSync(resolved).isFile()) candidate = resolved;
        } catch { /* Every non-static request remains subject to Laravel routing. */ }
        if (candidate && ['GET', 'HEAD'].includes(req.method)) {
            res.writeHead(200, { 'Content-Type': types[path.extname(candidate).toLowerCase()], 'Content-Length': fs.statSync(candidate).size, 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-cache' });
            if (req.method === 'HEAD') res.end();
            else fs.createReadStream(candidate).on('error', () => res.destroy()).pipe(res);
            return;
        }
        const upstream = http.request({ hostname: '127.0.0.1', port: phpPort, method: req.method, path: req.url, agent, headers: { ...req.headers, host: `127.0.0.1:${frontPort}` } }, response => {
            res.writeHead(response.statusCode, response.headers);
            response.pipe(res);
            response.on('error', () => res.destroy());
        });
        upstream.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end('Isolated preview unavailable'); });
        req.pipe(upstream);
    });
    let stopped = false;
    const stop = async () => {
        if (stopped) return;
        stopped = true;
        front.closeAllConnections();
        if (front.listening) await new Promise(resolve => front.close(resolve));
        agent.destroy();
        if (php.exitCode === null) php.kill();
        fs.closeSync(log);
    };
    try {
        let ready = false;
        for (let i = 0; i < 40; i++) {
            if (spawnError || php.exitCode !== null) throw new Error('The isolated PHP server could not start. See its private log.');
            ready = await phpReady();
            if (ready) break;
            await new Promise(resolve => setTimeout(resolve, 250));
        }
        if (!ready) throw new Error('The isolated PHP preview did not become ready.');
        await new Promise((resolve, reject) => { front.once('error', reject); front.listen(frontPort, '127.0.0.1', resolve); });
        return { origin, stop };
    } catch (error) {
        await stop();
        throw error;
    }
}

module.exports = { startServer, origin, privateRoot };
if (require.main === module) {
    startServer().then(server => {
        console.log(`Isolated visual preview: ${server.origin} (Ctrl+C to stop)`);
        const stop = async () => { await server.stop(); process.exit(0); };
        process.once('SIGINT', stop);
        process.once('SIGTERM', stop);
    }).catch(error => { console.error(error.message); process.exitCode = 1; });
}
