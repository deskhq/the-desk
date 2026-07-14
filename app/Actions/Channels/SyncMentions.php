<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\InlineMarkdown;

class SyncMentions
{
    /**
     * The composer inserts a `@[Display Name](user-id)` token for every member it
     * resolves; this captures each token's user id so the parser can round-trip
     * it back to a real member unambiguously, regardless of display-name clashes.
     */
    private const string TOKEN_PATTERN = '/@\[[^\]]+\]\(([0-9a-fA-F-]{36})\)/';

    /**
     * Parse the message body and reconcile its mention rows.
     *
     * Only tokens that resolve to an actual member of the channel's team are
     * kept; unknown ids and non-members are ignored. Because this uses `sync`,
     * an edit adds newly-mentioned members and drops any no longer referenced.
     */
    public function handle(Channel $channel, Message $message): void
    {
        $message->mentionedUsers()->sync(
            $this->resolveMemberIds($channel, $message->body),
        );
    }

    /**
     * Resolve the distinct team-member user ids referenced by mention tokens.
     *
     * @return array<int, string>
     */
    private function resolveMemberIds(Channel $channel, string $body): array
    {
        // A mention token inside an inline `code` span renders inert on the
        // client, so it must not notify either; mask code spans before matching.
        preg_match_all(self::TOKEN_PATTERN, InlineMarkdown::maskInlineCode($body), $matches);

        $ids = array_unique($matches[1]);

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('users.id', $ids)
            ->whereHas('teams', fn ($query) => $query->whereKey($channel->team_id))
            ->pluck('users.id')
            ->all();
    }
}
