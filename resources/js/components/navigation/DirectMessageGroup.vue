<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import DirectMessageListItem from '@/components/DirectMessageListItem.vue';
import SidebarSectionHeader from '@/components/navigation/SidebarSectionHeader.vue';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
} from '@/components/ui/sidebar';
import { useTranslations } from '@/composables/useTranslations';
import { dmParticipantPresence } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import type { Channel } from '@/types/channels';

const props = defineProps<{
    /** The DM conversations, already ordered by recent activity. */
    channels: Channel[];
    teamSlug: string;
    activeChannelSlug: string | null;
    collapsed: boolean;
    /** How a teammate's presence dot should render. */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether a teammate is in do-not-disturb, for the crescent badge. */
    isDndFor: (userId: string) => boolean;
}>();

defineEmits<{
    /** The user asked to collapse or expand the group. */
    toggle: [];
    /** The user asked to start a new direct message. */
    newMessage: [];
}>();

const page = usePage();

const { t } = useTranslations();

const currentUser = computed(() => page.props.auth.user);

/**
 * The dot for a DM row: the counterpart's live presence, falling back to the
 * viewer's own where there is no single counterpart to read.
 */
function presenceForRow(channel: Channel): RenderedPresence {
    return dmParticipantPresence(
        channel.dmUserId,
        props.presenceFor,
        currentUser.value.presence,
    );
}
</script>

<template>
    <!-- Direct messages: a fixed group outside the star/section/placement
         system. Rows render the other participant (self renders "You") with a
         presence dot and a plain unread badge, ordered by recent activity. -->
    <SidebarGroup class="pb-0" data-test="direct-messages-group">
        <SidebarSectionHeader
            name="direct"
            :label="t('Direct messages')"
            :collapsed="collapsed"
            @toggle="$emit('toggle')"
        />
        <SidebarGroupAction
            :title="$t('New message')"
            data-test="new-dm-trigger"
            class="top-2 size-5 rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            @click="$emit('newMessage')"
        >
            <Plus class="size-3.25" />
            <span class="sr-only">{{ $t('New message') }}</span>
        </SidebarGroupAction>
        <SidebarGroupContent
            v-show="!collapsed"
            data-test="section-content-direct"
        >
            <ul class="flex w-full min-w-0 flex-col gap-1">
                <DirectMessageListItem
                    v-for="dm in channels"
                    :key="dm.id"
                    :channel="dm"
                    :team-slug="teamSlug"
                    :active-channel-slug="activeChannelSlug"
                    :presence="presenceForRow(dm)"
                    :is-dnd="dm.dmUserId != null && isDndFor(dm.dmUserId)"
                    :is-self="dm.dmUserId === String(currentUser.id)"
                />
            </ul>
            <p
                v-if="channels.length === 0"
                data-test="direct-messages-empty"
                class="px-2 pb-1 text-[12px] text-muted-foreground normal-case"
            >
                {{ $t('No direct messages yet') }}
            </p>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
