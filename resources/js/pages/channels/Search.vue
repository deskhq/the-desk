<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    index,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { index as search } from '@/actions/App/Http/Controllers/Channels/SearchController';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { getInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/datetime';
import { renderMessageBody } from '@/lib/messageBody';
import type { MessageSearchResult } from '@/types';

interface TeamData {
    id: string;
    name: string;
    slug: string;
}

const props = defineProps<{
    team: TeamData;
    query: string;
    results: MessageSearchResult[];
}>();

// Seed the box from the server-echoed query so a shared/reloaded search URL
// stays in sync with what's rendered.
const term = ref(props.query);

// Debounce keystrokes into a single scoped reload that only refreshes the
// results (and the echoed query), preserving the input's focus and caret.
const searchPost = useDebouncedPost(
    (value: string) => {
        router.get(
            search(props.team.slug).url,
            { q: value },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['results', 'query'],
            },
        );
    },
    { delay: 300 },
);

watch(term, (value) => {
    searchPost.schedule(value);
});

const page = usePage();

const { map: customEmojis } = useCustomEmojis();

const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

function formatTimestamp(iso: string): string {
    return formatDateTime(iso, viewerTimeZone.value);
}

function jumpHref(result: MessageSearchResult): string {
    return show(
        { team: props.team.slug, channel: result.channelSlug },
        { query: { message: result.message.id } },
    ).url;
}
</script>

<template>
    <Head :title="$t('Search messages')" />

    <header
        class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
    >
        <SidebarTrigger
            class="-ml-1.5 size-8 text-muted-foreground md:hidden"
        />
        <h1 class="text-[15px] font-semibold text-foreground">
            {{ $t('Search messages') }}
        </h1>
        <Link
            :href="index(props.team.slug).url"
            class="ml-auto text-[13px] text-muted-foreground hover:text-foreground"
            >{{ $t('Back') }}</Link
        >
    </header>

    <div class="flex flex-1 justify-center overflow-y-auto px-6 pt-8">
        <div class="w-full max-w-[640px]">
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="term"
                    type="search"
                    :placeholder="$t('Search messages')"
                    :aria-label="$t('Search messages')"
                    autofocus
                    class="h-[38px] rounded-[10px] bg-muted/40 pl-9"
                />
            </div>

            <p
                v-if="props.query === ''"
                class="pt-16 text-center text-sm text-muted-foreground"
            >
                {{ $t('Search your channels for messages.') }}
            </p>

            <p
                v-else-if="props.results.length === 0"
                data-test="search-empty"
                class="pt-16 text-center text-sm text-muted-foreground"
            >
                {{ $t('No messages match “:query”.', { query: props.query }) }}
            </p>

            <template v-else>
                <p class="mt-4 mb-1 text-xs text-muted-foreground">
                    {{ props.results.length }} {{ $t('result')
                    }}{{ props.results.length === 1 ? '' : 's' }}
                </p>

                <ul>
                    <li
                        v-for="result in props.results"
                        :key="result.message.id"
                    >
                        <Link
                            :href="jumpHref(result)"
                            data-test="search-result"
                            class="flex gap-3 rounded-md border-b border-border/60 px-1 py-3 last:border-0 hover:bg-accent/40"
                        >
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-primary/10 text-[12px] font-semibold text-primary select-none"
                                aria-hidden="true"
                            >
                                {{ getInitials(result.message.user.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-baseline gap-2 text-[13px]"
                                >
                                    <span class="font-semibold text-foreground">
                                        {{ result.message.user.name }}
                                    </span>
                                    <span class="text-muted-foreground/70">
                                        <span class="text-muted-foreground/50"
                                            >#</span
                                        >{{ result.channelName }}
                                    </span>
                                    <span
                                        class="ml-auto shrink-0 text-[11px] text-muted-foreground/70"
                                    >
                                        {{
                                            formatTimestamp(
                                                result.message.createdAt,
                                            )
                                        }}
                                    </span>
                                </div>
                                <p
                                    class="mt-0.5 line-clamp-2 text-[14px] leading-[1.5] break-words text-foreground/90"
                                >
                                    <span
                                        v-html="
                                            renderMessageBody(
                                                result.message.body,
                                                result.message.mentions,
                                                customEmojis,
                                            )
                                        "
                                    ></span>
                                </p>
                            </div>
                        </Link>
                    </li>
                </ul>
            </template>
        </div>
    </div>
</template>
