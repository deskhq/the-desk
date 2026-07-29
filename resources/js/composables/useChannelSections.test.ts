import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const { destroy, patch, post, toastError } = vi.hoisted(() => ({
    destroy: vi.fn(),
    patch: vi.fn(),
    post: vi.fn(),
    toastError: vi.fn(),
}));

const page = reactive<{ props: Record<string, unknown> }>({
    props: { currentTeam: { slug: 'acme' } },
});

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: destroy, patch, post },
    usePage: () => page,
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { useChannelSections } from '@/composables/useChannelSections';
import type { ChannelSectionGroup } from '@/lib/channelSections';
import type { ChannelSection } from '@/types/channels';

function section(overrides: Partial<ChannelSection> = {}): ChannelSection {
    return {
        id: 's-1',
        name: 'Projects',
        position: 0,
        collapsed: false,
        ...overrides,
    } as ChannelSection;
}

function group(overrides: Partial<ChannelSection> = {}): ChannelSectionGroup {
    return { section: section(overrides), channels: [] };
}

/** The callbacks of the nth recorded request on the given router method. */
function callbacks(
    request: typeof patch,
    call = 0,
): { onSuccess?: () => void; onError: () => void } {
    const options = request.mock.calls[call].at(-1);

    return options as { onSuccess?: () => void; onError: () => void };
}

describe('useChannelSections', () => {
    beforeEach(() => {
        destroy.mockClear();
        patch.mockClear();
        post.mockClear();
        toastError.mockClear();

        // The state is module-scoped (the "+ New" menu opening the form sits
        // outside the panel rendering it), so each test starts from a clean one.
        const sections = useChannelSections();
        sections.cancelSectionForm();
        sections.cancelRename();
    });

    it('shows the create form and asks the panel to take its field', () => {
        const sections = useChannelSections();
        const before = sections.sectionFormFocusRequests.value;

        sections.openSectionForm();

        expect(sections.sectionFormOpen.value).toBe(true);
        expect(sections.sectionFormFocusRequests.value).toBe(before + 1);
    });

    it('re-takes the field when the form is reopened over itself', () => {
        const sections = useChannelSections();

        sections.openSectionForm();
        const once = sections.sectionFormFocusRequests.value;
        sections.openSectionForm();

        expect(sections.sectionFormFocusRequests.value).toBe(once + 1);
    });

    it('creates the section the field names, closing the form on success', () => {
        const sections = useChannelSections();

        sections.openSectionForm();
        sections.newSectionName.value = '  Projects  ';
        sections.createSection();

        expect(post.mock.calls[0][1]).toEqual({ name: 'Projects' });

        callbacks(post).onSuccess?.();

        expect(sections.sectionFormOpen.value).toBe(false);
        expect(sections.newSectionName.value).toBe('');
    });

    it('treats an empty field as a cancelled form rather than a request', () => {
        const sections = useChannelSections();

        sections.openSectionForm();
        sections.newSectionName.value = '   ';
        sections.createSection();

        expect(post).not.toHaveBeenCalled();
        expect(sections.sectionFormOpen.value).toBe(false);
    });

    it('says so when the section could not be created', () => {
        const sections = useChannelSections();

        sections.newSectionName.value = 'Projects';
        sections.createSection();
        callbacks(post).onError();

        expect(toastError).toHaveBeenCalledWith('Failed to create the section');
    });

    it('seeds the rename editor with the section name', () => {
        const sections = useChannelSections();

        sections.startRename(section());

        expect(sections.renamingSectionId.value).toBe('s-1');
        expect(sections.renameValue.value).toBe('Projects');
    });

    it('commits a rename and closes the editor on success', () => {
        const sections = useChannelSections();

        sections.startRename(section());
        sections.renameValue.value = 'Ops';
        sections.submitRename(section());

        expect(patch.mock.calls[0][1]).toEqual({ name: 'Ops' });

        callbacks(patch).onSuccess?.();

        expect(sections.renamingSectionId.value).toBeNull();
    });

    it('closes the editor without a request when the name is unchanged', () => {
        const sections = useChannelSections();

        sections.startRename(section());
        sections.submitRename(section());

        expect(patch).not.toHaveBeenCalled();
        expect(sections.renamingSectionId.value).toBeNull();
    });

    it('closes the editor without a request when the name was emptied', () => {
        const sections = useChannelSections();

        sections.startRename(section());
        sections.renameValue.value = '  ';
        sections.submitRename(section());

        expect(patch).not.toHaveBeenCalled();
        expect(sections.renamingSectionId.value).toBeNull();
    });

    it('says so when the rename could not be saved', () => {
        const sections = useChannelSections();

        sections.startRename(section());
        sections.renameValue.value = 'Ops';
        sections.submitRename(section());
        callbacks(patch).onError();

        expect(toastError).toHaveBeenCalledWith('Failed to rename the section');
    });

    it('reloads the channels alongside the sections when one is deleted', () => {
        const sections = useChannelSections();

        sections.deleteSection(section());

        expect(destroy.mock.calls[0][1]).toMatchObject({
            only: ['channels', 'channelSections'],
        });
    });

    it('says so when the section could not be deleted', () => {
        const sections = useChannelSections();

        sections.deleteSection(section());
        callbacks(destroy).onError();

        expect(toastError).toHaveBeenCalledWith('Failed to delete the section');
    });

    it('collapses a custom section optimistically', () => {
        const sections = useChannelSections();
        const target = group();

        sections.toggleCustomSection(target);

        expect(target.section.collapsed).toBe(true);
        expect(patch.mock.calls[0][1]).toEqual({ collapsed: true });
    });

    it('rolls the custom collapse back when the request fails, and says so', () => {
        const sections = useChannelSections();
        const target = group({ collapsed: true });

        sections.toggleCustomSection(target);

        expect(target.section.collapsed).toBe(false);

        callbacks(patch).onError();

        expect(target.section.collapsed).toBe(true);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to save the sidebar layout',
        );
    });

    it('addresses the request at the current team even before one is loaded', () => {
        const sections = useChannelSections();

        page.props.currentTeam = undefined;
        sections.deleteSection(section());
        page.props.currentTeam = { slug: 'acme' };

        expect(destroy.mock.calls[0][0]).toContain('/t//');
    });
});
