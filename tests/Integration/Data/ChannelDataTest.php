<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Data\ChannelData;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;
use App\Support\DirectMessageRoster;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The channel read-model, built for a viewer (#1113)
|--------------------------------------------------------------------------
|
| `ChannelData::fromChannel()` used to read `auth()->user()`, so proving what a
| direct message looks like to Ana meant signing in as Ana and re-fetching the
| channel to drop the roster the previous assertion had loaded. The viewer is
| now a parameter, so one arrange serves every viewer in it and the DTO is a
| pure mapping: no `actingAs`, no `->fresh()`. ADR-0011 has the account.
|
*/

test('a standard channel keeps its own name and exposes no participants', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    $view = ChannelData::fromChannel($general, $owner);

    expect($view->name)->toBe($general->name)
        ->and($view->isDirect)->toBeFalse()
        ->and($view->isGroupDirect)->toBeFalse()
        ->and($view->dmUserId)->toBeNull()
        ->and($view->dmParticipants)->toBeNull();
});

test('a direct message names the other participant, per viewer, from one arrange', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $ownerView = ChannelData::fromChannel($dm, $owner);
    $otherView = ChannelData::fromChannel($dm, $other);

    expect($ownerView->isDirect)->toBeTrue()
        ->and($ownerView->isGroupDirect)->toBeFalse()
        ->and($ownerView->name)->toBe($other->name)
        ->and($ownerView->dmUserId)->toBe($other->id)
        ->and($ownerView->dmParticipants)->toHaveCount(1)
        ->and($ownerView->dmParticipants[0]->id)->toBe($other->id)
        ->and($otherView->name)->toBe($owner->name)
        ->and($otherView->dmUserId)->toBe($owner->id)
        ->and($otherView->dmParticipants[0]->id)->toBe($owner->id);
});

test('a self direct message resolves the viewer as its own participant', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $owner);

    $view = ChannelData::fromChannel($dm, $owner);

    expect($view->isDirect)->toBeTrue()
        ->and($view->name)->toBe($owner->name)
        ->and($view->dmUserId)->toBe($owner->id)
        ->and($view->dmParticipants)->toBe([]);
});

test('a group direct message lists the other participants in name order', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $owner->update(['name' => 'Owner']);

    $ana = teamMemberInChannel($general, ['name' => 'Ana Pires']);
    $tomas = teamMemberInChannel($general, ['name' => 'Tomas K']);

    $dm = app(OpenDirectMessage::class)->openForUsers($team, $owner, collect([$ana, $tomas]));

    $ownerView = ChannelData::fromChannel($dm, $owner);
    $anaView = ChannelData::fromChannel($dm, $ana);

    expect($ownerView->isDirect)->toBeTrue()
        ->and($ownerView->isGroupDirect)->toBeTrue()
        // Only a 1:1 has a single counterpart to key presence and an avatar off.
        ->and($ownerView->dmUserId)->toBeNull()
        ->and(collect($ownerView->dmParticipants)->pluck('name')->all())->toBe(['Ana Pires', 'Tomas K'])
        ->and($ownerView->name)->toBe('Ana Pires, Tomas K')
        // The viewer never appears in their own participant list.
        ->and(collect($anaView->dmParticipants)->pluck('name')->all())->toBe(['Owner', 'Tomas K'])
        ->and($anaView->name)->toBe('Owner, Tomas K');
});

test('the viewer per-channel state is read off the membership it is handed', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    $membership = channelMembership(
        $general,
        $owner,
        fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory
            ->muted()
            ->starred()
            ->draft('half a thought')
            ->notificationLevel(NotificationLevel::Mentions),
    );

    $view = ChannelData::fromChannel($general, $owner, $membership);

    expect($view->muted)->toBeTrue()
        ->and($view->notificationLevel)->toBe(NotificationLevel::Mentions)
        ->and($view->draft)->toBe('half a thought')
        ->and($view->hasDraft)->toBeTrue()
        ->and($view->starred)->toBeTrue();
});

test('a non-member viewing a public channel gets the defaults, not another member state', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    // A public channel nobody is auto-joined to, so the newcomer below can read
    // it by URL without belonging to it.
    $public = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $public->members()->attach($owner->id);

    channelMembership(
        $public,
        $owner,
        fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory->muted()->draft('mine alone'),
    );

    // On the team, so the channel is theirs to open — and with no pivot row on
    // it, so the DTO answers with its own defaults rather than with anybody
    // else's.
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $view = ChannelData::fromChannel($public, $stranger);

    expect($view->muted)->toBeFalse()
        ->and($view->notificationLevel)->toBe(NotificationLevel::All)
        ->and($view->draft)->toBeNull()
        ->and($view->hasDraft)->toBeFalse()
        ->and($view->starred)->toBeFalse();
});

test('a batched roster costs the DTO no query of its own', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $group = teamMemberInChannel($general);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    $groupDm = app(OpenDirectMessage::class)->openForUsers($team, $owner, collect([$other, $group]));

    $channels = Channel::query()->whereKey([$dm->id, $groupDm->id, $general->id])->get();
    DirectMessageRoster::load($channels);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $views = $channels->map(fn (Channel $channel): ChannelData => ChannelData::fromChannel($channel, $owner));

    expect(DB::connection()->getQueryLog())->toBe([]);

    // Sanity-check the rosters actually landed, so the assertion above is not
    // passing on channels that resolved to nothing.
    expect($views->firstWhere('id', $dm->id)->dmUserId)->toBe($other->id)
        ->and($views->firstWhere('id', $groupDm->id)->dmParticipants)->toHaveCount(2);
});
