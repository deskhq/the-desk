<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/composables/useLocale';
import { useTimeFormat } from '@/composables/useTimeFormat';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/locale';
import type {
    AppLocale,
    LocaleOption,
    TimeFormat,
    TimeFormatOption,
} from '@/types';

defineProps<{
    locales: LocaleOption[];
    timeFormats: TimeFormatOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Language & region'),
                href: edit(),
            },
        ],
    },
});

const { locale, updateLocale } = useLocale();

const selected = ref<AppLocale>(locale.value);

function onSelect(value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    selected.value = value as AppLocale;
    void updateLocale(selected.value);
}

const { timeFormat, updateTimeFormat } = useTimeFormat();

// Local mirror so the select answers on click, before the persisted preference
// round-trips back through the shared prop.
const selectedTimeFormat = ref<TimeFormat>(timeFormat.value);

watch(timeFormat, (value) => {
    selectedTimeFormat.value = value;
});

function onSelectTimeFormat(value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    selectedTimeFormat.value = value as TimeFormat;
    updateTimeFormat(selectedTimeFormat.value);
}
</script>

<template>
    <Head :title="$t('Language & region')" />

    <h1 class="sr-only">{{ $t('Language & region') }}</h1>

    <SettingsSection
        :title="$t('Language')"
        :description="
            $t('Choose the language used across your workspace interface.')
        "
    >
        <FormField
            id="locale"
            :label="$t('Display language')"
            :hint="
                $t(
                    'Dates and numbers are formatted to match your selected language.',
                )
            "
            v-slot="{ id }"
        >
            <Select :model-value="selected" @update:model-value="onSelect">
                <SelectTrigger :id="id" class="w-full">
                    <SelectValue :placeholder="$t('Select a language')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in locales"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </FormField>
    </SettingsSection>

    <SettingsSection
        :title="$t('Clock')"
        :description="
            $t(
                'Choose whether times of day are shown on a 12-hour or a 24-hour clock.',
            )
        "
    >
        <FormField
            id="time-format"
            :label="$t('Clock style')"
            :hint="
                $t(
                    'Auto follows your display language. Your choice applies everywhere a time of day appears, including your quiet hours.',
                )
            "
            v-slot="{ id }"
        >
            <Select
                :model-value="selectedTimeFormat"
                @update:model-value="onSelectTimeFormat"
            >
                <SelectTrigger :id="id" data-test="time-format" class="w-full">
                    <SelectValue :placeholder="$t('Select a clock style')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in timeFormats"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </FormField>
    </SettingsSection>
</template>
