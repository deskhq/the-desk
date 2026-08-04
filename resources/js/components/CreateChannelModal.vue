<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslations } from '@/composables/useTranslations';
import { canCreateChannel, creatableVisibilities } from '@/lib/channelCreation';

/**
 * The shell's create-channel form.
 *
 * One of {@see DialogHost}'s singletons rather than a wrapper around each of its
 * triggers, which is what lets a zero-argument palette verb reach it (#1223).
 * The affordances that open it gate themselves on {@see canCreateChannel}, the
 * same reading this makes to decide whether to mount at all.
 */
const props = defineProps<{
    teamSlug: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const { t } = useTranslations();

/** The picker's options, labelled, for the visibilities the policy leaves open. */
const visibilities = computed(() => {
    const labels: Record<App.Enums.ChannelVisibility, string> = {
        public: t('Public'),
        private: t('Private'),
    };

    return creatableVisibilities(page.props.creatableChannelVisibilities).map(
        (value) => ({ value, label: labels[value] }),
    );
});

/** Whether there is any channel at all for the viewer to open here. */
const canCreate = computed(() =>
    canCreateChannel(page.props.creatableChannelVisibilities),
);

const defaultVisibility = computed(
    () => visibilities.value[0]?.value ?? 'public',
);

const visibility = ref<string>(defaultVisibility.value);
const formKey = ref(0);

/**
 * A half-finished form is forgotten between openings, so it always starts clean.
 *
 * Watched off the model rather than hooked onto the dialog's own `update:open`,
 * because as a singleton it is closed from both sides: its own chrome writes
 * through the model, and so does whatever opened it.
 *
 * The picker is re-read on the way *in* rather than reset on the way out,
 * because the singleton outlives a workspace switch — a default settled on
 * leaving one workspace may not be offered at all in the next, and the picker
 * would open holding a value with no option under it. The fields the `Form`
 * owns are cleared by remounting it, which is the close's business.
 */
watch(open, (isOpen) => {
    if (isOpen) {
        visibility.value = defaultVisibility.value;

        return;
    }

    formKey.value++;
});
</script>

<template>
    <Dialog v-if="canCreate" v-model:open="open">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form(props.teamSlug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('Create a channel') }}</DialogTitle>
                    <DialogDescription>
                        {{ $t('Channels are where your team communicates.') }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <FormField
                        id="name"
                        :label="$t('Name')"
                        :error="errors.name"
                        v-slot="{ id }"
                    >
                        <div class="relative">
                            <span
                                aria-hidden="true"
                                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 font-serif text-[15px] text-brass italic"
                                >#</span
                            >
                            <Input
                                :id="id"
                                name="name"
                                data-test="create-channel-name"
                                :placeholder="$t('marketing')"
                                class="pl-7"
                                required
                            />
                        </div>
                    </FormField>

                    <FormField
                        id="visibility"
                        :label="$t('Visibility')"
                        :error="errors.visibility"
                        v-slot="{ id }"
                    >
                        <Select
                            v-model="visibility"
                            name="visibility"
                            data-test="create-channel-visibility"
                        >
                            <SelectTrigger :id="id" class="w-full">
                                <SelectValue
                                    :placeholder="$t('Select visibility')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in visibilities"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>

                    <FormField
                        id="topic"
                        :label="$t('Topic (optional)')"
                        :error="errors.topic"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            name="topic"
                            data-test="create-channel-topic"
                            :placeholder="$t('What\'s this channel about?')"
                        />
                    </FormField>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            {{ $t('Cancel') }}
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="create-channel-submit"
                        :disabled="processing"
                    >
                        {{ $t('Create channel') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
