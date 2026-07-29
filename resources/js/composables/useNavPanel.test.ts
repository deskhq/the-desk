import { describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, reactive } from 'vue';

const { pinUrl } = vi.hoisted(() => ({ pinUrl: vi.fn() }));

const page = reactive({ url: '/t/acme/c/general' });

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));
vi.mock('@/lib/pinUrl', () => ({ pinUrl }));

import {
    destinationFromUrl,
    urlForDestination,
    useNavPanel,
} from '@/composables/useNavPanel';
import type { NavPanel } from '@/composables/useNavPanel';

function harness(url = '/t/acme/c/general') {
    pinUrl.mockClear();
    page.url = url;

    const scope = effectScope();

    let panel!: NavPanel;

    scope.run(() => {
        panel = useNavPanel();
    });

    return { panel, scope };
}

describe('destinationFromUrl', () => {
    it('falls back to the channel list when no destination is pinned', () => {
        expect(destinationFromUrl('/t/acme/c/general')).toBe('channels');
    });

    it('reads a pinned destination off the query string', () => {
        expect(destinationFromUrl('/t/acme/c/general?nav=search')).toBe(
            'search',
        );
    });

    it('ignores a destination it does not know', () => {
        expect(destinationFromUrl('/t/acme?nav=elsewhere')).toBe('channels');
    });
});

describe('urlForDestination', () => {
    it('pins a destination without disturbing the other query params', () => {
        expect(
            urlForDestination('/t/acme/c/general?thread=42', 'threads'),
        ).toBe('/t/acme/c/general?thread=42&nav=threads');
    });

    it('drops the param again for the default destination', () => {
        expect(
            urlForDestination('/t/acme/c/general?nav=search', 'channels'),
        ).toBe('/t/acme/c/general');
    });

    it('replaces a destination that is already pinned', () => {
        expect(urlForDestination('/t/acme?nav=search', 'you')).toBe(
            '/t/acme?nav=you',
        );
    });

    it('keeps the hash so an in-page anchor survives a destination switch', () => {
        expect(urlForDestination('/t/acme/c/general#m-7', 'reminders')).toBe(
            '/t/acme/c/general?nav=reminders#m-7',
        );
    });
});

describe('useNavPanel', () => {
    it('opens on the channel list by default', () => {
        const { panel, scope } = harness();

        expect(panel.activeDestination.value).toBe('channels');
        expect(panel.isActive('channels')).toBe(true);
        expect(panel.isActive('threads')).toBe(false);

        scope.stop();
    });

    it('restores a deep-linked destination on a cold load', () => {
        const { panel, scope } = harness('/t/acme/c/general?nav=reminders');

        expect(panel.activeDestination.value).toBe('reminders');

        scope.stop();
    });

    it('switches destination client-side, pinning it on the current route', () => {
        const { panel, scope } = harness();

        panel.openDestination('threads');

        expect(panel.activeDestination.value).toBe('threads');
        expect(pinUrl).toHaveBeenCalledWith('/t/acme/c/general?nav=threads');

        scope.stop();
    });

    it('does nothing when the destination is already open', () => {
        const { panel, scope } = harness('/t/acme?nav=search');

        panel.openDestination('search');

        expect(pinUrl).not.toHaveBeenCalled();

        scope.stop();
    });

    it('follows history traversal back onto the destination in the URL', async () => {
        const { panel, scope } = harness();

        panel.openDestination('search');
        page.url = '/t/acme/c/general?nav=you';
        await nextTick();

        expect(panel.activeDestination.value).toBe('you');

        scope.stop();
    });

    it('returns to the channel list when navigating to a channel', async () => {
        const { panel, scope } = harness('/t/acme/c/general?nav=threads');

        page.url = '/t/acme/c/random';
        await nextTick();

        expect(panel.activeDestination.value).toBe('channels');

        scope.stop();
    });
});
