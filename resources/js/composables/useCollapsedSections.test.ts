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

import { useCollapsedSections } from '@/composables/useCollapsedSections';
import type { CollapsedSections } from '@/composables/useCollapsedSections';

/** The options bag of the nth recorded patch. */
function patchOptions(call = 0): { onError: () => void } {
    return patch.mock.calls[call][2] as { onError: () => void };
}

function harness(collapsed: string[] = []): {
    sections: CollapsedSections;
    stop: () => void;
} {
    page.props = { collapsedChannelSections: collapsed };

    const scope = effectScope();

    let sections!: CollapsedSections;

    scope.run(() => {
        sections = useCollapsedSections();
    });

    return { sections, stop: () => scope.stop() };
}

describe('useCollapsedSections', () => {
    beforeEach(() => {
        patch.mockClear();
        toastError.mockClear();
    });

    it('seeds the collapsed set from the shared prop', () => {
        const { sections, stop } = harness(['channels']);

        expect(sections.isSectionCollapsed('channels')).toBe(true);
        expect(sections.isSectionCollapsed('starred')).toBe(false);

        stop();
    });

    it('treats a missing prop as nothing collapsed', () => {
        page.props = {};

        const scope = effectScope();
        let sections!: CollapsedSections;
        scope.run(() => {
            sections = useCollapsedSections();
        });

        expect(sections.isSectionCollapsed('direct')).toBe(false);

        scope.stop();
    });

    it('collapses optimistically and persists the whole new set', () => {
        const { sections, stop } = harness(['starred']);

        sections.toggleSection('channels');

        expect(sections.isSectionCollapsed('channels')).toBe(true);
        expect(patch.mock.calls[0][1]).toEqual({
            collapsed: ['starred', 'channels'],
        });

        stop();
    });

    it('expands a section that was collapsed', () => {
        const { sections, stop } = harness(['starred', 'channels']);

        sections.toggleSection('starred');

        expect(sections.isSectionCollapsed('starred')).toBe(false);
        expect(patch.mock.calls[0][1]).toEqual({ collapsed: ['channels'] });

        stop();
    });

    it('rolls the toggle back when the request fails, and says so', () => {
        const { sections, stop } = harness(['starred']);

        sections.toggleSection('channels');
        patchOptions().onError();

        expect(sections.isSectionCollapsed('channels')).toBe(false);
        expect(sections.isSectionCollapsed('starred')).toBe(true);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to save the sidebar layout',
        );

        stop();
    });

    it('adopts the set the server recomputed, e.g. after another device', async () => {
        const { sections, stop } = harness([]);

        page.props.collapsedChannelSections = ['direct'];
        await nextTick();

        expect(sections.isSectionCollapsed('direct')).toBe(true);

        stop();
    });

    it('empties the set when the server stops sending one', async () => {
        const { sections, stop } = harness(['direct']);

        page.props.collapsedChannelSections = undefined;
        await nextTick();

        expect(sections.isSectionCollapsed('direct')).toBe(false);

        stop();
    });
});
