import { describe, expect, it } from 'vitest';
import {
    customEmojiList,
    parseCustomEmojiToken,
    resolveCustomEmoji,
    searchCustomEmoji,
} from '@/lib/customEmoji';
import type { CustomEmojiMap } from '@/lib/customEmoji';

const map: CustomEmojiMap = {
    'party-otter': 'https://cdn.test/party-otter.png',
    shipit: 'https://cdn.test/shipit.png',
    'cozy-coffee': 'https://cdn.test/cozy-coffee.png',
};

describe('resolveCustomEmoji', () => {
    it('returns the url for a known name', () => {
        expect(resolveCustomEmoji('shipit', map)).toBe(
            'https://cdn.test/shipit.png',
        );
    });

    it('returns null for an unknown (e.g. revoked) name', () => {
        expect(resolveCustomEmoji('gone', map)).toBeNull();
    });

    it('does not resolve inherited object properties', () => {
        expect(resolveCustomEmoji('toString', map)).toBeNull();
    });
});

describe('parseCustomEmojiToken', () => {
    it('resolves a whole :name: token', () => {
        expect(parseCustomEmojiToken(':party-otter:', map)).toEqual({
            name: 'party-otter',
            url: 'https://cdn.test/party-otter.png',
        });
    });

    it('returns null for a native unicode emoji', () => {
        expect(parseCustomEmojiToken('🎉', map)).toBeNull();
    });

    it('returns null for a shortcode that does not resolve', () => {
        expect(parseCustomEmojiToken(':gone:', map)).toBeNull();
    });

    it('returns null when the value is not a whole token', () => {
        expect(parseCustomEmojiToken('a :shipit: b', map)).toBeNull();
    });
});

describe('customEmojiList', () => {
    it('lists entries sorted by name', () => {
        expect(customEmojiList(map).map((entry) => entry.name)).toEqual([
            'cozy-coffee',
            'party-otter',
            'shipit',
        ]);
    });
});

describe('searchCustomEmoji', () => {
    it('returns the full list for an empty query', () => {
        expect(searchCustomEmoji('', map)).toHaveLength(3);
    });

    it('ignores a leading colon in the query', () => {
        expect(searchCustomEmoji(':part', map).map((e) => e.name)).toEqual([
            'party-otter',
        ]);
    });

    it('matches a substring anywhere in the name', () => {
        expect(searchCustomEmoji('coffee', map).map((e) => e.name)).toEqual([
            'cozy-coffee',
        ]);
    });

    it('returns nothing when no name matches', () => {
        expect(searchCustomEmoji('zzz', map)).toEqual([]);
    });
});
