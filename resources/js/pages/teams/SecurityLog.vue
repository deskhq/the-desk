<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { index, edit } from '@/routes/teams';
import { index as securityLogIndex } from '@/routes/teams/security-log';
import type {
    SecurityEventsPage,
    SecurityEventTypeOption,
    SecurityLogActor,
    Team,
    TeamSecurityEvent,
} from '@/types';

type Props = {
    team: Team;
    events: SecurityEventsPage;
    filters: {
        type: string | null;
        actor: string | null;
    };
    typeOptions: SecurityEventTypeOption[];
    actors: SecurityLogActor[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
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
                title: translate('Security log'),
                href: securityLogIndex(props.team.slug),
            },
        ],
    }),
});

const ALL = 'all';

const { timezone } = useTimezone();

const typeFilter = ref(props.filters.type ?? ALL);
const actorFilter = ref(props.filters.actor ?? ALL);

watch([typeFilter, actorFilter], ([type, actor]) => {
    router.get(
        securityLogIndex(props.team.slug).url,
        {
            type: type === ALL ? undefined : type,
            actor: actor === ALL ? undefined : actor,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
});

function occurredAt(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}

// Status dot: destructive for a sign-in from a new device, brass for password
// changes/resets, muted for everything else.
function dotClass(event: TeamSecurityEvent): string {
    if (event.isNewDevice) {
        return 'bg-destructive';
    }

    if (event.type === 'password_changed' || event.type === 'password_reset') {
        return 'bg-brass';
    }

    return 'bg-muted-foreground/40';
}
</script>

<template>
    <Head :title="$t('Security log')" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Security log')"
            :description="
                $t('Security events for the members of this workspace')
            "
        />

        <div class="flex flex-wrap items-center gap-3">
            <Select v-model="typeFilter">
                <SelectTrigger
                    class="w-56"
                    data-test="security-log-type-filter"
                >
                    <SelectValue :placeholder="$t('All events')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">{{ $t('All events') }}</SelectItem>
                    <SelectItem
                        v-for="option in typeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="actorFilter">
                <SelectTrigger
                    class="w-56"
                    data-test="security-log-actor-filter"
                >
                    <SelectValue :placeholder="$t('All members')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">{{
                        $t('All members')
                    }}</SelectItem>
                    <SelectItem
                        v-for="actor in actors"
                        :key="actor.id"
                        :value="actor.id"
                    >
                        {{ actor.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <p
            v-if="events.data.length === 0"
            class="text-sm text-muted-foreground"
            data-test="security-log-empty"
        >
            {{ $t('No security activity to show.') }}
        </p>

        <ul
            v-else
            class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
            role="list"
            data-test="security-log-list"
        >
            <li
                v-for="event in events.data"
                :key="event.id"
                class="flex items-center gap-3 px-4 py-3"
                :data-test="`security-log-event-${event.id}`"
            >
                <span
                    class="size-1.75 shrink-0 rounded-full"
                    :class="dotClass(event)"
                />

                <div class="flex min-w-0 flex-col gap-px">
                    <p
                        class="flex items-center gap-2 text-[13.5px] font-semibold"
                    >
                        <span class="truncate">{{ event.actorName }}</span>
                        <span
                            v-if="event.isNewDevice"
                            class="inline-flex h-4.75 shrink-0 items-center rounded-full border border-destructive/25 bg-destructive/10 px-2.5 text-[10.5px] font-semibold tracking-[0.05em] text-destructive-text uppercase"
                            data-test="security-log-new-device-badge"
                        >
                            {{ $t('New device') }}
                        </span>
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ event.label }}
                        &middot;
                        {{
                            $t(':browser on :platform', {
                                browser: event.browser,
                                platform: event.platform,
                            })
                        }}
                        &middot;
                        {{ event.ipAddress ?? $t('Unknown IP') }}
                    </p>
                </div>

                <time
                    class="ml-auto shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                    :datetime="event.occurredAt"
                >
                    {{ occurredAt(event.occurredAt) }}
                </time>
            </li>
        </ul>

        <div
            v-if="events.prevPageUrl || events.nextPageUrl"
            class="flex items-center justify-between"
        >
            <Button
                v-if="events.prevPageUrl"
                as-child
                variant="outline"
                size="sm"
                class="rounded-full"
                data-test="security-log-prev-page"
            >
                <Link :href="events.prevPageUrl" preserve-scroll>
                    <ChevronLeft class="h-4 w-4" /> {{ $t('Newer') }}
                </Link>
            </Button>
            <span v-else></span>

            <Button
                v-if="events.nextPageUrl"
                as-child
                variant="outline"
                size="sm"
                class="rounded-full"
                data-test="security-log-next-page"
            >
                <Link :href="events.nextPageUrl" preserve-scroll>
                    {{ $t('Older') }} <ChevronRight class="h-4 w-4" />
                </Link>
            </Button>
            <span v-else></span>
        </div>
    </div>
</template>
