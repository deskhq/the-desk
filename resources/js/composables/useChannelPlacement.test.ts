import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, reactive } from 'vue';

const { patch, toastError } = vi.hoisted(() => ({
    patch: vi.fn(),
    toastError: vi.fn(),
}));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({ router: { patch }, usePage: () => page }));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { useChannelPlacement } from '@/composables/useChannelPlacement';
import type { ChannelPlacement } from '@/composables/useChannelPlacement';
import type { Channel, ChannelSection } from '@/types/channels';

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c-1',
        name: 'general',
        slug: 'general',
        starred: false,
        sectionId: null,
        isDirect: false,
        lastActivityAt: null,
        ...overrides,
    } as Channel;
}

function section(overrides: Partial<ChannelSection> = {}): ChannelSection {
    return {
        id: 's-1',
        name: 'Projects',
        position: 0,
        collapsed: false,
        ...overrides,
    } as ChannelSection;
}

/** The options bag of the nth recorded patch. */
function patchOptions(call = 0): { onError: () => void } {
    return patch.mock.calls[call][2] as { onError: () => void };
}

/** The payload of the nth recorded patch. */
function patchPayload(call = 0): Record<string, unknown> {
    return patch.mock.calls[call][1] as Record<string, unknown>;
}

function harness(
    channels: Channel[] = [],
    sections: ChannelSection[] = [],
): { placement: ChannelPlacement; stop: () => void } {
    page.props = {
        currentTeam: { slug: 'acme' },
        channels,
        channelSections: sections,
    };

    const scope = effectScope();

    let placement!: ChannelPlacement;

    scope.run(() => {
        placement = useChannelPlacement();
    });

    return { placement, stop: () => scope.stop() };
}

describe('useChannelPlacement', () => {
    beforeEach(() => {
        patch.mockClear();
        toastError.mockClear();
    });

    it('partitions the shared channels into the four sidebar groups', () => {
        const { placement, stop } = harness(
            [
                channel({ id: 'c-1', slug: 'starred', starred: true }),
                channel({ id: 'c-2', slug: 'filed', sectionId: 's-1' }),
                channel({ id: 'c-3', slug: 'plain' }),
                channel({ id: 'c-4', slug: 'dm', isDirect: true }),
            ],
            [section()],
        );

        expect(placement.starredList.value.map((entry) => entry.slug)).toEqual([
            'starred',
        ]);
        expect(placement.defaultList.value.map((entry) => entry.slug)).toEqual([
            'plain',
        ]);
        expect(placement.directList.value.map((entry) => entry.slug)).toEqual([
            'dm',
        ]);
        expect(
            placement.customGroups.value[0].channels.map((entry) => entry.slug),
        ).toEqual(['filed']);

        stop();
    });

    it('re-seeds the groups when the server recomputes the channels', async () => {
        const { placement, stop } = harness([channel()]);

        page.props.channels = [channel(), channel({ id: 'c-2', slug: 'ops' })];
        await nextTick();

        expect(placement.defaultList.value).toHaveLength(2);

        stop();
    });

    it('files a channel under this group when it was dragged in from another', () => {
        const moved = channel({ id: 'c-9', slug: 'ops' });
        const { placement, stop } = harness();

        placement.onChannelChange(
            { added: { element: moved } },
            [moved],
            's-1',
        );

        expect(patchPayload()).toEqual({
            ordered_ids: ['c-9'],
            section_id: 's-1',
        });

        stop();
    });

    it('leaves the assignment alone when the drag was a reorder in place', () => {
        const moved = channel({ id: 'c-9', slug: 'ops' });
        const { placement, stop } = harness();

        placement.onChannelChange(
            { moved: { element: moved } },
            [moved],
            's-1',
        );

        // No `section_id` key at all: sending null would unfile the channel.
        expect(patchPayload()).toEqual({ ordered_ids: ['c-9'] });

        stop();
    });

    it('ignores a drag event carrying neither an add nor a move', () => {
        const { placement, stop } = harness();

        placement.onChannelChange({}, [], null);

        expect(patch).not.toHaveBeenCalled();

        stop();
    });

    it('persists order alone for a reorder within the starred group', () => {
        const starred = channel({ id: 'c-1', slug: 'ops', starred: true });
        const { placement, stop } = harness([starred]);

        placement.onStarredChange({ moved: { element: starred } });

        expect(patchPayload()).toEqual({ ordered_ids: ['c-1'] });

        stop();
    });

    it('ignores a starred channel arriving from another group', () => {
        const { placement, stop } = harness();

        placement.onStarredChange({ added: { element: channel() } });

        expect(patch).not.toHaveBeenCalled();

        stop();
    });

    it('appends a channel to the end of the section it is filed under', () => {
        const filed = channel({ id: 'c-1', slug: 'filed', sectionId: 's-1' });
        const moving = channel({ id: 'c-2', slug: 'ops' });
        const { placement, stop } = harness([filed, moving], [section()]);

        placement.moveChannelToSection(moving, 's-1');

        expect(patchPayload()).toEqual({
            ordered_ids: ['c-1', 'c-2'],
            section_id: 's-1',
        });

        stop();
    });

    it('appends a channel to the default group when unfiled', () => {
        const plain = channel({ id: 'c-1', slug: 'plain' });
        const moving = channel({ id: 'c-2', slug: 'filed', sectionId: 's-1' });
        const { placement, stop } = harness([plain, moving], [section()]);

        placement.moveChannelToSection(moving, null);

        expect(patchPayload()).toEqual({
            ordered_ids: ['c-1', 'c-2'],
            section_id: null,
        });

        stop();
    });

    it('files into an empty target when the section has gone missing', () => {
        const moving = channel({ id: 'c-2', slug: 'ops' });
        const { placement, stop } = harness([moving]);

        placement.moveChannelToSection(moving, 'gone');

        expect(patchPayload()).toEqual({
            ordered_ids: ['c-2'],
            section_id: 'gone',
        });

        stop();
    });

    it('drops a refused placement back onto the server order, and says so', () => {
        const moved = channel({ id: 'c-1', slug: 'ops' });
        const { placement, stop } = harness([moved]);

        // Stand in for the drag itself: vuedraggable has already emptied the
        // group by the time the request comes back.
        placement.defaultList.value = [];
        placement.onChannelChange({ moved: { element: moved } }, [], null);
        patchOptions().onError();

        expect(placement.defaultList.value.map((entry) => entry.slug)).toEqual([
            'ops',
        ]);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to save the sidebar layout',
        );

        stop();
    });

    it('persists the section order after a section drag', () => {
        const { placement, stop } = harness(
            [],
            [section({ id: 's-1' }), section({ id: 's-2', name: 'Ops' })],
        );

        placement.customGroups.value.reverse();
        placement.onSectionReorder();

        expect(patchPayload()).toEqual({ sections: ['s-2', 's-1'] });

        stop();
    });

    it('drops a refused section order back onto the server order, and says so', () => {
        const { placement, stop } = harness(
            [],
            [section({ id: 's-1' }), section({ id: 's-2', name: 'Ops' })],
        );

        placement.customGroups.value.reverse();
        placement.onSectionReorder();
        patchOptions().onError();

        expect(
            placement.customGroups.value.map((group) => group.section.id),
        ).toEqual(['s-1', 's-2']);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to save the section order',
        );

        stop();
    });

    it('addresses the request at the current team even before one is loaded', () => {
        const { placement, stop } = harness();

        page.props.currentTeam = undefined;
        placement.onSectionReorder();

        expect(patch.mock.calls[0][0]).toContain('/t//');

        stop();
    });
});
