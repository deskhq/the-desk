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
import { destroy } from '@/routes/teams/groups';

type UserGroup = App.Data.UserGroupData;

const props = defineProps<{
    /** The workspace slug the group belongs to. */
    team: string;
}>();

/** The group awaiting confirmation, or `null` while the dialog is closed. */
const pendingRemoval = defineModel<UserGroup | null>('group', {
    default: null,
});

const removalForm = useForm({});

function confirmRemoval(): void {
    const group = pendingRemoval.value;

    if (group === null) {
        return;
    }

    removalForm.delete(destroy({ team: props.team, group: group.id }).url, {
        preserveScroll: true,
        // Closed only once the delete lands: a failed request leaves the
        // dialog open rather than dismissing it as though it had worked.
        onSuccess: () => {
            pendingRemoval.value = null;
        },
    });
}
</script>

<template>
    <Dialog
        :open="pendingRemoval !== null"
        @update:open="(open) => !open && (pendingRemoval = null)"
    >
        <DialogContent data-test="group-remove-dialog">
            <DialogHeader>
                <DialogTitle
                    >{{
                        $t('Delete @:handle?', {
                            handle: pendingRemoval?.slug ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The group stops being mentionable and its handle becomes available again. Messages that already mentioned it show plain text, and the notifications they sent are unaffected.',
                        )
                    }}
                </DialogDescription>
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
                    data-test="group-remove-confirm"
                    :disabled="removalForm.processing"
                    @click="confirmRemoval"
                >
                    {{ $t('Delete group') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
