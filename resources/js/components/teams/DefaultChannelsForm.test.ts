// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { DefaultChannelCandidate, Team } from '@/types';

/**
 * Covers the switch list an admin uses to decide where new members land: that
 * each switch writes straight through to the channel it names, that it carries
 * a name assistive tech can read, and that #general is stated as permanent
 * rather than offered as a switch nobody could move.
 */
const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { demoMode: false } }),
    router: { visit },
}));

vi.mock('@/actions/App/Http/Controllers/Channels/ChannelController', () => ({
    update: ({ team, channel }: { team: string; channel: string }) => ({
        url: `/t/${team}/c/${channel}`,
        method: 'patch',
    }),
}));

vi.mock('@/components/ui/switch', () => ({
    Switch: defineComponent({
        props: {
            modelValue: { type: Boolean, default: false },
            disabled: { type: Boolean, default: false },
        },
        emits: ['update:modelValue'],
        setup:
            (props, { emit, attrs }) =>
            () =>
                h('button', {
                    ...attrs,
                    role: 'switch',
                    'aria-checked': String(props.modelValue),
                    disabled: props.disabled,
                    onClick: () => emit('update:modelValue', !props.modelValue),
                }),
    }),
}));

import DefaultChannelsForm from './DefaultChannelsForm.vue';

function team(): Team {
    return {
        id: 't-1',
        name: 'Acme',
        slug: 'acme',
        isPersonal: false,
        membersCount: 1,
    };
}

function channels(): DefaultChannelCandidate[] {
    return [
        { slug: 'general', name: 'general', isDefault: false, isGeneral: true },
        {
            slug: 'announcements',
            name: 'Announcements',
            isDefault: true,
            isGeneral: false,
        },
        {
            slug: 'watercooler',
            name: 'Watercooler',
            isDefault: false,
            isGeneral: false,
        },
    ];
}

let app: App | null = null;

function mount(list: DefaultChannelCandidate[] = channels()): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () => h(DefaultChannelsForm, { team: team(), channels: list }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

beforeEach(() => {
    visit.mockClear();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the default-channels list', () => {
    it('lists every channel it was given', () => {
        const host = mount();

        expect(host.textContent).toContain('general');
        expect(host.textContent).toContain('Announcements');
        expect(host.textContent).toContain('Watercooler');
    });

    it('shows each switch in the state the channel is already in', () => {
        const host = mount();

        expect(
            find(host, 'default-channel-announcements')?.getAttribute(
                'aria-checked',
            ),
        ).toBe('true');
        expect(
            find(host, 'default-channel-watercooler')?.getAttribute(
                'aria-checked',
            ),
        ).toBe('false');
    });

    it('patches the channel it names when a switch is turned on', () => {
        find(mount(), 'default-channel-watercooler')?.click();

        expect(visit).toHaveBeenCalledWith(
            { url: '/t/acme/c/watercooler', method: 'patch' },
            expect.objectContaining({ data: { is_default: true } }),
        );
    });

    it('patches it back off again', () => {
        find(mount(), 'default-channel-announcements')?.click();

        expect(visit).toHaveBeenCalledWith(
            { url: '/t/acme/c/announcements', method: 'patch' },
            expect.objectContaining({ data: { is_default: false } }),
        );
    });

    it('names each switch for assistive tech', () => {
        const host = mount();

        expect(
            find(host, 'default-channel-watercooler')?.getAttribute(
                'aria-label',
            ),
        ).toBe('Make Watercooler a default channel');
    });

    it('states #general as permanent instead of offering a switch', () => {
        const host = mount();

        expect(find(host, 'default-channel-general')).toBeNull();
        expect(
            find(host, 'default-channel-always-general')?.textContent,
        ).toContain('Always');
    });

    it('renders an empty list without a switch', () => {
        const host = mount([]);

        expect(find(host, 'default-channels')?.children).toHaveLength(0);
    });
});
