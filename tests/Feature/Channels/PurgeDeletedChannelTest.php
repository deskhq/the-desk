<?php

use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\PurgeExpiredChannels;
use App\Actions\Teams\CreateTeam;
use App\Jobs\PurgeDeletedChannel;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
});

/**
 * A deleted channel populated with one of everything the purge has to reach: a
 * message, a claimed attachment with a blob on disk, a pin, a scheduled message,
 * and a membership.
 *
 * @return array{0: Channel, 1: Attachment}
 */
function purgeableChannel(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    $message = Message::factory()->for($channel)->for($owner)->create();

    $attachment = Attachment::factory()->for($channel)->for($owner)->create(['message_id' => $message->id]);
    Storage::disk($attachment->disk)->put($attachment->path, 'blob');

    MessagePin::factory()->for($message)->create([
        'channel_id' => $channel->id,
        'pinned_by' => $owner->id,
    ]);

    ScheduledMessage::factory()->for($channel)->for($owner)->create();

    app(DeleteChannel::class)->handle($channel, null);

    return [$channel, $attachment];
}

test('the purge job destroys the channel, every child row, and the attachment blobs on disk', function (): void {
    [$channel, $attachment] = purgeableChannel();

    $this->travel(PurgeDeletedChannel::GRACE_WINDOW_DAYS + 1)->days();

    (new PurgeDeletedChannel($channel->id))->handle();

    expect(Channel::withTrashed()->whereKey($channel->id)->exists())->toBeFalse()
        ->and(Message::withTrashed()->where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(ChannelMember::where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(MessagePin::where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(ScheduledMessage::where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(Storage::disk($attachment->disk)->exists($attachment->path))->toBeFalse();
});

test('the purge job reclaims the blob of an attachment that was already soft-deleted', function (): void {
    [$channel] = purgeableChannel();

    $orphan = Attachment::factory()->create(['channel_id' => $channel->id]);
    Storage::disk($orphan->disk)->put($orphan->path, 'blob');
    $orphan->delete();

    $this->travel(PurgeDeletedChannel::GRACE_WINDOW_DAYS + 1)->days();

    (new PurgeDeletedChannel($channel->id))->handle();

    expect(Storage::disk($orphan->disk)->exists($orphan->path))->toBeFalse();
});

test('the purge job leaves a channel whose grace window has not closed', function (): void {
    [$channel] = purgeableChannel();

    $this->travel(PurgeDeletedChannel::GRACE_WINDOW_DAYS - 1)->days();

    (new PurgeDeletedChannel($channel->id))->handle();

    expect(Channel::withTrashed()->whereKey($channel->id)->exists())->toBeTrue();
});

test('the purge job leaves a channel that has since been restored', function (): void {
    [$channel] = purgeableChannel();
    $channel->restore();

    $this->travel(PurgeDeletedChannel::GRACE_WINDOW_DAYS + 1)->days();

    (new PurgeDeletedChannel($channel->id))->handle();

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

test('the purge job is safe to run again once the channel is gone', function (): void {
    [$channel] = purgeableChannel();

    $this->travel(PurgeDeletedChannel::GRACE_WINDOW_DAYS + 1)->days();

    (new PurgeDeletedChannel($channel->id))->handle();

    expect(fn () => (new PurgeDeletedChannel($channel->id))->handle())->not->toThrow(Throwable::class);
});

test('the scheduled sweep queues a purge only for channels past their grace window', function (): void {
    Bus::fake();

    [$expired] = purgeableChannel();
    [$fresh] = purgeableChannel();

    // Age only the first channel's deletion past the window, so the sweep has one
    // candidate and one channel still inside its window.
    DB::table('channels')
        ->where('id', $expired->id)
        ->update(['deleted_at' => now()->subDays(PurgeDeletedChannel::GRACE_WINDOW_DAYS + 1)]);

    expect(app(PurgeExpiredChannels::class)->handle())->toBe(1);

    Bus::assertDispatchedTimes(PurgeDeletedChannel::class, 1);
    Bus::assertNotDispatched(PurgeDeletedChannel::class, fn (PurgeDeletedChannel $job): bool => $job->channelId === $fresh->id);
});
