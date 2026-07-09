<?php

namespace App\Policies;

use App\Models\ChannelSection;
use App\Models\User;

class ChannelSectionPolicy
{
    /**
     * Determine whether the user can update (rename or collapse) the section.
     *
     * Sections are private to the user who created them, so only the owner may
     * touch one.
     */
    public function update(User $user, ChannelSection $section): bool
    {
        return $section->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the section.
     */
    public function delete(User $user, ChannelSection $section): bool
    {
        return $section->user_id === $user->id;
    }
}
