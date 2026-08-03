<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A channel a message may be forwarded into: one in the source's workspace that
 * the author could post into unaided.
 *
 * Forwarding stays inside one team, which is why the source channel is what the
 * destination is looked up against rather than the author's teams — the author
 * may belong to a channel in another workspace, but the message does not.
 *
 * The second half is deliberately *asked* rather than restated. The rule this
 * replaced spelled `postMessage`'s two conditions out as `whereNull('archived_at')`
 * and a `whereIn` over the author's memberships, under a comment saying it was
 * "the same constraint the `postMessage` gate applies, expressed as an existence
 * check" — one rule with two spellings, and no way for the gate to know when the
 * copy stopped agreeing with it.
 */
final class ForwardDestination extends LookupRule
{
    public function __construct(
        private readonly Channel $source,
        private readonly User $author,
    ) {}

    /**
     * Whether the given id names a channel this forward may land in.
     */
    #[\Override]
    protected function matches(mixed $value): bool
    {
        $target = Channel::query()
            ->whereKey($value)
            ->where('team_id', $this->source->team_id)
            ->first();

        return $target instanceof Channel
            && Gate::forUser($this->author)->allows('postMessage', $target);
    }
}
