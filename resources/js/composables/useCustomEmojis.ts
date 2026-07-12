import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import {
    customEmojiList,
    parseCustomEmojiToken,
    resolveCustomEmoji,
    searchCustomEmoji,
} from '@/lib/customEmoji';
import type { CustomEmojiEntry, CustomEmojiMap } from '@/lib/customEmoji';

/**
 * Read the current workspace's custom emoji (a shared `name -> url` map) and the
 * helpers for resolving `:name:` shortcodes. One source for message bodies,
 * reaction pills, and the picker "Custom" strip so they never drift.
 */
export function useCustomEmojis(): {
    map: ComputedRef<CustomEmojiMap>;
    list: ComputedRef<CustomEmojiEntry[]>;
    resolve: (name: string) => string | null;
    parseToken: (value: string) => CustomEmojiEntry | null;
    search: (query: string) => CustomEmojiEntry[];
} {
    const page = usePage();

    const map = computed<CustomEmojiMap>(() => page.props.customEmojis ?? {});
    const list = computed<CustomEmojiEntry[]>(() => customEmojiList(map.value));

    return {
        map,
        list,
        resolve: (name) => resolveCustomEmoji(name, map.value),
        parseToken: (value) => parseCustomEmojiToken(value, map.value),
        search: (query) => searchCustomEmoji(query, map.value),
    };
}
