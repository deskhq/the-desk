<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ChevronDown, Crown, X } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useDemoMode } from '@/composables/useDemoMode';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { show as showMember } from '@/routes/teams/members';
import type {
    RoleOption,
    TeamMember,
    TeamPermissions,
    TeamRole,
} from '@/types';

defineProps<{
    /** The member this row stands for. */
    member: TeamMember;
    /** The workspace slug, for the member's profile link. */
    teamSlug: string;
    /** Whether the row is the viewer's own membership. */
    isCurrentUser: boolean;
    /** What the viewer may do to this roster. */
    permissions: TeamPermissions;
    /** The roles the viewer may assign, owner excluded. */
    availableRoles: RoleOption[];
}>();

defineEmits<{
    /** The viewer picked a different role for this member. */
    updateRole: [role: string];
    /** The viewer asked to hand the team over to this member. */
    transferOwnership: [];
    /** The viewer asked to remove this member from the team. */
    remove: [];
}>();

const { getInitials } = useInitials();
const { t } = useTranslations();
const { demoMode } = useDemoMode();

/**
 * How a demo-locked owner-level control paints: dimmed and inert-looking, but
 * still a live button, so it keeps its place in the tab order and its tooltip
 * still opens on hover.
 */
const lockedClass =
    'cursor-not-allowed opacity-50 hover:bg-transparent dark:hover:bg-transparent';

/**
 * The one-line description of what each assignable role can do, shown under the
 * role name inside the role dropdown. Keyed by role so the copy stays with the
 * permission it describes.
 */
const roleDescription = (role: TeamRole): string => {
    switch (role) {
        case 'admin':
            return t('Can manage members, channels, and invitations');
        case 'member':
            return t('Can read and post in channels they belong to');
        default:
            return '';
    }
};
</script>

<template>
    <div
        data-test="member-row"
        class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card p-3.5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
    >
        <Avatar class="h-10 w-10 shrink-0">
            <AvatarImage
                v-if="member.avatar"
                :src="member.avatar"
                :alt="member.name"
            />
            <AvatarFallback
                :class="
                    member.role === 'owner'
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground'
                "
                class="text-xs font-semibold"
                >{{ getInitials(member.name) }}</AvatarFallback
            >
        </Avatar>

        <div class="min-w-0">
            <Link
                :href="showMember([teamSlug, member.id])"
                class="font-semibold underline-offset-4 hover:underline"
                data-test="member-profile-link"
            >
                {{ member.name }}
                <span
                    v-if="isCurrentUser"
                    class="text-sm font-medium text-muted-foreground"
                    >{{ $t('(you)') }}</span
                >
                <UserStatusEmoji
                    :status="member.status"
                    :name="member.name"
                    class="align-[-1px] text-xs"
                />
            </Link>
            <div
                v-if="member.email"
                class="truncate text-sm text-muted-foreground"
            >
                {{ member.email }}
            </div>
            <div
                v-if="member.status?.text"
                data-test="member-status-text"
                class="truncate text-sm text-muted-foreground"
            >
                {{ member.status.text }}
            </div>
        </div>

        <div class="ml-auto flex items-center gap-1.5">
            <span
                v-if="member.role === 'owner'"
                class="inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass-fill px-3 py-1 text-xs font-semibold text-brass-fill-foreground"
            >
                <Crown class="h-3 w-3 text-brass" />
                {{ member.role_label }}
            </span>

            <DropdownMenu v-else-if="permissions.canUpdateMember">
                <DropdownMenuTrigger as-child>
                    <Button
                        data-test="member-role-trigger"
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                    >
                        {{ member.role_label }}
                        <ChevronDown class="ml-1 h-3.5 w-3.5 opacity-50" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-64">
                    <DropdownMenuItem
                        v-for="role in availableRoles"
                        :key="role.value"
                        data-test="member-role-option"
                        class="flex-col items-start gap-0.5"
                        @click="$emit('updateRole', role.value)"
                    >
                        <span
                            class="flex w-full items-center gap-1.5 font-medium"
                        >
                            {{ role.label }}
                            <Check
                                v-if="member.role === role.value"
                                class="ml-auto h-3.5 w-3.5 text-brass"
                            />
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ roleDescription(role.value) }}
                        </span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <span
                v-else
                class="rounded-full border border-border px-3 py-1 text-xs font-semibold"
            >
                {{ member.role_label }}
            </span>

            <TooltipProvider
                v-if="
                    member.role !== 'owner' && permissions.canTransferOwnership
                "
            >
                <Tooltip>
                    <!-- The demo lock is `aria-disabled`, not `disabled`: a
                         disabled button leaves the tab order and swallows
                         pointer events, so its reason would be reachable
                         neither by keyboard nor on hover. -->
                    <TooltipTrigger as-child>
                        <Button
                            data-test="member-transfer-ownership-button"
                            variant="ghost"
                            size="icon"
                            class="rounded-full text-muted-foreground"
                            :class="demoMode ? lockedClass : undefined"
                            :aria-disabled="demoMode || undefined"
                            :aria-label="$t('Transfer ownership')"
                            @click="!demoMode && $emit('transferOwnership')"
                        >
                            <Crown class="h-4 w-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p>
                            {{
                                demoMode
                                    ? $t('Disabled in the demo')
                                    : $t('Transfer ownership')
                            }}
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <TooltipProvider
                v-if="member.role !== 'owner' && permissions.canRemoveMember"
            >
                <Tooltip>
                    <!-- Same demo lock as the transfer control above. -->
                    <TooltipTrigger as-child>
                        <Button
                            data-test="member-remove-button"
                            variant="ghost"
                            size="icon"
                            class="rounded-full text-muted-foreground"
                            :class="demoMode ? lockedClass : undefined"
                            :aria-disabled="demoMode || undefined"
                            :aria-label="$t('Remove member')"
                            @click="!demoMode && $emit('remove')"
                        >
                            <X class="h-4 w-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p>
                            {{
                                demoMode
                                    ? $t('Disabled in the demo')
                                    : $t('Remove member')
                            }}
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </div>
</template>
