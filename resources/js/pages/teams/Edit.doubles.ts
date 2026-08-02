import type {
    ChannelCreationSettings,
    DefaultChannelCandidate,
    TeamPermissions,
} from '@/types';

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
        canManageEmojis: true,
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

/**
 * The workspace's channel-creation settings as the admin page receives them,
 * both visibilities open unless a case says otherwise.
 */
export function channelCreationSettings(
    overrides: Partial<ChannelCreationSettings> = {},
): ChannelCreationSettings {
    return {
        public: 'members',
        private: 'members',
        options: [
            {
                value: 'members',
                label: 'Everyone',
                description: 'Every member of the workspace can create one.',
            },
            {
                value: 'admins',
                label: 'Admins only',
                description:
                    'Only admins and the workspace owner can create one.',
            },
        ],
        ...overrides,
    };
}

/** The default-channel candidates an admin sees: #general plus one ordinary channel. */
export function defaultChannelCandidates(): DefaultChannelCandidate[] {
    return [
        {
            slug: 'general',
            name: 'general',
            isDefault: false,
            isGeneral: true,
        },
        {
            slug: 'announcements',
            name: 'Announcements',
            isDefault: true,
            isGeneral: false,
        },
    ];
}
