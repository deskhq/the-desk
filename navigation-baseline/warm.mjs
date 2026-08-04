import { chromium } from '/Users/epaul/www/z_perso/the-desk/node_modules/playwright/index.mjs';

const BASE = 'https://demo.thedeskhq.app';
const browser = await chromium.launch({ headless: true });

const boot = await browser.newContext();
const bp = await boot.newPage();
await bp.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
await bp.evaluate(async () => {
    const token = decodeURIComponent(
        (document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=').slice(1).join('='),
    );
    await fetch('/demo/login', { method: 'POST', headers: { 'X-XSRF-TOKEN': token }, redirect: 'manual' });
});
const state = await boot.storageState();
await boot.close();

const ctx = await browser.newContext({ storageState: state });
const page = await ctx.newPage();
await page.goto(`${BASE}/t/northwind-labs/c/engineering`, { waitUntil: 'load' });
await page.waitForTimeout(2500);

// --- font + chunk accounting on the already-loaded page -----------------------
const assets = await page.evaluate(() => {
    const res = performance.getEntriesByType('resource');
    const g = (pred) => res.filter(pred);
    const sum = (a, k) => a.reduce((t, r) => t + r[k], 0);
    const fonts = g((r) => /\.(woff2?|ttf|otf)(\?|$)/.test(r.name));
    return {
        fontCount: fonts.length,
        woff: fonts.filter((r) => r.name.endsWith('.woff')).length,
        woff2: fonts.filter((r) => r.name.endsWith('.woff2')).length,
        fontWire: sum(fonts, 'transferSize'),
        fontNames: fonts.map((r) => r.name.split('/').pop()),
        jsFiles: g((r) => r.name.endsWith('.js')).length,
        jsWire: sum(g((r) => r.name.endsWith('.js')), 'transferSize'),
        jsRaw: sum(g((r) => r.name.endsWith('.js')), 'decodedBodySize'),
    };
});
console.log('ASSETS on a cold channel load:');
console.log(`  fonts: ${assets.fontCount} files (${assets.woff} .woff + ${assets.woff2} .woff2), ${assets.fontWire.toLocaleString()}B wire`);
console.log(`  ${assets.fontNames.join('\n  ')}`);
console.log(`  js: ${assets.jsFiles} files, ${assets.jsWire.toLocaleString()}B wire / ${assets.jsRaw.toLocaleString()}B raw`);

// --- warm client-side navigation, measured off Inertia's own events -----------
await page.evaluate(() => {
    window.__nav = [];
    let t0 = 0;
    document.addEventListener('inertia:start', () => { t0 = performance.now(); });
    document.addEventListener('inertia:finish', () => {
        const t = performance.now() - t0;
        requestAnimationFrame(() => requestAnimationFrame(() => window.__nav.push({ t, painted: performance.now() - t0 })));
    });
});

const slugs = ['marketing', 'engineering', 'random', 'design-review', 'general', 'product-launch', 'announcements', 'watercooler'];
for (const slug of slugs) {
    const link = page.locator(`a[href$="/c/${slug}"]`).first();
    if ((await link.count()) === 0) { console.log(`  (no link for ${slug})`); continue; }
    await link.click();
    await page.waitForTimeout(1200);
}

const nav = await page.evaluate(() => window.__nav);
console.log('\nWARM client-side channel -> channel click (Inertia start -> finish -> 2 frames painted):');
for (const n of nav) console.log(`  request ${n.t.toFixed(0)}ms   painted ${n.painted.toFixed(0)}ms`);
const ts = nav.map((n) => n.painted).sort((a, b) => a - b);
if (ts.length) console.log(`  median painted: ${ts[Math.floor(ts.length / 2)].toFixed(0)}ms   (n=${ts.length})`);

await browser.close();
