<script setup lang="ts">
import { SmilePlus } from '@lucide/vue';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import type { CustomEmojiEntry } from '@/lib/customEmoji';
import { hasReacted, reactionRoster } from '@/lib/reactions';
import type { Reaction } from '@/types';

const props = defineProps<{
    reactions: Reaction[];
    currentUserId: string;
    // Whether the viewer may add/remove reactions (channel member, non-archived);
    // when false the existing pills still render read-only.
    canReact: boolean;
}>();

const emit = defineEmits<{
    toggle: [emoji: string];
}>();

const { parseToken } = useCustomEmojis();

/**
 * When a reaction is a resolvable custom-emoji `:name:` token, its image entry;
 * otherwise null (a native unicode reaction, or a revoked emoji that falls back
 * to showing its literal `:name:`).
 */
function customEmoji(reaction: Reaction): CustomEmojiEntry | null {
    return parseToken(reaction.emoji);
}

/**
 * Whether the viewer has reacted with a given emoji, driving the pill's
 * highlighted (own-reaction) styling.
 */
function reacted(reaction: Reaction): boolean {
    return hasReacted(reaction, props.currentUserId);
}

/**
 * The full roster of who reacted with an emoji ("You, Alice and Bob"), shown in
 * the pill's hover card and used as its accessible label.
 */
function roster(reaction: Reaction): string {
    return reactionRoster(reaction, props.currentUserId);
}

function toggle(emoji: string): void {
    if (!props.canReact) {
        return;
    }

    emit('toggle', emoji);
}
</script>

<template>
    <!-- Rendered only when reactions exist, so an empty message reserves no
         vertical space — the add-reaction affordance for a message with no
         reactions lives in the floating hover toolbar, which never reflows the
         timeline. Once reactions exist this row keeps a stable height. -->
    <div
        v-if="props.reactions.length > 0"
        data-test="message-reactions"
        class="mt-1 flex flex-wrap items-center gap-1"
    >
        <HoverCard
            v-for="reaction in props.reactions"
            :key="reaction.emoji"
            :open-delay="200"
            :close-delay="100"
        >
            <HoverCardTrigger as-child>
                <button
                    type="button"
                    data-test="reaction-pill"
                    :data-emoji="reaction.emoji"
                    :data-reacted="reacted(reaction)"
                    :disabled="!props.canReact"
                    :aria-label="`${reaction.emoji} ${roster(reaction)}`"
                    :aria-pressed="reacted(reaction)"
                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[12px] leading-none transition-colors"
                    :class="
                        reacted(reaction)
                            ? 'border-brass-border bg-brass-fill text-brass-fill-foreground'
                            : 'border-border bg-muted/40 text-muted-foreground hover:bg-muted'
                    "
                    @click="toggle(reaction.emoji)"
                >
                    <img
                        v-if="customEmoji(reaction)"
                        :src="customEmoji(reaction)!.url"
                        :alt="reaction.emoji"
                        class="custom-emoji inline-block h-[1.15em] w-[1.15em]"
                    />
                    <span v-else aria-hidden="true">{{ reaction.emoji }}</span>
                    <span class="font-medium tabular-nums">{{
                        reaction.count
                    }}</span>
                </button>
            </HoverCardTrigger>
            <HoverCardContent
                data-test="reaction-reactors"
                class="w-auto max-w-60 p-2.5"
            >
                <div class="flex items-center gap-2.5">
                    <img
                        v-if="customEmoji(reaction)"
                        :src="customEmoji(reaction)!.url"
                        :alt="reaction.emoji"
                        class="custom-emoji size-6"
                    />
                    <span
                        v-else
                        class="text-2xl leading-none"
                        aria-hidden="true"
                        >{{ reaction.emoji }}</span
                    >
                    <p class="text-[12.5px] leading-snug text-foreground">
                        <span class="font-medium">{{ roster(reaction) }}</span>
                        <span class="text-muted-foreground">
                            {{ ' ' + $t('reacted') }}</span
                        >
                    </p>
                </div>
            </HoverCardContent>
        </HoverCard>

        <EmojiPickerPopover
            v-if="props.canReact"
            @select="(emoji) => emit('toggle', emoji)"
        >
            <button
                type="button"
                data-test="add-reaction"
                :aria-label="$t('Add reaction')"
                class="inline-flex items-center rounded-full border border-border bg-muted/40 p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <SmilePlus class="size-3.5" />
            </button>
        </EmojiPickerPopover>
    </div>
</template>
