<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import DemoLock from '@/components/DemoLock.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { update } from '@/routes/teams';
import type { Team } from '@/types';

defineProps<{
    /** The workspace being renamed; also the form's route parameter. */
    team: Team;
}>();
</script>

<template>
    <section class="border-b border-border py-6">
        <div class="mb-4">
            <h2 class="font-serif text-lg font-semibold">
                {{ $t('Team name') }}
            </h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                {{ $t('Shown in the team switcher and on invitations') }}
            </p>
        </div>

        <Form v-bind="update.form(team.slug)" v-slot="{ errors, processing }">
            <div class="flex flex-wrap items-start gap-3">
                <!-- The section heading above already reads "Team name", so
                     the field's own label is hidden rather than repeated.
                     It stays a real `<label for>` all the same: it names
                     the control for assistive tech and voice control, and
                     it focuses the input when clicked. -->
                <FormField
                    id="name"
                    :label="$t('Team name')"
                    label-class="sr-only"
                    :error="errors.name"
                    class="w-full max-w-sm"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        name="name"
                        data-test="team-name-input"
                        :default-value="team.name"
                        class="rounded-lg"
                        required
                    />
                </FormField>

                <DemoLock v-slot="{ disabled }">
                    <Button
                        type="submit"
                        class="rounded-full px-6"
                        data-test="team-save-button"
                        :disabled="processing || disabled"
                    >
                        {{ $t('Save') }}
                    </Button>
                </DemoLock>
            </div>
        </Form>
    </section>
</template>
