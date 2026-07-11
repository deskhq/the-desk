<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Archive, Download } from '@lucide/vue';
import { computed } from 'vue';
import DataExportController from '@/actions/App/Http/Controllers/Settings/DataExportController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTimezone } from '@/composables/useTimezone';
import { formatDateTime } from '@/lib/datetime';
import type { DataExport } from '@/types';

type Props = {
    dataExport: DataExport | null;
};

const props = defineProps<Props>();

const { timezone } = useTimezone();

const isPending = computed(() => props.dataExport?.status === 'pending');
const isReady = computed(() => props.dataExport?.isReady ?? false);
const hasFailed = computed(() => props.dataExport?.status === 'failed');

const downloadUrl = computed(() =>
    props.dataExport
        ? DataExportController.download(props.dataExport.id).url
        : '#',
);

const requestLabel = computed(() =>
    props.dataExport ? 'Request a new export' : 'Request export',
);

function formatExpiry(iso: string): string {
    return formatDateTime(iso, timezone.value ?? undefined);
}
</script>

<template>
    <div class="max-w-md rounded-xl border border-border bg-card/40 p-5">
        <div class="flex gap-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/50 text-muted-foreground"
            >
                <Archive class="size-5" />
            </div>

            <div class="min-w-0 flex-1 space-y-4">
                <p class="text-sm text-pretty text-muted-foreground">
                    Request an export and we'll assemble an archive of your
                    profile, teams, messages, and security activity. It's
                    prepared in the background — we'll email you a download link
                    when it's ready.
                </p>

                <div
                    v-if="isReady && dataExport"
                    class="space-y-1.5"
                    data-test="data-export-ready"
                >
                    <Button
                        as="a"
                        :href="downloadUrl"
                        download
                        class="w-full justify-center sm:w-auto sm:justify-start"
                    >
                        <Download class="size-4" />
                        Download your data
                    </Button>
                    <p
                        v-if="dataExport.expiresAt"
                        class="text-xs text-muted-foreground"
                    >
                        Link expires {{ formatExpiry(dataExport.expiresAt) }}
                    </p>
                </div>

                <p
                    v-else-if="isPending"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    data-test="data-export-pending"
                >
                    <Badge variant="secondary">Preparing</Badge>
                    Your export is being prepared. We'll email you when it's
                    ready.
                </p>

                <p
                    v-else-if="hasFailed"
                    class="text-sm text-red-600 dark:text-red-400"
                    data-test="data-export-failed"
                >
                    We couldn't prepare your last export. Please try again.
                </p>

                <div :class="dataExport ? 'border-t border-border pt-4' : ''">
                    <Form
                        v-bind="DataExportController.store.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            :variant="isReady ? 'ghost' : 'outline'"
                            size="sm"
                            :disabled="processing || isPending"
                            data-test="request-data-export-button"
                            :class="
                                isReady ? '-ml-2 text-muted-foreground' : ''
                            "
                        >
                            {{ requestLabel }}
                        </Button>
                    </Form>
                </div>
            </div>
        </div>
    </div>
</template>
