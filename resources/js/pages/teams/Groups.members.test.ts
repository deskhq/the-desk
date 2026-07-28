// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick, ref } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the membership half of the editor a row's pencil opens — the roster of
 * chips it can drop someone from, the search that adds one, and the re-read
 * that keeps the roster from going stale after either. What is pinned here is
 * the rendered markup and the request each form makes. The page is mounted
 * whole, so the same expectations hold before and after its sections move into
 * components (#991).
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

function member(
    overrides: Partial<App.Data.MentionData> = {},
): App.Data.MentionData {
    return { id: 'u1', name: 'Ada Lovelace', avatar: null, ...overrides };
}

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

function candidate(
    overrides: Partial<App.Data.UserData> = {},
): App.Data.UserData {
    return {
        id: 'u2',
        name: 'Grace Hopper',
        avatar: null,
        isBot: false,
        status: null,
        presence: 'online' as App.Enums.PresenceState,
        isDnd: false,
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

function text(host: HTMLElement, selector: string): string {
    return find(host, selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
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

/** Opens the editor from the seeded group's pencil. */
async function openEditor(host: HTMLElement): Promise<HTMLElement> {
    find(host, 'group-edit-dev-team')?.click();
    await nextTick();

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
describe('the roster of members', () => {
    it('says so while the group is empty', async () => {
        const { host } = mount({ groups: [group()] });
        await openEditor(host);

        expect(text(host, 'group-members-empty')).toBe(
            'This group has no members yet.',
        );
    });

    it('renders a chip per member, with their initials and their name', async () => {
        const { host } = mount({
            groups: [group({ members: [member()] })],
        });
        await openEditor(host);

        expect(text(host, 'group-member-u1')).toContain('AL');
        expect(text(host, 'group-member-u1')).toContain('Ada Lovelace');
        expect(find(host, 'group-members-empty')).toBeNull();
    });

    it('names the chip’s cross for a screen reader', async () => {
        const { host } = mount({
            groups: [group({ members: [member()] })],
        });
        await openEditor(host);

        expect(
            find(host, 'group-member-remove-u1')?.getAttribute('aria-label'),
        ).toBe('Remove Ada Lovelace from the group');
    });

    it('drops a member without losing the scroll', async () => {
        const { host } = mount({
            groups: [group({ members: [member()] })],
        });
        await openEditor(host);

        find(host, 'group-member-remove-u1')?.click();
        await nextTick();

        const request = inertia.deletes.at(-1)!;
        expect(request.url).toBe('/settings/teams/acme/groups/g1/members/u1');
        expect(request.options.preserveScroll).toBe(true);
    });

    it('holds the chip’s cross while a membership request is in flight', async () => {
        const { host } = mount({
            groups: [group({ members: [member()] })],
        });
        await openEditor(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'group-member-remove-u1')).toHaveProperty(
            'disabled',
            true,
        );
    });
});

describe('adding a member', () => {
    it('offers everyone who is not already in the group', async () => {
        const { host } = mount({
            groups: [group({ members: [member()] })],
            members: [
                candidate({ id: 'u1', name: 'Ada Lovelace' }),
                candidate(),
            ],
        });
        await openEditor(host);

        expect(find(host, 'group-member-add-u2')).not.toBeNull();
        expect(find(host, 'group-member-add-u1')).toBeNull();
    });

    it('narrows the candidates by name, ignoring the case', async () => {
        const { host } = mount({
            groups: [group()],
            members: [candidate(), candidate({ id: 'u3', name: 'Alan Kay' })],
        });
        await openEditor(host);

        await type(find(host, 'group-member-search'), 'ALAN');

        expect(find(host, 'group-member-add-u3')).not.toBeNull();
        expect(find(host, 'group-member-add-u2')).toBeNull();
    });

    it('offers at most eight at a time', async () => {
        const { host } = mount({
            groups: [group()],
            members: Array.from({ length: 12 }, (_, index) =>
                candidate({ id: `u${index}`, name: `Member ${index}` }),
            ),
        });
        await openEditor(host);

        expect(
            find(host, 'group-member-candidates')?.querySelectorAll('li'),
        ).toHaveLength(8);
    });

    it('leaves the list out entirely when nobody is left to add', async () => {
        const { host } = mount({ groups: [group()] });
        await openEditor(host);

        expect(find(host, 'group-member-candidates')).toBeNull();
    });

    it('posts the pick without losing the scroll, then clears the search', async () => {
        const { host } = mount({
            groups: [group()],
            members: [candidate()],
        });
        await openEditor(host);
        await type(find(host, 'group-member-search'), 'grace');

        find(host, 'group-member-add-u2')?.click();
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe('/settings/teams/acme/groups/g1/members');
        expect(post.options.preserveScroll).toBe(true);
        expect(post.form.user_id).toBe('u2');

        post.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'group-member-search')).toHaveProperty('value', '');
    });

    it('holds every candidate while a membership request is in flight', async () => {
        const { host } = mount({
            groups: [group()],
            members: [candidate()],
        });
        await openEditor(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'group-member-add-u2')).toHaveProperty(
            'disabled',
            true,
        );
    });
});

describe('the roster after a reload', () => {
    it('re-reads the open group, rather than showing the snapshot it opened with', async () => {
        const { host, groups } = mount({ groups: [group()] });
        await openEditor(host);

        expect(find(host, 'group-members-empty')).not.toBeNull();

        groups.value = [group({ members: [member()] })];
        await nextTick();
        await nextTick();

        expect(text(host, 'group-member-u1')).toContain('Ada Lovelace');
    });

    it('closes itself when the group it was opened on is gone', async () => {
        const { host, groups } = mount({ groups: [group()] });
        await openEditor(host);

        groups.value = [];
        await nextTick();
        await nextTick();

        expect(find(host, 'group-edit-dialog')).toBeNull();
    });

    it('leaves a closed editor alone', async () => {
        const { host, groups } = mount({ groups: [group()] });

        groups.value = [group({ name: 'Renamed' })];
        await nextTick();
        await nextTick();

        expect(find(host, 'group-edit-dialog')).toBeNull();
        expect(text(host, 'group-row-dev-team')).toContain('Renamed');
    });
});
