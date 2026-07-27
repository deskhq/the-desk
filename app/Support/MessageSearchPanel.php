<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Channels\SearchMessages;
use App\Data\MessageSearchCriteria;
use App\Data\MessageSearchHit;
use App\Data\MessageSearchResultData;
use App\Enums\SearchScope;
use App\Http\Requests\Channels\SearchMessagesRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The read model behind the Search destination: the criteria a shared URL
 * carries, the matches they select, and the channel union the channel facet
 * offers in "All workspaces" mode.
 *
 * It reads those criteria straight off the query string rather than through a
 * form request, because the panel's props are resolved in the Inertia middleware
 * on whatever workspace route the dock happens to sit on. A malformed facet on a
 * pasted link must not be allowed to reject the whole shell, so every input is
 * resolved leniently: an unparseable date, an unknown scope or an over-long
 * query is dropped rather than rejected. The client normalizes the same URL by
 * the same rules, so the chips it draws and the matches this returns agree.
 * {@see SearchMessagesRequest} still guards the quick switcher's JSON `suggest`
 * endpoint, which is a request in its own right.
 */
final readonly class MessageSearchPanel
{
    /**
     * The longest value the panel takes from the URL, mirroring the strict rule
     * the suggest endpoint validates against.
     */
    public const int MAX_VALUE_LENGTH = 255;

    /** The date format the facets are pinned onto the URL in. */
    private const string DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private User $viewer,
        private Team $team,
        private MessageSearchCriteria $criteria,
    ) {}

    /**
     * Resolve the panel from the search params pinned on the current URL.
     *
     * The date facets are widened to whole-day bounds — `after` from the start of
     * its day, `before` to the end — so a single-day range is inclusive of every
     * message posted on it.
     */
    public static function fromRequest(Request $request, User $viewer, Team $team): self
    {
        return new self($viewer, $team, new MessageSearchCriteria(
            query: trim((string) self::param($request, 'q')),
            authorId: self::param($request, 'from'),
            channelId: self::param($request, 'in'),
            after: self::day(self::param($request, 'after'))?->startOfDay(),
            before: self::day(self::param($request, 'before'))?->endOfDay(),
            scope: SearchScope::tryFrom((string) self::param($request, 'scope')) ?? SearchScope::default(),
        ));
    }

    /**
     * The criteria the matches below were selected by, as the client writes them
     * onto the URL.
     *
     * Not the panel's state — the URL is that — but its receipt: the panel
     * compares this against the URL it is looking at and refetches when the two
     * disagree, which is how a hand-off from the ⌘K palette and a history
     * traversal both end up showing results for what is actually being asked.
     *
     * @return array{q: string, from: ?string, in: ?string, after: ?string, before: ?string, scope: string}
     */
    public function criteria(): array
    {
        return [
            'q' => $this->criteria->query,
            'from' => $this->criteria->authorId,
            'in' => $this->criteria->channelId,
            'after' => $this->criteria->after?->format(self::DATE_FORMAT),
            'before' => $this->criteria->before?->format(self::DATE_FORMAT),
            'scope' => $this->criteria->scope->value,
        ];
    }

    /**
     * The matches, shaped for the client. An empty query yields none without
     * touching the search engine.
     *
     * @return array<int, MessageSearchResultData>
     */
    public function results(): array
    {
        return app(SearchMessages::class)
            ->handle($this->viewer, $this->team, $this->criteria)
            ->map(fn (MessageSearchHit $hit): MessageSearchResultData => MessageSearchResultData::fromHit($hit, $this->viewer))
            ->all();
    }

    /**
     * The union of the viewer's channels across every team, for the channel facet
     * in cross-team ("All workspaces") mode. Each carries its owning team so the
     * picker can disambiguate same-named channels between workspaces.
     *
     * @return array<int, array{id: string, name: string, slug: string, visibility: string, teamName: string, teamSlug: string}>
     */
    public function workspaceChannels(): array
    {
        return $this->viewer->channels()
            ->with('team:id,name,slug')
            ->orderBy('channels.name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'visibility' => $channel->visibility->value,
                'teamName' => $channel->team->name,
                'teamSlug' => $channel->team->slug,
            ])
            ->all();
    }

    /**
     * A single query-string value, or null when it is absent, empty, arrived as
     * an array (`?from[]=…`, which the facets never send), or is longer than the
     * engine accepts.
     *
     * An over-long value is dropped rather than truncated: half a query is a
     * different search, and silently running it would be worse than showing the
     * empty state.
     */
    private static function param(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * A calendar day from the URL. Only the format the facets write is accepted,
     * so a hand-edited value cannot smuggle a relative expression ("yesterday")
     * into the range.
     */
    private static function day(?string $value): ?CarbonInterface
    {
        if ($value === null || ! Carbon::hasFormat($value, self::DATE_FORMAT)) {
            return null;
        }

        return Carbon::createFromFormat('!'.self::DATE_FORMAT, $value);
    }
}
