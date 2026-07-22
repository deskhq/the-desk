import { RuleTester } from 'eslint';
import { describe, expect, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import rule, {
    findDestructiveTextUtilities,
} from './no-destructive-fill-as-text.js';

describe('findDestructiveTextUtilities', () => {
    it('flags the bare fill utility with the accessible text token', () => {
        expect(
            findDestructiveTextUtilities('flex text-xs text-destructive'),
        ).toEqual([
            {
                start: 13,
                original: 'text-destructive',
                suggestion: 'text-destructive-text',
            },
        ]);
    });

    it('reports every occurrence in a class string', () => {
        expect(
            findDestructiveTextUtilities(
                'text-destructive hover:text-destructive focus:text-destructive',
            ),
        ).toHaveLength(3);
    });

    it('keeps variant, important and opacity modifiers around the utility', () => {
        const variants = findDestructiveTextUtilities(
            "data-[variant=destructive]:text-destructive *:[svg]:!text-destructive *:data-[slot=alert-description]:text-destructive/90",
        );

        expect(variants).toHaveLength(3);
        expect(variants.map((match) => match.original)).toEqual([
            'text-destructive',
            'text-destructive',
            'text-destructive',
        ]);
    });

    it('leaves the accessible text token alone', () => {
        expect(
            findDestructiveTextUtilities(
                'text-destructive-text hover:text-destructive-text',
            ),
        ).toEqual([]);
    });

    it('leaves the on-fill foreground token alone', () => {
        expect(
            findDestructiveTextUtilities(
                'bg-destructive text-destructive-foreground',
            ),
        ).toEqual([]);
    });

    it('leaves non-text destructive utilities alone', () => {
        expect(
            findDestructiveTextUtilities(
                'bg-destructive/10 border-destructive/25 ring-destructive',
            ),
        ).toEqual([]);
    });

    it('ignores utilities that merely end in the same word', () => {
        expect(
            findDestructiveTextUtilities('context-destructive'),
        ).toEqual([]);
    });
});

RuleTester.describe = describe;
RuleTester.it = it;

const ruleTester = new RuleTester({
    languageOptions: {
        parser: vueParser,
        ecmaVersion: 2022,
        sourceType: 'module',
    },
});

ruleTester.run('no-destructive-fill-as-text', rule, {
    valid: [
        {
            filename: 'resources/js/components/InputError.vue',
            code: '<template><p class="text-sm text-destructive-text">Nope</p></template>',
        },
        {
            filename: 'resources/js/components/ui/alert/index.ts',
            code: 'export const variant = "bg-destructive text-destructive-foreground"',
        },
        {
            filename: 'resources/js/components/SecurityActivity.vue',
            code: '<template><span class="border-destructive/25 bg-destructive/10" /></template>',
        },
    ],
    invalid: [
        {
            filename: 'resources/js/components/InputError.vue',
            code: '<template><p class="text-sm text-destructive">Nope</p></template>',
            output: '<template><p class="text-sm text-destructive-text">Nope</p></template>',
            errors: [{ messageId: 'preferDestructiveText' }],
        },
        {
            // Every occurrence is flagged, and the variant, `!` and opacity
            // modifiers wrapped around the utility survive the fix.
            filename: 'resources/js/components/MessageActions.vue',
            code: "<template><span :class=\"'hover:text-destructive data-[variant=destructive]:*:[svg]:!text-destructive'\" /></template>",
            output: "<template><span :class=\"'hover:text-destructive-text data-[variant=destructive]:*:[svg]:!text-destructive-text'\" /></template>",
            errors: [
                { messageId: 'preferDestructiveText' },
                { messageId: 'preferDestructiveText' },
            ],
        },
        {
            filename: 'resources/js/components/ui/alert/index.ts',
            code: 'export const variant = "text-destructive bg-card"',
            output: 'export const variant = "text-destructive-text bg-card"',
            errors: [{ messageId: 'preferDestructiveText' }],
        },
    ],
});
