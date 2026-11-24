// Read-only, unthrottled local Edge measurements against the isolated snapshot.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { startServer, privateRoot } = require('./server.cjs');
const output = path.join(privateRoot, 'performance.json');
const widths = [1440, 1039, 820, 390];
const priorPath = path.join(privateRoot, 'browser/report.json');
const prior = fs.existsSync(priorPath) ? JSON.parse(fs.readFileSync(priorPath)) : null;
const report = {
    createdUtc: new Date().toISOString(),
    conditions: {
        browser: 'Headless Microsoft Edge', rounds: 3, widths,
        viewportHeights: { desktop: 1000, mobile390: 844 }, deviceScaleFactor: 1,
        cpuOrNetworkThrottling: false, externalOriginsBlocked: true,
        freshContextPerNavigation: true, browserCache: 'Disabled by Playwright routing',
        server: 'New local server process; existing isolated snapshot and generated thumbnails retained',
        order: 'Round-robin widths, repeated three times; first request is marked separately',
        settle: 'Wait for load, fonts.ready, then 3000 ms and visible hero chips/image',
        mutations: 'No clicks, form submissions, inventory updates, or request creation',
    },
    priorBrowserHomeMetrics: prior?.metrics?.filter(metric => metric.route === 'home').map(metric => ({
        width: metric.width, lcp: metric.lcp, cls: metric.cls,
        sampledMotionFrames: metric.motion?.length || 0,
        firstMotionFrame: metric.motion?.[0] || null,
        lastMotionFrame: metric.motion?.at(-1) || null,
    })) || [],
    samples: [], errors: [], ok: false,
};

function instrument() {
    window.localPerformance = { cls: 0, lcp: 0, lcpCandidates: [], longTasks: [], motion: [], observerSupport: [] };
    const metrics = window.localPerformance;
    const describe = element => element ? { tag: element.tagName, className: typeof element.className === 'string' ? element.className : '', id: element.id || null } : null;
    for (const [type, consume] of [
        ['layout-shift', entry => { if (!entry.hadRecentInput) metrics.cls += entry.value; }],
        ['largest-contentful-paint', entry => {
            metrics.lcp = entry.startTime;
            metrics.lcpCandidates.push({ time: entry.startTime, renderTime: entry.renderTime, loadTime: entry.loadTime, size: entry.size, element: describe(entry.element), url: entry.url ? new URL(entry.url).pathname : null });
        }],
        ['longtask', entry => metrics.longTasks.push({ startTime: entry.startTime, duration: entry.duration, name: entry.name })],
    ]) {
        try { new PerformanceObserver(list => list.getEntries().forEach(consume)).observe({ type, buffered: true }); metrics.observerSupport.push(type); } catch {}
    }
    const sampler = setInterval(() => {
        const title = document.querySelector('[data-h-line] > span');
        const photo = document.querySelector('[data-h-img]');
        if (title && photo) {
            const style = getComputedStyle(photo);
            metrics.motion.push({ at: performance.now(), titleTransform: getComputedStyle(title).transform, photoOpacity: Number(style.opacity), photoClip: style.clipPath });
        }
        if (performance.now() > 15000) clearInterval(sampler);
    }, 50);
    window.stopLocalPerformanceSampling = () => clearInterval(sampler);
}

const round = value => Math.round(value * 100) / 100;
function distribution(values, precision = 2) {
    const sorted = [...values].sort((a, b) => a - b);
    const exact = value => Number(value.toFixed(precision));
    return { median: exact(sorted[Math.floor(sorted.length / 2)]), min: exact(sorted[0]), max: exact(sorted.at(-1)) };
}
function summarize(samples) {
    return Object.fromEntries(widths.map(width => {
        const selected = samples.filter(sample => sample.width === width);
        return [width, Object.fromEntries([
            ['lcpMs', selected.map(sample => sample.lcp)],
            ['cls', selected.map(sample => sample.cls)],
            ['ttfbMs', selected.map(sample => sample.navigation.responseStart)],
            ['documentResponseEndMs', selected.map(sample => sample.navigation.responseEnd)],
            ['dclMs', selected.map(sample => sample.navigation.domContentLoadedEventEnd)],
            ['loadMs', selected.map(sample => sample.navigation.loadEventEnd)],
            ['heroResponseEndMs', selected.map(sample => sample.heroResource?.responseEnd || 0)],
            ['heroDurationMs', selected.map(sample => sample.heroResource?.duration || 0)],
            ['lcpAfterHeroResponseMs', selected.map(sample => sample.lcp - (sample.heroResource?.responseEnd || 0))],
            ['lcpAfterDclMs', selected.map(sample => sample.lcp - sample.navigation.domContentLoadedEventEnd)],
        ].map(([name, values]) => [name, distribution(values, name === 'cls' ? 6 : 2)]))];
    }));
}

function interpret(samples, previousMetrics) {
    const assetDurations = extension => samples.flatMap(sample => sample.mainAssets).filter(asset => asset.path.endsWith(extension)).map(asset => asset.duration);
    return {
        previousMaximumLcpMs: Math.max(...previousMetrics.map(metric => metric.lcp)),
        repeatedMaximumLcpMs: Math.max(...samples.map(sample => sample.lcp)),
        maximumCls: Math.max(...samples.map(sample => sample.cls)),
        ttfbMs: distribution(samples.map(sample => sample.navigation.responseStart)),
        heroDownloadMs: distribution(samples.map(sample => sample.heroResource.duration)),
        javascriptDownloadMs: distribution(assetDurations('.js')),
        stylesheetDownloadMs: distribution(assetDurations('.css')),
        maximumLongTaskMs: Math.max(0, ...samples.flatMap(sample => sample.longTasks.map(task => task.duration))),
        finalLcpResourcePaths: [...new Set(samples.map(sample => sample.lcpCandidates.at(-1)?.url))],
        historicalOutlierCause: 'Indeterminate: the previous report contains no navigation/resource timings. The recorded 6860 ms result is retained, not replaced by these repetitions.',
        observedVariation: 'The largest variation in these samples occurs before the first HTML byte. Hero, JavaScript and CSS transfers remain short; this points to local backend/environment scheduling as a material variable, without distinguishing PHP work from host I/O or load.',
        animationContribution: 'LCP is the hero image in every sample. Some candidates precede DOMContentLoaded; others follow it while GSAP is entering. The image is downloaded before its final LCP time. LCP therefore is not equivalent to the end of the entry animation; motion timing is recorded separately.',
        scope: 'Twelve unthrottled local lab navigations, with browser cache disabled and server/OS caches retained. These measurements do not establish production performance or the unique cause of the historical outlier.',
    };
}

(async () => {
    const server = await startServer(path.join(privateRoot, 'performance-server.log'));
    let browser;
    try {
        browser = await chromium.launch({ executablePath: process.env.EDGE_BINARY || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, args: ['--disable-background-timer-throttling', '--disable-renderer-backgrounding'] });
        for (let iteration = 1; iteration <= 3; iteration++) {
            for (const width of widths) {
                const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1000 }, deviceScaleFactor: 1 });
                await context.route('**/*', route => new URL(route.request().url()).origin === server.origin ? route.continue() : route.abort());
                await context.addInitScript(instrument);
                const page = await context.newPage();
                page.on('pageerror', error => report.errors.push({ width, iteration, message: error.message }));
                const response = await page.goto(server.origin, { waitUntil: 'load', timeout: 90000 });
                assert.equal(response.status(), 200);
                await page.evaluate(() => document.fonts.ready);
                await page.waitForTimeout(3000);
                await page.waitForFunction(() => [...document.querySelectorAll('[data-h-img], [data-h-chip]')].every(element => getComputedStyle(element).opacity === '1'), null, { timeout: 15000 });
                const measured = await page.evaluate(() => {
                    window.stopLocalPerformanceSampling();
                    const metrics = window.localPerformance;
                    const navigation = performance.getEntriesByType('navigation')[0].toJSON();
                    const photo = document.querySelector('[data-h-img] img');
                    const resource = entry => ({
                        path: new URL(entry.name).pathname, initiatorType: entry.initiatorType,
                        startTime: entry.startTime, fetchStart: entry.fetchStart, requestStart: entry.requestStart,
                        responseStart: entry.responseStart, responseEnd: entry.responseEnd, duration: entry.duration,
                        transferSize: entry.transferSize, encodedBodySize: entry.encodedBodySize, decodedBodySize: entry.decodedBodySize,
                    });
                    const resources = performance.getEntriesByType('resource');
                    const hero = resources.find(entry => entry.name === photo.currentSrc);
                    const animated = metrics.motion.filter(frame => frame.photoOpacity < 0.999 || frame.photoClip !== 'none' || frame.titleTransform !== 'none');
                    const lastAnimated = animated.at(-1)?.at;
                    return {
                        ...metrics, navigation,
                        heroResource: hero ? resource(hero) : null,
                        mainAssets: resources.filter(entry => /\/build\/assets\/.*\.(css|js)$/.test(new URL(entry.name).pathname)).map(resource),
                        fontResources: resources.filter(entry => /\.(woff2?|ttf)$/.test(new URL(entry.name).pathname)).map(resource),
                        hero: { complete: photo.complete, naturalWidth: photo.naturalWidth, sourcePath: new URL(photo.currentSrc).pathname },
                        motionTiming: {
                            firstDomObservedAt: metrics.motion[0]?.at || null,
                            firstAnimatedAt: animated[0]?.at || null,
                            lastAnimatedAt: lastAnimated || null,
                            settledAt: lastAnimated ? metrics.motion.find(frame => frame.at > lastAnimated)?.at || null : null,
                            photoFirstVisibleAfterAnimationAt: animated[0] ? metrics.motion.find(frame => frame.at >= animated[0].at && frame.photoOpacity > 0.01)?.at || null : null,
                        },
                    };
                });
                assert(measured.hero.complete && measured.hero.naturalWidth > 0);
                assert(measured.lcp > 0);
                report.samples.push({ width, iteration, firstServerHomeRequest: report.samples.length === 0, ...measured });
                fs.writeFileSync(output, JSON.stringify(report, null, 2));
                console.log(JSON.stringify({ width, iteration, lcp: round(measured.lcp), cls: measured.cls, ttfb: round(measured.navigation.responseStart), dcl: round(measured.navigation.domContentLoadedEventEnd), heroEnd: round(measured.heroResource?.responseEnd || 0), finalLcpElement: measured.lcpCandidates.at(-1)?.element }));
                await context.close();
            }
        }
        report.summary = summarize(report.samples);
        report.interpretation = interpret(report.samples, report.priorBrowserHomeMetrics);
        report.ok = report.samples.length === 12 && report.errors.length === 0;
        fs.writeFileSync(output, JSON.stringify(report, null, 2));
        console.log(JSON.stringify({ ok: report.ok, summary: report.summary }));
    } catch (error) {
        report.failure = error.message;
        fs.writeFileSync(output, JSON.stringify(report, null, 2));
        throw error;
    } finally {
        await browser?.close();
        await server.stop();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
