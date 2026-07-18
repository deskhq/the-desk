<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a bot and records the deletion in the workspace audit log.
 *
 * The bot's authored messages (and any pins it created) are reassigned to the
 * retained "Deleted User" tombstone so channel history stays coherent — the same
 * anonymize-not-remove policy applied to humans (see {@see App\Support\AccountDeleter}).
 * Deleting the bot row then cascades its API tokens, incoming webhooks, and
 * channel memberships away, so no credential or posting path survives it.
 */
class DeleteBot
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(User $actor, User $bot): void
    {
        $team = $bot->ownerTeam()->firstOrFail();
        $name = $bot->name;

        DB::transaction(function () use ($bot): void {
            $tombstone = User::tombstone();

            Message::withTrashed()
                ->where('user_id', $bot->id)
                ->update(['user_id' => $tombstone->id]);

            MessagePin::where('pinned_by', $bot->id)
                ->update(['pinned_by' => $tombstone->id]);

            $bot->delete();
        });

        $this->recorder->record($team, $actor, AuditAction::BotDeleted, $bot, [
            'bot_name' => $name,
        ]);
    }
}
