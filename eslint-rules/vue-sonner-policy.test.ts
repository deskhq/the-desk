import { ESLint } from 'eslint';
import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Guards the wiring rather than a rule implementation: `useToast` is only the
 * single entry point for toasts if nothing else can reach `vue-sonner`, and
 * that holds only while `no-restricted-imports` names the package everywhere
 * except the two files that own the boundary. Resolving the project config per
 * file path is what proves both halves — a config typo, a stray `files` glob,
 * or someone dropping the restriction would fail here.
 */
const eslint = new ESLint();

const ORDINARY_COMPOSABLE = 'resources/js/composables/useProbe.ts';
const ORDINARY_COMPONENT = 'resources/js/components/Probe.vue';
const USE_TOAST = 'resources/js/composables/useToast.ts';

const PROBE_PATHS = [ORDINARY_COMPOSABLE, ORDINARY_COMPONENT, USE_TOAST];

/** Resolved flat config per probe path, populated once before the assertions. */
const configs = new Map<string, { rules?: Record<string, unknown[]> }>();

/**
 * Building the flat config and its vue parser pipeline costs a second or two,
 * and charging it to whichever test runs first has crossed vitest's per-test
 * timeout under full-suite load before (#804). Resolve every probe path on a
 * hook with a budget big enough for it.
 */
beforeAll(async () => {
    for (const filePath of PROBE_PATHS) {
        configs.set(filePath, await eslint.calculateConfigForFile(filePath));
    }
}, 60_000);

/** The paths `no-restricted-imports` forbids for a given file. */
function restrictedPathsFor(filePath: string): string[] {
    const config = configs.get(filePath);

    if (!config) {
        throw new Error(`No config was resolved for ${filePath}.`);
    }

    const entry = config.rules?.['no-restricted-imports'];

    if (!entry || entry[0] === 0 || entry[0] === 'off') {
        return [];
    }

    const options = entry[1] as { paths?: { name: string }[] } | undefined;

    return (options?.paths ?? []).map((path) => path.name);
}

describe('the vue-sonner policy', () => {
    it('forbids reaching for vue-sonner from an ordinary composable', () => {
        expect(restrictedPathsFor(ORDINARY_COMPOSABLE)).toContain('vue-sonner');
    });

    it('forbids reaching for vue-sonner from an ordinary component', () => {
        expect(restrictedPathsFor(ORDINARY_COMPONENT)).toContain('vue-sonner');
    });

    it('allows it in useToast, the composable that owns the boundary', () => {
        expect(restrictedPathsFor(USE_TOAST)).not.toContain('vue-sonner');
    });
});
