// Loopback-only PHP QA server using the same native routing model as the experiment.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const origin = 'http://127.0.0.1:4398';
const qa = path.join(root, 'storage/app/private/qa/api-postmerge');

async function vacant(port) {
    await new Promise((resolve, reject) => {
        const server = net.createServer();
        server.once('error', () => reject(new Error('QA port unavailable')));
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}

async function startServer() {
    if (root.replaceAll('\\', '/') !== 'E:/proyectos/usbstock/site' || !fs.existsSync(path.join(qa, 'manifest.json'))) {
        throw new Error('Prepared main QA required');
    }
    await vacant(4398);
    const log = fs.openSync(path.join(qa, 'server.log'), 'a');
    const php = spawn('C:/xampp/php/php.exe', [
        '-S', '127.0.0.1:4398', '-t', path.join(root, 'public'),
        path.join(__dirname, 'postmerge-router.php'),
    ], { cwd: root, windowsHide: true, stdio: ['ignore', log, log] });
    let stopped = false;
    let launchFailed = false;
    php.once('error', () => { launchFailed = true; });
    const stop = async () => {
        if (stopped) return;
        stopped = true;
        if (php.exitCode === null && !php.killed) {
            let timer;
            const exited = new Promise(resolve => php.once('exit', resolve));
            php.kill();
            await Promise.race([exited, new Promise(resolve => { timer = setTimeout(resolve, 5000); })]);
            clearTimeout(timer);
        }
        fs.closeSync(log);
    };
    try {
        for (let attempt = 0; attempt < 20; attempt++) {
            if (launchFailed || php.exitCode !== null) throw new Error('Isolated PHP unavailable');
            let healthy = false;
            try { healthy = (await fetch(`${origin}/up`, { signal: AbortSignal.timeout(1000) })).ok; } catch {}
            if (healthy) return { origin, stop, phpPid: php.pid };
            await new Promise(resolve => setTimeout(resolve, 200));
        }
        throw new Error('Isolated PHP unavailable');
    } catch (error) {
        await stop();
        throw error;
    }
}

module.exports = { startServer, origin };
