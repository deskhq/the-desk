<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { ref } from 'vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useChimes } from '@/composables/useChimes';
import { useReadReceipts } from '@/composables/useReadReceipts';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/notifications';
import type { ChimeSound, ChimeSoundOption } from '@/types';

defineProps<{
    chimeSounds: ChimeSoundOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Notification settings'),
                href: edit(),
            },
        ],
    },
});

const { chimeSound, preview, updateChimeSound } = useChimes();

const selected = ref<ChimeSound>(chimeSound.value);

function onSelect(value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    selected.value = value as ChimeSound;
    updateChimeSound(selected.value);
}

const { shareReadReceipts, updateShareReadReceipts } = useReadReceipts();
</script>

<template>
    <Head :title="$t('Notification settings')" />

    <h1 class="sr-only">{{ $t('Notification settings') }}</h1>

    <SettingsSection
        :title="$t('Notifications')"
        :description="
            $t(
                'Choose the chime played when a new message arrives while you\'re away',
            )
        "
    >
        <div class="grid gap-2">
            <Label for="chime-sound">{{ $t('Chime sound') }}</Label>

            <div class="flex items-center gap-2">
                <Select :model-value="selected" @update:model-value="onSelect">
                    <SelectTrigger id="chime-sound" class="w-full">
                        <SelectValue :placeholder="$t('Select a chime')" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in chimeSounds"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="rounded-full"
                    :disabled="selected === 'off'"
                    data-test="preview-chime"
                    :aria-label="$t('Preview chime')"
                    @click="preview(selected)"
                >
                    <Play class="size-4" />
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        "Chimes never play for your own messages, for muted channels, or for the channel you're actively viewing. Choose",
                    )
                }}
                <span class="font-medium">{{ $t('Off') }}</span>
                {{ $t('to silence them entirely.') }}
            </p>
        </div>
    </SettingsSection>

    <SettingsSection
        :title="$t('Read receipts')"
        :description="
            $t(
                'Control whether channel members can see when you\'ve read their messages.',
            )
        "
    >
        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-4">
                <Label for="share-read-receipts">{{
                    $t('Share read receipts')
                }}</Label>

                <Switch
                    id="share-read-receipts"
                    data-test="share-read-receipts"
                    :model-value="shareReadReceipts"
                    @update:model-value="updateShareReadReceipts"
                />
            </div>

            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        "When on, channel members can see when you've read their messages. Turn this off to keep your read position private — you'll still see when others have read yours.",
                    )
                }}
            </p>
        </div>
    </SettingsSection>
</template>
