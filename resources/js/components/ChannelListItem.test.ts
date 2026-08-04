import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { Channel } from '@/types/channels';
import type { UnreadCounts } from '@/types/unread';

/**
 * Inertia + Wayfinder + UI stubs, so the row's own markup — the mention badge,
 * the draft cue, the unread dot — renders in isolation. `stub(tag)` builds a
 * passthrough component; hoisted so the vi.mock factories can reach it.
 */
const { stub } = await vi.hoisted(async () => {
    const { defineComponent, h: hyper } = await import('vue');

    return {
        stub: (tag: string) =>
            defineComponent({
                setup:
                    (_: unknown, ctx: { slots: { default?: () => unknown } }) =>
                    () =>
                        hyper(tag, ctx.slots.default?.() as never),
            }),
    };
});

/**
 * The shared props the row reads its badge from. The `channel` prop carries no
 * counts at all — the digest is the only thing that says what is waiting.
 */
const page = await vi.hoisted(async () => ({
    props: {
        unread: { channels: {}, teams: {}, threads: false },
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: stub('a'),
    router: { patch: vi.fn() },
    usePage: () => page,
}));
vi.mock('@lucide/vue', () => ({
    GripVertical: stub('svg'),
    MoreVertical: stub('svg'),
    Pencil: stub('svg'),
    Star: stub('svg'),
}));
vi.mock('@/composables/useToast', () => ({
    useToast: () => ({
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    }),
}));
vi.mock('@/actions/App/Http/Controllers/Channels/ChannelController', () => ({
    show: () => ({ url: '/acme/design' }),
}));
vi.mock(
    '@/actions/App/Http/Controllers/Channels/ChannelStarController',
    () => ({
        update: () => ({ url: '/acme/design/star' }),
    }),
);
vi.mock('@/components/ui/button', () => ({ Button: stub('button') }));
vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: stub('div'),
    DropdownMenuContent: stub('div'),
    DropdownMenuItem: stub('div'),
    DropdownMenuLabel: stub('div'),
    DropdownMenuSeparator: stub('div'),
    DropdownMenuTrigger: stub('div'),
}));
vi.mock('@/components/ui/sidebar', () => ({
    SidebarMenuItem: stub('div'),
    SidebarMenuButton: stub('div'),
}));
vi.mock('@/components/ui/tooltip', () => ({
    Tooltip: stub('div'),
    TooltipTrigger: stub('div'),
    TooltipContent: stub('div'),
}));

import ChannelListItem from './ChannelListItem.vue';

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'ch-1',
        name: 'design',
        slug: 'design',
        visibility: 'public',
        topic: null,
        description: null,
        isGeneral: false,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        hasDraft: false,
        draft: null,
        starred: false,
        sectionId: null,
        position: 0,
        isDirect: false,
        isGroupDirect: false,
        dmUserId: null,
        dmParticipants: null,
        lastActivityAt: null,
        ...overrides,
    };
}

async function render(
    unread?: UnreadCounts,
    overrides: Partial<Channel> = {},
): Promise<string> {
    const row = channel(overrides);

    page.props.unread = {
        channels: unread ? { [row.id]: unread } : {},
        teams: {},
        threads: false,
    };

    const app = createSSRApp({
        render: () =>
            h(ChannelListItem, {
                channel: row,
                teamSlug: 'acme',
                activeChannelSlug: null,
            }),
    });

    app.config.globalProperties.$t = (
        key: string,
        replacements?: Record<string, unknown>,
    ) =>
        replacements
            ? Object.entries(replacements).reduce(
                  (line, [token, value]) =>
                      line.replaceAll(`:${token}`, String(value)),
                  key,
              )
            : key;

    return renderToString(app);
}

describe('ChannelListItem badges', () => {
    it('spells out the mention count from the digest, not from the channel row', async () => {
        const html = await render({ unread: 9, mention: 2 });

        expect(html).toContain('data-test="mention-badge"');
        expect(html).toContain('>2</span>');
        expect(html).toContain('2 unread mentions');
    });

    it('falls back to a plain dot when the unread traffic named nobody', async () => {
        const html = await render({ unread: 3, mention: 0 });

        expect(html).not.toContain('data-test="mention-badge"');
        expect(html).toContain('data-test="unread-dot"');
        expect(html).toContain('data-test="channel-unread-design"');
    });

    /**
     * The draft cue outranks the dot and is outranked by the mention badge, and
     * it lives on the roster rather than the digest — the two halves have to
     * compose in one row for that ordering to survive the split.
     */
    it('prefers the draft cue over the unread dot, and the mention badge over both', async () => {
        const drafted = await render(
            { unread: 3, mention: 0 },
            { hasDraft: true },
        );

        expect(drafted).toContain('data-test="draft-indicator"');
        expect(drafted).not.toContain('data-test="unread-dot"');

        const mentioned = await render(
            { unread: 3, mention: 1 },
            { hasDraft: true },
        );

        expect(mentioned).toContain('data-test="mention-badge"');
        expect(mentioned).not.toContain('data-test="draft-indicator"');
    });

    it('renders no badge at all for a channel the digest does not name', async () => {
        const html = await render();

        expect(html).not.toContain('data-test="mention-badge"');
        expect(html).not.toContain('data-test="unread-dot"');
    });
});
