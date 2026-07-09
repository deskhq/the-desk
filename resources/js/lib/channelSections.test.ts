import { describe, expect, it } from 'vitest';
import {
    partitionChannels,
    toggleCollapsedSection,
} from '@/lib/channelSections';
import type { Channel, ChannelSection } from '@/types/channels';

/**
 * A minimal channel with only the fields the section helpers read; `starred`
 * defaults to false and is overridden per case.
 */
function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: overrides.id ?? overrides.slug ?? 'id',
        name: overrides.name ?? 'General',
        slug: overrides.slug ?? 'general',
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
        ...overrides,
    };
}

/** A minimal custom section. */
function section(overrides: Partial<ChannelSection> = {}): ChannelSection {
    return {
        id: overrides.id ?? 'section',
        name: overrides.name ?? 'Section',
        position: 0,
        collapsed: false,
        ...overrides,
    };
}

describe('partitionChannels', () => {
    it('splits starred channels from the rest', () => {
        const alpha = channel({ slug: 'alpha', starred: true });
        const beta = channel({ slug: 'beta' });
        const gamma = channel({ slug: 'gamma', starred: true });

        const { starred, others } = partitionChannels([alpha, beta, gamma]);

        expect(starred.map((c) => c.slug)).toEqual(['alpha', 'gamma']);
        expect(others.map((c) => c.slug)).toEqual(['beta']);
    });

    it('preserves the incoming order within each group', () => {
        const channels = [
            channel({ slug: 'zebra', starred: true }),
            channel({ slug: 'apple', starred: true }),
        ];

        expect(partitionChannels(channels).starred.map((c) => c.slug)).toEqual([
            'zebra',
            'apple',
        ]);
    });

    it('returns empty groups for an empty list', () => {
        expect(partitionChannels([])).toEqual({
            starred: [],
            custom: [],
            others: [],
        });
    });

    it('puts everything in others when nothing is starred or assigned', () => {
        const { starred, custom, others } = partitionChannels([
            channel({ slug: 'a' }),
            channel({ slug: 'b' }),
        ]);

        expect(starred).toEqual([]);
        expect(custom).toEqual([]);
        expect(others.map((c) => c.slug)).toEqual(['a', 'b']);
    });

    it('files assigned channels under their custom section', () => {
        const projects = section({ id: 's1', name: 'Projects' });
        const clients = section({ id: 's2', name: 'Clients', position: 1 });

        const { custom, others } = partitionChannels(
            [
                channel({ slug: 'api', sectionId: 's1' }),
                channel({ slug: 'random' }),
                channel({ slug: 'acme', sectionId: 's2' }),
            ],
            [projects, clients],
        );

        expect(custom.map((g) => g.section.name)).toEqual([
            'Projects',
            'Clients',
        ]);
        expect(custom[0].channels.map((c) => c.slug)).toEqual(['api']);
        expect(custom[1].channels.map((c) => c.slug)).toEqual(['acme']);
        expect(others.map((c) => c.slug)).toEqual(['random']);
    });

    it('keeps starred channels in Starred even when assigned to a section', () => {
        const projects = section({ id: 's1', name: 'Projects' });

        const { starred, custom } = partitionChannels(
            [channel({ slug: 'api', sectionId: 's1', starred: true })],
            [projects],
        );

        expect(starred.map((c) => c.slug)).toEqual(['api']);
        expect(custom[0].channels).toEqual([]);
    });

    it('falls back to others when the assigned section no longer exists', () => {
        const { custom, others } = partitionChannels(
            [channel({ slug: 'orphan', sectionId: 'gone' })],
            [section({ id: 's1' })],
        );

        expect(custom[0].channels).toEqual([]);
        expect(others.map((c) => c.slug)).toEqual(['orphan']);
    });

    it('renders an empty custom section with no channels', () => {
        const { custom } = partitionChannels([], [section({ id: 's1' })]);

        expect(custom).toHaveLength(1);
        expect(custom[0].channels).toEqual([]);
    });
});

describe('toggleCollapsedSection', () => {
    it('collapses a section that was expanded', () => {
        expect(toggleCollapsedSection([], 'starred')).toEqual(['starred']);
    });

    it('expands a section that was collapsed', () => {
        expect(
            toggleCollapsedSection(['starred', 'channels'], 'starred'),
        ).toEqual(['channels']);
    });

    it('does not mutate the input array', () => {
        const collapsed = ['channels'];
        toggleCollapsedSection(collapsed, 'starred');
        expect(collapsed).toEqual(['channels']);
    });
});
