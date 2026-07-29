// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { Message } from '@/types';
import PinsPanel from './PinsPanel.vue';

function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hi!',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: {
            pinnedBy: { id: 'pinner', name: 'Alexandra Featherstonehaugh' },
            pinnedAt: '2024-01-01T00:00:00.000Z',
        },
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        ...overrides,
    } as Message;
}

let app: App | null = null;

function mount(componentProps: Record<string, unknown> = {}): {
    host: HTMLElement;
    emitted: Record<string, unknown[][]>;
} {
    const emitted: Record<string, unknown[][]> = {};
    const capture =
        (name: string) =>
        (...args: unknown[]) => {
            (emitted[name] ??= []).push(args);
        };
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(PinsPanel, {
                pins: [message()],
                pinCount: 1,
                canPin: true,
                viewerTimezone: 'UTC',
                onClose: capture('close'),
                onJump: capture('jump'),
                onUnpin: capture('unpin'),
                ...componentProps,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return { host, emitted };
}

function find(host: HTMLElement, name: string): HTMLElement {
    return host.querySelector<HTMLElement>(`[data-test="${name}"]`)!;
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('PinsPanel rows', () => {
    it('lists a row per pin, with who pinned it and a body preview', () => {
        const { host } = mount({
            pins: [message(), message({ id: 'm2', clientUuid: 'uuid-2' })],
            pinCount: 2,
        });

        const row = find(host, 'pins-panel-row');

        expect(
            host.querySelectorAll('[data-test="pins-panel-row"]'),
        ).toHaveLength(2);
        expect(row.textContent).toContain(
            'Pinned by Alexandra Featherstonehaugh',
        );
        expect(row.textContent).toContain('Peer');
        expect(row.textContent).toContain('hi!');
    });

    it('jumps to the message when the row is clicked', () => {
        const { host, emitted } = mount();

        find(host, 'pins-panel-row').click();

        expect(emitted.jump).toEqual([['m1']]);
    });

    it('unpins the message from the row control', () => {
        const { host, emitted } = mount();

        find(host, 'pins-panel-unpin').click();

        expect(emitted.unpin).toHaveLength(1);
        expect((emitted.unpin[0][0] as Message).id).toBe('m1');
        // The control sits beside the row rather than inside it, so unpinning
        // never doubles as a jump.
        expect(emitted.jump).toBeUndefined();
    });

    it('drops the unpin control entirely for a read-only viewer', () => {
        const { host } = mount({ canPin: false });

        expect(host.querySelector('[data-test="pins-panel-unpin"]')).toBeNull();
    });

    it('renders its empty state with no rows', () => {
        const { host } = mount({ pins: [], pinCount: 0 });

        expect(find(host, 'pins-panel-empty').textContent).toContain(
            'Nothing pinned yet',
        );
        expect(host.querySelector('[data-test="pins-panel-row"]')).toBeNull();
    });
});

describe('PinsPanel unpin reachability', () => {
    it('keeps the unpin control in the tab order rather than display-hidden', () => {
        const { host } = mount();

        const unpin = find(host, 'pins-panel-unpin');

        // `hidden`/`invisible` take the control out of the accessibility tree and
        // the tab order, which is what made it mouse-only (#999). Whatever hides
        // it at rest has to keep it focusable.
        expect(unpin.classList.contains('hidden')).toBe(false);
        expect(unpin.classList.contains('invisible')).toBe(false);
        expect(unpin.tabIndex).toBeGreaterThanOrEqual(0);
    });

    it('reveals the unpin control wherever hover cannot fire', () => {
        const { host } = mount();

        const classes = find(host, 'pins-panel-unpin').className;

        // Focus anywhere in the row brings it back for keyboard users, and it is
        // drawn outright on a coarse pointer and in the narrow layout, where
        // :hover never fires at all.
        expect(classes).toContain('group-focus-within/pin:opacity-100');
        expect(classes).toContain('pointer-coarse:opacity-100');
        expect(classes).toContain('max-md:opacity-100');
    });
});

describe('PinsPanel attribution line', () => {
    it('lets the pinned-by name ellipsise while the rest holds its baseline', () => {
        const { host } = mount();

        expect(find(host, 'pins-panel-attribution').className).toContain(
            'truncate',
        );

        for (const name of ['pins-panel-pinned-at', 'pins-panel-jump']) {
            expect(find(host, name).className).toContain('shrink-0');
            expect(find(host, name).className).toContain('whitespace-nowrap');
        }
    });

    it('gives the author line the same shape as the line above it', () => {
        const { host } = mount();

        expect(find(host, 'pins-panel-author').className).toContain('truncate');
        expect(find(host, 'pins-panel-sent-at').className).toContain(
            'shrink-0',
        );
        expect(find(host, 'pins-panel-sent-at').className).toContain(
            'whitespace-nowrap',
        );
    });

    it('reserves no pixel guess for the unpin control width', () => {
        const { host } = mount();

        // `mr-24` was sized around the English "Unpin" and was overrun by the
        // French "Désépingler". The control now sizes its own column, so no
        // margin on the line may stand in for its width (#999).
        expect(find(host, 'pins-panel-jump').className).not.toMatch(/\bmr-/);
        expect(host.innerHTML).not.toContain('mr-24');
    });
});
