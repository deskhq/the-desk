<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download, X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize } from '@/lib/attachments';
import { formatTimeOfDay } from '@/lib/datetime';
import type { AttachmentData } from '@/types/attachments';

const props = defineProps<{
    open: boolean;
    images: AttachmentData[];
    startIndex: number;
    authorName: string;
    createdAt: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { t } = useTranslations();

// The image currently on screen. Seeded from the tile that was clicked and
// stepped through the message's images with the arrows; wraps at either end.
const current = ref(props.startIndex);

watch(
    () => props.open,
    (open) => {
        if (open) {
            current.value = props.startIndex;
        }
    },
);

const activeImage = computed<AttachmentData>(() => props.images[current.value]);

const meta = computed(() => {
    const parts = [props.authorName, formatTimeOfDay(props.createdAt)];

    if (activeImage.value.width && activeImage.value.height) {
        parts.push(`${activeImage.value.width}×${activeImage.value.height}`);
    }

    parts.push(formatFileSize(activeImage.value.sizeBytes));

    return parts.join(' · ');
});

function step(delta: number): void {
    const count = props.images.length;
    current.value = (current.value + delta + count) % count;
}

function onKeydown(event: KeyboardEvent): void {
    if (props.images.length < 2) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        step(-1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        step(1);
    }
}
</script>

<template>
    <DialogRoot
        :open="open"
        @update:open="(value) => emit('update:open', value)"
    >
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-[rgba(18,16,12,0.94)] data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                data-test="attachment-lightbox"
                class="fixed inset-0 z-50 flex items-center justify-center p-6 focus:outline-none"
                @keydown="onKeydown"
            >
                <img
                    :src="activeImage.url"
                    :alt="activeImage.filename"
                    class="max-h-[85vh] max-w-[85vw] rounded-lg object-contain shadow-2xl"
                />

                <div class="absolute top-4 left-5 flex flex-col gap-0.5 pr-32">
                    <DialogTitle
                        class="truncate text-[13px] font-semibold text-[#ece7da]"
                    >
                        {{ activeImage.filename }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[11.5px] text-[#8b8370] tabular-nums"
                    >
                        {{ meta }}
                    </DialogDescription>
                </div>

                <div class="absolute top-3 right-4 flex gap-2">
                    <a
                        :href="activeImage.url"
                        :download="activeImage.filename"
                        :aria-label="
                            t('Download :name', { name: activeImage.filename })
                        "
                        class="flex size-8 items-center justify-center rounded-[9px] bg-[rgba(243,239,228,0.12)] text-[#ece7da] transition-colors hover:bg-[rgba(243,239,228,0.22)] focus-visible:ring-2 focus-visible:ring-[#ece7da] focus-visible:outline-none"
                    >
                        <Download class="size-3.5" />
                    </a>
                    <DialogClose
                        :aria-label="t('Close')"
                        class="flex size-8 items-center justify-center rounded-[9px] bg-[rgba(243,239,228,0.12)] text-[#ece7da] transition-colors hover:bg-[rgba(243,239,228,0.22)] focus-visible:ring-2 focus-visible:ring-[#ece7da] focus-visible:outline-none"
                    >
                        <X class="size-3.5" />
                    </DialogClose>
                </div>

                <template v-if="images.length > 1">
                    <!-- eslint-disable-next-line local/no-raw-button -- bespoke lightbox nav control on a dark backdrop -->
                    <button
                        type="button"
                        data-test="lightbox-prev"
                        :aria-label="t('Previous image')"
                        class="absolute top-1/2 left-4 flex size-9 -translate-y-1/2 items-center justify-center rounded-full bg-[rgba(243,239,228,0.12)] text-[#ece7da] transition-colors hover:bg-[rgba(243,239,228,0.22)] focus-visible:ring-2 focus-visible:ring-[#ece7da] focus-visible:outline-none"
                        @click="step(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <!-- eslint-disable-next-line local/no-raw-button -- bespoke lightbox nav control on a dark backdrop -->
                    <button
                        type="button"
                        data-test="lightbox-next"
                        :aria-label="t('Next image')"
                        class="absolute top-1/2 right-4 flex size-9 -translate-y-1/2 items-center justify-center rounded-full bg-[rgba(243,239,228,0.12)] text-[#ece7da] transition-colors hover:bg-[rgba(243,239,228,0.22)] focus-visible:ring-2 focus-visible:ring-[#ece7da] focus-visible:outline-none"
                        @click="step(1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                    <span
                        class="absolute bottom-3 left-1/2 -translate-x-1/2 text-[11.5px] text-[#8b8370] tabular-nums"
                    >
                        {{ current + 1 }} / {{ images.length }}
                    </span>
                </template>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
