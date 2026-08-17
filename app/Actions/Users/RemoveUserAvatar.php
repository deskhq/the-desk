<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;
use App\Support\Avatars\AvatarStorage;

/**
 * Remove a user's uploaded avatar, reverting to the Gravatar → initials
 * fallback, and clean up its stored blob.
 */
final readonly class RemoveUserAvatar
{
    public function __construct(private AvatarStorage $storage) {}

    public function handle(User $user): void
    {
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_url' => null, 'avatar_path' => null])->save();

        $this->storage->delete($previous);

        event(new UserProfileUpdated($user));
    }
}
