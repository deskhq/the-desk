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
    /** Only present for roster managers (Owner/Admin); null for plain Members. */
    email: string | null;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
    /** The member's live custom status; null when unset or already lapsed. */
    status: App.Data.UserStatusData | null;
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
    avatar: string | null;
    pronouns: string | null;
    title: string | null;
    phone: string | null;
    timezone: string | null;
    role: TeamRole | null;
    roleLabel: string | null;
    memberSince: string | null;
    isYou: boolean;
    status: App.Data.UserStatusData | null;
    /** Whether the member is in do-not-disturb right now; never says when it ends. */
    isDnd: boolean;
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
    canViewSecurityLog: boolean;
    canViewAnalytics: boolean;
    canManageIntegrations: boolean;
    canManageUserGroups: boolean;
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

/**
 * A single headline metric on the analytics dashboard. Mirrors the
 * `AnalyticsStatData` DTO; each tile fills only the optional fields it renders.
 */
export type AnalyticsStat = {
    value: number;
    total: number | null;
    delta: number | null;
    deltaPercent: number | null;
    secondary: number | null;
};

/** The message count for a single day in the messages-per-day series. */
export type DailyMessageCount = {
    date: string;
    count: number;
};

/** A channel's message count in the most-active-channels ranking. */
export type ChannelActivity = {
    id: string;
    name: string;
    count: number;
};

/** The cumulative member total at the end of a month in the growth series. */
export type MonthlyMemberCount = {
    month: string;
    total: number;
};

/** A member's message count in the top-contributors ranking. */
export type Contributor = {
    id: string;
    name: string;
    count: number;
};

/** The full analytics payload for a workspace over a selected window. */
export type WorkspaceAnalytics = {
    range: string;
    days: number;
    activeMembers: AnalyticsStat;
    messagesPerDay: AnalyticsStat;
    messagesSent: AnalyticsStat;
    activeChannels: AnalyticsStat;
    messagesByDay: DailyMessageCount[];
    topChannels: ChannelActivity[];
    memberGrowth: MonthlyMemberCount[];
    topContributors: Contributor[];
};

/** One option in the analytics range toggle (7d / 30d / 90d). */
export type AnalyticsRangeOption = {
    value: string;
    label: string;
    days: number;
};
