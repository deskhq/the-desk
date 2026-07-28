import stylistic from '@stylistic/eslint-plugin';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import vuejsAccessibility from 'eslint-plugin-vuejs-accessibility';
import noArbitraryTailwindSpacing from './eslint-rules/no-arbitrary-tailwind-spacing.js';
import noDestructiveFillAsText from './eslint-rules/no-destructive-fill-as-text.js';
import noRawButton from './eslint-rules/no-raw-button.js';
import noSmallMobileInputText from './eslint-rules/no-small-mobile-input-text.js';
import noStandaloneInputError from './eslint-rules/no-standalone-input-error.js';

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
                    'no-destructive-fill-as-text': noDestructiveFillAsText,
                    'no-raw-button': noRawButton,
                    'no-small-mobile-input-text': noSmallMobileInputText,
                    'no-standalone-input-error': noStandaloneInputError,
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
            // Keep the `--destructive` fill token out of text colours: it reads
            // below WCAG AA as inline text on the dark card and on the
            // `bg-destructive/10` tint (#678, #717). `error` (auto-fixable via
            // `./vendor/bin/sail npm run lint`) because every occurrence was
            // migrated to `text-destructive-text`, so the gate stays clean.
            'local/no-destructive-fill-as-text': 'error',
            // iOS Safari zooms the whole page onto a focused field whose font
            // size is under 16px, and never zooms back out (#855). `error`
            // (auto-fixable via `./vendor/bin/sail npm run lint`) because every
            // occurrence was migrated to `text-base md:<size>`, so the gate
            // stays clean and a new sub-16px field cannot land silently.
            'local/no-small-mobile-input-text': 'error',
            // A field's error belongs to `<FormField>` or, where the field
            // cannot take that shape, to `<FieldError>`: both reserve its space
            // and draw it out of flow, so an error cannot shift the form
            // (#883). A bare `<InputError>` joins the flow instead, which left
            // the same field behaving differently depending on the page it was
            // on (#894). `error` because every occurrence was converted, so a
            // new fork cannot land silently.
            'local/no-standalone-input-error': 'error',
            // Cap how large a single file may grow, so the shell's biggest
            // components can only shrink. Several grew past a thousand lines
            // with nothing to stop them — `MainLayout.vue` reached 1643 raw
            // lines before anyone called it (#956) — and `max-lines` counts
            // the whole `.vue` file, template included, not just the
            // `<script>` block. `error` rather than `warn` because a warning
            // leaves `lint:check` green and a new 900-line file lands anyway.
            // Blank lines and comments are skipped: the conventions here ask
            // for JSDoc on declarations and for *why* comments, and charging
            // those against the budget would punish exactly the behaviour the
            // conventions require. Today's offenders are grandfathered by
            // explicit path further down (#957).
            'max-lines': [
                'error',
                { max: 400, skipBlankLines: true, skipComments: true },
            ],
            // Every toast in the app goes through `useToast()`, which is where
            // the duration policy, the merge key and the action slot live. A
            // call site reaching for `vue-sonner` directly would bypass all
            // three, which is how the app ended up with no place to put them
            // (#978). Exempted just below for `useToast` itself — the one
            // module allowed to see the package (`components/ui/` is not
            // linted at all, so `sonner/Sonner.vue` needs no entry).
            'no-restricted-imports': [
                'error',
                {
                    paths: [
                        {
                            name: 'vue-sonner',
                            message:
                                'Raise toasts through useToast() from @/composables/useToast instead.',
                        },
                    ],
                },
            ],
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
        // `useToast` is the app's toast boundary: it wraps `vue-sonner` so no
        // other module has to, which is precisely what the restriction above
        // exists to guarantee everywhere else.
        files: ['resources/js/composables/useToast.ts'],
        rules: {
            'no-restricted-imports': 'off',
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
        // The rules' own tests embed the utilities they flag as fixtures, and
        // `no-destructive-fill-as-text` matches its own detection regex; don't
        // let the rules rewrite either.
        files: ['eslint-rules/**'],
        rules: {
            'local/no-arbitrary-tailwind-spacing': 'off',
            'local/no-destructive-fill-as-text': 'off',
        },
    },
    {
        // Every file that already breached the `max-lines` cap when it was
        // introduced (#957), listed by explicit path: a glob would hand new
        // files the same free pass, which is the one thing the rule exists to
        // prevent. The counts come from running the rule itself, not `wc -l`
        // — six files over 400 raw lines fall under it once blanks and
        // comments are skipped, and are deliberately absent here rather than
        // being granted another 60 lines of room.
        //
        // This is a burn-down list, not a settlement: each entry is a file
        // waiting to be split (#956 takes the first, `MainLayout.vue`).
        // `eslint-rules/max-lines-policy.test.ts` fails as soon as an entry
        // stops breaching the threshold, so the list can only shrink.
        files: [
            'resources/js/components/QuickSwitcher.vue',
            'resources/js/components/UserStatusDialog.vue',
            'resources/js/composables/useAttachmentUploads.test.ts',
            'resources/js/composables/useMessageActions.test.ts',
            'resources/js/composables/useMessageActions.ts',
            'resources/js/layouts/MainLayout.vue',
            'resources/js/pages/teams/Analytics.vue',
            'resources/js/pages/teams/AuditExports.vue',
            'resources/js/pages/teams/Groups.vue',
        ],
        rules: {
            'max-lines': 'off',
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
            // Ambient types transformed out of the PHP `Data` classes and
            // enums. Like the Wayfinder output above and below it, it is
            // git-ignored build output nobody hand-writes, and it is absent
            // in CI's lint job (which never runs `typescript:transform`) — so
            // linting it can only ever fail locally, on a file whose shape is
            // not ours to change.
            'resources/js/generated/**',
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
