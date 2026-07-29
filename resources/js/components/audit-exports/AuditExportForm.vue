<script setup lang="ts">
import { AlertCircle, Clock, Download, ShieldCheck, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import type { AuditExportOption } from '@/types';

/**
 * The export request: which log, in which format, over which period. The form
 * owns nothing but its own fields — the request it describes is emitted, and
 * the page decides what to send.
 */
type Props = {
    logTypeOptions: AuditExportOption[];
    formatOptions: AuditExportOption[];
    /** Whether the server is busy, either with this request or another export. */
    busy: boolean;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    submit: [
        logType: string,
        format: string,
        rangeStart: string | null,
        rangeEnd: string | null,
    ];
}>();

// Operator docs page covering the export capability and the security-log
// scoping caveat surfaced in the footnote's "Learn more" link.
const DOCS_URL =
    'https://docs.thedeskhq.app/reference/security-and-compliance/#audit-log-exports';

const selectedLogType = ref(props.logTypeOptions[0]?.value ?? 'audit');
const selectedFormat = ref(props.formatOptions[0]?.value ?? 'csv');
const rangeStart = ref('');
const rangeEnd = ref('');

// End-before-start is caught client-side for immediate feedback; the server
// re-validates the same rule on submit.
const rangeError = computed(
    () =>
        rangeStart.value !== '' &&
        rangeEnd.value !== '' &&
        rangeEnd.value < rangeStart.value,
);

const canSubmit = computed(() => !props.busy && !rangeError.value);

function clearRange(): void {
    rangeStart.value = '';
    rangeEnd.value = '';
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    emit(
        'submit',
        selectedLogType.value,
        selectedFormat.value,
        rangeStart.value || null,
        rangeEnd.value || null,
    );
}
</script>

<template>
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
                        class="h-8 gap-1.5 px-4 text-[12.5px] font-medium max-md:h-11"
                        :aria-pressed="option.value === selectedLogType"
                        :data-test="`audit-export-log-${option.value}`"
                        @click="selectedLogType = option.value"
                    >
                        <Clock v-if="option.value === 'audit'" class="size-3" />
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
                        class="h-8 px-4 text-[12.5px] font-medium max-md:h-11"
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
                <div class="flex flex-wrap items-center gap-2">
                    <DatePicker
                        :model-value="rangeStart || null"
                        :placeholder="$t('Start date')"
                        :field-label="$t('Start date')"
                        class="w-40 max-md:h-11"
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
                        class="w-40 max-md:h-11"
                        data-test="audit-export-range-end"
                        @update:model-value="rangeEnd = $event ?? ''"
                    />
                    <Button
                        v-if="rangeStart || rangeEnd"
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        class="rounded-full text-muted-foreground max-md:size-11"
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
                class="h-9 gap-2 rounded-full px-5.5 max-md:h-11"
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
</template>
