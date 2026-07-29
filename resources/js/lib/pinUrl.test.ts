import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));

const page = reactive({ url: '/t/acme/c/general' });

vi.mock('@inertiajs/vue3', () => ({
    router: { replace },
    usePage: () => page,
}));

import { pinUrl } from '@/lib/pinUrl';

const SOURCE_ROOT = 'resources/js';

/** The options object the nth `router.replace` was issued with. */
function visit(index = 0): {
    url: string;
    preserveState: boolean;
    preserveScroll: boolean;
    onFinish: () => void;
} {
    return replace.mock.calls[index][0];
}

/**
 * Finish the nth visit as Inertia would once its page swap settled, with `url`
 * standing for what the page ended up on: the write's own target when it landed,
 * the route it was issued from when a concurrent response swallowed it.
 */
function settle(index: number, url: string): void {
    page.url = url;
    visit(index).onFinish();
    vi.runAllTimers();
}

/** Every source file under `resources/js`, tests excluded. */
function sourceFiles(directory = SOURCE_ROOT): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(ts|vue)$/.test(entry.name) &&
            !entry.name.endsWith('.test.ts')
            ? [path]
            : [];
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    replace.mockClear();
    page.url = '/t/acme/c/general';
});

afterEach(() => {
    vi.useRealTimers();
});

describe('pinUrl', () => {
    it('writes the url onto the current route without a server round trip', () => {
        pinUrl('/t/acme/c/general?nav=search');

        expect(replace).toHaveBeenCalledTimes(1);
        expect(visit()).toMatchObject({
            url: '/t/acme/c/general?nav=search',
            preserveState: true,
            preserveScroll: true,
        });
    });

    it('asks again when a concurrent response swallowed the write', () => {
        pinUrl('/t/acme/c/general?nav=search');
        settle(0, '/t/acme/c/general');

        expect(replace).toHaveBeenCalledTimes(2);
        expect(visit(1).url).toBe('/t/acme/c/general?nav=search');
    });

    it('stops asking once the write lands', () => {
        pinUrl('/t/acme/c/general?nav=search');
        settle(0, '/t/acme/c/general?nav=search');

        expect(replace).toHaveBeenCalledTimes(1);
    });

    it('stands down when a real navigation moved the url meanwhile', () => {
        pinUrl('/t/acme/c/general?nav=search');
        settle(0, '/t/acme/c/random');

        expect(replace).toHaveBeenCalledTimes(1);
    });

    it('gives up rather than loop when every attempt is swallowed', () => {
        pinUrl('/t/acme/c/general?nav=search');
        settle(0, '/t/acme/c/general');
        settle(1, '/t/acme/c/general');

        // The third attempt is swallowed too, and this time nothing follows it:
        // a write that cannot land through three windows is a symptom of
        // something else, and looping on it would clobber whatever that is.
        settle(2, '/t/acme/c/general');

        expect(replace).toHaveBeenCalledTimes(3);
    });

    it('issues a write that changes nothing exactly once', () => {
        pinUrl('/t/acme/c/general');
        settle(0, '/t/acme/c/general');

        expect(replace).toHaveBeenCalledTimes(1);
    });

    it('is the only place a client-side visit is issued from', () => {
        // A `router.replace` written by hand is a write that can be dropped in
        // silence (#964). Route every one of them through here, so the retry is
        // not something each call site has to remember.
        const direct = sourceFiles()
            .filter((path) => path !== join(SOURCE_ROOT, 'lib', 'pinUrl.ts'))
            .filter((path) =>
                readFileSync(path, 'utf8').includes('router.replace('),
            )
            .map((path) => path.slice(`${SOURCE_ROOT}/`.length));

        expect(direct).toEqual([]);
    });
});
