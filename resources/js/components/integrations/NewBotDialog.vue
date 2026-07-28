<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import { store as botStore } from '@/routes/teams/integrations/bots';

const props = defineProps<{
    /** The workspace slug the bot is minted in. */
    team: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const botForm = useForm<{ name: string }>({ name: '' });

function submitBot(): void {
    botForm.post(botStore(props.team).url, {
        onSuccess: () => {
            open.value = false;
            botForm.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="new-bot-dialog">
            <form @submit.prevent="submitBot">
                <DialogHeader>
                    <DialogTitle>{{ $t('New bot') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'A bot posts through the API. Add it to channels and mint a token next.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <div class="py-4">
                    <FormField
                        id="bot-name"
                        :label="$t('Name')"
                        :error="botForm.errors.name"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="botForm.name"
                            data-test="bot-name-input"
                            :placeholder="$t('Deploy Bot')"
                            autocomplete="off"
                        />
                    </FormField>
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
                        data-test="bot-create-button"
                        :disabled="botForm.processing"
                        >{{ $t('Create bot') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
