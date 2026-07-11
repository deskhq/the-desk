import { AtSign, BellMinus, BellOff } from '@lucide/vue';
import type { Component } from 'vue';
import type { NotificationLevel } from '@/types';

/**
 * A compact cue for a member's non-default notification state on a channel or
 * DM, shared by the conversation masthead and the sidebar rows so they never
 * disagree.
 */
export interface NotificationIndicator {
    /** The Lucide icon component to render. */
    icon: Component;
    /** The English label / i18n key; wrap it in `$t(...)` at the call site. */
    label: string;
    /** A stable token for `data-status` / `data-test` attributes. */
    status: 'muted' | 'nothing' | 'mentions';
}

/**
 * Derive the notification indicator for a member's `muted` + `notificationLevel`
 * state. Muting wins over the level, and the "all" default (unmuted) shows
 * nothing so a normal conversation stays uncluttered.
 */
export function notificationIndicator(
    muted: boolean,
    level: NotificationLevel,
): NotificationIndicator | null {
    if (muted) {
        return { icon: BellOff, label: 'Muted', status: 'muted' };
    }

    if (level === 'nothing') {
        return {
            icon: BellMinus,
            label: 'Notifications off',
            status: 'nothing',
        };
    }

    if (level === 'mentions') {
        return { icon: AtSign, label: 'Mentions only', status: 'mentions' };
    }

    return null;
}
