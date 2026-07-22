import { ESLint } from 'eslint';
import { describe, expect, it } from 'vitest';

/**
 * Guards the wiring rather than a rule implementation: `vue/no-v-html` is what
 * keeps the DOMPurify trust boundary from being bypassed, and it only does that
 * if it is an error everywhere except the one component that owns the boundary.
 * Resolving the project config per file path is what proves both halves — a
 * config typo, a stray `files` glob, or someone flipping the rule off would fail
 * here.
 */
const eslint = new ESLint();

async function severityFor(
    filePath: string,
    rule = 'vue/no-v-html',
): Promise<unknown> {
    const config = await eslint.calculateConfigForFile(filePath);

    return config.rules?.[rule]?.[0];
}

describe('the v-html policy', () => {
    it('errors on v-html in an ordinary component', async () => {
        expect(await severityFor('resources/js/components/Probe.vue')).toBe(2);
    });

    it('errors on v-html in a page', async () => {
        expect(await severityFor('resources/js/pages/channels/Probe.vue')).toBe(
            2,
        );
    });

    it('allows v-html in the SafeHtml primitive that owns the trust boundary', async () => {
        expect(await severityFor('resources/js/components/SafeHtml.vue')).toBe(
            0,
        );
    });

    it('also clears the on-component variant for SafeHtml, whose directive sits on a dynamic <component>', async () => {
        expect(
            await severityFor(
                'resources/js/components/SafeHtml.vue',
                'vue/no-v-text-v-html-on-component',
            ),
        ).toBe(0);

        expect(
            await severityFor(
                'resources/js/components/Probe.vue',
                'vue/no-v-text-v-html-on-component',
            ),
        ).toBe(2);
    });
});
