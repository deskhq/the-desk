<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AnalyticsRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ViewAnalyticsRequest;
use App\Models\Team;
use App\Support\WorkspaceAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(private WorkspaceAnalytics $analytics) {}

    /**
     * Show the workspace analytics dashboard for the selected time window.
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
            'range' => $range->value,
            'rangeOptions' => AnalyticsRange::options(),
        ]);
    }
}
