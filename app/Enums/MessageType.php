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
     * Whether the type is a system notice rather than a user-authored message.
     *
     * The flag is what makes a row render as a centered, inert notice and keeps
     * it out of every message-interaction path (edit, delete, react, reply,
     * thread, forward) and out of the unread / mention badges.
     */
    public function isSystem(): bool
    {
        return $this !== self::Standard;
    }
}
