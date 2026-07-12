<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\CustomEmoji;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class CreateCustomEmoji
{
    /**
     * Store the uploaded image on the configured disk and register the emoji.
     *
     * The file lands under a per-team prefix so a workspace's emoji are grouped;
     * the row records who uploaded it so "delete your own" and the registry's
     * "added by" both resolve.
     */
    public function handle(Team $team, User $creator, string $name, UploadedFile $image): CustomEmoji
    {
        $path = $image->store("custom-emoji/{$team->id}", CustomEmoji::DISK);

        return $team->customEmojis()->create([
            'created_by' => $creator->id,
            'name' => $name,
            'path' => $path,
        ]);
    }
}
