<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
import { edit } from '@/routes/notifications';
import type { ChimeSound, ChimeSoundOption } from '@/types';

defineProps<{
    chimeSounds: ChimeSoundOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
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
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notifications"
            description="Choose the chime played when a new message arrives while you're away"
        />

        <div class="grid max-w-md gap-2">
            <Label for="chime-sound">Chime sound</Label>

            <div class="flex items-center gap-2">
                <Select :model-value="selected" @update:model-value="onSelect">
                    <SelectTrigger id="chime-sound" class="w-full">
                        <SelectValue placeholder="Select a chime" />
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
                    aria-label="Preview chime"
                    @click="preview(selected)"
                >
                    <Play class="size-4" />
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                Chimes never play for your own messages, for muted channels, or
                for the channel you're actively viewing. Choose
                <span class="font-medium">Off</span> to silence them entirely.
            </p>
        </div>

        <div class="grid max-w-md gap-2">
            <div class="flex items-center justify-between gap-4">
                <Label for="share-read-receipts">Share read receipts</Label>

                <Switch
                    id="share-read-receipts"
                    data-test="share-read-receipts"
                    :model-value="shareReadReceipts"
                    @update:model-value="updateShareReadReceipts"
                />
            </div>

            <p class="text-sm text-muted-foreground">
                When on, channel members can see when you've read their
                messages. Turn this off to keep your read position private —
                you'll still see when others have read yours.
            </p>
        </div>
    </div>
</template>
