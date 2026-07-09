<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\SearchMessages;
use App\Data\MessageSearchResultData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\SearchMessagesRequest;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    /**
     * The number of message matches surfaced inline in the quick switcher, kept
     * small so the palette stays a preview; the full page shows the rest.
     */
    private const SUGGEST_LIMIT = 5;

    /**
     * Search messages in the current team, scoped to the user's channels.
     *
     * The ACL-filtered query lives in the SearchMessages action; the controller
     * only shapes the matches for the client. An empty query renders the page
     * with no results.
     */
    public function index(SearchMessagesRequest $request, Team $team, SearchMessages $searchMessages): Response
    {
        $query = trim((string) $request->validated('q'));

        $results = $searchMessages->handle($request->user(), $team, $query)
            ->map(fn (Message $message) => MessageSearchResultData::fromMessage($message))
            ->all();

        return Inertia::render('channels/Search', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'query' => $query,
            'results' => $results,
        ]);
    }

    /**
     * A JSON preview of the top message matches for the quick switcher.
     *
     * Shares the ACL-filtered SearchMessages action with {@see self::index()},
     * capped at {@see self::SUGGEST_LIMIT} so the palette shows only a handful
     * of hits; users open the full page for the complete result set.
     */
    public function suggest(SearchMessagesRequest $request, Team $team, SearchMessages $searchMessages): JsonResponse
    {
        $query = trim((string) $request->validated('q'));

        $results = $searchMessages->handle($request->user(), $team, $query, self::SUGGEST_LIMIT)
            ->map(fn (Message $message) => MessageSearchResultData::fromMessage($message))
            ->all();

        return response()->json(['results' => $results]);
    }
}
