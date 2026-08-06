import type { ReverbRuntimeConfig } from '@/lib/echo';
import type { Auth } from '@/types/auth';
import type { Channel, ChannelSection } from '@/types/channels';
import type {
    MessageReminder,
    MessageSearchCriteria,
    MessageSearchResult,
    RosterMember,
    SearchWorkspaceChannel,
    ThreadInboxPage,
} from '@/types/messages';
import type { SidebarPositionOption } from '@/types/sidebar';
import type {
    DashboardInvitation,
    RoleOption,
    Team,
    TeamInvitationContext,
} from '@/types/teams';
import type { UnreadDigest } from '@/types/unread';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            /**
             * Instance branding. `logo` is the URL of the operator's own mark, or
             * null on an instance that still ships ours (the inline SVG mark);
             * `attribution` is the removable "Powered by The Desk" line.
             */
            branding: { logo: string | null; attribution: boolean };
            reverb: ReverbRuntimeConfig;
            webPush: { enabled: boolean; publicKey: string | null };
            auth: Auth;
            registrationEnabled: boolean;
            emailVerificationEnabled: boolean;
            demoMode: boolean;
            sso: { oidcEnabled: boolean; passwordLoginEnabled: boolean };
            /**
             * The device this request came from, for a surface that has to name
             * it. Joined for display through the `:browser on :platform` key.
             */
            currentDevice: { browser: string; platform: string };
            /**
             * The one-time account-security prompt owed to an account created in
             * this session, or null. Dies with the session, so a returning user
             * is never prompted.
             */
            postRegistrationPrompt: App.Enums.PostRegistrationPrompt | null;
            attachments: { maxSizeMb: number; maxPerMessage: number };
            gifPickerEnabled: boolean;
            pollsEnabled: boolean;
            sidebarPositions: SidebarPositionOption[];
            presence: { awayAfterMinutes: number };
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            canInviteToCurrentTeam: boolean;
            /**
             * The channel visibilities the viewer may open in their current
             * workspace, per its channel-creation policy. Empty off a workspace
             * and for someone the policy shuts out entirely.
             */
            creatableChannelVisibilities: App.Enums.ChannelVisibility[];
            canUpdateCurrentTeam: boolean;
            canViewCurrentTeamAudit: boolean;
            canViewCurrentTeamSecurityLog: boolean;
            canManageCurrentTeamIntegrations: boolean;
            integrationsEnabled: boolean;
            /** How long a deleted channel stays restorable before it is purged. */
            channelRestoreWindowDays: number;
            invitableRoles: RoleOption[];
            channels?: Channel[];
            /**
             * The workspace roster: full user records, which is what lets the
             * open channel compose its own roster from this rather than be
             * shipped a second copy of it. The DM pickers narrow it to a
             * `PersonRef` themselves.
             */
            teamMembers?: RosterMember[];
            channelSections?: ChannelSection[];
            customEmojis?: Record<string, string>;
            frequentEmojis?: string[];
            userGroups?: App.Data.UserGroupData[];
            slashCommands?: App.Data.SlashCommandData[];
            collapsedChannelSections?: string[];
            /**
             * What the viewer has not read, per channel and per workspace, plus
             * the Threads dot. The one shared prop that is never cached: the
             * rosters above say what the workspace is, this says what has
             * happened in it.
             */
            unread?: UnreadDigest;
            /**
             * The Threads panel's own props, present only while the dock has that
             * destination pinned (`?nav=threads`) — absent everywhere else, which is
             * what keeps the inbox query off every workspace navigation.
             */
            threads?: ThreadInboxPage;
            unreadThreadCount?: number;
            /**
             * The Search panel's props, on the same terms (`?nav=search`). The
             * panel's state is the URL rather than `searchCriteria` — it reads what
             * to search from there and uses the echo only to tell whether the
             * matches it holds still answer it.
             */
            searchCriteria?: MessageSearchCriteria;
            searchResults?: MessageSearchResult[];
            searchWorkspaceChannels?: SearchWorkspaceChannel[];
            pendingInvitations?: DashboardInvitation[];
            reminders?: MessageReminder[];
            firedReminders?: MessageReminder[];
            update: App.Data.UpdateStatusData | null;
            locale: string;
            translations?: Record<string, string>;
            /**
             * Set on the auth pages reached through an invitation link, so the
             * auth shell can dress its ink panel without the page threading it
             * back down as a layout prop.
             */
            teamInvitation?: TeamInvitationContext | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
        /**
         * Translate a message key against the active catalog. Registered as a
         * global property in `app.ts` so it is available in every template.
         */
        $t: (
            key: string,
            replacements?: Record<string, string | number>,
        ) => string;
    }
}
