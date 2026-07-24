import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Linter, RuleTester } from 'eslint';
import { describe, expect, it } from 'vitest';
import { parser as tsParser } from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import rule, { findSmallMobileFont, fontSizeInPixels } from './no-small-mobile-input-text.js';

describe('fontSizeInPixels', () => {
    it('resolves the named font-size scale', () => {
        expect(fontSizeInPixels('text-xs')).toBe(12);
        expect(fontSizeInPixels('text-sm')).toBe(14);
        expect(fontSizeInPixels('text-base')).toBe(16);
        expect(fontSizeInPixels('text-2xl')).toBe(24);
    });

    it('resolves arbitrary pixel, rem and em values', () => {
        expect(fontSizeInPixels('text-[13.5px]')).toBe(13.5);
        expect(fontSizeInPixels('text-[1rem]')).toBe(16);
        expect(fontSizeInPixels('text-[0.875em]')).toBe(14);
        expect(fontSizeInPixels('text-[length:14px]')).toBe(14);
    });

    it('ignores the `!` importance marker', () => {
        expect(fontSizeInPixels('!text-sm')).toBe(14);
    });

    it('returns null for utilities that are not font sizes', () => {
        expect(fontSizeInPixels('text-muted-foreground')).toBeNull();
        expect(fontSizeInPixels('text-center')).toBeNull();
        expect(fontSizeInPixels('text-[--brand]')).toBeNull();
        expect(fontSizeInPixels('truncate')).toBeNull();
    });
});

describe('findSmallMobileFont', () => {
    it('flags an unprefixed sub-16px utility', () => {
        expect(findSmallMobileFont('w-full text-sm text-foreground')).toMatchObject({
            start: 7,
            original: 'text-sm',
            suggestion: 'text-base md:text-sm',
        });
    });

    it('accepts the `text-base md:text-sm` pattern', () => {
        expect(findSmallMobileFont('text-base md:text-sm')).toBeNull();
    });

    it('accepts a mobile override that lands back on 16px', () => {
        expect(findSmallMobileFont('text-sm max-md:text-base')).toBeNull();
    });

    it('ignores utilities behind a min-width breakpoint or a state variant', () => {
        expect(findSmallMobileFont('md:text-sm')).toBeNull();
        expect(findSmallMobileFont('placeholder:text-xs')).toBeNull();
        expect(findSmallMobileFont('file:text-sm')).toBeNull();
        expect(findSmallMobileFont('@lg:text-sm')).toBeNull();
    });

    it('suggests plain `text-base` when a desktop size is already declared', () => {
        expect(findSmallMobileFont('text-[13px] md:text-[13px]')).toMatchObject({
            original: 'text-[13px]',
            suggestion: 'text-base',
        });
    });

    it('returns null for a class string with no font size at all', () => {
        expect(findSmallMobileFont('h-9 w-full rounded-md')).toBeNull();
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

ruleTester.run('no-small-mobile-input-text', rule, {
    valid: [
        {
            // The blessed pattern: 16px on a phone, the design size from `md` up.
            filename: 'resources/js/components/MessageComposer.vue',
            code: '<template><textarea class="text-base md:text-sm"></textarea></template>',
        },
        {
            // No font size at all inherits the 16px body size.
            filename: 'resources/js/components/MessageComposer.vue',
            code: '<template><input class="h-9 w-full" /></template>',
        },
        {
            // Non-text inputs never trigger the focus zoom.
            filename: 'resources/js/pages/teams/Emojis.vue',
            code: '<template><input type="file" class="text-sm file:text-sm" /></template>',
        },
        {
            filename: 'resources/js/components/MessageComposer.vue',
            code: '<template><input type="checkbox" class="text-sm" /></template>',
        },
        {
            // Only form controls are zoomed to; ordinary text is left alone.
            filename: 'resources/js/components/MessageList.vue',
            code: '<template><p class="text-xs">Edited</p></template>',
        },
        {
            filename: 'resources/js/components/Foo.vue',
            code: '<template><CustomThing class="text-xs" /></template>',
        },
        {
            filename: 'resources/js/pages/channels/Search.vue',
            code: '<template><Input class="text-base md:text-xs" /></template>',
        },
        {
            filename: 'resources/js/components/Foo.vue',
            code: '<template><input :class="[\'text-base\', \'md:text-sm\']" /></template>',
        },
    ],
    invalid: [
        {
            filename: 'resources/js/components/MessageComposer.vue',
            code: '<template><textarea class="flex-1 text-sm text-foreground"></textarea></template>',
            output: '<template><textarea class="flex-1 text-base md:text-sm text-foreground"></textarea></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            filename: 'resources/js/components/GifPickerPanel.vue',
            code: '<template><input type="search" class="text-sm" /></template>',
            output: '<template><input type="search" class="text-base md:text-sm" /></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            filename: 'resources/js/components/ScheduledMessagesDialog.vue',
            code: '<template><textarea class="text-[13.5px]"></textarea></template>',
            output: '<template><textarea class="text-base md:text-[13.5px]"></textarea></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            filename: 'resources/js/components/ui/native-select/NativeSelect.vue',
            code: '<template><select class="text-sm"></select></template>',
            output: '<template><select class="text-base md:text-sm"></select></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            // A dynamic `type` is treated as text entry: the zoom is the safer default.
            filename: 'resources/js/components/Foo.vue',
            code: '<template><input :type="kind" class="text-xs" /></template>',
            output: '<template><input :type="kind" class="text-base md:text-xs" /></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            // The shared control wrappers carry the same hazard as a raw field.
            filename: 'resources/js/pages/channels/Search.vue',
            code: '<template><Input class="mb-1 h-8 text-xs max-md:h-11" /></template>',
            output: '<template><Input class="mb-1 h-8 text-base md:text-xs max-md:h-11" /></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            filename: 'resources/js/layouts/MainLayout.vue',
            code: '<template><Input class="px-2 text-[13px] md:text-[13px]" /></template>',
            output: '<template><Input class="px-2 text-base md:text-[13px]" /></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            // Bound class strings are read the same way as a static attribute.
            filename: 'resources/js/components/Foo.vue',
            code: '<template><textarea :class="[\'text-sm\', extra]"></textarea></template>',
            output: '<template><textarea :class="[\'text-base md:text-sm\', extra]"></textarea></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
        {
            filename: 'resources/js/components/Foo.vue',
            code: '<template><textarea :class="`text-sm ${extra}`"></textarea></template>',
            output: '<template><textarea :class="`text-base md:text-sm ${extra}`"></textarea></template>',
            errors: [{ messageId: 'zoomsOnFocus' }],
        },
    ],
});

/**
 * `eslint.config.js` ignores `resources/js/components/ui/*`, so the shadcn
 * primitives — `Input`, `CommandInput`, `NativeSelect`, the calendar's month and
 * year selects — are the one place a sub-16px field could still land unseen.
 * They are also the widest-reach fields in the app, so lint them here instead.
 */
describe('the shadcn primitives under components/ui/', () => {
    const uiDirectory = fileURLToPath(
        new URL('../resources/js/components/ui', import.meta.url),
    );

    const linter = new Linter();

    const lint = (file: string): Linter.LintMessage[] =>
        linter.verify(
            readFileSync(join(uiDirectory, file), 'utf8'),
            {
                files: ['**/*.vue'],
                languageOptions: {
                    parser: vueParser,
                    ecmaVersion: 2022,
                    sourceType: 'module',
                    parserOptions: { parser: tsParser },
                },
                plugins: { local: { rules: { 'no-small-mobile-input-text': rule } } },
                rules: { 'local/no-small-mobile-input-text': 'error' },
            },
            file,
        );

    const vueFiles = readdirSync(uiDirectory, { recursive: true })
        .map(String)
        .filter((file) => file.endsWith('.vue'));

    it('finds primitives to lint', () => {
        expect(vueFiles.length).toBeGreaterThan(0);
    });

    it('keeps every field at 16px or larger below `md`', () => {
        const violations = vueFiles.flatMap((file) =>
            lint(file).map((message) => `${file}:${message.line} ${message.message}`),
        );

        expect(violations).toEqual([]);
    });
});
