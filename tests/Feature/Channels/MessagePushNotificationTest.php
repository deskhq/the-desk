<?php

use App\Data\MessageData;
use App\Enums\ChannelType;
use App\Enums\NotificationLevel;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'webpush.vapid.public_key' => 'test-public-key',
        'webpush.vapid.private_key' => 'test-private-key',
    ]);

    Notification::fake();
});

/**
 * Give the user a subscribed device, so the fan-out considers them at all.
 */
function subscribeDevice(User $user): User
{
    $user->updatePushSubscription(
        'https://push.example.test/'.Str::uuid(),
        'public-key',
        'auth-token',
    );

    return $user;
}

/**
 * A team with a channel, its author, and one subscribed recipient at the given
 * preference.
 *
 * @return array{0: User, 1: User, 2: Team, 3: Channel}
 */
function pushScenario(NotificationLevel $level = NotificationLevel::All, bool $muted = false): array
{
    $team = Team::factory()->create();
    $author = User::factory()->create(['name' => 'Ada Lovelace']);
    $recipient = subscribeDevice(User::factory()->create());

    $team->members()->attach([$author->id => ['role' => 'member'], $recipient->id => ['role' => 'member']]);

    $channel = Channel::factory()->create(['team_id' => $team->id, 'name' => 'deploys', 'slug' => 'deploys']);

    foreach ([$author, $recipient] as $member) {
        ChannelMember::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $member->id,
            'muted' => $member->is($recipient) && $muted,
            'notification_level' => $member->is($recipient) ? $level : NotificationLevel::All,
        ]);
    }

    return [$author, $recipient, $team, $channel];
}

/**
 * Post a message as the author through the real send endpoint.
 */
function postPushMessage(User $author, Team $team, Channel $channel, string $body): void
{
    test()->actingAs($author)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => $body,
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();
}

test('a new channel message pushes to a subscribed member', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();

    postPushMessage($author, $team, $channel, 'Standing up the new deploy');

    Notification::assertSentTo($recipient, NewMessageNotification::class);
});

test('the author is never pushed about their own message', function (): void {
    [$author, , $team, $channel] = pushScenario();
    subscribeDevice($author);

    postPushMessage($author, $team, $channel, 'Talking to myself');

    Notification::assertNotSentTo($author, NewMessageNotification::class);
});

test('a muted channel never pushes', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario(muted: true);

    postPushMessage($author, $team, $channel, 'Quiet in here');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('ordinary traffic stays silent at the mentions level', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario(NotificationLevel::Mentions);

    postPushMessage($author, $team, $channel, 'Just chatter');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('a mention pushes at the mentions level', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario(NotificationLevel::Mentions);

    postPushMessage($author, $team, $channel, "@[{$recipient->name}]({$recipient->id}) can you look?");

    Notification::assertSentTo($recipient, NewMessageNotification::class);
});

test('the nothing level silences even a mention', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario(NotificationLevel::Nothing);

    postPushMessage($author, $team, $channel, "@[{$recipient->name}]({$recipient->id}) hello?");

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('do-not-disturb suppresses the push', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();
    $recipient->forceFill(['dnd_until' => now()->addHour()])->save();

    postPushMessage($author, $team, $channel, 'Deploy is green');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('a member with no subscribed device is never queued a notification', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();
    $recipient->pushSubscriptions()->delete();

    postPushMessage($author, $team, $channel, 'Nobody to reach');

    Notification::assertNothingSent();
});

test('a deactivated member is not pushed to', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();
    $recipient->forceFill(['deactivated_at' => now()])->save();

    postPushMessage($author, $team, $channel, 'Gone from the directory');

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);
});

test('a system notice never pushes', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();

    $this->actingAs($recipient)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect();

    Notification::assertNothingSent();
});

test('nothing is pushed on an instance with no vapid keypair', function (): void {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

    [$author, , $team, $channel] = pushScenario();

    postPushMessage($author, $team, $channel, 'No keys, no push');

    Notification::assertNothingSent();
});

test('a thread-only reply pushes only through the mention path', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();

    $root = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $author->id,
    ]);

    $this->actingAs($author)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => 'A quiet thread reply',
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $root->id,
        ])
        ->assertRedirect();

    Notification::assertNotSentTo($recipient, NewMessageNotification::class);

    $this->actingAs($author)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => "@[{$recipient->name}]({$recipient->id}) in the thread",
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $root->id,
        ])
        ->assertRedirect();

    Notification::assertSentTo($recipient, NewMessageNotification::class);
});

test('a thread reply also sent to the channel pushes as ordinary traffic', function (): void {
    [$author, $recipient, $team, $channel] = pushScenario();

    $root = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $author->id,
    ]);

    $this->actingAs($author)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => 'Sharing this back to the channel',
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $root->id,
            'sent_to_channel' => true,
        ])
        ->assertRedirect();

    Notification::assertSentTo($recipient, NewMessageNotification::class);
});

test('the banner names the sender and the channel, and links to the message', function (): void {
    [$author, $recipient, , $channel] = pushScenario();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $author->id,
        'body' => 'Deploy is green',
    ]);
    $message->loadMessageDataRelations();

    $push = (new NewMessageNotification($channel, MessageData::fromMessage($message)))
        ->toWebPush($recipient)
        ->toArray();

    expect($push['title'])->toBe('Ada Lovelace in #deploys')
        ->and($push['body'])->toBe('Deploy is green')
        ->and($push['tag'])->toBe('channel-'.$channel->id)
        ->and($push['renotify'])->toBeTrue()
        ->and($push['icon'])->toBe('/icons/icon-192.png')
        ->and($push['data']['url'])->toContain('?message='.$message->id)
        ->and($push['data']['messageId'])->toBe($message->id);
});

test('a direct message banner is titled by its sender alone', function (): void {
    [$author, $recipient, $team] = pushScenario();

    $dm = Channel::factory()->create([
        'team_id' => $team->id,
        'name' => null,
        'type' => ChannelType::Direct,
    ]);

    $message = Message::factory()->create([
        'channel_id' => $dm->id,
        'user_id' => $author->id,
        'body' => 'Got a minute?',
    ]);
    $message->loadMessageDataRelations();

    $push = (new NewMessageNotification($dm, MessageData::fromMessage($message)))
        ->toWebPush($recipient)
        ->toArray();

    expect($push['title'])->toBe('Ada Lovelace')
        ->and($push['body'])->toBe('Got a minute?');
});

test('the preview unwraps mention tokens and truncates a long body', function (): void {
    [$author, $recipient, , $channel] = pushScenario();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $author->id,
        'body' => "@[Ada Lovelace]({$author->id}) ".str_repeat('long ', 60),
    ]);
    $message->loadMessageDataRelations();

    $body = (new NewMessageNotification($channel, MessageData::fromMessage($message)))
        ->toWebPush($recipient)
        ->toArray()['body'];

    expect($body)->toStartWith('@Ada Lovelace long')
        ->and(mb_strlen((string) $body))->toBeLessThanOrEqual(124);
});

test('a message with no text of its own describes itself', function (array $attributes, int $attachments, string $expected): void {
    [$author, $recipient, , $channel] = pushScenario();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $author->id,
        ...$attributes,
    ]);

    if ($attachments > 0) {
        Attachment::factory()->count($attachments)->attachedTo($message)->create(['user_id' => $author->id]);
    }

    $message->loadMessageDataRelations();

    $body = (new NewMessageNotification($channel, MessageData::fromMessage($message)))
        ->toWebPush($recipient)
        ->toArray()['body'];

    expect($body)->toBe($expected);
})->with([
    'a poll' => [['body' => '', 'type' => 'poll'], 0, 'Posted a poll'],
    'a single file' => [['body' => ''], 1, 'Sent an attachment'],
    'several files' => [['body' => ''], 2, 'Sent 2 attachments'],
]);
