<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Search, Users, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { useInitials } from '@/composables/useInitials';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import {
    destroy,
    index as groupsIndex,
    store,
    update,
} from '@/routes/teams/groups';
import {
    destroy as removeMember,
    store as addMember,
} from '@/routes/teams/groups/members';
import type { Team } from '@/types';

type UserGroup = App.Data.UserGroupData;
type Member = App.Data.UserData;

const props = defineProps<{
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

const { getInitials } = useInitials();

const createForm = useForm({ name: '', slug: '' });

function submitCreate(): void {
    createForm.post(store(props.team.slug).url, {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

const search = ref('');
const filteredGroups = computed<UserGroup[]>(() => {
    const needle = search.value.trim().replace(/^@/, '').toLowerCase();

    if (needle === '') {
        return props.groups;
    }

    return props.groups.filter(
        (group) =>
            group.slug.includes(needle) ||
            group.name.toLowerCase().includes(needle),
    );
});

const editing = ref<UserGroup | null>(null);
const editForm = useForm({ name: '', slug: '' });
const memberSearch = ref('');

function openEditor(group: UserGroup): void {
    editing.value = group;
    editForm.defaults({ name: group.name, slug: group.slug });
    editForm.reset();
    editForm.clearErrors();
    memberSearch.value = '';
}

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

    editForm.patch(update({ team: props.team.slug, group: group.id }).url, {
        preserveScroll: true,
    });
}

const membershipForm = useForm<{ user_id: string }>({ user_id: '' });

const candidates = computed<Member[]>(() => {
    const group = editing.value;

    if (group === null) {
        return [];
    }

    const already = new Set(group.members.map((member) => member.id));
    const needle = memberSearch.value.trim().toLowerCase();

    return props.members
        .filter(
            (member) =>
                !already.has(member.id) &&
                (needle === '' || member.name.toLowerCase().includes(needle)),
        )
        .slice(0, 8);
});

function addToGroup(member: Member): void {
    const group = editing.value;

    if (group === null) {
        return;
    }

    membershipForm.user_id = member.id;
    membershipForm.post(
        addMember({ team: props.team.slug, group: group.id }).url,
        { preserveScroll: true, onSuccess: () => (memberSearch.value = '') },
    );
}

function removeFromGroup(memberId: string): void {
    const group = editing.value;

    if (group === null) {
        return;
    }

    membershipForm.delete(
        removeMember({
            team: props.team.slug,
            group: group.id,
            user: memberId,
        }).url,
        { preserveScroll: true },
    );
}

const pendingRemoval = ref<UserGroup | null>(null);
const removalForm = useForm({});

function confirmRemoval(): void {
    const group = pendingRemoval.value;

    if (group === null) {
        return;
    }

    removalForm.delete(
        destroy({ team: props.team.slug, group: group.id }).url,
        {
            preserveScroll: true,
            onFinish: () => {
                pendingRemoval.value = null;
            },
        },
    );
}
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

        <form
            v-if="permissions.canManageUserGroups"
            data-test="group-create-form"
            class="flex flex-col gap-3 rounded-xl border border-dashed border-border p-4 sm:flex-row sm:items-start"
            @submit.prevent="submitCreate"
        >
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-border bg-muted text-muted-foreground"
            >
                <Users class="size-4" />
            </div>
            <div class="flex flex-1 flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-semibold">{{
                        $t('Create a group')
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        $t(
                            'The handle is what people type after @. Leave it blank to derive it from the name.',
                        )
                    }}</span>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="flex flex-col gap-1">
                        <Input
                            v-model="createForm.name"
                            data-test="group-name-input"
                            :placeholder="$t('Dev Team')"
                            class="sm:w-56"
                            autocomplete="off"
                        />
                        <InputError :message="createForm.errors.name" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <Input
                            v-model="createForm.slug"
                            data-test="group-slug-input"
                            placeholder="@dev-team"
                            class="font-mono sm:w-48"
                            autocapitalize="off"
                            autocomplete="off"
                            spellcheck="false"
                        />
                        <InputError :message="createForm.errors.slug" />
                    </div>
                    <Button
                        type="submit"
                        data-test="group-create-button"
                        class="rounded-full"
                        :disabled="createForm.processing"
                    >
                        <Plus class="size-4" /> {{ $t('Create') }}
                    </Button>
                </div>
            </div>
        </form>

        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                data-test="group-search"
                :placeholder="$t('Search groups')"
                class="rounded-full pl-9"
            />
        </div>

        <p
            v-if="filteredGroups.length === 0"
            data-test="group-empty"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No user groups yet.') }}
        </p>

        <ul v-else class="space-y-2" data-test="group-list">
            <li
                v-for="group in filteredGroups"
                :key="group.id"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                :data-test="`group-row-${group.slug}`"
            >
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted text-muted-foreground"
                >
                    <Users class="size-4" aria-hidden="true" />
                </div>
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate font-mono text-sm font-semibold text-foreground"
                        >@{{ group.slug }}</span
                    >
                    <span class="truncate text-xs text-muted-foreground">{{
                        group.name
                    }}</span>
                </div>
                <span class="w-28 shrink-0 text-xs text-muted-foreground">{{
                    group.membersCount === 1
                        ? $t(':count member', { count: group.membersCount })
                        : $t(':count members', { count: group.membersCount })
                }}</span>
                <Button
                    v-if="permissions.canManageUserGroups"
                    variant="link"
                    size="none"
                    type="button"
                    :data-test="`group-edit-${group.slug}`"
                    class="shrink-0 text-xs font-semibold"
                    @click="openEditor(group)"
                >
                    {{ $t('Edit') }}
                </Button>
                <Button
                    v-if="permissions.canManageUserGroups"
                    variant="linkDestructive"
                    size="none"
                    type="button"
                    :data-test="`group-remove-${group.slug}`"
                    class="shrink-0 text-xs font-semibold"
                    @click="pendingRemoval = group"
                >
                    {{ $t('Delete') }}
                </Button>
            </li>
        </ul>
    </div>

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
                <div class="flex flex-1 flex-col gap-1">
                    <Input
                        v-model="editForm.name"
                        data-test="group-edit-name-input"
                        :aria-label="$t('Group name')"
                        autocomplete="off"
                    />
                    <InputError :message="editForm.errors.name" />
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <Input
                        v-model="editForm.slug"
                        data-test="group-edit-slug-input"
                        :aria-label="$t('Group handle')"
                        class="font-mono"
                        autocapitalize="off"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <InputError :message="editForm.errors.slug" />
                </div>
                <Button
                    type="submit"
                    class="rounded-full"
                    data-test="group-rename-button"
                    :disabled="editForm.processing"
                >
                    {{ $t('Save') }}
                </Button>
            </form>

            <div class="flex flex-col gap-2">
                <span class="text-sm font-semibold">{{ $t('Members') }}</span>
                <p
                    v-if="editing && editing.members.length === 0"
                    data-test="group-members-empty"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('This group has no members yet.') }}
                </p>
                <ul v-else class="flex flex-wrap gap-2">
                    <li
                        v-for="member in editing?.members ?? []"
                        :key="member.id"
                        class="flex items-center gap-2 rounded-full border border-border py-1 pr-1 pl-2 text-sm"
                        :data-test="`group-member-${member.id}`"
                    >
                        <span
                            class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[8px] font-semibold text-primary"
                            aria-hidden="true"
                            >{{ getInitials(member.name) }}</span
                        >
                        <span class="truncate">{{ member.name }}</span>
                        <Button
                            variant="ghost"
                            size="none"
                            type="button"
                            class="rounded-full p-1"
                            :aria-label="
                                $t('Remove :name from the group', {
                                    name: member.name,
                                })
                            "
                            :data-test="`group-member-remove-${member.id}`"
                            @click="removeFromGroup(member.id)"
                        >
                            <X class="size-3" />
                        </Button>
                    </li>
                </ul>
            </div>

            <div class="flex flex-col gap-2">
                <Input
                    v-model="memberSearch"
                    data-test="group-member-search"
                    :placeholder="$t('Add a member')"
                    class="rounded-full"
                />
                <ul
                    v-if="candidates.length > 0"
                    class="flex flex-col gap-1"
                    data-test="group-member-candidates"
                >
                    <li v-for="member in candidates" :key="member.id">
                        <Button
                            variant="ghost"
                            type="button"
                            class="w-full justify-start gap-2 rounded-lg text-sm"
                            :data-test="`group-member-add-${member.id}`"
                            @click="addToGroup(member)"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[8px] font-semibold text-primary"
                                aria-hidden="true"
                                >{{ getInitials(member.name) }}</span
                            >
                            {{ member.name }}
                        </Button>
                    </li>
                </ul>
            </div>

            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        $t('Done')
                    }}</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>

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
