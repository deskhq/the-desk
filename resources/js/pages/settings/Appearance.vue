<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { ref, watch } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import SettingsPane from '@/components/SettingsPane.vue';
import SettingsPaneSection from '@/components/SettingsPaneSection.vue';
import { Switch } from '@/components/ui/switch';
import { useChimes } from '@/composables/useChimes';
import { useReadReceipts } from '@/composables/useReadReceipts';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/appearance';
import type { ChimeSound, ChimeSoundOption } from '@/types';

defineProps<{
    chimeSounds: ChimeSoundOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Appearance & notifications'),
                href: edit(),
            },
        ],
    },
});

const { chimeSound, preview, updateChimeSound } = useChimes();

// Local mirror so the active pill highlights on click, before the persisted
// preference round-trips back through the shared prop.
const selected = ref<ChimeSound>(chimeSound.value);
watch(chimeSound, (value) => {
    selected.value = value;
});

function choose(value: ChimeSound): void {
    selected.value = value;
    updateChimeSound(value);
}

const { shareReadReceipts, updateShareReadReceipts } = useReadReceipts();
</script>

<template>
    <Head :title="$t('Appearance & notifications')" />

    <h1 class="sr-only">{{ $t('Appearance & notifications') }}</h1>

    <SettingsPane
        :title="$t('Appearance & notifications')"
        :description="$t('How the desk looks, and how it gets your attention')"
    >
        <SettingsPaneSection
            :title="$t('Theme')"
            :description="
                $t('Choose a light or dark theme, or match your system')
            "
        >
            <AppearanceTabs />
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Chime sound')"
            :description="
                $t(
                    'Played when a message arrives while you\'re away — never for your own messages, muted channels, or the channel you\'re viewing',
                )
            "
        >
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="option in chimeSounds"
                    :key="option.value"
                    type="button"
                    :aria-pressed="selected === option.value"
                    @click="choose(option.value)"
                    class="inline-flex h-[30px] items-center rounded-full border px-3.5 text-[12.5px] transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        selected === option.value
                            ? 'border-brass bg-brass-fill font-semibold text-foreground'
                            : 'border-border bg-card font-medium text-muted-foreground hover:text-foreground'
                    "
                >
                    {{ option.label }}
                </button>

                <button
                    type="button"
                    :disabled="selected === 'off'"
                    data-test="preview-chime"
                    @click="preview(selected)"
                    class="ml-1 inline-flex h-[30px] items-center gap-1.5 rounded-full border border-border bg-card px-3.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                >
                    <Play class="size-3 fill-current" />
                    {{ $t('Preview') }}
                </button>
            </div>
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Share read receipts')"
            :description="
                $t(
                    'When on, channel members can see when you\'ve read their messages. Turn this off to keep your read position private — you\'ll still see when others have read yours.',
                )
            "
        >
            <template #action>
                <Switch
                    id="share-read-receipts"
                    data-test="share-read-receipts"
                    :model-value="shareReadReceipts"
                    :aria-label="$t('Share read receipts')"
                    @update:model-value="updateShareReadReceipts"
                />
            </template>
        </SettingsPaneSection>
    </SettingsPane>
</template>
