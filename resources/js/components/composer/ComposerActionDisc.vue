<script setup lang="ts">
import { Mic, Send } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';

const props = defineProps<{
    /**
     * Whether the composer carries anything to send. Drives the mode alone —
     * deliberately not `canSubmit`, which also goes false mid-upload and would
     * turn the disc back into a mic with a file still climbing the wire.
     */
    hasContent: boolean;
    /** Whether this browser and channel can record at all. */
    canRecord: boolean;
    /** Whether a send would go through right now (nothing uploading, failed or pending). */
    canSubmit: boolean;
}>();

const emit = defineEmits<{
    /** Deliver the composed message now. */
    send: [];
    /** Open the mic. */
    record: [];
}>();

const { t } = useTranslations();

/**
 * The single trailing target below the breakpoint: an outlined mic while there
 * is nothing to send, an ink-filled Send arrow the moment there is. One element
 * in both modes at one size, so the field's right edge never moves — and where
 * recording is unavailable it is simply always Send, never absent.
 */
const isRecordMode = computed(() => !props.hasContent && props.canRecord);
</script>

<template>
    <Button
        size="icon"
        :variant="isRecordMode ? 'outline' : 'default'"
        :disabled="!isRecordMode && !canSubmit"
        :data-test="
            isRecordMode ? 'message-composer-record' : 'message-composer-send'
        "
        class="size-12 shrink-0 rounded-full"
        :class="
            isRecordMode
                ? 'bg-card text-brass-fill-foreground'
                : 'bg-primary text-primary-foreground shadow-[0_3px_10px_rgba(29,26,21,0.25)] hover:bg-primary/90'
        "
        :aria-label="
            isRecordMode ? t('Record a voice message') : t('Send message')
        "
        @click="isRecordMode ? emit('record') : emit('send')"
    >
        <Mic v-if="isRecordMode" class="size-5" />
        <Send v-else class="size-4.75" />
    </Button>
</template>
