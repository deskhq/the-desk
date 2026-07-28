<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import CreateUserGroupForm from '@/components/teams/CreateUserGroupForm.vue';
import DeleteUserGroupDialog from '@/components/teams/DeleteUserGroupDialog.vue';
import EditUserGroupDialog from '@/components/teams/EditUserGroupDialog.vue';
import UserGroupsRack from '@/components/teams/UserGroupsRack.vue';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { index as groupsIndex } from '@/routes/teams/groups';
import type { Team } from '@/types';

type UserGroup = App.Data.UserGroupData;
type Member = App.Data.UserData;

defineProps<{
    team: Team;
    groups: UserGroup[];
    members: Member[];
    permissions: { canManageUserGroups: boolean };
}>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            { title: translate('Teams'), href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            {
                title: translate('User groups'),
                href: groupsIndex(props.team.slug),
            },
        ],
    }),
});

/** The editor, opened through its exposed handler so it can seed its form. */
const editor = ref<InstanceType<typeof EditUserGroupDialog> | null>(null);

/** The group the deletion dialog is confirming, or `null` while it is closed. */
const pendingRemoval = ref<UserGroup | null>(null);
</script>

<template>
    <Head :title="$t('User groups')" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('User groups')"
            :description="
                $t(
                    'Name a set of people so anyone in this workspace can notify them all with a single @mention',
                )
            "
        />

        <CreateUserGroupForm
            v-if="permissions.canManageUserGroups"
            :team="team.slug"
        />

        <UserGroupsRack
            :groups="groups"
            :can-manage="permissions.canManageUserGroups"
            @edit="editor?.open($event)"
            @remove="pendingRemoval = $event"
        />
    </div>

    <EditUserGroupDialog
        ref="editor"
        :team="team.slug"
        :groups="groups"
        :members="members"
    />

    <DeleteUserGroupDialog v-model:group="pendingRemoval" :team="team.slug" />
</template>
