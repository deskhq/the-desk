<script setup lang="ts">
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import type { SecurityActivityEvent } from '@/types';

type Props = {
    events: SecurityActivityEvent[];
};

const props = defineProps<Props>();

const { timezone } = useTimezone();

// Status dot: destructive for a sign-in from a new device, brass for password
// changes/resets, muted for everything else.
function dotClass(event: SecurityActivityEvent): string {
    if (event.isNewDevice) {
        return 'bg-destructive';
    }

    if (event.type === 'password_changed' || event.type === 'password_reset') {
        return 'bg-brass';
    }

    return 'bg-muted-foreground/40';
}

function occurredAt(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <p
        v-if="props.events.length === 0"
        class="text-sm text-muted-foreground"
        data-test="security-activity-empty"
    >
        {{ $t('No recent activity to show yet.') }}
    </p>

    <ul
        v-else
        class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
        data-test="security-activity-list"
    >
        <li
            v-for="event in props.events"
            :key="event.id"
            class="flex items-center gap-3 px-4 py-3"
            :data-test="`security-event-${event.id}`"
        >
            <span
                class="size-[7px] shrink-0 rounded-full"
                :class="dotClass(event)"
            />

            <div class="flex min-w-0 flex-col gap-px">
                <p class="flex items-center gap-2 text-[13.5px] font-semibold">
                    <span class="truncate">{{ event.label }}</span>
                    <span
                        v-if="event.isNewDevice"
                        class="inline-flex h-[19px] shrink-0 items-center rounded-full border border-destructive/25 bg-destructive/10 px-2.5 text-[10.5px] font-semibold tracking-[0.05em] text-destructive uppercase"
                        data-test="new-device-badge"
                    >
                        {{ $t('New device') }}
                    </span>
                </p>
                <p class="truncate text-xs text-muted-foreground">
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

            <span
                class="ml-auto shrink-0 text-xs whitespace-nowrap text-muted-foreground"
            >
                {{ occurredAt(event.occurredAt) }}
            </span>
        </li>
    </ul>
</template>
