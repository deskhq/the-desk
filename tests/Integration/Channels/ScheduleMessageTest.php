<?php

declare(strict_types=1);

use App\Actions\Channels\ScheduleMessage;
use App\Enums\ScheduledMessageStatus;
use App\Models\Message;
use App\Models\ScheduledMessage;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An hour out, whole seconds, and mutable — the action takes the same
 * `Illuminate\Support\Carbon` the controller parses out of the request, not the
 * immutable instance `now()` hands back.
 */
function anHourFromNow(): Carbon
{
    return Carbon::now()->addHour()->startOfSecond();
}

test('scheduling stores the message pending and consumes the author\'s draft', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    channelMembership($general, $owner, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->draft('half-typed'));

    $sendAt = anHourFromNow();
    $clientUuid = (string) Str::uuid7();

    $scheduled = app(ScheduleMessage::class)
        ->handle($general, $owner, 'the finished thought', $clientUuid, $sendAt);

    // Read back rather than asserted on the returned model: what has to survive
    // until the dispatcher runs is the row, and `send_at` in particular only
    // round-trips through the column at whole-second precision.
    $stored = ScheduledMessage::findOrFail($scheduled->id);

    expect($stored->status)->toBe(ScheduledMessageStatus::Pending)
        ->and($stored->body)->toBe('the finished thought')
        ->and($stored->client_uuid)->toBe($clientUuid)
        ->and($stored->send_at->equalTo($sendAt))->toBeTrue()
        ->and($general->channelMembers()->where('user_id', $owner->id)->value('draft'))->toBeNull();

    // Nothing is posted now — the per-minute dispatcher delivers it later.
    $this->assertDatabaseCount('messages', 0);
});

test('scheduling leaves every other member\'s draft where it was', function (): void {
    ['channel' => $general] = teamWithChannel();

    $author = teamMemberInChannel($general, ['name' => 'Ada Lovelace'],
        state: fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->draft('mine'));
    $bystander = teamMemberInChannel($general,
        state: fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->draft('theirs'));

    app(ScheduleMessage::class)
        ->handle($general, $author, 'later', (string) Str::uuid7(), anHourFromNow());

    expect($general->channelMembers()->where('user_id', $author->id)->value('draft'))->toBeNull()
        ->and($general->channelMembers()->where('user_id', $bystander->id)->value('draft'))->toBe('theirs');
});

test('a scheduled reply stores the message it answers', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $replyTo = Message::factory()->for($general)->for($owner)->create();

    $scheduled = app(ScheduleMessage::class)
        ->handle($general, $owner, 'answering later', (string) Str::uuid7(), anHourFromNow(), $replyTo->id);

    expect(ScheduledMessage::findOrFail($scheduled->id)->reply_to_id)->toBe($replyTo->id);
});
