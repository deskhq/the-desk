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
import { edit, index } from '@/routes/teams';
import { index as auditIndex } from '@/routes/teams/audit';
import type {
    AuditActionOption,
    AuditActor,
    AuditEntriesPage,
    Team,
} from '@/types';

type Props = {
    team: Team;
    entries: AuditEntriesPage;
    filters: {
        action: string | null;
        actor: string | null;
    };
    actionOptions: AuditActionOption[];
    actors: AuditActor[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: 'Teams',
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
            {
                title: 'Audit log',
                href: auditIndex(props.team.slug),
            },
        ],
    }),
});

const ALL = 'all';

const { timezone } = useTimezone();

const actionFilter = ref(props.filters.action ?? ALL);
const actorFilter = ref(props.filters.actor ?? ALL);

watch([actionFilter, actorFilter], ([action, actor]) => {
    router.get(
        auditIndex(props.team.slug).url,
        {
            action: action === ALL ? undefined : action,
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
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Audit log"
            description="A record of moderation and admin actions in this workspace"
        />

        <div class="flex flex-wrap items-center gap-3">
            <Select v-model="actionFilter">
                <SelectTrigger class="w-56" data-test="audit-action-filter">
                    <SelectValue placeholder="All actions" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All actions</SelectItem>
                    <SelectItem
                        v-for="option in actionOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="actorFilter">
                <SelectTrigger class="w-56" data-test="audit-actor-filter">
                    <SelectValue placeholder="All members" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All members</SelectItem>
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
            v-if="entries.data.length === 0"
            class="text-sm text-muted-foreground"
            data-test="audit-empty"
        >
            No audit activity to show.
        </p>

        <ul v-else class="space-y-3" role="list" data-test="audit-list">
            <li
                v-for="entry in entries.data"
                :key="entry.id"
                class="flex flex-col gap-1 rounded-lg border border-border bg-card p-4 shadow-[0_2px_8px_rgba(29,26,21,0.05)] sm:flex-row sm:items-center sm:justify-between"
                :data-test="`audit-entry-${entry.id}`"
            >
                <div class="min-w-0 space-y-0.5">
                    <p class="text-sm font-semibold">
                        {{ entry.actorName ?? 'Unknown member' }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ entry.description }}
                    </p>
                </div>
                <time
                    class="shrink-0 text-xs text-muted-foreground"
                    :datetime="entry.occurredAt"
                >
                    {{ occurredAt(entry.occurredAt) }}
                </time>
            </li>
        </ul>

        <div
            v-if="entries.prevPageUrl || entries.nextPageUrl"
            class="flex items-center justify-between"
        >
            <Button
                v-if="entries.prevPageUrl"
                as-child
                variant="outline"
                size="sm"
                class="rounded-full"
                data-test="audit-prev-page"
            >
                <Link :href="entries.prevPageUrl" preserve-scroll>
                    <ChevronLeft class="h-4 w-4" /> Newer
                </Link>
            </Button>
            <span v-else></span>

            <Button
                v-if="entries.nextPageUrl"
                as-child
                variant="outline"
                size="sm"
                class="rounded-full"
                data-test="audit-next-page"
            >
                <Link :href="entries.nextPageUrl" preserve-scroll>
                    Older <ChevronRight class="h-4 w-4" />
                </Link>
            </Button>
            <span v-else></span>
        </div>
    </div>
</template>
