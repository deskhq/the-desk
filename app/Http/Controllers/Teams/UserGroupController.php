<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Data\UserData;
use App\Data\UserGroupData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\StoreUserGroupMemberRequest;
use App\Http\Requests\Teams\StoreUserGroupRequest;
use App\Http\Requests\Teams\UpdateUserGroupRequest;
use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class UserGroupController extends Controller
{
    /**
     * Show the workspace's mentionable user groups.
     */
    public function index(Request $request, Team $team): Response
    {
        Gate::authorize('viewAny', [UserGroup::class, $team]);

        return Inertia::render('teams/Groups', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'groups' => UserGroupData::forTeam($team),
            // The workspace roster backs the member picker; no separate
            // autocomplete endpoint is needed at this scale.
            'members' => UserData::collect($team->members()->orderBy('name')->get()),
            'permissions' => [
                'canManageUserGroups' => $this->viewer($request)->toTeamPermissions($team)->canManageUserGroups,
            ],
        ]);
    }

    /**
     * Create a group. It starts empty; members are added afterwards.
     */
    public function store(StoreUserGroupRequest $request, Team $team): RedirectResponse
    {
        $team->userGroups()->create($request->safe()->only(['name', 'slug']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created')]);

        return back();
    }

    /**
     * Rename a group or change its handle.
     *
     * Existing messages keep the label baked into their token at post time, so a
     * rename never rewrites history — old bodies still read as they were sent.
     */
    public function update(UpdateUserGroupRequest $request, Team $team, UserGroup $userGroup): RedirectResponse
    {
        $userGroup->update($request->safe()->only(['name', 'slug']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group updated')]);

        return back();
    }

    /**
     * Delete a group. Its `@handle` tokens in existing messages fall back to
     * plain text, and the mention rows they already fanned out to stay put.
     */
    public function destroy(Team $team, UserGroup $userGroup): RedirectResponse
    {
        Gate::authorize('delete', $userGroup);

        $userGroup->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted')]);

        return back();
    }

    /**
     * Add a workspace member to a group.
     */
    public function storeMember(StoreUserGroupMemberRequest $request, Team $team, UserGroup $userGroup): RedirectResponse
    {
        // `syncWithoutDetaching` keeps a repeated add idempotent rather than
        // tripping the pivot's unique constraint.
        $userGroup->members()->syncWithoutDetaching([$request->validated('user_id')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member added to the group')]);

        return back();
    }

    /**
     * Remove a member from a group.
     */
    public function destroyMember(Team $team, UserGroup $userGroup, User $member): RedirectResponse
    {
        Gate::authorize('update', $userGroup);

        $userGroup->members()->detach($member->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed from the group')]);

        return back();
    }
}
