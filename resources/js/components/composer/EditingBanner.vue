<script setup lang="ts">
import { Pencil, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineEmits<{
    /** Abandon the edit and return to composing. */
    cancel: [];
}>();
</script>

<template>
    <!-- Brass-tinted so the composer unmistakably reads as editing an existing
         message rather than composing a new one, naming how to save or cancel. -->
    <div
        data-test="composer-editing-banner"
        class="mb-2 flex items-center gap-2 rounded-2xl border border-brass-border bg-brass-fill px-3.5 py-2 max-md:py-1"
    >
        <Pencil class="size-3.5 shrink-0 text-brass" />
        <span
            class="min-w-0 flex-1 truncate text-[12.5px] font-semibold text-brass-fill-foreground"
        >
            {{ $t('Editing your message') }}
        </span>
        <span class="shrink-0 text-[11.5px] text-muted-foreground">
            {{ $t('Enter to save · Esc to cancel') }}
        </span>
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="composer-editing-dismiss"
            :aria-label="$t('Cancel edit')"
            class="flex shrink-0 items-center justify-center rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground max-md:size-11"
            @click="$emit('cancel')"
        >
            <X class="size-3.5 max-md:size-4.5" />
        </Button>
    </div>
</template>
