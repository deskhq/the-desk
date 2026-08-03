/**
 * PROTOTYPE — throwaway. Which list shape the palette is wearing.
 *
 * Read once from `?variant=` on the initial URL and held in module scope, so it
 * survives the Inertia visits the palette itself performs. `null` means no
 * variant was asked for and the palette renders exactly as it does on
 * `develop` — the baseline the three variants are judged against.
 */
import { ref } from 'vue';

export type PaletteVariant = 'a' | 'b' | 'c';

export const VARIANTS: PaletteVariant[] = ['a', 'b', 'c'];

export const VARIANT_NAMES: Record<PaletteVariant, string> = {
    a: 'Commands last, fixed groups',
    b: 'Groups reorder by query',
    c: 'Flat list, commands on open',
};

/** Each variant's own promise about what the list holds (#1208 handed these here). */
export const VARIANT_COPY: Record<
    PaletteVariant,
    { placeholder: string; trigger: string; mobileHint: string }
> = {
    a: {
        placeholder: 'Jump to a channel or search messages…',
        trigger: 'Jump to…',
        mobileHint: 'Recent shows before you type · results ranked by activity',
    },
    b: {
        placeholder: 'Search, jump, or run a command…',
        trigger: 'Search or run…',
        mobileHint: 'Recent shows before you type · commands rank as you type',
    },
    c: {
        placeholder: 'Type to jump anywhere, or pick a command',
        trigger: 'Run a command…',
        mobileHint: 'Commands show before you type · type to reach anything',
    },
};

function fromUrl(): PaletteVariant | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const asked = new URLSearchParams(window.location.search).get('variant');

    return VARIANTS.includes(asked as PaletteVariant)
        ? (asked as PaletteVariant)
        : null;
}

export const variant = ref<PaletteVariant | null>(fromUrl());

/** Move to the next or previous variant, keeping the URL shareable. */
export function cycleVariant(step: 1 | -1): void {
    if (variant.value === null) {
        return;
    }

    const next =
        VARIANTS[
            (VARIANTS.indexOf(variant.value) + step + VARIANTS.length) %
                VARIANTS.length
        ];

    variant.value = next;

    const url = new URL(window.location.href);
    url.searchParams.set('variant', next);
    window.history.replaceState({}, '', url);
}
