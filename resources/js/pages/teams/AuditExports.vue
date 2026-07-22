<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Clock,
    Download,
    Loader2,
    RotateCcw,
    ShieldCheck,
    X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { useTimezone } from '@/composables/useTimezone';
import { useTranslations } from '@/composables/useTranslations';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { formatDateTime, formatIsoDay } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import {
    download,
    index as auditExportsIndex,
    store,
} from '@/routes/teams/audit-exports';
import type { AuditExport, AuditExportOption, Team } from '@/types';

type Props = {
    team: Team;
    exports: AuditExport[];
    logTypeOptions: AuditExportOption[];
    formatOptions: AuditExportOption[];
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
                title: translate('Exports'),
                href: auditExportsIndex(props.team.slug),
            },
        ],
    }),
});

const { t } = useTranslations();
const { timezone } = useTimezone();

// Operator docs page covering the export capability and the security-log
// scoping caveat surfaced in the footnote's "Learn more" link.
const DOCS_URL =
    'https://docs.thedeskhq.app/reference/security-and-compliance/#audit-log-exports';

const selectedLogType = ref(props.logTypeOptions[0]?.value ?? 'audit');
const selectedFormat = ref(props.formatOptions[0]?.value ?? 'csv');
const rangeStart = ref('');
const rangeEnd = ref('');
const submitting = ref(false);

const hasPending = computed(() =>
    props.exports.some((entry) => entry.status === 'pending'),
);

// End-before-start is caught client-side for immediate feedback; the server
// re-validates the same rule on submit.
const rangeError = computed(
    () =>
        rangeStart.value !== '' &&
        rangeEnd.value !== '' &&
        rangeEnd.value < rangeStart.value,
);

const canSubmit = computed(
    () => !submitting.value && !hasPending.value && !rangeError.value,
);

function clearRange(): void {
    rangeStart.value = '';
    rangeEnd.value = '';
}

function requestExport(
    logType: string,
    format: string,
    start: string | null,
    end: string | null,
): void {
    router.post(
        store(props.team.slug).url,
        {
            log_type: logType,
            format,
            range_start: start,
            range_end: end,
        },
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    requestExport(
        selectedLogType.value,
        selectedFormat.value,
        rangeStart.value || null,
        rangeEnd.value || null,
    );
}

function retry(entry: AuditExport): void {
    if (hasPending.value) {
        return;
    }

    requestExport(
        entry.logType,
        entry.format,
        entry.rangeStart,
        entry.rangeEnd,
    );
}

// Poll for readiness while any export is still generating, matching the
// Mailable with an in-page refresh; the request is a no-op once nothing pends.
let pollTimer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    pollTimer = setInterval(() => {
        if (hasPending.value) {
            // A timer tick, not a click; see {@see backgroundVisit}.
            router.reload({ ...backgroundVisit, only: ['exports'] });
        }
    }, 4000);
});

onUnmounted(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
});

type RowState = 'generating' | 'ready' | 'failed' | 'expired';

function rowState(entry: AuditExport): RowState {
    if (entry.status === 'pending') {
        return 'generating';
    }

    if (entry.status === 'failed') {
        return 'failed';
    }

    return entry.isReady ? 'ready' : 'expired';
}

function rangeText(entry: AuditExport): string {
    if (entry.rangeStart === null && entry.rangeEnd === null) {
        return t('All time');
    }

    if (entry.rangeStart === null) {
        return t('Until :date', {
            date: formatIsoDay(entry.rangeEnd as string),
        });
    }

    if (entry.rangeEnd === null) {
        return t('From :date', { date: formatIsoDay(entry.rangeStart) });
    }

    return t(':start – :end', {
        start: formatIsoDay(entry.rangeStart),
        end: formatIsoDay(entry.rangeEnd),
    });
}

function requestedAt(entry: AuditExport): string {
    return formatDateTime(entry.requestedAt, timezone.value ?? undefined);
}

function downloadUrl(entry: AuditExport): string {
    return download([props.team.slug, entry.id]).url;
}
</script>

<template>
    <Head :title="$t('Audit exports')" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Audit exports')"
            :description="
                $t(
                    'Export audit evidence for a review period. Files are available to team admins for 7 days.',
                )
            "
        />

        <!-- Request form -->
        <section
            class="flex flex-col gap-4 border-b border-border pb-6"
            data-test="audit-export-form"
        >
            <div>
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('New export') }}
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{
                        $t(
                            "One log, one format, one file. You'll get an email when it's ready.",
                        )
                    }}
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-5">
                <!-- Log type -->
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-semibold text-muted-foreground">{{
                        $t('Log')
                    }}</span>
                    <div
                        class="inline-flex items-center rounded-full bg-muted p-0.5"
                        role="group"
                        :aria-label="$t('Log')"
                    >
                        <Button
                            v-for="option in logTypeOptions"
                            :key="option.value"
                            variant="segmented"
                            size="none"
                            type="button"
                            class="h-8 gap-1.5 px-4 text-[12.5px] font-medium"
                            :aria-pressed="option.value === selectedLogType"
                            :data-test="`audit-export-log-${option.value}`"
                            @click="selectedLogType = option.value"
                        >
                            <Clock
                                v-if="option.value === 'audit'"
                                class="size-3"
                            />
                            <ShieldCheck v-else class="size-3" />
                            {{ option.label }}
                        </Button>
                    </div>
                </div>

                <!-- Format -->
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-semibold text-muted-foreground">{{
                        $t('Format')
                    }}</span>
                    <div
                        class="inline-flex items-center rounded-full bg-muted p-0.5"
                        role="group"
                        :aria-label="$t('Format')"
                    >
                        <Button
                            v-for="option in formatOptions"
                            :key="option.value"
                            variant="segmented"
                            size="none"
                            type="button"
                            class="h-8 px-4 text-[12.5px] font-medium"
                            :aria-pressed="option.value === selectedFormat"
                            :data-test="`audit-export-format-${option.value}`"
                            @click="selectedFormat = option.value"
                        >
                            {{ option.label }}
                        </Button>
                    </div>
                </div>

                <!-- Period -->
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-semibold text-muted-foreground">
                        {{ $t('Period') }}
                        <span class="font-normal">· {{ $t('optional') }}</span>
                    </span>
                    <div class="flex items-center gap-2">
                        <DatePicker
                            :model-value="rangeStart || null"
                            :placeholder="$t('Start date')"
                            :field-label="$t('Start date')"
                            class="w-40"
                            data-test="audit-export-range-start"
                            @update:model-value="rangeStart = $event ?? ''"
                        />
                        <span class="text-sm text-muted-foreground">{{
                            $t('to')
                        }}</span>
                        <DatePicker
                            :model-value="rangeEnd || null"
                            :placeholder="$t('End date')"
                            :field-label="$t('End date')"
                            :invalid="rangeError"
                            :min="rangeStart || null"
                            class="w-40"
                            data-test="audit-export-range-end"
                            @update:model-value="rangeEnd = $event ?? ''"
                        />
                        <Button
                            v-if="rangeStart || rangeEnd"
                            variant="ghost"
                            size="icon-sm"
                            type="button"
                            class="rounded-full text-muted-foreground"
                            :aria-label="$t('Clear period')"
                            data-test="audit-export-range-clear"
                            @click="clearRange"
                        >
                            <X class="size-3.5" />
                        </Button>
                    </div>
                </div>

                <!-- Submit -->
                <Button
                    type="button"
                    class="h-9 gap-2 rounded-full px-5.5"
                    :disabled="!canSubmit"
                    data-test="audit-export-submit"
                    @click="submit"
                >
                    <Download class="size-4" />
                    {{ $t('Request export') }}
                </Button>
            </div>

            <p
                v-if="rangeError"
                class="flex items-center gap-1.5 text-xs font-medium text-destructive-text"
                data-test="audit-export-range-error"
            >
                <AlertCircle class="size-3.5" />
                {{ $t('End date must be on or after the start date.') }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        "Timestamps are exported in UTC. Security-event exports cover the current members' account-level events for the period, including activity outside this team.",
                    )
                }}
                <a
                    :href="DOCS_URL"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="underline underline-offset-2 hover:text-foreground"
                    data-test="audit-export-docs-link"
                >
                    {{ $t('Learn more') }}
                </a>
            </p>
        </section>

        <!-- Recent exports -->
        <section class="flex flex-col gap-3.5">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Recent exports') }}
                </h2>
                <span
                    v-if="hasPending"
                    class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                    data-test="audit-exports-polling"
                >
                    <span class="size-1.5 rounded-full bg-brass"></span>
                    {{ $t('refreshing while an export is generating') }}
                </span>
            </div>

            <div
                v-if="exports.length === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-border bg-card px-6 py-10 text-center"
                data-test="audit-exports-empty"
            >
                <div
                    class="flex size-11 items-center justify-center rounded-xl bg-muted"
                >
                    <Download class="size-5 text-muted-foreground" />
                </div>
                <p class="font-serif text-base font-semibold">
                    {{ $t('No exports yet') }}
                </p>
                <p class="max-w-xs text-sm text-muted-foreground">
                    {{
                        $t(
                            'Request an export above and it will appear here. Files stay available for 7 days.',
                        )
                    }}
                </p>
            </div>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="entry in exports"
                    :key="entry.id"
                    class="flex items-center gap-3.5 rounded-xl border border-border bg-card p-3.5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
                    :class="{ 'opacity-65': rowState(entry) === 'expired' }"
                    :data-test="`audit-export-row-${entry.id}`"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-muted"
                    >
                        <Clock
                            v-if="entry.logType === 'audit'"
                            class="size-4 text-muted-foreground"
                        />
                        <ShieldCheck
                            v-else
                            class="size-4 text-muted-foreground"
                        />
                    </div>

                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            {{ entry.logTypeLabel }} · {{ entry.formatLabel }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ rangeText(entry) }} ·
                            {{
                                $t('requested by :name', {
                                    name:
                                        entry.requestedByName ??
                                        $t('a former member'),
                                })
                            }}, {{ requestedAt(entry) }}
                            <template
                                v-if="
                                    rowState(entry) === 'ready' &&
                                    entry.expiresAt
                                "
                            >
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

                    <div class="ml-auto flex items-center gap-2.5">
                        <!-- Generating -->
                        <span
                            v-if="rowState(entry) === 'generating'"
                            class="inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass-fill px-3 py-1 text-[11.5px] font-semibold text-brass-fill-foreground"
                            data-test="audit-export-status-generating"
                        >
                            <Loader2 class="size-3 animate-spin" />
                            {{ $t('Generating…') }}
                        </span>

                        <!-- Ready -->
                        <Button
                            v-else-if="rowState(entry) === 'ready'"
                            as="a"
                            :href="downloadUrl(entry)"
                            download
                            class="h-8 gap-2 rounded-full px-4"
                            :data-test="`audit-export-download-${entry.id}`"
                        >
                            <Download class="size-3.5" />
                            {{ $t('Download') }}
                        </Button>

                        <!-- Failed -->
                        <template v-else-if="rowState(entry) === 'failed'">
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
                                class="rounded-full"
                                :disabled="hasPending"
                                :data-test="`audit-export-retry-${entry.id}`"
                                @click="retry(entry)"
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
            </ul>
        </section>
    </div>
</template>
