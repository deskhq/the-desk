/**
 * The Playwright half of `bin/capture-shell` — see that script for the
 * environment it is handed (a seeded demo database, a live server, a Reverb
 * server) and for why the capture is pinned the way it is.
 *
 * Writes one PNG per variant into the asset directory, or — with `--check` —
 * compares what the app renders now against the committed PNGs and exits
 * non-zero when the shell has drifted away from its own product shot.
 */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { chromium } from 'playwright';

const BASE_URL = requireEnv('CAPTURE_BASE_URL');
const ASSET_DIR = requireEnv('CAPTURE_ASSET_DIR');
const CLOCK = requireEnv('CAPTURE_CLOCK');
const EMAIL = requireEnv('CAPTURE_EMAIL');
const PASSWORD = requireEnv('CAPTURE_PASSWORD');
const CHANNEL_URL = `${BASE_URL}${requireEnv('CAPTURE_CHANNEL_PATH')}`;

const CHECK_ONLY = process.argv.includes('--check');

/**
 * Exit status meaning "the captures no longer match", as opposed to the 1 that
 * any crash produces. bin/capture-shell keys its advice off the difference.
 */
const DRIFT_EXIT_CODE = 2;

/**
 * Where a `--check` run leaves what it actually rendered, so CI can upload it
 * and a contributor can commit those bytes instead of re-deriving them.
 *
 * Deliberately not the asset directory: a check that wrote its findings over the
 * committed captures would turn "your shell drifted" into "your shell drifted
 * and I have already accepted the drift".
 */
const ACTUAL_DIR = requireEnv('CAPTURE_ACTUAL_DIR');

/**
 * The share of pixels allowed to differ before a variant counts as drifted.
 *
 * Not zero, and not because the capture is flaky: two runs in the same
 * environment are byte-identical. It is the *cross-environment* case this
 * absorbs — the committed bytes are produced on one machine and verified on
 * CI's, and font rasterisation is free to differ in the last bit between them.
 * Wide enough to swallow that, far too narrow to swallow a redesign: a moved
 * pane or a changed control shifts percent, not per-mille.
 */
const MAX_DIFF_RATIO = diffRatioLimit(process.env.CAPTURE_MAX_DIFF_RATIO);

/**
 * The configured limit, or the default when nothing usable was configured.
 *
 * A bare `Number()` would turn a typo into `NaN`, and every `ratio > NaN` is
 * false — so a mistyped override would not loosen the gate, it would disable it,
 * silently and while still reporting each variant as a match.
 */
function diffRatioLimit(configured) {
    const fallback = 0.002;

    if (configured === undefined) {
        return fallback;
    }

    const parsed = Number(configured);

    if (!Number.isFinite(parsed) || parsed < 0 || parsed > 1) {
        console.warn(
            `CAPTURE_MAX_DIFF_RATIO=${configured} is not a ratio between 0 and 1; using ${fallback}.`,
        );

        return fallback;
    }

    return parsed;
}

/**
 * How different two pixels must be to count as different at all, per channel,
 * on a 0-255 scale. Guards against a whole image being nudged imperceptibly.
 */
const CHANNEL_TOLERANCE = 12;

/**
 * The product shots the landing page, the README and the docs all read from.
 *
 * Two widths because a desktop three-pane shot scaled into a phone-width column
 * is unreadable, and the mobile shell (rail folded into a tab bar) is a
 * different enough product to be worth advertising on its own.
 */
const VARIANTS = [
    { name: 'desktop-light', width: 1440, height: 900, theme: 'light' },
    { name: 'desktop-dark', width: 1440, height: 900, theme: 'dark' },
    { name: 'mobile-light', width: 390, height: 844, theme: 'light' },
    { name: 'mobile-dark', width: 390, height: 844, theme: 'dark' },
];

/**
 * Read a required environment variable, failing loudly rather than capturing
 * something subtly wrong from a silent default.
 */
function requireEnv(name) {
    const value = process.env[name];

    if (!value) {
        throw new Error(`${name} must be set (bin/capture-shell sets it).`);
    }

    return value;
}

/**
 * Pin everything about a browser context that would otherwise vary per machine
 * or per run: the viewport, the pixel density, the locale, the timezone, and
 * the theme the app resolves.
 */
async function openContext(browser, variant) {
    const context = await browser.newContext({
        viewport: { width: variant.width, height: variant.height },
        deviceScaleFactor: 2,
        colorScheme: variant.theme,
        locale: 'en-US',
        timezoneId: 'UTC',
        reducedMotion: 'reduce',
    });

    // The app resolves its theme from localStorage plus a class on <html>, not
    // from the OS preference alone, so `colorScheme` above is not enough on its
    // own. Seeding both before any page script runs avoids photographing the
    // flash of the other theme.
    await context.addInitScript((theme) => {
        window.localStorage.setItem('appearance', theme);
        document.documentElement.classList.toggle('dark', theme === 'dark');
    }, variant.theme);

    return context;
}

/**
 * Freeze the wall clock the page reads, so relative timestamps ("2 hours ago",
 * the "Today" separator) render the same on every run. Pairs with the seeder's
 * `--at`, which back-dates the fixture from the same instant.
 */
async function pinClock(page) {
    await page.clock.setFixedTime(new Date(CLOCK));
}

/**
 * Sign in through the real login form and land on the workspace.
 */
async function signIn(page) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input[type="email"]');
    await page.fill('input[type="email"]', EMAIL);
    await page.fill('input[type="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 60000,
    });
}

/**
 * Settle the channel view: let the timeline mount and the realtime client
 * connect, then hold still long enough that no transition is mid-flight.
 */
async function settle(page) {
    await page.goto(CHANNEL_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-test="message-composer-input"]', {
        timeout: 60000,
    });
    await page.waitForTimeout(5000);
    await page.evaluate(() => document.fonts.ready);
}

/**
 * Visit the channel once before any variant is photographed, so its unread
 * state is cleared. Otherwise every shot carries a "New messages" pill and an
 * unread badge, which are true of a first visit and wrong for a product shot.
 */
async function clearUnreadState(browser) {
    const context = await openContext(browser, VARIANTS[0]);
    const page = await context.newPage();

    await pinClock(page);
    await signIn(page);
    await settle(page);

    const state = await context.storageState();
    await context.close();

    return state;
}

/**
 * Photograph one variant.
 */
async function capture(browser, variant, storageState) {
    const context = await openContext(browser, variant);
    await context.addCookies(storageState.cookies);

    const page = await context.newPage();
    await pinClock(page);
    await settle(page);

    const shot = await page.screenshot({
        animations: 'disabled',
        caret: 'hide',
    });

    await context.close();

    return shot;
}

/**
 * The share of pixels that differ between two PNGs, decoded in the browser we
 * already have open rather than by pulling in an image library.
 *
 * Returns 1 (nothing in common) when the two differ in size, which is what a
 * changed viewport or a changed layout at a fixed viewport looks like.
 */
async function diffRatio(page, expected, actual) {
    return page.evaluate(
        async ([expectedData, actualData, tolerance]) => {
            const load = (data) =>
                new Promise((resolve, reject) => {
                    const image = new Image();
                    image.onload = () => resolve(image);
                    image.onerror = reject;
                    image.src = data;
                });

            const [a, b] = await Promise.all([
                load(expectedData),
                load(actualData),
            ]);

            if (a.width !== b.width || a.height !== b.height) {
                return 1;
            }

            const pixels = (image) => {
                const canvas = document.createElement('canvas');
                canvas.width = image.width;
                canvas.height = image.height;

                const context = canvas.getContext('2d', {
                    willReadFrequently: true,
                });
                context.drawImage(image, 0, 0);

                return context.getImageData(0, 0, image.width, image.height)
                    .data;
            };

            const left = pixels(a);
            const right = pixels(b);
            let differing = 0;

            for (let i = 0; i < left.length; i += 4) {
                if (
                    Math.abs(left[i] - right[i]) > tolerance ||
                    Math.abs(left[i + 1] - right[i + 1]) > tolerance ||
                    Math.abs(left[i + 2] - right[i + 2]) > tolerance ||
                    Math.abs(left[i + 3] - right[i + 3]) > tolerance
                ) {
                    differing += 1;
                }
            }

            return differing / (left.length / 4);
        },
        [toDataUrl(expected), toDataUrl(actual), CHANNEL_TOLERANCE],
    );
}

function toDataUrl(png) {
    return `data:image/png;base64,${png.toString('base64')}`;
}

function assetPath(variant) {
    return join(ASSET_DIR, `${variant.name}.png`);
}

function shortHash(png) {
    return createHash('sha256').update(png).digest('hex').slice(0, 12);
}

const drifted = [];
const browser = await chromium.launch({ headless: true });

// Closed however this ends: a browser left running holds a Chromium and its
// pages open for the life of the shell that started it, and this repo has spent
// enough time chasing leaked Playwright processes already (see bin/browser-tests).
try {
    const storageState = await clearUnreadState(browser);

    // A blank page to run the pixel comparison in; `about:blank` is same-origin
    // with nothing, so the canvas it draws data: URLs into stays readable.
    const comparisonPage = CHECK_ONLY ? await browser.newPage() : null;

    for (const variant of VARIANTS) {
        const shot = await capture(browser, variant, storageState);
        const path = assetPath(variant);

        if (!CHECK_ONLY) {
            writeFileSync(path, shot);
            console.log(`wrote ${path} (${shortHash(shot)})`);

            continue;
        }

        if (!existsSync(path)) {
            drifted.push({
                variant: variant.name,
                reason: 'no committed capture',
            });
            writeFileSync(join(ACTUAL_DIR, `${variant.name}.png`), shot);

            continue;
        }

        const ratio = await diffRatio(comparisonPage, readFileSync(path), shot);

        if (ratio > MAX_DIFF_RATIO) {
            const actualPath = join(ACTUAL_DIR, `${variant.name}.png`);
            writeFileSync(actualPath, shot);
            drifted.push({
                variant: variant.name,
                reason: `${(ratio * 100).toFixed(3)}% of pixels differ (limit ${(MAX_DIFF_RATIO * 100).toFixed(3)}%), rendered ${basename(actualPath)}`,
            });

            continue;
        }

        console.log(
            `${variant.name} matches (${(ratio * 100).toFixed(3)}% of pixels differ)`,
        );
    }
} finally {
    await browser.close();
}

if (drifted.length > 0) {
    console.error('\nThe app shell no longer matches its committed captures:');

    for (const { variant, reason } of drifted) {
        console.error(`  - ${variant}: ${reason}`);
    }

    // Distinct from the 1 any crash above exits with, so the caller can tell
    // "the shell drifted" from "the capture never ran" and stop advising a
    // refresh for what is actually a broken environment.
    process.exitCode = DRIFT_EXIT_CODE;
}
