<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FieldError from '@/components/FieldError.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { store as tokenStore } from '@/routes/teams/integrations/bots/tokens';

type BotSummary = App.Data.BotData;
type Option = { value: string; label: string };

const props = defineProps<{
    /** The workspace slug the bot belongs to. */
    team: string;
    bot: BotSummary;
    /** The scopes a token can be granted. */
    scopeOptions: Option[];
}>();

const open = defineModel<boolean>('open', { default: false });

const tokenForm = useForm<{ name: string; abilities: string[] }>({
    name: '',
    abilities: [],
});

function toggleScope(value: string): void {
    const at = tokenForm.abilities.indexOf(value);

    if (at === -1) {
        tokenForm.abilities.push(value);
    } else {
        tokenForm.abilities.splice(at, 1);
    }
}

function submitToken(): void {
    tokenForm.post(tokenStore({ team: props.team, bot: props.bot.id }).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            tokenForm.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="new-token-dialog">
            <form @submit.prevent="submitToken">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('New token for :bot', { bot: bot.name })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('Grant only the scopes this integration needs.')
                    }}</DialogDescription>
                </DialogHeader>
                <div
                    class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto py-4"
                >
                    <FormField
                        id="token-name"
                        :label="$t('Name')"
                        :error="tokenForm.errors.name"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="tokenForm.name"
                            data-test="token-name-input"
                            :placeholder="$t('ci-pipeline')"
                            autocomplete="off"
                        />
                    </FormField>
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium">
                            {{ $t('Scopes') }}
                        </legend>
                        <label
                            v-for="scope in scopeOptions"
                            :key="scope.value"
                            class="flex cursor-pointer items-start gap-2.5 rounded-xl border px-3 py-2.5 transition-colors"
                            :class="
                                tokenForm.abilities.includes(scope.value)
                                    ? 'border-brass-border bg-brass-fill'
                                    : 'border-border'
                            "
                            :data-test="`scope-${scope.value}`"
                        >
                            <Checkbox
                                class="mt-0.5"
                                :model-value="
                                    tokenForm.abilities.includes(scope.value)
                                "
                                @update:model-value="
                                    () => toggleScope(scope.value)
                                "
                            />
                            <span class="flex flex-col">
                                <span class="font-mono text-xs font-semibold">{{
                                    scope.value
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    scope.label
                                }}</span>
                            </span>
                        </label>
                        <FieldError :message="tokenForm.errors.abilities" />
                    </fieldset>
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="rounded-full"
                            >{{ $t('Cancel') }}</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        class="rounded-full"
                        data-test="token-create-button"
                        :disabled="tokenForm.processing"
                        >{{ $t('Create token') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
