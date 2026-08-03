<?php

declare(strict_types=1);

use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Message;
use App\Rules\MessageTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Which message a new message may point at (#1150)
|--------------------------------------------------------------------------
|
| "A live, non-system message in this channel" — and, for a thread, "one that
| is not itself a reply" — was spelled six times across four form requests,
| each as a `Rule::exists` chain only a rendered 422 could disagree with. It
| is one fact with two readings now, so it is stated here, against the rule
| itself.
|
*/

/**
 * Whether the rule accepts the given id, driven through a validator rather than
 * called directly: the rule's contract is what the validator does with it.
 */
function accepts(ValidationRule $rule, string $id): bool
{
    return Validator::make(['target' => $id], ['target' => [$rule]])->passes();
}

/**
 * A message in the channel, standard and live unless the state says otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function messageIn(Channel $channel, array $attributes = []): Message
{
    return Message::factory()->for($channel)->create($attributes);
}

test('a live message in the channel is a reply target', function (): void {
    ['channel' => $channel] = teamWithChannel();

    expect(accepts(MessageTarget::replyTo($channel), messageIn($channel)->id))->toBeTrue();
});

test('a message in another channel is not a reply target', function (): void {
    ['channel' => $channel, 'team' => $team] = teamWithChannel();
    $elsewhere = Channel::factory()->for($team)->create();

    expect(accepts(MessageTarget::replyTo($channel), messageIn($elsewhere)->id))->toBeFalse();
});

test('a deleted message is not a reply target', function (): void {
    ['channel' => $channel] = teamWithChannel();
    $deleted = messageIn($channel);
    $deleted->delete();

    expect(accepts(MessageTarget::replyTo($channel), $deleted->id))->toBeFalse();
});

test('a system notice is not a reply target', function (): void {
    ['channel' => $channel] = teamWithChannel();
    $notice = Message::factory()->for($channel)->system(MessageType::MemberJoined)->create();

    expect(accepts(MessageTarget::replyTo($channel), $notice->id))->toBeFalse();
});

/**
 * The two readings differ on exactly one message: a reply. Quoting one inline is
 * ordinary — you answer what somebody said in a thread — but hanging a thread off
 * it is not, because that is what would make threads two levels deep.
 */
test('a thread reply may be quoted but may not root a thread', function (): void {
    ['channel' => $channel] = teamWithChannel();
    $root = messageIn($channel);
    $reply = messageIn($channel, ['thread_root_id' => $root->id]);

    expect(accepts(MessageTarget::replyTo($channel), $reply->id))->toBeTrue()
        ->and(accepts(MessageTarget::threadRootIn($channel), $reply->id))->toBeFalse()
        ->and(accepts(MessageTarget::threadRootIn($channel), $root->id))->toBeTrue();
});

/**
 * Everything the reply reading refuses, the thread reading refuses too: the
 * root rule is the reply rule plus one clause, not a separate list.
 */
test('a thread root is held to the reply target rule as well', function (): void {
    ['channel' => $channel, 'team' => $team] = teamWithChannel();
    $elsewhere = Channel::factory()->for($team)->create();
    $notice = Message::factory()->for($channel)->system(MessageType::MemberJoined)->create();
    $deleted = messageIn($channel);
    $deleted->delete();

    $rule = MessageTarget::threadRootIn($channel);

    expect(accepts($rule, messageIn($elsewhere)->id))->toBeFalse()
        ->and(accepts($rule, $notice->id))->toBeFalse()
        ->and(accepts($rule, $deleted->id))->toBeFalse();
});

/**
 * The message the six `Rule::exists` copies produced, kept verbatim: the rule
 * changed shape, so what a client is told must not.
 */
test('a rejected target fails with the message the exists rule gave', function (): void {
    ['channel' => $channel] = teamWithChannel();

    $validator = Validator::make(
        ['thread_root_id' => (string) Str::uuid()],
        ['thread_root_id' => [MessageTarget::threadRootIn($channel)]],
    );

    expect($validator->errors()->first('thread_root_id'))
        ->toBe('The selected thread root id is invalid.');
});

/**
 * The other half of what {@see App\Rules\LookupRule} reproduces: a value that
 * already failed is never looked up. It is not tidiness — `messages.id` is a
 * Postgres `uuid`, so asking the database about `not-a-uuid` raises a 22P02 and
 * turns a 422 into a 500. Laravel skips its own `exists` for exactly this
 * reason, and the suite has pinned the 422 since long before this rule existed.
 */
test('a target that already failed the uuid rule is never looked up', function (): void {
    ['channel' => $channel] = teamWithChannel();

    $validator = Validator::make(
        ['thread_root_id' => 'not-a-uuid'],
        ['thread_root_id' => ['uuid', MessageTarget::threadRootIn($channel)]],
    );

    expect($validator->errors()->get('thread_root_id'))
        ->toBe(['The thread root id field must be a valid UUID.']);
});
