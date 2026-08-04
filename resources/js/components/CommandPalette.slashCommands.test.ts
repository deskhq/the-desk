// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers the one line the palette holds below its list when a query plainly
 * names a slash command: when it fires, what it reads, what it takes the place
 * of below the breakpoint, and — the property the whole shape exists for — that
 * it is a paragraph outside the list rather than anything `Enter` can reach.
 *
 * Its own file rather than the destinations suite because that suite is at the
 * line budget; the preamble below is the same one every palette suite carries.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble } = await import('./CommandPalette.doubles');

    return inertiaDouble();
});

vi.mock('@lucide/vue', async () => {
    const { lucideDouble } = await import('./CommandPalette.stubs');

    return lucideDouble();
});

vi.mock('reka-ui', async () => {
    const { rekaDouble } = await import('./CommandPalette.stubs');

    return rekaDouble();
});

vi.mock(
    '@/actions/App/Http/Controllers/Channels/ChannelController',
    async () => {
        const { channelActionDouble } =
            await import('./CommandPalette.doubles');

        return channelActionDouble();
    },
);

vi.mock(
    '@/actions/App/Http/Controllers/Channels/SearchController',
    async () => {
        const { searchActionDouble } = await import('./CommandPalette.doubles');

        return searchActionDouble();
    },
);

vi.mock('@/components/PresenceDot.vue', async () => {
    const { presenceDotDouble } = await import('./CommandPalette.stubs');

    return presenceDotDouble();
});

vi.mock('@/components/SafeHtml.vue', async () => {
    const { safeHtmlDouble } = await import('./CommandPalette.stubs');

    return safeHtmlDouble();
});

vi.mock('@/components/ui/button', async () => {
    const { buttonDouble } = await import('./CommandPalette.stubs');

    return buttonDouble();
});

vi.mock('@/components/ui/command', async () => {
    const { commandDouble } = await import('./CommandPalette.stubs');

    return commandDouble();
});

vi.mock('@/components/ui/dialog', async () => {
    const { dialogDouble } = await import('./CommandPalette.stubs');

    return dialogDouble();
});

vi.mock('@/components/ui/sidebar', async () => {
    const { sidebarDouble } = await import('./CommandPalette.doubles');

    return sidebarDouble();
});

vi.mock('@/composables/useIsMobile', async () => {
    const { isMobileDouble } = await import('./CommandPalette.doubles');

    return isMobileDouble();
});

vi.mock('@/composables/useMessageSearch', async () => {
    const { messageSearchDouble } = await import('./CommandPalette.doubles');

    return messageSearchDouble();
});

vi.mock('@/composables/useOpenDirectMessage', async () => {
    const { openDirectMessageDouble } =
        await import('./CommandPalette.doubles');

    return openDirectMessageDouble();
});

vi.mock('@/lib/pinUrl', async () => {
    const { pinUrlDouble } = await import('./CommandPalette.doubles');

    return pinUrlDouble();
});

import {
    find,
    isMobile,
    mountSwitcher,
    resetDoubles,
    type,
    unmountAll,
    workspace,
} from './CommandPalette.doubles';
import CommandPalette from './CommandPalette.vue';

/** The line the slot below the list holds, or null when it holds nothing. */
function hint(host: HTMLElement): string | null {
    return (
        find(host, 'quick-switcher-slash-command-hint')?.textContent?.trim() ??
        null
    );
}

beforeEach(() => {
    resetDoubles();
});

afterEach(() => {
    unmountAll();
});

describe('the slash-command hint', () => {
    it('names where a query naming a slash command actually leads', async () => {
        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'gif');

        expect(hint(host)).toBe(
            '/gif is a message command. Type it in a channel to use it.',
        );
    });

    it('reads the same query with its leading slash typed', async () => {
        const { host } = mountSwitcher(CommandPalette);

        await type(host, '/gif');

        expect(hint(host)).toBe(
            '/gif is a message command. Type it in a channel to use it.',
        );
    });

    it('matches the manifest whatever the case, and names it back in its own', async () => {
        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'TableFlip');

        expect(hint(host)).toBe(
            '/tableflip is a message command. Type it in a channel to use it.',
        );
    });

    it('stays silent for a prefix of a command', async () => {
        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'g');
        expect(hint(host)).toBeNull();

        // The whole point of matching outright: someone typing towards a
        // channel called #giraffes is not asking about /gif.
        await type(host, 'gi');
        expect(hint(host)).toBeNull();
    });

    it('stays silent for a query naming no command at all', async () => {
        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'quokka');

        expect(hint(host)).toBeNull();
    });

    it('is disabled outright by an empty manifest', async () => {
        workspace.slashCommands = [];

        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'gif');

        expect(hint(host)).toBeNull();
    });

    it('is disabled outright by an absent manifest', async () => {
        workspace.slashCommands = undefined;

        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'gif');

        expect(hint(host)).toBeNull();
    });

    it('takes the standing hint’s place below the breakpoint', async () => {
        isMobile.value = true;

        const { host } = mountSwitcher(CommandPalette);

        expect(find(host, 'quick-switcher-ranking-hint')).not.toBeNull();

        await type(host, 'gif');

        // One line in that slot, always.
        expect(hint(host)).not.toBeNull();
        expect(find(host, 'quick-switcher-ranking-hint')).toBeNull();
    });

    it('renders below the list rather than in it, as nothing selectable', async () => {
        const { host } = mountSwitcher(CommandPalette);

        await type(host, 'gif');

        const line = find(host, 'quick-switcher-slash-command-hint');

        // In no group, in no sort, and unhighlightable — so it can never move
        // what Enter runs under the hands of whoever asked for it.
        expect(line?.closest('[data-slot="command-list"]')).toBeNull();
        expect(line?.tagName).toBe('P');
    });
});
