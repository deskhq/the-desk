<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\User;
use App\Rules\ForwardDestination;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Where a forwarded message may land (#1150)
|--------------------------------------------------------------------------
|
| The three-clause `Rule::exists` this replaced said in its own comment that it
| was "the same constraint the `postMessage` gate applies, expressed as an
| existence check" — two spellings of one rule, kept in step by hand. The rule
| asks the gate now, so there is nothing left to drift.
|
*/

/**
 * Whether the rule accepts the channel as a destination for a message forwarded
 * out of the source, driven through a validator: what the rule promises is what
 * the validator does with it.
 */
function mayForwardInto(Channel $source, User $author, string $targetId): bool
{
    return Validator::make(
        ['target_channel_id' => $targetId],
        ['target_channel_id' => [new ForwardDestination($source, $author)]],
    )->passes();
}

test('a live channel in the team the author belongs to is a destination', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $source] = teamWithChannel();
    $target = Channel::factory()->for($team)->create();
    $target->members()->attach($owner->id);

    expect(mayForwardInto($source, $owner, $target->id))->toBeTrue();
});

/**
 * The membership reading, not the readable one: a public channel is one the
 * author may *open*, and forwarding into it would still post as them somewhere
 * they never joined.
 */
test('a channel the author has not joined is not a destination', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $source] = teamWithChannel();
    $stranger = Channel::factory()->for($team)->create();

    expect(mayForwardInto($source, $owner, $stranger->id))->toBeFalse();
});

test('an archived channel is not a destination', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $source] = teamWithChannel();
    $archived = Channel::factory()->for($team)->archived()->create();
    $archived->members()->attach($owner->id);

    expect(mayForwardInto($source, $owner, $archived->id))->toBeFalse();
});

/**
 * Forwarding stays inside one workspace. The author may well be in the other
 * team's channel — that is not the question, because the message is not.
 */
test('a channel in another team is not a destination even when the author is in it', function (): void {
    ['owner' => $owner, 'channel' => $source] = teamWithChannel();
    ['team' => $elsewhere] = teamWithChannel('Globex');

    $across = Channel::factory()->for($elsewhere)->create();
    $across->members()->attach($owner->id);

    expect(mayForwardInto($source, $owner, $across->id))->toBeFalse();
});

test('an id naming no channel is not a destination', function (): void {
    ['owner' => $owner, 'channel' => $source] = teamWithChannel();

    expect(mayForwardInto($source, $owner, (string) Str::uuid()))->toBeFalse();
});

/**
 * The message the `Rule::exists` chain produced, kept verbatim.
 */
test('a rejected destination fails with the message the exists rule gave', function (): void {
    ['owner' => $owner, 'channel' => $source] = teamWithChannel();

    $validator = Validator::make(
        ['target_channel_id' => (string) Str::uuid()],
        ['target_channel_id' => [new ForwardDestination($source, $owner)]],
    );

    expect($validator->errors()->first('target_channel_id'))
        ->toBe('The selected target channel id is invalid.');
});

/**
 * `channels.id` is a Postgres `uuid`, so the rule inherits the same silence its
 * siblings do: a destination that already failed `uuid` is never looked up.
 */
test('a destination that already failed the uuid rule is never looked up', function (): void {
    ['owner' => $owner, 'channel' => $source] = teamWithChannel();

    $validator = Validator::make(
        ['target_channel_id' => 'not-a-uuid'],
        ['target_channel_id' => ['uuid', new ForwardDestination($source, $owner)]],
    );

    expect($validator->errors()->get('target_channel_id'))
        ->toBe(['The target channel id field must be a valid UUID.']);
});
