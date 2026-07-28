<script setup lang="ts">
import { CircleAlert, FileText, X } from '@lucide/vue';
import AudioPlayer from '@/components/AudioPlayer.vue';
import { Button } from '@/components/ui/button';
import type { PendingAttachment } from '@/composables/useAttachmentUploads';
import { formatFileSize } from '@/lib/attachments';

defineProps<{
    /** The staged rows, in send order (`attachment_ids[]`). */
    items: PendingAttachment[];
}>();

defineEmits<{
    /** Drop a staged row. Immediate — the upload is pre-send, so there is nothing to undo. */
    remove: [localId: string];
    /** Re-fire a failed row's upload. */
    retry: [localId: string];
}>();
</script>

<template>
    <!-- Pre-send attachment tray. Row order is the send order
         (attachment_ids[]).

         Every remove control here is hover-revealed from `md` up and
         always visible below it: there is no hover on a touch
         screen, so a phone had no way at all to drop a staged file
         (#920). Each one also carries a 44pt hit box below `md`,
         which the three chip shapes reach differently — see each. -->
    <div
        data-test="composer-attachment-tray"
        class="flex flex-wrap gap-2.5 px-4 pt-3.5 pb-1"
    >
        <template v-for="item in items" :key="item.localId">
            <!-- Failed upload: a retryable chip. Nothing was
                 persisted; it blocks send until retried or removed. -->
            <div
                v-if="item.status === 'failed'"
                data-test="composer-attachment"
                data-kind="failed"
                data-status="failed"
                class="relative flex h-19 min-w-50 items-center gap-2.5 rounded-xl border border-destructive/40 bg-destructive/10 px-3"
            >
                <span
                    class="flex size-9.5 shrink-0 items-center justify-center rounded-[10px] bg-destructive/15 text-destructive-text"
                >
                    <CircleAlert class="size-4.5" />
                </span>
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span
                        class="truncate text-[12.5px] font-semibold text-foreground"
                    >
                        {{ item.name }}
                    </span>
                    <span class="text-[11px] text-destructive-text">
                        {{ $t('Upload failed') }} ·
                        <Button
                            variant="unstyled"
                            size="none"
                            type="button"
                            data-test="composer-attachment-retry"
                            class="underline hover:no-underline"
                            @click="$emit('retry', item.localId)"
                        >
                            {{ $t('Retry') }}
                        </Button>
                    </span>
                </span>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attachment-remove"
                    :aria-label="$t('Remove attachment')"
                    class="flex shrink-0 items-center justify-center rounded-full p-1 text-muted-foreground hover:text-foreground max-md:size-11"
                    @click="$emit('remove', item.localId)"
                >
                    <X class="size-3 max-md:size-4.5" />
                </Button>
            </div>

            <!-- Image preview thumbnail (never SVG). The remove
                 control is the one chip that cannot spend 44pt on a
                 visible button: it would bury the picture it belongs
                 to. Below `md` the thumbnail grows instead and the
                 painted badge keeps its corner while its hit box
                 reaches back over the thumbnail, which has no
                 competing tap target of its own. -->
            <div
                v-else-if="item.isImage"
                data-test="composer-attachment"
                data-kind="image"
                :data-status="item.status"
                class="group relative size-19 overflow-hidden rounded-xl border border-input bg-muted max-md:size-25"
            >
                <img
                    v-if="item.previewUrl"
                    :src="item.previewUrl"
                    alt=""
                    class="size-full object-cover"
                />
                <div
                    v-if="item.status === 'uploading'"
                    class="absolute inset-0 bg-foreground/25"
                ></div>
                <div
                    v-if="item.status === 'uploading'"
                    class="absolute inset-x-1.5 bottom-1.5 h-0.75 overflow-hidden rounded-full bg-background/40"
                >
                    <div
                        class="h-full rounded-full bg-brass"
                        :style="{ width: `${item.progress}%` }"
                    ></div>
                </div>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attachment-remove"
                    :aria-label="$t('Remove attachment')"
                    class="absolute top-1 right-1 flex items-center justify-center max-md:top-0 max-md:right-0 max-md:size-11 max-md:items-start max-md:justify-end max-md:p-1 md:opacity-0 md:transition-opacity md:group-hover:opacity-100 md:focus:opacity-100"
                    @click="$emit('remove', item.localId)"
                >
                    <span
                        data-test="composer-attachment-remove-badge"
                        class="flex size-5.5 items-center justify-center rounded-full bg-foreground/80 text-background"
                    >
                        <X class="size-2.75" />
                    </span>
                </Button>
            </div>

            <!-- Audio chip: the same inline player the timeline
                 uses, previewing the local blob before send. A
                 recorded clip drops its filename line inside the
                 player, so the tray reads as "a voice message". -->
            <div
                v-else-if="item.isAudio && item.previewUrl"
                data-test="composer-attachment"
                data-kind="audio"
                :data-status="item.status"
                class="group relative flex items-center max-md:gap-1"
            >
                <!-- The player and its progress bar share a box of
                     their own so the bar keeps tracking the player
                     once the remove button takes a column beside
                     it below `md`. -->
                <div class="relative">
                    <AudioPlayer
                        :src="item.previewUrl"
                        :filename="item.name"
                        compact
                    />
                    <div
                        v-if="item.status === 'uploading'"
                        class="absolute inset-x-3 bottom-1.5 h-0.75 overflow-hidden rounded-full bg-border"
                    >
                        <div
                            class="h-full rounded-full bg-brass"
                            :style="{ width: `${item.progress}%` }"
                        ></div>
                    </div>
                </div>
                <!-- A 44pt overlay would sit on the right end of the
                     scrubber, so below `md` this one leaves the
                     corner and takes a column of its own. -->
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attachment-remove"
                    :aria-label="$t('Remove attachment')"
                    class="flex items-center justify-center rounded-full p-1 text-muted-foreground hover:text-foreground max-md:size-11 max-md:shrink-0 md:absolute md:top-1.5 md:right-1.5 md:opacity-0 md:transition-opacity md:group-hover:opacity-100 md:focus:opacity-100"
                    @click="$emit('remove', item.localId)"
                >
                    <X class="size-3 max-md:size-4.5" />
                </Button>
            </div>

            <!-- Non-image file chip (uploading or done). -->
            <div
                v-else
                data-test="composer-attachment"
                data-kind="file"
                :data-status="item.status"
                class="group relative flex h-19 min-w-52 items-center gap-2.5 rounded-xl border border-input bg-muted px-3"
            >
                <span
                    class="flex size-9.5 shrink-0 items-center justify-center rounded-[10px] bg-background text-muted-foreground"
                >
                    <FileText class="size-4.5" />
                </span>
                <span class="flex min-w-0 flex-1 flex-col gap-1">
                    <span
                        class="truncate text-[12.5px] font-semibold text-foreground"
                    >
                        {{ item.name }}
                    </span>
                    <span
                        class="text-[11px] text-muted-foreground tabular-nums"
                    >
                        {{ formatFileSize(item.sizeBytes)
                        }}<template v-if="item.status === 'uploading'">
                            · {{ item.progress }}%</template
                        >
                    </span>
                    <div
                        v-if="item.status === 'uploading'"
                        class="h-0.75 overflow-hidden rounded-full bg-border"
                    >
                        <div
                            class="h-full rounded-full bg-brass"
                            :style="{ width: `${item.progress}%` }"
                        ></div>
                    </div>
                </span>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attachment-remove"
                    :aria-label="$t('Remove attachment')"
                    class="flex shrink-0 items-center justify-center rounded-full p-1 text-muted-foreground hover:text-foreground max-md:size-11 md:opacity-0 md:transition-opacity md:group-hover:opacity-100 md:focus:opacity-100"
                    @click="$emit('remove', item.localId)"
                >
                    <X class="size-3 max-md:size-4.5" />
                </Button>
            </div>
        </template>
    </div>
</template>
