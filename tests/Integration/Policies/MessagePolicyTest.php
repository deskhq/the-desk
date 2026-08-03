<?php

declare(strict_types=1);

use App\Enums\ChannelVisibility;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| What may be forwarded (#1150)
|--------------------------------------------------------------------------
|
| "Forwarding is gated on being able to see the source, and a system notice is
| not forwardable" was an `authorize()` body in a class that cannot exist
| without a `Request`. It is an ability now, so it is asked here directly —
| forwarding *into* somewhere is a separate question and lives on
| `App\Rules\ForwardDestination`.
|
*/

/**
 * Whether the user may forward the message.
 */
function mayForward(User $user, Message $message): bool
{
    return Gate::forUser($user)->allows('forward', $message);
}

test('a member of the source channel may forward a message from it', function (): void {
    ['owner' => $owner, 'channel' => $channel] = teamWithChannel();

    expect(mayForward($owner, Message::factory()->for($channel)->create()))->toBeTrue();
});

/**
 * A system notice is inert everywhere else — it cannot be edited, deleted,
 * replied to or threaded under — and forwarding is not the exception.
 */
test('a system notice may not be forwarded', function (): void {
    ['owner' => $owner, 'channel' => $channel] = teamWithChannel();
    $notice = Message::factory()->for($channel)->system(MessageType::MemberJoined)->create();

    expect(mayForward($owner, $notice))->toBeFalse();
});

/**
 * The half that makes this a security question: forwarding copies a message
 * somewhere its author never put it, so the source has to be readable first.
 */
test('a message in a private channel may not be forwarded by an outsider', function (): void {
    ['team' => $team] = teamWithChannel();
    $private = Channel::factory()->for($team)->create(['visibility' => ChannelVisibility::Private]);
    $secret = Message::factory()->for($private)->create();

    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Member]);

    expect(mayForward($outsider, $secret))->toBeFalse();
});

/**
 * The reading is *readable*, not *mine*: a public channel is visible to the
 * whole workspace, so a teammate who never joined it may still forward out of
 * it. Which channels they may forward *into* is the membership question, and
 * that one is asked elsewhere.
 */
test('a teammate may forward a message out of a public channel they never joined', function (): void {
    ['team' => $team] = teamWithChannel();
    $public = Channel::factory()->for($team)->create();
    $message = Message::factory()->for($public)->create();

    $teammate = User::factory()->create();
    $team->memberships()->create(['user_id' => $teammate->id, 'role' => TeamRole::Member]);

    expect(mayForward($teammate, $message))->toBeTrue();
});
