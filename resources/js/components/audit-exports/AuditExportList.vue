<script setup lang="ts">
import { Download } from '@lucide/vue';
import AuditExportRow from '@/components/audit-exports/AuditExportRow.vue';
import type { AuditExport } from '@/types';

/**
 * The exports requested so far, newest first, or an invitation to request the
 * first one.
 */
type Props = {
    exports: AuditExport[];
    teamSlug: string;
    /** Whether a file is still being built, which the section announces. */
    hasPending: boolean;
};

defineProps<Props>();

const emit = defineEmits<{
    retry: [entry: AuditExport];
}>();
</script>

<template>
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
            <AuditExportRow
                v-for="entry in exports"
                :key="entry.id"
                :entry="entry"
                :team-slug="teamSlug"
                :has-pending="hasPending"
                @retry="emit('retry', $event)"
            />
        </ul>
    </section>
</template>
