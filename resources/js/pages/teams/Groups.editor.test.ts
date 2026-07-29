// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick, ref } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the dialog a row's pencil opens, and the rename it carries — what the
 * editor is seeded with, what it drops when it is reopened, and the request the
 * form makes. Its membership half is pinned in `Groups.members.test.ts`. The
 * page is mounted whole, so the same expectations hold before and after its
 * sections move into components (#991).
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

/**
 * Mounts the page with the `groups` prop held in a ref, so a test can push a
 * fresh server payload the way a partial reload would.
 */
function mount(props: Record<string, unknown> = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const groups = ref((props.groups ?? []) as App.Data.UserGroupData[]);

    const app = createApp({
        render: () =>
            h(Groups, {
                team,
                members: [],
                permissions: { canManageUserGroups: true },
                ...props,
                groups: groups.value,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return { host, groups };
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

/** Types into a text input the way a person would, model binding included. */
async function type(input: HTMLElement | null, value: string): Promise<void> {
    (input as HTMLInputElement).value = value;
    input?.dispatchEvent(new Event('input'));
    await nextTick();
}

/** Marks every recorded form in flight, disabling whatever waits on one. */
function markProcessing(): void {
    for (const { form } of inertia.forms) {
        form.processing = true;
    }
}

/**
 * The rename form, captured when the editor last opened. It declares the same
 * two fields as the creation form, so it is told apart by the group the editor
 * seeds it with rather than by the order the two were declared in.
 */
let renameForm: RecordedForm['form'];

/** Opens the editor from the seeded group's pencil. */
async function openEditor(host: HTMLElement): Promise<HTMLElement> {
    find(host, 'group-edit-dev-team')?.click();
    await nextTick();

    const seeded = inertia.forms.find(
        ({ fields, form }) =>
            fields.length === 2 &&
            fields.includes('name') &&
            fields.includes('slug') &&
            form.slug === 'dev-team',
    );

    expect(seeded, 'the editor seeded no rename form').toBeDefined();
    renameForm = seeded!.form;

    return find(host, 'group-edit-dialog')!;
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

describe('opening the editor', () => {
    it('stays closed until a row asks for it', async () => {
        const { host } = mount({ groups: [group()] });

        expect(find(host, 'group-edit-dialog')).toBeNull();
        expect(await openEditor(host)).not.toBeNull();
    });

    it('says a rename never rewrites the messages already sent', async () => {
        const { host } = mount({ groups: [group()] });
        const dialog = await openEditor(host);

        expect(dialog.textContent).toContain('Edit group');
        expect(dialog.textContent?.replace(/\s+/g, ' ')).toContain(
            'Renaming a group never rewrites messages already sent. They keep the handle they were written with.',
        );
    });

    it('fills the rename form with the group it was opened on', async () => {
        const { host } = mount({ groups: [group()] });
        await openEditor(host);

        expect(find(host, 'group-edit-name-input')).toHaveProperty(
            'value',
            'Dev Team',
        );
        expect(find(host, 'group-edit-slug-input')).toHaveProperty(
            'value',
            'dev-team',
        );
    });

    it('drops what was typed into the last edit, and its errors with it', async () => {
        const { host } = mount({ groups: [group()] });
        await openEditor(host);
        await type(find(host, 'group-edit-name-input'), 'Half typed');
        Object.assign(renameForm.errors, { name: 'Too short.' });
        await type(find(host, 'group-member-search'), 'gra');

        find(host, 'group-edit-dev-team')?.click();
        await nextTick();

        expect(find(host, 'group-edit-name-input')).toHaveProperty(
            'value',
            'Dev Team',
        );
        expect(renameForm.errors).toEqual({});
        expect(find(host, 'group-member-search')).toHaveProperty('value', '');
    });
});

describe('renaming a group', () => {
    it('patches the group without losing the scroll', async () => {
        const { host } = mount({ groups: [group()] });
        await openEditor(host);

        await type(find(host, 'group-edit-name-input'), 'Platform');
        await type(find(host, 'group-edit-slug-input'), 'platform');

        find(host, 'group-rename-form')?.dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await nextTick();

        const patch = inertia.patches.at(-1)!;
        expect(patch.url).toBe('/settings/teams/acme/groups/g1');
        expect(patch.options.preserveScroll).toBe(true);
        expect(renameForm.name).toBe('Platform');
        expect(renameForm.slug).toBe('platform');
    });

    it('holds the save button while the request is in flight', async () => {
        const { host } = mount({ groups: [group()] });
        await openEditor(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'group-rename-button')).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('shows the errors the server sent back', async () => {
        const { host } = mount({ groups: [group()] });
        const dialog = await openEditor(host);

        Object.assign(renameForm.errors, {
            name: 'The name field is required.',
            slug: 'That handle is taken.',
        });
        await nextTick();

        expect(dialog.textContent).toContain('The name field is required.');
        expect(dialog.textContent).toContain('That handle is taken.');
    });
});
