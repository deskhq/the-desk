import type { ChimeSound } from './chimes';
import type { AppLocale } from './locale';
import type { SidebarPosition } from './sidebar';

export type User = {
    id: number;
    name: string;
    email: string;
    pronouns: string | null;
    title: string | null;
    phone: string | null;
    timezone: string | null;
    /** The viewer's own live custom status; null when unset or already lapsed. */
    status: App.Data.UserStatusData | null;
    /**
     * The viewer's own effective presence — their manual override, or what their
     * live connections say when they have set none.
     */
    presence: App.Enums.PresenceState;
    locale: AppLocale;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    chime_sound: ChimeSound;
    share_read_receipts: boolean;
    sidebar_position: SidebarPosition;
    onboarding_completed_at: string | null;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */
