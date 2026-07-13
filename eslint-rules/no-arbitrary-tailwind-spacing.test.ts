import { describe, expect, it } from 'vitest';
import {
    findSpacingReplacements,
    scaleSuffix,
} from './no-arbitrary-tailwind-spacing.js';

describe('scaleSuffix', () => {
    it.each([
        [38, '9.5'],
        [19, '4.75'],
        [30, '7.5'],
        [16, '4'],
        [2, '0.5'],
        [1, 'px'],
    ])('maps %ipx to the scale suffix %s', (px, expected) => {
        expect(scaleSuffix(px)).toBe(expected);
    });
});

describe('findSpacingReplacements', () => {
    it('flags the spacing utilities from the issue with their scale equivalents', () => {
        expect(
            findSpacingReplacements('flex size-[38px] shrink-0'),
        ).toEqual([
            { start: 5, original: 'size-[38px]', suggestion: 'size-9.5' },
        ]);

        expect(findSpacingReplacements('h-[19px]')[0]?.suggestion).toBe(
            'h-4.75',
        );
        expect(findSpacingReplacements('h-[30px]')[0]?.suggestion).toBe(
            'h-7.5',
        );
    });

    it('reports every occurrence in a class string', () => {
        const suggestions = findSpacingReplacements(
            'gap-[18px] px-[18px] pt-[18px]',
        ).map((replacement) => replacement.suggestion);

        expect(suggestions).toEqual(['gap-4.5', 'px-4.5', 'pt-4.5']);
    });

    it('preserves negative and variant prefixes while replacing only the value', () => {
        expect(findSpacingReplacements('-left-[52px]')).toEqual([
            { start: 1, original: 'left-[52px]', suggestion: 'left-13' },
        ]);

        const variant = findSpacingReplacements('hover:mt-[8px]')[0];
        expect(variant?.original).toBe('mt-[8px]');
        expect(variant?.suggestion).toBe('mt-2');
    });

    it('prefers the longest matching prefix', () => {
        expect(findSpacingReplacements('min-h-[18px]')[0]?.suggestion).toBe(
            'min-h-4.5',
        );
    });

    it('ignores utilities that are not on the spacing scale', () => {
        expect(
            findSpacingReplacements(
                'rounded-[10px] text-[10.5px] leading-[19px] ring-[3px] max-w-[680px]',
            ),
        ).toEqual([]);
    });

    it('ignores non-integer pixel values that have no exact scale step', () => {
        expect(findSpacingReplacements('size-[10.5px]')).toEqual([]);
    });
});
