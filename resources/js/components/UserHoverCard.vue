<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AtSign, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { useInitials } from '@/composables/useInitials';
import { fetchUserProfile } from '@/composables/useUserProfileCard';
import { formatLocalTime } from '@/lib/datetime';
import { show } from '@/routes/teams/members';
import type { UserProfile } from '@/types';

const props = defineProps<{
    teamSlug: string;
    userId: string;
    name: string;
}>();

const emit = defineEmits<{
    mention: [member: { id: string; name: string }];
}>();

const { getInitials } = useInitials();

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

function onMention(): void {
    emit('mention', { id: props.userId, name: props.name });
}
</script>

<template>
    <HoverCard :open-delay="300" :close-delay="150" @update:open="onOpenChange">
        <HoverCardTrigger as-child>
            <slot />
        </HoverCardTrigger>
        <HoverCardContent class="w-72">
            <!-- Loading skeleton until the first fetch resolves. -->
            <div v-if="loading && !profile" class="flex animate-pulse gap-3">
                <div class="size-12 rounded-full bg-muted" />
                <div class="flex-1 space-y-2 py-1">
                    <div class="h-3.5 w-2/3 rounded bg-muted" />
                    <div class="h-3 w-1/2 rounded bg-muted" />
                </div>
            </div>

            <div v-else class="space-y-3">
                <div class="flex items-start gap-3">
                    <Avatar class="size-12 text-base">
                        <AvatarFallback>{{
                            getInitials(profile?.name ?? name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2">
                            <span class="font-semibold">{{
                                profile?.name ?? name
                            }}</span>
                            <span
                                v-if="profile?.pronouns"
                                class="text-xs text-muted-foreground"
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
                            class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <Badge v-if="profile?.roleLabel" variant="outline">
                                {{ profile.roleLabel }}
                            </Badge>
                            <span
                                v-if="localTime()"
                                class="text-xs text-muted-foreground"
                                >{{ localTime() }} local time</span
                            >
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        class="flex-1"
                        data-test="hover-card-mention"
                        @click="onMention"
                    >
                        <AtSign class="size-4" /> Mention
                    </Button>
                    <Button variant="outline" size="sm" class="flex-1" as-child>
                        <Link :href="show([teamSlug, userId])">
                            <UserRound class="size-4" /> Profile
                        </Link>
                    </Button>
                </div>
            </div>
        </HoverCardContent>
    </HoverCard>
</template>
