<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AnalyticsRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ViewAnalyticsRequest;
use App\Models\Team;
use App\Support\TeamStorage;
use App\Support\WorkspaceAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(private readonly WorkspaceAnalytics $analytics, private readonly TeamStorage $storage) {}

    /**
     * Show the workspace analytics dashboard for the selected time window.
     *
     * The storage read-out rides alongside the cached analytics snapshot rather
     * than inside it: usage moves with every upload and delete, so it is read
     * fresh on each view. It is null while no quota is configured.
     */
    public function index(ViewAnalyticsRequest $request, Team $team): Response
    {
        $range = $request->range();

        return Inertia::render('teams/Analytics', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'analytics' => $this->analytics->for($team, $range),
            'storage' => $this->storage->usage($team),
            'range' => $range->value,
            'rangeOptions' => AnalyticsRange::options(),
        ]);
    }
}
