<script setup lang="ts">
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

defineProps<{
    /** Named in the title, so the confirmation says which channel it archives. */
    channelName: string;
}>();

defineEmits<{
    /** The viewer confirmed: archive the channel. */
    confirm: [];
}>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    $t('Archive #:channel', { channel: channelName })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The channel becomes read-only and leaves the sidebar. Its messages are kept and stay searchable.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> {{ $t('Cancel') }} </Button>
                </DialogClose>

                <Button
                    data-test="archive-channel-confirm"
                    variant="destructive"
                    @click="$emit('confirm')"
                >
                    {{ $t('Archive') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
