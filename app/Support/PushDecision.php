<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationLevel;

final class PushDecision
{
    /**
     * Decide whether one channel member should be pushed about a new message.
     *
     * This is the server-side twin of the client's `shouldChime` gate
     * (`resources/js/lib/shouldChime.ts`), and it deliberately reads the same
     * way: a direct @mention alerts unless the channel is muted or set to
     * "nothing"; ordinary traffic alerts only at the "all" level, and a
     * thread-only reply never counts as ordinary traffic (it lives in the thread
     * view, and the sidebar badge excludes it too). Do-not-disturb and the
     * member's own messages suppress everything.
     *
     * The three inputs `shouldChime` also takes but this cannot — whether a
     * chime is selected, whether the tab has focus, and whether the message
     * landed in the channel already on screen — are the browser's to answer.
     * The last two are answered there too: the service worker drops a
     * notification whose app window is already visible, so a tab that just
     * chimed does not also raise a banner.
     */
    public static function shouldPush(
        bool $isOwnMessage,
        bool $isChannelMessage,
        bool $mentionsRecipient,
        bool $muted,
        NotificationLevel $level,
        bool $dndActive,
    ): bool {
        if ($dndActive || $isOwnMessage || $muted) {
            return false;
        }

        if ($mentionsRecipient) {
            return $level->alertsOnMention();
        }

        return $isChannelMessage && $level->alertsOnUnread();
    }
}
