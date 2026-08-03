<?php

declare(strict_types=1);

use App\Actions\Channels\CreateChannel;
use App\Models\Channel;
use App\Models\Team;
use App\Rules\AvailableChannelName;
use App\Rules\LookupRule;
use App\Support\NameSlug;
use Illuminate\Support\Facades\Validator;

/*
|--------------------------------------------------------------------------
| Whether a channel name is still free in the workspace (#1150)
|--------------------------------------------------------------------------
|
| The check is not "is this name taken" but "does this name *slug* the same way
| as one already here", because the slug is what the URL is built from and two
| channels sharing one would leave the second unreachable. That distinction was
| spelled in three `after()` bodies — the web create, the rename, and the API
| create — and could only be observed through a 422.
|
*/

/**
 * Whether the rule lets the given name through, driven through a validator
 * rather than called directly: the rule's contract is what the validator does
 * with it.
 */
function nameIsFree(AvailableChannelName $rule, string $name): bool
{
    return Validator::make(['name' => $name], ['name' => [$rule]])->passes();
}

/**
 * A channel in the team, slugged the way {@see CreateChannel}
 * slugs one — the factory would otherwise keep the slug of the name it invented.
 */
function namedChannel(Team $team, string $name): Channel
{
    return Channel::factory()->for($team)->create([
        'name' => $name,
        'slug' => NameSlug::distinct($name, Channel::FALLBACK_SLUG),
    ]);
}

test('a name nothing in the team has taken is free', function (): void {
    ['team' => $team] = teamWithChannel();

    expect(nameIsFree(new AvailableChannelName($team), 'Marketing'))->toBeTrue();
});

test('a name already in the team is taken', function (): void {
    ['team' => $team] = teamWithChannel();
    namedChannel($team, 'Marketing');

    expect(nameIsFree(new AvailableChannelName($team), 'Marketing'))->toBeFalse();
});

/**
 * The reason the rule slugs rather than compares names: "Marketing" and
 * "marketing!" read as two names and would be one URL.
 */
test('a name that only differs in what the slug drops is taken', function (): void {
    ['team' => $team] = teamWithChannel();
    namedChannel($team, 'Marketing');

    expect(nameIsFree(new AvailableChannelName($team), 'marketing!'))->toBeFalse();
});

test('a name taken in another team is free here', function (): void {
    ['team' => $team] = teamWithChannel();
    ['team' => $elsewhere] = teamWithChannel('Globex');
    namedChannel($elsewhere, 'Marketing');

    expect(nameIsFree(new AvailableChannelName($team), 'Marketing'))->toBeTrue();
});

/**
 * A rename asks the question about *other* channels: a channel keeps its slug
 * through a rename, so its own row is never the collision.
 */
test('a rename does not collide with the channel being renamed', function (): void {
    ['team' => $team] = teamWithChannel();
    $channel = namedChannel($team, 'Marketing');

    expect(nameIsFree(new AvailableChannelName($team, except: $channel), 'Marketing!'))->toBeTrue()
        ->and(nameIsFree(new AvailableChannelName($team), 'Marketing!'))->toBeFalse();
});

/**
 * The copy the three `after()` bodies added, kept verbatim — it is what the
 * composer and the workspace admin page both show under the name field.
 */
test('a taken name is refused with the copy the after() bodies gave', function (): void {
    ['team' => $team] = teamWithChannel();
    namedChannel($team, 'Marketing');

    $validator = Validator::make(['name' => 'Marketing'], ['name' => [new AvailableChannelName($team)]]);

    expect($validator->errors()->first('name'))->toBe('A channel with this name already exists.');
});

/**
 * The guard the three bodies opened with, now inherited from
 * {@see LookupRule}: a name that already failed is not slugged and not
 * looked up, so the client is told the one thing that is wrong with it.
 */
test('a name that already failed an earlier rule is left alone', function (): void {
    ['team' => $team] = teamWithChannel();
    namedChannel($team, 'Marketing');

    $validator = Validator::make(
        ['name' => 'Marketing'],
        ['name' => ['max:3', new AvailableChannelName($team)]],
    );

    expect($validator->errors()->get('name'))
        ->toBe(['The name field must not be greater than 3 characters.']);
});
