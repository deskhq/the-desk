<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import UserGroupMembersEditor from '@/components/teams/UserGroupMembersEditor.vue';
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
import { update } from '@/routes/teams/groups';

type UserGroup = App.Data.UserGroupData;
type Member = App.Data.UserData;

const props = defineProps<{
    /** The workspace slug the group belongs to. */
    team: string;
    /** Every group in the workspace, re-read from after each change. */
    groups: UserGroup[];
    /** Everyone in the workspace, offered to the member picker. */
    members: Member[];
}>();

/** The group being edited, or `null` while the dialog is closed. */
const editing = ref<UserGroup | null>(null);

const editForm = useForm({ name: '', slug: '' });
const memberSearch = ref('');

function open(group: UserGroup): void {
    editing.value = group;
    editForm.defaults({ name: group.name, slug: group.slug });
    editForm.reset();
    editForm.clearErrors();
    memberSearch.value = '';
}

defineExpose({ open });

/**
 * The editor renders off the `groups` prop, so after every membership change it
 * has to re-read the freshly reloaded group rather than the stale snapshot it
 * was opened with.
 */
watch(
    () => props.groups,
    (groups) => {
        if (editing.value === null) {
            return;
        }

        editing.value =
            groups.find((group) => group.id === editing.value?.id) ?? null;
    },
);

function submitRename(): void {
    const group = editing.value;

    if (group === null) {
        return;
    }

    editForm.patch(update({ team: props.team, group: group.id }).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Dialog
        :open="editing !== null"
        @update:open="(open) => !open && (editing = null)"
    >
        <DialogContent data-test="group-edit-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('Edit group') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Renaming a group never rewrites messages already sent. They keep the handle they were written with.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex flex-col gap-3 sm:flex-row sm:items-start"
                data-test="group-rename-form"
                @submit.prevent="submitRename"
            >
                <FormField
                    :label="$t('Group name')"
                    label-class="sr-only"
                    :error="editForm.errors.name"
                    class="flex-1"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="editForm.name"
                        data-test="group-edit-name-input"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    :label="$t('Group handle')"
                    label-class="sr-only"
                    :error="editForm.errors.slug"
                    class="flex-1"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        v-model="editForm.slug"
                        data-test="group-edit-slug-input"
                        class="font-mono"
                        autocapitalize="off"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </FormField>
                <Button
                    type="submit"
                    class="rounded-full"
                    data-test="group-rename-button"
                    :disabled="editForm.processing"
                >
                    {{ $t('Save') }}
                </Button>
            </form>

            <UserGroupMembersEditor
                v-if="editing"
                v-model:search="memberSearch"
                :team="team"
                :group="editing"
                :members="members"
            />

            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        $t('Done')
                    }}</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
