<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\SearchMessages;
use App\Data\MessageSearchCriteria;
use App\Data\MessageSearchHit;
use App\Data\MessageSearchResultData;
use App\Enums\NavDestination;
use App\Enums\SearchScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\SearchMessagesRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SearchController extends Controller
{
    /**
     * The number of message matches surfaced inline in the quick switcher, kept
     * small so the palette stays a preview; the panel shows the rest.
     */
    private const int SUGGEST_LIMIT = 5;

    /**
     * The facets a legacy search link carries, forwarded onto the destination so
     * a shared URL still reproduces its filtered view (#391).
     */
    private const array FORWARDED_FACETS = ['q', 'from', 'in', 'after', 'before', 'scope'];

    /**
     * Send the legacy full-width search page onto the pinned destination.
     *
     * Search is a dock panel now (`?nav=search` on a workspace route), so the old
     * URL survives only as a redirect. The hop goes through the bare team URL,
     * which already forwards its query string on to #general; the route params
     * and the destination are spread last so a crafted facet cannot steer either.
     */
    public function index(Request $request, Team $team): RedirectResponse
    {
        return to_route('channels.index', [
            ...Arr::only($request->query(), self::FORWARDED_FACETS),
            'team' => $team->slug,
            NavDestination::QUERY_PARAM => NavDestination::Search->value,
        ]);
    }

    /**
     * A JSON preview of the top message matches for the quick switcher.
     *
     * Shares the ACL-filtered, faceted SearchMessages action with the panel, capped
     * at {@see self::SUGGEST_LIMIT} so the palette shows only a handful of hits;
     * users open the search destination for the complete result set.
     */
    public function suggest(SearchMessagesRequest $request, Team $team, SearchMessages $searchMessages): JsonResponse
    {
        $criteria = new MessageSearchCriteria(
            query: trim((string) $request->validated('q')),
            authorId: $request->validated('from'),
            channelId: $request->validated('in'),
            after: $request->date('after')?->startOfDay(),
            before: $request->date('before')?->endOfDay(),
            scope: SearchScope::tryFrom((string) $request->validated('scope')) ?? SearchScope::default(),
        );

        $user = $request->user();

        return response()->json([
            'results' => $searchMessages->handle($user, $team, $criteria, self::SUGGEST_LIMIT)
                ->map(fn (MessageSearchHit $hit): MessageSearchResultData => MessageSearchResultData::fromHit($hit, $user))
                ->all(),
        ]);
    }
}
