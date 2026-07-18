<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('creates a bot with no personal team, no membership pivot, and no password', function (): void {
    $team = Team::factory()->create();

    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);

    expect($bot->type)->toBe(UserType::Bot)
        ->and($bot->isBot())->toBeTrue()
        ->and($bot->isHuman())->toBeFalse()
        ->and($bot->password)->toBeNull()
        ->and($bot->owner_team_id)->toBe($team->id)
        ->and($bot->ownerTeam->is($team))->toBeTrue()
        ->and($bot->teams()->count())->toBe(0)
        ->and($bot->personalTeam())->toBeNull();
});

it('defaults a human user to the human type with the usual personal team', function (): void {
    $user = User::factory()->create();

    expect($user->type)->toBe(UserType::Human)
        ->and($user->isHuman())->toBeTrue()
        ->and($user->isBot())->toBeFalse()
        ->and($user->owner_team_id)->toBeNull()
        ->and($user->personalTeam())->not->toBeNull();
});

it('lets a bot post only to channels it is a member of', function (): void {
    $team = Team::factory()->create();
    $memberChannel = Channel::factory()->for($team)->create();
    $otherChannel = Channel::factory()->for($team)->create();

    $bot = User::factory()->bot($team)->create();
    $memberChannel->channelMembers()->create(['user_id' => $bot->id]);

    expect(Gate::forUser($bot)->allows('postMessage', $memberChannel))->toBeTrue()
        ->and(Gate::forUser($bot)->allows('postMessage', $otherChannel))->toBeFalse();
});
