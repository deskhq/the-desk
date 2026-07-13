<?php

use App\Actions\Channels\PostMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\AttachmentStatus;
use App\Events\MessageSent;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function sendTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Post to the channel's message-store endpoint.
 */
function sendMessage(User $user, Team $team, Channel $channel, array $payload): TestResponse
{
    return test()->actingAs($user)->post(
        route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]),
        $payload,
    );
}

test('a send claims its pending attachments in the same message', function (): void {
    [$owner, $team, $general] = sendTeam();
    $attachment = Attachment::factory()->for($owner)->for($general)->create();

    sendMessage($owner, $team, $general, [
        'body' => 'Here you go',
        'client_uuid' => (string) Str::uuid7(),
        'attachment_ids' => [$attachment->id],
    ])->assertRedirect();

    $message = Message::sole();
    $attachment->refresh();
    expect($attachment->message_id)->toBe($message->id)
        ->and($attachment->status)->toBe(AttachmentStatus::Attached);
});

test('a message may be sent with attachments and no body', function (): void {
    [$owner, $team, $general] = sendTeam();
    $attachment = Attachment::factory()->for($owner)->for($general)->create();

    sendMessage($owner, $team, $general, [
        'client_uuid' => (string) Str::uuid7(),
        'attachment_ids' => [$attachment->id],
    ])->assertRedirect();

    expect(Message::sole()->body)->toBe('');
});

test('a message with neither body nor attachments is rejected', function (): void {
    [$owner, $team, $general] = sendTeam();

    sendMessage($owner, $team, $general, [
        'client_uuid' => (string) Str::uuid7(),
    ])->assertInvalid(['body']);

    expect(Message::count())->toBe(0);
});

test('the claimed attachments ride the broadcast payload', function (): void {
    [$owner, $team, $general] = sendTeam();
    Event::fake([MessageSent::class]);
    $attachment = Attachment::factory()->for($owner)->for($general)->create();

    sendMessage($owner, $team, $general, [
        'body' => 'With a file',
        'client_uuid' => (string) Str::uuid7(),
        'attachment_ids' => [$attachment->id],
    ])->assertRedirect();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => count($event->message->attachments) === 1
        && $event->message->attachments[0]->id === $attachment->id);
});

test('a send cannot claim another member attachment and rolls back', function (): void {
    [$owner, $team, $general] = sendTeam();
    $other = User::factory()->create();
    $team->members()->attach($other, ['role' => 'member']);
    $foreign = Attachment::factory()->for($other)->for($general)->create();

    $clientUuid = (string) Str::uuid7();
    sendMessage($owner, $team, $general, [
        'body' => 'Stealing this',
        'client_uuid' => $clientUuid,
        'attachment_ids' => [$foreign->id],
    ])->assertInvalid(['attachment_ids']);

    expect(Message::where('client_uuid', $clientUuid)->exists())->toBeFalse();
    expect($foreign->refresh()->message_id)->toBeNull();
});

test('a send cannot claim an attachment from another channel', function (): void {
    [$owner, $team, $general] = sendTeam();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $elsewhere = Attachment::factory()->for($owner)->for($other)->create();

    sendMessage($owner, $team, $general, [
        'body' => 'Wrong channel',
        'client_uuid' => (string) Str::uuid7(),
        'attachment_ids' => [$elsewhere->id],
    ])->assertInvalid(['attachment_ids']);

    expect(Message::count())->toBe(0);
});

test('a send cannot claim an attachment already attached to another message', function (): void {
    [$owner, $team, $general] = sendTeam();
    $existing = Message::factory()->for($general)->for($owner)->create();
    $claimed = Attachment::factory()->for($owner)->for($general)->attachedTo($existing)->create();

    sendMessage($owner, $team, $general, [
        'body' => 'Double claim',
        'client_uuid' => (string) Str::uuid7(),
        'attachment_ids' => [$claimed->id],
    ])->assertInvalid(['attachment_ids']);

    expect(Message::count())->toBe(1)
        ->and($claimed->refresh()->message_id)->toBe($existing->id);
});

test('resending with the same client uuid re-claims nothing and stays idempotent', function (): void {
    [$owner, $team, $general] = sendTeam();
    $attachment = Attachment::factory()->for($owner)->for($general)->create();
    $clientUuid = (string) Str::uuid7();
    $payload = [
        'body' => 'Retry me',
        'client_uuid' => $clientUuid,
        'attachment_ids' => [$attachment->id],
    ];

    sendMessage($owner, $team, $general, $payload)->assertRedirect();
    sendMessage($owner, $team, $general, $payload)->assertRedirect();

    $message = Message::sole();
    expect($attachment->refresh()->message_id)->toBe($message->id)
        ->and($attachment->status)->toBe(AttachmentStatus::Attached);
});

test('a reused client uuid cannot append attachments to another member message', function (): void {
    [$owner, $team, $general] = sendTeam();
    $other = User::factory()->create();
    $team->members()->attach($other, ['role' => 'member']);

    // The owner sends first — a message now exists under this client_uuid, whose
    // value rides the broadcast payload and is therefore observable to members.
    $clientUuid = (string) Str::uuid7();
    sendMessage($owner, $team, $general, [
        'body' => 'My message',
        'client_uuid' => $clientUuid,
    ])->assertRedirect();

    // The attacker reuses it, trying to graft their own upload onto that message.
    $attackerFile = Attachment::factory()->for($other)->for($general)->create();
    sendMessage($other, $team, $general, [
        'client_uuid' => $clientUuid,
        'attachment_ids' => [$attackerFile->id],
    ])->assertInvalid(['attachment_ids']);

    $message = Message::where('client_uuid', $clientUuid)->sole();
    expect($message->user_id)->toBe($owner->id)
        ->and(Attachment::where('message_id', $message->id)->count())->toBe(0)
        ->and($attackerFile->refresh()->message_id)->toBeNull()
        ->and($attackerFile->status)->toBe(AttachmentStatus::Pending);
});

test('a retry cannot append a new attachment to the existing message or bypass the cap', function (): void {
    [$owner, $team, $general] = sendTeam();
    $first = Attachment::factory()->for($owner)->for($general)->create();
    $clientUuid = (string) Str::uuid7();

    sendMessage($owner, $team, $general, [
        'body' => 'First try',
        'client_uuid' => $clientUuid,
        'attachment_ids' => [$first->id],
    ])->assertRedirect();

    // Same client_uuid, but now with an extra freshly uploaded file appended.
    $second = Attachment::factory()->for($owner)->for($general)->create();
    sendMessage($owner, $team, $general, [
        'body' => 'First try',
        'client_uuid' => $clientUuid,
        'attachment_ids' => [$first->id, $second->id],
    ])->assertInvalid(['attachment_ids']);

    expect($first->refresh()->message_id)->toBe(Message::sole()->id)
        ->and($second->refresh()->message_id)->toBeNull()
        ->and($second->status)->toBe(AttachmentStatus::Pending);
});

test('claiming rejects the whole send when a requested id no longer exists', function (): void {
    [$owner, $team, $general] = sendTeam();

    // Drive the action directly: the HTTP layer's exists rule 404s a bad id
    // before it reaches the claim, so this covers the mid-transaction TOCTOU
    // window (and any direct caller) where a requested row has since vanished.
    expect(fn () => app(PostMessage::class)->handle(
        channel: $general,
        author: $owner,
        body: 'Ghost file',
        clientUuid: (string) Str::uuid7(),
        attachmentIds: [(string) Str::uuid7()],
    ))->toThrow(ValidationException::class);

    expect(Message::count())->toBe(0);
});

test('a send cannot claim more attachments than the per-message limit', function (): void {
    [$owner, $team, $general] = sendTeam();
    config()->set('attachments.max_per_message', 2);
    $ids = Attachment::factory()->count(3)->for($owner)->for($general)->create()->pluck('id')->all();

    sendMessage($owner, $team, $general, [
        'body' => 'Too many',
        'client_uuid' => (string) Str::uuid7(),
        'attachment_ids' => $ids,
    ])->assertInvalid(['attachment_ids']);

    expect(Message::count())->toBe(0);
});
