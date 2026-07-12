import stylistic from '@stylistic/eslint-plugin';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import vuejsAccessibility from 'eslint-plugin-vuejs-accessibility';

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
        //   - form-control-has-label / label-has-for: mostly false positives against
        //     shadcn `<Label>` / reka-ui form composition (the control association is
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
            'vuejs-accessibility/label-has-for': 'warn',
            'vuejs-accessibility/tabindex-no-positive': 'warn',
            'vuejs-accessibility/no-autofocus': 'warn',
            'vuejs-accessibility/no-redundant-roles': 'warn',
            'vuejs-accessibility/no-static-element-interactions': 'warn',
            'vuejs-accessibility/aria-unsupported-elements': 'warn',
            'vuejs-accessibility/mouse-events-have-key-events': 'warn',
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
