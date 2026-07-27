<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal, Plus, Search } from '@lucide/vue';
import draggable from 'vuedraggable';
import { browse } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import ChannelListItem from '@/components/ChannelListItem.vue';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import SidebarSectionHeader from '@/components/navigation/SidebarSectionHeader.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
} from '@/components/ui/sidebar';
import type { ChannelDragChange } from '@/composables/useChannelPlacement';
import type { Channel, ChannelSection } from '@/types/channels';

defineProps<{
    /** Every custom section, offered as move targets on each row's kebab. */
    sections: ChannelSection[];
    /** Empty until a workspace is selected, which is what gates the actions. */
    teamSlug: string;
    activeChannelSlug: string | null;
    collapsed: boolean;
}>();

/**
 * The unstarred, unassigned channels. A model rather than a plain prop because
 * vuedraggable writes to it as the user drags, and the array it writes belongs
 * to the placement composable up in the panel.
 */
const channels = defineModel<Channel[]>('channels', { required: true });

defineEmits<{
    /** The user asked to collapse or expand the group. */
    toggle: [];
    /** A row asked to be filed under a section (null for the default group). */
    move: [channel: Channel, sectionId: string | null];
    /** vuedraggable reordered the group, or a channel was dragged into it. */
    change: [change: ChannelDragChange];
}>();
</script>

<template>
    <SidebarGroup>
        <SidebarSectionHeader
            name="channels"
            :label="$t('Channels')"
            :collapsed="collapsed"
            @toggle="$emit('toggle')"
        />
        <!-- The group header's overflow. "Browse channels" left the destination
             set when the rail took it over, and lands here — beside the "+" the
             design keeps — rather than as a fifth glyph. -->
        <DropdownMenu v-if="teamSlug !== ''">
            <DropdownMenuTrigger as-child>
                <SidebarGroupAction
                    :aria-label="
                        $t('Options for :name', { name: $t('Channels') })
                    "
                    :title="$t('Section options')"
                    data-test="channels-section-menu"
                    class="top-2 right-9 size-5 rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                >
                    <MoreHorizontal class="size-3.5" />
                </SidebarGroupAction>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-44">
                <DropdownMenuItem as-child>
                    <Link
                        :href="browse(teamSlug).url"
                        data-test="browse-channels"
                        class="cursor-pointer"
                    >
                        <Search class="size-3.5" />
                        {{ $t('Browse channels') }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <CreateChannelModal v-if="teamSlug !== ''" :team-slug="teamSlug">
            <SidebarGroupAction
                :title="$t('Create channel')"
                data-test="create-channel-trigger"
                data-tour="create-channel"
                class="top-2 size-5 rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            >
                <Plus class="size-3.25" />
                <span class="sr-only">{{ $t('Create channel') }}</span>
            </SidebarGroupAction>
        </CreateChannelModal>
        <SidebarGroupContent
            v-show="!collapsed"
            data-test="section-content-channels"
        >
            <draggable
                v-model="channels"
                :group="{ name: 'sidebar-channels' }"
                handle=".channel-drag-handle"
                item-key="id"
                tag="ul"
                class="flex w-full min-w-0 flex-col gap-1"
                :animation="150"
                @change="(change: ChannelDragChange) => $emit('change', change)"
            >
                <template #item="{ element }">
                    <ChannelListItem
                        :channel="element"
                        :team-slug="teamSlug"
                        :active-channel-slug="activeChannelSlug"
                        :sections="sections"
                        :current-section-id="null"
                        @move="(sectionId) => $emit('move', element, sectionId)"
                    />
                </template>
            </draggable>
            <!-- Brand-new workspace: nothing files into the default list yet. A
                 dashed hint stands in until the first channel appears. -->
            <div
                v-if="channels.length === 0"
                data-test="no-channels-empty"
                class="mx-1 mt-1.5 flex flex-col gap-1 rounded-[11px] border border-dashed border-sidebar-border px-3 py-3.5 text-center"
            >
                <span
                    class="text-[12.5px] font-semibold text-sidebar-foreground/70"
                    >{{ $t('No channels yet') }}</span
                >
                <span
                    class="text-[11.5px] leading-[1.45] text-muted-foreground"
                    >{{
                        $t('Channels keep conversations organized by topic.')
                    }}</span
                >
            </div>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
