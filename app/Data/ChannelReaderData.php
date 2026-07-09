<?php

namespace App\Data;

use App\Models\ChannelMember;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ChannelReaderData extends Data
{
    public function __construct(
        public UserData $user,
        public ?string $lastReadMessageId,
    ) {}

    /**
     * Build the DTO from a channel membership row.
     *
     * `lastReadMessageId` is the member's read pointer, powering how far the
     * "Seen by" affordance places their avatar. The membership's `user` relation
     * is expected to be eager-loaded by the caller.
     */
    public static function fromMember(ChannelMember $member): self
    {
        return new self(
            user: UserData::fromUser($member->user),
            lastReadMessageId: $member->last_read_message_id !== null ? (string) $member->last_read_message_id : null,
        );
    }
}
