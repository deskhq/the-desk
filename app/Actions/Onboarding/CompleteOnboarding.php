<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\User;

class CompleteOnboarding
{
    /**
     * Mark the user's first-run onboarding tour as complete.
     *
     * Idempotent: an existing completion timestamp is never overwritten, so
     * replaying the tour and finishing again keeps the original date.
     */
    public function handle(User $user): void
    {
        if ($user->onboarding_completed_at !== null) {
            return;
        }

        $user->update(['onboarding_completed_at' => now()]);
    }
}
