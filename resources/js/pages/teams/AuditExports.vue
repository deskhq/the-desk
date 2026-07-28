<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AuditExportForm from '@/components/audit-exports/AuditExportForm.vue';
import AuditExportList from '@/components/audit-exports/AuditExportList.vue';
import Heading from '@/components/Heading.vue';
import { useAuditExportRequests } from '@/composables/useAuditExportRequests';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as auditExportsIndex } from '@/routes/teams/audit-exports';
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

const { submitting, hasPending, requestExport, retryExport } =
    useAuditExportRequests({
        teamSlug: () => props.team.slug,
        exports: () => props.exports,
    });
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

        <AuditExportForm
            :log-type-options="logTypeOptions"
            :format-options="formatOptions"
            :busy="submitting || hasPending"
            @submit="requestExport"
        />

        <AuditExportList
            :exports="exports"
            :team-slug="team.slug"
            :has-pending="hasPending"
            @retry="retryExport"
        />
    </div>
</template>
