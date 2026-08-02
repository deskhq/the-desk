<?php

namespace App\Concerns;

use App\Models\Team;
use App\Models\UserGroup;

/**
 * Resolves the `{team}` and `{userGroup}` route bindings for the user-group
 * management requests.
 *
 * Neither accessor re-asks whether the group belongs to the team in the URL:
 * the route binds `{userGroup}` through `Team::userGroups()`, so a group from
 * another workspace is already a 404 by the time a request is validated
 * (ADR-0014).
 */
trait ResolvesUserGroupRoute
{
    /**
     * Get the team in the URL.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }

    /**
     * Get the group in the URL.
     */
    public function group(): UserGroup
    {
        $group = $this->route('userGroup');

        abort_if(! $group instanceof UserGroup, 404);

        return $group;
    }
}
