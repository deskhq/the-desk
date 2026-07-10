<?php

namespace App\Http\Controllers\Teams;

use App\Data\AuditEventData;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ViewAuditLogRequest;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /**
     * The number of audit entries shown per page.
     */
    private const int PER_PAGE = 30;

    /**
     * Show the workspace's audit log, newest first, filterable by action and actor.
     */
    public function index(ViewAuditLogRequest $request, Team $team): Response
    {
        $action = $request->validated('action');
        $actorId = $request->validated('actor');

        $entries = AuditActivity::query()
            ->where('team_id', $team->id)
            ->when($action, fn ($query) => $query->where('event', $action))
            ->when($actorId, fn ($query) => $query->where('causer_id', $actorId))
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('teams/Audit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'entries' => [
                'data' => collect($entries->items())
                    ->map(fn (AuditActivity $entry): AuditEventData => AuditEventData::fromActivity($entry))
                    ->all(),
                'prevPageUrl' => $entries->previousPageUrl(),
                'nextPageUrl' => $entries->nextPageUrl(),
            ],
            'filters' => [
                'action' => $action,
                'actor' => $actorId,
            ],
            'actionOptions' => AuditAction::options(),
            'actors' => $this->actors($team),
        ]);
    }

    /**
     * List the distinct actors who appear in the team's audit log, for the
     * actor filter dropdown.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function actors(Team $team): array
    {
        $actorIds = AuditActivity::query()
            ->where('team_id', $team->id)
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id');

        return User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
