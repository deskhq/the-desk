<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { GripVertical, MoreVertical, Pencil, Star } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { update as updateChannelStar } from '@/actions/App/Http/Controllers/Channels/ChannelStarController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslations } from '@/composables/useTranslations';
import { notificationIndicator } from '@/lib/notificationIndicator';
import type { Channel, ChannelSection } from '@/types/channels';

const props = defineProps<{
    channel: Channel;
    teamSlug: string;
    activeChannelSlug: string | null;
    /** The user's custom sections, offered as move targets in the kebab menu. */
    sections?: ChannelSection[];
    /** The section this row currently renders in (null for the default group). */
    currentSectionId?: string | null;
}>();

/**
 * Ask the parent to file this channel under a different section (null moves it
 * back to the default "Channels" group). The parent owns the placement request
 * because it knows the target group's full order.
 */
const emit = defineEmits<{
    move: [sectionId: string | null];
}>();

// Keep the hover controls visible while the move menu is open, so a click on a
// menu item doesn't dismiss the row's actions mid-interaction.
const menuOpen = ref(false);

const { t } = useTranslations();

// The mute / notification-level cue for this row, matching the conversation
// masthead; null (and so no icon) for an unmuted channel at the default level.
const indicator = computed(() =>
    notificationIndicator(props.channel.muted, props.channel.notificationLevel),
);

/**
 * Star or unstar the channel, reloading only the shared `channels` prop so the
 * sidebar re-partitions between the "Starred" and "Channels" sections.
 */
function toggleStar(): void {
    router.patch(
        updateChannelStar({
            team: props.teamSlug,
            channel: props.channel.slug,
        }).url,
        { starred: !props.channel.starred },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channels'],
            onError: () => {
                toast.error(
                    t('Failed to update the channel. Please try again.'),
                );
            },
        },
    );
}
</script>

<template>
    <SidebarMenuItem class="group/row relative">
        <SidebarMenuButton
            as-child
            :is-active="channel.slug === activeChannelSlug"
            :data-muted="channel.muted"
            class="h-8 gap-2 rounded-[9px] py-0 pr-2.5 pl-7 text-[13.5px] text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground data-[active=true]:bg-sidebar-primary data-[active=true]:font-medium data-[active=true]:text-sidebar-primary-foreground data-[active=true]:shadow-[0_2px_6px_rgba(29,26,21,0.25)] data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground data-[muted=true]:opacity-55 data-[muted=true]:hover:opacity-100"
        >
            <Link
                :href="
                    show({
                        team: teamSlug,
                        channel: channel.slug,
                    }).url
                "
                :aria-current="
                    channel.slug === activeChannelSlug ? 'page' : undefined
                "
            >
                <span
                    class="shrink-0 font-medium"
                    :class="
                        channel.slug === activeChannelSlug
                            ? 'text-brass'
                            : channel.unreadCount > 0
                              ? 'text-muted-foreground'
                              : 'text-muted-foreground'
                    "
                    >#</span
                >
                <span
                    class="truncate"
                    :class="
                        channel.unreadCount > 0 &&
                        channel.slug !== activeChannelSlug
                            ? 'font-semibold text-sidebar-foreground'
                            : ''
                    "
                    >{{ channel.name }}</span
                >
                <!-- The mute / notification-level cue sits just after the name,
                     left of the right-aligned unread/mention/draft badge so the
                     two never collide. A tooltip names the state on hover/focus. -->
                <Tooltip v-if="indicator">
                    <TooltipTrigger as-child>
                        <span
                            data-test="notification-indicator"
                            :data-status="indicator.status"
                            class="inline-flex shrink-0 items-center text-muted-foreground/70"
                            :aria-label="$t(indicator.label)"
                        >
                            <component :is="indicator.icon" class="size-3" />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{{ $t(indicator.label) }}</TooltipContent>
                </Tooltip>
                <!-- A numeric badge for unread @mentions takes priority; -->
                <!-- then a "draft" cue for a pending unsent message on -->
                <!-- another channel; otherwise a plain unread dot. -->
                <span
                    v-if="channel.mentionCount > 0"
                    data-test="mention-badge"
                    class="ml-auto flex h-4.25 min-w-4.5 items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
                    :aria-label="
                        $t(':count unread mentions', {
                            count: channel.mentionCount,
                        })
                    "
                    >{{ channel.mentionCount }}</span
                >
                <span
                    v-else-if="
                        channel.hasDraft && channel.slug !== activeChannelSlug
                    "
                    data-test="draft-indicator"
                    class="ml-auto inline-flex items-center gap-1 text-[10px] font-semibold tracking-[0.04em] text-amber-500 uppercase"
                    :aria-label="$t('Draft saved')"
                >
                    <Pencil class="size-3" />
                    {{ $t('Draft') }}
                </span>
                <template v-else-if="channel.unreadCount > 0">
                    <span
                        data-test="unread-dot"
                        aria-hidden="true"
                        class="ml-auto size-1.5 rounded-full bg-brass"
                    />
                    <!-- The dot is decorative; the unread state is named for
                         assistive tech through a screen-reader-only label. -->
                    <span
                        :data-test="`channel-unread-${channel.slug}`"
                        class="sr-only"
                        >{{ $t('Unread') }}</span
                    >
                </template>
            </Link>
        </SidebarMenuButton>
        <!-- Star toggle to the left of the channel name. A separate button
             (outside the navigation link, so the anchor stays valid) overlaid on
             the row's reserved left padding: filled amber when starred, hollow and
             dimmed otherwise, brightening on hover. -->
        <Button
            variant="ghost"
            size="icon"
            :data-test="`star-toggle-${channel.slug}`"
            :data-starred="channel.starred"
            :aria-pressed="channel.starred"
            :aria-label="
                channel.starred
                    ? $t('Unstar :channel', { channel: channel.name })
                    : $t('Star :channel', { channel: channel.name })
            "
            :title="channel.starred ? $t('Unstar channel') : $t('Star channel')"
            class="absolute top-1/2 left-1 z-10 size-5 -translate-y-1/2 rounded text-muted-foreground/60 opacity-70 transition hover:bg-transparent hover:text-brass hover:opacity-100 data-[starred=true]:text-brass data-[starred=true]:opacity-100"
            @click="toggleStar"
        >
            <Star
                class="size-3.5"
                :class="channel.starred ? 'fill-current' : ''"
            />
        </Button>
        <!-- Hover controls on the right: a drag handle (the SortableJS handle for
             reordering rows) and a kebab menu to file the channel under a custom
             section. Revealed on hover or focus, and pinned open while the menu
             is; a solid background masks any unread badge underneath. -->
        <div
            class="absolute top-1/2 right-1 z-10 flex -translate-y-1/2 items-center gap-0.5 rounded-md bg-sidebar pl-1 opacity-0 transition group-hover/row:opacity-100 group-data-[active=true]/row:bg-sidebar-primary focus-within:opacity-100"
            :class="menuOpen ? 'opacity-100' : ''"
        >
            <Button
                variant="ghost"
                size="icon"
                :data-test="`channel-drag-handle-${channel.slug}`"
                :aria-label="$t('Reorder :channel', { channel: channel.name })"
                :title="$t('Drag to reorder')"
                class="channel-drag-handle size-5 cursor-grab rounded text-muted-foreground/60 transition group-data-[active=true]/row:text-sidebar-primary-foreground/70 hover:bg-transparent hover:text-sidebar-foreground group-data-[active=true]/row:hover:text-sidebar-primary-foreground active:cursor-grabbing"
            >
                <GripVertical class="size-3.5" />
            </Button>
            <DropdownMenu
                v-if="(sections?.length ?? 0) > 0"
                v-model:open="menuOpen"
            >
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        :data-test="`channel-menu-${channel.slug}`"
                        :aria-label="
                            $t('Channel options for :channel', {
                                channel: channel.name,
                            })
                        "
                        :title="$t('More options')"
                        class="size-5 rounded text-muted-foreground/60 transition group-data-[active=true]/row:text-sidebar-primary-foreground/70 hover:bg-transparent hover:text-sidebar-foreground group-data-[active=true]/row:hover:text-sidebar-primary-foreground data-[state=open]:text-sidebar-foreground"
                    >
                        <MoreVertical class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                    <DropdownMenuLabel>{{ $t('Move to') }}</DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="section in sections"
                        :key="section.id"
                        :disabled="section.id === (currentSectionId ?? null)"
                        :data-test="`move-to-${section.id}`"
                        @select="emit('move', section.id)"
                    >
                        <span class="truncate">{{ section.name }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        :disabled="(currentSectionId ?? null) === null"
                        data-test="move-to-default"
                        @select="emit('move', null)"
                    >
                        {{ $t('Channels') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </SidebarMenuItem>
</template>
