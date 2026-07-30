// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import ChannelContentSummary from './ChannelContentSummary.vue';

/**
 * The one line both the delete dialog and the recently-deleted panel read a
 * channel's weight from, so the counts have to be pluralized and locale-grouped
 * rather than interpolated raw.
 */
let active: Array<{ app: App; container: HTMLElement }> = [];

function mount(summary: {
    messageCount: number;
    fileCount: number;
    memberCount: number;
}): HTMLElement {
    const container = document.createElement('div');
    document.body.appendChild(container);

    const app = createApp(
        defineComponent({
            setup() {
                return () => h(ChannelContentSummary, { summary });
            },
        }),
    );
    app.mount(container);
    active.push({ app, container });

    return container;
}

afterEach(() => {
    for (const { app, container } of active) {
        app.unmount();
        container.remove();
    }

    active = [];
});

describe('ChannelContentSummary', () => {
    it('names each count in the plural, joined into one line', () => {
        const container = mount({
            messageCount: 12,
            fileCount: 3,
            memberCount: 7,
        });

        expect(container.textContent).toBe('12 messages · 3 files · 7 members');
    });

    it('drops to the singular for a count of one', () => {
        const container = mount({
            messageCount: 1,
            fileCount: 1,
            memberCount: 1,
        });

        expect(container.textContent).toBe('1 message · 1 file · 1 member');
    });

    it('keeps the plural for an empty channel rather than reading as one', () => {
        const container = mount({
            messageCount: 0,
            fileCount: 0,
            memberCount: 0,
        });

        expect(container.textContent).toBe('0 messages · 0 files · 0 members');
    });

    it('groups a large count for the active locale', () => {
        const container = mount({
            messageCount: 3412,
            fileCount: 88,
            memberCount: 12,
        });

        expect(container.textContent).toContain('3,412 messages');
    });
});
