<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { store as hideDirectMessage } from '@/actions/App/Http/Controllers/Channels/HideDirectMessageController';
import { SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { notificationIndicator } from '@/lib/notificationIndicator';
import type { Channel } from '@/types/channels';

const props = defineProps<{
    channel: Channel;
    teamSlug: string;
    activeChannelSlug: string | null;
    /** Whether the DM's participant is currently on the team presence roster. */
    online: boolean;
    /** Whether this is the viewer's own self-DM (renders "You"). */
    isSelf: boolean;
}>();

const { getInitials } = useInitials();
const { t } = useTranslations();

// The viewer-relative name comes pre-resolved on the channel (the other
// participant, or the viewer themselves for a self-DM); only the self-DM label
// is localized on the client.
const displayName = computed(() =>
    props.isSelf ? t('You') : props.channel.name,
);

const isActive = computed(() => props.channel.slug === props.activeChannelSlug);

// The mute / notification-level cue for this DM, matching the conversation
// masthead; null (and so no icon) for an unmuted DM at the default level.
const indicator = computed(() =>
    notificationIndicator(props.channel.muted, props.channel.notificationLevel),
);

/**
 * Close (hide) this DM from the sidebar, reloading only the shared `channels`
 * prop so the row leaves without a full reload. A later message — or reopening
 * the conversation — brings it back.
 */
function hide(): void {
    router.post(
        hideDirectMessage({
            team: props.teamSlug,
            channel: props.channel.slug,
        }).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channels'],
            onError: () => {
                toast.error(
                    t('Failed to close the conversation. Please try again.'),
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
            :is-active="isActive"
            :data-muted="channel.muted"
            class="h-8 gap-2 rounded-[9px] py-0 pr-2.5 pl-2.5 text-[13.5px] text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground data-[active=true]:bg-sidebar-primary data-[active=true]:font-medium data-[active=true]:text-sidebar-primary-foreground data-[active=true]:shadow-[0_2px_6px_rgba(29,26,21,0.25)] data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground data-[muted=true]:opacity-55 data-[muted=true]:hover:opacity-100"
        >
            <Link
                :href="show({ team: teamSlug, channel: channel.slug }).url"
                :data-test="`dm-row-${channel.slug}`"
            >
                <span class="relative size-[18px] shrink-0">
                    <!-- On the active row the button fills with the brass
                         primary, so the avatar switches to a light-on-dark
                         treatment to stay legible instead of washing out. -->
                    <span
                        class="flex size-[18px] items-center justify-center rounded-full text-[8px] font-semibold select-none"
                        :class="
                            isActive
                                ? 'bg-sidebar-primary-foreground/25 text-sidebar-primary-foreground'
                                : 'bg-primary/10 text-primary'
                        "
                        aria-hidden="true"
                        >{{ getInitials(channel.name) }}</span
                    >
                    <span
                        data-test="dm-presence-dot"
                        :data-online="online"
                        :aria-label="online ? $t('Online') : $t('Offline')"
                        class="absolute -right-0.5 -bottom-0.5 size-2 rounded-full ring-2"
                        :class="[
                            online
                                ? 'bg-emerald-500'
                                : 'bg-muted-foreground/50',
                            isActive ? 'ring-sidebar-primary' : 'ring-sidebar',
                        ]"
                    />
                </span>
                <span
                    class="truncate"
                    :class="
                        channel.unreadCount > 0 && !isActive
                            ? 'font-semibold text-sidebar-foreground'
                            : ''
                    "
                    >{{ displayName }}</span
                >
                <!-- The mute / notification-level cue sits just after the name,
                     left of the right-aligned unread badge so the two never
                     collide. A tooltip names the state on hover/focus. -->
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
                <!-- DMs badge on plain unread count (never mention-weighted): a
                     numeric pill when there is anything unread. -->
                <span
                    v-if="channel.unreadCount > 0"
                    data-test="dm-unread-badge"
                    class="ml-auto flex h-[17px] min-w-[18px] items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
                    :aria-label="
                        $t(':count unread messages', {
                            count: channel.unreadCount,
                        })
                    "
                    >{{ channel.unreadCount }}</span
                >
            </Link>
        </SidebarMenuButton>
        <!-- Hover control: a close button that hides this DM from the sidebar. A
             separate button (outside the navigation link, so the anchor stays
             valid) overlaid on the row's right side, revealed on hover or focus;
             a solid background masks any unread badge underneath. -->
        <button
            type="button"
            :data-test="`dm-close-${channel.slug}`"
            :aria-label="
                $t('Close conversation with :name', { name: displayName })
            "
            :title="$t('Close direct message')"
            class="absolute top-1/2 right-1 z-10 flex size-5 -translate-y-1/2 items-center justify-center rounded bg-sidebar text-muted-foreground/60 opacity-0 transition group-hover/row:opacity-100 group-data-[active=true]/row:bg-sidebar-primary group-data-[active=true]/row:text-sidebar-primary-foreground/70 hover:text-sidebar-foreground group-data-[active=true]/row:hover:text-sidebar-primary-foreground focus-visible:opacity-100"
            @click="hide"
        >
            <X class="size-3.5" />
        </button>
    </SidebarMenuItem>
</template>
