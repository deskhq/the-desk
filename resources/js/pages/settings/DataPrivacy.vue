<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DataPrivacy from '@/components/DataPrivacy.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/data-export';
import type { DataExport } from '@/types';

defineProps<{
    dataExport: DataExport | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Data & privacy'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Data & privacy')" />

    <h1 class="sr-only">{{ $t('Data & privacy') }}</h1>

    <SettingsSection
        :title="$t('Data & privacy')"
        :description="$t('Download a copy of your personal data')"
    >
        <DataPrivacy :data-export="dataExport" />
    </SettingsSection>
</template>
