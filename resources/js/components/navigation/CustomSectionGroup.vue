<script setup lang="ts">
import {
    ChevronRight,
    GripVertical,
    MoreVertical,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { nextTick, useTemplateRef, watch } from 'vue';
import draggable from 'vuedraggable';
import ChannelListItem from '@/components/ChannelListItem.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { SidebarGroup, SidebarGroupContent } from '@/components/ui/sidebar';
import type { ChannelDragChange } from '@/composables/useChannelPlacement';
import type { Channel, ChannelSection } from '@/types/channels';

const props = defineProps<{
    /** The section this group renders, including its collapse flag. */
    section: ChannelSection;
    /** Every custom section, offered as move targets on each row's kebab. */
    sections: ChannelSection[];
    teamSlug: string;
    activeChannelSlug: string | null;
    /** Whether this section is the one currently being renamed inline. */
    renaming: boolean;
}>();

/**
 * The channels filed under this section. A model rather than a plain prop
 * because vuedraggable writes to it as the user drags, and the array it writes
 * belongs to the placement composable up in the panel.
 */
const channels = defineModel<Channel[]>('channels', { required: true });

/** The name being typed into the inline rename editor. */
const renameValue = defineModel<string>('renameValue', { required: true });

defineEmits<{
    /** The user asked to collapse or expand this section. */
    toggle: [];
    /** The user asked to start renaming this section. */
    renameStart: [];
    /** The user abandoned the rename (Esc). */
    renameCancel: [];
    /** The editor lost focus, committing whatever it holds. */
    renameCommit: [];
    /** The user asked to delete this section. */
    delete: [];
    /** A row asked to be filed under another section (null for the default). */
    move: [channel: Channel, sectionId: string | null];
    /** vuedraggable reordered this group, or a channel was dragged into it. */
    change: [change: ChannelDragChange];
}>();

const renameInput = useTemplateRef('renameInput');

/**
 * Take the editor as it appears, with the current name selected so typing
 * replaces it. Each group owns exactly one rename field, so its own template ref
 * resolves it — no lookup across the document by test selector.
 */
watch(
    () => props.renaming,
    async (renaming) => {
        if (!renaming) {
            return;
        }

        await nextTick();
        renameInput.value?.$el?.focus();
        renameInput.value?.$el?.select();
    },
);

/**
 * Blur the editor so its `@blur` handler commits the value — the
 * Enter-to-confirm idiom the inline fields share.
 */
function commitOnEnter(): void {
    renameInput.value?.$el?.blur();
}
</script>

<template>
    <SidebarGroup class="pb-0" :data-test="`section-custom-${section.id}`">
        <div
            class="group/section flex h-7 w-full items-center gap-1 rounded-md pr-1 pl-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
        >
            <Button
                variant="ghost"
                size="icon"
                :data-test="`section-drag-${section.id}`"
                :aria-label="$t('Reorder :name', { name: section.name })"
                :title="$t('Drag to reorder section')"
                class="section-drag-handle size-4 shrink-0 cursor-grab rounded text-muted-foreground/50 opacity-0 transition group-hover/section:opacity-100 hover:bg-transparent hover:text-sidebar-foreground active:cursor-grabbing"
            >
                <GripVertical class="size-3" />
            </Button>
            <!-- While renaming, the editor stands in for the toggle as its
                 sibling — never nested inside the <button>, which would be
                 invalid interactive-in-interactive markup and breaks keyboard
                 focus. -->
            <Input
                v-if="renaming"
                ref="renameInput"
                v-model="renameValue"
                :data-test="`section-rename-input-${section.id}`"
                class="h-auto min-w-0 flex-1 rounded-sm border-sidebar-border bg-sidebar px-1 py-0.5 text-base tracking-normal text-sidebar-foreground normal-case md:text-[11px] dark:bg-sidebar"
                type="text"
                maxlength="50"
                @keydown.enter.prevent="commitOnEnter"
                @keydown.esc="$emit('renameCancel')"
                @blur="$emit('renameCommit')"
            />
            <Button
                v-else
                variant="ghost"
                :data-test="`section-toggle-custom-${section.id}`"
                :aria-expanded="!section.collapsed"
                class="flex h-auto min-w-0 flex-1 items-center justify-start gap-1 rounded-none p-0 text-[10.5px] font-semibold hover:bg-transparent"
                @click="$emit('toggle')"
            >
                <ChevronRight
                    class="size-3 shrink-0 transition-transform"
                    :class="section.collapsed ? '' : 'rotate-90'"
                />
                <span class="truncate" @dblclick.stop="$emit('renameStart')">{{
                    section.name
                }}</span>
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        :data-test="`section-menu-${section.id}`"
                        :aria-label="
                            $t('Options for :name', { name: section.name })
                        "
                        :title="$t('Section options')"
                        class="size-5 shrink-0 rounded text-muted-foreground/60 opacity-0 transition group-hover/section:opacity-100 hover:bg-transparent hover:text-sidebar-foreground focus-visible:opacity-100 data-[state=open]:opacity-100"
                    >
                        <MoreVertical class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-40">
                    <DropdownMenuItem
                        :data-test="`section-rename-${section.id}`"
                        @select="$emit('renameStart')"
                    >
                        <Pencil class="size-3.5" />
                        {{ $t('Rename') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        :data-test="`section-delete-${section.id}`"
                        @select="$emit('delete')"
                    >
                        <Trash2 class="size-3.5" />
                        {{ $t('Delete') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <SidebarGroupContent
            v-show="!section.collapsed"
            :data-test="`section-content-custom-${section.id}`"
        >
            <draggable
                v-model="channels"
                :group="{ name: 'sidebar-channels' }"
                handle=".channel-drag-handle"
                item-key="id"
                tag="ul"
                class="flex w-full min-w-0 flex-col gap-1"
                :class="channels.length === 0 ? 'min-h-6' : ''"
                :animation="150"
                @change="(change: ChannelDragChange) => $emit('change', change)"
            >
                <template #item="{ element }">
                    <ChannelListItem
                        :channel="element"
                        :team-slug="teamSlug"
                        :active-channel-slug="activeChannelSlug"
                        :sections="sections"
                        :current-section-id="section.id"
                        @move="(sectionId) => $emit('move', element, sectionId)"
                    />
                </template>
            </draggable>
            <p
                v-if="channels.length === 0"
                class="px-7 pb-1 text-[12px] text-muted-foreground normal-case"
            >
                {{ $t('Drag channels here') }}
            </p>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
