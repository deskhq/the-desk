<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Models\CustomEmoji;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\Storage;

class RevokeCustomEmoji
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * Remove a custom emoji and its image.
     *
     * Deleting the row frees the name for reuse and makes existing `:name:`
     * tokens in messages and reactions fall back to plain text. When an admin
     * revokes someone else's emoji (the actor is not the uploader) the action is
     * recorded to the workspace audit log; deleting your own upload is not.
     */
    public function handle(Team $team, User $actor, CustomEmoji $emoji): void
    {
        if ($emoji->created_by !== $actor->id) {
            $this->recorder->record($team, $actor, AuditAction::EmojiRevoked, context: [
                'emoji_name' => $emoji->name,
            ]);
        }

        Storage::disk(CustomEmoji::DISK)->delete($emoji->path);

        $emoji->delete();
    }
}
