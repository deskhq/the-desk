<script setup lang="ts">
/**
 * PROTOTYPE — throwaway (#1244). Variant C — instant chrome, ghost timeline.
 *
 * The same instant chrome as B, with the stage filled by shimmer rows shaped
 * like messages. Bottom-anchored, because a conversation grows upward from the
 * composer and a top-anchored skeleton reads as a document, not a chat.
 *
 * The bet is that a populated-looking stage reads as "arriving" where an empty
 * one reads as "empty". The risk is the opposite: seven fake messages that
 * vanish and are replaced wholesale is a bigger visual event than a pause.
 */
import ChannelMasthead from '@/components/ChannelMasthead.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { SyntheticMasthead } from './syntheticProps';

const props = defineProps<{ synthetic: SyntheticMasthead }>();

/** Widths chosen to break the eye's expectation of a uniform block. */
const rows = [
    { lines: ['72%', '46%'], tall: false },
    { lines: ['58%'], tall: false },
    { lines: ['88%', '81%', '39%'], tall: true },
    { lines: ['44%'], tall: false },
    { lines: ['66%', '52%'], tall: false },
    { lines: ['91%'], tall: false },
    { lines: ['37%'], tall: false },
];
</script>

<template>
    <div class="absolute inset-0 z-30 flex flex-col bg-background">
        <ChannelMasthead v-bind="props.synthetic" />

        <div
            class="flex min-h-0 flex-1 flex-col justify-end gap-5 overflow-hidden px-4 pb-5 @2xl:px-7"
        >
            <div
                v-for="(row, index) in rows"
                :key="index"
                class="flex items-start gap-3"
            >
                <Skeleton class="size-9 shrink-0 rounded-lg" />
                <div class="min-w-0 flex-1 space-y-2 pt-0.5">
                    <div class="flex items-center gap-2">
                        <Skeleton class="h-3 w-24 rounded" />
                        <Skeleton class="h-2.5 w-10 rounded opacity-60" />
                    </div>
                    <Skeleton
                        v-for="(width, line) in row.lines"
                        :key="line"
                        class="h-3 rounded"
                        :style="{ width }"
                    />
                </div>
            </div>
        </div>

        <div class="shrink-0 px-4 pb-4 @2xl:px-7">
            <div
                class="rounded-xl border border-border bg-card px-4 py-3 text-[14px] text-muted-foreground"
            >
                {{ $t('Message :name', { name: props.synthetic.title }) }}
            </div>
        </div>
    </div>
</template>
