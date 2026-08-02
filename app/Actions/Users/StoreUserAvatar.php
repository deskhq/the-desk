<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;
use App\Support\Avatars\AvatarStorage;
use Illuminate\Http\UploadedFile;

/**
 * Replace a user's uploaded avatar with a newly uploaded one.
 *
 * The order is the rule: process and store the new blob, point the user at its
 * cacheable URL, and only then delete the previous blob, so a replacement
 * leaves no orphan and never strands the row on a file that is already gone.
 * The broadcast lets every other open client swap the image live.
 */
class StoreUserAvatar
{
    public function __construct(private readonly AvatarStorage $storage) {}

    public function handle(User $user, UploadedFile $photo): void
    {
        $previous = $user->avatar_path;

        ['url' => $url, 'path' => $path] = $this->storage->store($photo);

        $user->forceFill(['avatar_url' => $url, 'avatar_path' => $path])->save();

        $this->storage->delete($previous);

        event(new UserProfileUpdated($user));
    }
}
