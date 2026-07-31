<?php

declare(strict_types=1);

use App\Actions\Channels\ArchiveChannel;
use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\DeleteMessage;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Actions\Channels\RemoveChannelMember;
use App\Actions\Channels\RestoreChannel;
use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Jobs\PurgeDeletedChannel;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Bus;

/**
 * The four channel facts that used to be recorded by their controllers now live
 * in their Actions, so every caller — a console command, a queued directory
 * sync, a slash command — records them, not just the HTTP surfaces.
 */
beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->channel = Channel::factory()->for($this->team)->private()->create();
});

/**
 * Count the audit entries of an action recorded against the test's team.
 */
function seamEntries(AuditAction $action): int
{
    return AuditActivity::query()
        ->where('team_id', test()->team->id)
        ->where('event', $action->value)
        ->count();
}

it('records both the webhook delivery and the audit row for a non-HTTP member add', function (): void {
    WebhookSubscription::factory()->for($this->team)->create([
        'events' => [WebhookEvent::ChannelMemberAdded->value],
    ]);

    Bus::fake([DeliverWebhook::class]);

    app(JoinChannel::class)->handle($this->channel, $this->member, addedBy: $this->admin);

    Bus::assertDispatched(DeliverWebhook::class);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::ChannelMemberAdded->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->subject_id)->toBe($this->channel->id);
    expect($entry->properties['member_name'])->toBe($this->member->name);
    expect($entry->properties['channel_name'])->toBe($this->channel->name);
});

it('does not audit a member joining a channel of their own accord', function (): void {
    app(JoinChannel::class)->handle($this->channel, $this->member);

    expect(seamEntries(AuditAction::ChannelMemberAdded))->toBe(0);
});

it('does not audit a repeat add that changes no membership', function (): void {
    app(JoinChannel::class)->handle($this->channel, $this->member, addedBy: $this->admin);
    app(JoinChannel::class)->handle($this->channel, $this->member, addedBy: $this->admin);

    expect(seamEntries(AuditAction::ChannelMemberAdded))->toBe(1);
});

it('records a non-HTTP member removal against the actor who removed them', function (): void {
    app(JoinChannel::class)->handle($this->channel, $this->member);

    app(RemoveChannelMember::class)->handle($this->channel, $this->member, removedBy: $this->admin);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::ChannelMemberRemoved->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->properties['member_name'])->toBe($this->member->name);
});

it('does not audit a member leaving a channel', function (): void {
    app(JoinChannel::class)->handle($this->channel, $this->member);

    app(LeaveChannel::class)->handle($this->channel, $this->member);

    expect(seamEntries(AuditAction::ChannelMemberRemoved))->toBe(0);
});

it('records a channel creation against its team', function (): void {
    $channel = app(CreateChannel::class)->handle($this->team, 'marketing', ChannelVisibility::Public, $this->admin);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::ChannelCreated->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->subject_id)->toBe($channel->id);
    expect($entry->properties['channel_name'])->toBe('marketing');
});

it('does not audit the protected general channel a new workspace bootstraps', function (): void {
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);

    expect(AuditActivity::query()
        ->where('team_id', $team->id)
        ->where('event', AuditAction::ChannelCreated->value)
        ->count())->toBe(0);
});

it('records a channel deletion with the date its content is purged', function (): void {
    $channel = app(DeleteChannel::class)->handle($this->channel, $this->admin);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::ChannelDeleted->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->properties['purge_at'])
        ->toBe($channel->deleted_at->addDays(PurgeDeletedChannel::GRACE_WINDOW_DAYS)->toDateString());
});

it('does not audit deleting a channel that is already deleted', function (): void {
    app(DeleteChannel::class)->handle($this->channel, $this->admin);
    app(DeleteChannel::class)->handle($this->channel, $this->admin);

    expect(seamEntries(AuditAction::ChannelDeleted))->toBe(1);
});

it('records a channel restore', function (): void {
    $channel = app(DeleteChannel::class)->handle($this->channel, $this->admin);

    app(RestoreChannel::class)->handle($channel, $this->admin);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::ChannelRestored->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->properties['channel_name'])->toBe($this->channel->name);
});

it('records a moderator deleting another members message', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->member)->create();

    app(DeleteMessage::class)->handle($this->channel, $message, deletedBy: $this->admin);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::MessageDeleted->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->properties['author_name'])->toBe($this->member->name);
    expect($entry->properties['channel_name'])->toBe($this->channel->name);
});

it('does not audit a member deleting their own message', function (): void {
    $message = Message::factory()->for($this->channel)->for($this->member)->create();

    app(DeleteMessage::class)->handle($this->channel, $message, deletedBy: $this->member);

    expect(seamEntries(AuditAction::MessageDeleted))->toBe(0);
});

it('records a channel archive against the actor who archived it', function (): void {
    app(ArchiveChannel::class)->handle($this->channel, $this->admin);

    $entry = AuditActivity::query()
        ->where('team_id', $this->team->id)
        ->where('event', AuditAction::ChannelArchived->value)
        ->sole();

    expect($entry->causer_id)->toBe($this->admin->id);
    expect($entry->properties['channel_name'])->toBe($this->channel->name);
});
