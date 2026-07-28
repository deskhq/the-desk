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
import { destroy as tokenDestroy } from '@/routes/teams/integrations/bots/tokens';

type BotSummary = App.Data.BotData;
type BotToken = App.Data.BotTokenData;

const props = defineProps<{
    /** The workspace slug the bot belongs to. */
    team: string;
    bot: BotSummary;
}>();

/** The token awaiting confirmation, or `null` while the dialog is closed. */
const pendingToken = defineModel<BotToken | null>('token', { default: null });

const revokeForm = useForm({});

function confirmRevoke(): void {
    const token = pendingToken.value;

    if (!token) {
        return;
    }

    revokeForm.delete(
        tokenDestroy({
            team: props.team,
            bot: props.bot.id,
            token: token.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (pendingToken.value = null),
        },
    );
}
</script>

<template>
    <Dialog
        :open="pendingToken !== null"
        @update:open="(open) => !open && (pendingToken = null)"
    >
        <DialogContent data-test="revoke-token-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Revoke :name?', { name: pendingToken?.name ?? '' })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'The token stops working immediately. Any integration using it will get a 401.',
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
                    data-test="revoke-token-confirm"
                    :disabled="revokeForm.processing"
                    @click="confirmRevoke"
                >
                    {{ $t('Revoke token') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
