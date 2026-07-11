<script setup lang="ts">
import { Monitor, Smartphone } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import type { SecurityActivityEvent } from '@/types';

type Props = {
    events: SecurityActivityEvent[];
};

const props = defineProps<Props>();

const { timezone } = useTimezone();

function isMobile(platform: string): boolean {
    return platform === 'iOS' || platform === 'Android';
}

function occurredAt(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <div>
        <p
            v-if="props.events.length === 0"
            class="text-sm text-muted-foreground"
            data-test="security-activity-empty"
        >
            No recent activity to show yet.
        </p>

        <ul
            v-else
            class="space-y-3"
            role="list"
            data-test="security-activity-list"
        >
            <li
                v-for="event in props.events"
                :key="event.id"
                class="flex items-center gap-4 rounded-lg border border-border p-4"
                :data-test="`security-event-${event.id}`"
            >
                <component
                    :is="isMobile(event.platform) ? Smartphone : Monitor"
                    class="size-5 shrink-0 text-muted-foreground"
                />

                <div class="min-w-0 flex-1 space-y-0.5">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-medium">
                            {{ event.label }}
                        </p>
                        <Badge
                            v-if="event.isNewDevice"
                            variant="destructive"
                            data-test="new-device-badge"
                        >
                            New device
                        </Badge>
                    </div>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ event.browser }} on {{ event.platform }} &middot;
                        {{ event.ipAddress ?? 'Unknown IP' }} &middot;
                        {{ occurredAt(event.occurredAt) }}
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
