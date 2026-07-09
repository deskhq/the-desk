<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\SearchMessages;
use App\Data\MessageSearchResultData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\SearchMessagesRequest;
use App\Models\Message;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
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
}
