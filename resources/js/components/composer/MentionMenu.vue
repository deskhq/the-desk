<script setup lang="ts">
import { Bot, Users } from '@lucide/vue';
import type { MentionSuggestion } from '@/composables/useComposerMentions';
import { useInitials } from '@/composables/useInitials';

defineProps<{
    /** The rows to offer, people first and user groups in the reserved tail slots. */
    suggestions: MentionSuggestion[];
    /** Which row the keyboard is on. */
    activeIndex: number;
    /**
     * Whether this channel has any bot members. Bots are excluded from the
     * suggestions (they can't be mentioned), so the menu explains their absence
     * with a quiet footnote only when at least one is present.
     */
    hasBots?: boolean;
}>();

defineEmits<{
    /** A row was chosen (clicked). */
    select: [suggestion: MentionSuggestion];
    /** The pointer moved onto a row, which becomes the active one. */
    activate: [index: number];
}>();

const { getInitials } = useInitials();
</script>

<template>
    <ul
        id="mention-listbox"
        data-test="mention-menu"
        role="listbox"
        :aria-label="$t('Mention a teammate')"
        class="absolute bottom-full left-0 z-10 mb-2 max-h-60 w-64 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md"
    >
        <li
            v-for="(suggestion, index) in suggestions"
            :id="`mention-option-${index}`"
            :key="`${suggestion.kind}-${suggestion.id}`"
            data-test="mention-option"
            role="option"
            tabindex="-1"
            :aria-selected="index === activeIndex"
            class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-popover-foreground"
            :class="
                index === activeIndex
                    ? 'bg-accent text-accent-foreground'
                    : 'hover:bg-accent/60'
            "
            @mousedown.prevent="$emit('select', suggestion)"
            @mouseenter="$emit('activate', index)"
        >
            <span
                v-if="suggestion.kind === 'group'"
                class="flex size-6 shrink-0 items-center justify-center rounded-md bg-violet-500/10 text-violet-700 select-none dark:bg-violet-400/15 dark:text-violet-300"
                aria-hidden="true"
            >
                <Users class="size-3.5" />
            </span>
            <span
                v-else
                class="flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/10 text-[10px] font-semibold text-primary select-none"
                aria-hidden="true"
            >
                {{ getInitials(suggestion.label) }}
            </span>
            <span class="truncate">{{ suggestion.label }}</span>
            <!-- The member count is what tells a reader how far this one
                 mention reaches before they send it. -->
            <span
                v-if="suggestion.kind === 'group'"
                data-test="mention-option-group-count"
                class="ml-auto shrink-0 text-[11px] text-muted-foreground"
            >
                {{
                    suggestion.group.membersCount === 1
                        ? $t(':count member', {
                              count: suggestion.group.membersCount,
                          })
                        : $t(':count members', {
                              count: suggestion.group.membersCount,
                          })
                }}
            </span>
        </li>
        <!-- A quiet footnote explaining why bots never appear here — shown
             only in a channel that actually has a bot. Presentational, so
             it is not announced as a selectable option. -->
        <li
            v-if="hasBots"
            role="presentation"
            data-test="mention-bot-hint"
            class="mt-1 flex items-center gap-2 border-t border-border px-2 pt-2 pb-1 text-[11px] text-muted-foreground italic"
        >
            <Bot class="size-3 shrink-0" aria-hidden="true" />
            <span>{{
                $t('Bots can’t be mentioned — they don’t read messages')
            }}</span>
        </li>
    </ul>
</template>
