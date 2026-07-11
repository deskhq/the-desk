import { describe, expect, it } from 'vitest';
import { i18n, setMessages, translate } from './i18n';

describe('translate', () => {
    it('returns the translated line for a known key', () => {
        setMessages('fr', { Notifications: 'Notifications françaises' });

        expect(translate('Notifications')).toBe('Notifications françaises');
    });

    it('falls back to the key itself when the catalog has no entry', () => {
        setMessages('en', {});

        expect(translate('Back to workspace')).toBe('Back to workspace');
    });

    it('interpolates :placeholder replacements', () => {
        setMessages('en', {});

        expect(translate('Signed in as :name', { name: 'Ada' })).toBe(
            'Signed in as Ada',
        );
    });

    it('interpolates numeric replacements', () => {
        setMessages('en', {});

        expect(translate(':count new messages', { count: 3 })).toBe(
            '3 new messages',
        );
    });

    it('supports Laravel-style capitalization variants', () => {
        setMessages('en', {});

        expect(translate(':Greeting there', { Greeting: 'hello' })).toBe(
            'Hello there',
        );
        expect(translate(':GREETING there', { GREETING: 'hello' })).toBe(
            'HELLO there',
        );
    });

    it('replaces longer placeholders before their prefixes', () => {
        setMessages('en', {});

        expect(
            translate(':name_long and :name', { name: 'A', name_long: 'B' }),
        ).toBe('B and A');
    });
});

describe('setMessages', () => {
    it('swaps the active locale and catalog', () => {
        setMessages('fr', { Hello: 'Bonjour' });

        expect(i18n.locale).toBe('fr');
        expect(i18n.messages).toEqual({ Hello: 'Bonjour' });
    });
});
