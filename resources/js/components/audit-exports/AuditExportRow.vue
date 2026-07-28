<script setup lang="ts">
import { Clock, Download, Loader2, RotateCcw, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useTimezone } from '@/composables/useTimezone';
import { useTranslations } from '@/composables/useTranslations';
import { formatDateTime, formatIsoDay } from '@/lib/datetime';
import { download } from '@/routes/teams/audit-exports';
import type { AuditExport } from '@/types';

/**
 * One requested export, in whichever of its four states it has reached.
 */
type Props = {
    entry: AuditExport;
    teamSlug: string;
    /** Whether another export is still building, which holds off a retry. */
    hasPending: boolean;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    retry: [entry: AuditExport];
}>();

const { t } = useTranslations();
const { timezone } = useTimezone();

type RowState = 'generating' | 'ready' | 'failed' | 'expired';

const rowState = computed<RowState>(() => {
    if (props.entry.status === 'pending') {
        return 'generating';
    }

    if (props.entry.status === 'failed') {
        return 'failed';
    }

    return props.entry.isReady ? 'ready' : 'expired';
});

const rangeText = computed(() => {
    if (props.entry.rangeStart === null && props.entry.rangeEnd === null) {
        return t('All time');
    }

    if (props.entry.rangeStart === null) {
        return t('Until :date', {
            date: formatIsoDay(props.entry.rangeEnd as string),
        });
    }

    if (props.entry.rangeEnd === null) {
        return t('From :date', { date: formatIsoDay(props.entry.rangeStart) });
    }

    return t(':start – :end', {
        start: formatIsoDay(props.entry.rangeStart),
        end: formatIsoDay(props.entry.rangeEnd),
    });
});

const requestedAt = computed(() =>
    formatDateTime(props.entry.requestedAt, timezone.value ?? undefined),
);

const downloadUrl = computed(
    () => download([props.teamSlug, props.entry.id]).url,
);
</script>

<template>
    <li
        class="flex items-center gap-3.5 rounded-xl border border-border bg-card p-3.5 shadow-[0_2px_8px_rgba(29,26,21,0.05)] max-md:flex-wrap"
        :class="{ 'opacity-65': rowState === 'expired' }"
        :data-test="`audit-export-row-${entry.id}`"
    >
        <div
            class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-muted"
        >
            <Clock
                v-if="entry.logType === 'audit'"
                class="size-4 text-muted-foreground"
            />
            <ShieldCheck v-else class="size-4 text-muted-foreground" />
        </div>

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">
                {{ entry.logTypeLabel }} · {{ entry.formatLabel }}
            </p>
            <p class="text-xs text-muted-foreground md:truncate">
                {{ rangeText }} ·
                {{
                    $t('requested by :name', {
                        name: entry.requestedByName ?? $t('a former member'),
                    })
                }}, {{ requestedAt }}
                <template v-if="rowState === 'ready' && entry.expiresAt">
                    ·
                    {{
                        $t('expires :date', {
                            date: formatDateTime(
                                entry.expiresAt,
                                timezone ?? undefined,
                            ),
                        })
                    }}
                </template>
            </p>
        </div>

        <div
            class="ml-auto flex items-center gap-2.5 max-md:w-full max-md:justify-end"
        >
            <!-- Generating -->
            <span
                v-if="rowState === 'generating'"
                class="inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass-fill px-3 py-1 text-[11.5px] font-semibold text-brass-fill-foreground"
                data-test="audit-export-status-generating"
            >
                <Loader2 class="size-3 animate-spin" />
                {{ $t('Generating…') }}
            </span>

            <!-- Ready -->
            <Button
                v-else-if="rowState === 'ready'"
                as="a"
                :href="downloadUrl"
                download
                class="h-8 gap-2 rounded-full px-4 max-md:h-11"
                :data-test="`audit-export-download-${entry.id}`"
            >
                <Download class="size-3.5" />
                {{ $t('Download') }}
            </Button>

            <!-- Failed -->
            <template v-else-if="rowState === 'failed'">
                <span
                    class="inline-flex items-center rounded-full border border-destructive/25 bg-destructive/10 px-3 py-1 text-[11.5px] font-semibold text-destructive-text"
                    data-test="audit-export-status-failed"
                >
                    {{ $t('Failed') }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    type="button"
                    class="rounded-full max-md:h-11"
                    :disabled="hasPending"
                    :data-test="`audit-export-retry-${entry.id}`"
                    @click="emit('retry', entry)"
                >
                    <RotateCcw class="size-3.5" />
                    {{ $t('Retry') }}
                </Button>
            </template>

            <!-- Expired -->
            <span
                v-else
                class="inline-flex items-center rounded-full bg-muted px-3 py-1 text-[11.5px] font-semibold text-muted-foreground"
                data-test="audit-export-status-expired"
            >
                {{ $t('Expired') }}
            </span>
        </div>
    </li>
</template>
