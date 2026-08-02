<?php

use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Message;
use Database\Factories\ChannelMemberFactory;

/**
 * The shared arrange in tests/Helpers.php, which fifty-odd files will lean on
 * once #1110's sweep converts them. Its one non-obvious promise — that stating
 * a membership *restates* it rather than replacing it — is the one worth
 * holding still, because breaking it would surface as unrelated arranges
 * quietly losing a read cursor rather than as a failure here.
 */
test('a team arrives with its general channel and its owner in both', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel('Acme');

    expect($team->name)->toBe('Acme')
        ->and($general->team_id)->toBe($team->id)
        ->and($owner->fresh()->current_team_id)->toBe($team->id)
        ->and($team->memberships()->where('user_id', $owner->id)->value('role'))->toBe(TeamRole::Owner)
        ->and($general->channelMembers()->where('user_id', $owner->id)->exists())->toBeTrue();
});

test('a member is put in the team and the channel, in the role and state asked for', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();

    $member = teamMemberInChannel($general, ['name' => 'Ada Lovelace'], TeamRole::Admin,
        fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->starred()->notificationLevel(NotificationLevel::Mentions));

    $pivot = $general->channelMembers()->where('user_id', $member->id)->firstOrFail();

    expect($member->name)->toBe('Ada Lovelace')
        ->and($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Admin)
        ->and($pivot->starred)->toBeTrue()
        ->and($pivot->notification_level)->toBe(NotificationLevel::Mentions);
});

test('a membership can be asked for with no state at all', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    $plain = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['draft' => 'kept']);
    channelMembership($general, $owner);

    expect($general->channelMembers()->where('user_id', $plain->id)->firstOrFail()->notification_level)
        ->toBe(NotificationLevel::All)
        // Restating without a state is a no-op, not a reset — this is the shape
        // the 116 hand-rolled `firstOrCreate` calls will convert to.
        ->and($general->channelMembers()->where('user_id', $owner->id)->value('draft'))->toBe('kept');
});

test('stating an existing membership leaves the rest of the row standing', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $read = Message::factory()->for($general)->for($owner)->create();

    // The owner is auto-joined to #general, so this row already exists and is
    // already carrying something the arrange did not put there.
    $general->channelMembers()->where('user_id', $owner->id)->update([
        'last_read_message_id' => $read->id,
        'notification_level' => NotificationLevel::Mentions,
    ]);

    channelMembership($general, $owner, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->muted());

    $pivot = $general->channelMembers()->where('user_id', $owner->id)->firstOrFail();

    expect($pivot->muted)->toBeTrue()
        ->and($pivot->last_read_message_id)->toBe($read->id)
        ->and($pivot->notification_level)->toBe(NotificationLevel::Mentions);
});

test('a state is applied even when it asks for the column default', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Nothing]);

    channelMembership($general, $owner,
        fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->notificationLevel(NotificationLevel::All));

    expect($general->channelMembers()->where('user_id', $owner->id)->firstOrFail()->notification_level)
        ->toBe(NotificationLevel::All);
});
