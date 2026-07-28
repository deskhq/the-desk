// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the user-groups page's own surface — the creation form, the search
 * box, the list of groups and the deletion confirmation. What is pinned here is
 * the rendered markup and the request each form makes: every selector, every
 * string, every guard deciding whether a piece renders at all. The page is
 * mounted whole, so the same expectations hold before and after its sections
 * move into components (#991).
 */
type FormFields = Record<string, unknown>;

type RecordedForm = {
    fields: string[];
    form: FormFields & { errors: Record<string, string>; processing: boolean };
};

type RecordedRequest = {
    /** The form that fired it, so a request identifies its own sender. */
    form: RecordedForm['form'];
    url: string;
    options: { onSuccess?: () => void; preserveScroll?: boolean };
};

const inertia = vi.hoisted(() => ({
    forms: [] as RecordedForm[],
    posts: [] as RecordedRequest[],
    patches: [] as RecordedRequest[],
    deletes: [] as RecordedRequest[],
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');

    return {
        Head: defineComponent({ name: 'HeadStub', setup: () => () => null }),
        useForm: (initial: Record<string, unknown>) => {
            const fields = Object.keys(initial);
            let defaults = structuredClone(initial);
            const form = reactive({
                ...structuredClone(initial),
                errors: {} as Record<string, string>,
                processing: false,
                defaults(values: Record<string, unknown>) {
                    defaults = structuredClone(values);
                },
                reset() {
                    Object.assign(form, structuredClone(defaults));
                },
                clearErrors() {
                    for (const key of Object.keys(form.errors)) {
                        delete form.errors[key];
                    }
                },
                post(url: string, options: RecordedRequest['options'] = {}) {
                    inertia.posts.push({
                        form: form as RecordedForm['form'],
                        url,
                        options,
                    });
                },
                patch(url: string, options: RecordedRequest['options'] = {}) {
                    inertia.patches.push({
                        form: form as RecordedForm['form'],
                        url,
                        options,
                    });
                },
                delete(url: string, options: RecordedRequest['options'] = {}) {
                    inertia.deletes.push({
                        form: form as RecordedForm['form'],
                        url,
                        options,
                    });
                },
            });

            inertia.forms.push({ fields, form: form as RecordedForm['form'] });

            return form;
        },
    };
});

vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h } = await import('vue');

    const passthrough = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', { 'data-stub': name }, slots.default?.()),
        });

    return {
        // Renders its content only while open, so a closed dialog is observably
        // gone from the DOM the way the real overlay is.
        Dialog: defineComponent({
            name: 'DialogStub',
            props: { open: { type: Boolean, default: false } },
            emits: ['update:open'],
            setup:
                (props, { slots }) =>
                () =>
                    props.open
                        ? h('div', { 'data-stub': 'Dialog' }, slots.default?.())
                        : null,
        }),
        DialogClose: passthrough('DialogClose'),
        DialogContent: passthrough('DialogContent'),
        DialogDescription: passthrough('DialogDescription'),
        DialogFooter: passthrough('DialogFooter'),
        DialogHeader: passthrough('DialogHeader'),
        DialogTitle: passthrough('DialogTitle'),
    };
});

import Groups from './Groups.vue';

const team = {
    id: 't1',
    name: 'Acme',
    slug: 'acme',
    isPersonal: false,
    membersCount: 3,
    unreadCount: 0,
    mentionCount: 0,
} as Team;

function group(
    overrides: Partial<App.Data.UserGroupData> = {},
): App.Data.UserGroupData {
    return {
        id: 'g1',
        name: 'Dev Team',
        slug: 'dev-team',
        membersCount: 0,
        members: [],
        ...overrides,
    };
}

let active: Array<{ app: App; host: HTMLElement }> = [];

function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(Groups, {
                team,
                groups: [],
                members: [],
                permissions: { canManageUserGroups: true },
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

function text(host: HTMLElement, selector: string): string {
    return find(host, selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
}

/** Types into a text input the way a person would, model binding included. */
async function type(input: HTMLElement | null, value: string): Promise<void> {
    (input as HTMLInputElement).value = value;
    input?.dispatchEvent(new Event('input'));
    await nextTick();
}

/**
 * Marks every recorded form in flight. The deletion form is declared with no
 * fields at all, so there is nothing to tell it apart by — and a button that
 * disables on the wrong form's `processing` would still be wrong against the
 * request it fires, which each test asserts separately.
 */
function markProcessing(): void {
    for (const { form } of inertia.forms) {
        form.processing = true;
    }
}

beforeEach(() => {
    inertia.forms = [];
    inertia.posts = [];
    inertia.patches = [];
    inertia.deletes = [];
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the page heading', () => {
    it('names the page and says what a group is for', () => {
        const host = mount();

        expect(host.textContent).toContain('User groups');
        expect(host.textContent?.replace(/\s+/g, ' ')).toContain(
            'Name a set of people so anyone in this workspace can notify them all with a single @mention',
        );
    });
});

describe('the creation form', () => {
    it('asks for a name and a handle, and says where the handle is used', () => {
        const host = mount();

        expect(text(host, 'group-create-form')).toContain('Create a group');
        expect(text(host, 'group-create-form')).toContain(
            'The handle is what people type after @. Leave it blank to derive it from the name.',
        );
        expect(find(host, 'group-name-input')).not.toBeNull();
        expect(find(host, 'group-slug-input')).not.toBeNull();
    });

    it('is withheld from someone who may not manage groups', () => {
        const host = mount({ permissions: { canManageUserGroups: false } });

        expect(find(host, 'group-create-form')).toBeNull();
    });

    it('posts the group without losing the scroll, then clears itself', async () => {
        const host = mount();

        await type(find(host, 'group-name-input'), 'Design');
        await type(find(host, 'group-slug-input'), 'design');

        find(host, 'group-create-form')?.dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe('/settings/teams/acme/groups');
        expect(post.options.preserveScroll).toBe(true);
        expect(post.form.name).toBe('Design');
        expect(post.form.slug).toBe('design');

        post.options.onSuccess?.();
        await nextTick();

        expect(post.form.name).toBe('');
        expect(post.form.slug).toBe('');
    });

    it('holds the submit button while the request is in flight', async () => {
        const host = mount();

        markProcessing();
        await nextTick();

        expect(find(host, 'group-create-button')).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('shows the errors the server sent back for the name and the handle', async () => {
        const host = mount();

        find(host, 'group-create-form')?.dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await nextTick();

        Object.assign(inertia.posts.at(-1)!.form.errors, {
            name: 'The name field is required.',
            slug: 'That handle is taken.',
        });
        await nextTick();

        expect(text(host, 'group-create-form')).toContain(
            'The name field is required.',
        );
        expect(text(host, 'group-create-form')).toContain(
            'That handle is taken.',
        );
    });
});

describe('the list of groups', () => {
    it('says so when there are no groups at all', () => {
        const host = mount();

        expect(text(host, 'group-empty')).toBe('No user groups yet.');
        expect(find(host, 'group-list')).toBeNull();
    });

    it('renders a row per group with its handle, its name and its size', () => {
        const host = mount({
            groups: [
                group({ membersCount: 3 }),
                group({ id: 'g2', name: 'Design', slug: 'design' }),
            ],
        });

        expect(text(host, 'group-row-dev-team')).toContain('@dev-team');
        expect(text(host, 'group-row-dev-team')).toContain('Dev Team');
        expect(text(host, 'group-row-dev-team')).toContain('3 members');
        expect(find(host, 'group-row-design')).not.toBeNull();
        expect(find(host, 'group-empty')).toBeNull();
    });

    it('counts a single member in the singular', () => {
        const host = mount({ groups: [group({ membersCount: 1 })] });

        expect(text(host, 'group-row-dev-team')).toContain('1 member');
        expect(text(host, 'group-row-dev-team')).not.toContain('1 members');
    });

    it('filters by handle, ignoring a leading @ and the case', async () => {
        const host = mount({
            groups: [
                group(),
                group({ id: 'g2', name: 'Design', slug: 'design' }),
            ],
        });

        await type(find(host, 'group-search'), '@DEV');

        expect(find(host, 'group-row-dev-team')).not.toBeNull();
        expect(find(host, 'group-row-design')).toBeNull();
    });

    it('filters by name as well as by handle', async () => {
        const host = mount({
            groups: [
                group(),
                group({ id: 'g2', name: 'Design', slug: 'design' }),
            ],
        });

        await type(find(host, 'group-search'), 'desi');

        expect(find(host, 'group-row-design')).not.toBeNull();
        expect(find(host, 'group-row-dev-team')).toBeNull();
    });

    it('falls back to the empty line when nothing matches the search', async () => {
        const host = mount({ groups: [group()] });

        await type(find(host, 'group-search'), 'nobody');

        expect(text(host, 'group-empty')).toBe('No user groups yet.');
    });

    it('names each row action for a screen reader', () => {
        const host = mount({ groups: [group()] });

        expect(find(host, 'group-edit-dev-team')?.getAttribute('aria-label')) //
            .toBe('Edit Dev Team');
        expect(
            find(host, 'group-remove-dev-team')?.getAttribute('aria-label'),
        ).toBe('Delete Dev Team');
    });

    it('withholds the row actions from someone who may not manage groups', () => {
        const host = mount({
            groups: [group()],
            permissions: { canManageUserGroups: false },
        });

        expect(find(host, 'group-row-dev-team')).not.toBeNull();
        expect(find(host, 'group-edit-dev-team')).toBeNull();
        expect(find(host, 'group-remove-dev-team')).toBeNull();
    });
});

describe('the deletion dialog', () => {
    /** Open the deletion dialog for the seeded group. */
    async function openDialog(host: HTMLElement): Promise<HTMLElement> {
        find(host, 'group-remove-dev-team')?.click();
        await nextTick();

        return find(host, 'group-remove-dialog')!;
    }

    it('stays closed until a row asks for it, and names that handle', async () => {
        const host = mount({ groups: [group()] });

        expect(find(host, 'group-remove-dialog')).toBeNull();

        const dialog = await openDialog(host);

        expect(dialog.textContent).toContain('Delete @dev-team?');
        expect(dialog.textContent?.replace(/\s+/g, ' ')).toContain(
            'The group stops being mentionable and its handle becomes available again. Messages that already mentioned it show plain text, and the notifications they sent are unaffected.',
        );
    });

    it('deletes the group without losing the scroll, then closes itself', async () => {
        const host = mount({ groups: [group()] });
        await openDialog(host);

        find(host, 'group-remove-confirm')?.click();
        await nextTick();

        const request = inertia.deletes.at(-1)!;
        expect(request.url).toBe('/settings/teams/acme/groups/g1');
        expect(request.options.preserveScroll).toBe(true);
        expect(find(host, 'group-remove-dialog')).not.toBeNull();

        request.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'group-remove-dialog')).toBeNull();
    });

    it('holds the confirm button while the request is in flight', async () => {
        const host = mount({ groups: [group()] });
        await openDialog(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'group-remove-confirm')).toHaveProperty(
            'disabled',
            true,
        );
    });
});
