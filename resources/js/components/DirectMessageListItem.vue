<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { store as hideDirectMessage } from '@/actions/App/Http/Controllers/Channels/HideDirectMessageController';
import AvatarStack from '@/components/AvatarStack.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { groupDmSidebarName } from '@/lib/groupDm';
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

// How many participant avatars a group row stacks before a "+N" overflow chip.
const MAX_ROW_AVATARS = 3;

const isGroup = computed(() => props.channel.isGroupDirect);

const page = usePage();

// The other participant of a 1:1 DM, whose avatar the row shows.
const soloParticipant = computed(
    () => props.channel.dmParticipants?.[0] ?? null,
);

// The avatar image for a 1:1 row: the other participant's, or — in a self-DM,
// which has no other participant — the viewer's own. Null (so the initials
// fallback shows) when that person has no avatar.
const soloAvatar = computed(() =>
    soloParticipant.value
        ? (soloParticipant.value.avatar ?? null)
        : (page.props.auth.user.avatar ?? null),
);

// The viewer-relative name. A 1:1 name comes pre-resolved on the channel (the
// other participant, self-DM localized to "You"); a group joins its
// participants' first names with a "+N" overflow.
const displayName = computed(() => {
    if (isGroup.value) {
        return (
            groupDmSidebarName(props.channel.dmParticipants ?? []) ||
            t('Group conversation')
        );
    }

    return props.isSelf ? t('You') : props.channel.name;
});

const isActive = computed(() => props.channel.slug === props.activeChannelSlug);

// The mute / notification-level cue for this DM, matching the conversation
// masthead; null (and so no icon) for an unmuted DM at the default level.
const indicator = computed(() =>
    notificationIndicator(props.channel.muted, props.channel.notificationLevel),
);

/**
 * Close (hide) this DM from the sidebar. Closing a DM the user isn't viewing just
 * reloads the shared `channels` prop so the row leaves in place; closing the DM
 * they're currently on redirects them home (the server sends them to the team's
 * default channel) so they don't linger on the now-hidden conversation. A later
 * message — or reopening the conversation — brings it back.
 */
function hide(): void {
    const leaving = isActive.value;

    router.post(
        hideDirectMessage({
            team: props.teamSlug,
            channel: props.channel.slug,
        }).url,
        { leaving },
        {
            // Staying put: patch only the sidebar and keep the current view.
            // Leaving: let the redirect home drive a normal visit.
            preserveScroll: !leaving,
            preserveState: !leaving,
            ...(leaving ? {} : { only: ['channels'] }),
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
                :aria-current="isActive ? 'page' : undefined"
            >
                <!-- A group DM stacks its participants' avatars; a 1:1 shows the
                     single other participant with a presence dot. -->
                <AvatarStack
                    v-if="isGroup"
                    data-test="dm-avatar-stack"
                    :members="channel.dmParticipants ?? []"
                    :max="MAX_ROW_AVATARS"
                    size="sm"
                    :ring-class="
                        isActive ? 'ring-sidebar-primary' : 'ring-sidebar'
                    "
                />
                <span v-else class="relative size-4.5 shrink-0">
                    <!-- The other participant's avatar (their initials when they
                         have none), keyed for presence by the dot below. -->
                    <Avatar class="size-4.5" aria-hidden="true">
                        <AvatarImage
                            v-if="soloAvatar"
                            :src="soloAvatar"
                            :alt="displayName"
                        />
                        <AvatarFallback
                            class="text-[8px] font-semibold text-primary"
                        >
                            {{ getInitials(channel.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <span
                        data-test="dm-presence-dot"
                        :data-online="online"
                        aria-hidden="true"
                        class="absolute -right-0.5 -bottom-0.5 size-2 rounded-full ring-2"
                        :class="[
                            online
                                ? 'bg-emerald-500'
                                : 'bg-muted-foreground/50',
                            isActive ? 'ring-sidebar-primary' : 'ring-sidebar',
                        ]"
                    />
                    <!-- The presence is announced through a screen-reader-only
                         label rather than an aria-label on the role-less dot,
                         which assistive tech ignores on a bare <span>. -->
                    <span data-test="dm-presence-label" class="sr-only">{{
                        online ? $t('Online') : $t('Offline')
                    }}</span>
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
                    class="ml-auto flex h-4.25 min-w-4.5 items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
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
        <Button
            variant="ghost"
            size="icon"
            :data-test="`dm-close-${channel.slug}`"
            :aria-label="
                $t('Close conversation with :name', { name: displayName })
            "
            :title="$t('Close direct message')"
            class="absolute top-1/2 right-1 z-10 size-5 -translate-y-1/2 rounded bg-sidebar text-muted-foreground/60 opacity-0 transition group-hover/row:opacity-100 group-data-[active=true]/row:bg-sidebar-primary group-data-[active=true]/row:text-sidebar-primary-foreground/70 hover:bg-sidebar hover:text-sidebar-foreground group-data-[active=true]/row:hover:bg-sidebar-primary group-data-[active=true]/row:hover:text-sidebar-primary-foreground focus-visible:opacity-100"
            @click="hide"
        >
            <X class="size-3.5" />
        </Button>
    </SidebarMenuItem>
</template>
