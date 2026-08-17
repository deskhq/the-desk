<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Data\ChannelContentSummaryData;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

final class ChannelDeletionSummaryController extends Controller
{
    /**
     * Report what deleting the channel would destroy.
     *
     * Its own endpoint, fetched when the confirmation dialog opens, rather than a
     * prop on the channel view: the counts are three aggregates that only an
     * admin about to delete the channel ever reads, and the channel view is the
     * hottest page in the app. Gated on the delete ability, so the counts are only
     * legible to someone who could already act on them.
     */
    public function show(Team $team, Channel $channel): ChannelContentSummaryData
    {
        Gate::authorize('delete', $channel);

        return ChannelContentSummaryData::forChannel($channel);
    }
}
