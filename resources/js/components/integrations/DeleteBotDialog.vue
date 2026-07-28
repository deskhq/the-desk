<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import { destroy as botDestroy } from '@/routes/teams/integrations/bots';

type BotSummary = App.Data.BotData;

const props = defineProps<{
    /** The workspace slug the bot belongs to. */
    team: string;
    bot: BotSummary;
}>();

const open = defineModel<boolean>('open', { default: false });

const deleteForm = useForm({});

function confirmDeleteBot(): void {
    deleteForm.delete(botDestroy({ team: props.team, bot: props.bot.id }).url);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="delete-bot-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete :name?', { name: bot.name })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'This permanently deletes the bot and its credentials. Its past messages are reassigned to a deleted account. This cannot be undone.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        $t('Cancel')
                    }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    class="rounded-full"
                    data-test="delete-bot-confirm"
                    :disabled="deleteForm.processing"
                    @click="confirmDeleteBot"
                >
                    {{ $t('Delete bot') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
