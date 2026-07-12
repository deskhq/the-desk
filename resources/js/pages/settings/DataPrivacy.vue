<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DataPrivacy from '@/components/DataPrivacy.vue';
import DeleteUser from '@/components/DeleteUser.vue';
import SettingsPane from '@/components/SettingsPane.vue';
import SettingsPaneSection from '@/components/SettingsPaneSection.vue';
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

    <SettingsPane
        :title="$t('Data & privacy')"
        :description="$t('Take your data with you, or remove it entirely')"
    >
        <SettingsPaneSection
            :title="$t('Export your data')"
            :description="
                $t(
                    'An archive of your profile, teams, messages, and security activity — prepared in the background, with a download link sent by email.',
                )
            "
        >
            <DataPrivacy :data-export="dataExport" />
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Delete account')"
            :description="
                $t(
                    'Permanently remove your account and all of its data. This cannot be undone.',
                )
            "
            destructive
        >
            <DeleteUser />
        </SettingsPaneSection>
    </SettingsPane>
</template>
