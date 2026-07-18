<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { useTranslations } from '@/composables/useTranslations';
import { destroy } from '@/routes/teams/integrations/incoming-webhooks';

type IncomingWebhook = App.Data.IncomingWebhookData;

const props = defineProps<{ team: string; webhook: IncomingWebhook }>();

const { t } = useTranslations();

const open = ref(false);
const form = useForm({});

function confirm(): void {
    form.delete(
        destroy({ team: props.team, incomingWebhook: props.webhook.id }).url,
        {
            preserveScroll: true,
            onFinish: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Button
        type="button"
        variant="outline"
        size="sm"
        class="shrink-0 rounded-full"
        :data-test="`revoke-incoming-${webhook.id}`"
        @click="open = true"
    >
        {{ t('Revoke') }}
    </Button>

    <Dialog :open="open" @update:open="(value) => (open = value)">
        <DialogContent :data-test="`revoke-incoming-dialog-${webhook.id}`">
            <DialogHeader>
                <DialogTitle>{{
                    t('Revoke :name?', { name: webhook.name })
                }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'The URL stops working immediately. Anything still posting to it will get a 404.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        t('Cancel')
                    }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    class="rounded-full"
                    :data-test="`revoke-incoming-confirm-${webhook.id}`"
                    :disabled="form.processing"
                    @click="confirm"
                >
                    {{ t('Revoke webhook') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
