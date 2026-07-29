import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { MessageReminder } from '@/types';
import ReminderNudge from './ReminderNudge.vue';

function reminder(overrides: Partial<MessageReminder> = {}): MessageReminder {
    return {
        id: 'rem-1',
        messageId: 'msg-1',
        remindAt: '2030-01-01T10:00:00+00:00',
        teamSlug: 'acme',
        channelSlug: 'war-room',
        channelName: 'war-room',
        authorName: 'Jordan West',
        body: 'the secret plan',
        isDeleted: false,
        isAccessible: true,
        ...overrides,
    };
}

async function render(
    overrides: Partial<MessageReminder> = {},
): Promise<string> {
    const app = createSSRApp({
        render: () => h(ReminderNudge, { reminder: reminder(overrides) }),
    });

    app.config.globalProperties.$t = (key: string) => key;

    return renderToString(app);
}

describe('ReminderNudge inaccessible reminders', () => {
    it('drops the open and snooze actions once the channel is out of reach', async () => {
        // The payload is left populated on purpose: the nudge must not surface
        // the body or the author even if the server ever stopped blanking them.
        const html = await render({ isAccessible: false, channelName: null });

        expect(html).not.toContain('data-test="reminder-nudge-open"');
        expect(html).not.toContain('data-test="reminder-nudge-snooze"');
        expect(html).not.toContain('Jordan West');
        expect(html).not.toContain('the secret plan');
        expect(html).toContain('No longer available');
        expect(html).toContain('You no longer have access to this channel.');
        // Acknowledging is the one thing still on offer.
        expect(html).toContain('data-test="reminder-nudge-done"');
        expect(html).toContain('data-test="reminder-nudge-close"');
    });

    it('keeps every action and the quote for a reminder still in reach', async () => {
        const html = await render();

        expect(html).toContain('data-test="reminder-nudge-open"');
        expect(html).toContain('data-test="reminder-nudge-snooze"');
        expect(html).toContain('the secret plan');
    });
});

describe('ReminderNudge author identity', () => {
    it('leaves a human author unbadged', async () => {
        const html = await render();

        expect(html).toContain('Jordan West');
        expect(html).not.toContain('data-test="author-bot-badge"');
    });

    it('shows a saved bot message under its display identity, badged', async () => {
        const html = await render({
            authorName: 'Deploy Bot',
            authorIsBot: true,
            authorOverride: { name: 'Release Train', avatar: null },
        });

        // The override replaces the bot's own name rather than sitting beside it.
        expect(html).toContain('Release Train');
        expect(html).not.toContain('Deploy Bot');
        expect(html).toContain('data-test="author-bot-badge"');
    });

    it('never names an author the viewer may no longer see', async () => {
        const html = await render({
            isAccessible: false,
            authorName: 'Deploy Bot',
            authorIsBot: true,
            authorOverride: { name: 'Release Train', avatar: null },
        });

        expect(html).not.toContain('Release Train');
        expect(html).not.toContain('Deploy Bot');
    });
});
