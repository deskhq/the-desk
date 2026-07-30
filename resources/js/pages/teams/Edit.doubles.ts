import type { TeamPermissions } from '@/types';

/**
 * Every workspace permission open at once, so a case can close only the one it
 * is about.
 *
 * Shared by the `Edit.vue` test files rather than copied into each: the type is
 * exhaustive, so a new permission would otherwise have to be added by hand to
 * three identical fixtures before any of them would typecheck again.
 */
export function teamPermissions(
    overrides: Partial<TeamPermissions> = {},
): TeamPermissions {
    return {
        canUpdateTeam: true,
        canDeleteTeam: true,
        canAddMember: true,
        canUpdateMember: true,
        canRemoveMember: true,
        canCreateInvitation: true,
        canCancelInvitation: true,
        canTransferOwnership: true,
        canViewAudit: true,
        canViewSecurityLog: true,
        canViewAnalytics: true,
        canViewDeletedChannels: true,
        canManageIntegrations: true,
        canManageUserGroups: true,
        ...overrides,
    };
}
