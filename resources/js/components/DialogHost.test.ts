// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick, reactive } from 'vue';
import { SHELL_DIALOG_NAMES, useDialog } from '@/composables/useDialog';

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

/**
 * Every dialog the host mounts, stubbed down to a marker that reports its own
 * `open` prop. What is under test is the wiring — which registry entry drives
 * which dialog, and what is gated on which prop — not the dialogs themselves.
 */
function marker(name: string): Component {
    return defineComponent({
        props: { open: { type: Boolean, default: false } },
        emits: ['update:open'],
        setup:
            (props, { emit }) =>
            () =>
                h('button', {
                    'data-test': `dialog-${name}`,
                    'data-open': String(props.open),
                    // Standing in for the dialog's own chrome dismissing it, which
                    // is what `v-model:open` has to write back through.
                    onClick: () => emit('update:open', false),
                }),
    });
}

for (const [path, name] of [
    ['@/components/InviteMemberModal.vue', 'invite'],
    ['@/components/PendingInvitationsModal.vue', 'invitations'],
    ['@/components/CommandPalette.vue', 'switcher'],
    ['@/components/NewDirectMessageModal.vue', 'newMessage'],
    ['@/components/KeyboardShortcutsModal.vue', 'shortcuts'],
    ['@/components/UserStatusDialog.vue', 'status'],
    ['@/components/InstallAppDialog.vue', 'install'],
    ['@/components/DndPauseDialog.vue', 'dnd'],
    ['@/components/auth/PasskeyPromptDialog.vue', 'passkey-prompt'],
    ['@/components/OnboardingTour.vue', 'tour'],
] as const) {
    vi.doMock(path, () => ({ default: marker(name) }));
}

const DialogHost = (await import('./DialogHost.vue')).default;

let app: App | null = null;

/** The shared workspace props, with a team and an invite permission by default. */
function seed(overrides: Record<string, unknown> = {}): void {
    page.props = {
        auth: { user: { id: 'u1' } },
        currentTeam: { id: 't1', slug: 'acme' },
        channels: [],
        teamMembers: [],
        pendingInvitations: [],
        canInviteToCurrentTeam: true,
        invitableRoles: [],
        ...overrides,
    };
}

function mountHost(): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () => h(DialogHost, {}),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host;
}

function dialog(host: HTMLElement, name: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="dialog-${name}"]`);
}

beforeEach(() => {
    SHELL_DIALOG_NAMES.forEach((name) => useDialog(name).close());
    seed();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

it.each([
    'invite',
    'switcher',
    'newMessage',
    'shortcuts',
    'status',
    'install',
    'dnd',
] as const)(
    'opens %s from the registry rather than from a prop',
    async (name) => {
        const host = mountHost();

        expect(dialog(host, name)!.dataset.open).toBe('false');

        useDialog(name).open();
        await nextTick();

        expect(dialog(host, name)!.dataset.open).toBe('true');
    },
);

it('closes a dialog back into the registry, so the opener sees it go', async () => {
    // `v-model:open` writes through to the shared ref. Bound to a copy instead,
    // the user menu would still believe the sheet it opened is up, and pressing
    // its row a second time would do nothing.
    const host = mountHost();

    useDialog('install').open();
    await nextTick();

    dialog(host, 'install')!.click();
    await nextTick();

    expect(useDialog('install').isOpen.value).toBe(false);
    expect(dialog(host, 'install')!.dataset.open).toBe('false');
});

it('leaves the invitations prompt presenting itself', () => {
    // It is mounted the moment the lazily shared invitations land, so it opens
    // without anyone asking — every other dialog waits.
    useDialog('invitations').open();
    seed({ pendingInvitations: [{ id: 'i1' }] });

    expect(dialog(mountHost(), 'invitations')!.dataset.open).toBe('true');
});

it('mounts no invitations prompt while there is nothing to accept', () => {
    expect(dialog(mountHost(), 'invitations')).toBeNull();
});

it('mounts no invite modal for a viewer who may not invite', () => {
    seed({ canInviteToCurrentTeam: false });

    expect(dialog(mountHost(), 'invite')).toBeNull();
});

it.each(['switcher', 'newMessage'] as const)(
    'mounts no %s before a workspace is loaded',
    (name) => {
        seed({ currentTeam: null });

        expect(dialog(mountHost(), name)).toBeNull();
    },
);

it('mounts the post-registration prompt only while one is queued', () => {
    expect(dialog(mountHost(), 'passkey-prompt')).toBeNull();

    app?.unmount();
    seed({ postRegistrationPrompt: 'passkey' });

    expect(dialog(mountHost(), 'passkey-prompt')).not.toBeNull();
});

it('always mounts the tour, which owns whether it has anything to show', () => {
    expect(dialog(mountHost(), 'tour')).not.toBeNull();
});
