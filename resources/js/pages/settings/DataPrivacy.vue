<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DataPrivacy from '@/components/DataPrivacy.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { edit } from '@/routes/data-export';
import type { DataExport } from '@/types';

defineProps<{
    dataExport: DataExport | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Data & privacy',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Data & privacy" />

    <h1 class="sr-only">Data &amp; privacy</h1>

    <SettingsSection
        title="Data &amp; privacy"
        description="Download a copy of your personal data"
    >
        <DataPrivacy :data-export="dataExport" />
    </SettingsSection>
</template>
