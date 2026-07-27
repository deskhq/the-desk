import type { ReverbRuntimeConfig } from '@/lib/echo';
import type { Auth } from '@/types/auth';
import type { Channel, ChannelSection } from '@/types/channels';
import type { MessageReminder } from '@/types/messages';
import type { PersonRef } from '@/types/people';
import type { SidebarPositionOption } from '@/types/sidebar';
import type {
    DashboardInvitation,
    RoleOption,
    Team,
    TeamInvitationContext,
} from '@/types/teams';

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
            canUpdateCurrentTeam: boolean;
            canViewCurrentTeamAudit: boolean;
            canViewCurrentTeamSecurityLog: boolean;
            canManageCurrentTeamIntegrations: boolean;
            integrationsEnabled: boolean;
            invitableRoles: RoleOption[];
            channels?: Channel[];
            teamMembers?: PersonRef[];
            channelSections?: ChannelSection[];
            customEmojis?: Record<string, string>;
            frequentEmojis?: string[];
            userGroups?: App.Data.UserGroupData[];
            slashCommands?: App.Data.SlashCommandData[];
            collapsedChannelSections?: string[];
            hasUnreadThreads?: boolean;
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
