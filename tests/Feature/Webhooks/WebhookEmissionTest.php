<?php

declare(strict_types=1);

use App\Actions\Channels\DeleteMessage;
use App\Actions\Channels\EditMessage;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\PostMessage;
use App\Actions\Channels\ToggleReaction;
use App\Enums\WebhookEvent;
use App\Events\WebhookEventOccurred;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->channel = Channel::factory()->for($this->team)->create();
    $this->user = User::factory()->create();
    $this->channel->channelMembers()->create(['user_id' => $this->user->id]);

    // Fake only after the fixtures are built: creating a user seeds its personal
    // team and #general membership through JoinChannel, which would otherwise
    // count toward the assertions below.
    Event::fake([WebhookEventOccurred::class]);
});

/**
 * Assert a webhook event of a given type was emitted for the beforeEach channel.
 */
function assertEmitted(WebhookEvent $event, Channel $channel): void
{
    Event::assertDispatched(WebhookEventOccurred::class, fn (WebhookEventOccurred $e): bool => $e->event === $event && $e->channel->is($channel));
}

it('emits message.created when a message is posted', function (): void {
    app(PostMessage::class)->handle($this->channel, $this->user, 'hello', (string) Str::uuid());

    assertEmitted(WebhookEvent::MessageCreated, $this->channel);
});

it('does not re-emit message.created on a client_uuid retry', function (): void {
    $uuid = (string) Str::uuid();
    app(PostMessage::class)->handle($this->channel, $this->user, 'hello', $uuid);
    app(PostMessage::class)->handle($this->channel, $this->user, 'hello', $uuid);

    Event::assertDispatchedTimes(WebhookEventOccurred::class, 1);
});

it('emits message.updated when a message is edited', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->user)->create();

    app(EditMessage::class)->handle($this->channel, $message, 'edited body');

    assertEmitted(WebhookEvent::MessageUpdated, $this->channel);
});

it('emits message.deleted when a message is deleted', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->user)->create();

    app(DeleteMessage::class)->handle($this->channel, $message);

    assertEmitted(WebhookEvent::MessageDeleted, $this->channel);
});

it('emits reaction.added only when a reaction is added, not removed', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->user)->create();

    app(ToggleReaction::class)->handle($this->channel, $message, $this->user, '👍');
    assertEmitted(WebhookEvent::ReactionAdded, $this->channel);

    // The second toggle removes the reaction and must not emit again.
    app(ToggleReaction::class)->handle($this->channel, $message, $this->user, '👍');
    Event::assertDispatchedTimes(WebhookEventOccurred::class, 1);
});

it('emits channel.member_added when a new member joins', function (): void {
    $newcomer = User::factory()->create();

    app(JoinChannel::class)->handle($this->channel, $newcomer);

    assertEmitted(WebhookEvent::ChannelMemberAdded, $this->channel);
});

it('does not emit channel.member_added when re-joining an existing membership', function (): void {
    app(JoinChannel::class)->handle($this->channel, $this->user);

    Event::assertNotDispatched(WebhookEventOccurred::class);
});
