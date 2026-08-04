import { chromium } from '/Users/epaul/www/z_perso/the-desk/node_modules/playwright/index.mjs';

const BASE = 'https://demo.thedeskhq.app';
const CHAN_A = `${BASE}/t/northwind-labs/c/engineering`;
const CHAN_B = `${BASE}/t/northwind-labs/c/marketing`;

const browser = await chromium.launch({ headless: true });

// --- log in once, keep the storage state -------------------------------------
const boot = await browser.newContext();
const bp = await boot.newPage();
await bp.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
// The demo CTA posts to /demo/login. Fire it through the page so the CSRF cookie rides along.
await bp.evaluate(async () => {
    const token = decodeURIComponent(
        (document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=').slice(1).join('='),
    );
    await fetch('/demo/login', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': token, Accept: 'text/html' },
        redirect: 'manual',
    });
});
const state = await boot.storageState();
await boot.close();

const metrics = async (client) => {
    const { metrics } = await client.send('Performance.getMetrics');
    return Object.fromEntries(metrics.map((m) => [m.name, m.value]));
};

async function coldLoad(url, label) {
    const ctx = await browser.newContext({ storageState: state }); // fresh cache, live session
    const page = await ctx.newPage();
    const client = await page.context().newCDPSession(page);
    await client.send('Performance.enable');
    await client.send('Network.enable');
    await client.send('Network.clearBrowserCache');

    await page.goto(url, { waitUntil: 'load' });
    // Wait for the app to actually paint the channel, not just for load.
    await page.waitForSelector('[data-test="message-list"], main', { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(1500);

    const m = await metrics(client);
    const perf = await page.evaluate(() => {
        const nav = performance.getEntriesByType('navigation')[0];
        const paints = Object.fromEntries(performance.getEntriesByType('paint').map((p) => [p.name, p.startTime]));
        const res = performance.getEntriesByType('resource').map((r) => ({
            name: r.name.split('/').pop().split('?')[0],
            type: r.initiatorType,
            start: r.startTime,
            dur: r.duration,
            transfer: r.transferSize,
            decoded: r.decodedBodySize,
        }));
        let lcp = 0;
        for (const e of performance.getEntriesByType('largest-contentful-paint') || []) lcp = e.startTime;
        return {
            ttfb: nav.responseStart - nav.requestStart,
            docDownload: nav.responseEnd - nav.responseStart,
            docBytes: { transfer: nav.transferSize, decoded: nav.decodedBodySize },
            domInteractive: nav.domInteractive,
            domContentLoaded: nav.domContentLoadedEventEnd,
            loadEvent: nav.loadEventEnd,
            paints,
            lcp,
            res,
        };
    });

    const js = perf.res.filter((r) => r.name.endsWith('.js'));
    const css = perf.res.filter((r) => r.name.endsWith('.css'));
    const sum = (a, k) => a.reduce((t, r) => t + r[k], 0);

    console.log(`\n===== ${label} (${url}) =====`);
    console.log(`  document  TTFB ${perf.ttfb.toFixed(0)}ms  download ${perf.docDownload.toFixed(0)}ms  ${perf.docBytes.transfer.toLocaleString()}B wire / ${perf.docBytes.decoded.toLocaleString()}B raw`);
    console.log(`  JS   ${js.length} files  ${sum(js, 'transfer').toLocaleString()}B wire / ${sum(js, 'decoded').toLocaleString()}B raw`);
    console.log(`  CSS  ${css.length} files  ${sum(css, 'transfer').toLocaleString()}B wire / ${sum(css, 'decoded').toLocaleString()}B raw`);
    console.log(`  FCP ${(perf.paints['first-contentful-paint'] || 0).toFixed(0)}ms   LCP ${perf.lcp.toFixed(0)}ms   domInteractive ${perf.domInteractive.toFixed(0)}ms   DCL ${perf.domContentLoaded.toFixed(0)}ms   load ${perf.loadEvent.toFixed(0)}ms`);
    console.log(`  CPU: script ${(m.ScriptDuration * 1000).toFixed(0)}ms  layout ${(m.LayoutDuration * 1000).toFixed(0)}ms  style ${(m.RecalcStyleDuration * 1000).toFixed(0)}ms  task ${(m.TaskDuration * 1000).toFixed(0)}ms`);
    console.log('  largest resources:');
    for (const r of perf.res.sort((a, b) => b.decoded - a.decoded).slice(0, 12)) {
        console.log(`    ${r.decoded.toString().padStart(9)}B raw / ${r.transfer.toString().padStart(8)}B wire  net ${r.dur.toFixed(0).padStart(5)}ms  ${r.name}`);
    }
    await ctx.close();
    return perf;
}

await coldLoad(CHAN_A, 'COLD LOAD — channel');
await coldLoad(`${BASE}/settings/profile`, 'COLD LOAD — settings/profile');

// --- warm client-side navigation ---------------------------------------------
{
    const ctx = await browser.newContext({ storageState: state });
    const page = await ctx.newPage();
    await page.goto(CHAN_A, { waitUntil: 'load' });
    await page.waitForTimeout(2000);

    const results = await page.evaluate(async ({ a, b }) => {
        const go = (url) =>
            new Promise((resolve) => {
                const t0 = performance.now();
                window.__inertia_done = () => resolve(performance.now() - t0);
                document.addEventListener('inertia:finish', function h() {
                    document.removeEventListener('inertia:finish', h);
                    requestAnimationFrame(() => requestAnimationFrame(() => resolve(performance.now() - t0)));
                });
                window.dispatchEvent(new Event('x'));
                // drive Inertia through the router the app already exposes
                const link = [...document.querySelectorAll('a')].find((el) => el.href === url);
                if (link) link.click();
                else resolve(-1);
            });
        const out = [];
        for (let i = 0; i < 6; i++) {
            out.push(await go(i % 2 === 0 ? b : a));
            await new Promise((r) => setTimeout(r, 600));
        }
        return out;
    }, { a: CHAN_A, b: CHAN_B });

    console.log('\n===== WARM client-side channel -> channel click (ms, click to inertia:finish + 2 frames) =====');
    console.log('  ', results.map((r) => r.toFixed(0)).join(', '));
    await ctx.close();
}

await browser.close();
