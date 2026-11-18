// Loopback-only QA frontend. PHP handles every application route; Node streams
// public assets to avoid intermittent Windows php -S static connection resets.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const frontPort = 4388;
const phpPort = 4389;
const origin = `http://127.0.0.1:${frontPort}`;
const types = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.ttf': 'font/ttf', '.woff': 'font/woff', '.woff2': 'font/woff2' };
async function startServer(logFile) {
    for (const port of [frontPort, phpPort]) {
        try { await fetch(`http://127.0.0.1:${port}/up`, { signal: AbortSignal.timeout(1500) }); throw Error(`QA port ${port} is occupied.`); } catch (e) { if (e.message.includes('occupied')) throw e; }
    }
    const log = fs.openSync(logFile, 'a');
    const php = spawn('C:/xampp/php/php.exe', ['-S', `127.0.0.1:${phpPort}`, '-t', path.resolve('public'), path.resolve('tools/commercial-review/router.php')], { windowsHide: true, stdio: ['ignore', log, log] });
    const publicRoot = fs.realpathSync('public') + path.sep;
    const agent = new http.Agent({ keepAlive: false, maxSockets: 1 });
    const front = http.createServer((req, res) => {
        if (!['127.0.0.1', '::1'].includes(req.socket.remoteAddress)) { res.writeHead(403).end(); return; }
        let candidate;
        try {
            const pathname = decodeURIComponent(new URL(req.url, origin).pathname);
            const resolved = fs.realpathSync(path.join(publicRoot, pathname));
            if (resolved.startsWith(publicRoot) && types[path.extname(resolved).toLowerCase()] && fs.statSync(resolved).isFile()) candidate = resolved;
        } catch {}
        if (candidate && ['GET', 'HEAD'].includes(req.method)) {
            res.writeHead(200, { 'Content-Type': types[path.extname(candidate).toLowerCase()], 'Content-Length': fs.statSync(candidate).size, 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-cache' });
            if (req.method === 'HEAD') res.end(); else fs.createReadStream(candidate).on('error', () => res.destroy()).pipe(res);
            return;
        }
        const upstream = http.request({ hostname: '127.0.0.1', port: phpPort, method: req.method, path: req.url, agent, headers: { ...req.headers, host: `127.0.0.1:${frontPort}` } }, response => {
            res.writeHead(response.statusCode, response.headers);
            response.pipe(res);
            response.on('error', () => res.destroy());
        });
        upstream.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end('QA application unavailable'); });
        req.pipe(upstream);
    });
    const stop = async () => { front.closeAllConnections(); await new Promise(resolve => front.close(resolve)); agent.destroy(); php.kill(); fs.closeSync(log); };
    try {
        for (let i = 0; i < 40; i++) { try { if ((await fetch(`http://127.0.0.1:${phpPort}/up`)).ok) break; } catch {} if (i === 39) throw Error('Isolated PHP unavailable'); await new Promise(resolve => setTimeout(resolve, 250)); }
        await new Promise((resolve, reject) => { front.once('error', reject); front.listen(frontPort, '127.0.0.1', resolve); });
        return { stop, origin };
    } catch (e) { await stop(); throw e; }
}
module.exports = { startServer, origin };
if (require.main === module) {
    fs.mkdirSync('storage/app/private/qa/commercial', { recursive: true });
    startServer('storage/app/private/qa/commercial/server.log').then(server => {
        console.log('QA Laravel: ' + server.origin + ' (Ctrl+C para detener)');
        const stop = async () => { await server.stop(); process.exit(0); };
        process.once('SIGINT', stop); process.once('SIGTERM', stop);
    }).catch(e => { console.error(e.message); process.exitCode = 1; });
}
