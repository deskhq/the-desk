/** A member's standing in a workspace. Backed by the PHP enum. */
export type TeamRole = App.Enums.TeamRole;

export type Team = {
    id: string;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    membersCount: number;
    isCurrent?: boolean;
    /**
     * Ordinary unread messages waiting in this workspace, muting and the
     * per-channel notification level already applied server-side. Drives the
     * rail tile's dot and the workspace sheet's row cue.
     */
    unreadCount: number;
    /** Unread @mentions waiting in this workspace, drawn as a numeric badge. */
    mentionCount: number;
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
 * A team member's profile, as shown on their dedicated profile page and in the
 * hover card.
 */
export type UserProfile = App.Data.UserProfileData;

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

/**
 * What the auth screens may say about a pending invitation. Backed by the PHP
 * DTO, which decides how much of the workspace a bare invite code is allowed to
 * reveal — see `App\Data\TeamInvitationContextData`.
 */
export type TeamInvitationContext = App.Data.TeamInvitationContextData;

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

/**
 * What the viewer may do in a workspace, answered once server-side and shipped
 * with the team settings page.
 */
export type TeamPermissions = App.Data.TeamPermissions;

export type RoleOption = {
    value: TeamRole;
    label: string;
};

/** Who a workspace lets open a new channel. Backed by the PHP enum. */
export type ChannelCreationPolicy = App.Enums.ChannelCreationPolicy;

/** One selectable policy in the channel-creation settings form. */
export type ChannelCreationPolicyOption = {
    value: ChannelCreationPolicy;
    label: string;
    /** One line on who the policy lets through, shown under the label. */
    description: string;
};

/**
 * The workspace's channel-creation settings as the admin page receives them:
 * the standing policy per visibility, plus the options both selects offer.
 */
export type ChannelCreationSettings = {
    public: ChannelCreationPolicy;
    private: ChannelCreationPolicy;
    options: ChannelCreationPolicyOption[];
};

/**
 * A public channel an admin may mark as a workspace default — one every new
 * member is joined to on arrival. Empty for anyone who may not manage them.
 */
export type DefaultChannelCandidate = {
    slug: string;
    name: string;
    isDefault: boolean;
    /** #general is a default in code, so its switch is on and immovable. */
    isGeneral: boolean;
};

/** A recorded admin/moderation action shown in a workspace's audit log. */
export type AuditEntry = App.Data.AuditEventData;

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

/** A single headline metric on the analytics dashboard. */
export type AnalyticsStat = App.Data.AnalyticsStatData;

/** The message count for a single day in the messages-per-day series. */
export type DailyMessageCount = App.Data.DailyMessageCountData;

/** A channel's message count in the most-active-channels ranking. */
export type ChannelActivity = App.Data.ChannelActivityData;

/** The cumulative member total at the end of a month in the growth series. */
export type MonthlyMemberCount = App.Data.MonthlyMemberCountData;

/** A member's message count in the top-contributors ranking. */
export type Contributor = App.Data.ContributorData;

/** The full analytics payload for a workspace over a selected window. */
export type WorkspaceAnalytics = App.Data.WorkspaceAnalyticsData;

/** A workspace's upload footprint against its configured storage quota. */
export type TeamStorage = App.Data.TeamStorageData;

/** One option in the analytics range toggle (7d / 30d / 90d). */
export type AnalyticsRangeOption = {
    value: string;
    label: string;
    days: number;
};
