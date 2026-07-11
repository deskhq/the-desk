<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Appearance settings'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Appearance settings')" />

    <h1 class="sr-only">{{ $t('Appearance settings') }}</h1>

    <SettingsSection
        :title="$t('Appearance')"
        :description="$t('Update the appearance settings for your account')"
    >
        <AppearanceTabs />
    </SettingsSection>
</template>
