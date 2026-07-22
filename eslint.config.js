import stylistic from '@stylistic/eslint-plugin';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import vuejsAccessibility from 'eslint-plugin-vuejs-accessibility';
import noArbitraryTailwindSpacing from './eslint-rules/no-arbitrary-tailwind-spacing.js';
import noRawButton from './eslint-rules/no-raw-button.js';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    ...vuejsAccessibility.configs['flat/recommended'],
    vueTsConfigs.recommended,
    {
        plugins: {
            import: importPlugin,
            local: {
                rules: {
                    'no-arbitrary-tailwind-spacing': noArbitraryTailwindSpacing,
                    'no-raw-button': noRawButton,
                },
            },
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: ['builtin', 'external', 'internal', 'parent', 'sibling', 'index'],
                    alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
            // Surface arbitrary `[Npx]` spacing utilities that have an exact
            // Tailwind scale equivalent (e.g. `size-[38px]` -> `size-9.5`).
            // `warn` (visible, auto-fixable via `npm run lint`) so the gate stays
            // green while the existing occurrences are burned down over time.
            'local/no-arbitrary-tailwind-spacing': 'warn',
            // Steer shared button styling onto the `<Button>` primitive: raw
            // `<button>` outside `components/ui/` is an `error`, so CI catches
            // new stray ones. Genuinely bespoke controls opt out per-occurrence
            // with `<!-- eslint-disable-next-line local/no-raw-button -- reason -->`.
            'local/no-raw-button': 'error',
            // XSS trust boundary. Every run of HTML the client renders as markup
            // must go through `<SafeHtml>`, which sanitizes it with DOMPurify
            // against a named allowlist; a raw `v-html` anywhere else would
            // bypass that boundary with nothing to catch it. Exempted for
            // `SafeHtml.vue` itself just below — the one place the directive is
            // allowed to appear.
            'vue/no-v-html': 'error',
        },
    },
    {
        // `<SafeHtml>` owns the app's only `v-html`: it sanitizes its input
        // before rendering it, which is precisely what the rule exists to
        // guarantee everywhere else.
        files: ['resources/js/components/SafeHtml.vue'],
        rules: {
            'vue/no-v-html': 'off',
            // The directive sits on a `<component :is="as">`, which the rule
            // reads as a component (where `v-html` would not render). `as` is
            // typed to a closed set of plain HTML tags, so the case the rule
            // guards against is unreachable here.
            'vue/no-v-text-v-html-on-component': 'off',
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        // Accessibility: `vuejs-accessibility/flat/recommended` is enabled above at
        // `error`, so every currently-clean rule blocks new violations immediately.
        // The rules below already have pre-existing violations across the shell that
        // the a11y remediation slices burn down; they are `warn` (visible, tracked)
        // until then, at which point each is flipped back to `error`:
        //   - form-control-has-label: mostly false positives against shadcn
        //     `<Label>` / reka-ui form composition (the control association is
        //     established at runtime, invisible to static analysis) mixed with a few
        //     real gaps (e.g. the composer textarea) — see #268.
        //   - tabindex-no-positive / no-static-element-interactions /
        //     mouse-events-have-key-events / aria-unsupported-elements: keyboard &
        //     ARIA gaps handled in the shell (#267) and timeline/composer (#268) slices.
        //   - no-redundant-roles: reconciled while adding list/log semantics (#268).
        //   - no-autofocus: intentional modal/quick-switcher focus management, reviewed
        //     alongside the focus-management work (#267).
        rules: {
            'vuejs-accessibility/form-control-has-label': 'warn',
            // Our labels associate their control via `for`/`id` across custom
            // wrapper components (`<Label for>` + `<PasswordInput id>` /
            // `<Input id>` / …), which the default `{ every: ['nesting', 'id'] }`
            // requirement can't see. Accept either an `id` association or a nested
            // control (registering our control wrappers so nesting is recognised),
            // which clears the false positives and restores the rule at `error`.
            'vuejs-accessibility/label-has-for': [
                'error',
                {
                    required: { some: ['nesting', 'id'] },
                    controlComponents: [
                        'Input',
                        'PasswordInput',
                        'Checkbox',
                        'NativeSelect',
                        'SelectTrigger',
                    ],
                },
            ],
            'vuejs-accessibility/tabindex-no-positive': 'warn',
            'vuejs-accessibility/no-autofocus': 'warn',
            'vuejs-accessibility/no-redundant-roles': 'warn',
            'vuejs-accessibility/no-static-element-interactions': 'warn',
            'vuejs-accessibility/aria-unsupported-elements': 'warn',
            'vuejs-accessibility/mouse-events-have-key-events': 'warn',
        },
    },
    {
        // The rule's own tests embed arbitrary `[Npx]` utilities as fixtures;
        // don't let the rule rewrite them.
        files: ['eslint-rules/**/*.test.ts'],
        rules: {
            'local/no-arbitrary-tailwind-spacing': 'off',
        },
    },
    {
        ignores: [
            '.claude',
            'vendor',
            'node_modules',
            'docs',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            'vite.config.ts',
            'vitest.config.ts',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier,
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
        },
    },
);
