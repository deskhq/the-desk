<script setup lang="ts">
import { Download, FileText } from '@lucide/vue';
import { computed, ref } from 'vue';
import MessageLightbox from '@/components/MessageLightbox.vue';
import { useTranslations } from '@/composables/useTranslations';
import {
    fileTypeLabel,
    imageGridColumns,
    imageGridTiles,
    partitionAttachments,
    singleImageSize,
} from '@/lib/attachmentLayout';
import { formatFileSize } from '@/lib/attachments';
import type { AttachmentData } from '@/types/attachments';

const props = defineProps<{
    attachments: AttachmentData[];
    authorName: string;
    createdAt: string;
    // The viewer's configured timezone, so the lightbox's timestamp matches the
    // timeline. Undefined falls back to the browser's local zone.
    viewerTimeZone?: string;
}>();

const { t } = useTranslations();

const partitioned = computed(() => partitionAttachments(props.attachments));
const images = computed(() => partitioned.value.images);
const files = computed(() => partitioned.value.files);
const tiles = computed(() => imageGridTiles(images.value));
const gridColumns = computed(() => imageGridColumns(images.value.length));
const singleBox = computed(() =>
    singleImageSize(
        images.value[0]?.width ?? null,
        images.value[0]?.height ?? null,
    ),
);

const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

function openLightbox(index: number): void {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
}

/** The grid/single preview source: the thumbnail when generated, else the original. */
function previewSrc(attachment: AttachmentData): string {
    return attachment.thumbUrl ?? attachment.url;
}

function isSvg(attachment: AttachmentData): boolean {
    return attachment.mimeType.toLowerCase() === 'image/svg+xml';
}
</script>

<template>
    <div class="mt-1.5 flex flex-col gap-1.5" data-test="message-attachments">
        <!-- Single image: natural ratio, sized from stored dimensions (no shift). -->
        <div
            v-if="images.length === 1"
            class="group relative overflow-hidden rounded-2xl border border-border"
            :style="{
                width: `${singleBox.width}px`,
                height: `${singleBox.height}px`,
            }"
        >
            <img
                :src="previewSrc(images[0])"
                alt=""
                loading="lazy"
                class="size-full object-cover"
            />
            <!-- eslint-disable-next-line local/no-raw-button -- bespoke full-bleed image overlay, not a variant button -->
            <button
                type="button"
                data-test="attachment-image"
                :aria-label="t('Open :name', { name: images[0].filename })"
                class="absolute inset-0 z-10 cursor-zoom-in focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="openLightbox(0)"
            ></button>
            <a
                :href="images[0].url"
                :download="images[0].filename"
                :aria-label="t('Download :name', { name: images[0].filename })"
                class="absolute top-2.5 right-2.5 z-20 flex size-7.5 items-center justify-center rounded-[9px] bg-[rgba(29,26,21,0.72)] text-[#f3efe4] opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-[#f3efe4] focus-visible:outline-none"
            >
                <Download class="size-3.5" />
            </a>
            <span
                class="pointer-events-none absolute bottom-2.5 left-2.5 z-20 inline-flex h-6 max-w-[calc(100%-1.25rem)] items-center truncate rounded-md bg-[rgba(29,26,21,0.72)] px-2.5 text-[11px] font-medium text-[#f3efe4] opacity-0 transition-opacity group-hover:opacity-100"
            >
                {{ images[0].filename }} ·
                {{ formatFileSize(images[0].sizeBytes) }}
            </span>
        </div>

        <!-- 2+ images: a grid, with a "+N" tile folding the overflow. -->
        <div
            v-else-if="images.length >= 2"
            class="grid w-fit gap-1.5"
            :style="{
                gridTemplateColumns: `repeat(${gridColumns}, minmax(0, 150px))`,
            }"
            data-test="attachment-grid"
        >
            <!-- eslint-disable-next-line local/no-raw-button -- bespoke image grid tile, not a variant button -->
            <button
                v-for="tile in tiles"
                :key="tile.attachment.id"
                type="button"
                data-test="attachment-image"
                :aria-label="
                    t('Open :name', { name: tile.attachment.filename })
                "
                class="group relative aspect-square cursor-zoom-in overflow-hidden rounded-xl border border-border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="openLightbox(tile.index)"
            >
                <img
                    :src="previewSrc(tile.attachment)"
                    alt=""
                    loading="lazy"
                    class="size-full object-cover"
                />
                <span
                    v-if="tile.overflow > 0"
                    data-test="attachment-overflow"
                    class="absolute inset-0 flex items-center justify-center bg-[rgba(18,16,12,0.6)] text-2xl font-semibold text-[#f3efe4]"
                    >+{{ tile.overflow }}</span
                >
            </button>
        </div>

        <!-- Non-image files (SVG included): a download card each. -->
        <a
            v-for="file in files"
            :key="file.id"
            :href="file.url"
            :download="file.filename"
            data-test="attachment-file"
            :aria-label="t('Download :name', { name: file.filename })"
            class="flex w-95 max-w-full items-center gap-3 rounded-xl border border-border bg-muted/40 px-3 py-2.5 transition-colors hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-muted text-muted-foreground"
            >
                <FileText class="size-4.5" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span
                    class="truncate text-[13.5px] font-semibold text-foreground"
                >
                    {{ file.filename }}
                </span>
                <span class="text-[11.5px] text-muted-foreground">
                    {{ fileTypeLabel(file.filename, file.mimeType) }} ·
                    {{ formatFileSize(file.sizeBytes)
                    }}<template v-if="isSvg(file)">
                        · {{ t('download only') }}</template
                    >
                </span>
            </span>
            <Download class="size-3.5 shrink-0 text-muted-foreground" />
        </a>

        <MessageLightbox
            v-if="images.length > 0"
            v-model:open="lightboxOpen"
            :images="images"
            :start-index="lightboxIndex"
            :author-name="authorName"
            :created-at="createdAt"
            :viewer-time-zone="viewerTimeZone"
        />
    </div>
</template>
