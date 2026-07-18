<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Enums\UserType;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a bot identity for a team and records it in the workspace audit log.
 *
 * A bot is a {@see UserType::Bot} user scoped to the team via `owner_team_id`
 * with no login password and a synthetic, non-routable email (bots never sign in
 * or receive mail). It is not attached to any channel here — posting stays
 * membership-gated, so an operator adds the bot to channels separately.
 */
class CreateBot
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(Team $team, User $actor, string $name): User
    {
        return DB::transaction(function () use ($team, $actor, $name): User {
            $bot = new User;

            $bot->forceFill([
                'name' => $name,
                'email' => sprintf('bot-%s@bots.invalid', Str::uuid()),
                'password' => null,
                'type' => UserType::Bot,
                'owner_team_id' => $team->id,
                'created_by' => $actor->id,
            ])->save();

            $this->recorder->record($team, $actor, AuditAction::BotCreated, $bot, [
                'bot_name' => $name,
            ]);

            return $bot;
        });
    }
}
