<script setup lang="ts">
import { UserPlus } from '@lucide/vue';
import MemberRow from '@/components/teams/MemberRow.vue';
import { Button } from '@/components/ui/button';
import type { RoleOption, TeamMember, TeamPermissions } from '@/types';

const props = defineProps<{
    /** Everyone who belongs to the workspace, owner included. */
    members: TeamMember[];
    /** The workspace slug, for each member's profile link. */
    teamSlug: string;
    /** The viewer's own user id, so their row can mark itself. */
    currentUserId: string;
    /** What the viewer may do to this roster. */
    permissions: TeamPermissions;
    /** The roles the viewer may assign, owner excluded. */
    availableRoles: RoleOption[];
}>();

defineEmits<{
    /** The viewer opened the invite dialog. */
    invite: [];
    /** The viewer picked a different role for a member. */
    updateRole: [member: TeamMember, role: string];
    /** The viewer asked to hand the team over to a member. */
    transferOwnership: [member: TeamMember];
    /** The viewer asked to remove a member from the team. */
    remove: [member: TeamMember];
}>();

const isCurrentUser = (member: TeamMember): boolean =>
    String(member.id) === props.currentUserId;
</script>

<template>
    <!-- Team members. The id is the anchor the dock's workspace sheet links
         its "Members" row at, so that row lands on the roster rather than at
         the top of the page. -->
    <section id="members" class="border-b border-border py-6">
        <div class="mb-4 flex flex-wrap items-start gap-3">
            <div class="min-w-0">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Team members') }}
                    <span class="font-normal text-muted-foreground"
                        >&middot; {{ members.length }}</span
                    >
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{
                        $t(
                            'Manage who belongs to this team and what they can do',
                        )
                    }}
                </p>
            </div>

            <Button
                v-if="permissions.canCreateInvitation"
                class="ml-auto rounded-full"
                data-test="invite-member-button"
                @click="$emit('invite')"
            >
                <UserPlus /> {{ $t('Invite member') }}
            </Button>
        </div>

        <div class="flex flex-col gap-2">
            <MemberRow
                v-for="member in members"
                :key="member.id"
                :member="member"
                :team-slug="teamSlug"
                :is-current-user="isCurrentUser(member)"
                :permissions="permissions"
                :available-roles="availableRoles"
                @update-role="$emit('updateRole', member, $event)"
                @transfer-ownership="$emit('transferOwnership', member)"
                @remove="$emit('remove', member)"
            />
        </div>
    </section>
</template>
