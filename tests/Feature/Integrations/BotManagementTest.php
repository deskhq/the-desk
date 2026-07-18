<?php

declare(strict_types=1);

use App\Actions\Integrations\CreateBot;
use App\Actions\Integrations\DeleteBot;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Enums\UserType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

it('creates a team-scoped bot with no password and a synthetic email, and audits it', function (): void {
    $bot = app(CreateBot::class)->handle($this->team, $this->owner, 'Deploy Bot');

    expect($bot->isBot())->toBeTrue()
        ->and($bot->type)->toBe(UserType::Bot)
        ->and($bot->owner_team_id)->toBe($this->team->id)
        ->and($bot->created_by)->toBe($this->owner->id)
        ->and($bot->password)->toBeNull()
        ->and($bot->email)->toEndWith('@bots.invalid')
        ->and($bot->creator->is($this->owner))->toBeTrue();

    // A bot is not a team member (kept out of seat counts and rosters).
    expect($this->team->members()->whereKey($bot->id)->exists())->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::BotCreated->value,
        'causer_id' => $this->owner->id,
    ]);
});

it('gives each bot a unique email', function (): void {
    $first = app(CreateBot::class)->handle($this->team, $this->owner, 'Bot One');
    $second = app(CreateBot::class)->handle($this->team, $this->owner, 'Bot Two');

    expect($first->email)->not->toBe($second->email);
});

it('deletes a bot, reassigns its messages to the tombstone, and audits it', function (): void {
    $bot = app(CreateBot::class)->handle($this->team, $this->owner, 'Deploy Bot');
    $channel = Channel::factory()->for($this->team)->create();
    $message = Message::factory()->for($channel)->for($bot)->create();

    app(DeleteBot::class)->handle($this->owner, $bot);

    $this->assertDatabaseMissing('users', ['id' => $bot->id]);

    // The bot's message survives, reassigned to the retained tombstone account.
    expect($message->fresh()->user_id)->toBe(User::tombstone()->id);

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $this->team->id,
        'event' => AuditAction::BotDeleted->value,
        'causer_id' => $this->owner->id,
    ]);
});
