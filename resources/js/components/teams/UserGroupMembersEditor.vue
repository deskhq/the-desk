<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/composables/useInitials';
import {
    destroy as removeMember,
    store as addMember,
} from '@/routes/teams/groups/members';

type UserGroup = App.Data.UserGroupData;
type Member = App.Data.UserData;

const props = defineProps<{
    /** The workspace slug the group belongs to. */
    team: string;
    group: UserGroup;
    /** Everyone in the workspace, before the search narrows them. */
    members: Member[];
}>();

/**
 * The picker's search. It belongs to the editor's owner, which clears it every
 * time the editor is opened.
 */
const search = defineModel<string>('search', { default: '' });

const { getInitials } = useInitials();

const membershipForm = useForm<{ user_id: string }>({ user_id: '' });

const candidates = computed<Member[]>(() => {
    const already = new Set(props.group.members.map((member) => member.id));
    const needle = search.value.trim().toLowerCase();

    return props.members
        .filter(
            (member) =>
                !already.has(member.id) &&
                (needle === '' || member.name.toLowerCase().includes(needle)),
        )
        .slice(0, 8);
});

function addToGroup(member: Member): void {
    membershipForm.user_id = member.id;
    membershipForm.post(
        addMember({ team: props.team, userGroup: props.group.id }).url,
        { preserveScroll: true, onSuccess: () => (search.value = '') },
    );
}

function removeFromGroup(memberId: string): void {
    membershipForm.delete(
        removeMember({
            team: props.team,
            userGroup: props.group.id,
            member: memberId,
        }).url,
        { preserveScroll: true },
    );
}
</script>

<template>
    <!-- The two blocks are a grid row each in the dialog they sit in, so the
         wrapper repeats its gap rather than collapsing them into one row. -->
    <div class="grid gap-4">
        <div class="flex flex-col gap-2">
            <span class="text-sm font-semibold">{{ $t('Members') }}</span>
            <p
                v-if="group.members.length === 0"
                data-test="group-members-empty"
                class="text-sm text-muted-foreground"
            >
                {{ $t('This group has no members yet.') }}
            </p>
            <ul v-else class="flex flex-wrap gap-2">
                <li
                    v-for="member in group.members"
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
                        :disabled="membershipForm.processing"
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
                v-model="search"
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
                        :disabled="membershipForm.processing"
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
    </div>
</template>
