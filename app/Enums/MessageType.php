<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageType: string
{
    /**
     * An ordinary user-authored message with a body — the default for every row.
     */
    case Standard = 'standard';

    /**
     * A system notice recording that the actor joined the channel. Rendered as a
     * centered, inert line (":name joined the channel"), never as a chat bubble.
     */
    case MemberJoined = 'member_joined';

    /**
     * A system notice recording that the actor left the channel. Rendered as a
     * centered, inert line (":name left the channel"), never as a chat bubble.
     */
    case MemberLeft = 'member_left';

    /**
     * A system notice recording that the actor changed the channel's topic. The
     * row's `body` carries the new topic verbatim (empty when it was cleared) so
     * the client can render ":name set the topic to …" in its own locale around
     * the author's own words.
     */
    case TopicChanged = 'topic_changed';

    /**
     * A system notice recording that the actor renamed the channel. The row's
     * `body` carries the new name, rendered client-side the same way as
     * {@see self::TopicChanged}.
     */
    case ChannelRenamed = 'channel_renamed';

    /**
     * A poll authored in the channel: the row's `body` is empty and the votable
     * question, options, and tally live in the related `polls` table. Rendered as
     * a first-class poll card, not a chat bubble.
     */
    case Poll = 'poll';

    /**
     * Whether the type is a system notice rather than a user-authored message.
     *
     * The flag is what makes a row render as a centered, inert notice and keeps
     * it out of every message-interaction path (edit, delete, react, reply,
     * thread, forward) and out of the unread / mention badges. A poll is
     * user-authored and interactive, so it is not a system notice — only the
     * membership and channel-edit lines are.
     *
     * Matched exhaustively so a new case has to declare which side it falls on
     * rather than silently defaulting to "not a system notice".
     */
    public function isSystem(): bool
    {
        return match ($this) {
            self::MemberJoined, self::MemberLeft, self::TopicChanged, self::ChannelRenamed => true,
            self::Standard, self::Poll => false,
        };
    }

    /**
     * The backing values of every system-notice type — the deny-list for
     * validation rules that reject a system notice as a reply or thread target.
     * Derived from isSystem() so each type is classified in exactly one place
     * and a future case joins (or stays off) the list automatically.
     *
     * @return list<string>
     */
    public static function systemValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isSystem()),
        ));
    }
}
