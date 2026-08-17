<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeamInvitation;
use App\Actions\Teams\ResendTeamInvitation;
use App\Actions\Teams\RevokeTeamInvitation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Support\WorkspaceRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

final class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, CreateTeamInvitation $createInvitation): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $createInvitation->handle(
            $team,
            $this->viewer($request),
            (string) $request->validated('email'),
            TeamRole::from($request->validated('role')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Request $request, Team $team, TeamInvitation $invitation, RevokeTeamInvitation $revokeInvitation): RedirectResponse
    {
        Gate::authorize('cancelInvitation', $team);

        $revokeInvitation->handle($invitation, $this->viewer($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Resend the specified pending invitation, refreshing its expiry.
     *
     * The throttle is a property of this surface — a person clicking the button
     * again — so it stays here rather than in the Action.
     */
    public function resend(Request $request, Team $team, TeamInvitation $invitation, ResendTeamInvitation $resendInvitation): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        abort_if($invitation->isAccepted(), 404);

        $throttleKey = 'resend-invitation:'.$invitation->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Please wait a moment before resending this invitation')]);

            return to_route('teams.edit', ['team' => $team->slug]);
        }

        RateLimiter::hit($throttleKey, 60);

        $resendInvitation->handle($invitation, $this->viewer($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitation $acceptInvitation): RedirectResponse
    {
        $acceptInvitation->handle($invitation, $this->viewer($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted')]);

        return to_route('channels.index', ['team' => $invitation->team->slug]);
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined')]);

        // Back to whichever workspace they were standing in, or the workspace
        // list when the declined invitation was their only way into one.
        return redirect()->to(WorkspaceRedirect::pathFor($this->viewer($request)) ?? route('teams.index'));
    }
}
