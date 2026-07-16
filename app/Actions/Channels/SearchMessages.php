<?php

namespace App\Actions\Channels;

use App\Data\MessageSearchCriteria;
use App\Data\MessageSearchHit;
use App\Enums\SearchScope;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\MessagePlainText;
use App\Support\MessageSnippet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SearchMessages
{
    /**
     * The maximum number of message matches returned for a single search.
     */
    public const int RESULT_LIMIT = 50;

    /**
     * How many relevance-ranked candidates the engine selects before the
     * authoritative Eloquent pass re-orders them by recency and trims to
     * {@see RESULT_LIMIT}. A window wider than the page keeps the newest matches
     * from being starved when relevance and recency disagree.
     */
    private const int CANDIDATE_WINDOW = 200;

    /**
     * Faceted, highlighted, recency-ordered full-text search over a team's
     * messages, ACL-filtered to the user's channels.
     *
     * The engine selects a relevance-ranked candidate window; an authoritative
     * Eloquent pass then re-asserts the channel ACL, applies the author and date
     * facets, orders by recency, and trims to the page. The `channel_id` filter is
     * the whole ACL — the id set is exactly the channels the user belongs to (in
     * this team, or across all their teams when the scope is `All`) — so a bug in
     * the engine-side filter can never leak a private channel. A blank query, or a
     * user who belongs to no channel, yields no matches without touching the engine.
     *
     * Highlighting comes from the engine's `_formatted.body` when present
     * (Meilisearch) and from a driver-agnostic snippet helper otherwise; either
     * way the snippet is keyed by message id so it survives the re-query.
     *
     * @return Collection<int, MessageSearchHit>
     */
    public function handle(User $user, Team $team, MessageSearchCriteria $criteria, int $limit = self::RESULT_LIMIT): Collection
    {
        $query = trim($criteria->query);

        $channelIds = $this->visibleChannelIds($user, $team, $criteria->scope);

        if ($query === '' || $channelIds === []) {
            return new Collection;
        }

        $formattedById = $this->candidateHits($query, $channelIds, $criteria);

        if ($formattedById === []) {
            return new Collection;
        }

        $messages = $this->hydrate(array_keys($formattedById), $channelIds, $criteria, $limit);

        return $messages
            ->map(fn (Message $message): MessageSearchHit => new MessageSearchHit(
                message: $message,
                snippet: $this->snippet($message, $query, $formattedById[$message->id] ?? null),
            ))
            ->values();
    }

    /**
     * The visible channel ids that form the whole ACL for this search, scoped to
     * the current team or unioned across every team the user belongs to.
     *
     * @return array<int, string>
     */
    private function visibleChannelIds(User $user, Team $team, SearchScope $scope): array
    {
        $ids = $scope === SearchScope::All
            ? $user->visibleChannelIdsAcrossTeams()
            : $user->visibleChannelIds($team);

        return $ids->all();
    }

    /**
     * The engine's relevance-ranked candidate window as an id => formatted-body
     * map. The formatted body is the engine's highlighted `_formatted.body`
     * (Meilisearch) or null for drivers that return none.
     *
     * @param  array<int, string>  $channelIds
     * @return array<string, string|null>
     */
    private function candidateHits(string $query, array $channelIds, MessageSearchCriteria $criteria): array
    {
        $builder = Message::search($query)
            ->whereIn('channel_id', $channelIds)
            ->when($criteria->authorId !== null, fn ($search) => $search->where('user_id', $criteria->authorId))
            ->options([
                'attributesToHighlight' => ['body'],
                'attributesToCrop' => ['body'],
                'cropLength' => 30,
                'highlightPreTag' => MessageSnippet::HIGHLIGHT_PRE_TAG,
                'highlightPostTag' => MessageSnippet::HIGHLIGHT_POST_TAG,
            ])
            ->take(self::CANDIDATE_WINDOW);

        // Meilisearch can narrow the candidate window by the indexed `created_at`
        // timestamp; the collection driver compares the raw datetime column, so
        // date bounds are enforced authoritatively in {@see hydrate()} for every
        // driver and only pre-filtered on the engine where it is safe.
        if ($this->engineFiltersTimestamps()) {
            $builder
                ->when($criteria->after instanceof CarbonInterface, fn ($search) => $search->where('created_at', '>=', $criteria->after?->getTimestamp()))
                ->when($criteria->before instanceof CarbonInterface, fn ($search) => $search->where('created_at', '<=', $criteria->before?->getTimestamp()));
        }

        return $this->normalizeHits($builder->raw());
    }

    /**
     * Re-query the candidate ids in Eloquent as the authoritative pass: re-assert
     * the channel ACL, apply the author, channel, and date facets, order by
     * recency, and trim to the page. Relations are eager-loaded for rendering.
     *
     * @param  array<int, string>  $candidateIds
     * @param  array<int, string>  $channelIds
     * @return Collection<int, Message>
     */
    private function hydrate(array $candidateIds, array $channelIds, MessageSearchCriteria $criteria, int $limit): Collection
    {
        $messages = Message::query()
            ->whereKey($candidateIds)
            ->whereIn('channel_id', $channelIds)
            ->when($criteria->channelId !== null, fn (Builder $query) => $query->where('channel_id', $criteria->channelId))
            ->when($criteria->authorId !== null, fn (Builder $query) => $query->where('user_id', $criteria->authorId))
            ->when($criteria->after instanceof CarbonInterface, fn (Builder $query) => $query->where('created_at', '>=', $criteria->after))
            ->when($criteria->before instanceof CarbonInterface, fn (Builder $query) => $query->where('created_at', '<=', $criteria->before))
            ->latest()
            ->limit($limit)
            ->get();

        return Message::loadMessageDataRelationsInto($messages)->load('channel.team');
    }

    /**
     * Normalize a raw engine response into an ordered id => formatted-body map.
     *
     * Meilisearch returns a `hits` array whose entries carry `_formatted`; the
     * collection driver returns hydrated models under `results` with no
     * highlighting. The response is typed `mixed`, so every shape is guarded
     * before it is read. Keying by id lets the caller pair each candidate with its
     * snippet after the Eloquent re-query.
     *
     * @return array<string, string|null>
     */
    private function normalizeHits(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $hits = [];

        if (isset($raw['hits']) && is_array($raw['hits'])) {
            foreach ($raw['hits'] as $hit) {
                if (is_array($hit) && is_scalar($hit['id'] ?? null)) {
                    $formatted = $hit['_formatted']['body'] ?? null;
                    $hits[(string) $hit['id']] = is_string($formatted) ? $formatted : null;
                }
            }

            return $hits;
        }

        $results = $raw['results'] ?? [];

        if (is_iterable($results)) {
            foreach ($results as $message) {
                if ($message instanceof Message) {
                    $hits[(string) $message->getScoutKey()] = null;
                }
            }
        }

        return $hits;
    }

    /**
     * Build the highlighted snippet for a match, preferring the engine's
     * `_formatted.body` when present.
     */
    private function snippet(Message $message, string $query, ?string $formatted): string
    {
        return $formatted !== null
            ? MessageSnippet::fromFormatted($formatted)
            : MessageSnippet::highlight(MessagePlainText::from($message->body), $query);
    }

    /**
     * Whether the active engine indexes `created_at` as a filterable timestamp,
     * so date bounds can be pre-filtered engine-side. Only Meilisearch does; the
     * collection driver runs its wheres against the raw datetime column.
     */
    private function engineFiltersTimestamps(): bool
    {
        return config('scout.driver') === 'meilisearch';
    }
}
