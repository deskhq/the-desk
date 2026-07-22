<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AtSign, MessageSquare, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useInitials } from '@/composables/useInitials';
import { useOpenDirectMessage } from '@/composables/useOpenDirectMessage';
import { fetchUserProfile } from '@/composables/useUserProfileCard';
import { formatLocalTime, formatTimeOfDay } from '@/lib/datetime';
import { presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import { show } from '@/routes/teams/members';
import type { UserProfile } from '@/types';

const props = defineProps<{
    teamSlug: string;
    userId: string;
    name: string;
    /**
     * How the member reads on the team presence roster. Absent on the surfaces
     * that open a card without a roster to hand, which then show no dot at all.
     */
    presence?: RenderedPresence;
}>();

const emit = defineEmits<{
    mention: [member: { id: string; name: string }];
}>();

const { getInitials } = useInitials();
const { openDirectMessage } = useOpenDirectMessage(() => props.teamSlug);

const profile = ref<UserProfile | null>(null);
const loading = ref(false);
const loaded = ref(false);

/**
 * Lazily load the profile the first time the card opens; the fetch is memoised
 * so reopening (or a second card for the same person) never refetches.
 */
async function onOpenChange(open: boolean): Promise<void> {
    if (!open || loaded.value) {
        return;
    }

    loading.value = true;
    profile.value = await fetchUserProfile(props.teamSlug, props.userId);
    loading.value = false;
    loaded.value = true;
}

function localTime(): string | null {
    return formatLocalTime(profile.value?.timezone ?? null, new Date());
}

/**
 * When the member's status clears, as a time of day in the *viewer's* zone —
 * "until 3:00 PM" is only useful against the reader's own clock. Null for a
 * status that never clears.
 */
const statusClearsAt = computed(() =>
    profile.value?.status?.expiresAt
        ? formatTimeOfDay(profile.value.status.expiresAt)
        : null,
);

function onMention(): void {
    emit('mention', { id: props.userId, name: props.name });
}

function onMessage(): void {
    openDirectMessage(props.userId);
}
</script>

<template>
    <HoverCard :open-delay="300" :close-delay="150" @update:open="onOpenChange">
        <HoverCardTrigger as-child>
            <slot />
        </HoverCardTrigger>
        <HoverCardContent
            class="w-72 rounded-2xl p-5 shadow-[0_10px_28px_rgba(29,26,21,0.14)]"
        >
            <!-- Loading skeleton until the first fetch resolves. -->
            <div v-if="loading && !profile" class="flex animate-pulse gap-3">
                <div class="size-12 rounded-full bg-muted" />
                <div class="flex-1 space-y-2 py-1">
                    <div class="h-3.5 w-2/3 rounded bg-muted" />
                    <div class="h-3 w-1/2 rounded bg-muted" />
                </div>
            </div>

            <div v-else class="space-y-4">
                <div class="flex items-start gap-3">
                    <div class="relative size-12 shrink-0">
                        <Avatar class="size-12 text-base">
                            <AvatarImage
                                v-if="profile?.avatar"
                                :src="profile.avatar"
                                :alt="profile?.name ?? name"
                            />
                            <AvatarFallback>{{
                                getInitials(profile?.name ?? name)
                            }}</AvatarFallback>
                        </Avatar>
                        <PresenceDot
                            v-if="presence"
                            data-test="hover-card-presence"
                            :presence="presence"
                            surface-class="bg-popover"
                            size="48"
                            class="ring-popover"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span
                                class="font-serif text-[17px] font-semibold"
                                >{{ profile?.name ?? name }}</span
                            >
                            <span
                                v-if="profile?.pronouns"
                                class="font-serif text-xs text-muted-foreground italic"
                                >{{ profile.pronouns }}</span
                            >
                        </div>
                        <p
                            v-if="profile?.title"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ profile.title }}
                        </p>
                        <div
                            class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <Badge
                                v-if="profile?.roleLabel"
                                variant="outline"
                                class="border-brass px-2 text-[10px] font-semibold tracking-wide text-brass-fill-foreground uppercase"
                            >
                                {{ profile.roleLabel }}
                            </Badge>
                            <!-- The card is the one surface with room to spell
                                 the presence out, so away gets the word beside
                                 its ring rather than only the dot's shape. -->
                            <span
                                v-if="presence"
                                data-test="hover-card-presence-label"
                                class="flex items-center gap-1.5 text-xs"
                                :class="
                                    presence === 'away'
                                        ? 'font-serif text-muted-foreground italic'
                                        : 'text-muted-foreground'
                                "
                            >
                                <PresenceDot
                                    :presence="presence"
                                    surface-class="bg-popover"
                                    class="size-2"
                                />
                                {{ $t(presenceLabelKey(presence)) }}
                            </span>
                            <span
                                v-if="localTime()"
                                class="text-xs text-muted-foreground"
                                >{{ localTime() }} {{ $t('local time') }}</span
                            >
                        </div>
                    </div>
                </div>

                <!-- The status band: the one surface with room for the emoji,
                     the full text, and when it clears. Absent entirely when the
                     member has no live status. -->
                <div
                    v-if="profile?.status"
                    data-test="hover-card-status"
                    class="flex items-center gap-2.5 rounded-[10px] border border-border bg-muted px-3 py-2"
                >
                    <UserStatusEmoji
                        :status="profile.status"
                        :name="profile.name"
                        class="text-[15px]"
                    />
                    <span
                        v-if="profile.status.text"
                        class="min-w-0 flex-1 truncate text-xs text-foreground"
                        >{{ profile.status.text }}</span
                    >
                    <span
                        v-if="statusClearsAt"
                        class="shrink-0 font-serif text-[10.5px] text-muted-foreground italic"
                        >{{ $t('until :time', { time: statusClearsAt }) }}</span
                    >
                </div>

                <div class="space-y-2">
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            class="h-8 flex-1 rounded-full"
                            data-test="hover-card-message"
                            @click="onMessage"
                        >
                            <MessageSquare class="size-4" /> {{ $t('Message') }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-8 flex-1 rounded-full"
                            data-test="hover-card-mention"
                            @click="onMention"
                        >
                            <AtSign class="size-4" /> {{ $t('Mention') }}
                        </Button>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 w-full rounded-full"
                        as-child
                    >
                        <Link :href="show([teamSlug, userId])">
                            <UserRound class="size-4" />
                            {{ $t('View profile') }}
                        </Link>
                    </Button>
                </div>
            </div>
        </HoverCardContent>
    </HoverCard>
</template>
