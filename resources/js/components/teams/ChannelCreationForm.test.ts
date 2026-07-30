// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { ChannelCreationSettings, Team } from '@/types';

/**
 * Covers what this form decides for itself: that both selects post the fields
 * the workspace-update endpoint validates, start on the standing policy, and
 * explain the choice the admin is looking at. The `Select` primitive is stubbed
 * to a native `<select>` — reka's listbox is not this component's behaviour, and
 * the hidden input it renders is what actually carries the value to the server.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { demoMode: false } }),
    Form: defineComponent({
        props: { action: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h(
                    'form',
                    { action: props.action },
                    slots.default?.({ errors: {}, processing: false }),
                ),
    }),
}));

vi.mock('@/routes/teams', () => ({
    update: { form: (slug: string) => ({ action: `/teams/${slug}` }) },
}));

vi.mock('@/components/ui/select', () => ({
    Select: defineComponent({
        props: {
            modelValue: { type: String, default: '' },
            name: { type: String, default: '' },
        },
        emits: ['update:modelValue'],
        setup:
            (props, { slots, emit }) =>
            () =>
                h(
                    'select',
                    {
                        name: props.name,
                        value: props.modelValue,
                        onChange: (event: Event) =>
                            emit(
                                'update:modelValue',
                                (event.target as HTMLSelectElement).value,
                            ),
                    },
                    slots.default?.(),
                ),
    }),
    SelectContent: defineComponent({
        setup:
            (_props, { slots }) =>
            () =>
                slots.default?.(),
    }),
    SelectItem: defineComponent({
        props: { value: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('option', { value: props.value }, slots.default?.()),
    }),
    SelectTrigger: defineComponent({
        setup: () => () => h('span'),
    }),
    SelectValue: defineComponent({ setup: () => () => h('span') }),
}));

import ChannelCreationForm from './ChannelCreationForm.vue';

function team(): Team {
    return {
        id: 't-1',
        name: 'Acme',
        slug: 'acme',
        isPersonal: false,
        membersCount: 1,
        unreadCount: 0,
        mentionCount: 0,
    };
}

function settings(
    overrides: Partial<ChannelCreationSettings> = {},
): ChannelCreationSettings {
    return {
        public: 'members',
        private: 'members',
        options: [
            {
                value: 'members',
                label: 'Everyone',
                description: 'Every member of the workspace can create one.',
            },
            {
                value: 'admins',
                label: 'Admins only',
                description:
                    'Only admins and the workspace owner can create one.',
            },
        ],
        ...overrides,
    };
}

let app: App | null = null;

function mount(overrides: Partial<ChannelCreationSettings> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(ChannelCreationForm, {
                team: team(),
                settings: settings(overrides),
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

function select(host: HTMLElement, name: string): HTMLSelectElement {
    return host.querySelector<HTMLSelectElement>(
        `[data-test="${name}-channel-creation-policy"]`,
    ) as HTMLSelectElement;
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the channel-creation form', () => {
    it('posts to the workspace update route', () => {
        expect(mount().querySelector('form')?.getAttribute('action')).toBe(
            '/teams/acme',
        );
    });

    it('names each select after the column the request validates', () => {
        const host = mount();

        expect(select(host, 'public').getAttribute('name')).toBe(
            'public_channel_creation_policy',
        );
        expect(select(host, 'private').getAttribute('name')).toBe(
            'private_channel_creation_policy',
        );
    });

    it('starts each select on the policy that already stands', () => {
        const host = mount({ public: 'admins', private: 'members' });

        expect(select(host, 'public').value).toBe('admins');
        expect(select(host, 'private').value).toBe('members');
    });

    it('offers every policy the server sent', () => {
        const options = [...select(mount(), 'public').options];

        expect(options.map((option) => option.value)).toEqual([
            'members',
            'admins',
        ]);
        expect(options.map((option) => option.textContent?.trim())).toEqual([
            'Everyone',
            'Admins only',
        ]);
    });

    it('explains the policy each select is showing', () => {
        const host = mount({ public: 'admins' });

        expect(host.textContent).toContain(
            'Only admins and the workspace owner can create one.',
        );
        expect(host.textContent).toContain(
            'Every member of the workspace can create one.',
        );
    });

    it('follows the choice with the matching explanation', async () => {
        const host = mount();
        const everyone = /Every member of the workspace can create one\./g;
        const adminsOnly =
            /Only admins and the workspace owner can create one\./g;

        // Both start open, so the same sentence sits under each select.
        expect(host.textContent?.match(everyone)).toHaveLength(2);
        expect(host.textContent?.match(adminsOnly)).toBeNull();

        const control = select(host, 'private');
        control.value = 'admins';
        control.dispatchEvent(new Event('change'));
        await nextTick();

        // Only the select that moved is re-explained.
        expect(host.textContent?.match(everyone)).toHaveLength(1);
        expect(host.textContent?.match(adminsOnly)).toHaveLength(1);
    });
});
