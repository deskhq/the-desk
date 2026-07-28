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
    /** The message queued for deletion; a non-null value opens the dialog. */
    open: boolean;
}>();

defineEmits<{
    /** Delete for real. */
    confirm: [];
    /** Dismiss without deleting. */
    close: [];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="(next) => !next && $emit('close')">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('Delete message') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            "Are you sure you want to delete this message? This can't be undone.",
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">
                        {{ $t('Cancel') }}
                    </Button>
                </DialogClose>

                <Button
                    data-test="delete-message-confirm"
                    variant="destructive"
                    @click="$emit('confirm')"
                >
                    {{ $t('Delete') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
