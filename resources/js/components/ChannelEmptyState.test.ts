// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { useDialog } from '@/composables/useDialog';
import type { Channel } from '@/types';

/**
 * The first-run welcome's "Create your first channel" card, which used to be a
 * trigger wrapped in the create modal and is now a plain button onto the shell's
 * singleton (#1223). The gate came with it: the modal declining to render is
 * what hid the card from a viewer the workspace policy shuts out, and unpicked
 * from that wrapper the welcome owes the reading itself.
 */
const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

vi.mock('@lucide/vue', () => ({
    Send: { render: () => h('svg') },
    UserPlus: { render: () => h('svg') },
}));

vi.mock('@/components/InviteMemberModal.vue', () => ({
    default: defineComponent({ setup: () => () => h('div') }),
}));

vi.mock('@/composables/useOnboardingTour', () => ({
    useOnboardingTour: () => ({ open: vi.fn() }),
}));

import ChannelEmptyState from './ChannelEmptyState.vue';

let app: App | null = null;

/** A fresh #general for a viewer who has not finished onboarding: the welcome. */
function seed(overrides: Record<string, unknown> = {}): void {
    page.props = {
        auth: { user: { onboarding_completed_at: null } },
        currentTeam: { id: 't1', slug: 'acme' },
        canInviteToCurrentTeam: false,
        invitableRoles: [],
        creatableChannelVisibilities: ['public', 'private'],
        ...overrides,
    };
}

function mountState(): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(ChannelEmptyState, {
                channel: { slug: 'general', name: 'general' } as Channel,
                isSelfDm: false,
                teamName: 'Acme',
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host;
}

beforeEach(() => {
    seed();
    useDialog('createChannel').close();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

it('opens the create form the shell mounts', () => {
    const host = mountState();

    host.querySelector<HTMLElement>(
        '[data-test="welcome-create-channel"]',
    )!.click();

    expect(useDialog('createChannel').isOpen.value).toBe(true);
});

it('withdraws the card from a viewer the workspace policy shuts out', () => {
    seed({ creatableChannelVisibilities: [] });

    const host = mountState();

    expect(
        host.querySelector('[data-test="welcome-create-channel"]'),
    ).toBeNull();
    // The rest of the welcome stands: it is one card that goes, not the moment.
    expect(
        host.querySelector('[data-test="workspace-welcome"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="welcome-post-message"]'),
    ).not.toBeNull();
});

it('stands the plain empty state in once the viewer has been onboarded', () => {
    seed({
        auth: { user: { onboarding_completed_at: '2026-01-01T00:00:00Z' } },
    });

    const host = mountState();

    expect(host.querySelector('[data-test="workspace-welcome"]')).toBeNull();
    expect(
        host.querySelector('[data-test="welcome-create-channel"]'),
    ).toBeNull();
});
