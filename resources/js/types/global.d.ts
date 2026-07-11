import type { ReverbRuntimeConfig } from '@/lib/echo';
import type { Auth } from '@/types/auth';
import type { Channel, ChannelSection } from '@/types/channels';
import type { DashboardInvitation, RoleOption, Team } from '@/types/teams';

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
            auth: Auth;
            registrationEnabled: boolean;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            canInviteToCurrentTeam: boolean;
            invitableRoles: RoleOption[];
            channels?: Channel[];
            channelSections?: ChannelSection[];
            collapsedChannelSections?: string[];
            hasUnreadThreads?: boolean;
            pendingInvitations?: DashboardInvitation[];
            locale: string;
            translations?: Record<string, string>;
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
