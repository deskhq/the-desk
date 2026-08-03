<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ViewAuditLogRequest;
use App\Models\Team;
use App\Support\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /**
     * Show the workspace's audit log, newest first, filterable by action and actor.
     */
    public function index(ViewAuditLogRequest $request, Team $team): Response
    {
        /** @var string|null $action */
        $action = $request->validated('action');
        /** @var string|null $actor */
        $actor = $request->validated('actor');

        $log = new AuditLog($team, $action, $actor);

        return Inertia::render('teams/Audit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'entries' => $log->entries(),
            'filters' => [
                'action' => $action,
                'actor' => $actor,
            ],
            'actionOptions' => AuditAction::options(),
            'actors' => $log->actors(),
        ]);
    }
}
