import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { ConnectionPill } from '@/composables/useConnectionState';
import type { Channel } from '@/types/channels';

/**
 * Inertia + Wayfinder + UI stubs so the masthead's own markup (the connection
 * pill) can be rendered in isolation without the full app/router context.
 * `stub(tag)` builds a passthrough component; hoisted so the vi.mock factories
 * (which run before the module body) can reach it.
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

vi.mock('@inertiajs/vue3', () => ({
    Link: stub('a'),
    usePage: () => ({
        props: { auth: { user: { avatar: null, presence: 'active' } } },
    }),
}));
vi.mock('@lucide/vue', () => ({
    Archive: stub('svg'),
    Bot: stub('svg'),
    Check: stub('svg'),
    EllipsisVertical: stub('svg'),
    LogOut: stub('svg'),
    Pin: stub('svg'),
    Search: stub('svg'),
    Star: stub('svg'),
    UserPlus: stub('svg'),
}));
vi.mock('@/actions/App/Http/Controllers/Channels/SearchController', () => ({
    index: () => ({ url: '/acme/search' }),
}));
vi.mock('@/components/AvatarStack.vue', () => ({ default: stub('div') }));
vi.mock('@/components/PresenceDot.vue', () => ({ default: stub('div') }));
vi.mock('@/components/ui/avatar', () => ({
    Avatar: stub('div'),
    AvatarImage: stub('div'),
    AvatarFallback: stub('div'),
}));
vi.mock('@/components/ui/button', () => ({ Button: stub('button') }));
vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: stub('div'),
    DropdownMenuCheckboxItem: stub('div'),
    DropdownMenuContent: stub('div'),
    DropdownMenuItem: stub('div'),
    DropdownMenuLabel: stub('div'),
    DropdownMenuRadioGroup: stub('div'),
    DropdownMenuRadioItem: stub('div'),
    DropdownMenuSeparator: stub('div'),
    DropdownMenuTrigger: stub('div'),
}));
vi.mock('@/components/ui/sidebar', () => ({ SidebarTrigger: stub('button') }));
vi.mock('@/components/ui/tooltip', () => ({
    Tooltip: stub('div'),
    TooltipTrigger: stub('div'),
    TooltipContent: stub('div'),
}));
vi.mock('@/composables/useQuickSwitcher', () => ({
    useQuickSwitcher: () => ({ open: vi.fn() }),
}));

import ChannelMasthead from './ChannelMasthead.vue';

function channel(): Channel {
    return {
        id: 'ch-1',
        name: 'general',
        slug: 'general',
        visibility: 'public',
        topic: null,
        isGeneral: true,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        unreadCount: 0,
        mentionCount: 0,
        hasDraft: false,
        draft: null,
        starred: false,
        sectionId: null,
        position: 0,
        isDirect: false,
        isGroupDirect: false,
        dmUserId: null,
        dmParticipants: [],
        lastActivityAt: null,
    };
}

async function render(connectionPill: ConnectionPill): Promise<string> {
    const app = createSSRApp({
        render: () =>
            h(ChannelMasthead, {
                channel: channel(),
                teamSlug: 'acme',
                members: [],
                presenceFor: () => 'active' as const,
                title: 'general',
                canManagePreferences: false,
                canArchive: false,
                canLeave: false,
                canAddPeople: false,
                notificationLevels: [],
                starred: false,
                muted: false,
                pinCount: 0,
                notificationLevel: 'all',
                notificationStatus: null,
                connectionPill,
            }),
    });

    app.config.globalProperties.$t = (key: string) => key;

    return renderToString(app);
}

/** The opening tag of the element carrying the given data-test selector. */
function openingTag(html: string, dataTest: string): string {
    const tag = new RegExp(`<[a-z]+[^>]*data-test="${dataTest}"[^>]*>`).exec(
        html,
    )?.[0];

    expect(tag, `element with data-test="${dataTest}"`).toBeDefined();

    return tag ?? '';
}

describe('ChannelMasthead connection pill mobile overlay', () => {
    it('overlays the reconnecting pill below md instead of taking masthead space', async () => {
        const html = await render('reconnecting');

        const pill = openingTag(html, 'connection-reconnecting');

        // Below the breakpoint the pill leaves the masthead's flex row entirely:
        // it hangs centered under the masthead, anchored to the header...
        expect(pill).toContain('absolute');
        expect(pill).toContain('top-full');
        expect(pill).toContain('left-1/2');
        expect(pill).toContain('-translate-x-1/2');
        // ...and can never block the masthead actions or the first message row.
        expect(pill).toContain('pointer-events-none');

        // From md up it rejoins the action cluster exactly as before.
        expect(pill).toContain('md:static');
        expect(pill).toContain('md:translate-x-0');

        // The header is the overlay's positioning anchor.
        const header = /<header[^>]*>/.exec(html)?.[0] ?? '';
        expect(header).toContain('relative');

        // The selectors and semantics the rest of the suite relies on survive.
        expect(pill).toContain('role="status"');
    });

    it('renders the reconnecting pill larger below md, keeping the desktop size from md up', async () => {
        const html = await render('reconnecting');

        const pill = openingTag(html, 'connection-reconnecting');

        // Phone-legible size plus toast elevation while floating...
        expect(pill).toContain('text-[13px]');
        expect(pill).toContain('px-3.5');
        expect(pill).toContain('py-1.5');
        expect(pill).toContain('shadow-md');

        // ...and the exact pre-existing badge sizing once inline again.
        expect(pill).toContain('md:text-[11.5px]');
        expect(pill).toContain('md:px-2.5');
        expect(pill).toContain('md:py-1');
        expect(pill).toContain('md:shadow-none');
    });

    it('keeps the floating pill surface opaque in dark mode, restoring the translucent wash inline', async () => {
        const reconnecting = openingTag(
            await render('reconnecting'),
            'connection-reconnecting',
        );
        const backOnline = openingTag(
            await render('back-online'),
            'connection-back-online',
        );

        // Floating over arbitrary timeline content (a poll bar, an image), the
        // desktop's 40% dark wash lets whatever is behind bleed through and
        // garble the label — the overlay needs a solid surface.
        expect(reconnecting).toContain('dark:bg-amber-950 ');
        expect(reconnecting).toContain('md:dark:bg-amber-950/40');
        expect(backOnline).toContain('dark:bg-emerald-950 ');
        expect(backOnline).toContain('md:dark:bg-emerald-950/40');
    });

    it('gives the transient back-online pill the same overlay placement and sizing', async () => {
        const html = await render('back-online');

        const pill = openingTag(html, 'connection-back-online');

        expect(pill).toContain('role="status"');
        expect(pill).toContain('absolute');
        expect(pill).toContain('top-full');
        expect(pill).toContain('left-1/2');
        expect(pill).toContain('-translate-x-1/2');
        expect(pill).toContain('pointer-events-none');
        expect(pill).toContain('text-[13px]');
        expect(pill).toContain('shadow-md');
        expect(pill).toContain('md:static');
        expect(pill).toContain('md:translate-x-0');
        expect(pill).toContain('md:text-[11.5px]');
        expect(pill).toContain('md:shadow-none');
    });
});
