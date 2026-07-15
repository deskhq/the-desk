<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, AuditRecorder $recorder): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $role = TeamRole::from($request->validated('role'));

        $invitation = $team->invitations()->create([
            'email' => $request->validated('email'),
            'role' => $role,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        $recorder->record($team, $request->user(), AuditAction::InvitationCreated, $invitation, [
            'email' => $invitation->email,
            'role' => $role->label(),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Request $request, Team $team, TeamInvitation $invitation, AuditRecorder $recorder): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $recorder->record($team, $request->user(), AuditAction::InvitationRevoked, $invitation, [
            'email' => $invitation->email,
        ]);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Resend the specified pending invitation, refreshing its expiry.
     */
    public function resend(Request $request, Team $team, TeamInvitation $invitation, AuditRecorder $recorder): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('inviteMember', $team);

        abort_if($invitation->isAccepted(), 404);

        $throttleKey = 'resend-invitation:'.$invitation->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Please wait a moment before resending this invitation.')]);

            return to_route('teams.edit', ['team' => $team->slug]);
        }

        RateLimiter::hit($throttleKey, 60);

        $invitation->update(['expires_at' => now()->addDays(3)]);

        $recorder->record($team, $request->user(), AuditAction::InvitationResent, $invitation, [
            'email' => $invitation->email,
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, AuditRecorder $recorder): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $invitation): void {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        $recorder->record($invitation->team, $user, AuditAction::InvitationAccepted, $invitation, [
            'email' => $invitation->email,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('channels.index', ['team' => $invitation->team->slug]);
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('channels.index', ['team' => $request->user()->currentTeam->slug]);
    }
}
