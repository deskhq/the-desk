<script setup lang="ts">
import { computed } from 'vue';
import { formatNumber } from '@/lib/numbers';
import type { ChannelActivity } from '@/types';

type Props = {
    channels: ChannelActivity[];
};

const props = defineProps<Props>();

const channelMax = computed(() =>
    Math.max(1, ...props.channels.map((channel) => channel.count)),
);

function channelWidth(count: number): string {
    return `${Math.round((count / channelMax.value) * 100)}%`;
}

function channelColor(indexInList: number): string {
    if (indexInList === 0) {
        return 'var(--chart-1)';
    }

    return indexInList < 3 ? 'var(--chart-3)' : 'var(--chart-4)';
}
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
    >
        <div class="mb-4 flex items-baseline gap-2.5">
            <h3 class="text-sm font-semibold">
                {{ $t('Most-active channels') }}
            </h3>
            <span class="text-xs text-muted-foreground">{{
                $t('by messages')
            }}</span>
        </div>

        <p
            v-if="channels.length === 0"
            class="text-sm text-muted-foreground"
            data-test="analytics-channels-empty"
        >
            {{ $t('No channel activity in this window.') }}
        </p>

        <ul v-else class="space-y-3" data-test="analytics-channels">
            <li
                v-for="(channel, channelIndex) in channels"
                :key="channel.id"
                class="flex flex-col gap-1.5"
            >
                <div class="flex items-baseline gap-2 text-[13px]">
                    <span class="font-medium">
                        <span class="text-muted-foreground">#</span
                        >{{ channel.name }}
                    </span>
                    <span
                        class="ml-auto text-xs text-muted-foreground tabular-nums"
                    >
                        {{ formatNumber(channel.count) }}
                    </span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full"
                        :style="{
                            width: channelWidth(channel.count),
                            background: channelColor(channelIndex),
                        }"
                    ></div>
                </div>
            </li>
        </ul>
    </section>
</template>
