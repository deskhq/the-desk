<?php

namespace App\Http\Controllers\Teams;

use App\Data\TeamSecurityEventData;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ViewSecurityLogRequest;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Inertia\Inertia;
use Inertia\Response;

class SecurityLogController extends Controller
{
    /**
     * The number of security events shown per page.
     */
    private const int PER_PAGE = 30;

    /**
     * Show the workspace's security log, newest first, filterable by type and
     * actor.
     *
     * Events are account-level and carry no team scope, so the log is a live
     * join to the team's current membership: an event surfaces only while its
     * user is a member. Removing a member immediately drops their events here.
     */
    public function index(ViewSecurityLogRequest $request, Team $team): Response
    {
        $type = $request->validated('type');
        $actorId = $request->validated('actor');

        $events = SecurityEvent::query()
            ->whereIn('user_id', $this->memberIds($team))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($actorId, fn ($query) => $query->where('user_id', $actorId))
            ->with('user')
            ->latest()
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('teams/SecurityLog', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'events' => [
                'data' => collect($events->items())
                    ->map(fn (SecurityEvent $event): TeamSecurityEventData => TeamSecurityEventData::fromEvent($event))
                    ->all(),
                'prevPageUrl' => $events->previousPageUrl(),
                'nextPageUrl' => $events->nextPageUrl(),
            ],
            'filters' => [
                'type' => $type,
                'actor' => $actorId,
            ],
            'typeOptions' => SecurityEventType::options(),
            'actors' => $this->actors($team),
        ]);
    }

    /**
     * A subquery selecting the ids of the team's current members, used to scope
     * the account-level events to the workspace via a live membership join.
     */
    private function memberIds(Team $team): Builder
    {
        return $team->members()->getQuery()->select('users.id');
    }

    /**
     * List the current members who appear in the team's security log, for the
     * actor filter dropdown.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function actors(Team $team): array
    {
        $actorIds = SecurityEvent::query()
            ->whereIn('user_id', $this->memberIds($team))
            ->distinct()
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
