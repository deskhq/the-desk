import { describe, expect, it } from 'vitest';
import {
    authorOverrideKey,
    displayAuthorAvatar,
    displayAuthorName,
    marksAuthorAsBot,
} from '@/lib/authorIdentity';

describe('displayAuthorName', () => {
    it('falls back to the author name when nothing is overridden', () => {
        expect(displayAuthorName('Deploy Bot', null)).toBe('Deploy Bot');
        expect(displayAuthorName('Deploy Bot', undefined)).toBe('Deploy Bot');
    });

    it('prefers the overridden name', () => {
        expect(
            displayAuthorName('Deploy Bot', {
                name: 'Release Train',
                avatar: null,
            }),
        ).toBe('Release Train');
    });

    it('keeps the author name for an icon-only override', () => {
        expect(
            displayAuthorName('Deploy Bot', {
                name: null,
                avatar: '/images/proxy?url=train',
            }),
        ).toBe('Deploy Bot');
    });
});

describe('displayAuthorAvatar', () => {
    it('shows a human their own avatar', () => {
        expect(displayAuthorAvatar('/avatar.png', false, null)).toBe(
            '/avatar.png',
        );
    });

    it('shows a bot no image, so its glyph tile stands', () => {
        expect(displayAuthorAvatar('/avatar.png', true, null)).toBeNull();
    });

    it('shows the overridden icon even for a bot', () => {
        expect(
            displayAuthorAvatar(null, true, {
                name: 'Release Train',
                avatar: '/images/proxy?url=train',
            }),
        ).toBe('/images/proxy?url=train');
    });

    it('falls back to the glyph when the override carries no icon', () => {
        expect(
            displayAuthorAvatar(null, true, {
                name: 'Release Train',
                avatar: null,
            }),
        ).toBeNull();
    });

    it('normalises a missing avatar to null', () => {
        expect(displayAuthorAvatar(undefined, undefined, null)).toBeNull();
    });
});

describe('marksAuthorAsBot', () => {
    it('marks a bot', () => {
        expect(marksAuthorAsBot(true, null)).toBe(true);
    });

    it('leaves a human unmarked', () => {
        expect(marksAuthorAsBot(false, null)).toBe(false);
        expect(marksAuthorAsBot(undefined, null)).toBe(false);
    });

    it('marks an overridden identity even when the bot flag is missing', () => {
        expect(
            marksAuthorAsBot(undefined, { name: 'Ada Lovelace', avatar: null }),
        ).toBe(true);
    });
});

describe('authorOverrideKey', () => {
    it('collapses to a single key when nothing is overridden', () => {
        expect(authorOverrideKey(null)).toBe(authorOverrideKey(undefined));
    });

    it('separates two different display identities', () => {
        expect(
            authorOverrideKey({ name: 'Release Train', avatar: null }),
        ).not.toBe(authorOverrideKey({ name: 'Nightly', avatar: null }));
    });

    it('separates two identities that differ only by icon', () => {
        expect(
            authorOverrideKey({ name: 'Release Train', avatar: '/a.png' }),
        ).not.toBe(
            authorOverrideKey({ name: 'Release Train', avatar: '/b.png' }),
        );
    });

    it('does not confuse a name for an icon', () => {
        expect(authorOverrideKey({ name: 'a', avatar: null })).not.toBe(
            authorOverrideKey({ name: null, avatar: 'a' }),
        );
    });

    it('separates an overridden identity from an unoverridden one', () => {
        expect(authorOverrideKey({ name: null, avatar: null })).not.toBe(
            authorOverrideKey(null),
        );
    });
});
