<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/teams/groups';

const props = defineProps<{
    /** The workspace slug the new group belongs to. */
    team: string;
}>();

const form = useForm({ name: '', slug: '' });

function submit(): void {
    form.post(store(props.team).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form
        data-test="group-create-form"
        class="flex flex-col gap-3 rounded-xl border border-dashed border-border p-4 sm:flex-row sm:items-start"
        @submit.prevent="submit"
    >
        <div
            class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-border bg-muted text-muted-foreground"
        >
            <Users class="size-4" />
        </div>
        <div class="flex flex-1 flex-col gap-3">
            <div class="flex flex-col gap-1">
                <span class="text-sm font-semibold">{{
                    $t('Create a group')
                }}</span>
                <span class="text-xs text-muted-foreground">{{
                    $t(
                        'The handle is what people type after @. Leave it blank to derive it from the name.',
                    )
                }}</span>
            </div>
            <!-- The row heading above names the pair, so each field's own
                 label is hidden rather than repeated. It stays a real
                 `<label for>` all the same: a placeholder disappears the
                 moment someone types, which leaves the field unnamed
                 exactly when they might look for the name again.

                 The error's space is not reserved here. These cells are
                 narrow enough that a message wraps to three lines, and the
                 row is the last thing in the card — so drawn out of flow it
                 would run past the card's own border, and there is nothing
                 below it to be pushed around by letting it grow instead. -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <FormField
                    :label="$t('Group name')"
                    label-class="sr-only"
                    :error="form.errors.name"
                    :reserve="false"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.name"
                        data-test="group-name-input"
                        :placeholder="$t('Dev Team')"
                        class="max-md:h-11 sm:w-56"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    :label="$t('Group handle')"
                    label-class="sr-only"
                    :error="form.errors.slug"
                    :reserve="false"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="form.slug"
                        data-test="group-slug-input"
                        placeholder="@dev-team"
                        class="font-mono max-md:h-11 sm:w-48"
                        autocapitalize="off"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </FormField>
                <Button
                    type="submit"
                    data-test="group-create-button"
                    class="rounded-full max-md:h-11"
                    :disabled="form.processing"
                >
                    <Plus class="size-4" /> {{ $t('Create') }}
                </Button>
            </div>
        </div>
    </form>
</template>
