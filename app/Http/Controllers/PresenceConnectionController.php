<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\UserPresenceChanged;
use App\Http\Requests\ReleasePresenceRequest;
use App\Http\Requests\ReportPresenceRequest;
use App\Models\User;
use App\Support\PresenceRegistry;
use Illuminate\Http\Response;

/**
 * The client-side idle detector's two writes: a tab saying how active it is, and
 * a tab saying goodbye.
 *
 * Both are deliberately quiet — nothing is broadcast unless the *aggregate* the
 * user's teammates see actually moves. A second device going idle while the
 * first is still in use changes nothing, and so costs nothing.
 */
class PresenceConnectionController extends Controller
{
    public function __construct(private readonly PresenceRegistry $registry) {}

    /**
     * Record how active one of the user's tabs is.
     *
     * Called on every idle↔active transition and, far more rarely, as a slow
     * heartbeat so a crashed tab eventually ages out of the index.
     */
    public function store(ReportPresenceRequest $request): Response
    {
        $user = $request->user();

        $this->broadcastIfChanged($user, function () use ($user, $request): void {
            $this->registry->record($user->id, $request->connection(), $request->state());
        });

        return response()->noContent();
    }

    /**
     * Drop a tab from the index as it closes.
     *
     * Without this, closing the one tab you were active in would leave its
     * "active" entry standing until the TTL lapsed, so a second, idle tab would
     * keep showing you as active — the case where being wrong matters most.
     */
    public function destroy(ReleasePresenceRequest $request): Response
    {
        $user = $request->user();

        $this->broadcastIfChanged($user, function () use ($user, $request): void {
            $this->registry->forget($user->id, $request->connection());
        });

        return response()->noContent();
    }

    /**
     * Apply a change to the index, announcing it only if teammates would now see
     * the user differently.
     *
     * A manual away pins the effective state, so a tab reporting under one is
     * recorded (it still matters the moment the override is cleared) but never
     * broadcast.
     */
    private function broadcastIfChanged(User $user, callable $apply): void
    {
        $before = $user->effectivePresence();

        $apply();

        $after = $user->effectivePresence();

        if ($after !== $before) {
            event(new UserPresenceChanged($user, $after));
        }
    }
}
