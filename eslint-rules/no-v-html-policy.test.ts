import { ESLint } from 'eslint';
import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Guards the wiring rather than a rule implementation: `vue/no-v-html` is what
 * keeps the DOMPurify trust boundary from being bypassed, and it only does that
 * if it is an error everywhere except the one component that owns the boundary.
 * Resolving the project config per file path is what proves both halves — a
 * config typo, a stray `files` glob, or someone flipping the rule off would fail
 * here.
 */
const eslint = new ESLint();

const ORDINARY_COMPONENT = 'resources/js/components/Probe.vue';
const ORDINARY_PAGE = 'resources/js/pages/channels/Probe.vue';
const SAFE_HTML = 'resources/js/components/SafeHtml.vue';

const PROBE_PATHS = [ORDINARY_COMPONENT, ORDINARY_PAGE, SAFE_HTML];

/** Resolved flat config per probe path, populated once before the assertions. */
const configs = new Map<string, { rules?: Record<string, unknown[]> }>();

/**
 * Building the flat config and its vue parser pipeline costs a second or two,
 * and it is paid entirely by whichever resolution happens to run first — so
 * charging it to the first test made this file cross vitest's 5s per-test
 * timeout under full-suite load (#804). Resolving every probe path here moves
 * that cost onto a hook with a budget big enough for it, and leaves the tests
 * as pure assertions over the already-resolved configs.
 */
beforeAll(async () => {
    for (const filePath of PROBE_PATHS) {
        configs.set(filePath, await eslint.calculateConfigForFile(filePath));
    }
}, 60_000);

function severityFor(filePath: string, rule = 'vue/no-v-html'): unknown {
    const config = configs.get(filePath);

    if (!config) {
        throw new Error(`No config was resolved for ${filePath}.`);
    }

    return config.rules?.[rule]?.[0];
}

describe('the v-html policy', () => {
    it('errors on v-html in an ordinary component', () => {
        expect(severityFor(ORDINARY_COMPONENT)).toBe(2);
    });

    it('errors on v-html in a page', () => {
        expect(severityFor(ORDINARY_PAGE)).toBe(2);
    });

    it('allows v-html in the SafeHtml primitive that owns the trust boundary', () => {
        expect(severityFor(SAFE_HTML)).toBe(0);
    });

    it('also clears the on-component variant for SafeHtml, whose directive sits on a dynamic <component>', () => {
        expect(
            severityFor(SAFE_HTML, 'vue/no-v-text-v-html-on-component'),
        ).toBe(0);

        expect(
            severityFor(
                ORDINARY_COMPONENT,
                'vue/no-v-text-v-html-on-component',
            ),
        ).toBe(2);
    });
});
