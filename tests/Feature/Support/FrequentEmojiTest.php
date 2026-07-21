<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\CustomEmoji;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Team;
use App\Models\User;
use App\Support\FrequentEmoji;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A fresh owner with their own workspace and its default channel.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function frequentWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$user, $team, $channel];
}

/**
 * React `$times` times with `$emoji` in `$channel`, one distinct message each
 * (the table allows a user one row per emoji per message).
 */
function reactTimes(Channel $channel, User $user, string $emoji, int $times = 1, ?string $at = null): void
{
    for ($index = 0; $index < $times; $index++) {
        $message = Message::factory()->for($channel)->for($user)->create();

        MessageReaction::factory()
            ->for($message)
            ->for($user)
            ->emoji($emoji)
            ->create(['created_at' => $at ?? now()]);
    }
}

test('the ranking orders by lifetime count, then last use, then emoji', function (): void {
    [$user, $team, $channel] = frequentWorkspace();
    CustomEmoji::factory()->for($team)->name('alpha')->create();
    CustomEmoji::factory()->for($team)->name('beta')->create();

    reactTimes($channel, $user, '🎉', 3);
    reactTimes($channel, $user, '👍', 2, at: now()->subDay()->toDateTimeString());
    reactTimes($channel, $user, '👀', 2);
    reactTimes($channel, $user, ':beta:');
    reactTimes($channel, $user, ':alpha:');

    expect(FrequentEmoji::forUser($user))->toBe(['🎉', '👀', '👍', ':alpha:', ':beta:']);
});

test('the ranking counts only the viewer, only in their current team', function (): void {
    [$user, , $channel] = frequentWorkspace();
    $other = User::factory()->create();
    [, , $elsewhere] = frequentWorkspace();

    reactTimes($channel, $user, '🙌');
    reactTimes($channel, $other, '🚀', 4);
    reactTimes($elsewhere, $user, '🔥', 4);

    expect(FrequentEmoji::forUser($user)[0])->toBe('🙌')
        ->and(FrequentEmoji::forUser($user))->not->toContain('🚀')
        ->and(FrequentEmoji::forUser($user))->not->toContain('🔥');
});

test('a shortcode whose custom emoji was revoked drops out of the ranking', function (): void {
    [$user, $team, $channel] = frequentWorkspace();
    $emoji = CustomEmoji::factory()->for($team)->name('shipit')->create();

    reactTimes($channel, $user, ':shipit:', 4);

    expect(FrequentEmoji::forUser($user))->toContain(':shipit:');

    $emoji->delete();

    expect(FrequentEmoji::forUser($user))->not->toContain(':shipit:');
});

test('the ranking is padded from the default set to exactly five entries', function (): void {
    [$user, , $channel] = frequentWorkspace();

    reactTimes($channel, $user, '🚀', 3);

    expect(FrequentEmoji::forUser($user))->toBe(['🚀', '👍', '❤️', '😂', '🎉']);
});

test('a ranking longer than five is truncated to the top five', function (): void {
    [$user, , $channel] = frequentWorkspace();

    foreach (['🚀' => 6, '🔥' => 5, '🙌' => 4, '🤝' => 3, '🧠' => 2, '🍕' => 1] as $emoji => $times) {
        reactTimes($channel, $user, $emoji, $times);
    }

    expect(FrequentEmoji::forUser($user))->toBe(['🚀', '🔥', '🙌', '🤝', '🧠']);
});

test('an unscoped viewer gets the default set without querying reactions', function (): void {
    // The factory hands every user a personal team, so drop it back out.
    $teamless = User::factory()->create();
    $teamless->forceFill(['current_team_id' => null])->save();
    $teamless->unsetRelation('currentTeam');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(FrequentEmoji::forUser(null))->toBe(['👍', '❤️', '😂', '🎉', '👀'])
        ->and(FrequentEmoji::forUser($teamless))->toBe(['👍', '❤️', '😂', '🎉', '👀'])
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'message_reactions')))->toBeEmpty();
});

test('the ranking rides every inertia response as a shared prop', function (): void {
    [$user, $team, $channel] = frequentWorkspace();

    reactTimes($channel, $user, '🚀', 3);

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team, 'channel' => $channel]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('frequentEmojis', 5)
            ->where('frequentEmojis.0', '🚀')
        );
});

test('a guest still receives the default set on an inertia response', function (): void {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('frequentEmojis', ['👍', '❤️', '😂', '🎉', '👀'])
        );
});
