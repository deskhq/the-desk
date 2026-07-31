<?php

declare(strict_types=1);

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\Team;
use App\Models\User;
use App\Support\ChannelMembership;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The channel-member pivot's one module (#1112)
|--------------------------------------------------------------------------
|
| Every one of these writes used to be its own Action behind its own `PATCH`,
| so the state each leaves behind could only be asserted from the far side of
| a rendered page. The module is constructible, so they are asserted here.
|
*/

/**
 * Another channel in the team, with the user a member of it.
 */
function channelInTeam(Team $team, User $user, string $name): Channel
{
    $channel = Channel::factory()->for($team)->create(['name' => $name, 'slug' => Str::slug($name)]);
    channelMembership($channel, $user);

    return $channel;
}

/**
 * The user's manual position in each of the given channels, in order.
 *
 * @param  list<Channel>  $channels
 * @return list<int|null>
 */
function positionsOf(User $user, array $channels): array
{
    return array_map(
        fn (Channel $channel): ?int => $channel->channelMembers()->where('user_id', $user->id)->value('position'),
        $channels,
    );
}

/**
 * How many statements the callback wrote, which is the cost a reorder pays.
 */
function writesWhile(Closure $callback): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    try {
        $callback();

        return collect(DB::connection()->getQueryLog())
            ->reject(fn (array $entry): bool => Str::startsWith(Str::lower(ltrim((string) $entry['query'])), 'select'))
            ->count();
    } finally {
        DB::connection()->disableQueryLog();
    }
}

test('the module resolves the viewer own membership row', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    channelMembership($general, $owner, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->starred());

    $membership = new ChannelMembership($general, $owner);

    expect($membership->exists())->toBeTrue()
        ->and($membership->row()?->starred)->toBeTrue();
});

test('a non-member has no row and no membership', function (): void {
    ['channel' => $general] = teamWithChannel();
    $stranger = User::factory()->create();

    $membership = new ChannelMembership($general, $stranger);

    expect($membership->exists())->toBeFalse()
        ->and($membership->row())->toBeNull();
});

test('starring writes only the caller own row', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $other = teamMemberInChannel($general);

    new ChannelMembership($general, $owner)->star(true);

    expect($general->channelMembers()->where('user_id', $owner->id)->value('starred'))->toBeTrue()
        ->and($general->channelMembers()->where('user_id', $other->id)->value('starred'))->toBeFalse();
});

test('starring a channel the user does not belong to is a no-op', function (): void {
    ['channel' => $general] = teamWithChannel();
    $stranger = User::factory()->create();

    new ChannelMembership($general, $stranger)->star(true);

    expect($general->channelMembers()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('the notification preference writes the mute flag and the level together', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    new ChannelMembership($general, $owner)->setNotificationPreference(true, NotificationLevel::Mentions);

    $pivot = $general->channelMembers()->firstWhere('user_id', $owner->id);

    expect($pivot?->muted)->toBeTrue()
        ->and($pivot?->notification_level)->toBe(NotificationLevel::Mentions);
});

test('the notification preference turns back off again', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    channelMembership($general, $owner, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership
        ->muted()
        ->notificationLevel(NotificationLevel::Nothing));

    new ChannelMembership($general, $owner)->setNotificationPreference(false, NotificationLevel::All);

    $pivot = $general->channelMembers()->firstWhere('user_id', $owner->id);

    expect($pivot?->muted)->toBeFalse()
        ->and($pivot?->notification_level)->toBe(NotificationLevel::All);
});

test('a draft is stored verbatim, mention tokens and all', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    new ChannelMembership($general, $owner)->saveDraft('  hey <@ada>  ');

    expect($general->channelMembers()->where('user_id', $owner->id)->value('draft'))->toBe('  hey <@ada>  ');
});

test('a blank draft is stored as nothing rather than as an empty draft', function (string $blank): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    channelMembership($general, $owner, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->draft('a half-written thought'));

    new ChannelMembership($general, $owner)->saveDraft($blank);

    expect($general->channelMembers()->where('user_id', $owner->id)->value('draft'))->toBeNull();
})->with(['', '   ', "\n\t"]);

test('sending consumes the draft', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    channelMembership($general, $owner, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->draft('a half-written thought'));

    new ChannelMembership($general, $owner)->clearDraft();

    expect($general->channelMembers()->where('user_id', $owner->id)->value('draft'))->toBeNull();
});

test('closing a conversation stamps the closer own row and leaves the other side listed', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $other = teamMemberInChannel($general);

    new ChannelMembership($general, $owner)->hide();

    expect($general->channelMembers()->where('user_id', $owner->id)->value('hidden_at'))->not->toBeNull()
        ->and($general->channelMembers()->where('user_id', $other->id)->value('hidden_at'))->toBeNull();
});

test('re-opening a closed conversation brings it back', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $membership = new ChannelMembership($general, $owner);
    $membership->hide();

    $membership->unhide();

    expect($general->channelMembers()->where('user_id', $owner->id)->value('hidden_at'))->toBeNull();
});

test('placing a channel reindexes the whole group in the order given', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alpha = channelInTeam($team, $owner, 'Alpha');
    $beta = channelInTeam($team, $owner, 'Beta');

    new ChannelMembership($general, $owner)->place([$beta->id, $alpha->id, $general->id], false, null);

    expect(positionsOf($owner, [$beta, $alpha, $general]))->toBe([0, 1, 2]);
});

test('placement ignores ids the user has no membership for', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $foreign = Channel::factory()->for($team)->create();

    new ChannelMembership($general, $owner)->place([$foreign->id, $general->id], false, null);

    expect($general->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(1)
        ->and($foreign->channelMembers()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('an order naming nothing the user owns writes nothing at all', function (array $orderedIds): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $foreign = Channel::factory()->for($team)->create();
    $owner->channels()->updateExistingPivot($general->id, ['position' => 7]);

    $writes = writesWhile(fn () => new ChannelMembership($general, $owner)->place(
        array_map(fn (Closure $id): string => $id($foreign), $orderedIds), false, null,
    ));

    expect($writes)->toBe(0)
        ->and($general->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(7);
})->with([
    'an empty order' => [[]],
    'only ids they have no membership for' => [[fn (Channel $foreign): string => $foreign->id]],
]);

test('placement ignores a channel the user belongs to in another team', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    ['team' => $elsewhere] = teamWithChannel('Globex');
    $other = channelInTeam($elsewhere, $owner, 'Elsewhere');

    new ChannelMembership($general, $owner)->place([$other->id, $general->id], false, null);

    expect($other->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(0)
        ->and($general->channelMembers()->where('user_id', $owner->id)->value('position'))->toBe(1);
});

test('moving files the channel under a section, and a pure reorder leaves it standing', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();
    $membership = new ChannelMembership($general, $owner);

    $membership->place([$general->id], true, $section->id);
    expect($general->channelMembers()->where('user_id', $owner->id)->value('section_id'))->toBe($section->id);

    $membership->place([$general->id], false, null);
    expect($general->channelMembers()->where('user_id', $owner->id)->value('section_id'))->toBe($section->id);

    $membership->place([$general->id], true, null);
    expect($general->channelMembers()->where('user_id', $owner->id)->value('section_id'))->toBeNull();
});

test('reordering writes once however many channels the group holds', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $group = collect(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'])
        ->map(fn (string $name): Channel => channelInTeam($team, $owner, $name));

    $membership = new ChannelMembership($general, $owner);
    $small = writesWhile(fn () => $membership->place($group->take(2)->pluck('id')->all(), false, null));
    $large = writesWhile(fn () => $membership->place($group->pluck('id')->all(), false, null));

    // A 40-channel drag used to issue 40 UPDATEs. The cost of a reorder is now
    // the same whether the group holds two channels or the whole sidebar.
    expect($small)->toBe(1)
        ->and($large)->toBe($small);
});

test('removing the membership reports whether there was one to remove', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $membership = new ChannelMembership($general, $owner);

    expect($membership->remove())->toBeTrue()
        ->and($general->channelMembers()->where('user_id', $owner->id)->exists())->toBeFalse()
        // Nothing left to remove, which is what tells a caller not to audit.
        ->and($membership->remove())->toBeFalse();
});

test('a read after a write sees the state the write left', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();

    $membership = new ChannelMembership($general, $owner);
    // Resolved first, so the memo is warm and has to be dropped by the write.
    expect($membership->row()?->muted)->toBeFalse();

    $membership->setNotificationPreference(true, NotificationLevel::All);

    expect($membership->row()?->muted)->toBeTrue();
});
