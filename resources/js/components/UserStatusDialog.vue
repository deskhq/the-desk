<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import StatusExpiryFields from '@/components/status/StatusExpiryFields.vue';
import StatusPresetPicks from '@/components/status/StatusPresetPicks.vue';
import StatusTextField from '@/components/status/StatusTextField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUserStatusForm } from '@/composables/useUserStatusForm';
import { STATUS_EXPIRY_KEYS, statusExpiryLabel } from '@/lib/statusExpiry';

const open = defineModel<boolean>('open', { default: false });

const page = usePage();

const {
    isEditing,
    presets,
    emoji,
    text,
    expiryKey,
    saving,
    dateValue,
    minDate,
    hour,
    minute,
    period,
    isExpiryInFuture,
    previewStatus,
    canSave,
    choosePreset,
    save,
    clear,
} = useUserStatusForm(open);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="status-dialog"
            class="max-h-[85dvh] gap-4 overflow-y-auto p-0 sm:max-w-md"
        >
            <DialogHeader class="gap-1 border-b border-border px-5 pt-5 pb-3.5">
                <DialogTitle class="font-serif text-[20px] tracking-[-0.01em]">
                    {{ isEditing ? $t('Edit status') : $t('Set a status') }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    {{
                        $t(
                            'Pick an emoji and a short message your teammates will see beside your name.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4 px-5">
                <StatusTextField
                    v-model:emoji="emoji"
                    v-model:text="text"
                    :preview-status="previewStatus"
                    :name="page.props.auth.user.name"
                />

                <StatusPresetPicks
                    v-if="!isEditing"
                    :presets="presets"
                    @select="choosePreset"
                />

                <div class="flex items-center gap-3">
                    <span
                        id="status-expiry-label"
                        class="shrink-0 text-[13px] font-semibold text-muted-foreground"
                        >{{ $t('Clear after') }}</span
                    >
                    <Select v-model="expiryKey">
                        <SelectTrigger
                            data-test="status-expiry"
                            aria-labelledby="status-expiry-label"
                            class="h-9 flex-1 rounded-[10px] text-[13.5px]"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="key in STATUS_EXPIRY_KEYS"
                                :key="key"
                                :value="key"
                            >
                                {{ statusExpiryLabel(key) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <StatusExpiryFields
                    v-if="expiryKey === 'custom'"
                    v-model:date="dateValue"
                    v-model:hour="hour"
                    v-model:minute="minute"
                    v-model:period="period"
                    :min-date="minDate"
                />
                <p
                    v-if="!isExpiryInFuture"
                    data-test="status-expiry-past"
                    class="text-[12.5px] text-destructive-text"
                >
                    {{ $t('Pick a time in the future.') }}
                </p>
            </div>

            <div class="flex items-center gap-2 px-5 pb-5">
                <Button
                    v-if="isEditing"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="status-clear"
                    :disabled="saving"
                    class="inline-flex h-8.5 items-center rounded-full border border-border px-4 text-[12.5px] font-medium text-destructive-text hover:bg-destructive/10"
                    @click="clear"
                >
                    {{ $t('Clear status') }}
                </Button>
                <span class="flex-1" />
                <Button
                    variant="secondary"
                    class="h-8.5 rounded-full text-[12.5px]"
                    @click="open = false"
                >
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    data-test="status-save"
                    class="h-8.5 rounded-full text-[12.5px]"
                    :disabled="!canSave"
                    @click="save"
                >
                    {{ $t('Save status') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
