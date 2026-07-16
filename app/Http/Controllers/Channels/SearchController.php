<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\SearchMessages;
use App\Data\MessageSearchCriteria;
use App\Data\MessageSearchHit;
use App\Data\MessageSearchResultData;
use App\Enums\SearchScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\SearchMessagesRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    /**
     * The number of message matches surfaced inline in the quick switcher, kept
     * small so the palette stays a preview; the full page shows the rest.
     */
    private const int SUGGEST_LIMIT = 5;

    /**
     * Search messages in the current team, scoped to the user's channels.
     *
     * The ACL-filtered, faceted query lives in the SearchMessages action; the
     * controller only resolves the facets from the request and shapes the matches
     * for the client. The applied facets echo back so the page round-trips them
     * as URL state. An empty query renders the page with no results.
     */
    public function index(SearchMessagesRequest $request, Team $team, SearchMessages $searchMessages): Response
    {
        $criteria = $this->criteria($request);

        return Inertia::render('channels/Search', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'query' => $criteria->query,
            'filters' => [
                'from' => $criteria->authorId,
                'in' => $criteria->channelId,
                'after' => $request->validated('after'),
                'before' => $request->validated('before'),
                'scope' => $criteria->scope->value,
            ],
            'results' => $this->results($request->user(), $team, $criteria, $searchMessages),
        ]);
    }

    /**
     * A JSON preview of the top message matches for the quick switcher.
     *
     * Shares the ACL-filtered, faceted SearchMessages action with
     * {@see self::index()}, capped at {@see self::SUGGEST_LIMIT} so the palette
     * shows only a handful of hits; users open the full page for the complete
     * result set.
     */
    public function suggest(SearchMessagesRequest $request, Team $team, SearchMessages $searchMessages): JsonResponse
    {
        $criteria = $this->criteria($request);

        return response()->json([
            'results' => $this->results($request->user(), $team, $criteria, $searchMessages, self::SUGGEST_LIMIT),
        ]);
    }

    /**
     * Resolve the validated request into the search criteria.
     *
     * The date facets are widened to whole-day bounds — `after` from the start of
     * its day, `before` to the end — so a single-day range is inclusive of every
     * message posted on it.
     */
    private function criteria(SearchMessagesRequest $request): MessageSearchCriteria
    {
        return new MessageSearchCriteria(
            query: trim((string) $request->validated('q')),
            authorId: $request->validated('from'),
            channelId: $request->validated('in'),
            after: $request->date('after')?->startOfDay(),
            before: $request->date('before')?->endOfDay(),
            scope: SearchScope::tryFrom((string) $request->validated('scope')) ?? SearchScope::default(),
        );
    }

    /**
     * Run the search and shape the hits for the client.
     *
     * @return array<int, MessageSearchResultData>
     */
    private function results(User $user, Team $team, MessageSearchCriteria $criteria, SearchMessages $searchMessages, int $limit = SearchMessages::RESULT_LIMIT): array
    {
        return $searchMessages->handle($user, $team, $criteria, $limit)
            ->map(fn (MessageSearchHit $hit): MessageSearchResultData => MessageSearchResultData::fromHit($hit))
            ->all();
    }
}
