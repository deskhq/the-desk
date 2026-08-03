<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Message;

/**
 * The message a new message is allowed to point at, in the two readings that
 * exist:
 *
 * - {@see replyTo()} — an inline reply quotes a live, user-authored message in
 *   this same channel. A cross-channel, deleted or inert system-notice target is
 *   rejected rather than silently dropped.
 * - {@see threadRootIn()} — a thread hangs off one of those that is not itself a
 *   reply, which is what keeps threads one level deep.
 *
 * The two are not interchangeable and differ on exactly one message: a thread
 * reply may be quoted inline, but may not root a thread of its own.
 */
final class MessageTarget extends PresenceRule
{
    private function __construct(
        private readonly Channel $channel,
        private readonly bool $mustBeThreadRoot,
    ) {}

    /**
     * The rule for a message an inline reply may quote.
     */
    public static function replyTo(Channel $channel): self
    {
        return new self($channel, mustBeThreadRoot: false);
    }

    /**
     * The rule for a message a thread may hang off.
     */
    public static function threadRootIn(Channel $channel): self
    {
        return new self($channel, mustBeThreadRoot: true);
    }

    /**
     * Whether the given id names a message this rule accepts.
     */
    #[\Override]
    protected function matches(mixed $value): bool
    {
        $query = Message::query()
            ->whereKey($value)
            ->where('channel_id', $this->channel->id)
            ->whereNotIn('type', MessageType::systemValues());

        if ($this->mustBeThreadRoot) {
            $query->whereNull('thread_root_id');
        }

        return $query->exists();
    }
}
