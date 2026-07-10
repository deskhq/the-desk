<script setup lang="ts">
import { computed } from 'vue';
import { previewHost } from '@/lib/linkPreview';
import type { MessagePreview } from '@/types';

const props = defineProps<{
    preview: MessagePreview;
}>();

// Prefer the site's own name; fall back to the URL's host so the attribution
// line is never empty (it also labels the skeleton while the fetch is pending).
const siteLabel = computed(
    () => props.preview.siteName ?? previewHost(props.preview.url),
);
</script>

<template>
    <div
        v-if="preview.status === 'pending'"
        data-test="link-preview-skeleton"
        class="animate-pulse"
    >
        <div class="h-36 w-full bg-muted-foreground/15"></div>
        <div class="space-y-1.5 p-3">
            <div class="h-2.5 w-1/3 rounded bg-muted-foreground/15"></div>
            <div class="h-2.5 w-3/4 rounded bg-muted-foreground/15"></div>
        </div>
    </div>
    <div v-else data-test="link-preview">
        <img
            v-if="preview.imageUrl"
            :src="preview.imageUrl"
            alt=""
            loading="lazy"
            class="w-full"
        />
        <div class="px-[15px] py-[13px]">
            <p
                class="truncate text-[10px] font-semibold tracking-[0.08em] text-muted-foreground/70 uppercase"
            >
                {{ siteLabel }}
            </p>
            <p
                class="mt-1 font-serif text-[15px] leading-[1.3] font-semibold text-foreground"
            >
                {{ preview.title }}
            </p>
            <p
                v-if="preview.description"
                class="mt-1 line-clamp-3 text-[12.5px] leading-[1.5] text-muted-foreground"
            >
                {{ preview.description }}
            </p>
        </div>
    </div>
</template>
