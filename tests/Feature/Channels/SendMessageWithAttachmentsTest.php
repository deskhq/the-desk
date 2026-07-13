<?php

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
