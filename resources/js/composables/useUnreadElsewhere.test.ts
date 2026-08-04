import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { Channel } from '@/types/channels';
import type { UnreadCounts, UnreadDigest } from '@/types/unread';

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

const { useUnreadElsewhere } = await import('@/composables/useUnreadElsewhere');

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

/** The shared digest, naming only the conversations that hold something. */
function digest(channels: Record<string, UnreadCounts>): UnreadDigest {
    return { channels, teams: {}, threads: false };
}

describe('useUnreadElsewhere', () => {
    it('reports nothing on a page that carries no sidebar channels', () => {
        page.props = {};

        expect(useUnreadElsewhere().value).toEqual({
            count: 0,
            hasUnread: false,
        });
    });

    it('derives the rollup from the channels roster and the unread digest', () => {
        page.props = {
            channels: [
                channel({ id: 'ch-design' }),
                channel({ id: 'dm-ep', isDirect: true }),
            ],
            unread: digest({
                'ch-design': { unread: 3, mention: 0 },
                'dm-ep': { unread: 1, mention: 0 },
            }),
        };

        expect(useUnreadElsewhere().value).toEqual({
            count: 1,
            hasUnread: true,
        });
    });

    it('excludes the open conversation named by the page', () => {
        page.props = {
            channel: { id: 'dm-ep' },
            channels: [channel({ id: 'dm-ep', isDirect: true })],
            unread: digest({ 'dm-ep': { unread: 1, mention: 0 } }),
        };

        expect(useUnreadElsewhere().value).toEqual({
            count: 0,
            hasUnread: false,
        });
    });

    it('tracks the digest, so the partial reload behind the sidebar badges moves it too', () => {
        page.props = {
            channels: [channel({ id: 'dm-ep', isDirect: true })],
            unread: digest({}),
        };

        const summary = useUnreadElsewhere();

        expect(summary.value.hasUnread).toBe(false);

        // The badge reload asks for the digest alone; the roster beside it is
        // unchanged, and the rollup still moves.
        page.props = {
            ...page.props,
            unread: digest({ 'dm-ep': { unread: 2, mention: 0 } }),
        };

        expect(summary.value).toEqual({ count: 2, hasUnread: true });
    });
});
