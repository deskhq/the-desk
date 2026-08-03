<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Channel;
use App\Models\Team;
use App\Support\NameSlug;
use Closure;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A channel name is available in a workspace when nothing already there slugs
 * the same way.
 *
 * The question is deliberately asked about the *slug* rather than the name: the
 * slug is what the channel's URL is built from, so "Marketing" and "marketing!"
 * are one channel as far as reachability goes, and letting the second be created
 * would leave one of them unreachable.
 *
 * `$except` is the rename reading. A channel keeps its original slug through a
 * rename, so the collision to guard against there is with *other* channels — its
 * own row is never the answer.
 */
final class AvailableChannelName extends LookupRule
{
    public function __construct(
        private readonly Team $team,
        private readonly ?Channel $except = null,
    ) {}

    /**
     * Whether the given name leaves every channel in the team reachable.
     */
    #[\Override]
    protected function matches(mixed $value): bool
    {
        $slug = NameSlug::distinct((string) $value, Channel::FALLBACK_SLUG);

        return ! Channel::query()
            ->where('team_id', $this->team->id)
            ->where('slug', $slug)
            ->when($this->except, fn ($query, Channel $except) => $query->whereKeyNot($except->id))
            ->exists();
    }

    /**
     * A taken name is not a missing record, so this refusal says what it means
     * rather than falling back to the `exists` message.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    #[\Override]
    protected function failWith(Closure $fail): void
    {
        $fail(__('A channel with this name already exists.'));
    }
}
