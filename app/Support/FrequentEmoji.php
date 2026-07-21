<?php

namespace App\Support;

use App\Data\CustomEmojiData;
use App\Models\MessageReaction;
use App\Models\Team;
use App\Models\User;

/**
 * The viewer's frequently-used emoji, derived from their reaction history rather
 * than tracked on a write path — a toggled-off reaction deletes its row, so the
 * ranking un-counts it for free.
 */
class FrequentEmoji
{
    /**
     * How many entries the ranking always carries: the channel hover bar shows
     * all of them, the thread panel the leading three.
     */
    public const int COUNT = 5;

    /**
     * The tail the ranking is padded from when the viewer has too little (or no)
     * reaction history. Frozen, not operator-configurable, and longer than
     * `COUNT` so padding can always fill the list.
     *
     * @var list<string>
     */
    public const array DEFAULTS = ['👍', '❤️', '😂', '🎉', '👀', '🙏'];

    /**
     * The viewer's top `COUNT` emoji in their current workspace, ranked by
     * lifetime use and padded from the default set. Native glyphs and custom
     * `:name:` tokens intermix; a token whose emoji has since been revoked is
     * dropped, since it would no longer resolve to an image.
     *
     * An unscoped viewer (a guest, or a user not in a workspace) gets the
     * default set without touching the database.
     *
     * @return list<string>
     */
    public static function forUser(?User $user): array
    {
        $team = $user?->currentTeam;

        if (! $user || ! $team instanceof Team) {
            return self::padded([]);
        }

        $ranked = array_values(
            MessageReaction::query()
                ->select('message_reactions.emoji')
                ->join('messages', 'messages.id', '=', 'message_reactions.message_id')
                ->join('channels', 'channels.id', '=', 'messages.channel_id')
                ->where('message_reactions.user_id', $user->id)
                ->where('channels.team_id', $team->id)
                ->groupBy('message_reactions.emoji')
                ->orderByRaw('count(*) desc')
                ->orderByRaw('max(message_reactions.created_at) desc')
                ->orderBy('message_reactions.emoji')
                ->get()
                ->map(fn (MessageReaction $reaction): string => $reaction->emoji)
                ->all()
        );

        return self::padded(self::withoutRevokedShortcodes($ranked, $team));
    }

    /**
     * Drop every `:name:` token the team's live custom-emoji map no longer
     * resolves; native glyphs are always kept.
     *
     * @param  list<string>  $emojis
     * @return list<string>
     */
    protected static function withoutRevokedShortcodes(array $emojis, Team $team): array
    {
        $custom = CustomEmojiData::mapForTeam($team);

        return array_values(array_filter($emojis, function (string $emoji) use ($custom): bool {
            if (preg_match('/^:([a-z0-9]+(?:-[a-z0-9]+)*):$/', $emoji, $matches) !== 1) {
                return true;
            }

            return array_key_exists($matches[1], $custom);
        }));
    }

    /**
     * Cut the ranking to exactly `COUNT` entries, topping it up from the default
     * set (skipping anything already ranked) when it falls short.
     *
     * @param  list<string>  $emojis
     * @return list<string>
     */
    protected static function padded(array $emojis): array
    {
        $padding = array_diff(self::DEFAULTS, $emojis);

        return array_slice([...$emojis, ...$padding], 0, self::COUNT);
    }
}
