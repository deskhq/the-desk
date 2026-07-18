<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\CreateGiphyAttachment;
use App\Data\AttachmentData;
use App\Data\GiphyGifData;
use App\Data\GiphySearchData;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGiphyEnabled;
use App\Http\Requests\Channels\GifSearchRequest;
use App\Http\Requests\Channels\StoreGifRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Support\GiphyClient;
use Illuminate\Http\JsonResponse;

class GiphyController extends Controller
{
    /**
     * Proxy a Giphy trending/search page for the composer picker.
     *
     * The API key stays server-side; the endpoint is throttled per user and the
     * client's app locale is passed through for localized results. Authorized by
     * the post-message policy (see {@see GifSearchRequest}) and reachable only
     * when Giphy is configured (see {@see EnsureGiphyEnabled}).
     */
    public function search(GifSearchRequest $request, Team $team, Channel $channel, GiphyClient $giphy): JsonResponse
    {
        $page = $giphy->search(
            query: $request->validated('q'),
            offset: (int) $request->validated('offset', 0),
            limit: (int) config('services.giphy.page_size'),
            lang: $request->user()->locale->value,
        );

        return response()->json(GiphySearchData::from($page));
    }

    /**
     * Attach a chosen GIF to a draft as a pending remote attachment.
     *
     * The client sends only the opaque Giphy id; the server re-resolves it (the
     * sole authority on the stored URL) and creates a pending `source=giphy`
     * attachment owned by the sender and channel. The subsequent send claims it
     * through the ordinary `attachment_ids[]` flow. An id Giphy no longer knows
     * is rejected.
     */
    public function store(StoreGifRequest $request, Team $team, Channel $channel, GiphyClient $giphy, CreateGiphyAttachment $createGiphyAttachment): JsonResponse
    {
        $gif = $giphy->resolve($request->validated('id'));

        abort_if(! $gif instanceof GiphyGifData, 422, __('That GIF is no longer available.'));

        $attachment = $createGiphyAttachment->handle($channel, $request->user(), $gif);

        return response()->json(AttachmentData::fromAttachment($attachment), 201);
    }
}
