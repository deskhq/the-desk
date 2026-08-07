import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { Channel } from '@/types/channels';
import type { UnreadCounts } from '@/types/unread';

/**
 * Inertia + Wayfinder + UI stubs so the row's own markup (unread badge, close
 * button) can be rendered in isolation without the full app/router context.
 * `stub(tag)` builds a passthrough component; hoisted so the vi.mock factories
 * (which run before the module body) can reach it.
 */
const { stub, linkAttrs, recordingLink } = await vi.hoisted(async () => {
    const { defineComponent, h: hyper } = await import('vue');

    const stub = (tag: string) =>
        defineComponent({
            setup:
                (_: unknown, ctx: { slots: { default?: () => unknown } }) =>
                () =>
                    hyper(tag, ctx.slots.default?.() as never),
        });

    /**
     * Everything the row hands its navigation link, recorded per render. The
     * link declaring the right prefetch contract *is* the seam here — Inertia's
     * cache is the framework's to test, not ours.
     */
    const linkAttrs: Record<string, unknown>[] = [];

    return {
        stub,
        linkAttrs,
        recordingLink: defineComponent({
            setup(
                _: unknown,
                ctx: {
                    attrs: Record<string, unknown>;
                    slots: { default?: () => unknown };
                },
            ) {
                linkAttrs.push(ctx.attrs);

                return () => hyper('a', ctx.slots.default?.() as never);
            },
        }),
    };
});

/** Whether the rendered device hovers before it clicks. */
const device = await vi.hoisted(async () => ({ canHover: { value: true } }));

/**
 * The row reads its badge off the shared unread digest, so the stubbed page
 * carries one — the `channel` prop beside it holds no counts at all.
 */
const page = await vi.hoisted(async () => ({
    props: {
        auth: { user: { avatar: null } },
        unread: { channels: {}, teams: {}, threads: false },
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: recordingLink,
    router: { post: vi.fn() },
    usePage: () => page,
}));
vi.mock('@/composables/useCanHover', () => ({
    useCanHover: () => device.canHover,
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});
vi.mock('@/actions/App/Http/Controllers/Channels/ChannelController', () => ({
    show: () => ({ url: '/team/general' }),
}));
vi.mock(
    '@/actions/App/Http/Controllers/Channels/HideDirectMessageController',
    () => ({
        store: () => ({ url: '/team/dm/hide' }),
    }),
);
vi.mock('@/components/AvatarStack.vue', () => ({ default: stub('div') }));
vi.mock('@/components/ui/avatar', () => ({
    Avatar: stub('div'),
    AvatarImage: stub('div'),
    AvatarFallback: stub('div'),
}));
vi.mock('@/components/ui/button', () => ({ Button: stub('button') }));
vi.mock('@/components/ui/sidebar', () => ({
    SidebarMenuItem: stub('div'),
    SidebarMenuButton: stub('div'),
}));
vi.mock('@/components/ui/tooltip', () => ({
    Tooltip: stub('div'),
    TooltipTrigger: stub('div'),
    TooltipContent: stub('div'),
}));

import DirectMessageListItem from './DirectMessageListItem.vue';

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'ch-1',
        name: 'Jordan West',
        slug: 'jordan-west',
        visibility: 'private',
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
        isDirect: true,
        isGroupDirect: false,
        dmUserId: 'u-2',
        dmParticipants: [],
        lastActivityAt: null,
        ...overrides,
    };
}

async function render(
    unread: UnreadCounts = { unread: 0, mention: 0 },
    overrides: Partial<Channel> = {},
): Promise<string> {
    linkAttrs.length = 0;

    page.props.unread = {
        channels: { [channel(overrides).id]: unread },
        teams: {},
        threads: false,
    };

    const app = createSSRApp({
        render: () =>
            h(DirectMessageListItem, {
                channel: channel(overrides),
                teamSlug: 'acme',
                activeChannelSlug: null,
                presence: 'active',
                isSelf: false,
            }),
    });

    app.config.globalProperties.$t = (key: string) => key;

    return renderToString(app);
}

describe('DirectMessageListItem unread badge', () => {
    it('fades the unread pill out on hover and close-button focus so the ✕ replaces it', async () => {
        const html = await render({ unread: 3, mention: 0 });

        expect(html).toContain('data-test="dm-unread-badge"');
        // The pill hides whenever the close button is revealed: on row hover...
        expect(html).toContain('group-hover/row:opacity-0');
        // ...and when the close button itself takes keyboard focus.
        expect(html).toContain(
            'group-has-[button:focus-visible]/row:opacity-0',
        );
    });

    it('hides the multi-digit pill the same way, so a wider count never peeks past the mask', async () => {
        const html = await render({ unread: 42, mention: 0 });

        expect(html).toContain('>42</span>');
        expect(html).toContain('group-hover/row:opacity-0');
    });

    it('anchors the close button to the same right edge as the pill so the ✕ replaces it in place', async () => {
        const html = await render({ unread: 3, mention: 0 });

        // Row content sits at `pr-2.5`, so the right-aligned pill rests on that
        // inset; the overlay close button matches it (`right-2.5`) instead of the
        // old `right-1`, so the ✕ centers over the pill rather than shifting.
        expect(html).toContain('data-test="dm-close-jordan-west"');
        expect(html).toContain('right-2.5');
        expect(html).not.toContain('right-1 ');
    });

    it('renders no pill when there is nothing unread', async () => {
        const html = await render();

        expect(html).not.toContain('data-test="dm-unread-badge"');
    });
});

describe('DirectMessageListItem prefetch', () => {
    beforeEach(() => {
        device.canHover = { value: true };
    });

    it('prefetches on hover where a pointer can hover', async () => {
        await render();

        expect(linkAttrs[0].prefetch).toBe('hover');
    });

    it('prefetches on click where nothing hovers before the tap', async () => {
        device.canHover = { value: false };

        await render();

        expect(linkAttrs[0].prefetch).toBe('click');
    });

    it('tags the entry with the conversation it holds, so an arrival can flush it', async () => {
        await render();

        expect(linkAttrs[0].cacheTags).toEqual(['channel:ch-1']);
    });

    /**
     * `only` is part of Inertia's prefetch cache key and the once-exclusion
     * already omits the shell, so a nav link carrying one would silently miss
     * its own prefetched entry.
     */
    it('carries no `only`, which would break its own prefetch', async () => {
        await render();

        expect(linkAttrs[0]).not.toHaveProperty('only');
    });
});
