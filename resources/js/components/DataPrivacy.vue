<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Archive, Download } from '@lucide/vue';
import { computed } from 'vue';
import DataExportController from '@/actions/App/Http/Controllers/Settings/DataExportController';
import { Button } from '@/components/ui/button';
import { useTimezone } from '@/composables/useTimezone';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize } from '@/lib/attachments';
import { formatDateTime } from '@/lib/datetime';
import type { DataExport } from '@/types';

type Props = {
    dataExport: DataExport | null;
};

const props = defineProps<Props>();

const { timezone } = useTimezone();
const { t } = useTranslations();

const isPending = computed(() => props.dataExport?.status === 'pending');
const isReady = computed(() => props.dataExport?.isReady ?? false);
const hasFailed = computed(() => props.dataExport?.status === 'failed');

const downloadUrl = computed(() =>
    props.dataExport
        ? DataExportController.download(props.dataExport.id).url
        : '#',
);

const requestLabel = computed(() =>
    props.dataExport ? t('Request a new export') : t('Request export'),
);

// Ghost for the secondary "new export" link next to a ready download, outline
// after a failure, solid brass-ink call to action when there is no export yet.
const requestVariant = computed(() => {
    if (isReady.value) {
        return 'ghost';
    }

    return hasFailed.value ? 'outline' : 'default';
});

function formatDate(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <!-- Preparing: dashed card, request button held disabled until the email lands. -->
    <div
        v-if="isPending"
        class="flex flex-wrap items-center gap-3.5 rounded-xl border border-dashed border-border bg-muted/20 px-4 py-3.5"
        data-test="data-export-pending"
    >
        <span
            class="inline-flex h-5.5 shrink-0 items-center rounded-full bg-accent px-2.5 text-[11px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
        >
            {{ $t('Preparing') }}
        </span>
        <span class="text-sm text-muted-foreground">
            {{
                $t(
                    "Your export is being prepared. We'll email you when it's ready.",
                )
            }}
        </span>
        <Form
            v-bind="DataExportController.store.form()"
            :options="{ preserveScroll: true }"
            class="ml-auto"
            v-slot="{ processing }"
        >
            <Button
                type="submit"
                variant="ghost"
                size="sm"
                class="rounded-full text-muted-foreground"
                :disabled="processing || isPending"
                data-test="request-data-export-button"
            >
                {{ requestLabel }}
            </Button>
        </Form>
    </div>

    <!-- Ready / failed / no export yet -->
    <div
        v-else
        class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card px-4 py-4 shadow-[0_2px_8px_rgba(29,26,21,0.05)] dark:shadow-none"
        :data-test="
            isReady
                ? 'data-export-ready'
                : hasFailed
                  ? 'data-export-failed'
                  : undefined
        "
    >
        <div
            class="flex size-11 shrink-0 items-center justify-center rounded-xl border border-brass/30 bg-brass-fill text-brass-fill-foreground"
        >
            <Archive class="size-4.5" />
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <template v-if="isReady && dataExport">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    {{ $t('Your export is ready') }}
                    <span
                        v-if="dataExport.sizeBytes !== null"
                        class="inline-flex h-4.75 shrink-0 items-center rounded-full border border-brass/30 bg-brass-fill px-2.5 text-[10.5px] font-semibold tracking-[0.05em] text-brass-fill-foreground uppercase"
                        data-test="data-export-size"
                    >
                        {{ formatFileSize(dataExport.sizeBytes) }}
                    </span>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('Requested :time', {
                            time: formatDate(dataExport.requestedAt),
                        })
                    }}
                    <template v-if="dataExport.expiresAt">
                        &middot;
                        {{
                            $t('link expires :time', {
                                time: formatDate(dataExport.expiresAt),
                            })
                        }}
                    </template>
                </p>
            </template>
            <template v-else-if="hasFailed">
                <p class="text-sm font-semibold text-destructive-text">
                    {{ $t("We couldn't prepare your last export") }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t('Please request a new export to try again.') }}
                </p>
            </template>
            <template v-else>
                <p class="text-sm font-semibold">
                    {{ $t('No export ready') }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t("You haven't requested a data export yet.") }}
                </p>
            </template>
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-2.5">
            <Form
                v-bind="DataExportController.store.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    :variant="requestVariant"
                    size="sm"
                    class="rounded-full"
                    :class="isReady ? 'text-muted-foreground' : ''"
                    :disabled="processing"
                    data-test="request-data-export-button"
                >
                    {{ requestLabel }}
                </Button>
            </Form>

            <Button
                v-if="isReady && dataExport"
                as="a"
                :href="downloadUrl"
                download
                class="h-8.5 gap-2 rounded-full px-4.5"
            >
                <Download class="size-4" />
                {{ $t('Download') }}
            </Button>
        </div>
    </div>
</template>
