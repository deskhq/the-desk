<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { StatusPreset } from '@/composables/useUserStatusForm';
import { statusExpiryLabel } from '@/lib/statusExpiry';

defineProps<{
    /** The quick picks to offer, in the order they are listed. */
    presets: StatusPreset[];
}>();

const emit = defineEmits<{
    /** The pick that was tapped, for the form to fill itself from. */
    select: [preset: StatusPreset];
}>();
</script>

<template>
    <!-- Quick picks, offered when there is nothing set yet: one tap
         fills emoji, text, and a sensible default expiry. -->
    <div class="flex flex-col gap-1.75">
        <span
            id="status-presets-label"
            class="text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
            >{{ $t('Quick picks') }}</span
        >
        <!-- A set of buttons, not a list: a `role="listitem"` on a
             <button> replaces its button role, so the group is named
             by the heading above it instead. -->
        <div
            role="group"
            aria-labelledby="status-presets-label"
            class="flex flex-col gap-px"
        >
            <Button
                v-for="preset in presets"
                :key="preset.key"
                variant="unstyled"
                size="none"
                type="button"
                data-test="status-preset"
                :data-preset="preset.key"
                class="flex h-8.5 items-center gap-2.5 rounded-[9px] px-2 text-[13.5px] text-foreground hover:bg-muted"
                @click="emit('select', preset)"
            >
                <span aria-hidden="true" class="text-[15px]">{{
                    preset.emoji
                }}</span>
                <span class="truncate">{{ preset.text }}</span>
                <span
                    class="ml-auto font-serif text-[11.5px] text-muted-foreground italic"
                    >{{ statusExpiryLabel(preset.expiry) }}</span
                >
            </Button>
        </div>
    </div>
</template>
