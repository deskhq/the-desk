<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
    DialogTrigger,
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

const props = defineProps<{
    teamSlug: string;
}>();

const page = usePage();
const { t } = useTranslations();

/**
 * The visibilities this workspace's channel-creation policy leaves open to the
 * viewer, in the order the picker offers them. The create endpoint re-enforces
 * the policy, so this only keeps the form from offering a choice that would come
 * straight back as a 403.
 */
const visibilities = computed(() =>
    (
        [
            { value: 'public', label: t('Public') },
            { value: 'private', label: t('Private') },
        ] as const
    ).filter((option) =>
        (page.props.creatableChannelVisibilities ?? []).includes(option.value),
    ),
);

/** Whether there is any channel at all for the viewer to open here. */
const canCreate = computed(() => visibilities.value.length > 0);

const defaultVisibility = computed(
    () => visibilities.value[0]?.value ?? 'public',
);

const open = ref(false);
const visibility = ref<string>(defaultVisibility.value);
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    open.value = value;

    if (!value) {
        visibility.value = defaultVisibility.value;
        formKey.value++;
    }
}
</script>

<template>
    <Dialog v-if="canCreate" :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form(props.teamSlug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="handleOpenChange(false)"
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
