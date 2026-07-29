<script setup lang="ts">
import { Smile } from '@lucide/vue';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';

/** The longest a status text may be, mirroring the column and the request rule. */
const MAX_TEXT_LENGTH = 100;

defineProps<{
    /**
     * What the emoji square previews, so it always shows what will be saved —
     * null while the status carries neither an emoji nor any text.
     */
    previewStatus: App.Data.UserStatusData | null;
    /** The viewer's own name, which the preview announces the status against. */
    name: string;
}>();

const emoji = defineModel<string | null>('emoji', { required: true });
const text = defineModel<string>('text', { required: true });
</script>

<template>
    <!-- Emoji square + free-form text + the remaining-characters
         counter, as one focus-ringed field. -->
    <div
        class="flex h-11 items-center gap-2.5 rounded-xl border border-input bg-card px-1.5 focus-within:border-brass-border focus-within:ring-[3px] focus-within:ring-brass/15"
    >
        <EmojiPickerPopover
            :tooltip="$t('Choose an emoji')"
            @select="(picked) => (emoji = picked)"
        >
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="status-emoji-trigger"
                :aria-label="$t('Choose an emoji')"
                class="flex size-8.5 shrink-0 items-center justify-center rounded-[9px] bg-muted text-[17px] hover:bg-accent"
            >
                <UserStatusEmoji
                    v-if="previewStatus"
                    :status="previewStatus"
                    :name="name"
                    decorative
                />
                <Smile
                    v-else
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
            </Button>
        </EmojiPickerPopover>
        <Input
            v-model="text"
            data-test="status-text-input"
            :maxlength="MAX_TEXT_LENGTH"
            :aria-label="$t('Status message')"
            :placeholder="$t('What\'s your status?')"
            class="h-9 flex-1 border-0 bg-transparent px-0 shadow-none focus-visible:border-0 focus-visible:ring-0"
        />
        <span
            data-test="status-text-counter"
            aria-hidden="true"
            class="pr-2 font-mono text-[11px] text-muted-foreground"
            >{{ text.length }}/{{ MAX_TEXT_LENGTH }}</span
        >
    </div>
</template>
