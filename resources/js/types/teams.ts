export type TeamRole = 'owner' | 'admin' | 'member';

export type Team = {
    id: string;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    membersCount: number;
    isCurrent?: boolean;
};

export type TeamMember = {
    id: string;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

/**
 * A team member's profile as shown on their dedicated profile page. Mirrors the
 * `App\Data\UserProfileData` DTO. `role`/`roleLabel`/`memberSince` come from the
 * membership pivot; `isYou` marks the viewer's own profile.
 */
export type UserProfile = {
    id: string;
    name: string;
    email: string;
    pronouns: string | null;
    title: string | null;
    phone: string | null;
    timezone: string | null;
    role: TeamRole | null;
    roleLabel: string | null;
    memberSince: string | null;
    isYou: boolean;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
    canTransferOwnership: boolean;
    canViewAudit: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};

/**
 * A recorded admin/moderation action shown in a workspace's audit log. Mirrors
 * the `App\Data\AuditEventData` DTO. `actorName` is null when the acting user no
 * longer exists; `description` is a ready-to-render human sentence.
 */
export type AuditEntry = {
    id: string;
    action: string;
    label: string;
    actorName: string | null;
    description: string;
    occurredAt: string;
};

export type AuditActionOption = {
    value: string;
    label: string;
};

export type AuditActor = {
    id: string;
    name: string;
};

/**
 * One page of audit entries. Uses simple (prev/next) pagination so the log can
 * be paged through in full without a bounded cap.
 */
export type AuditEntriesPage = {
    data: AuditEntry[];
    prevPageUrl: string | null;
    nextPageUrl: string | null;
};
