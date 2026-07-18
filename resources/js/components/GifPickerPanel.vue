<script setup lang="ts">
import { X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import {
    search as searchRoute,
    store as storeRoute,
} from '@/actions/App/Http/Controllers/Channels/GiphyController';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';
import { attachGiphyGif, fetchGiphyPage } from '@/lib/giphy';
import type { AttachmentData } from '@/types/attachments';

const props = withDefaults(
    defineProps<{
        // The channel the picker searches and attaches within.
        teamSlug: string;
        channelSlug: string;
        // The search term to seed from (`/gif cats` → "cats"); blank → trending.
        initialQuery?: string;
        // Debounce for search-as-you-type; 0 in tests for determinism.
        debounceMs?: number;
        // Transport, injected in tests so the panel unit-tests without a network.
        searchGifs?: (
            url: string,
            signal?: AbortSignal,
        ) => Promise<App.Data.GiphySearchData>;
        attachGif?: (url: string, id: string) => Promise<AttachmentData>;
    }>(),
    {
        initialQuery: '',
        debounceMs: 300,
        searchGifs: fetchGiphyPage,
        attachGif: attachGiphyGif,
    },
);

const emit = defineEmits<{
    // A GIF was picked and attached; carries the stored remote attachment.
    select: [attachment: AttachmentData];
    // The picker should close (Escape, the close button, or a backdrop click).
    close: [];
}>();

const { t } = useTranslations();

const query = ref(props.initialQuery);
const results = ref<App.Data.GiphyGifData[]>([]);
const nextOffset = ref<number | null>(null);
const loading = ref(false);
const loadingMore = ref(false);
const errored = ref(false);
const attaching = ref(false);
// The keyboard-active option, or -1 when focus is in the search field.
const activeIndex = ref(-1);

const searchInput = ref<HTMLInputElement | null>(null);
const grid = ref<HTMLElement | null>(null);

const isEmpty = computed(
    () => !loading.value && !errored.value && results.value.length === 0,
);

/** The search endpoint for a given page offset (blank query → trending). */
function searchUrl(offset: number): string {
    return searchRoute.url(
        { team: props.teamSlug, channel: props.channelSlug },
        { query: { q: query.value.trim() || undefined, offset } },
    );
}

let controller: AbortController | null = null;

// False once the panel has unmounted, so an in-flight attach that resolves after
// the user closed the picker doesn't stage a GIF they cancelled.
let alive = true;

/**
 * Load a page of results, replacing them (a new/changed query) or appending
 * (infinite scroll). A superseded in-flight request is aborted so its late
 * response can't clobber the current one.
 */
async function loadPage(offset: number, append: boolean): Promise<void> {
    controller?.abort();
    const request = new AbortController();
    controller = request;

    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
        errored.value = false;
    }

    try {
        const page = await props.searchGifs(searchUrl(offset), request.signal);

        if (request.signal.aborted) {
            return;
        }

        results.value = append
            ? [...results.value, ...page.results]
            : page.results;
        nextOffset.value = page.nextOffset;
    } catch {
        if (request.signal.aborted) {
            return;
        }

        // An infinite-scroll (append) failure keeps the loaded grid and simply
        // stops paging; only an initial-load failure shows the full error state.
        if (append) {
            nextOffset.value = null;

            return;
        }

        results.value = [];
        errored.value = true;
        nextOffset.value = null;
    } finally {
        if (!request.signal.aborted) {
            loading.value = false;
            loadingMore.value = false;
        }
    }
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

watch(query, () => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(() => {
        activeIndex.value = -1;
        void loadPage(0, false);
    }, props.debounceMs);
});

/** Fetch the next page when the grid is scrolled near its end. */
function onScroll(): void {
    const el = grid.value;

    if (
        !el ||
        loadingMore.value ||
        loading.value ||
        nextOffset.value === null
    ) {
        return;
    }

    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 240) {
        void loadPage(nextOffset.value, true);
    }
}

/** Attach the chosen GIF, then hand it back to the composer. */
async function pick(gif: App.Data.GiphyGifData): Promise<void> {
    if (attaching.value) {
        return;
    }

    attaching.value = true;

    try {
        const attachment = await props.attachGif(
            storeRoute.url({
                team: props.teamSlug,
                channel: props.channelSlug,
            }),
            gif.id,
        );

        // The user may have closed the picker while the attach was in flight;
        // don't stage a GIF they cancelled.
        if (!alive) {
            return;
        }

        emit('select', attachment);
    } catch {
        toast.error(t('That GIF could not be added. Try another one.'));
    } finally {
        attaching.value = false;
    }
}

/** Move the active option, clamping into the results range. */
function moveActive(delta: number): void {
    const count = results.value.length;

    if (count === 0) {
        return;
    }

    const next = Math.min(Math.max(activeIndex.value + delta, 0), count - 1);
    activeIndex.value = next;

    nextTick(() => {
        grid.value
            ?.querySelector<HTMLElement>(`[data-gif-index="${next}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');

        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
        event.preventDefault();
        moveActive(activeIndex.value < 0 ? 0 : 1);

        return;
    }

    if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
        event.preventDefault();
        moveActive(-1);

        return;
    }

    if (event.key === 'Enter' && activeIndex.value >= 0) {
        const gif = results.value[activeIndex.value];

        if (gif) {
            event.preventDefault();
            void pick(gif);
        }
    }
}

onMounted(() => {
    void loadPage(0, false);
    nextTick(() => searchInput.value?.focus());
});

onBeforeUnmount(() => {
    alive = false;
    controller?.abort();

    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- the picker is a dialog; the key handler routes Escape/arrow navigation for the listbox it contains -->
    <div
        role="dialog"
        :aria-label="$t('GIF picker')"
        data-test="gif-picker"
        class="absolute bottom-full left-0 z-20 mb-2 flex w-80 flex-col overflow-hidden rounded-2xl border bg-popover shadow-[0_10px_28px_rgba(29,26,21,0.14)]"
        @keydown="onKeydown"
    >
        <div class="flex items-center gap-2 border-b border-border p-2">
            <input
                ref="searchInput"
                v-model="query"
                type="search"
                data-test="gif-search-input"
                role="combobox"
                aria-controls="gif-listbox"
                aria-autocomplete="list"
                :aria-expanded="results.length > 0"
                :aria-activedescendant="
                    activeIndex >= 0 ? `gif-option-${activeIndex}` : undefined
                "
                :aria-label="$t('Search GIFs')"
                :placeholder="$t('Search GIFs')"
                class="h-8 flex-1 rounded-full bg-muted px-3 text-sm outline-none focus:ring-2 focus:ring-brass"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-8 shrink-0"
                :aria-label="$t('Close')"
                @click="emit('close')"
            >
                <X class="size-4" />
            </Button>
        </div>

        <div
            id="gif-listbox"
            ref="grid"
            role="listbox"
            :aria-label="$t('GIF results')"
            class="max-h-72 overflow-y-auto p-2"
            @scroll="onScroll"
        >
            <div
                v-if="loading"
                data-test="gif-loading"
                class="grid grid-cols-2 gap-2"
                aria-hidden="true"
            >
                <div
                    v-for="n in 6"
                    :key="n"
                    class="h-24 animate-pulse rounded-lg bg-muted"
                />
            </div>

            <p
                v-else-if="errored"
                data-test="gif-error"
                class="px-2 py-8 text-center text-sm text-muted-foreground"
            >
                {{ $t('GIFs could not be loaded. Try again.') }}
            </p>

            <p
                v-else-if="isEmpty"
                data-test="gif-empty"
                class="px-2 py-8 text-center text-sm text-muted-foreground"
            >
                {{ $t('No GIFs found.') }}
            </p>

            <div v-else class="grid grid-cols-2 gap-2">
                <!-- eslint-disable-next-line local/no-raw-button -- bespoke GIF-grid cell -->
                <button
                    v-for="(gif, index) in results"
                    :id="`gif-option-${index}`"
                    :key="gif.id"
                    type="button"
                    role="option"
                    tabindex="-1"
                    data-test="gif-option"
                    :data-gif-index="index"
                    :aria-selected="index === activeIndex"
                    :aria-label="gif.description ?? $t('GIF')"
                    :disabled="attaching"
                    class="overflow-hidden rounded-lg border border-transparent bg-muted focus-visible:outline-none aria-selected:border-brass"
                    @click="pick(gif)"
                    @focus="activeIndex = index"
                    @mouseenter="activeIndex = index"
                >
                    <img
                        :src="gif.previewUrl"
                        :alt="gif.description ?? ''"
                        :width="gif.width"
                        :height="gif.height"
                        loading="lazy"
                        class="h-24 w-full object-cover"
                    />
                </button>

                <div
                    v-if="loadingMore"
                    data-test="gif-loading-more"
                    class="col-span-2 py-3 text-center text-xs text-muted-foreground"
                >
                    {{ $t('Loading more…') }}
                </div>
            </div>
        </div>

        <div
            data-test="gif-powered-by"
            class="border-t border-border px-3 py-1.5 text-[10.5px] font-semibold tracking-[0.08em] text-muted-foreground uppercase"
        >
            {{ $t('Powered by GIPHY') }}
        </div>
    </div>
</template>
