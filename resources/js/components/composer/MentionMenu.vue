<script setup lang="ts">
import { Bot, Users } from '@lucide/vue';
import AutocompleteListbox from '@/components/composer/AutocompleteListbox.vue';
import type { AutocompleteMenu } from '@/composables/useAutocompleteMenu';
import type { MentionSuggestion } from '@/composables/useComposerMentions';
import { useInitials } from '@/composables/useInitials';

defineProps<{
    /** The `@` menu, offering people first and user groups in the tail slots. */
    menu: AutocompleteMenu<MentionSuggestion>;
    /**
     * Whether this channel has any bot members. Bots are excluded from the
     * suggestions (they can't be mentioned), so the menu explains their absence
     * with a quiet footnote only when at least one is present.
     */
    hasBots?: boolean;
}>();

const { getInitials } = useInitials();

function keyOf(suggestion: MentionSuggestion): string {
    return `${suggestion.kind}-${suggestion.id}`;
}
</script>

<template>
    <AutocompleteListbox
        :menu="menu"
        :label="$t('Mention a teammate')"
        :key-of="keyOf"
    >
        <template #option="{ item }">
            <div class="flex items-center gap-2">
                <span
                    v-if="item.kind === 'group'"
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
                    {{ getInitials(item.label) }}
                </span>
                <span class="truncate">{{ item.label }}</span>
                <!-- The member count is what tells a reader how far this one
                     mention reaches before they send it. -->
                <span
                    v-if="item.kind === 'group'"
                    data-test="mention-option-group-count"
                    class="ml-auto shrink-0 text-[11px] text-muted-foreground"
                >
                    {{
                        item.group.membersCount === 1
                            ? $t(':count member', {
                                  count: item.group.membersCount,
                              })
                            : $t(':count members', {
                                  count: item.group.membersCount,
                              })
                    }}
                </span>
            </div>
        </template>
        <template #footer>
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
        </template>
    </AutocompleteListbox>
</template>
