<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ViewSecurityLogRequest;
use App\Models\Team;
use App\Support\SecurityLog;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityLogController extends Controller
{
    /**
     * Show the workspace's security log, newest first, filterable by type and
     * actor.
     */
    public function index(ViewSecurityLogRequest $request, Team $team): Response
    {
        /** @var string|null $type */
        $type = $request->validated('type');
        /** @var string|null $actor */
        $actor = $request->validated('actor');

        $log = new SecurityLog($team, $type, $actor);

        return Inertia::render('teams/SecurityLog', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'events' => $log->events(),
            'filters' => [
                'type' => $type,
                'actor' => $actor,
            ],
            'typeOptions' => SecurityEventType::options(),
            'actors' => $log->actors(),
        ]);
    }
}
