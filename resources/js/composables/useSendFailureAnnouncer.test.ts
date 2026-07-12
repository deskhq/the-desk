import { describe, expect, it } from 'vitest';
import { useSendFailureAnnouncer } from '@/composables/useSendFailureAnnouncer';

describe('useSendFailureAnnouncer', () => {
    it('starts with an empty announcement', () => {
        const { announcement } = useSendFailureAnnouncer();

        expect(announcement.value).toBe('');
    });

    it('surfaces the announced message', () => {
        const { announcement, announce } = useSendFailureAnnouncer();

        announce('Your message failed to send.');

        expect(announcement.value).toContain('Your message failed to send.');
    });

    it('re-announces an identical repeat so the live region fires again', () => {
        const { announcement, announce } = useSendFailureAnnouncer();

        announce('Your message failed to send.');
        const first = announcement.value;

        announce('Your message failed to send.');
        const second = announcement.value;

        // The spoken text is unchanged, but the rendered string differs so the
        // polite live region detects a mutation and re-announces the failure.
        expect(second).not.toBe(first);
        expect(second.trim()).toBe('Your message failed to send.');
        expect(first.trim()).toBe('Your message failed to send.');
    });
});
