import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * The viewer's frequently-used emoji, shared from the server already ranked,
 * revoked-token-filtered, and padded to a fixed length (see
 * `App\Support\FrequentEmoji`). Native glyphs and custom `:name:` tokens
 * intermix, so consumers resolve shortcodes through `useCustomEmojis`.
 *
 * One source for the hover bar's quick-react cluster and the picker's
 * "Frequently used" strip, so the two never drift.
 */
export function useFrequentEmojis(): { list: ComputedRef<string[]> } {
    const page = usePage();

    return {
        list: computed<string[]>(() => page.props.frequentEmojis ?? []),
    };
}
