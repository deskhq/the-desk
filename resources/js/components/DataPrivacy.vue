<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import DataExportController from '@/actions/App/Http/Controllers/Settings/DataExportController';
import Heading from '@/components/Heading.vue';
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
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Data & privacy"
            description="Download a copy of your personal data"
        />

        <div class="space-y-4 rounded-lg border border-border p-4">
            <p class="text-sm text-muted-foreground">
                Request an export and we'll assemble an archive of your profile,
                teams, messages, and security activity. It's prepared in the
                background — we'll email you a download link when it's ready.
            </p>

            <div
                v-if="isReady && dataExport"
                class="flex flex-wrap items-center gap-3"
                data-test="data-export-ready"
            >
                <Button as="a" :href="downloadUrl" download>
                    <Download class="size-4" />
                    Download your data
                </Button>
                <span
                    v-if="dataExport.expiresAt"
                    class="text-sm text-muted-foreground"
                >
                    Link expires {{ formatExpiry(dataExport.expiresAt) }}
                </span>
            </div>

            <p
                v-else-if="isPending"
                class="flex items-center gap-2 text-sm text-muted-foreground"
                data-test="data-export-pending"
            >
                <Badge variant="secondary">Preparing</Badge>
                Your export is being prepared. We'll email you when it's ready.
            </p>

            <p
                v-else-if="hasFailed"
                class="text-sm text-red-600 dark:text-red-400"
                data-test="data-export-failed"
            >
                We couldn't prepare your last export. Please try again.
            </p>

            <Form
                v-bind="DataExportController.store.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="processing || isPending"
                    data-test="request-data-export-button"
                >
                    {{ requestLabel }}
                </Button>
            </Form>
        </div>
    </div>
</template>
