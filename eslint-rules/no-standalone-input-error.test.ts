import { RuleTester } from 'eslint';
import { describe, expect, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import rule, {
    isErrorSlotOwner,
    isInputErrorTag,
} from './no-standalone-input-error.js';

describe('isInputErrorTag', () => {
    it('matches the component written in PascalCase', () => {
        expect(isInputErrorTag('InputError')).toBe(true);
    });

    it('matches the kebab-case tag Vue resolves to the same component', () => {
        expect(isInputErrorTag('input-error')).toBe(true);
    });

    it('leaves the components that are allowed to render it alone', () => {
        expect(isInputErrorTag('FieldError')).toBe(false);
        expect(isInputErrorTag('field-error')).toBe(false);
        expect(isInputErrorTag('FormField')).toBe(false);
    });

    it('leaves a plain input alone', () => {
        expect(isInputErrorTag('input')).toBe(false);
    });
});

describe('isErrorSlotOwner', () => {
    it('exempts the reserved-slot wrapper that owns the placement', () => {
        expect(
            isErrorSlotOwner('resources/js/components/FieldError.vue'),
        ).toBe(true);
    });

    it('exempts it on a Windows-style path too', () => {
        expect(
            isErrorSlotOwner(
                'C:\\repo\\resources\\js\\components\\FieldError.vue',
            ),
        ).toBe(true);
    });

    it('does not exempt the component it wraps', () => {
        expect(
            isErrorSlotOwner('resources/js/components/InputError.vue'),
        ).toBe(false);
    });

    it('does not exempt a file that merely mentions the name', () => {
        expect(
            isErrorSlotOwner('resources/js/pages/teams/FieldError.test.ts'),
        ).toBe(false);
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

ruleTester.run('no-standalone-input-error', rule, {
    valid: [
        {
            // The one blessed placement: the wrapper that reserves the space.
            filename: 'resources/js/components/FieldError.vue',
            code: '<template><div class="relative h-7"><InputError :message="message" class="absolute" /></div></template>',
        },
        {
            filename: 'resources/js/pages/teams/Groups.vue',
            code: '<template><FormField :error="form.errors.name" v-slot="{ id }"><Input :id="id" /></FormField></template>',
        },
        {
            filename: 'resources/js/pages/teams/integrations/Index.vue',
            code: '<template><fieldset><legend>Events</legend><FieldError :message="form.errors.events" /></fieldset></template>',
        },
        {
            // Importing the module is not placing it; `<FormField>` and
            // `<FieldError>` both legitimately reach for it.
            filename: 'resources/js/components/FormField.vue',
            code: "<script setup lang=\"ts\">import InputError from '@/components/InputError.vue';</script>",
        },
    ],
    invalid: [
        {
            filename: 'resources/js/pages/teams/Edit.vue',
            code: '<template><div><Input id="name" /><InputError :message="errors.name" /></div></template>',
            errors: [{ messageId: 'preferFieldError' }],
        },
        {
            // Every occurrence is flagged, not just the first on the page.
            filename: 'resources/js/pages/teams/Emojis.vue',
            code: '<template><form><InputError :message="form.errors.image" /><InputError :message="form.errors.name" /></form></template>',
            errors: [
                { messageId: 'preferFieldError' },
                { messageId: 'preferFieldError' },
            ],
        },
        {
            // A component is no more entitled to place it than a page is.
            filename: 'resources/js/components/CreateChannelModal.vue',
            code: '<template><InputError :message="errors.name" /></template>',
            errors: [{ messageId: 'preferFieldError' }],
        },
        {
            // The kebab-case tag resolves to the same component, so writing it
            // that way is not a way around the rule.
            filename: 'resources/js/pages/teams/Groups.vue',
            code: '<template><input-error :message="errors.slug" /></template>',
            errors: [{ messageId: 'preferFieldError' }],
        },
    ],
});
