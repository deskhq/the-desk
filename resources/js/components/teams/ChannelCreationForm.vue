<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DemoLock from '@/components/DemoLock.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { update } from '@/routes/teams';
import type {
    ChannelCreationPolicy,
    ChannelCreationSettings,
    Team,
} from '@/types';

/**
 * Who may open a channel in this workspace, held once per visibility so a
 * workspace can curate its public directory without also locking down private
 * channels. Admin+ only; the server re-enforces both on the create path.
 */
const props = defineProps<{
    /** The workspace being configured; also the form's route parameter. */
    team: Team;
    /** The standing policies and the options both selects offer. */
    settings: ChannelCreationSettings;
}>();

const publicPolicy = ref<ChannelCreationPolicy>(props.settings.public);
const privatePolicy = ref<ChannelCreationPolicy>(props.settings.private);

/** The chosen option's one-liner, shown under its select. */
function describe(policy: ChannelCreationPolicy): string {
    return (
        props.settings.options.find((option) => option.value === policy)
            ?.description ?? ''
    );
}

const publicHint = computed(() => describe(publicPolicy.value));
const privateHint = computed(() => describe(privatePolicy.value));
</script>

<template>
    <section class="border-b border-border py-6">
        <div class="mb-4">
            <h2 class="font-serif text-lg font-semibold">
                {{ $t('Channel creation') }}
            </h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                {{ $t('Who can open a new channel in this workspace') }}
            </p>
        </div>

        <Form
            v-bind="update.form(props.team.slug)"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid max-w-2xl gap-4 sm:grid-cols-2">
                <FormField
                    id="public-channel-creation-policy"
                    :label="$t('Public channels')"
                    :hint="publicHint"
                    :error="errors.public_channel_creation_policy"
                    v-slot="{ id }"
                >
                    <Select
                        v-model="publicPolicy"
                        name="public_channel_creation_policy"
                        data-test="public-channel-creation-policy"
                    >
                        <SelectTrigger :id="id" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in props.settings.options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    id="private-channel-creation-policy"
                    :label="$t('Private channels')"
                    :hint="privateHint"
                    :error="errors.private_channel_creation_policy"
                    v-slot="{ id }"
                >
                    <Select
                        v-model="privatePolicy"
                        name="private_channel_creation_policy"
                        data-test="private-channel-creation-policy"
                    >
                        <SelectTrigger :id="id" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in props.settings.options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>
            </div>

            <DemoLock v-slot="{ disabled }">
                <Button
                    type="submit"
                    class="rounded-full px-6"
                    data-test="channel-creation-save-button"
                    :disabled="processing || disabled"
                >
                    {{ $t('Save') }}
                </Button>
            </DemoLock>
        </Form>
    </section>
</template>
