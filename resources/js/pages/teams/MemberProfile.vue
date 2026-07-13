<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/composables/useInitials';
import { formatLocalTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import type { Team, UserProfile } from '@/types';

type Props = {
    team: Pick<Team, 'id' | 'name' | 'slug'>;
    profile: UserProfile;
};

const props = defineProps<Props>();

const { getInitials } = useInitials();

defineOptions({
    layout: (props: {
        team: Pick<Team, 'name' | 'slug'>;
        profile: UserProfile;
    }) => ({
        breadcrumbs: [
            {
                title: translate('Teams'),
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
            {
                title: props.profile.name,
            },
        ],
    }),
});

const memberSince = computed(() => {
    if (!props.profile.memberSince) {
        return null;
    }

    return new Date(props.profile.memberSince).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
});

// A ticking clock so the member's local time stays current while the page is
// open, refreshed each minute.
const now = ref(new Date());
let ticker: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    ticker = setInterval(() => {
        now.value = new Date();
    }, 60_000);
});

onUnmounted(() => {
    if (ticker !== null) {
        clearInterval(ticker);
    }
});

const localTime = computed(() =>
    formatLocalTime(props.profile.timezone, now.value),
);
</script>

<template>
    <Head :title="profile.name" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Profile')"
            :description="$t('Member of :name', { name: team.name })"
        />

        <div
            class="flex items-start gap-4 rounded-lg border bg-card p-6 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
        >
            <Avatar class="h-16 w-16 text-lg">
                <AvatarImage
                    v-if="profile.avatar"
                    :src="profile.avatar"
                    :alt="profile.name"
                />
                <AvatarFallback>{{ getInitials(profile.name) }}</AvatarFallback>
            </Avatar>

            <div class="min-w-0 flex-1 space-y-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-serif text-xl font-semibold">
                            {{ profile.name }}
                        </h3>
                        <span
                            v-if="profile.pronouns"
                            class="text-sm text-muted-foreground"
                            >{{ profile.pronouns }}</span
                        >
                        <Badge v-if="profile.isYou" variant="secondary">{{
                            $t('You')
                        }}</Badge>
                        <Badge v-if="profile.roleLabel" variant="outline">
                            {{ profile.roleLabel }}
                        </Badge>
                    </div>
                    <p
                        v-if="profile.title"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ profile.title }}
                    </p>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">{{ $t('Email') }}</dt>
                        <dd class="mt-0.5">
                            <a
                                :href="`mailto:${profile.email}`"
                                class="underline-offset-4 hover:underline"
                                >{{ profile.email }}</a
                            >
                        </dd>
                    </div>
                    <div v-if="profile.phone">
                        <dt class="text-muted-foreground">{{ $t('Phone') }}</dt>
                        <dd class="mt-0.5">
                            <a
                                :href="`tel:${profile.phone}`"
                                class="underline-offset-4 hover:underline"
                                >{{ profile.phone }}</a
                            >
                        </dd>
                    </div>
                    <div v-if="localTime">
                        <dt class="text-muted-foreground">
                            {{ $t('Local time') }}
                        </dt>
                        <dd class="mt-0.5">
                            {{ localTime }}
                            <span class="text-muted-foreground"
                                >· {{ profile.timezone }}</span
                            >
                        </dd>
                    </div>
                    <div v-if="memberSince">
                        <dt class="text-muted-foreground">
                            {{ $t('Member since') }}
                        </dt>
                        <dd class="mt-0.5">{{ memberSince }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>
