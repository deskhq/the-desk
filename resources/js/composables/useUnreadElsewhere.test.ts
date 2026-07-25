import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { Channel } from '@/types/channels';

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
        isGeneral: false,
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
        dmParticipants: null,
        lastActivityAt: null,
        ...overrides,
    };
}

describe('useUnreadElsewhere', () => {
    it('reports nothing on a page that carries no sidebar channels', () => {
        page.props = {};

        expect(useUnreadElsewhere().value).toEqual({
            count: 0,
            hasUnread: false,
        });
    });

    it('derives the rollup from the shared channels prop', () => {
        page.props = {
            channels: [
                channel({ id: 'ch-design', unreadCount: 3 }),
                channel({ id: 'dm-ep', isDirect: true, unreadCount: 1 }),
            ],
        };

        expect(useUnreadElsewhere().value).toEqual({
            count: 1,
            hasUnread: true,
        });
    });

    it('excludes the open conversation named by the page', () => {
        page.props = {
            channel: { id: 'dm-ep' },
            channels: [
                channel({ id: 'dm-ep', isDirect: true, unreadCount: 1 }),
            ],
        };

        expect(useUnreadElsewhere().value).toEqual({
            count: 0,
            hasUnread: false,
        });
    });

    it('tracks the channels prop, so the partial reload behind the sidebar badges moves it too', () => {
        page.props = { channels: [channel({ id: 'ch-design' })] };

        const summary = useUnreadElsewhere();

        expect(summary.value.hasUnread).toBe(false);

        page.props = {
            channels: [
                channel({ id: 'dm-ep', isDirect: true, unreadCount: 2 }),
            ],
        };

        expect(summary.value).toEqual({ count: 2, hasUnread: true });
    });
});
